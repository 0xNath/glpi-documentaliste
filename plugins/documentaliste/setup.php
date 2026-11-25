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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Documentaliste\Description;
use GlpiPlugin\Documentaliste\DocumentListener;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_DOCUMENTALISTE_VERSION', '0.2.0');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_DOCUMENTALISTE_MIN_GLPI_VERSION', '11.0.0');

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_DOCUMENTALISTE_MAX_GLPI_VERSION', '12.0.99');

/**
 * Init hooks of the plugin.
 */
function plugin_init_documentaliste(): void
{
    /** @var array<string, array<string, mixed>> $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    // Queue documents for analysis whenever a file is added or replaced.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['documentaliste'] = [
        Document::class => DocumentListener::onDocumentAdd(...),
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['documentaliste'] = [
        Document::class => DocumentListener::onDocumentUpdate(...),
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['documentaliste'] = [
        Document::class => DocumentListener::onDocumentPurge(...),
    ];

    // "Description" tab on documents.
    Plugin::registerClass(Description::class, ['addtabon' => [Document::class]]);
}

/**
 * Get the name and the version of the plugin.
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_documentaliste(): array
{
    return [
        'name'           => 'Documentaliste',
        'version'        => PLUGIN_DOCUMENTALISTE_VERSION,
        'author'         => 'Documentaliste plugin team',
        'license'        => 'MIT',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_DOCUMENTALISTE_MIN_GLPI_VERSION,
                'max' => PLUGIN_DOCUMENTALISTE_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install.
 */
function plugin_documentaliste_check_prerequisites(): bool
{
    if (!class_exists(ZipArchive::class)) {
        echo __('The PHP "zip" extension is required.', 'documentaliste');
        return false;
    }

    return true;
}

/**
 * Check configuration process.
 */
function plugin_documentaliste_check_config(bool $verbose = false): bool
{
    return true;
}
