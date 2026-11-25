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

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Spreadsheet extraction, using PhpSpreadsheet (already shipped by GLPI core:
 * do NOT add it to the plugin composer.json).
 */
final class SpreadsheetExtractor implements ExtractorInterface
{
    private const READERS = [
        'xlsx' => 'Xlsx',
        'xlsm' => 'Xlsx',
        'xls'  => 'Xls',
        'ods'  => 'Ods',
    ];

    private const MAX_ROWS_PER_SHEET = 500;

    private const MAX_COLS           = 50;

    private const MAX_SHEETS         = 20;

    public function supports(string $mime, string $extension): bool
    {
        return isset(self::READERS[$extension]);
    }

    public function extract(string $path, string $mime): ExtractedContent
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $reader_type = self::READERS[$extension] ?? null;

        try {
            $reader = $reader_type !== null
                ? IOFactory::createReader($reader_type)
                : IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $spreadsheet = $reader->load($path);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Unable to read spreadsheet: ' . $throwable->getMessage(), 0, $throwable);
        }

        $lines = [];
        $total_rows = 0;
        $sheet_count = $spreadsheet->getSheetCount();
        foreach ($spreadsheet->getWorksheetIterator() as $index => $sheet) {
            if ($index >= self::MAX_SHEETS) {
                break;
            }

            $lines[] = '# ' . $sheet->getTitle();
            $highest_row = $sheet->getHighestDataRow();
            $total_rows += $highest_row;

            foreach ($sheet->getRowIterator(1, min($highest_row, self::MAX_ROWS_PER_SHEET)) as $row) {
                $cells = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(true);
                foreach ($iterator as $cell) {
                    if (count($cells) >= self::MAX_COLS) {
                        break;
                    }

                    $raw = $cell->getValue();
                    $value = is_scalar($raw) ? trim((string) $raw) : '';
                    if ($value !== '') {
                        $cells[] = $value;
                    }
                }

                if ($cells !== []) {
                    $lines[] = implode("\t", $cells);
                }
            }
        }

        $spreadsheet->disconnectWorksheets();

        return new ExtractedContent(
            kind: ExtractedContent::KIND_SPREADSHEET,
            text: TextNormalizer::normalize(implode("\n", $lines)),
            metadata: ['sheets' => $sheet_count, 'rows' => $total_rows],
            mime: $mime,
        );
    }
}
