<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical\Portfolio;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicyCompiler;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyFixture;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use Symfony\Component\Clock\MockClock;

final class CanonicalPortfolioFixture
{
    /** @param array<string, mixed> $riskOverrides */
    public static function snapshot(array $riskOverrides = []): EffectiveTradingConfigSnapshot
    {
        $payload = CanonicalExecutionPolicyFixture::payload();
        $payload['mode']['risk'] = array_replace($payload['mode']['risk'], self::portfolioRisk(), $riskOverrides);
        $catalogHash = 'sha256:' . str_repeat('b', 64);

        return new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest(
                'day_trading',
                '1.0.0',
                'day_trading.trend_continuation.long',
                '1.0.0',
                'fake',
                'test',
                'long',
            ),
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $catalogHash),
            $catalogHash,
            [],
            [],
        );
    }

    public static function policy(?EffectiveTradingConfigSnapshot $snapshot = null): CanonicalPortfolioPolicy
    {
        return (new CanonicalPortfolioPolicyCompiler())->compile($snapshot ?? self::snapshot());
    }

    public static function plan(?EffectiveTradingConfigSnapshot $snapshot = null): CanonicalOrderPlan
    {
        $snapshot ??= self::snapshot();
        $executionPolicy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
        $components = CanonicalOrderPlanPipelineFixture::accepted(executionPolicy: $executionPolicy);
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
    }

    /** @return array<string, mixed> */
    private static function portfolioRisk(): array
    {
        $decision = static fn (mixed $value, string $unit): array => [
            'state' => 'defined',
            'value' => $value,
            'unit' => $unit,
            'source' => 'test-fixture',
            'justification' => 'canonical Lot C test fixture',
        ];

        return [
            'daily_loss_cap' => $decision([
                'percent_equity' => 6.0,
                'absolute_quote' => 30.0,
                'quote_currency' => 'USDT',
                'day_timezone' => 'UTC',
                'day_boundary_local' => '00:00:00',
                'include_unrealized_loss' => true,
            ], 'compound_percent_equity_and_quote_per_day'),
            'max_concurrent_positions' => $decision([
                'limit' => 4,
                'include_pending_entries' => true,
            ], 'positions'),
            'mode_exposure_cap' => $decision(100.0, 'percent_equity_notional'),
        ];
    }
}
