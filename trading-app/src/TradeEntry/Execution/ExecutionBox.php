<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Pricing\TickQuantizer;
use App\TradeEntry\Dto\{ExecutionResult};
use App\TradeEntry\Policy\{IdempotencyPolicy, MakerTakerSwitchPolicy, OrderModePolicyInterface};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

final class ExecutionBox
{
    public function __construct(
        private readonly MainProviderInterface $providers,
        private readonly TpSlAttacher $tpSl,
        private readonly OrderModePolicyInterface $orderModePolicy,
        private readonly IdempotencyPolicy $idempotency,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function execute(OrderPlanModel $plan, ?string $decisionKey = null): ExecutionResult
    {
        $this->orderModePolicy->enforce($plan);
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
}
