<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Exchange\Adapter\OkxExchangeAdapter;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxContractValueResolver;
use App\Exchange\Okx\OkxExchangeEventNormalizer;
use App\Exchange\Okx\OkxInstrumentResolver;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Provider\Okx\OkxPrivateReadMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Real-OKX fill producers declare quantity_unit "contracts" and the instrument's ctVal, so the
 * fill-cost ledger converts contracts x ctVal to base-asset units; an unresolvable ctVal is
 * left out (the ledger then flags contract_value_missing).
 */
#[CoversClass(OkxContractValueResolver::class)]
final class OkxFillContractValueTest extends TestCase
{
    public function testResolverReadsCtValOnceAndCanonicalizesIt(): void
    {
        $client = new CtValClient();
        $resolver = new OkxContractValueResolver($client);

        self::assertSame('0.01', $resolver->contractValue('BTC-USDT-SWAP'));
        self::assertSame('0.1', $resolver->contractValue('eth-usdt-swap'));
        self::assertSame('1', $resolver->contractValue('SOL-USDT-SWAP'));
        self::assertNull($resolver->contractValue('BAD-USDT-SWAP'));
        self::assertNull($resolver->contractValue('NOPE-USDT-SWAP'));
        self::assertSame(1, $client->instrumentCalls, 'one bulk load, misses are throttled');
    }

    public function testResolverFailsSafeWhenTheEndpointFails(): void
    {
        $client = new CtValClient();
        $client->fail = true;
        $resolver = new OkxContractValueResolver($client);

        self::assertNull($resolver->contractValue('BTC-USDT-SWAP'));
        self::assertSame(
            ['quantity_unit' => 'contracts'],
            OkxContractValueResolver::fillMetadata($resolver, 'BTC-USDT-SWAP'),
        );
        self::assertSame(
            ['quantity_unit' => 'contracts'],
            OkxContractValueResolver::fillMetadata(null, 'BTC-USDT-SWAP'),
        );
    }

    public function testPrivateReadMapperFillCarriesContractUnitAndValue(): void
    {
        $mapper = new OkxPrivateReadMapper(
            new OkxInstrumentResolver(),
            new OkxContractValueResolver(new CtValClient()),
        );

        $fill = $mapper->fill($this->row());

        self::assertSame('contracts', $fill->metadata['quantity_unit'] ?? null);
        self::assertSame('0.01', $fill->metadata['contract_value'] ?? null);
        self::assertArrayNotHasKey('contract_value', (new OkxPrivateReadMapper())->fill($this->row())->metadata);
    }

    public function testAdapterFillsSnapshotCarriesContractUnitAndValue(): void
    {
        $client = new CtValClient();
        $adapter = new OkxExchangeAdapter(
            $client,
            new OkxInstrumentResolver(),
            new OkxActionFactory(),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                simulatedTrading: true,
                demoTradingEnabled: true,
            ),
            $this->clock(),
        );

        $fills = $adapter->getFillsSnapshot('BTCUSDT');

        self::assertCount(1, $fills);
        self::assertSame('contracts', $fills[0]->metadata['quantity_unit'] ?? null);
        self::assertSame('0.01', $fills[0]->metadata['contract_value'] ?? null);
    }

    public function testWebSocketNormalizerFillCarriesContractUnitAndValue(): void
    {
        $normalizer = new OkxExchangeEventNormalizer(
            new OkxInstrumentResolver(),
            $this->clock(),
            new OkxContractValueResolver(new CtValClient()),
        );

        $events = $normalizer->normalize([
            'arg' => ['channel' => 'orders', 'instType' => 'SWAP'],
            'data' => [[
                'accFillSz' => '3', 'fillPx' => '30000', 'fillSz' => '3', 'fillTime' => '1767225601123',
                'instId' => 'BTC-USDT-SWAP', 'instType' => 'SWAP', 'ordId' => 'o1', 'ordType' => 'limit',
                'posSide' => 'long', 'px' => '30000', 'side' => 'buy', 'state' => 'filled', 'sz' => '3',
                'tradeId' => 't1', 'uTime' => '1767225601123',
            ]],
        ]);

        $fills = array_values(array_filter($events, static fn (mixed $e): bool => $e instanceof ExchangeFillReceived));
        self::assertCount(1, $fills);
        self::assertSame('contracts', $fills[0]->fill()->metadata['quantity_unit'] ?? null);
        self::assertSame('0.01', $fills[0]->fill()->metadata['contract_value'] ?? null);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return [
            'instId' => 'BTC-USDT-SWAP', 'ordId' => 'o1', 'tradeId' => 't1', 'side' => 'buy',
            'posSide' => 'long', 'fillSz' => '3', 'fillPx' => '30000', 'fee' => '-0.1',
            'feeCcy' => 'USDT', 'ts' => '1767225601123',
        ];
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }
}

final class CtValClient implements OkxRestClientInterface
{
    public int $instrumentCalls = 0;
    public bool $fail = false;

    public function publicGet(string $path, array $query = []): array
    {
        if ($path !== '/api/v5/public/instruments') {
            return ['code' => '0', 'data' => []];
        }
        ++$this->instrumentCalls;
        if ($this->fail) {
            throw new \RuntimeException('okx unavailable');
        }

        return ['code' => '0', 'data' => [
            ['instId' => 'BTC-USDT-SWAP', 'ctVal' => '0.0100'],
            ['instId' => 'ETH-USDT-SWAP', 'ctVal' => '0.1'],
            ['instId' => 'SOL-USDT-SWAP', 'ctVal' => '1'],
            ['instId' => 'BAD-USDT-SWAP', 'ctVal' => '0'],
        ]];
    }

    public function privateGet(string $path, array $query = []): array
    {
        if ($path === '/api/v5/trade/fills') {
            return ['code' => '0', 'data' => [[
                'instId' => 'BTC-USDT-SWAP', 'ordId' => 'o1', 'tradeId' => 't1', 'side' => 'buy',
                'posSide' => 'long', 'fillSz' => '3', 'fillPx' => '30000', 'fee' => '-0.1',
                'feeCcy' => 'USDT', 'ts' => '1767225601123',
            ]]];
        }

        return ['code' => '0', 'data' => []];
    }

    public function privatePost(string $path, array $body = []): array
    {
        return ['code' => '0', 'data' => []];
    }
}
