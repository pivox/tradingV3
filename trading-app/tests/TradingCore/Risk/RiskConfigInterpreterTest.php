<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Risk;

use App\TradingCore\Risk\Dto\RiskCalculationRequest;
use App\TradingCore\Risk\Enum\RiskSource;
use App\TradingCore\Risk\Service\RiskConfigInterpreter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RiskConfigInterpreter::class)]
#[CoversClass(RiskCalculationRequest::class)]
#[CoversClass(RiskSource::class)]
final class RiskConfigInterpreterTest extends TestCase
{
    public function testLegacyRiskPctPercentIsRuntimeSourceWhenPresent(): void
    {
        $interpreter = new RiskConfigInterpreter();

        $request = $interpreter->fromLegacyTradeEntryConfig(
            symbol: 'BTCUSDT',
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            entryPrice: 100.0,
            stopPct: 0.01,
            defaults: [
                'risk_pct_percent' => 0.4,
                'initial_margin_usdt' => 50.0,
                'fallback_account_balance' => 0.0,
            ],
            risk: [
                'fixed_risk_pct' => 5.0,
            ],
        );

        self::assertSame(0.004, $request->riskPctPercentLegacy);
        self::assertSame(0.05, $request->fixedRiskPct);
        self::assertSame(RiskSource::RiskPctPercentLegacy, $request->metadata['legacy_runtime_risk_source']);
        self::assertContains('risk.fixed_risk_pct is configured but legacy TradeEntry runtime derives TradeEntryRequest::riskPct from defaults.risk_pct_percent.', $request->metadata['warnings']);
    }

    public function testFixedRiskPctIsFallbackWhenLegacyRiskPctPercentIsMissing(): void
    {
        $interpreter = new RiskConfigInterpreter();

        $request = $interpreter->fromLegacyTradeEntryConfig(
            symbol: 'ETHUSDT',
            profile: 'regular',
            exchange: 'bitmart',
            marketType: 'futures',
            entryPrice: 100.0,
            stopPct: 0.02,
            defaults: [
                'initial_margin_usdt' => 40.0,
            ],
            risk: [
                'fixed_risk_pct' => 2.5,
            ],
        );

        self::assertSame(0.025, $request->fixedRiskPct);
        self::assertNull($request->riskPctPercentLegacy);
        self::assertSame(RiskSource::FixedRiskPct, $request->metadata['legacy_runtime_risk_source']);
    }
}
