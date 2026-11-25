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

/**
 * Reacts to Document lifecycle hooks.
 *
 * Must stay fast: the heavy work is done by the `analyze` automatic action.
 */
final class DocumentListener
{
    public static function onDocumentAdd(Document $document): void
    {
        if (self::hasFile($document)) {
            Description::queueDocument($document);
        }
    }

    public static function onDocumentUpdate(Document $document): void
    {
        // Only re-analyze when the file content actually changed.
        if (in_array('sha1sum', $document->updates, true) && self::hasFile($document)) {
            Description::queueDocument($document);
        }
    }

    public static function onDocumentPurge(Document $document): void
    {
        Description::removeForDocument((int) $document->getID());
    }

    /**
     * Documents can be simple links (no file): nothing to analyze then.
     */
    private static function hasFile(Document $document): bool
    {
        return !empty($document->fields['filepath']) && !empty($document->fields['sha1sum']);
    }
}
