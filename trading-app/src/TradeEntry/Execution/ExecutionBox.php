<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Message\LimitFillWatchMessage;
use App\TradeEntry\Pricing\TickQuantizer;
use App\TradeEntry\Dto\{ExecutionResult};
use App\TradeEntry\Dto\{EntryZone, FallbackEndOfZoneConfig};
use App\TradeEntry\Helper\SpreadHelper;
use App\TradeEntry\Policy\{IdempotencyPolicy, MakerTakerSwitchPolicy, OrderModePolicyInterface};
use App\TradeEntry\Service\TpSlTwoTargetsService;
use App\TradeEntry\Dto\TpSlTwoTargetsRequest;
use App\Common\Enum\OrderStatus;
use App\Config\TradeEntryConfigResolver;
use App\Logging\Dto\LifecycleContextBuilder;
use App\TradeEntry\Types\Side;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Psr\Log\LoggerInterface;

final class ExecutionBox
{
    private const MARKET_FILL_TIMEOUT_WS_STRICT = 3; // 3s pour WS strict
    private const MARKET_FILL_TIMEOUT_TOTAL = 10; // 10s total avec REST fallback
    private const LIMIT_WATCH_INITIAL_DELAY_MS = 5000; // premier poll watcher LIMIT
    private const TAKE_PROFIT_R_CAP = 1.3;

    public function __construct(
        private readonly MainProviderInterface $providers,
        private readonly TpSlAttacher $tpSl,
        private readonly OrderModePolicyInterface $orderModePolicy,
        private readonly IdempotencyPolicy $idempotency,
        private readonly TradeEntryConfigResolver $tradeEntryConfigResolver,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
        private readonly MessageBusInterface $bus,
        private readonly ?TpSlTwoTargetsService $tpSlService = null,
    ) {}

    public function execute(
        OrderPlanModel $plan,
        ?string $decisionKey = null,
        ?LifecycleContextBuilder $contextBuilder = null,
        ?string $mode = null,
        ?string $executionTf = null
    ): ExecutionResult
    {
        $this->orderModePolicy->enforce($plan);
        $plan = $this->applyTimeframeMultiplier($plan, $mode, $executionTf, $decisionKey);
        $this->positionsLogger->debug('execution.start', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'entry' => $plan->entry,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'order_type' => $plan->orderType,
            'mode' => $plan->orderMode,
            'decision_key' => $decisionKey,
        ]);

