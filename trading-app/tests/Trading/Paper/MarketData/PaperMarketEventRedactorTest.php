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
        yield 'Cyrillic Unicode confusable' => ["аpi_key"];
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

    public function testAllowsPublicBasicProtocolStatusText(): void
    {
        PaperMarketEventRedactor::assertSafe([
            'connection' => 'basic websocket disconnected',
            'update' => 'basic incremental update',
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
