<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Provider\Hyperliquid\Dto\HyperliquidMarginSafetyEvidence;
use App\Provider\Hyperliquid\Dto\HyperliquidMarginTierEvidence;
use App\Provider\Hyperliquid\HyperliquidMarginSafetyEvidenceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidMarginSafetyEvidenceMapper::class)]
#[CoversClass(HyperliquidMarginSafetyEvidence::class)]
#[CoversClass(HyperliquidMarginTierEvidence::class)]
final class HyperliquidMarginSafetyEvidenceMapperTest extends TestCase
{
    private const ACCOUNT = '0x1111111111111111111111111111111111111111';

    public function testRetainsCanonicalOfficialSingleTierTableAndObservedIdentities(): void
    {
        $evidence = $this->mapper()->map(
            meta: $this->meta(['name' => 'SOL', 'maxLeverage' => 10, 'marginTableId' => 10]),
            activeAssetData: $this->active('SOL', self::ACCOUNT, 'isolated', 5),
            symbol: 'SOLUSDT',
            accountAddress: self::ACCOUNT,
            observedAt: new \DateTimeImmutable('2026-07-12T12:00:00Z'),
        );

        self::assertSame('SOL', $evidence->coin);
        self::assertSame(10, $evidence->universeMaxLeverage);
        self::assertSame(self::ACCOUNT, $evidence->observedUser);
        self::assertSame('SOL', $evidence->observedCoin);
        self::assertSame('isolated', $evidence->observedMarginMode);
        self::assertSame(5, $evidence->observedLeverage);
        self::assertCount(1, $evidence->tiers);
        self::assertSame('0', $evidence->tiers[0]->lowerBound);
        self::assertSame(10, $evidence->tiers[0]->maxLeverage);
        self::assertSame('0.05', $evidence->tiers[0]->maintenanceMarginRate);
        self::assertSame('0', $evidence->tiers[0]->maintenanceMarginDeduction);
    }

    public function testRetainsAllTieredRowsWithCanonicalRatesAndCumulativeDeductions(): void
    {
        $evidence = $this->mapper()->map(
            meta: $this->meta(
                ['name' => 'ATOM', 'maxLeverage' => 10, 'marginTableId' => 52],
                [[52, ['marginTiers' => [
                    ['lowerBound' => '0.0', 'maxLeverage' => 10],
                    ['lowerBound' => '20000.0', 'maxLeverage' => 5],
                    ['lowerBound' => '50000.00', 'maxLeverage' => 3],
                ]]]],
            ),
            activeAssetData: $this->active('ATOM', self::ACCOUNT, 'cross', 3),
            symbol: 'ATOMUSDT',
            accountAddress: self::ACCOUNT,
            observedAt: new \DateTimeImmutable('2026-07-12T12:00:00Z'),
        );

        self::assertSame(['0', '20000', '50000'], array_map(static fn ($tier) => $tier->lowerBound, $evidence->tiers));
        self::assertSame([10, 5, 3], array_map(static fn ($tier) => $tier->maxLeverage, $evidence->tiers));
        self::assertSame('0.166666666666666666666666666666666667', $evidence->tiers[2]->maintenanceMarginRate);
        self::assertSame('4333.33333333333333333333333333333335', $evidence->tiers[2]->maintenanceMarginDeduction);
        self::assertSame('cross', $evidence->observedMarginMode);
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $active
     */
    #[DataProvider('invalidOfficialEvidence')]
    public function testRejectsMalformedTableOrObservedIdentity(array $meta, array $active): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper()->map(
            $meta,
            $active,
            'BTCUSDT',
            self::ACCOUNT,
            new \DateTimeImmutable('2026-07-12T12:00:00Z'),
        );
    }

