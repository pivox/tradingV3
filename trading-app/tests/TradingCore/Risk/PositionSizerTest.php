<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Risk;

use App\TradingCore\Risk\Dto\RiskCalculationRequest;
use App\TradingCore\Risk\Dto\RiskCalculationResult;
use App\TradingCore\Risk\Enum\RiskSource;
use App\TradingCore\Risk\Service\PositionSizer;
use App\TradingCore\Risk\Service\RiskConfigInterpreter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PositionSizer::class)]
#[CoversClass(RiskConfigInterpreter::class)]
#[CoversClass(RiskCalculationRequest::class)]
#[CoversClass(RiskCalculationResult::class)]
#[CoversClass(RiskSource::class)]
final class PositionSizerTest extends TestCase
{
    public function testCalculatesPositionFromLegacyRuntimeRiskPctAndStopPct(): void
    {
        $sizer = new PositionSizer();

        $result = $sizer->calculate(new RiskCalculationRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            equity: null,
            availableBalance: 100.0,
            entryPrice: 100.0,
            stopPrice: null,
            stopPct: 0.02,
            fixedRiskPct: null,
            riskPctPercentLegacy: 0.004,
            initialMarginUsdt: 50.0,
        ));

        self::assertSame(0.004, $result->effectiveRiskPct);
        self::assertSame(RiskSource::RiskPctPercentLegacy, $result->riskSource);
        self::assertSame(0.2, $result->riskUsdt);
        self::assertSame(0.02, $result->stopPct);
        self::assertSame(10.0, $result->positionNotional);
        self::assertSame(0.1, $result->quantity);
    }

    public function testRejectsZeroOrNegativeStopPct(): void
    {
        $sizer = new PositionSizer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stopPct must be positive');

        $sizer->calculate(new RiskCalculationRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            equity: null,
            availableBalance: 100.0,
            entryPrice: 100.0,
            stopPrice: null,
            stopPct: 0.0,
            fixedRiskPct: null,
            riskPctPercentLegacy: 0.01,
            initialMarginUsdt: 50.0,
        ));
    }

    public function testWarnsWhenCanonicalAndLegacyRiskFieldsAreBothPresent(): void
    {
        $sizer = new PositionSizer();

        $result = $sizer->calculate(new RiskCalculationRequest(
            symbol: 'SOLUSDT',
            instrument: null,
            profile: 'regular',
            exchange: 'bitmart',
            marketType: 'futures',
            equity: null,
            availableBalance: 200.0,
            entryPrice: 20.0,
            stopPrice: null,
            stopPct: 0.05,
            fixedRiskPct: 0.02,
            riskPctPercentLegacy: 0.01,
            initialMarginUsdt: 100.0,
        ));

        self::assertSame(0.02, $result->effectiveRiskPct);
        self::assertSame(RiskSource::FixedRiskPct, $result->riskSource);
        self::assertContains('Both fixedRiskPct and legacy riskPctPercent are present; fixedRiskPct is the canonical module source.', $result->warnings);
    }

    public function testRejectsPositionWhenAvailableBalanceIsExplicitlyZero(): void
    {
        $sizer = new PositionSizer();

        // availableBalance=0.0 explicitly means "no budget" and must be honored,
        // not silently ignored in favour of initialMarginUsdt.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('capital base must be positive');

        $sizer->calculate(new RiskCalculationRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            equity: null,
            availableBalance: 0.0,
            entryPrice: 100.0,
            stopPrice: null,
            stopPct: 0.02,
            fixedRiskPct: null,
            riskPctPercentLegacy: 0.004,
            initialMarginUsdt: 50.0,
        ));
    }

    public function testFallbackCapitalPathCappsToAvailableBalance(): void
    {
        $sizer = new PositionSizer();

        // syntheticMargin = 1000 * 0.004 = 4.0, availableBalance = 1.0
        // capitalBase = min(4.0, 1.0) = 1.0 → riskUsdt = 1.0 * 0.004 = 0.004
        $result = $sizer->calculate(new RiskCalculationRequest(
            symbol: 'ETHUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            equity: null,
            availableBalance: 1.0,
            entryPrice: 100.0,
            stopPrice: null,
            stopPct: 0.02,
            fixedRiskPct: null,
            riskPctPercentLegacy: 0.004,
            initialMarginUsdt: null,
            fallbackAccountBalance: 1000.0,
        ));

        self::assertSame(0.004, $result->riskUsdt);
        self::assertSame(0.2, $result->positionNotional);
    }

    public function testSizesFallbackCapitalUsingLegacyDoubleRiskPctPattern(): void
    {
        $sizer = new PositionSizer();

        // fallback_account_balance = 1000, riskPct = 0.004, initialMarginUsdt = null
        // resolveCapitalBase returns 1000 * 0.004 = 4.0 (mirrors TradeEntryRequestBuilder)
        // riskUsdt = 4.0 * 0.004 = 0.016 (riskPct applied twice — matches legacy behavior)
        $result = $sizer->calculate(new RiskCalculationRequest(
            symbol: 'ETHUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            equity: null,
            availableBalance: null,
            entryPrice: 100.0,
            stopPrice: null,
            stopPct: 0.02,
            fixedRiskPct: null,
            riskPctPercentLegacy: 0.004,
            initialMarginUsdt: null,
            fallbackAccountBalance: 1000.0,
        ));

        self::assertSame(RiskSource::RiskPctPercentLegacy, $result->riskSource);
        self::assertSame(0.016, $result->riskUsdt);
        self::assertSame(0.8, $result->positionNotional);
        self::assertSame(0.008, $result->quantity);
    }

    public function testPreservesLegacyRuntimeRiskSourceWhenRequestComesFromInterpreter(): void
    {
        $interpreter = new RiskConfigInterpreter();
        $sizer = new PositionSizer();

        $request = $interpreter->fromLegacyTradeEntryConfig(
            symbol: 'BTCUSDT',
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            entryPrice: 100.0,
            stopPct: 0.02,
            defaults: [
                'risk_pct_percent' => 0.4,
                'initial_margin_usdt' => 50.0,
            ],
            risk: [
                'fixed_risk_pct' => 5.0,
            ],
            availableBalance: 100.0,
        );

        $result = $sizer->calculate($request);

        self::assertSame(0.004, $result->effectiveRiskPct);
        self::assertSame(RiskSource::RiskPctPercentLegacy, $result->riskSource);
        self::assertContains('Preserving legacy runtime risk source from defaults.risk_pct_percent; risk.fixed_risk_pct is carried for audit only.', $result->warnings);
    }
}
