<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\Research\ResearchVariant;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchVariant::class)]
final class ResearchVariantTest extends TestCase
{
    public function testCatalogHasBaselineAndTwelveDistinctClosedDiffs(): void
    {
        [$policy, $setupHash, $catalogHash] = self::baseline();
        $variants = ResearchVariant::catalog($policy, $setupHash, $catalogHash);
        self::assertCount(13, $variants);
        self::assertSame([], $variants[0]->diff);
        self::assertCount(13, array_unique(array_map(static fn (ResearchVariant $v): string => $v->hash, $variants)));
        foreach ($variants as $variant) {
            self::assertSame($variant->hash, ResearchVariant::select($variant->id, $variant->diff, $policy, $setupHash, $catalogHash)->hash);
        }
    }

    public function testAllCatalogDiffsSurviveJsonRoundTripAndReorderedKeys(): void
    {
        [$policy, $setupHash, $catalogHash] = self::baseline();
        foreach (ResearchVariant::catalog($policy, $setupHash, $catalogHash) as $variant) {
            $wireDiff = json_decode(json_encode($variant->diff, JSON_THROW_ON_ERROR), true, 128, JSON_THROW_ON_ERROR);
            self::assertSame($variant->hash, ResearchVariant::select(
                $variant->id, $wireDiff, $policy, $setupHash, $catalogHash,
            )->hash, $variant->id);
            self::assertSame($variant->diff, ResearchVariant::select(
                $variant->id, array_reverse($wireDiff, true), $policy, $setupHash, $catalogHash,
            )->diff, $variant->id);
        }
    }

    public function testRejectsUnlistedAndNoopDiffs(): void
    {
        [$policy, $setupHash, $catalogHash] = self::baseline();
        foreach ([['baseline', ['zone_atr_multiplier' => 0.3]], ['width_050', ['zone_atr_multiplier' => 0.3]], ['unknown', []], ['width_050', ['risk_rate' => 0.9]]] as [$id, $diff]) {
            try {
                ResearchVariant::select($id, $diff, $policy, $setupHash, $catalogHash);
                self::fail('Expected research_variant_invalid for ' . $id);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('research_variant_invalid', $exception->getMessage());
            }
        }
    }

    public function testOnlyDeclaredGeometryFieldsChange(): void
    {
        [$policy, $setupHash, $catalogHash] = self::baseline();
        $variant = ResearchVariant::select('ema20_5m_width_050',
            ['anchor_source' => 'ema_20', 'zone_atr_multiplier' => 0.50], $policy, $setupHash, $catalogHash);
        self::assertSame('ema_20', $variant->entryZone->anchorSource);
        self::assertSame('5m', $variant->entryZone->anchorTimeframe);
        self::assertSame(0.50, $variant->entryZone->atrMultiplier);
        self::assertSame($policy->entryZone->ttlSeconds, $variant->entryZone->ttlSeconds);
        self::assertSame($policy->stop->atrMultiplier, $variant->stop->atrMultiplier);
        self::assertSame($policy->targets[0]->riskMultiple, $variant->targets[0]->riskMultiple);
    }

    /** @return array{CanonicalExecutionPolicy, string, string} */
    private static function baseline(): array
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'local', 'long', ShadowExecutionCapability::Backtest,
        ));
        $setupHash = (string) $snapshot->payload()['setup']['contract_hash'];
        return [CanonicalExecutionPolicy::fromSnapshot($snapshot), $setupHash, (string) $snapshot->conditionCatalogHash];
    }
}
