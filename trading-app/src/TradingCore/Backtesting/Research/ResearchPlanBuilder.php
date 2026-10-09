<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalHoldingBoundary;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\EntryZonePriceMath;
use App\TradingCore\OrderPlan\Canonical\NetRCostMath;
use App\TradingCore\OrderPlan\Canonical\ProtectionPriceMath;
use App\TradingCore\Risk\Canonical\CanonicalCostSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalInstrumentSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;
use App\TradingCore\Risk\Canonical\CanonicalRiskException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioDecimal;
use Brick\Math\BigDecimal;

/** Builds hypothetical plans from B1 envelopes; no canonical plan or order leaves this boundary. */
final readonly class ResearchPlanBuilder
{
    private string $codeHash;

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $sourceRun
     */
    public function __construct(
        private CanonicalExecutionPolicy $policy,
        private CanonicalPortfolioPolicy $portfolioPolicy,
        private ResearchVariant $variant,
        private ResearchInstrumentAssumptions $instruments,
        private ResearchCostAssumptions $costs,
        private string $costProfile,
        private array $baseline,
        private array $sourceRun,
    ) {
        if ($policy->riskPolicy->riskRate !== 0.05
            || $policy->riskPolicy->environmentMaxNotional !== 250.0
            || $policy->riskPolicy->modeLeverageCap !== 2.0
            || $policy->riskPolicy->exchangeMinNotional !== 5.0
            || $policy->riskPolicy->exchangeMaxNotional !== 1000.0
            || $portfolioPolicy->dailyLossRate !== 0.06
            || $portfolioPolicy->dailyLossAbsoluteQuote !== 30.0
            || $portfolioPolicy->maxConcurrentPositions !== 4
            || $portfolioPolicy->modeExposureRate !== 1.0
            || !$portfolioPolicy->includePendingEntries || !$portfolioPolicy->includeUnrealizedLoss
            || $policy->orderPolicy?->type !== 'limit' || $policy->orderPolicy->marketFallback
            || $policy->orderPolicy->ttlSeconds !== 90 || $policy->orderPolicy->cancelAfterSeconds !== 120
            || $policy->holdingWindowSeconds !== 28800 || $policy->minimumNetR !== 1.3
            || $policy->riskPolicy->makerFeeRate !== 0.0002 || $policy->riskPolicy->takerFeeRate !== 0.0005
        ) {
            throw new \InvalidArgumentException('research_baseline_limits_mismatch');
        }
        $costs->profile($costProfile);
        $this->codeHash = ResearchCodeIdentity::current();
    }

    /**
     * @param array<string, mixed> $signal B1 result from a separately verified run artifact.
     * @param array<string, mixed> $portfolio Shared portfolio view at the evaluation instant.
     * @return array<string, mixed>
     */
    public function build(array $signal, int $signalIndex, array $portfolio): array
    {
        $this->validateSignal($signal, $signalIndex);
        $identity = $this->identity($signal, $signalIndex);
        if ($signal['evaluated_ms'] >= $this->sourceRun['end_ms'] || $signal['evaluated_ms'] < $this->sourceRun['score_start_ms']) {
            return $this->reject($identity, 'research_signal_outside_score_window');
        }
        if ($signal['passed'] !== true) {
            return $this->reject($identity, 'research_signal_rules_not_passed');
        }
        $this->validatePortfolio($portfolio, $signal);
        if (in_array($signal['result_hash'], $portfolio['active_signal_hashes'], true)) {
            return $this->reject($identity, 'research_portfolio_signal_duplicate');
        }

        $symbol = $signal['symbol'];
        $instrument = $this->instruments->forSymbol($symbol);
        $contexts = $signal['contexts'];
        $anchorContext = $contexts[$this->variant->entryZone->anchorTimeframe];
        $atrContext = $contexts[$this->variant->entryZone->atrTimeframe];
        $stopContext = $contexts[$this->variant->stop->timeframe];
        $anchorKey = $this->variant->entryZone->anchorSource;
        foreach ([[$anchorContext, $anchorKey], [$atrContext, 'atr'], [$stopContext, 'atr'], [$contexts['15m'], 'close']] as [$context, $key]) {
            if (!isset($context[$key]) || !is_numeric($context[$key]) || !is_finite((float) $context[$key]) || (float) $context[$key] <= 0.0) {
                return $this->reject($identity, 'research_signal_price_context_missing');
            }
        }
        $candidate = (float) $contexts['15m']['close'];
        try {
            $zone = EntryZonePriceMath::calculate(
                $this->variant->entryZone, 'long', (float) $anchorContext[$anchorKey], (float) $atrContext['atr'],
                $candidate, (float) $instrument['tick_size'],
            );
            $protection = ProtectionPriceMath::calculate(
                $this->variant->stop, $this->variant->targets, 'long', $zone['entry_price'],
                (float) $instrument['tick_size'], (float) $stopContext['atr'],
            );
        } catch (CanonicalOrderPlanException $exception) {
            return $this->reject($identity, $exception->reasonCode);
        }

        $profile = $this->costs->profile($this->costProfile);
        $fundingIntervals = intdiv($this->policy->holdingWindowSeconds - 1, $this->policy->costContract->fundingIntervalSeconds) + 1;
        $riskCosts = new CanonicalCostSnapshot(
            $this->policy->costContract->entryLiquidityRole,
            $this->policy->costContract->stopLiquidityRole,
            $profile['entry_spread_rate'], $profile['stop_spread_rate'],
            $profile['entry_slippage_rate'], $profile['stop_slippage_rate'],
            $profile['funding_provision_rate'], $fundingIntervals,
        );
        // This fake/local instrument is a synthetic arithmetic adapter bound to the research manifest hash.
        // It is not a historical Binance instrument observation and grants no order authority.
        $syntheticInstrument = new CanonicalInstrumentSnapshot(
            'fake', 'local', $symbol, 'perpetual', 'USDT',
            (float) $instrument['contract_size'], (float) $instrument['quantity_step'],
            (float) $instrument['min_quantity'], (float) $instrument['max_quantity'], (float) $instrument['max_quantity'],
            (float) $instrument['leverage_cap'], (float) $instrument['leverage_cap'],
            new \DateTimeImmutable($this->instruments->retrievedAt), $this->instruments->hash,
        );
        try {
            $risk = (new CanonicalRiskEngine())->calculate(new CanonicalRiskCalculationRequest(
                $this->policy->riskPolicy, $symbol, 'perpetual', 'USDT', 'long',
                (float) $portfolio['equity_quote'], (float) $portfolio['available_balance_quote'],
                $zone['entry_price'], $protection['stop_price'],
                (float) $instrument['contract_size'], (float) $instrument['quantity_step'],
                (float) $instrument['min_quantity'], (float) $instrument['max_quantity'], (float) $instrument['max_quantity'],
                (float) $instrument['leverage_cap'], (float) $instrument['leverage_cap'],
                $riskCosts, $syntheticInstrument,
            ));
        } catch (CanonicalRiskException $exception) {
            return $this->reject($identity, $exception->reasonCode);
        }
        if ($risk->positionNotional < max(5.0, (float) $instrument['min_notional'])) {
            return $this->reject($identity, 'research_instrument_notional_below_minimum');
        }

        $riskAmounts = NetRCostMath::risk(
            'long', $zone['entry_price'], $protection['stop_price'], $risk->quantity, (float) $instrument['contract_size'],
            $this->feeRate($this->policy->costContract->entryLiquidityRole),
            $this->feeRate($this->policy->costContract->stopLiquidityRole),
            $profile['entry_spread_rate'], $profile['stop_spread_rate'],
            $profile['entry_slippage_rate'], $profile['stop_slippage_rate'],
            $profile['funding_provision_rate'], $fundingIntervals,
        );
        if ($riskAmounts['gross_risk']->toFloat() !== $risk->grossStopLoss
            || $riskAmounts['entry_fee']->toFloat() !== $risk->entryFee
            || $riskAmounts['stop_fee']->toFloat() !== $risk->stopExitFee
            || $riskAmounts['entry_spread']->toFloat() !== $risk->entrySpreadCost
            || $riskAmounts['stop_spread']->toFloat() !== $risk->stopSpreadCost
            || $riskAmounts['entry_slippage']->toFloat() !== $risk->entrySlippageCost
            || $riskAmounts['stop_slippage']->toFloat() !== $risk->stopSlippageCost
            || $riskAmounts['funding']->toFloat() !== $risk->fundingCost
            || $riskAmounts['net_risk']->toFloat() !== $risk->totalStopLoss
        ) {
            throw new \InvalidArgumentException('research_risk_cost_parity_failed');
        }
        $targets = [];
        foreach ($protection['targets'] as $target) {
            $amounts = NetRCostMath::target(
                $zone['entry_price'], $target->price, $risk->quantity, (float) $instrument['contract_size'],
                $this->feeRate($target->liquidityRole), $profile['target_spread_rate'], $profile['target_slippage_rate'], $riskAmounts,
            );
            if ($amounts['net_r']->isLessThan(BigDecimal::of((string) $this->policy->minimumNetR))) {
                return $this->reject($identity, 'research_minimum_net_r_not_met');
            }
            $targets[] = ['id' => $target->id, 'price' => $target->price, 'gross_reward_quote' => $amounts['gross_reward']->toFloat(),
                'entry_fee_quote' => $risk->entryFee, 'target_fee_quote' => $amounts['target_fee']->toFloat(),
                'entry_spread_quote' => $risk->entrySpreadCost, 'entry_slippage_quote' => $risk->entrySlippageCost,
                'target_spread_quote' => $amounts['target_spread']->toFloat(), 'target_slippage_quote' => $amounts['target_slippage']->toFloat(),
                'funding_provision_quote' => $risk->fundingCost, 'net_reward_quote' => $amounts['net_reward']->toFloat(),
                'net_risk_quote' => $risk->totalStopLoss, 'net_r' => $amounts['net_r']->toFloat()];
        }
        $portfolioRejection = $this->portfolioRejection($portfolio, $risk->totalStopLoss, $risk->positionNotional);
        if ($portfolioRejection !== null) {
            return $this->reject($identity, $portfolioRejection);
        }
        $evaluatedAt = new \DateTimeImmutable($signal['evaluated_at']);
        $holdingDeadline = CanonicalHoldingBoundary::expiresAt($evaluatedAt, $this->policy->holdingWindowSeconds, $this->policy->holdingHorizon);
        if ($holdingDeadline->getTimestamp() * 1000 <= $signal['evaluated_ms'] + 60000) {
            return $this->reject($identity, 'research_holding_window_unavailable');
        }
        $plan = ['schema_version' => 'research-plan.v1', 'research_only' => true, 'execution_authority' => 'none',
            ...$identity, 'portfolio_hash' => $portfolio['portfolio_hash'], 'symbol' => $symbol,
            'evaluated_ms' => $signal['evaluated_ms'], 'candidate_price_source' => 'last_closed_15m_close', 'candidate_price' => $candidate,
            'zone_lower_price' => $zone['lower_price'], 'zone_upper_price' => $zone['upper_price'],
            'entry_price' => $zone['entry_price'], 'stop_price' => $protection['stop_price'],
            'risk_distance' => $protection['risk_distance'], 'targets' => $targets,
            'quantity' => $risk->quantity, 'position_notional_quote' => $risk->positionNotional,
            'final_leverage' => $risk->finalLeverage, 'effective_leverage_cap' => $risk->effectiveLeverageCap,
            'risk_budget_quote' => $risk->riskBudgetQuote,
            'risk_components_quote' => ['gross_stop_loss' => $risk->grossStopLoss, 'entry_fee' => $risk->entryFee,
                'stop_exit_fee' => $risk->stopExitFee, 'entry_spread' => $risk->entrySpreadCost,
                'stop_spread' => $risk->stopSpreadCost, 'entry_slippage' => $risk->entrySlippageCost,
                'stop_slippage' => $risk->stopSlippageCost, 'funding_provision' => $risk->fundingCost,
                'total_stop_loss' => $risk->totalStopLoss],
            'entry_ttl_seconds' => $this->policy->orderPolicy->ttlSeconds,
            'cancel_after_seconds' => $this->policy->orderPolicy->cancelAfterSeconds,
            'holding_deadline_ms' => $holdingDeadline->getTimestamp() * 1000,
            'holding_deadline_exclusive' => true,
            'instrument_binding' => 'synthetic_fake_local_from_research_manifest',
            'instrument_status' => $this->instruments->status,
            'instrument_math' => [
                'tick_size' => (float) $instrument['tick_size'], 'quantity_step' => (float) $instrument['quantity_step'],
                'min_quantity' => (float) $instrument['min_quantity'], 'max_quantity' => (float) $instrument['max_quantity'],
                'min_notional' => max(5.0, (float) $instrument['min_notional']),
                'contract_size' => (float) $instrument['contract_size'],
                'leverage_cap_assumed' => (float) $instrument['leverage_cap'],
                'mmr_proxy_rate' => (float) $instrument['mmr_proxy_rate'],
                'liquidation_fee_rate' => (float) $instrument['liquidation_fee_rate'],
                'metadata_retrieved_at' => $this->instruments->retrievedAt,
                'metadata_raw_sha256' => $this->instruments->rawSha256,
            ],
            'cost_status' => 'hypothetical_ohlcv_not_live_order_book',
            'cost_profile' => $this->costProfile,
            'cost_model' => [
                'entry_fee_role' => $this->policy->costContract->entryLiquidityRole,
                'stop_fee_role' => $this->policy->costContract->stopLiquidityRole,
                'target_fee_role' => $this->variant->targets[0]->liquidityRole,
                'entry_fee_rate' => $this->feeRate($this->policy->costContract->entryLiquidityRole),
                'stop_fee_rate' => $this->feeRate($this->policy->costContract->stopLiquidityRole),
                'target_fee_rate' => $this->feeRate($this->variant->targets[0]->liquidityRole),
                ...$profile, 'funding_interval_seconds' => $this->policy->costContract->fundingIntervalSeconds,
                'funding_intervals_provisioned' => $fundingIntervals,
            ],
        ];
        $plan['plan_hash'] = CanonicalBacktestRuleEvaluator::canonicalHash($plan);
        return $plan;
    }

    /** @param array<string, mixed> $signal */
    private function validateSignal(array $signal, int $index): void
    {
        self::exact($signal, ['schema_version', 'session_id', 'source', 'execution', 'baseline', 'symbol', 'evaluated_ms', 'evaluated_at', 'passed', 'reason_code', 'numeric_context_hash', 'contexts', 'trace_hash', 'verdicts', 'trace', 'result_hash'], 'research_signal_shape_invalid');
        if ($index < 0 || ($signal['schema_version'] ?? null) !== 'research-signal-result.v1'
            || ($signal['session_id'] ?? null) !== $this->sourceRun['signal_session_id']
            || ($signal['symbol'] ?? null) !== $this->sourceRun['symbol']
            || !is_array($signal['source']) || !is_array($signal['execution']) || !is_array($signal['baseline'])
            || CanonicalBacktestRuleEvaluator::canonicalJson($signal['source']) !== CanonicalBacktestRuleEvaluator::canonicalJson($this->sourceRun['source'])
            || CanonicalBacktestRuleEvaluator::canonicalJson($signal['baseline']) !== CanonicalBacktestRuleEvaluator::canonicalJson($this->baseline)
            || ($signal['execution']['mode_id'] ?? null) !== 'day_trading' || ($signal['execution']['mode_version'] ?? null) !== '1.1.0'
            || ($signal['execution']['setup_id'] ?? null) !== 'day_trading.trend_continuation.long'
            || ($signal['execution']['setup_version'] ?? null) !== '1.1.0'
            || ($signal['execution']['exchange'] ?? null) !== 'fake' || ($signal['execution']['environment'] ?? null) !== 'local'
            || ($signal['execution']['side'] ?? null) !== 'long' || ($signal['execution']['execution_capability'] ?? null) !== 'backtest'
            || !is_int($signal['evaluated_ms']) || $signal['evaluated_ms'] < 0 || $signal['evaluated_ms'] % 900000 !== 0
            || !is_string($signal['evaluated_at']) || $signal['evaluated_at'] !== gmdate('Y-m-d\TH:i:s', intdiv($signal['evaluated_ms'], 1000)) . '.000000Z'
            || !is_bool($signal['passed']) || !is_string($signal['reason_code'])
            || !is_array($signal['contexts']) || !is_array($signal['verdicts'])
            || !is_string($signal['numeric_context_hash']) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $signal['numeric_context_hash']) !== 1
            || !is_string($signal['result_hash']) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $signal['result_hash']) !== 1
        ) {
            throw new \InvalidArgumentException('research_signal_binding_invalid');
        }
        self::exact($signal['execution'], ['mode_id', 'mode_version', 'setup_id', 'setup_version',
            'exchange', 'environment', 'side', 'execution_capability'], 'research_signal_binding_invalid');
        $withoutHash = $signal;
        unset($withoutHash['result_hash']);
        if (!hash_equals(CanonicalBacktestRuleEvaluator::canonicalHash($withoutHash), $signal['result_hash'])) {
            throw new \InvalidArgumentException('research_signal_hash_invalid');
        }
        if ($signal['passed'] === true && $signal['reason_code'] !== 'setup_rules_passed') {
            throw new \InvalidArgumentException('research_signal_verdict_invalid');
        }
        if ($signal['passed'] === true && (!is_array($signal['trace']) || !is_string($signal['trace_hash'])
            || !hash_equals(CanonicalBacktestRuleEvaluator::canonicalHash($signal['trace']), $signal['trace_hash'])
            || ($signal['trace']['schema_version'] ?? null) !== 'canonical-setup-rule-runtime.v1'
            || ($signal['trace']['mode_id'] ?? null) !== 'day_trading'
            || ($signal['trace']['mode_version'] ?? null) !== '1.1.0'
            || ($signal['trace']['setup_id'] ?? null) !== 'day_trading.trend_continuation.long'
            || ($signal['trace']['setup_version'] ?? null) !== '1.1.0'
            || ($signal['trace']['side'] ?? null) !== 'long'
            || self::digest($signal['trace']['setup_hash'] ?? null) !== self::digest($this->baseline['setup_hash'])
            || self::digest($signal['trace']['catalog_hash'] ?? null) !== self::digest($this->baseline['condition_catalog_hash'])
            || self::digest($signal['trace']['config_hash'] ?? null) !== self::digest($this->baseline['config_hash'])
            || ($signal['trace']['execution_timeframe'] ?? null) !== '15m'
            || ($signal['trace']['mandatory_confirmations'] ?? null) !== ['5m', '1m']
            || ($signal['trace']['evaluated_at'] ?? null) !== $signal['evaluated_at']
        )) {
            throw new \InvalidArgumentException('research_signal_trace_invalid');
        }
        self::exact($signal['contexts'], ['1m', '5m', '15m', '1h', '4h'], 'research_signal_context_invalid');
        $allowedContextKeys = ['open_ms', 'available_ms', 'research_window_hash', 'close', 'rsi', 'ema_20', 'ema_50',
            'ema_200', 'macd_hist', 'vwap', 'atr', 'adx', 'ma9', 'ma21', 'bb_upper', 'bb_middle', 'bb_lower',
            'ema', 'ema_prev', 'ema_200_slope', 'macd', 'pullback_age_bars', 'volume_ratio', 'ma_21_plus_k_atr'];
        foreach (['1m' => 60000, '5m' => 300000, '15m' => 900000, '1h' => 3600000, '4h' => 14400000] as $tf => $step) {
            $context = $signal['contexts'][$tf] ?? null;
            if (!is_array($context) || !is_int($context['open_ms'] ?? null) || !is_int($context['available_ms'] ?? null)
                || !is_string($context['research_window_hash'] ?? null)
                || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $context['research_window_hash']) !== 1
                || array_diff(array_keys($context), $allowedContextKeys) !== []
                || $context['available_ms'] !== $context['open_ms'] + $step
            ) {
                throw new \InvalidArgumentException('research_signal_context_invalid');
            }
            if ($context['available_ms'] > $signal['evaluated_ms']) {
                throw new \InvalidArgumentException('research_signal_context_future');
            }
            if (in_array($tf, ['1m', '5m', '15m'], true) && $context['available_ms'] !== $signal['evaluated_ms']) {
                throw new \InvalidArgumentException('research_signal_context_stale');
            }
        }
    }

    /**
     * @param array<string, mixed> $portfolio
     * @param array<string, mixed> $signal
     */
    private function validatePortfolio(array $portfolio, array $signal): void
    {
        self::exact($portfolio, ['schema_version', 'as_of_ms', 'equity_quote', 'available_balance_quote', 'realized_net_pnl_quote', 'unrealized_net_pnl_quote', 'open_positions', 'pending_entries', 'open_notional_quote', 'pending_notional_quote', 'reserved_risk_quote', 'active_signal_hashes', 'portfolio_hash'], 'research_portfolio_view_invalid');
        if (($portfolio['schema_version'] ?? null) !== 'research-portfolio-view.v1'
            || ($portfolio['as_of_ms'] ?? null) !== $signal['evaluated_ms']
            || !is_int($portfolio['open_positions']) || $portfolio['open_positions'] < 0
            || !is_int($portfolio['pending_entries']) || $portfolio['pending_entries'] < 0
            || !is_array($portfolio['active_signal_hashes']) || !array_is_list($portfolio['active_signal_hashes'])
        ) {
            throw new \InvalidArgumentException('research_portfolio_view_invalid');
        }
        foreach (['equity_quote', 'available_balance_quote', 'realized_net_pnl_quote', 'unrealized_net_pnl_quote', 'open_notional_quote', 'pending_notional_quote', 'reserved_risk_quote'] as $field) {
            if ((!is_float($portfolio[$field]) && !is_int($portfolio[$field])) || !is_finite((float) $portfolio[$field])) {
                throw new \InvalidArgumentException('research_portfolio_view_invalid');
            }
        }
        if ($portfolio['equity_quote'] <= 0.0 || $portfolio['available_balance_quote'] < 0.0
            || $portfolio['open_notional_quote'] < 0.0 || $portfolio['pending_notional_quote'] < 0.0 || $portfolio['reserved_risk_quote'] < 0.0
            || count($portfolio['active_signal_hashes']) !== count(array_unique($portfolio['active_signal_hashes']))) {
            throw new \InvalidArgumentException('research_portfolio_view_invalid');
        }
        foreach ($portfolio['active_signal_hashes'] as $hash) {
            if (!is_string($hash) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $hash) !== 1) {
                throw new \InvalidArgumentException('research_portfolio_view_invalid');
            }
        }
        $withoutHash = $portfolio;
        unset($withoutHash['portfolio_hash']);
        if (!is_string($portfolio['portfolio_hash']) || !hash_equals(CanonicalBacktestRuleEvaluator::canonicalHash($withoutHash), $portfolio['portfolio_hash'])) {
            throw new \InvalidArgumentException('research_portfolio_hash_invalid');
        }
    }

    /** @param array<string, mixed> $portfolio */
    private function portfolioRejection(array $portfolio, float $riskQuote, float $notionalQuote): ?string
    {
        $equity = self::portfolioDecimal((float) $portfolio['equity_quote']);
        $dailyPct = $equity->multipliedBy(self::portfolioDecimal($this->portfolioPolicy->dailyLossRate));
        $dailyAbs = self::portfolioDecimal($this->portfolioPolicy->dailyLossAbsoluteQuote);
        $dailyCap = $dailyPct->isLessThan($dailyAbs) ? $dailyPct : $dailyAbs;
        $realized = self::portfolioDecimal((float) $portfolio['realized_net_pnl_quote']);
        $unrealized = self::portfolioDecimal((float) $portfolio['unrealized_net_pnl_quote']);
        $consumed = ($realized->isNegative() ? $realized->negated() : BigDecimal::zero())
            ->plus($unrealized->isNegative() ? $unrealized->negated() : BigDecimal::zero())
            ->plus(self::portfolioDecimal((float) $portfolio['reserved_risk_quote']));
        if (self::portfolioDecimal($riskQuote)->isGreaterThan($dailyCap->minus($consumed))) {
            return 'research_portfolio_daily_loss_exceeded';
        }
        if ((int) $portfolio['open_positions'] + (int) $portfolio['pending_entries'] >= $this->portfolioPolicy->maxConcurrentPositions) {
            return 'research_portfolio_concurrency_exceeded';
        }
        $exposure = self::portfolioDecimal((float) $portfolio['open_notional_quote'])
            ->plus(self::portfolioDecimal((float) $portfolio['pending_notional_quote']))->plus(self::portfolioDecimal($notionalQuote));
        if ($exposure->isGreaterThan($equity->multipliedBy(self::portfolioDecimal($this->portfolioPolicy->modeExposureRate)))) {
            return 'research_portfolio_exposure_exceeded';
        }
        return null;
    }

    private function feeRate(string $role): float
    {
        return match ($role) {
            'maker' => $this->policy->riskPolicy->makerFeeRate,
            'taker' => $this->policy->riskPolicy->takerFeeRate,
            default => throw new \InvalidArgumentException('research_fee_role_invalid'),
        };
    }

    private static function portfolioDecimal(float $value): BigDecimal
    {
        return CanonicalPortfolioDecimal::fromFloat($value, 'research_portfolio_arithmetic_invalid');
    }

    private static function digest(mixed $hash): ?string
    {
        if (!is_string($hash)) {
            return null;
        }
        $value = str_starts_with($hash, 'sha256:') ? substr($hash, 7) : $hash;
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1 ? $value : null;
    }

    /**
     * @param array<string, mixed> $signal
     * @return array<string, mixed>
     */
    private function identity(array $signal, int $index): array
    {
        return ['signal_result_hash' => $signal['result_hash'], 'signal_index' => $index,
            'signal_run_id' => $this->sourceRun['signal_run_id'], 'signal_output_sha256' => $this->sourceRun['signal_output_sha256'],
            'dataset_id' => $signal['source']['dataset_id'], 'dataset_sha256' => $signal['source']['dataset_sha256'],
            'source_venue' => 'binance_usdm', 'source_network' => 'mainnet', 'market_type' => 'perpetual',
            'base_setup_hash' => $this->baseline['setup_hash'], 'base_config_hash' => $this->baseline['config_hash'],
            'base_catalog_hash' => $this->baseline['condition_catalog_hash'], 'base_snapshot_hash' => $this->baseline['snapshot_hash'],
            'variant_id' => $this->variant->id, 'variant_hash' => $this->variant->hash,
            'instrument_assumptions_hash' => $this->instruments->hash, 'cost_assumptions_hash' => $this->costs->hash,
            'research_code_hash' => $this->codeHash,
            'code_hash_scope' => 'research_plan_direct_dependencies_v1',
            'integrity_boundary' => 'sha256_and_local_baseline_only_runner_verifies_b1_artifact'];
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private function reject(array $identity, string $reason): array
    {
        return ['schema_version' => 'research-plan-rejection.v1', 'research_only' => true,
            ...$identity, 'reason_code' => $reason];
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private static function exact(array $value, array $expected, string $reason): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException($reason);
        }
    }
}
