<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Exchange\Hyperliquid\HyperliquidAssetResolver;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Provider\Hyperliquid\HyperliquidMarketGateway;
use App\Provider\Hyperliquid\HyperliquidMetadataProvider;
use App\Provider\Hyperliquid\HyperliquidProviderUnavailableException;
use App\Provider\Hyperliquid\HyperliquidRuntimeCheck;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HyperliquidPublicReadProviderTest extends TestCase
{
    public function testLoadsPerpMetadataAndAssetMapping(): void
    {
        $client = $this->client();
        $provider = new HyperliquidMetadataProvider($client, new HyperliquidAssetResolver($client));

        $contracts = $provider->getContracts();

        self::assertCount(2, $contracts);
        self::assertSame('BTCUSDT', $contracts[0]->symbol);
        self::assertSame('BTC', $contracts[0]->baseCurrency);
        self::assertSame('USDC', $contracts[0]->quoteCurrency);
        self::assertSame('1', (string) $contracts[0]->pricePrecision);
        self::assertSame('5', (string) $contracts[0]->volPrecision);
        self::assertSame('50', (string) $contracts[0]->maxLeverage);
        self::assertSame('live', $contracts[0]->status);
        self::assertSame('ETHUSDT', $contracts[1]->symbol);
        self::assertSame('2', (string) $contracts[1]->pricePrecision);
        self::assertSame('suspend', $contracts[1]->status);

        self::assertSame(0, $provider->assetId('BTCUSDT'));
        self::assertSame('BTC', $provider->coin('BTC-USDC'));
    }

    public function testLoadsTickerAndOrderBook(): void
    {
        $client = $this->client();
        $provider = new HyperliquidMetadataProvider($client, new HyperliquidAssetResolver($client));

        self::assertSame(25123.4, $provider->getLastPrice('BTCUSDT'));

        $book = $provider->getOrderBook('BTCUSDT', 2);

        self::assertSame('BTCUSDT', $book['symbol']);
        self::assertSame('BTC', $book['coin']);
        self::assertSame('2026-01-01T00:00:00+00:00', $book['timestamp']->format('c'));
        self::assertSame([['price' => 25123.3, 'quantity' => 4.0], ['price' => 25123.2, 'quantity' => 3.0]], $book['bids']);
        self::assertSame([['price' => 25123.5, 'quantity' => 2.0], ['price' => 25123.6, 'quantity' => 1.0]], $book['asks']);
    }

    public function testFundingAbsentIsUnknownNotZero(): void
    {
        $client = $this->client();
        $client->hideFunding = true;
        $provider = new HyperliquidMetadataProvider($client, new HyperliquidAssetResolver($client));

        $metadata = $provider->getInstrumentMetadata('BTCUSDT');

        self::assertNotNull($metadata);
        self::assertSame('BTCUSDT', $metadata->symbol);
        self::assertSame('BTC', $metadata->coin);
        self::assertNull($metadata->fundingRate);
        self::assertContains('funding_rate_unknown', $metadata->qualityFlags);
        self::assertTrue($metadata->isCompleteForSizing());
    }

    public function testHyperliquidPriceRulesEnforceSignificantFiguresAndMaxDecimals(): void
    {
        $provider = new HyperliquidMetadataProvider($this->client(), new HyperliquidAssetResolver($this->client()));

        $metadata = $provider->getInstrumentMetadata('BTCUSDT');

        self::assertNotNull($metadata);
        self::assertSame('0.1', $metadata->priceTick);

        $valid = $metadata->validateOrderShape('9999.9', '0.12345');
        self::assertTrue($valid['price_valid']);
        self::assertSame('9999.9', $valid['price_quantized']);

        $tooPrecise = $metadata->validateOrderShape('25123.45', '0.12345');
        self::assertFalse($tooPrecise['price_valid']);
        self::assertSame('25123', $tooPrecise['price_quantized']);
        self::assertContains('price_precision_mismatch', $tooPrecise['quality_flags']);
    }

    public function testNormalizesKlinesInAscendingUtcOrderAndDeduplicates(): void
    {
        $client = $this->client();
        $gateway = new HyperliquidMarketGateway($client, new HyperliquidAssetResolver($client));

        $klines = $gateway->getKlinesInWindow(
            'BTCUSDT',
            Timeframe::TF_1M,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-01-01T00:02:00+00:00'),
            2,
        );

        self::assertCount(2, $klines);
        self::assertSame('BTCUSDT', $klines[0]->symbol);
        self::assertSame(Timeframe::TF_1M, $klines[0]->timeframe);
        self::assertSame('2026-01-01T00:00:00+00:00', $klines[0]->openTime->format('c'));
        self::assertSame('25100', (string) $klines[0]->open);
        self::assertSame('25125', (string) $klines[0]->high);
        self::assertSame('25090', (string) $klines[0]->low);
        self::assertSame('25120', (string) $klines[0]->close);
        self::assertSame('12.5', (string) $klines[0]->volume);
        self::assertSame('HYPERLIQUID_REST_PUBLIC', $klines[0]->source);
        self::assertSame('2026-01-01T00:01:00+00:00', $klines[1]->openTime->format('c'));
    }

    public function testDefaultKlineReadUsesBoundedRecentWindow(): void
    {
        $client = $this->client();
        $gateway = new HyperliquidMarketGateway($client, new HyperliquidAssetResolver($client));

        $gateway->getKlines('BTCUSDT', Timeframe::TF_1M, 100);

        $request = $client->requests[array_key_last($client->requests)] ?? [];
        self::assertSame('candleSnapshot', $request['type'] ?? null);
        self::assertIsArray($request['req'] ?? null);

        $req = $request['req'];
        self::assertSame(100 * 60 * 1000, $req['endTime'] - $req['startTime']);
        self::assertGreaterThan(0, $req['startTime']);
    }

    public function testPublicRateLimitIsNormalized(): void
    {
        $client = $this->client();
        $client->rateLimited = true;
        $provider = new HyperliquidMetadataProvider($client, new HyperliquidAssetResolver($client));

        $this->expectException(HyperliquidProviderUnavailableException::class);
        $this->expectExceptionMessage('hyperliquid_public_rate_limited');

        $provider->getLastPrice('BTCUSDT');
    }

    public function testRuntimeCheckCanReachPublicReadOnlyForHl003(): void
    {
        $report = (new HyperliquidRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            environment: 'testnet',
            publicConnectivity: true,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: false,
            dryRun: true,
        ));

        self::assertSame(ExchangeReadinessLevel::PublicReadOnly, $report->readyLevel);
        self::assertSame([], $report->blockingErrors);
        self::assertNotContains('hyperliquid_provider_bundle_skeleton_not_ready', $report->blockingErrors);
        self::assertNotContains('hyperliquid_public_read_not_ready', $report->warnings);
        self::assertContains('hyperliquid_account_read_not_ready', $report->warnings);
        self::assertFalse($report->permissionsTrade);
        self::assertTrue($report->killSwitch);
    }

    private function client(): FakeHyperliquidPublicReadClient
    {
        return new FakeHyperliquidPublicReadClient();
    }
}

