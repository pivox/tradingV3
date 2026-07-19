<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\MarketData;

use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

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
            'assignment' => 'connection_state: disconnected',
            'query_status' => 'ignored&connection_state=disconnected',
        ]);

        self::addToAssertionCount(1);
    }

    public function testOnlyExactNormalizedSensitiveKeysAreRejected(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'authorization_status' => 'not_applicable',
            'api_key_hint' => 'not_present',
            'signature_count' => 0,
            'signed_price' => '29999.0',
            'signal' => 'public_trade',
            'wallet_balance_model' => 'unknown',
            'seed_phrase_model' => 'not_applicable',
            'assignment' => 'public_partition',
            'design' => 'public_layout',
        ]);

        self::addToAssertionCount(1);
    }

    public function testAllowsDesignAssignmentInFormString(): void
    {
        PaperMarketEventRedactor::assertSafe(['raw' => 'design=public_layout']);

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
