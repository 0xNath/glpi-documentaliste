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

use CommonDBTM;
use CommonGLPI;
use CronTask;
use DBmysql;
use Document;
use Glpi\Application\View\TemplateRenderer;
use Plugin;
use Safe\Exceptions\JsonException;
use Session;
use Throwable;

use function Safe\json_decode;

/**
 * Synthetic description of a GLPI document (one row per document).
 *
 * Public API for other plugins:
 *  - `Description::getForDocument(int $documents_id): ?Description`
 *  - hook `Description::HOOK_DESCRIBED`, fired with the Description item once an
 *    analysis is stored. Register it with:
 *    `$PLUGIN_HOOKS['documentaliste_document_described']['myplugin'] = [Description::class => callable];`
 */
class Description extends CommonDBTM
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DONE    = 'done';

    public const STATUS_ERROR   = 'error';

    public const STATUS_SKIPPED = 'skipped';

    public const MAX_ATTEMPTS = 3;

    public const CRON_ANALYZE            = 'analyze';

    public const CRON_DEFAULT_BATCH_SIZE = 20;

    public const HOOK_DESCRIBED = 'documentaliste_document_described';

    /**
     * Search option ids added on Document.
     * TODO: reserve an official range before publishing the plugin.
     */
    public const SO_SUMMARY     = 97101;

    public const SO_KEYWORDS    = 97102;

    public const SO_SENSITIVITY = 97103;

    public const SO_STATUS      = 97104;

    public const SO_DETECTED    = 97105;

    /**
     * Right used to read descriptions: the GLPI "document" right.
     *
     * `$rightname` is deliberately NOT redeclared: it is untyped in GLPI 11 and
     * typed `string` in GLPI 12, no declaration compiles on both. Rights are
     * enforced through the can*() methods below instead.
     */
    public const RIGHT_NAME = 'document';

    public static function canView(): bool
    {
        // haveRight() returns bool|int in GLPI 11, bool in GLPI 12: normalize without a
        // cast, which Rector removes as redundant when run against GLPI 12.
        return in_array(Session::haveRight(self::RIGHT_NAME, READ), [true, 1], true);
    }

    /**
     * Descriptions are written by the plugin itself (hooks, automatic action),
     * never edited from the UI.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Document description', 'Document descriptions', $nb, 'documentaliste');
    }

    public static function getIcon(): string
    {
        return 'ti ti-file-description';
    }

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    /**
     * Get the description of a document, or null if none exists yet.
     */
    public static function getForDocument(int $documents_id): ?self
    {
        $description = new self();
        if ($description->getFromDBByCrit(['documents_id' => $documents_id])) {
            return $description;
        }

        return null;
    }

    /**
     * Is the description available (analysis done)?
     */
    public function isDone(): bool
    {
        return ($this->fields['status'] ?? null) === self::STATUS_DONE;
    }

    /**
     * @return list<string>
     */
    public function getKeywords(): array
    {
        return $this->decodeList($this->fields['keywords'] ?? null);
    }

    /**
     * Sensitive content categories (see SensitiveContentDetector::TYPE_*).
     * The detected values themselves are never stored.
     *
     * @return list<string>
     */
    public function getDetectedTypes(): array
    {
        return $this->decodeList($this->fields['detected_types'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Queue management
    // ---------------------------------------------------------------------

    /**
     * Create or reset the description of a document so it gets (re)analyzed.
     */
    public static function queueDocument(Document $document): void
    {
        $documents_id = (int) $document->getID();
        if ($documents_id <= 0) {
            return;
        }

        $input = [
            'documents_id'   => $documents_id,
            'sha1sum'        => $document->fields['sha1sum'] ?? null,
            'status'         => self::STATUS_PENDING,
            'summary'        => null,
            'keywords'       => null,
            'detected_types' => null,
            'sensitivity'    => SensitiveContentDetector::LEVEL_NONE,
            'describer'      => null,
            'model'          => null,
            'attempts'       => 0,
            'error_message'  => null,
            'date_analyzed'  => null,
        ];

        $existing = self::getForDocument($documents_id);
        if ($existing instanceof Description) {
            $existing->update(['id' => $existing->getID()] + $input);
        } else {
            (new self())->add($input);
        }
    }

    public static function removeForDocument(int $documents_id): void
    {
        (new self())->deleteByCriteria(['documents_id' => $documents_id]);
    }

    // ---------------------------------------------------------------------
    // Automatic action
    // ---------------------------------------------------------------------

    /**
     * @return array{description: string, parameter?: string}
     */
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            self::CRON_ANALYZE => [
                'description' => __('Describe pending documents', 'documentaliste'),
                'parameter'   => __('Number of documents to process per run', 'documentaliste'),
            ],
            default => ['description' => ''],
        };
    }

    /**
     * @return int 0 = nothing to do, 1 = done
     */
    public static function cronAnalyze(CronTask $task): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $limit = Fields::int($task->fields, 'param');
        if ($limit <= 0) {
            $limit = self::CRON_DEFAULT_BATCH_SIZE;
        }

        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'attempts' => ['<', self::MAX_ATTEMPTS],
                'OR'       => [
                    ['status' => self::STATUS_PENDING],
                    // Results produced by an older analyzer are refreshed.
                    [
                        'status'           => [self::STATUS_DONE, self::STATUS_SKIPPED],
                        'analyzer_version' => ['<', Analyzer::VERSION],
                    ],
                ],
            ],
            'ORDER'  => ['id ASC'],
            'LIMIT'  => $limit,
        ]);

        $analyzer  = Analyzer::createDefault();
        $processed = 0;
        foreach ($rows as $row) {
            $description = new self();
            if (!is_array($row) || !$description->getFromDB(Fields::int($row, 'id'))) {
                continue;
            }

            $description->process($analyzer);
            $task->addVolume(1);
            $processed++;
        }

        return $processed > 0 ? 1 : 0;
    }

    /**
     * Analyze the linked document and store the result.
     */
    public function process(Analyzer $analyzer): void
    {
        $document = new Document();
        if (!$document->getFromDB(Fields::int($this->fields, 'documents_id'))) {
            // Document vanished in the meantime.
            $this->delete(['id' => $this->getID()], true);
            return;
        }

        try {
            $result = $this->findReusableResult(Fields::string($document->fields, 'sha1sum'))
                ?? $analyzer->analyze($document);
        } catch (Throwable $throwable) {
            $attempts = Fields::int($this->fields, 'attempts') + 1;
            $this->update([
                'id'            => $this->getID(),
                'attempts'      => $attempts,
                'status'        => $attempts >= self::MAX_ATTEMPTS ? self::STATUS_ERROR : self::STATUS_PENDING,
                'error_message' => mb_substr($throwable->getMessage(), 0, 1000),
            ]);
            return;
        }

        $this->update([
            'id'             => $this->getID(),
            'sha1sum'        => $document->fields['sha1sum'] ?? null,
            'status'         => $result->status,
            'summary'        => $result->summary,
            'keywords'       => json_encode($result->keywords, JSON_THROW_ON_ERROR),
            'detected_types' => json_encode($result->detected_types, JSON_THROW_ON_ERROR),
            'sensitivity'    => $result->sensitivity,
            'describer'      => $result->describer,
            'model'          => $result->model,
            'analyzer_version' => Analyzer::VERSION,
            'error_message'  => null,
            'date_analyzed'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);

        if ($this->getFromDB($this->getID())) {
            Plugin::doHook(self::HOOK_DESCRIBED, $this);
        }
    }

    /**
     * Reuse the analysis of another document sharing the same content.
     */
    private function findReusableResult(string $sha1sum): ?DescriptionResult
    {
        if ($sha1sum === '') {
            return null;
        }

        /** @var DBmysql $DB */
        global $DB;

        // Not getFromDBByCrit(): it fails as soon as several copies share the same content.
        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'sha1sum'          => $sha1sum,
                'status'           => self::STATUS_DONE,
                // Only results of the current analyzer: never copy an outdated (less safe) result.
                'analyzer_version' => Analyzer::VERSION,
                'NOT'              => ['id' => $this->getID()],
            ],
            'LIMIT'  => 1,
        ]);
        $row = $rows->current();

        $other = new self();
        if (!is_array($row) || !$other->getFromDB(Fields::int($row, 'id'))) {
            return null;
        }

        return new DescriptionResult(
            status: self::STATUS_DONE,
            summary: Fields::string($other->fields, 'summary'),
            keywords: $other->getKeywords(),
            detected_types: $other->getDetectedTypes(),
            sensitivity: Fields::int($other->fields, 'sensitivity'),
            describer: Fields::string($other->fields, 'describer'),
            model: Fields::nullableString($other->fields, 'model'),
        );
    }

    // ---------------------------------------------------------------------
    // UI
    // ---------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Document || !self::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Document) {
            return false;
        }

        $description = self::getForDocument((int) $item->getID());
        TemplateRenderer::getInstance()->display('@documentaliste/description_tab.html.twig', [
            'description'    => $description,
            'keywords'       => $description?->getKeywords() ?? [],
            'detected_types' => $description?->getDetectedTypes() ?? [],
        ]);
        return true;
    }

    // ---------------------------------------------------------------------
    // Search engine
    // ---------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getSearchOptionsForDocument(): array
    {
        $join = ['jointype' => 'child'];

        return [
            [
                'id'            => self::SO_SUMMARY,
                'table'         => self::getTable(),
                'field'         => 'summary',
                'name'          => __('Synthetic description', 'documentaliste'),
                'datatype'      => 'text',
                'massiveaction' => false,
                'joinparams'    => $join,
            ],
            [
                'id'            => self::SO_KEYWORDS,
                'table'         => self::getTable(),
                'field'         => 'keywords',
                'name'          => __('Keywords', 'documentaliste'),
                'datatype'      => 'text',
                'massiveaction' => false,
                'joinparams'    => $join,
            ],
            [
                'id'            => self::SO_SENSITIVITY,
                'table'         => self::getTable(),
                'field'         => 'sensitivity',
                'name'          => __('Sensitivity level', 'documentaliste'),
                'datatype'      => 'number',
                'massiveaction' => false,
                'joinparams'    => $join,
            ],
            [
                'id'            => self::SO_STATUS,
                'table'         => self::getTable(),
                'field'         => 'status',
                'name'          => __('Analysis status', 'documentaliste'),
                'datatype'      => 'string',
                'massiveaction' => false,
                'joinparams'    => $join,
            ],
            [
                'id'            => self::SO_DETECTED,
                'table'         => self::getTable(),
                'field'         => 'detected_types',
                'name'          => __('Detected sensitive content', 'documentaliste'),
                'datatype'      => 'text',
                'massiveaction' => false,
                'joinparams'    => $join,
            ],
        ];
    }

    // ---------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function decodeList(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }
}
