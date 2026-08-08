<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry;

use App\Config\TradeEntryConfig;
use App\Config\TradeEntryConfigProvider;
use App\Config\TradeEntryModeContext;
use App\TradeEntry\Policy\DailyLossGuard;
use App\TradeEntry\Service\Leverage\DynamicLeverageService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use App\Trading\Lineage\LineageContextException;

#[CoversClass(DynamicLeverageService::class)]
#[CoversClass(DailyLossGuard::class)]
final class CanonicalRiskCapPropagationTest extends TestCase
{
    public function testDynamicLeverageEnforcesCanonicalCap(): void
    {
        /** @var TradeEntryConfigProvider $provider */
        $provider = (new \ReflectionClass(TradeEntryConfigProvider::class))->newInstanceWithoutConstructor();
        /** @var TradeEntryModeContext $modeContext */
        $modeContext = (new \ReflectionClass(TradeEntryModeContext::class))->newInstanceWithoutConstructor();
        $config = new TradeEntryConfig(config: [
            'defaults' => ['risk_pct_percent' => 10.0, 'k_dynamic' => 100.0],
            'leverage' => ['canonical_cap' => 3.0],
        ]);
        $service = new DynamicLeverageService($provider, $modeContext, $config, new NullLogger());

        self::assertSame(3, $service->computeLeverage(
            'BTCUSDT', 100.0, 1.0, 1, 50.0, 100.0, 1, 50,
            stopPct: 0.01, executionTf: '1m', config: $config,
        ));
    }

    public function testDailyLossPolicyRejectsCompoundCanonicalSemanticsWithoutLegacyResolver(): void
    {
        /** @var DailyLossGuard $guard */
        $guard = (new \ReflectionClass(DailyLossGuard::class))->newInstanceWithoutConstructor();
        $config = new TradeEntryConfig(config: [
            'risk' => ['daily_loss_cap' => [
                'percent_equity' => 2.0,
                'absolute_quote' => 20.0,
                'quote_currency' => 'USDT',
            ]],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('canonical_daily_loss_policy_pending_304');
        $guard->checkAndMaybeLock('scalping', $config);
    }

    public function testDynamicLeverageRejectsMissingCanonicalRiskPercentBeforeFormula(): void
    {
        /** @var TradeEntryConfigProvider $provider */
        $provider = (new \ReflectionClass(TradeEntryConfigProvider::class))->newInstanceWithoutConstructor();
        /** @var TradeEntryModeContext $modeContext */
        $modeContext = (new \ReflectionClass(TradeEntryModeContext::class))->newInstanceWithoutConstructor();
        $config = new TradeEntryConfig(config: ['defaults' => [], 'leverage' => ['canonical_cap' => 3.0]]);
        $service = new DynamicLeverageService($provider, $modeContext, $config, new NullLogger());

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_risk_pct_pending_304');
        $service->computeLeverage(
            'BTCUSDT', 100.0, 1.0, 1, 0.0, 0.0, 1, 50,
            stopPct: null, executionTf: '1m', config: $config,
        );
    }
}
