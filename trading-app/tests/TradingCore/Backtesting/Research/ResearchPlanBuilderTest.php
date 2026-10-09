<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Research\ResearchCostAssumptions;
use App\TradingCore\Backtesting\Research\ResearchInstrumentAssumptions;
use App\TradingCore\Backtesting\Research\ResearchPlanBuilder;
use App\TradingCore\Backtesting\Research\ResearchVariant;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\NetRCostMath;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResearchPlanBuilder::class)]
final class ResearchPlanBuilderTest extends TestCase
{
    public function testBaselinePlanUsesClosedCandidateAndGenuineRiskCap(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $plan = $builder->build($signal, 2, $portfolio);

        self::assertSame('research-plan.v1', $plan['schema_version']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $plan['research_code_hash']);
        self::assertSame(true, $plan['research_only']);
        self::assertSame('BTCUSDT', $plan['symbol']);
        self::assertSame(100.2, $plan['entry_price']);
        self::assertSame('last_closed_15m_close', $plan['candidate_price_source']);
        self::assertSame(98.7, $plan['stop_price']);
        self::assertSame(103.2, $plan['targets'][0]['price']);
        self::assertLessThanOrEqual(250.0, $plan['position_notional_quote']);
        self::assertSame(1, $plan['final_leverage']);
        self::assertSame(90, $plan['entry_ttl_seconds']);
        self::assertSame(120, $plan['cancel_after_seconds']);
        self::assertSame(true, $plan['holding_deadline_exclusive']);
        self::assertSame('synthetic_fake_local_from_research_manifest', $plan['instrument_binding']);
        self::assertSame('hypothetical_ohlcv_not_live_order_book', $plan['cost_status']);
        self::assertSame(0.005, $plan['instrument_math']['mmr_proxy_rate']);
        self::assertSame(0.001, $plan['instrument_math']['liquidation_fee_rate']);
        self::assertSame(0.0002, $plan['cost_model']['entry_fee_rate']);
        self::assertSame(0.0005, $plan['cost_model']['stop_fee_rate']);
        self::assertSame(0.0001, $plan['cost_model']['funding_provision_rate']);
        self::assertGreaterThanOrEqual(1.3, $plan['targets'][0]['net_r']);
        $model = $plan['cost_model'];
        $risk = NetRCostMath::risk('long', $plan['entry_price'], $plan['stop_price'], $plan['quantity'],
            $plan['instrument_math']['contract_size'], $model['entry_fee_rate'], $model['stop_fee_rate'],
            $model['entry_spread_rate'], $model['stop_spread_rate'],
            $model['entry_slippage_rate'], $model['stop_slippage_rate'],
            $model['funding_provision_rate'], $model['funding_intervals_provisioned']);
        self::assertSame($risk['net_risk']->toFloat(), $plan['risk_components_quote']['total_stop_loss']);
        $target = NetRCostMath::target($plan['entry_price'], $plan['targets'][0]['price'], $plan['quantity'],
            $plan['instrument_math']['contract_size'], $model['target_fee_rate'],
            $model['target_spread_rate'], $model['target_slippage_rate'], $risk);
        self::assertSame($target['net_r']->toFloat(), $plan['targets'][0]['net_r']);
    }

    public function testTamperedSignalHashFailsClosed(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $signal['contexts']['15m']['close'] = 200.0;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_signal_hash_invalid');
        $builder->build($signal, 2, $portfolio);
    }

