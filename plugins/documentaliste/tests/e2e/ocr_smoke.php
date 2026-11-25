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

/**
 * End-to-end smoke test, run inside a RUNTIME GLPI container (official production image
 * + Tesseract), to catch what the QA image cannot: e.g. GLPI dev-only dependencies.
 *
 * Usage (see `make e2e`):
 *   php plugins/documentaliste/tests/e2e/ocr_smoke.php add      -> adds the fixture as a document
 *   php front/cron.php --force analyze
 *   php plugins/documentaliste/tests/e2e/ocr_smoke.php verify   -> checks the stored description
 */

use Glpi\Kernel\Kernel;
use GlpiPlugin\Documentaliste\Description;
use GlpiPlugin\Documentaliste\SensitiveContentDetector;

$glpi_root = dirname(__DIR__, 4);
require $glpi_root . '/vendor/autoload.php';

$kernel = new Kernel('production');
$kernel->boot();

$state_file = sys_get_temp_dir() . '/documentaliste_e2e_document_id';
$mode = $argv[1] ?? '';

function e2e_fail(string $message): never
{
    fwrite(STDERR, '❌ ' . $message . PHP_EOL);
    exit(1);
}

if (!Plugin::isPluginActive('documentaliste')) {
    e2e_fail('Plugin documentaliste is not active.');
}

if ($mode === 'add') {
    Session::setPath();
    Session::start();
    $auth = new Auth();
    $auth->auth_succeded = true;
    $auth->user = new User();
    $auth->user->getFromDBbyName('glpi');
    Session::init($auth);

    $name = 'e2e_capture_' . uniqid() . '.png';
    copy(__DIR__ . '/../fixtures/ocr_login_screenshot.png', GLPI_TMP_DIR . '/' . $name);

    $document = new Document();
    $id = (int) $document->add(['filename' => $name, 'entities_id' => 0, '_filename' => [$name]]);
    if ($id <= 0) {
        e2e_fail('Unable to create the document.');
    }

    $description = Description::getForDocument($id);
    if (!$description instanceof Description || $description->fields['status'] !== Description::STATUS_PENDING) {
        e2e_fail('Document was not queued for analysis.');
    }

    file_put_contents($state_file, (string) $id);
    echo sprintf('✅ GLPI %s: document %d queued.', GLPI_VERSION, $id) . PHP_EOL;
    exit(0);
}

if ($mode === 'verify') {
    $id = (int) @file_get_contents($state_file);
    $description = Description::getForDocument($id);
    if (!$description instanceof Description) {
        e2e_fail('No description found for document ' . $id);
    }

    $fields = $description->fields;
    $stored = mb_strtolower(implode(' ', [$fields['summary'], $fields['keywords'], $fields['error_message']]));
    $checks = [
        'analysis done (status: ' . $fields['status'] . ', error: ' . $fields['error_message'] . ')' => $description->isDone(),
        'OCR used (model: ' . $fields['model'] . ')' => str_starts_with((string) $fields['model'], 'tesseract'),
        'password detected' => in_array(SensitiveContentDetector::TYPE_PASSWORD, $description->getDetectedTypes(), true),
        'text recognized' => in_array('serveur', $description->getKeywords(), true),
        'no fragment of the secret or login stored' => preg_match('/ete2026|paris|jdupont/', $stored) === 0,
    ];

    foreach ($checks as $label => $ok) {
        echo ($ok ? '✅ ' : '❌ ') . $label . PHP_EOL;
    }

    exit(in_array(false, $checks, true) ? 1 : 0);
}

e2e_fail('Usage: ocr_smoke.php add|verify');
