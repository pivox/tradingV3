<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\MarketData;

use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(PaperMarketEventRedactor::class)]
final class PaperMarketEventRedactorTest extends TestCase
{
    public function testDocumentsThePayloadResourceBudgets(): void
    {
        self::assertSame(20_000, PaperMarketEventRedactor::MAX_PAYLOAD_NODES);
        self::assertSame(1_048_576, PaperMarketEventRedactor::MAX_PAYLOAD_BYTES);
        self::assertSame(1_048_576, PaperMarketEventRedactor::MAX_PAYLOAD_KEY_BYTES);
        self::assertSame(1_048_576, PaperMarketEventRedactor::MAX_PAYLOAD_STRING_BYTES);
        self::assertSame(4, PaperMarketEventRedactor::MAX_SENSITIVE_DECODE_DEPTH);
        self::assertSame(4_096, PaperMarketEventRedactor::MAX_SENSITIVE_DECODE_NODES);
        self::assertSame(1_048_576, PaperMarketEventRedactor::MAX_SENSITIVE_DECODE_BYTES);
    }

    #[DataProvider('sensitiveKeyProvider')]
    public function testRejectsNormalizedSensitiveKeysRecursively(string $key): void
    {
        $payload = [
            'book' => [
                ['bid' => '29999.0'],
                ['nested' => ['public' => [$key => 'synthetic-placeholder']]],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        PaperMarketEventRedactor::assertSafe($payload);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeyProvider(): iterable
    {
        yield 'authorization' => ['authorization'];
        yield 'api_key' => ['api_key'];
        yield 'apikey' => ['apikey'];
        yield 'api_secret' => ['api_secret'];
        yield 'secret_key' => ['secret_key'];
        yield 'passphrase' => ['passphrase'];
        yield 'private_key' => ['private_key'];
        yield 'sign' => ['sign'];
        yield 'signature' => ['signature'];
        yield 'wallet' => ['wallet'];
        yield 'mnemonic' => ['mnemonic'];
        yield 'seed_phrase' => ['seed_phrase'];
        yield 'trimmed and uppercase' => [' Authorization '];
        yield 'hyphen normalized to underscore' => ['API-KEY'];
        yield 'spaces normalized to underscore' => ['Seed Phrase'];
        yield 'compact camel case normalized by lowercase' => ['ApiKey'];
        yield 'camel case API secret' => ['apiSecret'];
        yield 'acronym API secret' => ['APISecret'];
        yield 'camel case secret key' => ['secretKey'];
        yield 'camel case private key' => ['privateKey'];
        yield 'camel case seed phrase' => ['seedPhrase'];
        yield 'common X API key header' => ['X-API-KEY'];
        yield 'OKX access key header' => ['OK-ACCESS-KEY'];
        yield 'OKX access signature header' => ['OK-ACCESS-SIGN'];
        yield 'OKX access passphrase header' => ['OK-ACCESS-PASSPHRASE'];
        yield 'Hyperliquid API secret key alias' => ['API-SECRET-KEY'];
        yield 'camel case access token' => ['accessToken'];
        yield 'camel case client secret' => ['clientSecret'];
        yield 'plural private keys' => ['private_keys'];
        yield 'plural API keys' => ['api_keys'];
        yield 'plural mnemonics' => ['mnemonics'];
        yield 'plural seed phrases' => ['seed_phrases'];
        yield 'numeric signature suffix' => ['signature64'];
        yield 'numeric API key suffix' => ['api_key2'];
        yield 'concatenated private key suffix' => ['private_keymaterial'];
        yield 'concatenated API key suffix' => ['api_keyhint_legacy'];
        yield 'percent-encoded map key' => ['api%5Fkeys'];
        yield 'fully percent-encoded map key' => ['%61%70%69%5F%6B%65%79'];
        yield 'fullwidth Unicode confusables' => ['ａｐｉ＿ｋｅｙ'];
        yield 'fullwidth authorization confusables' => ['ａｕｔｈｏｒｉｚａｔｉｏｎ'];
        yield 'mathematical bold compatibility characters' => ['𝐚𝐩𝐢_𝐤𝐞𝐲'];
        yield 'Cyrillic Unicode confusable' => ["аpi_key"];
    }

    #[DataProvider('jsonUnicodeEscapedDirectMapKeyProvider')]
    public function testRejectsJsonUnicodeEscapedDirectMapKey(string $key): void
    {
        $sentinel = 'synthetic-redaction-sentinel';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe([
                $key => $sentinel,
            ]),
            [$key, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function jsonUnicodeEscapedDirectMapKeyProvider(): iterable
    {
        yield 'JSON Unicode escape' => ['api' . str_repeat('\\', 1) . 'u005fkey'];
        yield 'double-escaped JSON Unicode escape' => ['api' . str_repeat('\\', 2) . 'u005fkey'];
        yield 'quadruply escaped JSON Unicode escape' => ['api' . str_repeat('\\', 4) . 'u005fkey'];
    }

    public function testRejectsBase64EncodedDirectMapKey(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $key = base64_encode('api_key');

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe([
                $key => $sentinel,
            ]),
            [$key, $sentinel],
        );
    }

    #[DataProvider('composedEncodedDirectMapKeyProvider')]
    public function testRejectsComposedEncodedDirectMapKey(string $key): void
    {
        $sentinel = 'synthetic-redaction-sentinel';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe([$key => $sentinel]),
            [$key, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function composedEncodedDirectMapKeyProvider(): iterable
    {
        yield 'percent-encoded Base64 key' => [rawurlencode(base64_encode('api_key'))];
        yield 'percent-encoded JSON Unicode escape' => [rawurlencode('api\\u005fkey')];
    }

    #[DataProvider('prefixedComposedDirectMapKeyProvider')]
    public function testRejectsPrefixedComposedDirectMapKeysAcrossDeterministicPrefixMatrix(
        #[\SensitiveParameter] string $prefix,
        #[\SensitiveParameter] string $composedKey,
    ): void {
        $sentinel = 'synthetic-prefixed-map-key-sentinel';
        $key = $prefix . $composedKey;

        self::assertSensitiveRejectionWithFullTraceWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe([$key => $sentinel]),
            [$key, $sentinel],
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function prefixedComposedDirectMapKeyProvider(): iterable
    {
        $composedKeys = [
            'percent-composed JSON Unicode key' => '%22api%5Cu005fkey%22',
            'Base64 key' => 'YXBpX2tleQ==',
            'Base64 key with non-ASCII suffix' => base64_encode("api_key\u{1F4A5}"),
            'Base64 key with non-ASCII separator' => base64_encode("api\u{1F4A5}key"),
            'Base64 key with invalid UTF-8 prefix' => base64_encode("\xFFapi_key"),
            'Base64 key with invalid UTF-8 suffix' => base64_encode("api_key\xFF"),
            'unpadded Base64 key with invalid UTF-8 prefix' => rtrim(base64_encode("\xFFapi_key"), '='),
            'folded Base64 key' => 'YX Bp X2 tl eQ==',
            'Base64 key in malformed quoted trailing escape' => '"YXBpX2tleQ==\\q"',
            'Base64 key in malformed quoted leading escape' => '"\\qYXBpX2tleQ=="',
            'Base64 key in unterminated quoted escape' => \chr(34) . 'YXBpX2tleQ==' . \chr(92),
        ];

        foreach ([
            'dot' => '.',
            'bang' => '!',
            'at sign' => '@',
            'colon' => ':',
            'pipe' => '|',
            'double quote' => \chr(34),
            'encoded double quote' => '%22',
            'double then single quote' => \chr(34) . \chr(39),
            'single then double quote' => \chr(39) . \chr(34),
            'token then double quote' => 'x' . \chr(34),
            'double quote then token' => \chr(34) . 'x',
            'token then encoded double quote' => 'x%22',
            'encoded double quote then token' => '%22x',
            'encoded bang' => '%21',
            'encoded NUL' => '%00',
            'repeated punctuation' => '!!',
            'token then punctuation' => 'x!',
            'punctuation then token' => '!x',
            'token then encoded punctuation' => 'x%21',
            'encoded punctuation then token' => '%21x',
            'mixed encoded bytes' => '%21%00',
            'repeated mixed prefix' => 'x_%21-.',
        ] as $prefixLabel => $prefix) {
            foreach ($composedKeys as $keyLabel => $composedKey) {
                yield $prefixLabel . ', ' . $keyLabel => [$prefix, $composedKey];
            }
        }
    }

    #[DataProvider('ordinaryComposedDirectMapKeyProvider')]
    public function testAllowsOrdinaryComposedDirectMapKeys(string $key): void
    {
        PaperMarketEventRedactor::assertSafe([$key => '29999.0']);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryComposedDirectMapKeyProvider(): iterable
    {
        yield 'Base64 public key with invalid UTF-8 prefix' => [
            '.' . base64_encode("\xFFprice"),
        ];
        yield 'folded Base64 public key' => ['.cH Jp Y2 U='];
        yield 'Base64 public key in malformed quoted trailing escape' => [
            '."cHJpY2U=\\q"',
        ];
        yield 'Base64 public key in malformed quoted leading escape' => [
            '."\\qcHJpY2U="',
        ];
        yield 'Base64 public key in unterminated quoted escape' => [
            '.' . \chr(34) . 'cHJpY2U=' . \chr(92),
        ];
        yield 'embedded JSON Unicode public key' => ['.!"pr\\u0069ce"'];
    }

    #[DataProvider('composedSensitiveFormKeyProvider')]
    public function testRejectsComposedSensitiveFormKeysWithoutDisclosure(string $key): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = $key . '=' . $sentinel;

        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $raw]);
            self::fail('A composed sensitive form key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            $trace = self::renderExceptionTraceChain($exception);
            self::assertStringNotContainsString($raw, $trace);
            self::assertStringNotContainsString($sentinel, $trace);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function composedSensitiveFormKeyProvider(): iterable
    {
        yield 'JSON Unicode escape' => ['api\\u005fkey'];
        yield 'percent-encoded JSON Unicode escape' => ['api%5Cu005fkey'];
        yield 'JSON-wrapped Base64 key' => ['"YXBpX2tleQ=="'];
        yield 'percent-encoded JSON-wrapped Base64 key' => ['%22YXBpX2tleQ%3D%3D'];

        $jsonUnicodeKey = '"api\\u005fkey"';
        $jsonPaddedBase64Key = '"YXBpX2tleQ\\u003d\\u003d"';
        foreach ([
            'space' => ' ',
            'horizontal tab' => "\t",
            'line feed' => "\n",
            'vertical tab' => "\v",
            'form feed' => "\f",
            'carriage return' => "\r",
            'form space' => '+',
            'percent-encoded form space' => '%2B',
        ] as $label => $whitespace) {
            yield 'JSON-wrapped Unicode escape before assignment with ' . $label => [
                $jsonUnicodeKey . $whitespace,
            ];
            yield 'percent-composed JSON-wrapped Unicode escape before assignment with ' . $label => [
                rawurlencode($jsonUnicodeKey) . $whitespace,
            ];
            yield 'JSON-wrapped Base64 padding before assignment with ' . $label => [
                $jsonPaddedBase64Key . $whitespace,
            ];
            yield 'percent-composed JSON-wrapped Base64 padding before assignment with ' . $label => [
                rawurlencode($jsonPaddedBase64Key) . $whitespace,
            ];
        }

        $singleQuote = "'";
        $singleQuotedUnicodeKey = $singleQuote . 'api\\u005fkey' . $singleQuote;
        $singleQuotedBase64Key = $singleQuote . 'YXBpX2tleQ==' . $singleQuote;
        yield 'single-quoted JSON Unicode escape' => [$singleQuotedUnicodeKey];
        yield 'unmatched opening single-quoted JSON Unicode escape' => [
            $singleQuote . 'api\\u005fkey',
        ];
        yield 'unmatched closing single-quoted JSON Unicode escape' => [
            'api\\u005fkey' . $singleQuote,
        ];
        yield 'single-quoted Base64 key with padding' => [$singleQuotedBase64Key];
        yield 'unmatched opening single-quoted Base64 key with padding' => [
            $singleQuote . 'YXBpX2tleQ==',
        ];
        yield 'unmatched closing single-quoted Base64 key with padding' => [
            'YXBpX2tleQ==' . $singleQuote,
        ];
        yield 'percent-composed single-quoted JSON Unicode escape' => [
            rawurlencode($singleQuotedUnicodeKey),
        ];
        yield 'percent-composed single-quoted Base64 key with padding' => [
            rawurlencode($singleQuotedBase64Key),
        ];
        yield 'single-quoted JSON Unicode escape before assignment with form space' => [
            $singleQuotedUnicodeKey . '+',
        ];
        yield 'single-quoted Base64 key before assignment with form space' => [
            $singleQuotedBase64Key . '+',
        ];
        yield 'percent-composed single-quoted JSON Unicode escape before assignment with form space' => [
            rawurlencode($singleQuotedUnicodeKey) . '+',
        ];
        yield 'percent-composed single-quoted Base64 key before assignment with form space' => [
            rawurlencode($singleQuotedBase64Key) . '+',
        ];
    }

    #[DataProvider('laterFormPairLeadingPrefixAndComposedKeyProvider')]
    public function testRejectsLeadingFormSpaceBeforeComposedSensitiveKeyInLaterPair(
        string $prefix,
        string $composedKey,
    ): void {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'symbol=BTCUSDT&' . $prefix . $composedKey . '=' . $sentinel;

        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $raw]);
            self::fail('A composed sensitive form key in a later pair must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            $trace = self::renderExceptionTraceChain($exception);
            self::assertStringNotContainsString($raw, $trace);
            self::assertStringNotContainsString($sentinel, $trace);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function laterFormPairLeadingPrefixAndComposedKeyProvider(): iterable
    {
        $composedKeys = [
            'percent-composed JSON Unicode key' => '%22api%5Cu005fkey%22',
            'percent-composed Base64 key' => '%22YXBpX2tleQ%3D%3D%22',
        ];

        foreach (self::laterFormPairLeadingPrefixProvider() as $prefixLabel => [$prefix]) {
            foreach ($composedKeys as $keyLabel => $composedKey) {
                yield $prefixLabel . ', ' . $keyLabel => [$prefix, $composedKey];
            }
        }
    }

    #[DataProvider('laterFormPairLeadingPrefixProvider')]
    public function testAllowsLeadingFormSpaceBeforeOrdinaryLaterFormField(string $prefix): void
    {
        PaperMarketEventRedactor::assertSafe([
            'raw' => 'symbol=BTCUSDT&' . $prefix . 'price=29999.0',
        ]);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function laterFormPairLeadingPrefixProvider(): iterable
    {
        yield 'space' => [' '];
        yield 'horizontal tab' => ["\t"];
        yield 'line feed' => ["\n"];
        yield 'vertical tab' => ["\v"];
        yield 'form feed' => ["\f"];
        yield 'carriage return' => ["\r"];
        yield 'form space' => ['+'];
        yield 'percent-encoded form space' => ['%2B'];
    }

    #[DataProvider('prefixedComposedSensitiveFormKeyProvider')]
    public function testRejectsPrefixedComposedSensitiveKeysInLaterFormPair(
        #[\SensitiveParameter] string $prefix,
        #[\SensitiveParameter] string $composedKey,
    ): void {
        $sentinel = 'synthetic-prefixed-form-key-sentinel';
        $raw = 'symbol=BTCUSDT&' . $prefix . $composedKey . '=' . $sentinel;

        self::assertSensitiveRejectionWithFullTraceWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function prefixedComposedSensitiveFormKeyProvider(): iterable
    {
        $composedKeys = [
            'percent-composed JSON Unicode key' => '%22api%5Cu005fkey%22',
            'percent-composed Base64 key' => '%22YXBpX2tleQ%3D%3D%22',
        ];

        foreach (self::composedKeyPrefixProvider() as $prefixLabel => [$prefix]) {
            foreach ($composedKeys as $keyLabel => $composedKey) {
                yield $prefixLabel . ', ' . $keyLabel => [$prefix, $composedKey];
            }
        }
    }

    #[DataProvider('composedKeyPrefixProvider')]
    public function testAllowsOrdinaryMapKeysAndFormRelationsWithCompositionPrefixes(
        string $prefix,
    ): void {
        PaperMarketEventRedactor::assertSafe([
            $prefix . 'price' => '29999.0',
            'raw' => 'symbol=BTCUSDT&' . $prefix . 'price=29999.0',
        ]);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function composedKeyPrefixProvider(): iterable
    {
        yield 'ASCII lowercase letter' => ['x'];
        yield 'ASCII uppercase letter' => ['Z'];
        yield 'ASCII digit' => ['0'];
        yield 'underscore' => ['_'];
        yield 'hyphen' => ['-'];
        yield 'dot' => ['.'];
        yield 'bang' => ['!'];
        yield 'at sign' => ['@'];
        yield 'colon' => [':'];
        yield 'slash' => ['/'];
        yield 'backslash' => ['\\'];
        yield 'pipe' => ['|'];
        yield 'comma' => [','];
        yield 'semicolon' => [';'];
        yield 'open parenthesis' => ['('];
        yield 'close parenthesis' => [')'];
        yield 'open bracket' => ['['];
        yield 'close bracket' => [']'];
        yield 'open brace' => ['{'];
        yield 'close brace' => ['}'];
        yield 'question mark' => ['?'];
        yield 'hash' => ['#'];
        yield 'dollar' => ['$'];
        yield 'caret' => ['^'];
        yield 'asterisk' => ['*'];
        yield 'double quote' => [\chr(34)];
        yield 'single quote' => [\chr(39)];
        yield 'encoded double quote' => ['%22'];
        yield 'encoded single quote' => ['%27'];
        yield 'repeated double quote' => [\chr(34) . \chr(34)];
        yield 'repeated single quote' => [\chr(39) . \chr(39)];
        yield 'double then single quote' => [\chr(34) . \chr(39)];
        yield 'single then double quote' => [\chr(39) . \chr(34)];
        yield 'token then double quote' => ['x' . \chr(34)];
        yield 'double quote then token' => [\chr(34) . 'x'];
        yield 'token then encoded double quote' => ['x%22'];
        yield 'encoded double quote then token' => ['%22x'];
        yield 'equals' => ['='];
        yield 'encoded equals' => ['%3D'];
        yield 'ampersand' => ['&'];
        yield 'encoded ampersand' => ['%26'];
        yield 'percent' => ['%'];
        yield 'malformed percent' => ['%2'];
        yield 'backtick' => [\chr(96)];
        yield 'space' => [' '];
        yield 'horizontal tab' => ["\t"];
        yield 'line feed' => ["\n"];
        yield 'vertical tab' => ["\v"];
        yield 'form feed' => ["\f"];
        yield 'carriage return' => ["\r"];
        yield 'form plus' => ['+'];
        yield 'encoded bang' => ['%21'];
        yield 'encoded NUL' => ['%00'];
        yield 'encoded dot' => ['%2E'];
        yield 'encoded space' => ['%20'];
        yield 'encoded plus' => ['%2B'];
        yield 'encoded tab' => ['%09'];
        yield 'encoded slash' => ['%2F'];
        yield 'encoded backslash' => ['%5C'];
        yield 'repeated token' => ['xx'];
        yield 'repeated punctuation' => ['!!'];
        yield 'token then punctuation' => ['x!'];
        yield 'punctuation then token' => ['!x'];
        yield 'token then encoded punctuation' => ['x%21'];
        yield 'encoded punctuation then token' => ['%21x'];
        yield 'mixed underscore and hyphen' => ['_-'];
        yield 'mixed dot and hyphen' => ['.-'];
        yield 'mixed encoded bytes' => ['%21%00'];
        yield 'repeated mixed prefix' => ['x_%21-.'];
        yield 'form plus then token' => ['+x'];
        yield 'encoded space then token' => ['%20x'];
    }

    #[DataProvider('sensitiveStructuralStringProvider')]
    public function testRejectsSensitiveStructuralKeysAcrossQuoteEncodings(string $raw): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, 'synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveStructuralStringProvider(): iterable
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $slash = '\\';

        yield 'escaped structural quotes and escaped quote inside key' => [
            'prefix {'
            . $slash . '"api' . str_repeat($slash, 3) . '"key' . $slash . '":'
            . $slash . '"' . $sentinel . $slash . '"} suffix',
        ];
        yield 'single-quoted Unicode-escaped sensitive member' => [
            "prefix {'api" . $slash . "u005fkey':'" . $sentinel . "'} suffix",
        ];
    }

    #[DataProvider('nonCanonicalBase64SensitiveMapKeyProvider')]
    public function testRejectsLenientlyDecodableBase64SensitiveMapKeys(string $key): void
    {
        self::assertSame('api_key', base64_decode($key, false));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe([$key => 'synthetic-redaction-sentinel']),
            [$key, 'synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function nonCanonicalBase64SensitiveMapKeyProvider(): iterable
    {
        $base64 = base64_encode('api_key');
        foreach ([
            'space' => ' ',
            'horizontal tab' => "\t",
            'line feed' => "\n",
            'vertical tab' => "\v",
            'form feed' => "\f",
            'carriage return' => "\r",
        ] as $label => $whitespace) {
            yield 'folded with ' . $label => [substr($base64, 0, 4) . $whitespace . substr($base64, 4)];
        }

        yield 'internal padding' => [substr($base64, 0, 4) . '=' . substr($base64, 4)];
        yield 'excess internal padding' => [substr($base64, 0, 4) . '===' . substr($base64, 4)];
    }

    #[DataProvider('jsonWrappedBase64SensitiveMapKeyProvider')]
    public function testRejectsJsonWrappedBase64SensitiveMapKeys(string $key): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe([$key => 'synthetic-redaction-sentinel']),
            [$key, 'synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function jsonWrappedBase64SensitiveMapKeyProvider(): iterable
    {
        $wrapped = json_encode(base64_encode('api_key'), JSON_THROW_ON_ERROR);

        yield 'JSON string wrapper' => [$wrapped];
        yield 'percent-encoded JSON string wrapper' => [rawurlencode($wrapped)];
    }

    /** @param array<array-key, mixed> $payload */
    #[DataProvider('ambiguousUnicodeStructuredKeyProvider')]
    public function testRejectsAmbiguousUnicodeKeysAcrossStructuredRepresentations(array $payload): void
    {
        try {
            PaperMarketEventRedactor::assertSafe($payload);
            self::fail('An ambiguous Unicode structured key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-secret-sentinel', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function ambiguousUnicodeStructuredKeyProvider(): iterable
    {
        $cyrillicApiKey = "аpi_key";
        $ambiguousPrivateKey = "priѵate_key";

        yield 'raw Unicode form key' => [[
            'raw' => $cyrillicApiKey . '=synthetic-secret-sentinel',
        ]];
        yield 'percent-encoded Unicode form key' => [[
            'raw' => rawurlencode($cyrillicApiKey) . '=synthetic-secret-sentinel',
        ]];
        yield 'ambiguous map key' => [[
            $ambiguousPrivateKey => 'synthetic-secret-sentinel',
        ]];
        yield 'ambiguous JSON key' => [[
            'raw' => json_encode(
                [$ambiguousPrivateKey => 'synthetic-secret-sentinel'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        ]];
    }

    #[DataProvider('malformedStructuredCredentialProvider')]
    public function testRejectsMalformedStructuredCredentialStrings(string $malformed): void
    {
        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $malformed]);
            self::fail('Malformed structured credential material must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-secret-sentinel', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedStructuredCredentialProvider(): iterable
    {
        yield 'JSON-escaped credential key with trailing data' => [
            '{"api\\u005fkey":"synthetic-secret-sentinel"} trailing',
        ];
        yield 'malformed query prefix before confusable credential key' => [
            "ignored&аpi_key=synthetic-secret-sentinel",
        ];
        yield 'malformed query suffix after confusable credential key' => [
            "аpi_key=synthetic-secret-sentinel&ignored",
        ];
        yield 'confusable credential assignment' => [
            "аpi_key: synthetic-secret-sentinel",
        ];
        yield 'confusable JSON credential key with trailing data' => [
            "{\"аpi_key\":\"synthetic-secret-sentinel\"} trailing",
        ];
    }

    #[DataProvider('jsonDecoderFailureProvider')]
    public function testJsonDecoderFailuresDoNotRetainRawInputExceptions(
        string $raw,
        string $expectedCode,
    ): void {
        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $raw]);
            self::fail('A malformed JSON credential representation must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($expectedCode, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function jsonDecoderFailureProvider(): iterable
    {
        $overDepthJson = str_repeat('[', 130)
            . '"synthetic-depth-trace-sentinel"'
            . str_repeat(']', 130);

        yield 'over-depth JSON payload' => [
            $overDepthJson,
            'paper_market_sensitive_decode_depth_exceeded',
        ];
        yield 'malformed escaped JSON map key' => [
            '{"public\\q_synthetic-key-trace-sentinel":"price"}',
            'paper_market_sensitive_field_rejected',
        ];
        yield 'indented malformed escaped JSON map key' => [
            "{\n  \"public\\q_synthetic-key-trace-sentinel\":\"price\"}",
            'paper_market_sensitive_field_rejected',
        ];
    }

    public function testRejectsEscapedSensitiveJsonObjectKeyAfterNonJsonPrefix(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix {"api\\u005fkey":"' . $sentinel . '"}';

        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $raw]);
            self::fail('A prefixed escaped sensitive JSON object key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString($sentinel, $exception->getMessage());
        }
    }

    public function testRejectsSensitiveJsonObjectKeyWithEscapedStructuralQuotes(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix {\\"api\\u005fkey\\":\\"' . $sentinel . '\\"} suffix';

        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $raw]);
            self::fail('An escaped sensitive JSON object fragment must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString($sentinel, $exception->getMessage());
        }
    }

    public function testRejectsSensitiveJsonObjectKeyWithMismatchedStructuralEscapes(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix {\\"api\\u005fkey' . str_repeat('\\', 2) . '":"' . $sentinel . '"} suffix';

        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $raw]);
            self::fail('A malformed escaped sensitive JSON object fragment must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString($sentinel, $exception->getMessage());
        }
    }

    #[DataProvider('escapedSensitiveMemberPrefixProvider')]
    public function testRejectsEscapedSensitiveMemberAfterAlphanumericOrUnderscorePrefix(
        string $prefix,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix' . $prefix . '\\"api\\u005fkey\\":\\"' . $sentinel . '\\" suffix';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function escapedSensitiveMemberPrefixProvider(): iterable
    {
        yield 'alphanumeric prefix' => ['a'];
        yield 'underscore prefix' => ['_'];
    }

    public function testRejectsUnquotedJsonUnicodeEscapedSensitiveMember(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix {api\\u005fkey:"' . $sentinel . '"} suffix';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    #[DataProvider('embeddedSensitiveRepresentationProvider')]
    public function testRejectsDelimiterBoundedEmbeddedCredentialRepresentations(string $raw): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, 'synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function embeddedSensitiveRepresentationProvider(): iterable
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $slash = '\\';

        yield 'escaped JSON member after opening bracket' => [
            'prefix [\\\"api\\u005fkey\\\":\\\"' . $sentinel . '\\\"] suffix',
        ];
        yield 'escaped JSON member at string start' => [
            '\\\"api\\u005fkey\\\":\\\"' . $sentinel . '\\"',
        ];
        yield 'escaped JSON member after ASCII whitespace' => [
            "prefix\f\\\"api\\u005fkey\\\":\\\"" . $sentinel . '\\"',
        ];
        yield 'escaped JSON member between punctuation delimiters' => [
            'prefix|'
            . $slash . '"api' . $slash . 'u005fkey' . $slash . '":'
            . $slash . '"' . $sentinel . $slash . '"|suffix',
        ];
        yield 'escaped opening and plain closing credential key quote' => [
            'prefix ['
            . $slash . '"api' . $slash . 'u005fkey":'
            . $slash . '"' . $sentinel . $slash . '"] suffix',
        ];
        yield 'plain opening and escaped closing credential key quote' => [
            'prefix ["api' . $slash . 'u005fkey' . $slash . '":'
            . $slash . '"' . $sentinel . $slash . '"] suffix',
        ];
        yield 'PHP serialized credential map' => [
            'prefix [' . serialize(['api_key' => $sentinel]) . '] suffix',
        ];
        yield 'PHP serialized credential map between punctuation delimiters' => [
            'prefix|' . serialize(['api_key' => $sentinel]) . '|suffix',
        ];
        yield 'canonical Base64 credential JSON' => [
            'prefix [' . base64_encode('{"api_key":"' . $sentinel . '"}') . '] suffix',
        ];
        yield 'canonical Base64 credential JSON between token delimiters' => [
            'prefix|' . base64_encode('{"api_key":"' . $sentinel . '"}') . '|suffix',
        ];

        $urlFixture = json_encode(
            ['api_key' => $sentinel, 'note' => "\u{1003E}"],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $classicPadded = base64_encode($urlFixture);
        $urlPadded = strtr($classicPadded, '+/', '-_');

        yield 'unpadded canonical Base64 credential JSON' => [
            'prefix [' . rtrim($classicPadded, '=') . '] suffix',
        ];
        yield 'padded canonical Base64url credential JSON' => [
            'prefix [' . $urlPadded . '] suffix',
        ];
        yield 'unpadded canonical Base64url credential JSON' => [
            'prefix [' . rtrim($urlPadded, '=') . '] suffix',
        ];
    }

    #[DataProvider('malformedEmbeddedPhpSerializationProvider')]
    public function testRejectsMalformedOrResourceIntensiveEmbeddedPhpSerialization(string $raw): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, 'synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEmbeddedPhpSerializationProvider(): iterable
    {
        yield 'declared string length exceeds candidate' => [
            'prefix [a:1:{s:7:"api_key";s:999999:"synthetic-redaction-sentinel";}] suffix',
        ];
        yield 'declared array size exceeds decode node budget' => [
            'prefix [a:999999:{}] suffix',
        ];
        yield 'negative declared array size' => [
            'prefix [a:-1:{s:7:"api_key";s:28:"synthetic-redaction-sentinel";}] suffix',
        ];

        $serialized = 'N;';
        for ($depth = 0; $depth <= 128; ++$depth) {
            $serialized = 'a:1:{i:0;' . $serialized . '}';
        }

        yield 'nesting exceeds decode depth budget' => [
            'prefix [' . $serialized . '] suffix',
        ];
        yield 'standalone aggregate node count exceeds decode budget' => [
            serialize(array_fill(0, 4_096, 'public-market-data')),
        ];
    }

    #[DataProvider('embeddedPublicRepresentationProvider')]
    public function testAllowsDelimiterBoundedEmbeddedPublicRepresentations(string $raw): void
    {
        PaperMarketEventRedactor::assertSafe(['raw' => $raw]);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function embeddedPublicRepresentationProvider(): iterable
    {
        $publicJson = '{"symbol":"BTCUSDT","price":"29999.0"}';

        yield 'public bracketed prose' => [
            'prefix [public BTCUSDT market snapshot at 29999.0] suffix',
        ];
        yield 'benign PHP serialization' => [
            'prefix [' . serialize(['symbol' => 'BTCUSDT', 'price' => '29999.0']) . '] suffix',
        ];
        yield 'benign canonical Base64 JSON' => [
            'prefix [' . base64_encode($publicJson) . '] suffix',
        ];
        yield 'ordinary long alphanumeric token' => [
            'prefix [' . str_repeat('PUBLICMARKETDATA42', 512) . '] suffix',
        ];
        yield 'noncanonical Base64-looking prose' => [
            'prefix [' . base64_encode($publicJson) . '=] suffix',
        ];
        yield 'ordinary Windows path' => [
            'Ordinary note: the public folder "C:\\prices": contains BTCUSDT snapshots.',
        ];
    }

    #[DataProvider('escapedPublicMemberProvider')]
    public function testAllowsPublicEscapedSymbolAndPriceFragments(string $raw): void
    {
        PaperMarketEventRedactor::assertSafe(['raw' => $raw]);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function escapedPublicMemberProvider(): iterable
    {
        for ($count = 1; $count <= 4; ++$count) {
            $slashes = str_repeat('\\', $count);

            yield sprintf('%d backslash(es)', $count) => [
                'prefix ['
                . $slashes . '"symbol' . $slashes . '":' . $slashes . '"BTCUSDT' . $slashes . '",'
                . $slashes . '"price' . $slashes . '":' . $slashes . '"29999.0' . $slashes . '"'
                . '] suffix',
            ];
        }
    }

    public function testAllowsPublicJsonObjectKeysAfterNonJsonPrefix(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'raw' => 'prefix {"symbol":"BTCUSDT","price":"29999.0"}',
        ]);
        PaperMarketEventRedactor::assertSafe([
            'raw' => 'prefix {\\"symbol\\":\\"BTCUSDT\\",\\"price\\":\\"29999.0\\"} suffix',
        ]);
        PaperMarketEventRedactor::assertSafe([
            'raw' => 'prefix {'
                . str_repeat('\\', 2) . '"symbol' . str_repeat('\\', 2)
                . '":"BTCUSDT"} suffix',
        ]);

        self::addToAssertionCount(1);
    }

    public function testAllowsOrdinaryWindowsPathLikePublicProse(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'note' => 'Ordinary note: the public folder "C:\\prices": contains BTCUSDT snapshots.',
        ]);

        self::addToAssertionCount(1);
    }

    #[DataProvider('quotedColonPublicProseProvider')]
    public function testAllowsQuotedColonPublicProseWithBackslashes(string $raw): void
    {
        PaperMarketEventRedactor::assertSafe(['note' => $raw]);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function quotedColonPublicProseProvider(): iterable
    {
        yield 'bid-backslash-ask prose' => [
            'Market note: "bid\\ask": public spread.',
        ];
        yield 'UNC public folder prose' => [
            'Market note: "\\\\market-server\\public": BTCUSDT snapshots.',
        ];
    }

    public function testRejectsJsonUnicodeCredentialEscapeInsideWindowsPathLikeToken(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'Ordinary note: "C:\\api\\u005fkey": ' . $sentinel;

        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $raw]);
            self::fail('A Windows-path-like token must not hide a JSON Unicode credential key.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString($sentinel, $exception->getMessage());
        }
    }

    public function testRejectsMapKeyStillPercentEncodedBeyondTheBoundedDecodeDepth(): void
    {
        $encodedKey = self::percentEncodeEveryByte('api_key');
        for ($depth = 0; $depth < PaperMarketEventRedactor::MAX_SENSITIVE_DECODE_DEPTH; ++$depth) {
            $encodedKey = rawurlencode($encodedKey);
        }

        try {
            PaperMarketEventRedactor::assertSafe([
                $encodedKey => 'synthetic-secret-sentinel',
            ]);
            self::fail('A key requiring another percent-decoding pass must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_decode_depth_exceeded', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-secret-sentinel', $exception->getMessage());
        }
    }

    /** @param array<array-key, mixed> $payload */
    #[DataProvider('serializedSensitiveValueProvider')]
    public function testRejectsSerializedCredentialsInsideStringValues(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        PaperMarketEventRedactor::assertSafe($payload);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function serializedSensitiveValueProvider(): iterable
    {
        $serializedAccessToken = serialize(['accessToken' => 'synthetic-placeholder']);

        yield 'authorization bearer header' => [[
            'headers' => ['Authorization: Bearer synthetic-placeholder'],
        ]];
        yield 'raw JSON API key' => [[
            'raw' => '{"api_key":"synthetic-placeholder"}',
        ]];
        yield 'form encoded client secret' => [[
            'request' => 'clientSecret=synthetic-placeholder',
        ]];
        yield 'raw JSON signature' => [[
            'raw' => '{"signature":"synthetic-placeholder"}',
        ]];
        yield 'standalone bearer credential' => [[
            'header_value' => 'Bearer synthetic-placeholder',
        ]];
        yield 'PHP serialized access token' => [[
            'raw' => $serializedAccessToken,
        ]];
        yield 'PHP escaped serialized access token string' => [[
            'raw' => sprintf(
                'S:%d:"%s";',
                strlen($serializedAccessToken),
                str_replace('"', '\\22', $serializedAccessToken),
            ),
        ]];
        yield 'PHP serialized client secret' => [[
            'raw' => serialize(['clientSecret' => 'synthetic-placeholder']),
        ]];
        yield 'PHP serialized credential pair' => [[
            'credentials' => serialize([
                'username' => 'synthetic-user',
                'password' => 'synthetic-placeholder',
            ]),
        ]];
    }

    #[DataProvider('privateKeyEnvelopeProvider')]
    public function testRejectsPrivateKeyEnvelopesUnderPublicKeys(string $privateKeyType): void
    {
        $envelope = sprintf(
            "-----BEGIN %1\$s-----\nc3ludGhldGljLXNlY3JldC1zZW50aW5lbA==\n-----END %1\$s-----",
            $privateKeyType,
        );

        try {
            PaperMarketEventRedactor::assertSafe(['note' => $envelope]);
            self::fail('A private-key envelope under a public key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-secret-sentinel', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function privateKeyEnvelopeProvider(): iterable
    {
        yield 'PKCS8 private key' => ['PRIVATE KEY'];
        yield 'PKCS8 encrypted private key' => ['ENCRYPTED PRIVATE KEY'];
        yield 'PKCS1 RSA private key' => ['RSA PRIVATE KEY'];
        yield 'legacy DSA private key' => ['DSA PRIVATE KEY'];
        yield 'SEC1 EC private key' => ['EC PRIVATE KEY'];
        yield 'OpenSSH private key' => ['OPENSSH PRIVATE KEY'];
    }

    #[DataProvider('shortBearerTokenProvider')]
    public function testRejectsBearerWithAnyNonEmptyToken(string $token): void
    {
        try {
            PaperMarketEventRedactor::assertSafe(['header' => 'Bearer ' . $token]);
            self::fail('A non-empty Bearer token must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString($token, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function shortBearerTokenProvider(): iterable
    {
        yield 'one-character token' => ['x'];
        yield 'seven-character token' => ['short_7'];
    }

    public function testRejectsAnUnboundedStringBeforeScanningIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_payload_string_too_large');

        PaperMarketEventRedactor::assertSafe([
            'public_snapshot' => str_repeat('x', 1_048_577),
        ]);
    }

    #[DataProvider('encodedCredentialProvider')]
    public function testRejectsCredentialsAfterBoundedCanonicalDecoding(string $encodedCredential): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        PaperMarketEventRedactor::assertSafe(['raw' => $encodedCredential]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodedCredentialProvider(): iterable
    {
        $jsonCredential = '{"api_key":"synthetic-secret-sentinel"}';
        $formCredential = 'api_key=synthetic-secret-sentinel';

        yield 'leading-whitespace form' => [" \n\tapi%5Fkey=synthetic-secret-sentinel"];
        yield 'fully percent-encoded form' => [self::percentEncodeEveryByte($formCredential)];
        yield 'unpadded base64 JSON' => [rtrim(base64_encode($jsonCredential), '=')];
        yield 'unpadded base64 form' => [rtrim(base64_encode($formCredential), '=')];
        yield 'double JSON' => [json_encode(json_encode(
            ['api_key' => 'synthetic-secret-sentinel'],
            JSON_THROW_ON_ERROR,
        ), JSON_THROW_ON_ERROR)];
        yield 'BOM-prefixed escaped JSON' => ["\xEF\xBB\xBF{\"api\\u005fkey\":\"synthetic-secret-sentinel\"}"];
    }

    #[DataProvider('base64UrlAlphabetProvider')]
    public function testRejectsCredentialsInsideUnpaddedBase64UrlJson(
        string $note,
        string $alphabetCharacter,
    ): void {
        $json = json_encode(
            ['api_key' => 'synthetic-secret-sentinel', 'note' => $note],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $encoded = self::base64UrlEncode($json);

        self::assertStringContainsString($alphabetCharacter, $encoded);
        self::assertStringNotContainsString('=', $encoded);

        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $encoded]);
            self::fail('Base64url-encoded credential JSON must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-secret-sentinel', $exception->getMessage());
        }
    }

    #[DataProvider('publicBase64UrlAlphabetProvider')]
    public function testAllowsUnpaddedBase64UrlPublicJson(
        string $note,
        string $alphabetCharacter,
    ): void {
        $json = json_encode(
            ['symbol' => 'BTCUSDT', 'note' => $note],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $encoded = self::base64UrlEncode($json);

        self::assertStringContainsString($alphabetCharacter, $encoded);
        self::assertStringNotContainsString('=', $encoded);
        PaperMarketEventRedactor::assertSafe(['raw' => $encoded]);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function base64UrlAlphabetProvider(): iterable
    {
        yield 'dash alphabet' => ["\u{1003E}", '-'];
        yield 'underscore alphabet' => ["\u{1003F}", '_'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function publicBase64UrlAlphabetProvider(): iterable
    {
        yield 'dash alphabet' => ['¾', '-'];
        yield 'underscore alphabet' => ['¿', '_'];
    }

    #[DataProvider('canonicalBase64CredentialProvider')]
    public function testRejectsCredentialsInEveryCanonicalBase64Form(string $encodedCredential): void
    {
        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $encodedCredential]);
            self::fail('A credential in canonical Base64 must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-secret-sentinel', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function canonicalBase64CredentialProvider(): iterable
    {
        $json = json_encode(
            ['api_key' => 'synthetic-secret-sentinel', 'note' => "\u{1003E}"],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $classicPadded = base64_encode($json);
        $urlPadded = strtr($classicPadded, '+/', '-_');

        yield 'classic padded' => [$classicPadded];
        yield 'classic unpadded' => [rtrim($classicPadded, '=')];
        yield 'URL-safe padded' => [$urlPadded];
        yield 'URL-safe unpadded' => [rtrim($urlPadded, '=')];
    }

    #[DataProvider('ambiguousRecoverableBase64CredentialProvider')]
    public function testRejectsAmbiguousRecoverableBase64CredentialEncodings(
        string $encodedCredential,
    ): void {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $encodedCredential]),
            ['synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousRecoverableBase64CredentialProvider(): iterable
    {
        $json = json_encode(
            ['api_key' => 'synthetic-redaction-sentinel'],
            JSON_THROW_ON_ERROR,
        );
        $base64 = base64_encode($json);

        yield 'whitespace-folded canonical Base64 JSON' => [
            substr($base64, 0, 4) . "\r\n " . substr($base64, 4),
        ];
        yield 'embedded whitespace-folded canonical Base64 JSON' => [
            'prefix|'
            . substr($base64, 0, 4)
            . "\r\n "
            . substr($base64, 4)
            . '|suffix',
        ];
        yield 'non-quantum whitespace-folded canonical Base64 JSON' => [
            substr($base64, 0, 1) . ' ' . substr($base64, 1),
        ];
        yield 'embedded non-quantum whitespace-folded canonical Base64 JSON' => [
            'prefix|'
            . substr($base64, 0, 1)
            . ' '
            . substr($base64, 1)
            . '|suffix',
        ];
        yield 'canonical Base64 with invalid UTF-8 prefix' => [
            base64_encode("\xFF" . $json),
        ];
        yield 'canonical Base64 with invalid UTF-8 and percent-encoded form' => [
            base64_encode("\xFF" . rawurlencode('api_key=synthetic-redaction-sentinel')),
        ];
        yield 'alphanumeric unpadded Base64 with invalid UTF-8 and percent-encoded form' => [
            base64_encode("\x80" . rawurlencode('api_key=synthetic-redaction-sentinel')),
        ];
        yield 'canonical Base64 with invalid UTF-8 and nested Base64 JSON' => [
            base64_encode("\xFF" . base64_encode($json)),
        ];
        $nestedJson = json_encode(
            [
                'api_key' => 'synthetic-redaction-sentinel',
                'note' => 'xxxxxx',
            ],
            JSON_THROW_ON_ERROR,
        );
        yield 'alphanumeric unpadded Base64 with invalid UTF-8 and nested Base64 JSON' => [
            base64_encode("\x80" . base64_encode($nestedJson)),
        ];
        yield 'canonical Base64 containing opaque invalid UTF-8 binary' => [
            base64_encode("\xFF\x00\xFEsynthetic-redaction-sentinel"),
        ];
    }

    public function testRejectsFoldedCredentialBase64AtEveryInternalOffsetAndProseBoundary(): void
    {
        $json = json_encode(
            [
                'note' => "\u{083E}",
                'api_key' => 'synthetic-fold-boundary-sentinel',
                'suffix' => '',
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $classicPadded = base64_encode($json);
        $urlPadded = strtr($classicPadded, '+/', '-_');
        self::assertMatchesRegularExpression('/[+\/]/', $classicPadded);
        self::assertStringEndsWith('=', $classicPadded);
        self::assertMatchesRegularExpression('/[-_]/', $urlPadded);
        $encodings = [
            'classic padded' => $classicPadded,
            'classic unpadded' => rtrim($classicPadded, '='),
            'URL-safe padded' => $urlPadded,
            'URL-safe unpadded' => rtrim($urlPadded, '='),
        ];
        $whitespaceBytes = [
            'space' => ' ',
            'horizontal tab' => "\t",
            'line feed' => "\n",
            'vertical tab' => "\v",
            'form feed' => "\f",
            'carriage return' => "\r",
        ];
        $contexts = [
            'unbounded' => static fn (string $folded): string => $folded,
            'left prose' => static fn (string $folded): string => 'market ' . $folded,
            'right prose' => static fn (string $folded): string => $folded . ' snapshot',
            'both prose' => static fn (string $folded): string => 'market ' . $folded . ' snapshot',
        ];

        $rejectionCount = 0;
        foreach ($encodings as $encodingLabel => $encoded) {
            for ($offset = 1, $length = \strlen($encoded); $offset < $length; ++$offset) {
                foreach ($whitespaceBytes as $whitespaceLabel => $whitespace) {
                    $folded = substr($encoded, 0, $offset)
                        . $whitespace
                        . substr($encoded, $offset);

                    foreach ($contexts as $contextLabel => $context) {
                        try {
                            PaperMarketEventRedactor::assertSafe(['raw' => $context($folded)]);
                            self::fail(sprintf(
                                '%s Base64 folded with %s at offset %d was accepted in %s.',
                                $encodingLabel,
                                $whitespaceLabel,
                                $offset,
                                $contextLabel,
                            ));
                        } catch (\InvalidArgumentException $exception) {
                            self::assertSame(
                                'paper_market_sensitive_field_rejected',
                                $exception->getMessage(),
                                sprintf(
                                    '%s Base64 folded with %s at offset %d in %s.',
                                    $encodingLabel,
                                    $whitespaceLabel,
                                    $offset,
                                    $contextLabel,
                                ),
                            );
                            ++$rejectionCount;
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(0, $rejectionCount);
    }

    #[DataProvider('foldedBase64AlphabetBoundaryProvider')]
    public function testRejectsFoldedCredentialBase64AcrossAlphabetAlignmentBoundaries(
        string $credential,
        string $public,
    ): void {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $credential]),
            [$credential, 'synthetic-fold-alignment-sentinel'],
        );
    }

    #[DataProvider('foldedBase64AlphabetBoundaryProvider')]
    public function testAllowsFoldedPublicBase64AcrossAlphabetAlignmentBoundaries(
        string $credential,
        string $public,
    ): void {
        PaperMarketEventRedactor::assertSafe(['raw' => $public]);

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function foldedBase64AlphabetBoundaryProvider(): iterable
    {
        $credentialJson = json_encode(
            [
                'api_key' => 'synthetic-fold-alignment-sentinel',
                'price' => '1',
            ],
            JSON_THROW_ON_ERROR,
        );
        $publicJson = json_encode(
            [
                'price' => 1,
            ],
            JSON_THROW_ON_ERROR,
        );

        foreach (range(1, 3) as $prefixLength) {
            foreach (range(0, 4) as $suffixLength) {
                $prefix = str_repeat('A', $prefixLength);
                $suffix = str_repeat('A', $suffixLength);
                $fold = static fn (string $encoded): string => substr($encoded, 0, 4)
                    . "\r\n "
                    . substr($encoded, 4);

                yield sprintf('prefix %d, suffix %d', $prefixLength, $suffixLength) => [
                    $prefix . $fold(rtrim(base64_encode($credentialJson), '=')) . $suffix,
                    $prefix . $fold(rtrim(base64_encode($publicJson), '=')) . $suffix,
                ];
            }
        }
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('decodeDepthInsertionOrderProvider')]
    public function testDecodeDepthEnforcementIsIndependentOfMapInsertionOrder(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_decode_depth_exceeded');

        PaperMarketEventRedactor::assertSafe($payload);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function decodeDepthInsertionOrderProvider(): iterable
    {
        $shallow = json_encode('public-market-value', JSON_THROW_ON_ERROR);
        $fiveLevels = $shallow;
        for ($depth = 0; $depth < 4; ++$depth) {
            $fiveLevels = json_encode($fiveLevels, JSON_THROW_ON_ERROR);
        }

        yield 'shallow cache prime before five-level copy' => [[
            'prime' => $shallow,
            'deep' => $fiveLevels,
        ]];
        yield 'five-level copy before shallow cache prime' => [[
            'deep' => $fiveLevels,
            'prime' => $shallow,
        ]];
    }

    public function testRejectsFoldedSerializedCredentialAfterSameAlignmentPublicSegment(): void
    {
        $credential = base64_encode(serialize([
            'api_key' => 'synthetic-secret-sentinel',
        ]));
        $folded = substr($credential, 0, 4) . "\r\n " . substr($credential, 4);
        $raw = 'bid1 ' . $folded;

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, 'synthetic-secret-sentinel'],
        );
    }

    #[DataProvider('unpaddedShortCredentialProseContextProvider')]
    public function testRejectsUnpaddedShortCredentialAfterSameAlignmentProse(string $raw): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function unpaddedShortCredentialProseContextProvider(): iterable
    {
        $credential = rtrim(base64_encode(json_encode(
            ['api_key' => 'x'],
            JSON_THROW_ON_ERROR,
        )), '=');

        yield 'left prose' => ['market' . $credential];
        yield 'both prose' => ['market' . $credential . 'snapshot'];
    }

    #[DataProvider('embeddedCanonicalCredentialAfterMalformedBoundaryProvider')]
    public function testRejectsCanonicalCredentialAfterMalformedBase64Boundary(string $raw): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, 'synthetic-secret-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function embeddedCanonicalCredentialAfterMalformedBoundaryProvider(): iterable
    {
        $credential = base64_encode(json_encode(
            ['api_key' => 'synthetic-secret-sentinel'],
            JSON_THROW_ON_ERROR,
        ));

        yield 'after malformed padded token' => ['AAAA==' . $credential];
        yield 'after delimiter padding' => ['prefix|=' . $credential . '|suffix'];
        yield 'unpadded after prose padding' => ['note==' . rtrim($credential, '=')];
    }

    public function testBase64TokenSegmentationPreservesNormalPublicMarketText(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'symbol_text' => 'prefix|=BTCUSDT|suffix',
            'sequence_text' => 'note==42',
            'prose' => 'bid1 BTCUSDT snapshot',
        ]);

        self::addToAssertionCount(1);
    }

    public function testBase64TokenSegmentationRemainsBoundedByDecodedNodeLimit(): void
    {
        $segments = [];
        for ($index = 0; $index < PaperMarketEventRedactor::MAX_SENSITIVE_DECODE_NODES; ++$index) {
            $segments[] = base64_encode('public-' . $index);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_decode_nodes_exceeded');

        PaperMarketEventRedactor::assertSafe(['raw' => implode('|', $segments)]);
    }

    public function testOneMiBWhitespaceFoldRunStopsAtTheDocumentedDecodeByteBound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_decode_bytes_exceeded');

        PaperMarketEventRedactor::assertSafe([
            str_repeat('A ', 524_288),
        ]);
    }

    public function testAllowsOneMiBPublicFormLikeStringInNearLinearTime(): void
    {
        $public = str_repeat('x&', 500_000) . 'price=1';
        self::assertLessThanOrEqual(
            PaperMarketEventRedactor::MAX_PAYLOAD_STRING_BYTES,
            \strlen($public),
        );

        $startedAt = hrtime(true);
        PaperMarketEventRedactor::assertSafe(['raw' => $public]);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertLessThan(2.0, $elapsedSeconds);
    }

    #[RunInSeparateProcess]
    public function testSerializedDecodeFailureDoesNotLeakItsRawValueThroughAFullTraceChain(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        $sentinel = 'synthetic-serialized-trace-sentinel';
        $serialized = sprintf('S:%d:"%s";', \strlen($sentinel), $sentinel);

        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $serialized]);
            self::fail('The serialized value must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString(
                $sentinel,
                self::renderExceptionTraceChain($exception),
            );
            self::assertNull($exception->getPrevious());
        }
    }

    #[DataProvider('malformedBase64CredentialPaddingProvider')]
    public function testRejectsCredentialsWithMalformedBase64Padding(string $malformed): void
    {
        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $malformed]);
            self::fail('Credential material with malformed Base64 padding must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-secret-sentinel', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedBase64CredentialPaddingProvider(): iterable
    {
        $requiresTwoPadding = self::base64CredentialWithPaddingCount(2);
        $requiresOnePadding = self::base64CredentialWithPaddingCount(1);

        yield 'classic partial padding' => [substr($requiresTwoPadding, 0, -1)];
        yield 'classic excessive padding' => [$requiresOnePadding . '='];
        yield 'URL-safe partial padding' => [strtr(substr($requiresTwoPadding, 0, -1), '+/', '-_')];
        yield 'URL-safe excessive padding' => [strtr($requiresOnePadding . '=', '+/', '-_')];
    }

    #[DataProvider('malformedBase64CredentialPaddingProvider')]
    public function testRejectsEmbeddedCredentialsWithMalformedBase64Padding(string $malformed): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix|' . $malformed . '|suffix';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    #[DataProvider('benignEncodedPublicValueProvider')]
    public function testAllowsBenignStructuredAndEncodedPublicValues(string $publicValue): void
    {
        try {
            PaperMarketEventRedactor::assertSafe(['raw' => $publicValue]);
        } catch (\InvalidArgumentException $exception) {
            self::fail(sprintf(
                'Benign structured public value was rejected with %s.',
                $exception->getMessage(),
            ));
        }

        self::addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function benignEncodedPublicValueProvider(): iterable
    {
        $publicJson = '{"symbol":"BTCUSDT","price":"29999.0"}';

        yield 'public JSON' => [$publicJson];
        yield 'public form' => ['symbol=BTCUSDT&price=29999.0'];
        yield 'serialized integer' => ['i:42;'];
        yield 'padded public base64' => [base64_encode($publicJson)];
    }

    public function testRejectsEncodedStringsBeyondTheBoundedDecodeDepth(): void
    {
        $encoded = '{"symbol":"BTCUSDT"}';
        for ($depth = 0; $depth < 6; ++$depth) {
            $encoded = json_encode($encoded, JSON_THROW_ON_ERROR);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_decode_depth_exceeded');

        PaperMarketEventRedactor::assertSafe(['raw' => $encoded]);
    }

    public function testAllowsDecodedNodesAtTheDocumentedBoundary(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'raw' => json_encode(array_fill(0, 4_094, null), JSON_THROW_ON_ERROR),
        ]);

        self::addToAssertionCount(1);
    }

    public function testRejectsDecodedNodesBeyondTheDocumentedBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_decode_nodes_exceeded');

        PaperMarketEventRedactor::assertSafe([
            'raw' => json_encode(array_fill(0, 4_095, null), JSON_THROW_ON_ERROR),
        ]);
    }

    public function testRejectsAggregateDecodedBytesWithAStableCode(): void
    {
        $nestedBase64 = base64_encode(base64_encode(str_repeat('x', 500_000)));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_decode_bytes_exceeded');

        PaperMarketEventRedactor::assertSafe(['raw' => $nestedBase64]);
    }

    public function testRejectsManyFormPairsWithinSixtyFourMegabytesWithAStableCode(): void
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = sprintf(
            <<<'PHP'
require %s;

try {
    \App\Trading\Paper\MarketData\PaperMarketEventRedactor::assertSafe([
        str_repeat('a=&', 349525),
    ]);
} catch (\InvalidArgumentException $exception) {
    fwrite(STDOUT, $exception->getMessage());
    exit(0);
}

fwrite(STDOUT, 'unexpected_success');
exit(2);
PHP,
            var_export($autoload, true),
        );
        $process = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=64M',
            '-d',
            'xdebug.mode=off',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-r',
            $script,
        ]);
        $process->setTimeout(20.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame('paper_market_sensitive_decode_nodes_exceeded', $process->getOutput());
    }

    #[RunInSeparateProcess]
    public function testRejectsCyclicPayloadsWithAStableCode(): void
    {
        $payload = [];
        $payload['self'] = &$payload;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_payload_cycle_detected');

        PaperMarketEventRedactor::assertSafe($payload);
    }

    public function testRejectsPayloadsBeyondTheBoundedNestingDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_payload_depth_exceeded');

        PaperMarketEventRedactor::assertSafe(self::nestedPayload(129));
    }

    public function testAllowsPayloadsAtTheBoundedNestingDepth(): void
    {
        PaperMarketEventRedactor::assertSafe(self::nestedPayload(128));

        self::addToAssertionCount(1);
    }

    public function testAllowsPublicFieldsThroughNestedMapsAndLists(): void
    {
        $payload = [
            'symbol' => 'BTCUSDT',
            'timestamp' => '2026-07-19T10:00:00.123456Z',
            'sequence' => '42',
            'levels' => [
                ['price' => '29999.0', 'size' => '1.2', 'bid' => true],
                ['price' => '30001.0', 'size' => '0.8', 'ask' => true],
            ],
        ];

        PaperMarketEventRedactor::assertSafe($payload);

        self::addToAssertionCount(1);
    }

    public function testSensitiveWordsInValuesAreNotMistakenForPrivateFields(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'description' => 'public wallet statistics without a private identifier',
            'status' => 'signature verification unavailable',
            'mode' => 'basic market snapshot',
            'unicode_note' => 'Marché public à Paris — 東京 🚀',
        ]);

        self::addToAssertionCount(1);
    }

    public function testAllowsOrdinaryPublicKeyMaterialAndAnEmptyBearerScheme(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'public_key' => "-----BEGIN PUBLIC KEY-----\ncHVibGljLW1hdGVyaWFs\n-----END PUBLIC KEY-----",
            'certificate' => "-----BEGIN CERTIFICATE-----\ncHVibGljLWNlcnRpZmljYXRl\n-----END CERTIFICATE-----",
            'header_status' => 'Bearer ',
        ]);

        self::addToAssertionCount(1);
    }

    public function testRejectsActualBasicCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        PaperMarketEventRedactor::assertSafe([
            'header' => 'Basic ' . base64_encode('synthetic-user:synthetic-secret-sentinel'),
        ]);
    }

    public function testRejectsBasicCredentialsWithNoncanonicalPadding(): void
    {
        try {
            PaperMarketEventRedactor::assertSafe(['header' => 'Basic dTpw==']);
            self::fail('Basic credentials with noncanonical padding must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString('dTpw', $exception->getMessage());
        }
    }

    public function testAllowsPublicBasicProtocolStatusText(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'connection' => 'basic websocket disconnected',
            'update' => 'basic incremental update',
        ]);

        self::addToAssertionCount(1);
    }

    public function testAllowsPublicTextNearStructuredCredentialDetectionBoundaries(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'prose' => 'ignored & websocket disconnected',
            'status_text' => 'connection_state: disconnected',
            'query_status' => 'ignored&connection_state=disconnected',
        ]);

        self::addToAssertionCount(1);
    }

    #[DataProvider('formValueWithInvalidComponentProvider')]
    public function testScansValidFormPairsIndependentlyOfInvalidComponents(string $raw): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            ['synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function formValueWithInvalidComponentProvider(): iterable
    {
        yield 'non-pair before sensitive pair' => [
            'public&api+key=synthetic-redaction-sentinel',
        ];
        yield 'non-pair after sensitive pair' => [
            'api+key=synthetic-redaction-sentinel&public',
        ];
        yield 'non-form key before sensitive pair' => [
            'public note=value&api+key=synthetic-redaction-sentinel',
        ];
    }

    public function testAllowsOnlyThePlanApprovedPublicMarketKeys(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'symbol' => 'BTCUSDT',
            'price' => '29999.0',
            'size' => '1.25',
            'bid' => '29998.5',
            'ask' => '29999.5',
            'timestamp' => '2026-07-19T10:00:00.123456Z',
            'sequence' => '42',
        ]);

        self::addToAssertionCount(1);
    }

    #[DataProvider('normalizedSignFragmentProvider')]
    public function testRejectsEveryNormalizedSignSubstringInDirectMapKeys(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        PaperMarketEventRedactor::assertSafe([$key => 'public-market-value']);
    }

    #[DataProvider('normalizedSignFragmentProvider')]
    public function testRejectsEveryNormalizedSignSubstringAfterEncoding(string $key): void
    {
        foreach ([
            rawurlencode($key) . '=public-market-value',
            base64_encode(json_encode([$key => 'public-market-value'], JSON_THROW_ON_ERROR)),
        ] as $encoded) {
            try {
                PaperMarketEventRedactor::assertSafe(['raw' => $encoded]);
                self::fail(sprintf('Encoded sign-fragment key %s was accepted.', $key));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function normalizedSignFragmentProvider(): iterable
    {
        yield 'signed payload' => ['signed_payload'];
        yield 'signal' => ['signal'];
        yield 'assignment' => ['assignment'];
        yield 'design' => ['design'];
        yield 'resigned payload' => ['resign_payload'];
    }

    #[DataProvider('mandatorySensitiveMetadataKeyProvider')]
    public function testRejectsMandatorySensitiveFragmentsInMetadataMapKeys(
        string $key,
        string $value,
    ): void {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe([$key => $value]),
            [$key, $value],
        );
    }

    #[DataProvider('mandatorySensitiveMetadataKeyProvider')]
    public function testRejectsMandatorySensitiveFragmentsInDecodedFormKeys(
        string $key,
        string $value,
    ): void {
        $raw = self::percentEncodeEveryByte($key) . '=' . rawurlencode($value);

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$key, $value],
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function mandatorySensitiveMetadataKeyProvider(): iterable
    {
        yield 'authorization fragment' => ['authorization_status', 'not_applicable'];
        yield 'API key fragment' => ['api_key_hint', 'not_present'];
        yield 'signature fragment' => ['signature_count', '0'];
        yield 'wallet fragment' => ['wallet_balance_model', 'unknown'];
        yield 'seed phrase fragment' => ['seed_phrase_model', 'not_applicable'];
    }

    public function testAllowsOrdinaryPublicRatioNotationNearSerializedArrayPrefix(): void
    {
        PaperMarketEventRedactor::assertSafe(['raw' => 'public ratio a:1 currently']);

        self::addToAssertionCount(1);
    }

    #[DataProvider('sensitiveSignAssignmentProvider')]
    public function testStillRejectsExplicitSensitiveSignAssignments(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        PaperMarketEventRedactor::assertSafe([
            'raw' => $key . '=synthetic-secret-sentinel',
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveSignAssignmentProvider(): iterable
    {
        yield 'sign' => ['sign'];
        yield 'signature' => ['signature'];
        yield 'prefixed sign' => ['order_sign'];
        yield 'numeric signature suffix' => ['order_signature64'];
    }

    #[DataProvider('sensitiveAssignmentAsciiWhitespaceProvider')]
    public function testRejectsSensitiveAssignmentWithAsciiWhitespaceAroundEquals(
        string $whitespace,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'api_key' . $whitespace . '=' . $whitespace . $sentinel;

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEventRedactor::assertSafe(['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveAssignmentAsciiWhitespaceProvider(): iterable
    {
        yield 'newline' => ["\n"];
        yield 'form-feed' => ["\f"];
    }

    public function testSemanticMetadataKeyCannotAllowCredentialMaterial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        PaperMarketEventRedactor::assertSafe([
            'api_key_hint' => 'synthetic-secret-sentinel',
        ]);
    }

    private static function percentEncodeEveryByte(string $value): string
    {
        $encoded = '';
        foreach (str_split($value) as $byte) {
            $encoded .= sprintf('%%%02X', ord($byte));
        }

        return $encoded;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64CredentialWithPaddingCount(int $paddingCount): string
    {
        for ($suffixLength = 0; $suffixLength < 3; ++$suffixLength) {
            $json = json_encode(
                [
                    'api_key' => 'synthetic-secret-sentinel',
                    'note' => "\u{1003E}",
                    'suffix' => str_repeat('x', $suffixLength),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
            $encoded = base64_encode($json);
            if (\strlen($encoded) - \strlen(rtrim($encoded, '=')) === $paddingCount) {
                return $encoded;
            }
        }

        throw new \LogicException('paper_market_test_base64_padding_fixture_unavailable');
    }

    /**
     * @param callable(): void $operation
     * @param list<string>      $prohibitedFragments
     */
    private static function assertSensitiveRejectionWithoutDisclosure(
        callable $operation,
        array $prohibitedFragments,
    ): void {
        try {
            $operation();
            self::fail('Embedded credential material must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());

            $current = $exception;
            do {
                foreach ($prohibitedFragments as $fragment) {
                    self::assertStringNotContainsString($fragment, $current->getMessage());
                }

                $current = $current->getPrevious();
            } while ($current !== null);
        }
    }

    /**
     * @param callable(): void $operation
     * @param list<string>      $prohibitedFragments
     */
    private static function assertSensitiveRejectionWithFullTraceWithoutDisclosure(
        #[\SensitiveParameter] callable $operation,
        #[\SensitiveParameter] array $prohibitedFragments,
    ): void {
        try {
            $operation();
            self::fail('A prefixed composed credential key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            $trace = self::renderExceptionTraceChain($exception);
            foreach ($prohibitedFragments as $fragment) {
                self::assertStringNotContainsString($fragment, $trace);
            }
        }
    }

    private static function renderExceptionTraceChain(\Throwable $exception): string
    {
        $rendered = '';
        $current = $exception;
        do {
            $rendered .= print_r([
                'message' => $current->getMessage(),
                'trace' => $current->getTrace(),
            ], true);
            $current = $current->getPrevious();
        } while ($current !== null);

        return $rendered;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function nestedPayload(int $levels): array
    {
        $payload = ['price' => '29999.0'];
        for ($level = 0; $level < $levels; ++$level) {
            $payload = ['nested' => $payload];
        }

        return $payload;
    }
}
