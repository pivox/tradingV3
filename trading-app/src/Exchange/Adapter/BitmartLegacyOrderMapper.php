<?php

declare(strict_types=1);

namespace App\Exchange\Adapter;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Entity\OrderIntent;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;

final class BitmartLegacyOrderMapper
{
    private const SIDE_OPEN_LONG = 1;
    private const SIDE_CLOSE_SHORT = 2;
    private const SIDE_CLOSE_LONG = 3;
    private const SIDE_OPEN_SHORT = 4;

    private const MODE_IOC = 3;

    /**
     * @return array<string,mixed>
     */
    public function entrySubmitPayload(OrderPlanModel $plan, string $clientOrderId): array
    {
        $payload = [
            'symbol' => $plan->symbol,
            'side' => $this->entrySideCode($plan),
            'type' => $plan->orderType,
            'mode' => $this->enforcedOrderMode($plan),
            'open_type' => $plan->openType,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'client_order_id' => $clientOrderId,
        ];

        if ($plan->orderType === 'limit') {
            $payload['price'] = (string) $plan->entry;
            $payload['preset_take_profit_price'] = (string) $plan->takeProfit;
            $payload['preset_take_profit_price_type'] = 1;
            $payload['preset_stop_loss_price'] = (string) $plan->stop;
            $payload['preset_stop_loss_price_type'] = 1;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function orderOptions(array $payload): array
    {
        $options = [
            'side' => $payload['side'],
            'mode' => $payload['mode'] ?? null,
            'open_type' => $payload['open_type'],
            'client_order_id' => $payload['client_order_id'],
            'leverage' => $payload['leverage'] ?? null,
        ];

        foreach ([
            'decision_key',
            'order_intent_id',
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
            static fn ($value) => $value !== null && $value !== '',
        );
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function withoutAttachedProtectionOptions(array $options): array
    {
        unset($options['preset_take_profit_price'], $options['preset_take_profit_price_type']);
        unset($options['preset_stop_loss_price'], $options['preset_stop_loss_price_type']);

        return $options;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function providerSide(array $payload): OrderSide
    {
        return match ((int) $payload['side']) {
            self::SIDE_OPEN_LONG, self::SIDE_CLOSE_SHORT => OrderSide::BUY,
            self::SIDE_CLOSE_LONG, self::SIDE_OPEN_SHORT => OrderSide::SELL,
            default => throw new \InvalidArgumentException(sprintf('Unsupported BitMart side code "%s"', (string) $payload['side'])),
        };
    }

    public function providerOrderType(OrderPlanModel $plan): OrderType
    {
        return $plan->orderType === 'market' ? OrderType::MARKET : OrderType::LIMIT;
    }

    public function enforcedOrderMode(OrderPlanModel $plan): int
    {
        return $plan->orderType === 'market' ? self::MODE_IOC : $plan->orderMode;
    }

    public function legacyIocMode(): int
    {
        return self::MODE_IOC;
    }

    public function entrySideCode(OrderPlanModel $plan): int
    {
        return $plan->side === Side::Long ? self::SIDE_OPEN_LONG : self::SIDE_OPEN_SHORT;
    }

    /**
     * @return array<string,mixed>
     */
    public function orderIntentExecutionParams(OrderPlanModel $plan, string $clientOrderId): array
    {
        return [
            'side' => $this->entrySideCode($plan),
            'type' => $plan->orderType,
            'open_type' => $plan->openType,
            'leverage' => $plan->leverage,
            'position_mode' => OrderIntent::POSITION_MODE_HEDGE,
            'price' => $plan->orderType === 'limit' ? (string) $plan->entry : null,
            'size' => $plan->size,
            'client_order_id' => $clientOrderId,
            'preset_mode' => ($plan->stop > 0.0 || $plan->takeProfit > 0.0)
                ? OrderIntent::PRESET_MODE_PRESET_ON_ENTRY
                : OrderIntent::PRESET_MODE_NONE,
            'preset_stop_loss_price' => $plan->stop > 0.0 ? (string) $plan->stop : null,
            'preset_take_profit_price' => $plan->takeProfit > 0.0 ? (string) $plan->takeProfit : null,
        ];
    }
}
