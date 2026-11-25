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

use GlpiPlugin\Documentaliste\Ocr\OcrEngineInterface;
use RuntimeException;
use Safe\Exceptions\ImageException;

use function Safe\getimagesize;

/**
 * Images: metadata + text recognized by a local OCR engine (Tesseract by default).
 * Without OCR engine installed, only metadata are extracted.
 */
final readonly class ImageExtractor implements ExtractorInterface
{
    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff'];

    /** Above this size (pixels), OCR is skipped to bound CPU and memory usage. */
    public const MAX_OCR_PIXELS = 40_000_000;

    public function __construct(private ?OcrEngineInterface $ocr = null) {}

    public function supports(string $mime, string $extension): bool
    {
        return in_array($extension, self::EXTENSIONS, true)
            || (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml');
    }

    public function extract(string $path, string $mime): ExtractedContent
    {
        try {
            $size = getimagesize($path);
        } catch (ImageException $imageException) {
            throw new RuntimeException('Unable to read image.', 0, $imageException);
        }

        if ($size === null) {
            throw new RuntimeException('Unable to read image.');
        }

        $metadata = ['width' => $size[0], 'height' => $size[1]];
        $text = '';
        if (!$this->ocr instanceof OcrEngineInterface) {
            $metadata['ocr_status'] = 'unavailable';
        } elseif ($size[0] * $size[1] > self::MAX_OCR_PIXELS) {
            $metadata['ocr_status'] = 'too_large';
        } else {
            $text = TextNormalizer::normalize($this->ocr->recognize($path));
            $metadata['ocr_status'] = 'done';
            $metadata['ocr'] = $this->ocr->getName();
        }

        return new ExtractedContent(
            kind: ExtractedContent::KIND_IMAGE,
            text: $text,
            metadata: $metadata,
            binary_path: $path,
            mime: $size['mime'],
        );
    }
}
