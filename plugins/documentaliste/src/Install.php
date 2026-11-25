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

use CronTask;
use DBConnection;
use DBmysql;
use Migration;

/**
 * Database schema and automatic actions management.
 *
 * Migrations must stay idempotent: `install()` is also called on plugin update.
 */
final class Install
{
    public static function install(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $table     = Description::getTable();
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage(sprintf('Creating table %s', $table));
            $DB->doQuery(
                <<<SQL
                CREATE TABLE `{$table}` (
                    `id` int {$sign} NOT NULL AUTO_INCREMENT,
                    `documents_id` int {$sign} NOT NULL DEFAULT '0',
                    `sha1sum` char(40) DEFAULT NULL,
                    `status` varchar(20) NOT NULL DEFAULT 'pending',
                    `summary` text,
                    `keywords` text,
                    `detected_types` text,
                    `sensitivity` tinyint NOT NULL DEFAULT '0',
                    `describer` varchar(100) DEFAULT NULL,
                    `model` varchar(100) DEFAULT NULL,
                    `attempts` int NOT NULL DEFAULT '0',
                    `analyzer_version` int NOT NULL DEFAULT '0',
                    `error_message` text,
                    `date_analyzed` timestamp NULL DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `documents_id` (`documents_id`),
                    KEY `sha1sum` (`sha1sum`),
                    KEY `status` (`status`),
                    KEY `analyzer_version` (`analyzer_version`),
                    KEY `sensitivity` (`sensitivity`),
                    KEY `date_analyzed` (`date_analyzed`),
                    KEY `date_creation` (`date_creation`),
                    KEY `date_mod` (`date_mod`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC
                SQL,
            );
        }

        // Upgrades from 0.1.0 (no-op on fresh installs: addField/addKey skip existing ones).
        $migration->addField($table, 'analyzer_version', 'integer', ['value' => 0, 'after' => 'attempts']);
        $migration->addKey($table, 'analyzer_version');

        CronTask::register(
            Description::class,
            Description::CRON_ANALYZE,
            5 * MINUTE_TIMESTAMP,
            [
                'param'   => Description::CRON_DEFAULT_BATCH_SIZE,
                'state'   => CronTask::STATE_WAITING,
                'mode'    => CronTask::MODE_EXTERNAL,
                'comment' => 'Documentaliste: describe pending documents',
            ],
        );
    }

    public static function uninstall(Migration $migration): void
    {
        // Automatic actions are unregistered by GLPI itself after uninstall.
        $migration->dropTable(Description::getTable());
    }
}
