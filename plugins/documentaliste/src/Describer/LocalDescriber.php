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

namespace GlpiPlugin\Documentaliste\Describer;

use GlpiPlugin\Documentaliste\Description;
use GlpiPlugin\Documentaliste\DescriptionResult;
use GlpiPlugin\Documentaliste\Extractor\ExtractedContent;
use GlpiPlugin\Documentaliste\SensitiveContentDetector;

use function Safe\preg_match;
use function Safe\preg_replace;
use function Safe\preg_split;

/**
 * Describer working fully on the GLPI server, without any AI service.
 *
 * Produces a factual summary (type, size) plus frequent keywords and sensitive
 * content detection. It is the default and the fallback of AI describers.
 * It never copies raw document content into the summary.
 */
final readonly class LocalDescriber implements DescriberInterface
{
    public const NAME = 'local';

    private const KEYWORDS_COUNT  = 10;

    private const MIN_WORD_LENGTH = 4;

    /** Minimal FR/EN stop words list; words shorter than MIN_WORD_LENGTH are ignored anyway. */
    private const STOP_WORDS = [
        'avec', 'dans', 'pour', 'cette', 'sont', 'nous', 'vous', 'mais', 'plus', 'tout', 'tous',
        'leur', 'leurs', 'elle', 'elles', 'être', 'avoir', 'fait', 'faire', 'comme', 'aussi', 'entre',
        'sans', 'sous', 'votre', 'notre', 'very', 'this', 'that', 'with', 'from', 'have', 'will',
        'your', 'they', 'their', 'there', 'which', 'were', 'been', 'what', 'when', 'into', 'than',
        'then', 'them', 'these', 'those', 'about', 'would', 'should', 'could',
    ];

    public function __construct(private SensitiveContentDetector $detector) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function describe(ExtractedContent $content, string $filename): DescriptionResult
    {
        $detected = $this->detector->detect($content->text);

        return new DescriptionResult(
            status: Description::STATUS_DONE,
            summary: $this->buildSummary($content),
            // Keywords come from the REDACTED text: no fragment of a secret can be stored.
            keywords: $this->extractKeywords($this->detector->redact($content->text)),
            detected_types: $detected,
            sensitivity: SensitiveContentDetector::getLevel($detected),
            describer: self::NAME,
            // Records the OCR engine used, if any (e.g. "tesseract 5.5.0 (fra+eng)").
            model: isset($content->metadata['ocr']) ? (string) $content->metadata['ocr'] : null,
        );
    }

    private function buildSummary(ExtractedContent $content): string
    {
        $words = $content->text === '' ? 0 : count(preg_split('/\s+/u', $content->text, -1, PREG_SPLIT_NO_EMPTY));
        $meta  = $content->metadata;

        return match ($content->kind) {
            ExtractedContent::KIND_IMAGE => $this->buildImageSummary($meta, $words),
            ExtractedContent::KIND_SPREADSHEET => sprintf(
                __('Spreadsheet with %1$d sheet(s) and %2$d row(s).', 'documentaliste'),
                (int) ($meta['sheets'] ?? 0),
                (int) ($meta['rows'] ?? 0),
            ),
            ExtractedContent::KIND_PRESENTATION => sprintf(
                __('Presentation of %1$d word(s).', 'documentaliste'),
                $words,
            ),
            ExtractedContent::KIND_DOCUMENT => sprintf(
                __('Text document of %1$d word(s).', 'documentaliste'),
                $words,
            ),
            default => sprintf(
                __('Text file of %1$d line(s) and %2$d word(s).', 'documentaliste'),
                (int) ($meta['lines'] ?? 0),
                $words,
            ),
        };
    }

    /**
     * @param array<string, int|string> $meta
     */
    private function buildImageSummary(array $meta, int $words): string
    {
        $width  = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);

        return match ($meta['ocr_status'] ?? null) {
            'done' => $words > 0
                ? sprintf(__('Image %1$d×%2$d px, %3$d word(s) recognized by OCR.', 'documentaliste'), $width, $height, $words)
                : sprintf(__('Image %1$d×%2$d px, no text recognized.', 'documentaliste'), $width, $height),
            'too_large' => sprintf(__('Image %1$d×%2$d px, too large for OCR.', 'documentaliste'), $width, $height),
            default => sprintf(__('Image %1$d×%2$d px (OCR engine not installed).', 'documentaliste'), $width, $height),
        };
    }

    /**
     * Most frequent meaningful words. Words looking like secrets/identifiers
     * (containing digits) are excluded on purpose.
     *
     * @return list<string>
     */
    private function extractKeywords(string $text): array
    {
        if ($text === '') {
            return [];
        }

        // Whole whitespace-separated tokens only: a token mixing letters and digits
        // ("Ete2026Paris", "srv01") is dropped entirely instead of being split.
        // Redaction placeholders ("[password]", "[login]"...) are not keywords.
        $cleaned = preg_replace('/\[[a-z_]+\]/', ' ', $text);
        $text = is_string($cleaned) ? $cleaned : '';

        $words = [];
        foreach (preg_split('/\s+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (!is_string($token)) {
                continue;
            }

            $word = trim($token, " \t\n\r\0\x0B.,;:!?()[]{}<>\"'«»“”’-_/\\|*#=+");
            if (mb_strlen($word) >= self::MIN_WORD_LENGTH && preg_match('/^\p{L}+(?:-\p{L}+)*$/u', $word) === 1) {
                $words[] = $word;
            }
        }

        $counts = array_count_values(array_diff($words, self::STOP_WORDS));
        arsort($counts);

        return array_map(strval(...), array_slice(array_keys($counts), 0, self::KEYWORDS_COUNT));
    }
}
