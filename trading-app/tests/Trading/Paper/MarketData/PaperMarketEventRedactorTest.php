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
    }

    public function testRejectsAnUnboundedStringBeforeScanningIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_payload_string_too_large');

        PaperMarketEventRedactor::assertSafe([
            'public_snapshot' => str_repeat('x', 1_048_577),
        ]);
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
        ]);

        self::addToAssertionCount(1);
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