final class FakeHyperliquidPublicReadClient implements HyperliquidRestClientInterface
{
    public bool $rateLimited = false;
    public bool $hideFunding = false;

    /** @var list<array<string,mixed>> */
    public array $requests = [];

    /**
     * @param array<string,mixed> $request
     * @return array<mixed>
     */
    public function info(array $request): array
    {
        if ($this->rateLimited) {
            throw new \RuntimeException('HTTP 429 Too Many Requests');
        }

        $this->requests[] = $request;

        return match ((string) ($request['type'] ?? '')) {
            'meta' => $this->meta(),
            'metaAndAssetCtxs' => $this->metaAndAssetCtxs(),
            'allMids' => ['BTC' => '25123.4', 'ETH' => '1800.1'],
            'l2Book' => $this->l2Book($request),
            'candleSnapshot' => $this->candles($request),
            'fundingHistory' => $this->funding($request),
            default => throw new \LogicException('Unexpected Hyperliquid info type: ' . (string) ($request['type'] ?? '')),
        };
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    public function exchange(array $action): array
    {
        throw new \LogicException('Hyperliquid public read tests must not call /exchange.');
    }

    /**
     * @return array<string,mixed>
     */
    private function meta(): array
    {
        return [
            'universe' => [
                ['name' => 'BTC', 'szDecimals' => 5, 'maxLeverage' => 50],
                ['name' => 'ETH', 'szDecimals' => 4, 'maxLeverage' => 25, 'isDelisted' => true],
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    private function metaAndAssetCtxs(): array
    {
        return [
            $this->meta(),
            [
                [
                    'markPx' => '25123.4',
                    'midPx' => '25123.4',
                    'prevDayPx' => '25000',
                    'dayNtlVlm' => '1000000',
                    'funding' => $this->hideFunding ? null : '0.0001',
                    'openInterest' => '123.45',
                ],
                [
                    'markPx' => '1800.1',
                    'midPx' => '1800.1',
                    'prevDayPx' => '1810',
                    'dayNtlVlm' => '500000',
                    'funding' => '0.0002',
                    'openInterest' => '42',
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function l2Book(array $request): array
    {
        $this->assertRequestValue($request, 'coin', 'BTC');

        return [
            'coin' => 'BTC',
            'time' => 1767225600000,
            'levels' => [
                [['px' => '25123.3', 'sz' => '4', 'n' => 2], ['px' => '25123.2', 'sz' => '3', 'n' => 1]],
                [['px' => '25123.5', 'sz' => '2', 'n' => 3], ['px' => '25123.6', 'sz' => '1', 'n' => 1]],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @return list<array<string,mixed>>
     */
    private function candles(array $request): array
    {
        $req = $request['req'] ?? null;
        if (!\is_array($req)) {
            throw new \RuntimeException('Expected Hyperliquid candleSnapshot req object.');
        }
        $this->assertRequestValue($req, 'coin', 'BTC');
        $this->assertRequestValue($req, 'interval', '1m');

        return [
            ['t' => 1767225660000, 'T' => 1767225719999, 's' => 'BTC', 'i' => '1m', 'o' => '25120', 'h' => '25130', 'l' => '25110', 'c' => '25125', 'v' => '9.0', 'n' => 4],
            ['t' => 1767225600000, 'T' => 1767225659999, 's' => 'BTC', 'i' => '1m', 'o' => '25100', 'h' => '25125', 'l' => '25090', 'c' => '25120', 'v' => '12.5', 'n' => 6],
            ['t' => 1767225600000, 'T' => 1767225659999, 's' => 'BTC', 'i' => '1m', 'o' => '25100', 'h' => '25125', 'l' => '25090', 'c' => '25120', 'v' => '12.5', 'n' => 6],
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @return list<array<string,mixed>>
     */
    private function funding(array $request): array
    {
        $this->assertRequestValue($request, 'coin', 'BTC');

        if ($this->hideFunding) {
            return [];
        }

        return [[
            'coin' => 'BTC',
            'fundingRate' => '0.0001',
            'premium' => '0.00001',
            'time' => 1767225600000,
        ]];
    }

    /**
     * @param array<string,mixed> $request
     */
    private function assertRequestValue(array $request, string $key, string $expected): void
    {
        $actual = (string) ($request[$key] ?? '');
        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('Expected Hyperliquid request %s=%s, got %s.', $key, $expected, $actual));
        }
    }
}
