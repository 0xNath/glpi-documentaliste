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

/**
 * Raw content extracted from a file, before description.
 */
final readonly class ExtractedContent
{
    public const KIND_TEXT         = 'text';

    public const KIND_DOCUMENT     = 'document';

    public const KIND_PRESENTATION = 'presentation';

    public const KIND_SPREADSHEET  = 'spreadsheet';

    public const KIND_IMAGE        = 'image';

    /**
     * @param self::KIND_* $kind
     * @param array<string, int|string> $metadata e.g. pages, sheets, rows, width, height
     * @param ?string $binary_path path of the original file, for describers able to
     *                             process binary content (e.g. vision models for images)
     */
    public function __construct(
        public string $kind,
        public string $text,
        public array $metadata = [],
        public ?string $binary_path = null,
        public ?string $mime = null,
    ) {}
}
