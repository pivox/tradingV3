<?php

declare(strict_types=1);

namespace App\Tests\Provider\Okx;

use App\Common\Enum\Timeframe;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Provider\Okx\OkxMarketDataGateway;
use App\Provider\Okx\OkxMetadataProvider;
use App\Provider\Okx\OkxProviderUnavailableException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OkxPublicReadProviderTest extends TestCase
{
    public function testLoadsSwapInstrumentsWithPrecisionAndStatus(): void
    {
        $provider = new OkxMetadataProvider($this->client());

        $contracts = $provider->getContracts();

        self::assertCount(2, $contracts);
        self::assertSame('BTCUSDT', $contracts[0]->symbol);
        self::assertSame('BTC', $contracts[0]->baseCurrency);
        self::assertSame('USDT', $contracts[0]->quoteCurrency);
        self::assertSame('0.01', (string) $contracts[0]->contractSize);
        self::assertSame('1', (string) $contracts[0]->pricePrecision);
        self::assertSame('2', (string) $contracts[0]->volPrecision);
        self::assertSame('0.01', (string) $contracts[0]->minVolume);
        self::assertSame('100', (string) $contracts[0]->maxVolume);
        self::assertSame('live', $contracts[0]->status);

        self::assertSame('ETHUSDT', $contracts[1]->symbol);
        self::assertSame('suspend', $contracts[1]->status);
    }

    public function testLoadsContractDetailsAndTicker(): void
    {
        $provider = new OkxMetadataProvider($this->client());

        $contract = $provider->getContractDetails('BTCUSDT');

        self::assertNotNull($contract);
        self::assertSame('BTCUSDT', $contract->symbol);
        self::assertSame('25123.4', (string) $contract->lastPrice);
        self::assertSame('1000', (string) $contract->volume24h);
        self::assertSame('251234', (string) $contract->turnover24h);
    }

    public function testNormalizesOrderBookAndBestBidAsk(): void
    {
        $metadata = new OkxMetadataProvider($this->client());

        $book = $metadata->getOrderBook('BTCUSDT', 2);

        self::assertSame('BTCUSDT', $book['symbol']);
        self::assertSame('BTC-USDT-SWAP', $book['instrument_id']);
        self::assertSame('2026-01-01T00:00:00+00:00', $book['timestamp']->format('c'));
        self::assertSame([['price' => 25123.3, 'quantity' => 4.0], ['price' => 25123.2, 'quantity' => 3.0]], $book['bids']);
        self::assertSame([['price' => 25123.5, 'quantity' => 2.0], ['price' => 25123.6, 'quantity' => 1.0]], $book['asks']);

        $orders = new \App\Provider\Okx\OkxOrderGateway($this->client());
        $top = $orders->getOrderBookTop('BTCUSDT');

        self::assertSame('BTCUSDT', $top->symbol);
        self::assertSame(25123.3, $top->bid);
        self::assertSame(25123.5, $top->ask);
        self::assertSame('2026-01-01T00:00:00+00:00', $top->timestamp->format('c'));
    }

    public function testNormalizesKlinesInAscendingUtcOrder(): void
    {
        $gateway = new OkxMarketDataGateway($this->client());

        $klines = $gateway->getKlines('BTCUSDT', Timeframe::TF_1M, 2);

        self::assertCount(2, $klines);
        self::assertSame('BTCUSDT', $klines[0]->symbol);
        self::assertSame(Timeframe::TF_1M, $klines[0]->timeframe);
        self::assertSame('2026-01-01T00:00:00+00:00', $klines[0]->openTime->format('c'));
        self::assertSame('25100', (string) $klines[0]->open);
        self::assertSame('25125', (string) $klines[0]->high);
        self::assertSame('25090', (string) $klines[0]->low);
        self::assertSame('25120', (string) $klines[0]->close);
        self::assertSame('12.5', (string) $klines[0]->volume);
        self::assertSame('OKX_REST_PUBLIC', $klines[0]->source);

        self::assertSame('2026-01-01T00:01:00+00:00', $klines[1]->openTime->format('c'));
    }

    public function testPaginatesKlinesUntilRequestedLimit(): void
    {
        $client = $this->client();
        $client->paginatedCandles = true;
        $gateway = new OkxMarketDataGateway($client);

        $klines = $gateway->getKlines('BTCUSDT', Timeframe::TF_1M, 302);

        self::assertCount(302, $klines);
        self::assertSame('2025-12-31T19:00:00+00:00', $klines[0]->openTime->format('c'));
        self::assertSame('2026-01-01T00:01:00+00:00', $klines[301]->openTime->format('c'));
        self::assertSame([
            ['limit' => '300', 'after' => null],
            ['limit' => '2', 'after' => '1767207720000'],
        ], $client->candleQueries);
    }

    public function testNormalizesPublicRateLimitError(): void
    {
        $client = $this->client();
        $client->rateLimited = true;
        $provider = new OkxMetadataProvider($client);

        $this->expectException(OkxProviderUnavailableException::class);
        $this->expectExceptionMessage('okx_public_rate_limited');

        $provider->getLastPrice('BTCUSDT');
    }

    public function testSyncContractsReportsReadOnlyNotPersisted(): void
    {
        $provider = new OkxMetadataProvider($this->client());

        $result = $provider->syncContracts(['BTCUSDT']);

        self::assertSame(0, $result['upserted']);
        self::assertSame(2, $result['total_fetched']);
        self::assertSame(['okx_contract_sync_read_only_not_persisted'], $result['errors']);
    }

    public function testKlineApiBodyRateLimitIsPreserved(): void
    {
        $client = $this->client();
        $client->klineRateLimited = true;
        $gateway = new OkxMarketDataGateway($client);

        $this->expectException(OkxProviderUnavailableException::class);
        $this->expectExceptionMessage('okx_public_rate_limited');

        $gateway->getKlines('BTCUSDT', Timeframe::TF_1M, 2);
    }

    private function client(): FakeOkxPublicReadClient
    {
        return new FakeOkxPublicReadClient();
    }
}

