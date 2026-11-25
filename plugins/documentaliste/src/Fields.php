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

/**
 * Typed accessors for GLPI `$item->fields` / DB rows (array<string, mixed>),
 * to keep PHPStan level max happy without blind casts.
 */
final class Fields
{
    /**
     * @param array<mixed> $fields
     */
    public static function int(array $fields, string $key, int $default = 0): int
    {
        $value = $fields[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<mixed> $fields
     */
    public static function string(array $fields, string $key, string $default = ''): string
    {
        return self::nullableString($fields, $key) ?? $default;
    }

    /**
     * @param array<mixed> $fields
     */
    public static function nullableString(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }
}
