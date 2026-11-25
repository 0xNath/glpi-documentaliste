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
use GlpiPlugin\Documentaliste\Describer\LocalDescriber;
use GlpiPlugin\Documentaliste\Description;
use GlpiPlugin\Documentaliste\Extractor\ExtractorRegistry;
use GlpiPlugin\Documentaliste\Extractor\ImageExtractor;
use GlpiPlugin\Documentaliste\Ocr\OcrEngineInterface;
use GlpiPlugin\Documentaliste\Ocr\TesseractOcr;
use GlpiPlugin\Documentaliste\SensitiveContentDetector;

/**
 * Fixture `ocr_login_screenshot.png`: fake login dialog containing
 * "Identifiant : jdupont" and "Mot de passe : Ete2026Paris".
 */
final class OcrTest extends GLPITestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/ocr_login_screenshot.png';

    private const SECRET = 'Ete2026Paris';

    public function testTesseractRecognizesScreenshot(): void
    {
        $ocr = $this->getTesseractOrSkip();

        $text = $ocr->recognize(self::FIXTURE);

        $this->assertStringContainsString('Connexion au serveur de fichiers', $text);
        $this->assertStringContainsString(self::SECRET, $text);
        $this->assertStringStartsWith('tesseract 5', $ocr->getName());
        $this->assertStringContainsString('fra', $ocr->getName());
    }

    public function testScreenshotWithPasswordIsFlaggedWithoutStoringIt(): void
    {
        $this->getTesseractOrSkip();

        $result = Analyzer::createDefault()->analyzeFile(self::FIXTURE, 'capture.png', 'image/png');

        $this->assertSame(Description::STATUS_DONE, $result->status);
        $this->assertSame([SensitiveContentDetector::TYPE_PASSWORD], $result->detected_types);
        $this->assertSame(SensitiveContentDetector::LEVEL_SECRET, $result->sensitivity);
        $this->assertStringContainsString('recognized by OCR', $result->summary);
        $this->assertContains('serveur', $result->keywords);
        $this->assertStringStartsWith('tesseract', (string) $result->model);

        $keywords = implode(' ', $result->keywords);
        $this->assertStringNotContainsString(self::SECRET, $result->summary);
        $this->assertStringNotContainsString(self::SECRET, $keywords);
        $this->assertStringNotContainsString(strtolower(self::SECRET), $keywords);
        // No fragment of the password ("Ete2026Paris" -> "paris") nor the login next to it.
        $this->assertNotContains('paris', $result->keywords);
        $this->assertNotContains('jdupont', $result->keywords);
        // Redaction placeholders are not keywords.
        $this->assertNotContains('password', $result->keywords);
        $this->assertNotContains('login', $result->keywords);
    }

    public function testImageWithoutOcrEngine(): void
    {
        $analyzer = new Analyzer(
            new ExtractorRegistry([new ImageExtractor()]),
            new LocalDescriber(new SensitiveContentDetector()),
        );

        $result = $analyzer->analyzeFile(self::FIXTURE, 'capture.png', 'image/png');

        $this->assertSame(Description::STATUS_DONE, $result->status);
        $this->assertStringContainsString('OCR engine not installed', $result->summary);
        $this->assertSame([], $result->detected_types);
        $this->assertNull($result->model);
    }

    public function testOcrIsSkippedOnHugeImages(): void
    {
        $engine = new class implements OcrEngineInterface {
            public bool $called = false;

            public function getName(): string
            {
                return 'fake';
            }

            public function recognize(string $image_path): string
            {
                $this->called = true;
                return '';
            }
        };

        $path = GLPI_TMP_DIR . '/' . uniqid('documentaliste_', true) . '.png';
        // 7000 x 6000 = 42 Mpx > ImageExtractor::MAX_OCR_PIXELS (palette image: small file)
        $image = imagecreate(7000, 6000);
        $this->assertNotFalse($image);
        imagecolorallocate($image, 255, 255, 255);
        imagepng($image, $path, 9);

        try {
            $content = (new ImageExtractor($engine))->extract($path, 'image/png');
        } finally {
            unlink($path);
        }

        $this->assertFalse($engine->called);
        $this->assertSame('too_large', $content->metadata['ocr_status']);
    }

    private function getTesseractOrSkip(): TesseractOcr
    {
        $ocr = TesseractOcr::detect();
        if (!$ocr instanceof TesseractOcr) {
            $this->markTestSkipped('Tesseract is not installed (apt install tesseract-ocr tesseract-ocr-fra).');
        }

        return $ocr;
    }
}