    /** @return iterable<string,array{array<string,mixed>,array<string,mixed>}> */
    public static function invalidOfficialEvidence(): iterable
    {
        $asset = ['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 51];
        $validTiers = [
            ['lowerBound' => '0', 'maxLeverage' => 10],
            ['lowerBound' => '10000', 'maxLeverage' => 5],
        ];
        $meta = static fn (array $tables): array => ['universe' => [$asset], 'marginTables' => $tables];
        $active = self::activeRow();

        yield 'duplicate table id' => [$meta([[51, ['marginTiers' => $validTiers]], [51, ['marginTiers' => $validTiers]]]), $active];
        yield 'single tier id differs from universe maximum' => [[
            'universe' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 3]],
            'marginTables' => [],
        ], $active];
        yield 'leverage above official maximum' => [[
            'universe' => [['name' => 'BTC', 'maxLeverage' => 51, 'marginTableId' => 51]],
            'marginTables' => [[51, ['marginTiers' => [
                ['lowerBound' => '0', 'maxLeverage' => 51],
            ]]]],
        ], $active];
        yield 'first bound nonzero' => [$meta([[51, ['marginTiers' => [
            ['lowerBound' => '1', 'maxLeverage' => 10],
        ]]]]), $active];
        yield 'equal bounds' => [$meta([[51, ['marginTiers' => [
            ['lowerBound' => '0', 'maxLeverage' => 10],
            ['lowerBound' => '0', 'maxLeverage' => 5],
        ]]]]), $active];
        yield 'leverage not strictly decreasing' => [$meta([[51, ['marginTiers' => [
            ['lowerBound' => '0', 'maxLeverage' => 10],
            ['lowerBound' => '10000', 'maxLeverage' => 10],
        ]]]]), $active];
        yield 'first tier differs from universe maximum' => [$meta([[51, ['marginTiers' => [
            ['lowerBound' => '0', 'maxLeverage' => 9],
        ]]]]), $active];
        yield 'tiers are not a canonical list' => [$meta([[51, ['marginTiers' => [
            'first' => ['lowerBound' => '0', 'maxLeverage' => 10],
        ]]]]), $active];
        yield 'too many tiers' => [$meta([[51, ['marginTiers' => array_map(
            static fn (int $i): array => ['lowerBound' => (string) $i, 'maxLeverage' => 40 - $i],
            range(0, 32),
        )]]]), $active];
        yield 'four tiers exceed official limit' => [$meta([[51, ['marginTiers' => [
            ['lowerBound' => '0', 'maxLeverage' => 10],
            ['lowerBound' => '10000', 'maxLeverage' => 8],
            ['lowerBound' => '20000', 'maxLeverage' => 5],
            ['lowerBound' => '30000', 'maxLeverage' => 3],
        ]]]]), $active];
        yield 'missing observed user' => [$meta([[51, ['marginTiers' => $validTiers]]]), array_diff_key($active, ['user' => true])];
        yield 'wrong observed user' => [$meta([[51, ['marginTiers' => $validTiers]]]), self::activeRow(user: '0x2222222222222222222222222222222222222222')];
        yield 'missing observed coin' => [$meta([[51, ['marginTiers' => $validTiers]]]), array_diff_key($active, ['coin' => true])];
        yield 'wrong observed coin' => [$meta([[51, ['marginTiers' => $validTiers]]]), self::activeRow(coin: 'ETH')];
    }

    private function mapper(): HyperliquidMarginSafetyEvidenceMapper
    {
        return new HyperliquidMarginSafetyEvidenceMapper();
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<mixed> $tables
     * @return array<string,mixed>
     */
    private function meta(array $asset, array $tables = []): array
    {
        return ['universe' => [$asset], 'marginTables' => $tables];
    }

    /** @return array<string,mixed> */
    private function active(string $coin, string $user, string $type, int $leverage): array
    {
        return self::activeRow($coin, $user, $type, $leverage);
    }

    /** @return array<string,mixed> */
    private static function activeRow(
        string $coin = 'BTC',
        string $user = self::ACCOUNT,
        string $type = 'isolated',
        int $leverage = 5,
    ): array {
        return ['user' => $user, 'coin' => $coin, 'leverage' => ['type' => $type, 'value' => $leverage]];
    }
}
