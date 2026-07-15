<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderType;
use Brick\Math\BigDecimal;

final readonly class FakeOrderValidator
{
    public function __construct(private FakeInstrumentProviderInterface $instruments)
    {
    }

    public function validate(
        PlaceOrderRequest $request,
        float $referencePrice,
        float $availableMargin,
    ): FakeOrderValidationResult {
        $instrument = $this->instruments->find($request->symbol);
        if ($instrument === null) {
            return FakeOrderValidationResult::rejected('instrument_unknown');
        }

        if ($request->marketType !== $instrument->marketType) {
            return FakeOrderValidationResult::rejected('market_type_not_supported');
        }

        if (!\in_array($request->orderType, $instrument->allowedOrderTypes, true)) {
            return FakeOrderValidationResult::rejected('order_type_not_supported');
        }

        if ($request->price !== null && (
            !\is_finite($request->price)
            || !$instrument->isPriceQuantized(self::decimal($request->price))
        )) {
            return FakeOrderValidationResult::rejected('price_not_quantized');
        }

        foreach ([$request->stopPrice, $request->attachedStopLossPrice, $request->attachedTakeProfitPrice] as $stopPrice) {
            if ($stopPrice !== null && (
                !\is_finite($stopPrice)
                || !$instrument->isPriceQuantized(self::decimal($stopPrice))
            )) {
                return FakeOrderValidationResult::rejected('stop_price_not_quantized');
            }
        }

        if (!\is_finite($request->quantity)) {
            return FakeOrderValidationResult::rejected('quantity_not_quantized');
        }

        $quantity = BigDecimal::of(self::decimal($request->quantity));
        if (!$instrument->isQuantityQuantized((string) $quantity)) {
            return FakeOrderValidationResult::rejected('quantity_not_quantized');
        }

        if ($quantity->isLessThan($instrument->minQuantity)) {
            return FakeOrderValidationResult::rejected('quantity_below_minimum');
        }

        $notionalPrice = $request->orderType === ExchangeOrderType::LIMIT
            ? $request->price
            : $referencePrice;
        if ($notionalPrice === null || !\is_finite($notionalPrice) || $notionalPrice <= 0.0) {
            return FakeOrderValidationResult::rejected('notional_below_minimum');
        }
        $notional = $quantity
            ->multipliedBy(self::decimal($notionalPrice))
            ->multipliedBy($instrument->contractSize);
        if ($notional->isLessThan($instrument->minNotional)) {
            return FakeOrderValidationResult::rejected('notional_below_minimum');
        }

        $leverage = $request->leverage ?? 1;
        if ($leverage > $instrument->maxLeverage) {
            return FakeOrderValidationResult::rejected('leverage_above_maximum');
        }

        if (!\in_array($request->marginMode, ['isolated', 'cross'], true)) {
            return FakeOrderValidationResult::rejected('margin_mode_not_supported');
        }

        if (!\is_finite($availableMargin) || $availableMargin < 0.0) {
            return FakeOrderValidationResult::rejected('insufficient_balance');
        }

        $protectionOrder = \in_array(
            $request->orderType,
            [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TAKE_PROFIT],
            true,
        );
        if (!$request->reduceOnly && !$protectionOrder) {
            $availableLeveragedMargin = BigDecimal::of(self::decimal($availableMargin))->multipliedBy($leverage);
            if ($notional->isGreaterThan($availableLeveragedMargin)) {
                return FakeOrderValidationResult::rejected('insufficient_balance');
            }
        }

        return FakeOrderValidationResult::accepted();
    }

    private static function decimal(float $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