    public function testFutureContextFailsClosed(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $signal['contexts']['5m']['available_ms'] = $signal['evaluated_ms'] + 300000;
        $signal['contexts']['5m']['open_ms'] = $signal['evaluated_ms'];
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($signal, ['result_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_signal_context_future');
        $builder->build($signal, 2, $portfolio);
    }

    public function testDailyCapRejectsSharedPortfolioCandidate(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $portfolio['reserved_risk_quote'] = 29.0;
        $portfolio['portfolio_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($portfolio, ['portfolio_hash' => true]));
        $rejection = $builder->build($signal, 2, $portfolio);
        self::assertSame('research-plan-rejection.v1', $rejection['schema_version']);
        self::assertSame('research_portfolio_daily_loss_exceeded', $rejection['reason_code']);
    }

    public function testPortfolioDependentRejectionsBindEachValidatedPortfolioHash(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $rejections = [];
        foreach ([28.0, 29.0] as $reservedRisk) {
            $portfolio['reserved_risk_quote'] = $reservedRisk;
            $portfolio['portfolio_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($portfolio, ['portfolio_hash' => true]));
            $rejection = $builder->build($signal, 2, $portfolio);
            self::assertSame('research_portfolio_daily_loss_exceeded', $rejection['reason_code']);
            self::assertSame($portfolio['portfolio_hash'], $rejection['portfolio_hash'] ?? null);
            $rejections[] = $rejection;
        }
        self::assertNotSame($rejections[0]['portfolio_hash'], $rejections[1]['portfolio_hash']);
    }

    public function testB1TraceDigestFormsRemainEquivalent(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $signal['trace']['setup_hash'] = 'sha256:' . $signal['baseline']['setup_hash'];
        $signal['trace']['catalog_hash'] = substr($signal['baseline']['condition_catalog_hash'], 7);
        $signal['trace_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($signal['trace']);
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($signal, ['result_hash' => true]));
        self::assertSame('research-plan.v1', $builder->build($signal, 2, $portfolio)['schema_version']);
    }

    public function testRejectionIdentityDistinguishesSelectedCostProfiles(): void
    {
        $rejections = [];
        foreach (['baseline', 'adverse'] as $profile) {
            [$builder, $signal, $portfolio] = self::fixture($profile);
            $portfolio['active_signal_hashes'] = [$signal['result_hash']];
            $portfolio['portfolio_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($portfolio, ['portfolio_hash' => true]));
            $rejection = $builder->build($signal, 2, $portfolio);
            self::assertSame('research_portfolio_signal_duplicate', $rejection['reason_code']);
            self::assertSame($profile, $rejection['cost_profile'] ?? null);
            $rejections[] = $rejection;
        }
        self::assertSame($rejections[0]['cost_assumptions_hash'], $rejections[1]['cost_assumptions_hash']);
        self::assertNotSame($rejections[0]['cost_profile'], $rejections[1]['cost_profile']);
    }

    public function testRejectsWrongSourceProvenanceDespiteRehashedPayload(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $signal['source']['dataset_id'] = 'other-dataset';
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($signal, ['result_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_signal_binding_invalid');
        $builder->build($signal, 2, $portfolio);
    }

    public function testRejectsNegativeAvailablePortfolioBalance(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $portfolio['available_balance_quote'] = -1.0;
        $portfolio['portfolio_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($portfolio, ['portfolio_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_portfolio_view_invalid');
        $builder->build($signal, 2, $portfolio);
    }

    public function testRejectsUnlistedContextTimeframe(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $signal['contexts']['30m'] = $signal['contexts']['15m'];
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($signal, ['result_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_signal_context_invalid');
        $builder->build($signal, 2, $portfolio);
    }

    public function testRejectsExtraExecutionAuthorityField(): void
    {
        [$builder, $signal, $portfolio] = self::fixture();
        $signal['execution']['mainnet_write_enabled'] = true;
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash(array_diff_key($signal, ['result_hash' => true]));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_signal_binding_invalid');
        $builder->build($signal, 2, $portfolio);
    }

    /** @return array{ResearchPlanBuilder, array<string, mixed>, array<string, mixed>} */
    public static function fixture(string $costProfile = 'baseline'): array
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'local', 'long', ShadowExecutionCapability::Backtest,
        ));
        $policy = CanonicalExecutionPolicy::fromSnapshot($snapshot);
        $portfolioPolicy = CanonicalPortfolioPolicy::fromSnapshot($snapshot);
        $setupHash = (string) $snapshot->payload()['setup']['contract_hash'];
        $variant = ResearchVariant::select('baseline', [], $policy, $setupHash, (string) $snapshot->conditionCatalogHash);
        $instrument = ResearchInstrumentAssumptions::fromArray(ResearchAssumptionsTest::instruments());
        $costs = ResearchCostAssumptions::fromArray(ResearchAssumptionsTest::costs());
        $baseline = ['config_hash' => $snapshot->configHash, 'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'snapshot_hash' => $snapshot->toArray()['snapshot_hash'], 'setup_hash' => $setupHash,
            'file_hashes' => [], 'mode_risk' => $snapshot->payload()['mode']['risk']];
        $eval = 1672660800000; // 2023-01-02 12:00 UTC
        $source = ['dataset_id' => 'reference-BTCUSDT', 'dataset_sha256' => str_repeat('a', 64),
            'source_venue' => 'binance_usdm', 'source_network' => 'mainnet', 'market_type' => 'perpetual'];
        $run = ['symbol' => 'BTCUSDT', 'source' => $source, 'signal_session_id' => 'reference-BTCUSDT',
            'signal_run_id' => 'run-reference-BTCUSDT', 'signal_output_sha256' => str_repeat('b', 64),
            'score_start_ms' => $eval - 900000, 'end_ms' => $eval + 900000];
        $trace = ['schema_version' => 'canonical-setup-rule-runtime.v1', 'mode_id' => 'day_trading', 'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long', 'setup_version' => '1.1.0',
            'setup_hash' => $setupHash, 'catalog_hash' => $snapshot->conditionCatalogHash,
            'config_hash' => $snapshot->configHash, 'side' => 'long', 'execution_timeframe' => '15m',
            'mandatory_confirmations' => ['5m', '1m'], 'evaluated_at' => '2023-01-02T12:00:00.000000Z'];
        $contexts = [];
        foreach (['1m' => 60000, '5m' => 300000, '15m' => 900000, '1h' => 3600000, '4h' => 14400000] as $tf => $step) {
            $contexts[$tf] = ['open_ms' => $eval - $step, 'available_ms' => $eval,
                'research_window_hash' => 'sha256:' . str_repeat('c', 64)];
        }
        $contexts['5m']['vwap'] = 100.0;
        $contexts['5m']['atr'] = 1.0;
        $contexts['15m']['close'] = 100.2;
        $signal = ['schema_version' => 'research-signal-result.v1', 'session_id' => $run['signal_session_id'],
            'source' => $source, 'execution' => $snapshot->request->toArray(), 'baseline' => $baseline,
            'symbol' => 'BTCUSDT', 'evaluated_ms' => $eval, 'evaluated_at' => '2023-01-02T12:00:00.000000Z',
            'passed' => true, 'reason_code' => 'setup_rules_passed',
            'numeric_context_hash' => 'sha256:' . str_repeat('d', 64), 'contexts' => $contexts,
            'trace_hash' => CanonicalBacktestRuleEvaluator::canonicalHash($trace), 'verdicts' => [], 'trace' => $trace];
        $signal['result_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($signal);
        $portfolio = ['schema_version' => 'research-portfolio-view.v1', 'as_of_ms' => $eval,
            'equity_quote' => 100000.0, 'available_balance_quote' => 100000.0,
            'realized_net_pnl_quote' => 0.0, 'unrealized_net_pnl_quote' => 0.0,
            'open_positions' => 0, 'pending_entries' => 0, 'open_notional_quote' => 0.0,
            'pending_notional_quote' => 0.0, 'reserved_risk_quote' => 0.0,
            'active_signal_hashes' => []];
        $portfolio['portfolio_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($portfolio);
        return [new ResearchPlanBuilder($policy, $portfolioPolicy, $variant, $instrument, $costs, $costProfile, $baseline, $run), $signal, $portfolio];
    }
}
