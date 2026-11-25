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

namespace GlpiPlugin\Documentaliste\Tests\Unit;

use Glpi\Tests\GLPITestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guard rail for the project rule: document data must never leave the GLPI server.
 *
 * Fails as soon as the plugin code uses a network primitive or an HTTP client,
 * or runs an external program other than the allowed local tools.
 * Changing the allow lists below requires an explicit review of the data flow.
 */
final class NoNetworkEgressTest extends GLPITestCase
{
    /** Functions able to open a network connection or run arbitrary programs. */
    private const FORBIDDEN_FUNCTIONS = [
        'curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen',
        'stream_socket_client', 'socket_create', 'socket_connect', 'ftp_connect',
        'ftp_ssl_connect', 'ssh2_connect', 'ldap_connect', 'mail', 'dns_get_record',
        'gethostbyname', 'get_headers', 'exec', 'shell_exec', 'system', 'passthru',
        'popen', 'pcntl_exec',
    ];

    /** Functions running external programs: only allowed in reviewed files. */
    private const PROCESS_FUNCTIONS = ['proc_open'];

    /** Classes / namespaces of HTTP clients and GLPI network helpers. */
    private const FORBIDDEN_CLASS_PATTERNS = [
        'GuzzleHttp\\',
        'Symfony\\Component\\HttpClient',
        'Symfony\\Contracts\\HttpClient',
        'Psr\\Http\\Client',
        'Toolbox::callCurl',
        'GLPINetwork',
        'GLPIMailer',
        'SoapClient',
    ];

    /** Files allowed to run a (local) external program, and the only binary they may run. */
    private const PROCESS_ALLOWED_FILES = [
        'src/Ocr/TesseractOcr.php' => 'tesseract',
    ];

    public function testNoNetworkPrimitiveInPluginCode(): void
    {
        foreach ($this->getPhpFiles() as $relative_path => $code) {
            foreach (token_get_all($code) as $token) {
                if (!is_array($token) || $token[0] !== T_STRING) {
                    continue;
                }

                if (in_array(strtolower($token[1]), self::PROCESS_FUNCTIONS, true)) {
                    $this->assertArrayHasKey(
                        $relative_path,
                        self::PROCESS_ALLOWED_FILES,
                        sprintf('%s:%d runs external processes but is not in the reviewed allow list.', $relative_path, $token[2]),
                    );
                    continue;
                }

                $this->assertNotContains(
                    strtolower($token[1]),
                    self::FORBIDDEN_FUNCTIONS,
                    sprintf('Forbidden network/exec function "%s" in %s:%d', $token[1], $relative_path, $token[2]),
                );
            }

            foreach (self::FORBIDDEN_CLASS_PATTERNS as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $code,
                    sprintf('Forbidden network client "%s" in %s', $pattern, $relative_path),
                );
            }

            // Remote stream wrappers (file_get_contents('https://...'), fopen('ftp://...'), ...)
            $this->assertDoesNotMatchRegularExpression(
                '#[\'"](?:https?|ftps?|ssh2\.[a-z]+|tcp|udp|ssl|tls)://#i',
                $this->stripComments($code),
                sprintf('Remote URL literal in %s', $relative_path),
            );
        }
    }

    public function testExternalProcessesAreLimitedToLocalTools(): void
    {
        foreach ($this->getPhpFiles() as $relative_path => $code) {
            $this->assertStringNotContainsString(
                'Symfony\\Component\\Process',
                $code,
                sprintf('%s: symfony/process is only a GLPI dev dependency, it is missing in production.', $relative_path),
            );

            if (!isset(self::PROCESS_ALLOWED_FILES[$relative_path])) {
                continue;
            }

            $binary = self::PROCESS_ALLOWED_FILES[$relative_path];
            $this->assertStringContainsString(
                sprintf("BINARY_NAME = '%s'", $binary),
                $code,
                sprintf('%s must only run "%s".', $relative_path, $binary),
            );
            $this->assertStringContainsString(
                'basename($binary) !== self::BINARY_NAME',
                $code,
                sprintf('%s must refuse to run any binary other than "%s".', $relative_path, $binary),
            );
        }
    }

    /**
     * @return array<string, string> relative path => code
     */
    private function getPhpFiles(): array
    {
        $root  = dirname(__DIR__, 2);
        $files = [];
        foreach (['src', 'setup.php', 'hook.php'] as $entry) {
            $path = $root . '/' . $entry;
            if (is_file($path)) {
                $files[$entry] = (string) file_get_contents($path);
                continue;
            }

            /** @var iterable<SplFileInfo> $iterator */
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $relative = substr($file->getPathname(), strlen($root) + 1);
                    $files[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        $this->assertNotEmpty($files);
        foreach ($files as $relative_path => $code) {
            $this->assertNotSame('', trim($code), sprintf('%s is empty.', $relative_path));
        }

        return $files;
    }

    private function stripComments(string $code): string
    {
        $result = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $result .= is_array($token) ? $token[1] : $token;
        }

        return $result;
    }
}