        if ($plan->size < 1) {
            $clientOrderId = $this->idempotency->newClientOrderId();
            $this->positionsLogger->warning('execution.size_below_min', [
                'symbol' => $plan->symbol,
                'size' => $plan->size,
                'min_required' => 1,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
            ]);
            // Single-channel logging only

            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: null,
                status: 'skipped',
                raw: [
                    'reason' => 'size_below_min',
                    'size' => $plan->size,
                    'min_required' => 1,
                ],
            );
        }

        if ($plan->leverage < 1) {
            $clientOrderId = $this->idempotency->newClientOrderId();
            $this->positionsLogger->warning('execution.leverage_below_min', [
                'symbol' => $plan->symbol,
                'leverage' => $plan->leverage,
                'min_required' => 5,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
            ]);
            // Single-channel logging only

            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: null,
                status: 'skipped',
                raw: [
                    'reason' => 'leverage_below_min',
                    'leverage' => $plan->leverage,
                    'min_required' => 5,
                ],
            );
        }

        $clientOrderId = $this->idempotency->newClientOrderId();

        $this->positionsLogger->debug('execution.leverage_submit', [
            'symbol' => $plan->symbol,
            'leverage' => $plan->leverage,
            'open_type' => $plan->openType,
            'decision_key' => $decisionKey,
        ]);
        $leverageResult = $this->providers->getOrderProvider()->submitLeverage($plan->symbol, $plan->leverage, $plan->openType);
        $this->positionsLogger->debug('execution.leverage_response', [
            'symbol' => $plan->symbol,
            'result' => $leverageResult,
            'decision_key' => $decisionKey,
        ]);
        // Single-channel logging only

        // Router vers le flux market si nécessaire
        if ($plan->orderType === 'market') {
            return $this->executeMarketOrder($plan, $clientOrderId, $decisionKey, $leverageResult);
        }

       // $plan = $this->enforceTakeProfitCap($plan, $decisionKey);

        $payload = $this->tpSl->presetInSubmitPayload($plan, $clientOrderId);

        // Mapper side BitMart (1,2,3,4) vers OrderSide enum
        $side = match($payload['side']) {
            1 => OrderSide::BUY,   // open_long
            2 => OrderSide::SELL,  // close_long
            3 => OrderSide::BUY,   // close_short
            4 => OrderSide::SELL,  // open_short
        };

        // Extra visibility before submit
        // Convertir le type du plan en enum OrderType pour le provider
        $enforcedOrderType = ($plan->orderType === 'market') ? OrderType::MARKET : OrderType::LIMIT;

        $this->positionsLogger->debug('execution.presubmit_check', [
            'symbol' => $plan->symbol,
            'side_enum' => $side->value,
            'side_numeric' => $payload['side'],
            'type' => $payload['type'],
            'size' => (int)$payload['size'],
            'price' => $payload['price'] ?? null,
            'mode' => $payload['mode'],
            'open_type' => $payload['open_type'],
            'client_order_id' => $payload['client_order_id'],
            'decision_key' => $decisionKey,
            'plan_order_type' => $plan->orderType,
            'plan_order_mode' => $plan->orderMode,
            'enforced_type' => $enforcedOrderType->value,
            'enforced_mode' => (int)$plan->orderMode,
        ]);

        $orderPayload = $payload;
        // Utiliser le type du plan (déjà dans payload via TpSlAttacher, mais s'assurer de la cohérence)
        $orderPayload['type'] = ($plan->orderType === 'market') ? OrderType::MARKET->value : OrderType::LIMIT->value;
        $orderPayload['mode'] = (int)$plan->orderMode;

        // Politique: forcer une fenêtre de surveillance locale à 120s pour les LIMIT
        // et désactiver le dead-man switch exchange (cancel-all-after) afin d'éviter
        // l'annulation à 60s côté Bitmart (cap échange). L'annulation sera assurée
        // par le watcher local si l'ordre n'est pas rempli au bout de 120s.
        $cancelAfterTimeout = null;   // valeur envoyée au provider (0 => désarmement exchange)
        $watchWindowSec = null;       // fenêtre locale de surveillance/fallback
        if ($plan->orderType !== 'market') {
            // Fenêtre locale forcée à 120 secondes
            $watchWindowSec = 120;
            // Désarmer le dead-man côté exchange (éviter le cap 60s)
            $orderPayload['cancel_after_timeout'] = 0; // 0 => disable cancel-all-after
        }

        // Single-channel logging only

        $attemptLabel = sprintf('%s-mode-%d', $orderPayload['type'], (int)$orderPayload['mode']);
        $attemptIndex = 1;
        $attemptTotal = 1;

        $this->positionsLogger->info('execution.order_type_mode_selected', [
            'symbol' => $plan->symbol,
            'plan_order_type' => $plan->orderType,
            'plan_order_mode' => $plan->orderMode,
            'final_type' => $orderPayload['type'],
            'final_mode' => $orderPayload['mode'],
            'enforced_order_type_enum' => $enforcedOrderType->value,
            'attempt_label' => $attemptLabel,
            'decision_key' => $decisionKey,
        ]);
        $orderOptions = $this->extractOrderOptions($orderPayload);
        if ($cancelAfterTimeout !== null && $cancelAfterTimeout > 0) {
            // cas général (non utilisé ici car on désarme côté exchange)
            $orderOptions['cancel_after_timeout'] = $cancelAfterTimeout;
        }
        $orderResult = null;

        $attemptPayload = $orderPayload + [
            'decision_key' => $decisionKey,
            'attempt' => $attemptLabel,
            'attempt_index' => $attemptIndex,
            'attempt_total' => $attemptTotal,
        ];

        $this->positionsLogger->debug('execution.order_submit', $attemptPayload);
        // Single-channel logging only

        $orderResult = $this->providers->getOrderProvider()->placeOrder(
            symbol: $orderPayload['symbol'],
            side: $side,
            type: $enforcedOrderType,
            quantity: (float)$orderPayload['size'],
            price: isset($orderPayload['price']) ? (float)$orderPayload['price'] : null,
            stopPrice: null,
            options: $orderOptions
        );

        $this->positionsLogger->debug('execution.order_response', [
            'symbol' => $plan->symbol,
            'result' => $orderResult ? $orderResult->toArray() : null,
            'decision_key' => $decisionKey,
            'attempt' => $attemptLabel,
        ]);

        if ($orderResult !== null) {
            $this->positionsLogger->info('positions.order_submit.success', [
                'result' => 'success',
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'attempt_index' => $attemptIndex,
                'attempt_total' => $attemptTotal,
                'order_id' => $orderResult->orderId,
            ]);
            // Single-channel logging only

            // Programmer un watcher de fill pour LIMIT: si filled → désarmer dead-man (cancel-all-after)
            if ($plan->orderType !== 'market') {
                try {
                    // Utiliser la fenêtre locale forcée si définie, sinon fallback sur 60s
                    $watchSec = $watchWindowSec ?? ($cancelAfterTimeout ?? 60);
                    if ($watchSec <= 0) { $watchSec = 60; }
                    $contextSnapshot = $contextBuilder?->toArray();
                    $this->bus->dispatch(
                        new LimitFillWatchMessage(
                            symbol: $plan->symbol,
                            exchangeOrderId: (string)$orderResult->orderId,
                            clientOrderId: $clientOrderId,
                            side: strtoupper($side->value),
                            cancelAfterSec: (int) $watchSec,
                            tries: 0,
                            decisionKey: $decisionKey,
                            lifecycleContext: $contextSnapshot,
                        ),
                        [new DelayStamp(self::LIMIT_WATCH_INITIAL_DELAY_MS)] // premier poll dans 5s
                    );
                    $this->positionsLogger->info('execution.limit_watch.scheduled', [
                        'symbol' => $plan->symbol,
                        'order_id' => $orderResult->orderId,
                        'client_order_id' => $clientOrderId,
                        'watch_seconds' => $watchSec,
                        'decision_key' => $decisionKey,
                    ]);
                } catch (\Throwable $e) {
                    $this->positionsLogger->warning('execution.limit_watch.schedule_failed', [
                        'symbol' => $plan->symbol,
                        'order_id' => $orderResult->orderId,
                        'error' => $e->getMessage(),
                        'decision_key' => $decisionKey,
                    ]);
                }
            }
        } else {
            $this->positionsLogger->warning('execution.order_attempt_failed', [
                'symbol' => $plan->symbol,
                'attempt' => $attemptLabel,
                'order_type' => $enforcedOrderType->value,
                'mode' => $orderOptions['mode'] ?? null,
                'decision_key' => $decisionKey,
            ]);
            $this->positionsLogger->warning('positions.order_submit.fail', [
                'result' => 'fail',
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'attempt_index' => $attemptIndex,
                'attempt_total' => $attemptTotal,
                'order_type' => $enforcedOrderType->value,
                'mode' => $orderOptions['mode'] ?? null,
            ]);
            // Single-channel logging only
        }

        $this->positionsLogger->info('trade_entry.order_submitted', [
            'payload' => $orderPayload,
            'leverage' => $plan->leverage,
            'leverage_submit_success' => $leverageResult,
            'order' => $orderResult ? $orderResult->toArray() : null,
            'attempt' => $orderResult !== null ? $attemptLabel : null,
        ]);
        // Single-channel logging only

        $orderId = $orderResult?->orderId;
        $isOk = $orderResult !== null;

        if (!$isOk) {
            $this->positionsLogger->error('execution.order_error', [
                'symbol' => $plan->symbol,
                'code' => 0,
                'result' => null,
                'decision_key' => $decisionKey,
            ]);
            $this->positionsLogger->error('positions.order_submit.error', [
                'result' => 'error',
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'order_id' => $orderResult?->orderId,
                'reason' => 'all_attempts_failed',
            ]);
            // Single-channel logging only
        }

        return new ExecutionResult(
            clientOrderId: $clientOrderId,
            exchangeOrderId: $orderId,
            status: $isOk ? 'submitted' : 'error',
            raw: [
                'leverage' => $plan->leverage,
                'leverage_submit_success' => $leverageResult,
                'order' => $orderResult ? $orderResult->toArray() : null,
                'attempt' => $orderResult !== null ? $attemptLabel : null,
            ],
        );
    }

    /**
     * Prépare les options Bitmart pour l'appel placeOrder.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function extractOrderOptions(array $payload): array
    {
        $options = [
            'side' => $payload['side'],
            'mode' => $payload['mode'] ?? null,
            'open_type' => $payload['open_type'],
            'client_order_id' => $payload['client_order_id'],
            'leverage' => $payload['leverage'] ?? null,
        ];

        foreach ([
            'preset_take_profit_price',
            'preset_take_profit_price_type',
            'preset_stop_loss_price',
            'preset_stop_loss_price_type',
        ] as $key) {
            if (isset($payload[$key])) {
                $options[$key] = $payload[$key];
            }
        }

        return array_filter(
            $options,
            static fn($value) => $value !== null && $value !== ''
        );
    }

    private function applyTimeframeMultiplier(
        OrderPlanModel $plan,
        ?string $mode,
        ?string $executionTf,
        ?string $decisionKey
    ): OrderPlanModel {
        $tfKey = '5m';
        if (is_string($executionTf)) {
            $executionTf = trim($executionTf);
            if ($executionTf !== '') {
                $tfKey = strtolower($executionTf);
            }
        }

        try {
            $config = $this->tradeEntryConfigResolver->resolve($mode);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('execution.timeframe_multiplier.resolve_failed', [
                'mode' => $mode,
                'execution_tf' => $tfKey,
                'error' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);
            return $plan;
        }

        $defaults = $config->getDefaults();
        $leverageCfg = $config->getLeverage();
        $multipliers = $leverageCfg['timeframe_multipliers'] ?? [];
        if (!\is_array($multipliers)) {
            $multipliers = [];
        }
        $tfMultiplier = (float)($multipliers[$tfKey] ?? 1.0);
        if (!\is_finite($tfMultiplier) || $tfMultiplier <= 0.0) {
            $tfMultiplier = 1.0;
        }

        $effectiveMultiplier = $tfMultiplier;
        $maxLossPct = $leverageCfg['max_loss_pct'] ?? null;
        if ($maxLossPct !== null) {
            $maxLossPct = (float)$maxLossPct;
            if (\is_finite($maxLossPct)) {
                if ($maxLossPct > 1.0) {
                    $maxLossPct *= 0.01;
                }
                if ($maxLossPct <= 0.0) {
                    $maxLossPct = null;
                }
            } else {
                $maxLossPct = null;
            }
        }
        $maxLossUsdt = null;
        $maxSizeAllowed = null;
        if ($maxLossPct !== null) {
            $capital = (float)($defaults['initial_margin_usdt'] ?? 0.0);
            $riskPerContract = abs($plan->entry - $plan->stop) * $plan->contractSize;
            if ($capital > 0.0 && $riskPerContract > 0.0) {
                $maxLossUsdt = $capital * $maxLossPct;
                $maxSizeAllowed = (int)floor($maxLossUsdt / $riskPerContract);
                if ($maxSizeAllowed > 0) {
                    $maxMultiplier = $maxSizeAllowed / max(1.0, (float)$plan->size);
                    if (\is_finite($maxMultiplier) && $maxMultiplier > 0.0) {
                        $effectiveMultiplier = min($effectiveMultiplier, $maxMultiplier);
                    } else {
                        $effectiveMultiplier = 0.0;
                    }
                } else {
                    $effectiveMultiplier = 0.0;
                }
            }
        }

        $scaledSize = (int)floor($plan->size * $effectiveMultiplier);
        if ($scaledSize < 0) {
            $scaledSize = 0;
        }

        $scaledLeverageRaw = $plan->leverage * $effectiveMultiplier;
        $roundMode = strtolower((string)($leverageCfg['rounding']['mode'] ?? 'ceil'));
        $scaledLeverage = match ($roundMode) {
            'floor' => (int)floor($scaledLeverageRaw),
            'round' => (int)round($scaledLeverageRaw),
            default => (int)ceil($scaledLeverageRaw),
        };

        if ($scaledLeverage < 0) {
            $scaledLeverage = 0;
        }
        if ($scaledLeverage === 0 && $scaledSize > 0) {
            $scaledLeverage = 1;
        }
        if ($scaledLeverage < 1 && $scaledSize > 0) {
            $scaledLeverage = 1;
        }

        $floorCfg = isset($leverageCfg['floor']) ? (float)$leverageCfg['floor'] : null;
        if ($floorCfg !== null && \is_finite($floorCfg) && $floorCfg > 0.0) {
            $scaledLeverage = max($scaledLeverage, (int)ceil($floorCfg));
        }

        if ($tfMultiplier === 1.0) {
            $exchangeCapCfg = isset($leverageCfg['exchange_cap']) ? (float)$leverageCfg['exchange_cap'] : null;
            if ($exchangeCapCfg !== null && \is_finite($exchangeCapCfg) && $exchangeCapCfg > 0.0) {
                $scaledLeverage = min($scaledLeverage, (int)floor($exchangeCapCfg));
            }
        }

        $multiplierCapped = abs($effectiveMultiplier - $tfMultiplier) > 1e-9;
        if ($scaledSize === $plan->size && $scaledLeverage === $plan->leverage && !$multiplierCapped) {
            return $plan;
        }

        $this->positionsLogger->debug('execution.timeframe_multiplier_applied', [
            'symbol' => $plan->symbol,
            'execution_tf' => $tfKey,
            'tf_multiplier' => $tfMultiplier,
            'effective_multiplier' => $effectiveMultiplier,
            'max_loss_pct' => $maxLossPct,
            'max_loss_usdt' => $maxLossUsdt,
            'risk_per_contract' => abs($plan->entry - $plan->stop) * $plan->contractSize,
            'max_size_allowed' => $maxSizeAllowed,
            'base_size' => $plan->size,
            'scaled_size' => $scaledSize,
            'base_leverage' => $plan->leverage,
            'scaled_leverage' => $scaledLeverage,
            'mode' => $mode,
            'decision_key' => $decisionKey,
        ]);

        return $plan->copyWith(size: $scaledSize, leverage: $scaledLeverage);
    }

    private function enforceTakeProfitCap(OrderPlanModel $plan, ?string $decisionKey): OrderPlanModel
    {
        if (self::TAKE_PROFIT_R_CAP <= 0.0) {
            return $plan;
        }

        $riskUnit = abs($plan->entry - $plan->stop);
        if ($riskUnit <= 0.0) {
            return $plan;
        }

        $capDistance = self::TAKE_PROFIT_R_CAP * $riskUnit;
        $adjusted = $plan->takeProfit;

        if ($plan->side === Side::Long) {
            $maxTp = $plan->entry + $capDistance;
            if ($plan->takeProfit > $maxTp) {
                $adjusted = TickQuantizer::quantize(min($plan->takeProfit, $maxTp), $plan->pricePrecision);
            }
        } else {
            $minTp = $plan->entry - $capDistance;
            if ($plan->takeProfit < $minTp) {
                $adjusted = TickQuantizer::quantizeUp(max($plan->takeProfit, $minTp), $plan->pricePrecision);
            }
        }

        if (abs($adjusted - $plan->takeProfit) < 1e-12) {
            return $plan;
        }

        $this->positionsLogger->info('execution.take_profit_capped', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'tp_before' => $plan->takeProfit,
            'tp_after' => $adjusted,
            'risk_unit' => $riskUnit,
            'cap_r_multiple' => self::TAKE_PROFIT_R_CAP,
            'decision_key' => $decisionKey,
        ]);

        return $plan->copyWith(takeProfit: $adjusted);
    }

    /**
     * Exécute un ordre market avec soumission séparée de TP/SL après récupération du prix d'entrée réel.
     */
    private function executeMarketOrder(
        OrderPlanModel $plan,
        string $clientOrderId,
        ?string $decisionKey,
        bool $leverageResult
    ): ExecutionResult {
        $this->positionsLogger->info('execution.market_order.start', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'size' => $plan->size,
            'client_order_id' => $clientOrderId,
            'decision_key' => $decisionKey,
        ]);

        // 1) Soumettre l'ordre market sans TP/SL
        $payload = $this->tpSl->presetInSubmitPayload($plan, $clientOrderId);
        $side = match($payload['side']) {
            1 => OrderSide::BUY,
            2 => OrderSide::SELL,
            3 => OrderSide::BUY,
            4 => OrderSide::SELL,
        };

        $orderOptions = $this->extractOrderOptions($payload);
        // Retirer TP/SL du payload pour market
        unset($orderOptions['preset_take_profit_price'], $orderOptions['preset_take_profit_price_type']);
        unset($orderOptions['preset_stop_loss_price'], $orderOptions['preset_stop_loss_price_type']);

        $this->positionsLogger->debug('execution.market_order.submit', [
            'symbol' => $plan->symbol,
            'size' => $plan->size,
            'client_order_id' => $clientOrderId,
            'decision_key' => $decisionKey,
        ]);

        $orderResult = $this->providers->getOrderProvider()->placeOrder(
            symbol: $plan->symbol,
            side: $side,
            type: OrderType::MARKET,
            quantity: (float)$plan->size,
            price: null,
            stopPrice: null,
            options: $orderOptions
        );

        if ($orderResult === null) {
            $this->positionsLogger->error('execution.market_order.submit_failed', [
                'symbol' => $plan->symbol,
                'client_order_id' => $clientOrderId,
                'decision_key' => $decisionKey,
            ]);
            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: null,
                status: 'error',
                raw: ['reason' => 'market_order_submit_failed'],
            );
        }

        $orderId = $orderResult->orderId;
        $this->positionsLogger->info('execution.market_order.submitted', [
            'symbol' => $plan->symbol,
            'order_id' => $orderId,
            'client_order_id' => $clientOrderId,
            'decision_key' => $decisionKey,
        ]);

        // 2) Attendre l'exécution (3s WS strict, 10s total avec REST fallback)
        $filled = $this->waitForMarketOrderFill($orderId, $clientOrderId, $plan->symbol, $decisionKey);
        if (!$filled) {
            $this->positionsLogger->error('execution.market_order.fill_timeout', [
                'symbol' => $plan->symbol,
                'order_id' => $orderId,
                'client_order_id' => $clientOrderId,
                'decision_key' => $decisionKey,
            ]);
            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: $orderId,
                status: 'error',
                raw: ['reason' => 'market_order_fill_timeout'],
            );
        }

        // 3) Récupérer le prix d'entrée exact depuis la position
        $entryPrice = $this->getPositionEntryPrice($plan->symbol, $plan->side, $decisionKey);
        if ($entryPrice === null) {
            $this->positionsLogger->error('execution.market_order.entry_price_not_found', [
                'symbol' => $plan->symbol,
                'order_id' => $orderId,
                'decision_key' => $decisionKey,
            ]);
            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: $orderId,
                status: 'error',
                raw: ['reason' => 'entry_price_not_found'],
            );
        }

        $this->positionsLogger->info('execution.market_order.entry_price_retrieved', [
            'symbol' => $plan->symbol,
            'entry_price' => $entryPrice,
            'order_id' => $orderId,
            'decision_key' => $decisionKey,
        ]);

        // 4) Calculer et soumettre TP1, TP2, SL via TpSlTwoTargetsService
        if ($this->tpSlService === null) {
            $this->positionsLogger->error('execution.market_order.tp_sl_service_unavailable', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
            ]);
            return new ExecutionResult(
                clientOrderId: $clientOrderId,
                exchangeOrderId: $orderId,
                status: 'error',
                raw: ['reason' => 'tp_sl_service_unavailable'],
            );
        }

        $tpSlRequest = new TpSlTwoTargetsRequest(
            symbol: $plan->symbol,
            side: $plan->side,
            entryPrice: $entryPrice,
            size: $plan->size,
            rMultiple: null, // Utiliser les defaults du service
            splitPct: null, // Utiliser le resolver automatique
            cancelExistingStopLossIfDifferent: true,
            cancelExistingTakeProfits: true,
            slFullSize: true,
            momentum: null,
            mtfValidCount: null,
            pullbackClear: null,
            lateEntry: null,
            dryRun: false,
        );

        $tpSlResult = null;
        try {
            $tpSlResult = ($this->tpSlService)($tpSlRequest, $decisionKey);
            $this->positionsLogger->info('execution.market_order.tp_sl_submitted', [
                'symbol' => $plan->symbol,
                'sl' => $tpSlResult['sl'],
                'tp1' => $tpSlResult['tp1'],
                'tp2' => $tpSlResult['tp2'],
                'submitted_count' => count($tpSlResult['submitted']),
                'decision_key' => $decisionKey,
            ]);

            // 5) Vérifier les plans TP/SL
            $this->verifyPlanOrders($plan->symbol, $tpSlResult['submitted'], $decisionKey);

            // 6) Désarmer le dead-man switch symbolique (cancel-all-after) une fois la position ouverte et TP/SL soumis.
           /* $orderProvider = $this->providers->getOrderProvider();
            if ($orderProvider instanceof \App\Provider\Bitmart\BitmartOrderProvider) {
                try {
                    $orderProvider->cancelAllAfter($plan->symbol, 0);
                    $this->positionsLogger->info('execution.deadman_disarmed', [
                        'symbol' => $plan->symbol,
                        'reason' => 'position_opened_tp_sl_active',
                        'decision_key' => $decisionKey,
                    ]);
                } catch (\Throwable $e) {
                    $this->positionsLogger->warning('execution.deadman_disarm_failed', [
                        'symbol' => $plan->symbol,
                        'error' => $e->getMessage(),
                        'decision_key' => $decisionKey,
                    ]);
                }
            }*/
        } catch (\Throwable $e) {
            $this->positionsLogger->error('execution.market_order.tp_sl_submit_failed', [
                'symbol' => $plan->symbol,
                'error' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);
        }

        return new ExecutionResult(
            clientOrderId: $clientOrderId,
            exchangeOrderId: $orderId,
            status: 'submitted',
            raw: [
                'leverage' => $plan->leverage,
                'leverage_submit_success' => $leverageResult,
                'order' => $orderResult->toArray(),
                'entry_price' => $entryPrice,
                'tp_sl_submitted' => $tpSlResult['submitted'] ?? [],
            ],
        );
    }

    /**
     * Attend l'exécution d'un ordre market (3s WS strict, 10s total avec REST fallback).
     */
    private function waitForMarketOrderFill(string $orderId, string $clientOrderId, string $symbol, ?string $decisionKey): bool
    {
        $startTime = time();
        $wsDeadline = $startTime + self::MARKET_FILL_TIMEOUT_WS_STRICT;
        $totalDeadline = $startTime + self::MARKET_FILL_TIMEOUT_TOTAL;

        $this->positionsLogger->debug('execution.market_order.waiting_fill', [
            'symbol' => $symbol,
            'order_id' => $orderId,
            'client_order_id' => $clientOrderId,
            'ws_deadline' => $wsDeadline,
            'total_deadline' => $totalDeadline,
            'decision_key' => $decisionKey,
        ]);

        // Polling REST avec backoff
        $pollInterval = 0.2; // 200ms initial
        $maxPollInterval = 1.0; // 1s max

        while (time() < $totalDeadline) {
            $order = $this->providers->getOrderProvider()->getOrder($symbol, $orderId);
            if ($order !== null) {
                $status = $order->status;
                if ($status === OrderStatus::FILLED || $status === OrderStatus::PARTIALLY_FILLED) {
                    $this->positionsLogger->info('execution.market_order.filled', [
                        'symbol' => $symbol,
                        'order_id' => $orderId,
                        'status' => $status->value,
                        'filled_qty' => $order->filledQuantity->toFloat(),
                        'avg_price' => $order->averagePrice?->toFloat(),
                        'elapsed' => time() - $startTime,
                        'decision_key' => $decisionKey,
                    ]);
                    return true;
                }
                if ($status === OrderStatus::CANCELLED || $status === OrderStatus::REJECTED) {
                    $this->positionsLogger->warning('execution.market_order.cancelled_or_rejected', [
                        'symbol' => $symbol,
                        'order_id' => $orderId,
                        'status' => $status->value,
                        'decision_key' => $decisionKey,
                    ]);
                    return false;
                }
            }

            // Backoff exponentiel
            usleep((int)($pollInterval * 1_000_000));
            $pollInterval = min($pollInterval * 1.5, $maxPollInterval);
        }

        return false;
    }

    /**
     * Récupère le prix d'entrée exact depuis la position.
     */
    private function getPositionEntryPrice(string $symbol, \App\TradeEntry\Types\Side $side, ?string $decisionKey): ?float
    {
        $accountProvider = $this->providers->getAccountProvider();
        if ($accountProvider === null) {
            return null;
        }

        try {
            $positions = $accountProvider->getOpenPositions($symbol);
            $targetSideValue = $side->value; // 'long' ou 'short'

            foreach ($positions as $pos) {
                if ($pos->symbol === $symbol && strtolower($pos->side->value) === $targetSideValue) {
                    $entryPrice = $pos->entryPrice->toFloat();
                    $this->positionsLogger->debug('execution.market_order.position_found', [
                        'symbol' => $symbol,
                        'side' => $targetSideValue,
                        'entry_price' => $entryPrice,
                        'size' => $pos->size->toFloat(),
                        'decision_key' => $decisionKey,
                    ]);
                    return $entryPrice;
                }
            }

            $this->positionsLogger->warning('execution.market_order.position_not_found', [
                'symbol' => $symbol,
                'side' => $targetSideValue,
                'decision_key' => $decisionKey,
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->positionsLogger->error('execution.market_order.position_fetch_error', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);
            return null;
        }
    }

    /**
     * Vérifie que les plans TP/SL sont bien actifs.
     */
    private function verifyPlanOrders(string $symbol, array $submitted, ?string $decisionKey): void
    {
        $orderProvider = $this->providers->getOrderProvider();
        // Vérifier si le provider supporte getPlanOrders (spécifique BitMart)
        if (!$orderProvider instanceof \App\Provider\Bitmart\BitmartOrderProvider) {
            $this->positionsLogger->debug('execution.market_order.verify_skip', [
                'symbol' => $symbol,
                'reason' => 'getPlanOrders_not_available',
                'decision_key' => $decisionKey,
            ]);
            return;
        }

        try {
            $planOrders = $orderProvider->getPlanOrders($symbol);
            $submittedIds = array_column($submitted, 'order_id');
            $foundIds = [];

            foreach ($planOrders as $planOrder) {
                $planOrderId = $planOrder['plan_order_id'] ?? $planOrder['order_id'] ?? null;
                if ($planOrderId !== null && in_array($planOrderId, $submittedIds, true)) {
                    $foundIds[] = $planOrderId;
                }
            }

            $missing = array_diff($submittedIds, $foundIds);
            if (!empty($missing)) {
                $this->positionsLogger->warning('execution.market_order.verify_missing', [
                    'symbol' => $symbol,
                    'missing_order_ids' => $missing,
                    'found_count' => count($foundIds),
                    'submitted_count' => count($submittedIds),
                    'decision_key' => $decisionKey,
                ]);
            } else {
                $this->positionsLogger->info('execution.market_order.verify_success', [
                    'symbol' => $symbol,
                    'verified_count' => count($foundIds),
                    'decision_key' => $decisionKey,
                ]);
            }
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('execution.market_order.verify_error', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);
        }
    }

    private function applyMakerTakerSwitch(
        OrderPlanModel $plan,
        float $bid1,
        float $ask1,
        float $last,
        \DateTimeImmutable $now,
        MakerTakerSwitchPolicy $policy
    ): OrderPlanModel {
        $mid = max(($bid1 + $ask1) / 2.0, 1e-12);
        $spreadBps = 10_000.0 * ($ask1 - $bid1) / $mid;

        $withinZone = true;
        if ($plan->entryZoneLow !== null && $plan->entryZoneHigh !== null) {
            $withinZone = ($last >= $plan->entryZoneLow && $last <= $plan->entryZoneHigh);
        }

        if (!$policy->shouldSwitch($now, $plan->zoneExpiresAt, $spreadBps, $withinZone)) {
            return $plan; // rester Maker-Only
        }

        // IOC limit "capée" (prix plafond/plancher) pour contrôler le slippage
        if ($plan->side->name === 'Long') {
            $cap = $ask1 * (1.0 + $policy->maxSlippageBps / 10_000.0);
            if ($plan->entryZoneHigh !== null) {
                $cap = min($cap, $plan->entryZoneHigh);
            }
            $cap = TickQuantizer::quantizeUp($cap, $plan->pricePrecision);
        } else { // Short
            $cap = $bid1 * (1.0 - $policy->maxSlippageBps / 10_000.0);
            if ($plan->entryZoneLow !== null) {
                $cap = max($cap, $plan->entryZoneLow);
            }
            $cap = TickQuantizer::quantize($cap, $plan->pricePrecision);
        }

        $this->positionsLogger->debug('order_journey.execution.switch_to_taker', [
            'symbol' => $plan->symbol,
            'spread_bps' => $spreadBps,
            'cap' => $cap,
            'ttl_left' => $plan->zoneExpiresAt?->getTimestamp() - $now->getTimestamp(),
            'reason' => 'end_of_zone_fallback',
        ]);

        // BitMart Futures V2: IOC = mode=3 ; MakerOnly = mode=4 ; type reste 'limit'
        return $plan->copyWith(orderType: 'limit', orderMode: 3, entry: $cap);
    }

    /**
     * Fallback "fin de zone": si la zone expire sous peu et que les garde-fous sont OK,
     * décider d'un passage en taker (market ou limit selon config).
     * Retourne une petite décision structurelle pour que l'appelant adapte le plan.
     *
     * @return array{mode:string,order_type:string,reason:string}|null
     */
    public function applyEndOfZoneFallback(
        FallbackEndOfZoneConfig $cfg,
        EntryZone $zone,
        string $symbol,
        float $currentPrice,
        int $ttlRemainingSec
    ): ?array {
        if (!$cfg->enabled || $ttlRemainingSec > $cfg->ttlThresholdSec) {
            return null;
        }

        $orderBook = $this->providers->getOrderProvider()->getOrderBookTop($symbol);
        $spreadBps = SpreadHelper::calculateSpreadBps($orderBook);

        if ($spreadBps > $cfg->maxSpreadBps) {
            $this->positionsLogger->info("Skip fallback: spread too high ({$spreadBps} bps)");
            return null;
        }

        if ($cfg->onlyIfWithinZone && !$zone->contains($currentPrice)) {
            $this->positionsLogger->info('Skip fallback: price outside zone');
            return null;
        }

        // Approximated anchor: mid of the zone when not explicitly provided
        $anchor = max(1e-12, 0.5 * ($zone->min + $zone->max));
        $slippageBps = abs(($currentPrice - $anchor) / $anchor) * 10_000.0;
        if ($slippageBps > $cfg->maxSlippageBps) {
            $this->positionsLogger->info("Skip fallback: slippage too large ({$slippageBps} bps)");
            return null;
        }

        $this->positionsLogger->notice("Applying end-of-zone fallback taker for {$symbol}");

        return [
            'mode' => 'taker',
            'order_type' => $cfg->takerOrderType,
            'reason' => 'end_of_zone_fallback',
        ];
    }
}
