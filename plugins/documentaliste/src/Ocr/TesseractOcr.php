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

namespace GlpiPlugin\Documentaliste\Ocr;

use RuntimeException;

use function Safe\fclose;
use function Safe\fread;
use function Safe\proc_close;
use function Safe\stream_set_blocking;

/**
 * Local OCR with Tesseract 5 (https://github.com/tesseract-ocr/tesseract).
 *
 * Why Tesseract: open source, CPU only (no GPU), well under 1 s per screenshot,
 * available as a standard package on every Linux distribution, no network access.
 * Install: `apt install tesseract-ocr tesseract-ocr-fra tesseract-ocr-eng`.
 *
 * Uses native `proc_open()` with an argument array (no shell involved).
 * symfony/process is NOT usable here: it is only a dev dependency of GLPI 11/12.
 * This is the only file allowed to run an external program (see NoNetworkEgressTest).
 */
final readonly class TesseractOcr implements OcrEngineInterface
{
    public const BINARY_NAME = 'tesseract';

    public const DEFAULT_LANGUAGES = ['fra', 'eng'];

    private const TIMEOUT = 60.0;

    private const MAX_OUTPUT = 5 * 1024 * 1024;

    /** Searched before $PATH (cron/web contexts may have a minimal PATH). */
    private const DEFAULT_DIRS = ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin'];

    /**
     * @param string       $binary    absolute path to the tesseract executable
     * @param list<string> $languages tesseract language codes, all installed
     */
    public function __construct(
        private string $binary,
        private array $languages,
        private string $version = '',
    ) {}

    /**
     * Find tesseract on the server and keep only the installed languages.
     * Returns null if tesseract is not installed.
     *
     * @param list<string> $wanted_languages
     */
    public static function detect(array $wanted_languages = self::DEFAULT_LANGUAGES): ?self
    {
        $binary = self::findBinary();
        if ($binary === null) {
            return null;
        }

        $installed = self::listInstalledLanguages($binary);
        $languages = array_values(array_intersect($wanted_languages, $installed));
        if ($languages === []) {
            // Keep working with whatever is installed, except the orientation-only model.
            $languages = array_values(array_diff($installed, ['osd']));
        }

        if ($languages === []) {
            return null;
        }

        return new self($binary, $languages, self::readVersion($binary));
    }

    public function getName(): string
    {
        return trim(self::BINARY_NAME . ' ' . $this->version) . ' (' . implode('+', $this->languages) . ')';
    }

    public function recognize(string $image_path): string
    {
        // `--psm 3`: fully automatic page segmentation, suited for screenshots and scans.
        [$exit_code, $stdout, $stderr] = self::run(
            $this->binary,
            [$image_path, 'stdout', '-l', implode('+', $this->languages), '--psm', '3'],
            self::TIMEOUT,
        );

        if ($exit_code !== 0) {
            throw new RuntimeException('OCR failed: ' . mb_substr(trim($stderr), 0, 500));
        }

        return $stdout;
    }

    private static function findBinary(): ?string
    {
        $path_env = getenv('PATH');
        $dirs = array_merge(self::DEFAULT_DIRS, is_string($path_env) ? explode(PATH_SEPARATOR, $path_env) : []);
        foreach (array_unique($dirs) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . self::BINARY_NAME;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function listInstalledLanguages(string $binary): array
    {
        [$exit_code, $stdout] = self::run($binary, ['--list-langs'], 10.0);
        if ($exit_code !== 0) {
            return [];
        }

        $languages = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            // First line is a header like `List of available languages in "..." (3):`
            if ($line !== '' && !str_contains($line, ' ')) {
                $languages[] = $line;
            }
        }

        return $languages;
    }

    private static function readVersion(string $binary): string
    {
        [, $stdout, $stderr] = self::run($binary, ['--version'], 10.0);

        $first_line = strtok($stdout . $stderr, "\n");
        $parts = is_string($first_line) ? explode(' ', trim($first_line)) : [];

        return $parts[1] ?? '';
    }

    /**
     * Run the tesseract binary without shell, with a timeout and bounded output.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function run(string $binary, array $arguments, float $timeout): array
    {
        if (basename($binary) !== self::BINARY_NAME) {
            throw new RuntimeException('Only the tesseract binary may be executed.');
        }

        $env = getenv();
        // One thread per run: predictable CPU usage on the GLPI server.
        $env['OMP_THREAD_LIMIT'] = '1';

        $pipes = [];
        // Native proc_open() on purpose: Safe\proc_open() only accepts a string command,
        // which would be run through a shell. The argument array form never uses a shell.
        /** @phpstan-ignore theCodingMachineSafe.function */
        $process = proc_open(
            array_merge([$binary], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($process) || !isset($pipes[0], $pipes[1], $pipes[2])) {
            throw new RuntimeException('Unable to start tesseract.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output   = [1 => '', 2 => ''];
        $open     = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + $timeout;

        while ($open !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                proc_terminate($process, 9);
                proc_close($process);
                throw new RuntimeException(sprintf('tesseract timed out after %d s.', (int) $timeout));
            }

            $read = array_values($open);
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            if (@stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000)) === false) {
                break;
            }

            foreach ($open as $index => $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk !== '' && strlen($output[$index]) < self::MAX_OUTPUT) {
                    $output[$index] .= $chunk;
                }

                if (feof($stream)) {
                    fclose($stream);
                    unset($open[$index]);
                }
            }
        }

        $exit_code = proc_close($process);

        return [$exit_code, $output[1], $output[2]];
    }
}
