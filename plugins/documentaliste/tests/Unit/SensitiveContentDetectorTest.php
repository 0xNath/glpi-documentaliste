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
use GlpiPlugin\Documentaliste\SensitiveContentDetector;
use PHPUnit\Framework\Attributes\DataProvider;

final class SensitiveContentDetectorTest extends GLPITestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function textProvider(): iterable
    {
        yield 'empty' => ['', []];
        yield 'harmless' => ['Bonjour, mon imprimante ne fonctionne plus depuis ce matin.', []];
        yield 'word password alone' => ['Je ne me souviens plus de mon mot de passe.', []];
        yield 'fr password' => ["Identifiant : jdupont\nMot de passe : Sup3rS3cret!", [SensitiveContentDetector::TYPE_PASSWORD]];
        yield 'en password' => ['login=admin password=hunter22', [SensitiveContentDetector::TYPE_PASSWORD]];
        yield 'mdp' => ['mdp: azerty123', [SensitiveContentDetector::TYPE_PASSWORD]];
        yield 'private key' => ["-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n", [SensitiveContentDetector::TYPE_PRIVATE_KEY]];
        yield 'rsa private key' => ['-----BEGIN RSA PRIVATE KEY-----', [SensitiveContentDetector::TYPE_PRIVATE_KEY]];
        yield 'aws key' => ['key AKIAIOSFODNN7EXAMPLE here', [SensitiveContentDetector::TYPE_API_TOKEN]];
        yield 'github token' => ['ghp_' . str_repeat('a1B2', 9), [SensitiveContentDetector::TYPE_API_TOKEN]];
        yield 'valid iban' => ['IBAN FR76 3000 6000 0112 3456 7890 189', [SensitiveContentDetector::TYPE_IBAN]];
        yield 'invalid iban' => ['IBAN FR76 3000 6000 0112 3456 7890 188', []];
        yield 'valid card' => ['CB 4111 1111 1111 1111', [SensitiveContentDetector::TYPE_CREDIT_CARD]];
        yield 'invalid card' => ['N° 4111 1111 1111 1112', []];
        yield 'phone number is not a card' => ['Tel 01 23 45 67 89', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('textProvider')]
    public function testDetect(string $text, array $expected): void
    {
        $this->assertSame($expected, (new SensitiveContentDetector())->detect($text));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function redactProvider(): iterable
    {
        yield 'password and login' => [
            "Identifiant : jdupont\nMot de passe : Ete2026Paris\nMerci",
            "Identifiant [login]\n[password]\nMerci",
            ['jdupont', 'Ete2026Paris', 'Paris'],
        ];
        yield 'private key block' => [
            "avant\n-----BEGIN RSA PRIVATE KEY-----\nMIIEabc\n-----END RSA PRIVATE KEY-----\naprès",
            "avant\n[private_key]\naprès",
            ['MIIEabc'],
        ];
        yield 'token' => ['clé AKIAIOSFODNN7EXAMPLE ok', 'clé [api_token] ok', ['AKIAIOSFODNN7EXAMPLE']];
        yield 'valid iban only' => [
            'A FR76 3000 6000 0112 3456 7890 189 B FR76 3000 6000 0112 3456 7890 188',
            'A [iban] B FR76 3000 6000 0112 3456 7890 188',
            ['7890 189'],
        ];
        yield 'card' => ['CB 4111 1111 1111 1111.', 'CB [credit_card].', ['4111']];
        yield 'harmless text untouched' => ['Imprimante en panne', 'Imprimante en panne', []];
    }

    /**
     * @param list<string> $leaks
     */
    #[DataProvider('redactProvider')]
    public function testRedact(string $text, string $expected, array $leaks): void
    {
        $redacted = (new SensitiveContentDetector())->redact($text);

        $this->assertSame($expected, $redacted);
        foreach ($leaks as $leak) {
            $this->assertStringNotContainsString($leak, $redacted);
        }
    }

    public function testLevel(): void
    {
        $this->assertSame(SensitiveContentDetector::LEVEL_NONE, SensitiveContentDetector::getLevel([]));
        $this->assertSame(
            SensitiveContentDetector::LEVEL_PERSONAL,
            SensitiveContentDetector::getLevel([SensitiveContentDetector::TYPE_IBAN]),
        );
        $this->assertSame(
            SensitiveContentDetector::LEVEL_SECRET,
            SensitiveContentDetector::getLevel([SensitiveContentDetector::TYPE_IBAN, SensitiveContentDetector::TYPE_PASSWORD]),
        );
    }
}
