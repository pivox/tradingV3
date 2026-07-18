<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangePositionSide;

final readonly class FakeOneWayConflictGuard
{
    public const REJECTION_REASON = 'one_way_position_conflict';
    public const VERSION = 'fake-one-way-v1';

    public function __construct(private FakeExchangeStateStore $stateStore)
    {
    }

    /**
     * @return array<string,string>|null
     */
    public function conflictMetadata(PlaceOrderRequest $request, bool $reduceIntent): ?array
    {
        if ($reduceIntent) {
            return null;
        }

        $oppositeSide = match ($request->positionSide) {
            ExchangePositionSide::LONG => ExchangePositionSide::SHORT,
            ExchangePositionSide::SHORT => ExchangePositionSide::LONG,
        };

        foreach ($this->stateStore->getOpenPositions() as $position) {
            if (!$this->positionMatchesScope($position, $request) || $position->side !== $oppositeSide) {
                continue;
            }

            return $this->metadata($request, 'open_position', $position->side);
        }

        foreach ($this->stateStore->getOpenOrders() as $order) {
            if (!$this->orderMatchesScope($order, $request) || $order->reduceOnly) {
                continue;
            }
            if ($order->positionSide === null) {
                return $this->metadata($request, 'ambiguous_active_order');
            }
            if ($order->positionSide === $oppositeSide) {
                return $this->metadata($request, 'active_order', $order->positionSide);
            }
        }

        return null;
    }

    private function positionMatchesScope(ExchangePositionDto $position, PlaceOrderRequest $request): bool
    {
        return $position->exchange === $request->exchange
            && $position->marketType === $request->marketType
            && strtoupper($position->symbol) === strtoupper($request->symbol);
    }

    private function orderMatchesScope(ExchangeOrderDto $order, PlaceOrderRequest $request): bool
    {
        return $order->exchange === $request->exchange
            && $order->marketType === $request->marketType
            && strtoupper($order->symbol) === strtoupper($request->symbol);
    }

    /**
     * @return array<string,string>
     */
    private function metadata(
        PlaceOrderRequest $request,
        string $source,
        ?ExchangePositionSide $conflictingSide = null,
    ): array {
        return array_filter([
            'position_mode' => 'one_way',
            'position_mode_version' => self::VERSION,
            'position_scope' => implode('::', [
                $request->exchange->value,
                $request->marketType->value,
                strtoupper($request->symbol),
            ]),
            'requested_position_side' => $request->positionSide->value,
            'conflict_source' => $source,
            'conflicting_position_side' => $conflictingSide?->value,
        ], static fn (?string $value): bool => $value !== null);
    }
}
