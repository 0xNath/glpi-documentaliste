<?php

/**
 * -------------------------------------------------------------------------
 * Documentaliste plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Documentaliste plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Documentaliste\Tests\Functional;

use CronTask;
use Document;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Documentaliste\Analyzer;
use GlpiPlugin\Documentaliste\Describer\DescriberInterface;
use GlpiPlugin\Documentaliste\Description;
use GlpiPlugin\Documentaliste\DescriptionResult;
use GlpiPlugin\Documentaliste\Extractor\ExtractedContent;
use GlpiPlugin\Documentaliste\Extractor\ExtractorRegistry;
use GlpiPlugin\Documentaliste\SensitiveContentDetector;
use LogicException;

final class DescriptionTest extends DbTestCase
{
    public function testDocumentIsQueuedOnAdd(): void
    {
        $document = $this->createTextDocument('Rien de sensible ici.');

        $description = Description::getForDocument($document->getID());
        $this->assertNotNull($description);
        $this->assertSame(Description::STATUS_PENDING, $description->fields['status']);
        $this->assertSame($document->fields['sha1sum'], $description->fields['sha1sum']);
    }

    public function testProcessStoresDescriptionWithoutSecret(): void
    {
        $document = $this->createTextDocument("Accès VPN\nlogin: jdupont\npassword: Zx9!kLm2");
        $description = Description::getForDocument($document->getID());
        $this->assertNotNull($description);

        $description->process(Analyzer::createDefault());

        $description = Description::getForDocument($document->getID());
        $this->assertNotNull($description);
        $this->assertTrue($description->isDone());
        $this->assertSame([SensitiveContentDetector::TYPE_PASSWORD], $description->getDetectedTypes());
        $this->assertSame(SensitiveContentDetector::LEVEL_SECRET, (int) $description->fields['sensitivity']);
        $this->assertStringNotContainsString('Zx9!kLm2', (string) $description->fields['summary']);
        $this->assertStringNotContainsString('Zx9!kLm2', (string) $description->fields['keywords']);
    }

    public function testCronProcessesPendingDocuments(): void
    {
        $document = $this->createTextDocument('Inventaire des imprimantes du site.');

        $task = new CronTask();
        $this->assertTrue($task->getFromDBbyName(Description::class, Description::CRON_ANALYZE));
        $this->assertSame(1, Description::cronAnalyze($task));

        $description = Description::getForDocument($document->getID());
        $this->assertNotNull($description);
        $this->assertTrue($description->isDone());
        $this->assertContains('imprimantes', $description->getKeywords());
    }

    public function testDescriptionIsRemovedOnPurge(): void
    {
        $document = $this->createTextDocument('À supprimer.');
        $id = $document->getID();
        $this->assertNotNull(Description::getForDocument($id));

        $this->deleteItem(Document::class, $id, true);

        $this->assertNull(Description::getForDocument($id));
    }

    public function testSameContentIsAnalyzedOnce(): void
    {
        $contents = 'Contrat de maintenance des copieurs ' . uniqid();
        $documents = [];
        for ($i = 0; $i < 3; $i++) {
            $documents[] = $this->createTextDocument($contents);
        }

        $first = Description::getForDocument($documents[0]->getID());
        $this->assertNotNull($first);
        $first->process(Analyzer::createDefault());

        // Copies reuse the stored result (3 copies: more than one candidate row).
        foreach ([1, 2] as $index) {
            $copy = Description::getForDocument($documents[$index]->getID());
            $this->assertNotNull($copy);
            $copy->process($this->createFailingAnalyzer());
            $copy = Description::getForDocument($documents[$index]->getID());
            $this->assertNotNull($copy);
            $this->assertTrue($copy->isDone(), 'copy ' . $index . ' should reuse the result');
            $this->assertSame(Analyzer::VERSION, (int) $copy->fields['analyzer_version']);
        }
    }

    public function testOutdatedResultIsNeitherReusedNorKept(): void
    {
        $contents = "Accès NAS\nmot de passe: Ete2026Paris " . uniqid();
        $old = $this->createTextDocument($contents);
        $old_description = Description::getForDocument($old->getID());
        $this->assertNotNull($old_description);

        // Simulate a result stored by a previous, less safe analyzer version.
        $this->assertTrue($old_description->update([
            'id'               => $old_description->getID(),
            'status'           => Description::STATUS_DONE,
            'keywords'         => '["paris"]',
            'analyzer_version' => Analyzer::VERSION - 1,
        ]));

        // A new copy of the same file must not inherit the outdated result.
        $copy = $this->createTextDocument($contents);
        $copy_description = Description::getForDocument($copy->getID());
        $this->assertNotNull($copy_description);
        $copy_description->process(Analyzer::createDefault());
        $copy_description = Description::getForDocument($copy->getID());
        $this->assertNotNull($copy_description);
        $this->assertNotContains('paris', $copy_description->getKeywords());

        // The automatic action refreshes the outdated row.
        $task = new CronTask();
        $this->assertTrue($task->getFromDBbyName(Description::class, Description::CRON_ANALYZE));
        Description::cronAnalyze($task);

        $old_description = Description::getForDocument($old->getID());
        $this->assertNotNull($old_description);
        $this->assertSame(Analyzer::VERSION, (int) $old_description->fields['analyzer_version']);
        $this->assertNotContains('paris', $old_description->getKeywords());
    }

    private function createFailingAnalyzer(): Analyzer
    {
        $describer = new class implements DescriberInterface {
            public function getName(): string
            {
                return 'failing';
            }

            public function describe(ExtractedContent $content, string $filename): DescriptionResult
            {
                throw new LogicException('Should not be called: result must be reused.');
            }
        };

        return new Analyzer(ExtractorRegistry::createDefault(), $describer);
    }

    public function testTabRendersDescription(): void
    {
        $document = $this->createTextDocument("mot de passe: Q8#vXr2p\nimprimante imprimante");
        $description = Description::getForDocument($document->getID());
        $this->assertNotNull($description);
        $description->process(Analyzer::createDefault());

        $this->assertNotSame('', (new Description())->getTabNameForItem($document));

        ob_start();
        Description::displayTabContentForItem($document);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('imprimante', $html);
        $this->assertStringContainsString(SensitiveContentDetector::TYPE_PASSWORD, $html);
        $this->assertStringNotContainsString('Q8#vXr2p', $html);
    }

    private function createTextDocument(string $contents): Document
    {
        $this->login();

        $filename = uniqid('documentaliste_', true) . '.txt';
        file_put_contents(GLPI_TMP_DIR . '/' . $filename, $contents);

        $document = $this->createItem(
            Document::class,
            [
                'filename'    => $filename,
                'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
                '_filename'   => [$filename],
            ],
        );
        $this->assertInstanceOf(Document::class, $document);
        return $document;
    }
}
