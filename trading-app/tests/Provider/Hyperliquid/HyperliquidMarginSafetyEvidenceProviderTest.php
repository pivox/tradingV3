<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Provider\Hyperliquid\HyperliquidMarginSafetyEvidenceMapper;
use App\Provider\Hyperliquid\HyperliquidMarginSafetyEvidenceProvider;
use App\Provider\Hyperliquid\HyperliquidMarginSafetyEvidenceProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidMarginSafetyEvidenceProvider::class)]
final class HyperliquidMarginSafetyEvidenceProviderTest extends TestCase
{
    public function testFetchesMetaThenCurrentAccountEvidenceAndMapsIt(): void
    {
        $client = new MarginEvidenceInfoClient();
        $provider = new HyperliquidMarginSafetyEvidenceProvider(
            $client,
            $this->config(),
            new HyperliquidMarginSafetyEvidenceMapper(),
            new MockClock('2026-07-12T12:00:00Z'),
        );

        $evidence = $provider->current('BTCUSDT', '100', 5);

        self::assertInstanceOf(HyperliquidMarginSafetyEvidenceProviderInterface::class, $provider);
        self::assertSame('0.05', $evidence->maintenanceMarginRate);
        self::assertSame('2026-07-12T12:00:00+00:00', $evidence->observedAt->format('c'));
        self::assertSame([
            ['type' => 'meta'],
            [
                'type' => 'activeAssetData',
                'user' => '0x1111111111111111111111111111111111111111',
                'coin' => 'BTC',
            ],
        ], $client->requests);
    }

    public function testRejectsMissingConfiguredAccountBeforeAnyRead(): void
    {
        $client = new MarginEvidenceInfoClient();
        $provider = new HyperliquidMarginSafetyEvidenceProvider(
            $client,
            new HyperliquidConfig(environment: 'testnet', network: 'testnet'),
            new HyperliquidMarginSafetyEvidenceMapper(),
            new MockClock('2026-07-12T12:00:00Z'),
        );

        $this->expectException(\RuntimeException::class);
        try {
            $provider->current('BTCUSDT', '100', 5);
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    private function config(): HyperliquidConfig
    {
        return new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: 'testnet',
            testnetAccountAddress: '0x1111111111111111111111111111111111111111',
        );
    }
}

final class MarginEvidenceInfoClient implements HyperliquidRestClientInterface
{
    /** @var list<array<string,mixed>> */
    public array $requests = [];

    public function info(array $request): array
    {
        $this->requests[] = $request;

        return match ($request['type'] ?? null) {
            'meta' => [
                'universe' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 10]],
                'marginTables' => [],
            ],
            'activeAssetData' => ['leverage' => ['type' => 'isolated', 'value' => 5]],
            default => throw new \LogicException('unexpected info request'),
        };
    }

    public function exchange(array $action): array
    {
        throw new \LogicException('exchange must not be called');
    }
}
