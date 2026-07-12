<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Provider\Hyperliquid\Dto\HyperliquidMarginSafetyEvidence;
use App\Provider\Hyperliquid\HyperliquidMarginSafetyEvidenceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidMarginSafetyEvidenceMapper::class)]
#[CoversClass(HyperliquidMarginSafetyEvidence::class)]
final class HyperliquidMarginSafetyEvidenceMapperTest extends TestCase
{
    private const ACCOUNT = '0x1111111111111111111111111111111111111111';

    public function testMapsOfficialSingleTierRuleFromMarginTableIdBelowFifty(): void
    {
        $evidence = $this->mapper()->map(
            meta: $this->meta(['name' => 'SOL', 'maxLeverage' => 10, 'marginTableId' => 10]),
            activeAssetData: $this->active('isolated', 5),
            symbol: 'SOLUSDT',
            notional: '100',
            requestedLeverage: 5,
            accountAddress: self::ACCOUNT,
            observedAt: new \DateTimeImmutable('2026-07-12T12:00:00Z'),
        );

        self::assertSame(10, $evidence->marginTableId);
        self::assertSame('0', $evidence->tierLowerBound);
        self::assertSame(10, $evidence->tierMaxLeverage);
        self::assertSame('0.05', $evidence->maintenanceMarginRate);
        self::assertSame('0', $evidence->maintenanceMarginDeduction);
        self::assertSame('isolated', $evidence->accountMarginMode);
        self::assertSame(5, $evidence->accountLeverage);
    }

    public function testSelectsTierAtThresholdAndDerivesContinuousDeduction(): void
    {
        $evidence = $this->mapper()->map(
            meta: $this->meta(
                ['name' => 'ATOM', 'maxLeverage' => 10, 'marginTableId' => 52],
                [[52, ['marginTiers' => [
                    ['lowerBound' => '0.0', 'maxLeverage' => 10],
                    ['lowerBound' => '20000.0', 'maxLeverage' => 5],
                ]]]],
            ),
            activeAssetData: $this->active('isolated', 5),
            symbol: 'ATOMUSDT',
            notional: '20000',
            requestedLeverage: 5,
            accountAddress: self::ACCOUNT,
            observedAt: new \DateTimeImmutable('2026-07-12T12:00:00Z'),
        );

        self::assertSame('20000', $evidence->tierLowerBound);
        self::assertSame('20000', $evidence->notional);
        self::assertSame(5, $evidence->tierMaxLeverage);
        self::assertSame('0.1', $evidence->maintenanceMarginRate);
        self::assertSame('1000', $evidence->maintenanceMarginDeduction);
    }

    #[DataProvider('invalidEvidence')]
    public function testRejectsMissingMalformedWrongTierLeverageOrAccountEvidence(
        array $asset,
        array $tables,
        array $active,
        string $notional,
        int $leverage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        $this->mapper()->map(
            meta: $this->meta($asset, $tables),
            activeAssetData: $active,
            symbol: 'BTCUSDT',
            notional: $notional,
            requestedLeverage: $leverage,
            accountAddress: self::ACCOUNT,
            observedAt: new \DateTimeImmutable('2026-07-12T12:00:00Z'),
        );
    }

    /** @return iterable<string,array{array<string,mixed>,array<mixed>,array<string,mixed>,string,int}> */
    public static function invalidEvidence(): iterable
    {
        $tiered = [[51, ['marginTiers' => [
            ['lowerBound' => '0.0', 'maxLeverage' => 10],
            ['lowerBound' => '10000.0', 'maxLeverage' => 5],
        ]]]];
        yield 'missing margin table id' => [['name' => 'BTC', 'maxLeverage' => 10], $tiered, self::activeRow(), '100', 5];
        yield 'missing tiered table' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 51], [], self::activeRow(), '100', 5];
        yield 'unordered tiers' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 51], [[51, ['marginTiers' => [
            ['lowerBound' => '10000', 'maxLeverage' => 5],
            ['lowerBound' => '0', 'maxLeverage' => 10],
        ]]]], self::activeRow(), '100', 5];
        yield 'leverage exceeds selected tier' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 51], $tiered, self::activeRow(6), '10000', 6];
        yield 'account leverage mismatch' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 51], $tiered, self::activeRow(3), '100', 5];
        yield 'cross account evidence' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 51], $tiered, self::activeRow(5, 'cross'), '100', 5];
        yield 'missing account evidence' => [['name' => 'BTC', 'maxLeverage' => 10, 'marginTableId' => 51], $tiered, [], '100', 5];
    }

    private function mapper(): HyperliquidMarginSafetyEvidenceMapper
    {
        return new HyperliquidMarginSafetyEvidenceMapper();
    }

    /** @param array<string,mixed> $asset @param array<mixed> $tables @return array<string,mixed> */
    private function meta(array $asset, array $tables = []): array
    {
        return ['universe' => [$asset], 'marginTables' => $tables];
    }

    /** @return array<string,mixed> */
    private function active(string $type, int $leverage): array
    {
        return self::activeRow($leverage, $type);
    }

    /** @return array<string,mixed> */
    private static function activeRow(int $leverage = 5, string $type = 'isolated'): array
    {
        return ['leverage' => ['type' => $type, 'value' => $leverage]];
    }
}
