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

use function Safe\preg_match;
use function Safe\preg_match_all;
use function Safe\preg_replace;
use function Safe\preg_replace_callback;

/**
 * Fast, local, synchronous detection of sensitive content in a text.
 *
 * Designed to be reusable from blocking hooks (e.g. `pre_item_add` on ITILFollowup)
 * as well as from the asynchronous analysis. It only returns categories:
 * the matched values must never be stored or logged.
 */
final class SensitiveContentDetector
{
    public const TYPE_PASSWORD    = 'password';

    public const TYPE_PRIVATE_KEY = 'private_key';

    public const TYPE_API_TOKEN   = 'api_token';

    public const TYPE_IBAN        = 'iban';

    public const TYPE_CREDIT_CARD = 'credit_card';

    public const LEVEL_NONE     = 0;

    public const LEVEL_PERSONAL = 1;

    public const LEVEL_SECRET   = 2;

    /** Max scanned length, to keep the detection fast on huge inputs. */
    private const MAX_SCANNED_LENGTH = 1_000_000;

    private const PASSWORD_PATTERN = '/(?:\b(?:password|passwd|pwd|passphrase|mdp)|mot\s+de\s+passe)\s*[:=]\s*\S{4,}/iu';

    /** Login identifier next to a label; only used for redaction (not a detection type). */
    private const LOGIN_PATTERN = '/\b(identifiant|login|username|user|utilisateur|compte)\s*[:=]\s*\S+/iu';

    private const PRIVATE_KEY_PATTERN = '/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----/';

    private const API_TOKEN_PATTERN = '/\b(?:'
        . 'AKIA[0-9A-Z]{16}'                    // AWS access key
        . '|gh[pousr]_[A-Za-z0-9]{36,}'         // GitHub token
        . '|github_pat_[A-Za-z0-9_]{40,}'       // GitHub fine-grained token
        . '|glpat-[A-Za-z0-9_-]{20,}'           // GitLab token
        . '|xox[abpr]-[A-Za-z0-9-]{10,}'        // Slack token
        . '|sk-(?:ant-|proj-)?[A-Za-z0-9_-]{20,}' // Anthropic / OpenAI keys
        . ')/';

    private const IBAN_PATTERN = '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,4})?\b/';

    private const CARD_PATTERN = '/\b\d(?:[ -]?\d){12,18}\b/';

    /**
     * @return list<string> detected TYPE_* values, without duplicates
     */
    public function detect(string $text): array
    {
        if ($text === '') {
            return [];
        }

        if (strlen($text) > self::MAX_SCANNED_LENGTH) {
            $text = mb_strcut($text, 0, self::MAX_SCANNED_LENGTH);
        }

        $types = [];
        if (preg_match(self::PASSWORD_PATTERN, $text) === 1) {
            $types[] = self::TYPE_PASSWORD;
        }

        if (preg_match(self::PRIVATE_KEY_PATTERN, $text) === 1) {
            $types[] = self::TYPE_PRIVATE_KEY;
        }

        if (preg_match(self::API_TOKEN_PATTERN, $text) === 1) {
            $types[] = self::TYPE_API_TOKEN;
        }

        if ($this->matchesWithChecksum(self::IBAN_PATTERN, $text, self::isValidIban(...))) {
            $types[] = self::TYPE_IBAN;
        }

        if ($this->matchesWithChecksum(self::CARD_PATTERN, $text, self::isValidCardNumber(...))) {
            $types[] = self::TYPE_CREDIT_CARD;
        }

        return $types;
    }

    /**
     * @param list<string> $types
     */
    public static function getLevel(array $types): int
    {
        $secrets = [self::TYPE_PASSWORD, self::TYPE_PRIVATE_KEY, self::TYPE_API_TOKEN];
        if (array_intersect($types, $secrets) !== []) {
            return self::LEVEL_SECRET;
        }

        return $types === [] ? self::LEVEL_NONE : self::LEVEL_PERSONAL;
    }

    /**
     * Replace sensitive values by placeholders, e.g. "Mot de passe : S3cret!" → "[password]".
     *
     * Must be applied to any text before deriving stored data from it (keywords, and the
     * future local LLM prompt): otherwise fragments of a secret ("Ete2026Paris" → "paris")
     * could end up stored. Login identifiers are redacted too, as they pair with passwords.
     */
    public function redact(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = $this->replace('/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----.*?(?:-----END (?:[A-Z0-9]+ )*PRIVATE KEY-----|$)/s', '[private_key]', $text);
        $text = $this->replace(self::PASSWORD_PATTERN, '[password]', $text);
        $text = $this->replace(self::LOGIN_PATTERN, '$1 [login]', $text);
        $text = $this->replace(self::API_TOKEN_PATTERN, '[api_token]', $text);
        $text = $this->replaceValid(self::IBAN_PATTERN, '[iban]', $text, self::isValidIban(...));

        return $this->replaceValid(self::CARD_PATTERN, '[credit_card]', $text, self::isValidCardNumber(...));
    }

    private function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        return is_string($result) ? $result : '';
    }

    /**
     * @param callable(string): bool $validator
     */
    private function replaceValid(string $pattern, string $replacement, string $subject, callable $validator): string
    {
        $result = preg_replace_callback(
            $pattern,
            static function (array $match) use ($validator, $replacement): string {
                $candidate = is_string($match[0] ?? null) ? $match[0] : '';

                return $validator($candidate) ? $replacement : $candidate;
            },
            $subject,
        );

        return is_string($result) ? $result : '';
    }

    /**
     * @param callable(string): bool $validator
     */
    private function matchesWithChecksum(string $pattern, string $text, callable $validator): bool
    {
        preg_match_all($pattern, $text, $matches);
        $candidates = is_array($matches) && is_array($matches[0] ?? null) ? $matches[0] : [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $validator($candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function isValidIban(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        // mod 97 on a big number, chunk by chunk
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    public static function isValidCardNumber(string $number): bool
    {
        $digits = (string) preg_replace('/\D/', '', $number);
        $length = strlen($digits);
        if ($length < 13 || $length > 19 || count(array_unique(str_split($digits))) === 1) {
            return false;
        }

        // Luhn algorithm
        $sum = 0;
        $double = false;
        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