final class FakeOkxPublicReadClient implements OkxRestClientInterface
{
    public bool $rateLimited = false;
    public bool $klineRateLimited = false;
    public bool $paginatedCandles = false;

    /** @var list<array{limit: string, after: string|null}> */
    public array $candleQueries = [];

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function publicGet(string $path, array $query = []): array
    {
        if ($this->rateLimited) {
            throw new \RuntimeException('HTTP 429 Too Many Requests');
        }

        return match ($path) {
            '/api/v5/public/instruments' => $this->instruments(),
            '/api/v5/market/ticker' => $this->ticker($query),
            '/api/v5/market/books' => $this->books($query),
            '/api/v5/market/candles' => $this->candles($query),
            default => ['code' => '404', 'msg' => 'unexpected path ' . $path, 'data' => []],
        };
    }

    public function privateGet(string $path, array $query = []): array
    {
        throw new \LogicException('Private OKX read must not be used by public providers.');
    }

    public function privatePost(string $path, array $body = []): array
    {
        throw new \LogicException('Private OKX write must not be used by public providers.');
    }

    /**
     * @return array<string,mixed>
     */
    private function instruments(): array
    {
        return ['code' => '0', 'data' => [
            [
                'instType' => 'SWAP',
                'instId' => 'BTC-USDT-SWAP',
                'baseCcy' => 'BTC',
                'quoteCcy' => 'USDT',
                'settleCcy' => 'USDT',
                'ctVal' => '0.01',
                'tickSz' => '0.1',
                'lotSz' => '0.01',
                'minSz' => '0.01',
                'maxMktSz' => '100',
                'maxLmtSz' => '150',
                'lever' => '100',
                'state' => 'live',
                'listTime' => '1767225600000',
                'expTime' => '',
            ],
            [
                'instType' => 'SWAP',
                'instId' => 'ETH-USDT-SWAP',
                'baseCcy' => 'ETH',
                'quoteCcy' => 'USDT',
                'settleCcy' => 'USDT',
                'ctVal' => '0.1',
                'tickSz' => '0.01',
                'lotSz' => '0.001',
                'minSz' => '0.001',
                'maxMktSz' => '50',
                'lever' => '50',
                'state' => 'suspend',
                'listTime' => '1767225600000',
                'expTime' => '',
            ],
        ]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function ticker(array $query): array
    {
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');

        return ['code' => '0', 'data' => [[
            'instId' => 'BTC-USDT-SWAP',
            'last' => '25123.4',
            'vol24h' => '1000',
            'volCcy24h' => '10',
            'ts' => '1767225600000',
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function books(array $query): array
    {
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');
        $size = (string) ($query['sz'] ?? '');
        if (!\in_array($size, ['1', '2'], true)) {
            throw new \RuntimeException(sprintf('Expected OKX query sz=1 or sz=2, got %s.', $size));
        }

        return ['code' => '0', 'data' => [[
            'ts' => '1767225600000',
            'bids' => [['25123.3', '4'], ['25123.2', '3']],
            'asks' => [['25123.5', '2'], ['25123.6', '1']],
        ]]];
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function candles(array $query): array
    {
        $this->assertQueryValue($query, 'instId', 'BTC-USDT-SWAP');
        $this->assertQueryValue($query, 'bar', '1m');

        if ($this->paginatedCandles) {
            $limit = (string) ($query['limit'] ?? '');
            $after = isset($query['after']) ? (string) $query['after'] : null;
            $this->candleQueries[] = ['limit' => $limit, 'after' => $after];

            return ['code' => '0', 'data' => $this->paginatedCandlesPage($limit, $after)];
        }

        $this->assertQueryValue($query, 'limit', '2');

        if ($this->klineRateLimited) {
            return ['code' => '50011', 'msg' => 'Too Many Requests', 'data' => []];
        }

        return ['code' => '0', 'data' => [
            ['1767225660000', '25120', '25130', '25110', '25125', '9.0', '0', '0', '0'],
            ['1767225600000', '25100', '25125', '25090', '25120', '12.5', '0', '0', '1'],
        ]];
    }

    /**
     * @return list<list<string>>
     */
    private function paginatedCandlesPage(string $limit, ?string $after): array
    {
        if ($after === null) {
            if ($limit !== '300') {
                throw new \RuntimeException(sprintf('Expected first OKX candle page limit=300, got %s.', $limit));
            }

            return $this->candleRows(1767225660000, 300);
        }

        if ($after !== '1767207720000' || $limit !== '2') {
            throw new \RuntimeException(sprintf('Unexpected second OKX candle page after=%s limit=%s.', $after, $limit));
        }

        return $this->candleRows(1767207660000, 2);
    }

    /**
     * @return list<list<string>>
     */
    private function candleRows(int $newestOpenMs, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $openMs = $newestOpenMs - ($i * 60_000);
            $open = (string) (25000 + $i);
            $rows[] = [
                (string) $openMs,
                $open,
                (string) ((int) $open + 10),
                (string) ((int) $open - 10),
                (string) ((int) $open + 5),
                '1',
                '0',
                '0',
                '1',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $query
     */
    private function assertQueryValue(array $query, string $key, string $expected): void
    {
        $actual = (string) ($query[$key] ?? '');
        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf('Expected OKX query %s=%s, got %s.', $key, $expected, $actual));
        }
    }
}
