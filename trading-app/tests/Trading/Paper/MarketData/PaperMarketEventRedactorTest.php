<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\MarketData;

use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
                ['nested' => ['public' => [$key => 'must-not-be-stored']]],
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
}
