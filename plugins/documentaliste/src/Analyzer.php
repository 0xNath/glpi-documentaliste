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

namespace GlpiPlugin\Documentaliste;

use Document;
use GlpiPlugin\Documentaliste\Describer\DescriberInterface;
use GlpiPlugin\Documentaliste\Describer\LocalDescriber;
use GlpiPlugin\Documentaliste\Extractor\ExtractorInterface;
use GlpiPlugin\Documentaliste\Extractor\ExtractorRegistry;
use RuntimeException;

use function Safe\filesize;

/**
 * Extraction + description pipeline for one file.
 */
final readonly class Analyzer
{
    public const DEFAULT_MAX_FILE_SIZE = 20 * 1024 * 1024;

    /**
     * Version of the extraction + description logic. BUMP IT whenever stored results
     * would differ (new extractor, OCR, redaction rules...): older results are then
     * no longer reused by deduplication, and get re-analyzed by the automatic action.
     *
     * 1: initial version; 2: OCR + redaction before keyword extraction;
     * 3: redaction placeholders excluded from keywords.
     */
    public const VERSION = 3;

    public function __construct(
        private ExtractorRegistry $extractors,
        private DescriberInterface $describer,
        private int $max_file_size = self::DEFAULT_MAX_FILE_SIZE,
    ) {}

    public static function createDefault(): self
    {
        // TODO (milestone 4): pick the describer from the plugin configuration
        // (local LLM running on the GLPI server only) with LocalDescriber as fallback.
        return new self(
            ExtractorRegistry::createDefault(),
            new LocalDescriber(new SensitiveContentDetector()),
        );
    }

    public function analyze(Document $document): DescriptionResult
    {
        $filepath = Fields::string($document->fields, 'filepath');
        if ($filepath === '') {
            return DescriptionResult::skipped(__('Document has no file.', 'documentaliste'), $this->describer->getName());
        }

        return $this->analyzeFile(
            GLPI_DOC_DIR . '/' . $filepath,
            Fields::string($document->fields, 'filename', basename($filepath)),
            Fields::string($document->fields, 'mime'),
        );
    }

    /**
     * @throws RuntimeException when the file is missing or unreadable
     */
    public function analyzeFile(string $path, string $filename, string $mime): DescriptionResult
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('File not found or not readable.');
        }

        if (filesize($path) > $this->max_file_size) {
            return DescriptionResult::skipped(__('File too large to be analyzed.', 'documentaliste'), $this->describer->getName());
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $extractor = $this->extractors->find(strtolower($mime), $extension);
        if (!$extractor instanceof ExtractorInterface) {
            return DescriptionResult::skipped(__('Unsupported file type.', 'documentaliste'), $this->describer->getName());
        }

        return $this->describer->describe($extractor->extract($path, $mime), $filename);
    }
}
