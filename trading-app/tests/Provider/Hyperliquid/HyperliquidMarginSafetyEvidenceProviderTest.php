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
        $clock = new MockClock('2026-07-12T12:00:00Z');
        $client = new MarginEvidenceInfoClient($clock, 500);
        $provider = new HyperliquidMarginSafetyEvidenceProvider(
            $client,
            $this->config(),
            new HyperliquidMarginSafetyEvidenceMapper(),
            $clock,
        );

        $evidence = $provider->current('BTCUSDT');

        self::assertInstanceOf(HyperliquidMarginSafetyEvidenceProviderInterface::class, $provider);
        self::assertSame('0.05', $evidence->tiers[0]->maintenanceMarginRate);
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

    public function testRejectsWhenTotalOfficialEvidenceReadsExceedTwoSeconds(): void
    {
        $clock = new MockClock('2026-07-12T12:00:00Z');
        $provider = new HyperliquidMarginSafetyEvidenceProvider(
            new MarginEvidenceInfoClient($clock, 1_001),
            $this->config(),
            new HyperliquidMarginSafetyEvidenceMapper(),
            $clock,
        );

        $this->expectException(\RuntimeException::class);
        $provider->current('BTCUSDT');
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
            $provider->current('BTCUSDT');
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

    public function __construct(
        private readonly ?MockClock $clock = null,
        private readonly int $delayMilliseconds = 0,
    ) {
    }

    public function info(array $request): array
    {
        $this->requests[] = $request;
        $this->clock?->sleep($this->delayMilliseconds / 1_000);

        return match ($request['type'] ?? null) {
            'meta' => [
                'universe' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 10]],
                'marginTables' => [],
            ],
            'activeAssetData' => [
                'user' => '0x1111111111111111111111111111111111111111',
                'coin' => 'BTC',
                'leverage' => ['type' => 'isolated', 'value' => 5],
            ],
            default => throw new \LogicException('unexpected info request'),
        };
    }

    public function exchange(array $action): array
    {
        throw new \LogicException('exchange must not be called');
    }
}
