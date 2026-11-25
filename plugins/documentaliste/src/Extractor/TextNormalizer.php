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

use function Safe\mb_convert_encoding;
use function Safe\preg_replace;

/**
 * Shared helpers to bound and clean extracted text.
 */
final class TextNormalizer
{
    /** Max length of text kept after extraction (bytes). */
    public const MAX_TEXT_LENGTH = 200_000;

    public static function normalize(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            $text = is_string($converted) ? $converted : '';
        }

        // Strip control chars except tab / newline.
        $text = self::replace('/[^\P{C}\t\n]/u', '', $text);
        $text = self::replace("/[ \t]+/u", ' ', $text);
        $text = self::replace("/\n{3,}/u", "\n\n", $text);
        $text = trim($text);

        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = mb_strcut($text, 0, self::MAX_TEXT_LENGTH);
        }

        return $text;
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);
        return is_string($result) ? $result : '';
    }
}
