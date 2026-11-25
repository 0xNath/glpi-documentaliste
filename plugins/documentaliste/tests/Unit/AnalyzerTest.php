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

namespace GlpiPlugin\Documentaliste\Tests\Unit;

use Glpi\Tests\GLPITestCase;
use GlpiPlugin\Documentaliste\Analyzer;
use GlpiPlugin\Documentaliste\Description;
use GlpiPlugin\Documentaliste\SensitiveContentDetector;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use ZipArchive;

final class AnalyzerTest extends GLPITestCase
{
    /** @var list<string> */
    private array $files = [];

    public function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
        parent::tearDown();
    }

    public function testPlainTextWithPassword(): void
    {
        $path = $this->tmpFile('txt', "Serveur de fichiers\nmot de passe: Tr0ub4dor&3\nserveur serveur");

        $result = Analyzer::createDefault()->analyzeFile($path, 'notes.txt', 'text/plain');

        $this->assertSame(Description::STATUS_DONE, $result->status);
        $this->assertSame([SensitiveContentDetector::TYPE_PASSWORD], $result->detected_types);
        $this->assertSame(SensitiveContentDetector::LEVEL_SECRET, $result->sensitivity);
        $this->assertSame('serveur', $result->keywords[0]);
        $this->assertSecretNotLeaked('Tr0ub4dor&3', $result->summary, $result->keywords);
    }

    public function testDocx(): void
    {
        $path = $this->tmpFile('docx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<w:document><w:body><w:p><w:r><w:t>Procédure de sauvegarde</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>La sauvegarde est quotidienne.</w:t></w:r></w:p></w:body></w:document>',
        );
        $zip->close();

        $result = Analyzer::createDefault()->analyzeFile($path, 'procedure.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->assertSame(Description::STATUS_DONE, $result->status);
        $this->assertStringContainsString('7', $result->summary); // 7 words
        $this->assertSame('sauvegarde', $result->keywords[0]);
        $this->assertSame([], $result->detected_types);
    }

    public function testXlsx(): void
    {
        $path = $this->tmpFile('xlsx');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Compte', 'IBAN'], ['Fournisseur', 'FR76 3000 6000 0112 3456 7890 189']]);
        (new Xlsx($spreadsheet))->save($path);

        $result = Analyzer::createDefault()->analyzeFile($path, 'fournisseurs.xlsx', '');

        $this->assertSame(Description::STATUS_DONE, $result->status);
        $this->assertSame([SensitiveContentDetector::TYPE_IBAN], $result->detected_types);
        $this->assertSame(SensitiveContentDetector::LEVEL_PERSONAL, $result->sensitivity);
        $this->assertSecretNotLeaked('3000 6000', $result->summary, $result->keywords);
    }

    public function testImage(): void
    {
        $path = $this->tmpFile('png');
        $image = imagecreatetruecolor(12, 8);
        $this->assertNotFalse($image);
        imagepng($image, $path);

        $result = Analyzer::createDefault()->analyzeFile($path, 'capture.png', 'image/png');

        $this->assertSame(Description::STATUS_DONE, $result->status);
        $this->assertStringContainsString('12×8', $result->summary);
    }

    public function testUnsupportedType(): void
    {
        $path = $this->tmpFile('bin', random_bytes(64));

        $result = Analyzer::createDefault()->analyzeFile($path, 'blob.bin', 'application/octet-stream');

        $this->assertSame(Description::STATUS_SKIPPED, $result->status);
    }

    public function testMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        Analyzer::createDefault()->analyzeFile('/nonexistent/file.txt', 'file.txt', 'text/plain');
    }

    private function tmpFile(string $extension, ?string $contents = null): string
    {
        $path = GLPI_TMP_DIR . '/' . uniqid('documentaliste_', true) . '.' . $extension;
        if ($contents !== null) {
            file_put_contents($path, $contents);
        }

        $this->files[] = $path;
        return $path;
    }

    /**
     * @param list<string> $keywords
     */
    private function assertSecretNotLeaked(string $secret, string $summary, array $keywords): void
    {
        $this->assertStringNotContainsString($secret, $summary);
        $this->assertStringNotContainsString($secret, implode(' ', $keywords));
    }
}
