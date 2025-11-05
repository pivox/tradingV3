<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Decision;

use App\Config\{MtfValidationConfig, TradingDecisionConfig};
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\Repository\MtfSwitchRepository;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TradingDecisionService
{
    public function __construct(
        private readonly TradingDecisionConfig $decisionConfig,
        private readonly MtfValidationConfig $mtfConfig,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $logger,
    ) {
    }

    public function generateDecisionKey(string $symbol): string
    {
        try {
            return sprintf('mtf:%s:%s', $symbol, bin2hex(random_bytes(6)));
        } catch (\Throwable) {
            return uniqid('mtf:' . $symbol . ':', true);
        }
    }

    public function evaluate(SymbolResultDto $symbolResult, string $decisionKey): TradingDecisionEvaluation
    {
        if ($symbolResult->isError() || $symbolResult->isSkipped()) {
            return new TradingDecisionEvaluation(
                TradingDecisionEvaluation::ACTION_NONE,
                $symbolResult,
                $decisionKey
            );
        }

        if (!$symbolResult->isReady()) {
            return new TradingDecisionEvaluation(
                TradingDecisionEvaluation::ACTION_NONE,
                $symbolResult,
                $decisionKey
            );
        }

        $blockReason = $this->checkPreconditions($symbolResult);
        if ($blockReason !== null) {
            return new TradingDecisionEvaluation(
                action: TradingDecisionEvaluation::ACTION_SKIP,
                result: $this->createSkippedResult($symbolResult, 'trading_conditions_not_met'),
                decisionKey: $decisionKey,
                skipReason: 'trading_conditions_not_met',
                blockReason: $blockReason,
            );
        }

        $tradeRequest = $this->buildTradeEntryRequest(
            $symbolResult,
            $symbolResult->currentPrice,
            $symbolResult->atr
        );

        if ($tradeRequest === null) {
            return new TradingDecisionEvaluation(
                action: TradingDecisionEvaluation::ACTION_SKIP,
                result: $this->createSkippedResult($symbolResult, 'unable_to_build_request'),
                decisionKey: $decisionKey,
                skipReason: 'unable_to_build_request',
                blockReason: 'unable_to_build_request',
                extraContext: [
                    'log_event' => 'unable_to_build_request',
                    'reason' => 'builder_returned_null',
                ],
            );
        }

        return new TradingDecisionEvaluation(
            action: TradingDecisionEvaluation::ACTION_PREPARE,
            result: $symbolResult,
            decisionKey: $decisionKey,
            tradeRequest: $tradeRequest,
        );
    }

    public function applyPostExecutionGuards(SymbolResultDto $symbolResult, ExecutionResult $execution, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        if ($execution->status !== 'submitted') {
            return;
        }

        try {
            $this->mtfSwitchRepository->turnOffSymbolFor15Minutes($symbolResult->symbol);
            $this->logger->info('[Trading Decision] Symbol switched OFF for 15 minutes after order', [
                'symbol' => $symbolResult->symbol,
                'duration' => '15 minutes',
                'reason' => 'order_submitted',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[Trading Decision] Failed to switch OFF symbol', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function checkPreconditions(SymbolResultDto $symbolResult): ?string
    {
        if ($symbolResult->executionTf === null) {
            return 'missing_execution_tf';
        }

        $allowedTfs = (array) ($this->decisionConfig->get(
            'allowed_execution_timeframes',
            $this->mtfConfig->getDefault('allowed_execution_timeframes', ['1m', '5m', '15m'])
        ));

        if (!in_array(strtolower($symbolResult->executionTf), array_map('strtolower', $allowedTfs), true)) {
            $this->logger->info('[Trading Decision] Skipping (unsupported execution TF)', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf,
            ]);

            return 'unsupported_execution_tf';
        }

        if ($symbolResult->signalSide === null) {
            return 'missing_signal_side';
        }

        $requirePriceOrAtr = (bool) ($this->decisionConfig->get(
            'require_price_or_atr',
            $this->mtfConfig->getDefault('require_price_or_atr', true)
        ));

        if ($requirePriceOrAtr && $symbolResult->currentPrice === null && $symbolResult->atr === null) {
            $this->logger->debug('[Trading Decision] Missing price and ATR', [
                'symbol' => $symbolResult->symbol,
            ]);

            return 'missing_price_and_atr';
        }

        return null;
    }

    private function buildTradeEntryRequest(SymbolResultDto $symbolResult, ?float $price, ?float $atr): ?TradeEntryRequest
    {
        $side = strtoupper((string) $symbolResult->signalSide);
        if (!in_array($side, ['LONG', 'SHORT'], true)) {
            return null;
        }

        $defaults = $this->mtfConfig->getDefaults();
        $executionTf = strtolower($symbolResult->executionTf ?? '1m');
        $multipliers = $defaults['timeframe_multipliers'] ?? [];
        $tfMultiplier = (float) ($multipliers[$executionTf] ?? 1.0);

        $riskPctPercent = (float) ($defaults['risk_pct_percent'] ?? 2.0);
        $riskPct = max(0.0, $riskPctPercent / 100.0) * $tfMultiplier;
        if ($riskPct <= 0.0) {
            return null;
        }

        $initialMargin = max(0.0, (float) ($defaults['initial_margin_usdt'] ?? 100.0) * $tfMultiplier);
        if ($initialMargin <= 0.0) {
            $fallbackCapital = (float) ($defaults['fallback_account_balance'] ?? 0.0);
            $initialMargin = $fallbackCapital * $riskPct;
        }

        if ($initialMargin <= 0.0) {
            return null;
        }

        $stopFrom = $defaults['stop_from'] ?? 'risk';
        $atrK = (float) ($defaults['atr_k'] ?? 1.5);
        $atrValue = ($atr !== null && $atr > 0.0) ? $atr : null;

        if ($stopFrom === 'atr' && ($atrValue === null || $atrValue <= 0.0)) {
            $this->logger->warning('[Trading Decision] ATR required but invalid/missing', [
                'symbol' => $symbolResult->symbol,
                'stop_from' => $stopFrom,
                'atr' => $atr,
                'atr_value' => $atrValue,
            ]);

            return null;
        }

        if ($atrValue === null && $stopFrom === 'atr') {
            $stopFrom = 'risk';
        }

        $orderType = $defaults['order_type'] ?? 'limit';
        $entryLimitHint = ($orderType === 'limit' && $price !== null) ? $price : null;

        $marketMaxSpreadPct = (float) ($defaults['market_max_spread_pct'] ?? 0.001);
        if ($marketMaxSpreadPct > 1.0) {
            $marketMaxSpreadPct /= 100.0;
        }

        $insideTicks = (int) ($defaults['inside_ticks'] ?? 1);
        $maxDeviationPct = isset($defaults['max_deviation_pct']) ? (float) $defaults['max_deviation_pct'] : null;
        $implausiblePct = isset($defaults['implausible_pct']) ? (float) $defaults['implausible_pct'] : null;
        $zoneMaxDeviationPct = isset($defaults['zone_max_deviation_pct']) ? (float) $defaults['zone_max_deviation_pct'] : null;

        $tpPolicy = (string) ($defaults['tp_policy'] ?? 'pivot_conservative');
        $tpBufferPct = isset($defaults['tp_buffer_pct']) ? (float) $defaults['tp_buffer_pct'] : null;
        if ($tpBufferPct !== null && $tpBufferPct <= 0.0) {
            $tpBufferPct = null;
        }

        $tpBufferTicks = isset($defaults['tp_buffer_ticks']) ? (int) $defaults['tp_buffer_ticks'] : null;
        if ($tpBufferTicks !== null && $tpBufferTicks <= 0) {
            $tpBufferTicks = null;
        }

        $tpMinKeepRatio = (float) ($defaults['tp_min_keep_ratio'] ?? 0.95);
        $tpMaxExtraR = isset($defaults['tp_max_extra_r']) ? (float) $defaults['tp_max_extra_r'] : null;
        if ($tpMaxExtraR !== null && $tpMaxExtraR < 0.0) {
            $tpMaxExtraR = null;
        }

        $pivotSlPolicy = (string) ($defaults['pivot_sl_policy'] ?? 'nearest_below');
        $pivotSlBufferPct = isset($defaults['pivot_sl_buffer_pct']) ? (float) $defaults['pivot_sl_buffer_pct'] : null;
        if ($pivotSlBufferPct !== null && $pivotSlBufferPct < 0.0) {
            $pivotSlBufferPct = null;
        }

        $pivotSlMinKeepRatio = isset($defaults['pivot_sl_min_keep_ratio']) ? (float) $defaults['pivot_sl_min_keep_ratio'] : null;
        if ($pivotSlMinKeepRatio !== null && $pivotSlMinKeepRatio <= 0.0) {
            $pivotSlMinKeepRatio = null;
        }

        $sideEnum = $side === 'LONG' ? Side::Long : Side::Short;

        return new TradeEntryRequest(
            symbol: $symbolResult->symbol,
            side: $sideEnum,
            orderType: $orderType,
            openType: $defaults['open_type'] ?? 'isolated',
            orderMode: (int) ($defaults['order_mode'] ?? 1),
            initialMarginUsdt: $initialMargin,
            riskPct: $riskPct,
            rMultiple: (float) ($defaults['r_multiple'] ?? 2.0),
            entryLimitHint: $entryLimitHint,
            stopFrom: $stopFrom,
            pivotSlPolicy: $pivotSlPolicy,
            pivotSlBufferPct: $pivotSlBufferPct,
            pivotSlMinKeepRatio: $pivotSlMinKeepRatio,
            atrValue: $atrValue,
            atrK: (float) $atrK,
            marketMaxSpreadPct: $marketMaxSpreadPct,
            insideTicks: $insideTicks,
            maxDeviationPct: $maxDeviationPct,
            implausiblePct: $implausiblePct,
            zoneMaxDeviationPct: $zoneMaxDeviationPct,
            tpPolicy: $tpPolicy,
            tpBufferPct: $tpBufferPct,
            tpBufferTicks: $tpBufferTicks,
            tpMinKeepRatio: $tpMinKeepRatio,
            tpMaxExtraR: $tpMaxExtraR,
        );
    }

    private function createSkippedResult(SymbolResultDto $symbolResult, string $reason): SymbolResultDto
    {
        return new SymbolResultDto(
            symbol: $symbolResult->symbol,
            status: $symbolResult->status,
            executionTf: $symbolResult->executionTf,
            signalSide: $symbolResult->signalSide,
            tradingDecision: [
                'status' => 'skipped',
                'reason' => $reason,
            ],
            error: $symbolResult->error,
            context: $symbolResult->context,
            currentPrice: $symbolResult->currentPrice,
            atr: $symbolResult->atr
        );
    }
}

