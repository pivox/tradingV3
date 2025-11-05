<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Application;

use App\Contract\Provider\Dto\PositionDto;

/**
 * Immutable snapshot for positions/orders context used during MTF run.
 */
final class PositionsSnapshot
{
    /**
     * @param array<string, PositionDto> $positions
     * @param array<string, list<OrderDto>> $orders
     * @param array<string, bool> $adjustmentRequests
     */
    public function __construct(
        private readonly array $positions,
        private readonly array $orders,
        private readonly array $adjustmentRequests,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function getSymbols(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->positions),
            array_keys($this->orders),
            array_keys($this->adjustmentRequests),
        )));
    }

    public function hasSymbol(string $symbol): bool
    {
        $symbol = strtoupper($symbol);
        return isset($this->positions[$symbol]) || isset($this->orders[$symbol]) || isset($this->adjustmentRequests[$symbol]);
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        $symbol = strtoupper($symbol);
        return $this->positions[$symbol] ?? null;
    }

    /**
     * @return list<OrderDto>
     */
    public function getOrders(string $symbol): array
    {
        $symbol = strtoupper($symbol);
        return $this->orders[$symbol] ?? [];
    }

    public function isAdjustmentRequested(string $symbol): bool
    {
        $symbol = strtoupper($symbol);
        return (bool)($this->adjustmentRequests[$symbol] ?? false);
    }

    /**
     * Export a compact symbol context used by downstream processors.
     *
     * @return array<string, mixed>
     */
    public function getSymbolContext(string $symbol): array
    {
        return [
            'position' => $this->getPosition($symbol),
            'orders' => $this->getOrders($symbol),
            'adjustment_requested' => $this->isAdjustmentRequested($symbol),
        ];
    }
}
