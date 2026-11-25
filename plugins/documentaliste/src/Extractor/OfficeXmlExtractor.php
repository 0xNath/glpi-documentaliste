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

namespace GlpiPlugin\Documentaliste\Extractor;

use RuntimeException;
use ZipArchive;

use function Safe\preg_match;
use function Safe\preg_replace;

/**
 * Text extraction for zipped XML office formats: docx, pptx, odt, odp.
 * No external dependency: reads the XML parts straight from the archive.
 */
final class OfficeXmlExtractor implements ExtractorInterface
{
    /** Max uncompressed size read per XML part (zip bomb protection). */
    private const MAX_PART_SIZE = 20 * 1024 * 1024;

    private const MAX_PARTS = 500;

    public function supports(string $mime, string $extension): bool
    {
        return in_array($extension, ['docx', 'docm', 'pptx', 'pptm', 'odt', 'odp'], true);
    }

    public function extract(string $path, string $mime): ExtractedContent
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Unable to open office archive.');
        }

        try {
            $parts = $this->listTextParts($zip);
            if ($parts === []) {
                throw new RuntimeException('No text part found in office archive.');
            }

            $is_presentation = str_starts_with($parts[0], 'ppt/')
                || $zip->getFromName('mimetype') === 'application/vnd.oasis.opendocument.presentation';

            $chunks = [];
            foreach ($parts as $part) {
                $stat = $zip->statName($part);
                if ($stat === false || $stat['size'] > self::MAX_PART_SIZE) {
                    continue;
                }

                $xml = $zip->getFromName($part);
                if (is_string($xml)) {
                    $chunks[] = $this->xmlToText($xml);
                }
            }
        } finally {
            $zip->close();
        }

        $metadata = $is_presentation && str_starts_with($parts[0], 'ppt/') ? ['slides' => count($parts)] : [];

        return new ExtractedContent(
            kind: $is_presentation ? ExtractedContent::KIND_PRESENTATION : ExtractedContent::KIND_DOCUMENT,
            text: TextNormalizer::normalize(implode("\n\n", $chunks)),
            metadata: $metadata,
            mime: $mime,
        );
    }

    /**
     * @return list<string>
     */
    private function listTextParts(ZipArchive $zip): array
    {
        if ($zip->locateName('word/document.xml') !== false) {
            return ['word/document.xml'];
        }

        if ($zip->locateName('content.xml') !== false) {
            // OpenDocument (odt, odp)
            return ['content.xml'];
        }

        $slides = [];
        for ($i = 0; $i < $zip->numFiles && count($slides) < self::MAX_PARTS; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m) === 1 && isset($m[1])) {
                $slides[(int) $m[1]] = $name;
            }
        }

        ksort($slides);
        return array_values($slides);
    }

    private function xmlToText(string $xml): string
    {
        // Paragraph / line breaks of WordprocessingML, DrawingML and OpenDocument.
        $xml = (string) preg_replace('#</(?:w:p|a:p|text:p|text:h)>|<(?:w:br|w:tab|text:line-break|text:tab)\b[^>]*/>#', "\n", $xml);
        $text = strip_tags($xml);
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
