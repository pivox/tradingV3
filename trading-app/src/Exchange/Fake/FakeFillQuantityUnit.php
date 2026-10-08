<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeOrderDto;
use Brick\Math\BigDecimal;

/**
 * Unit of a Fake fill quantity (#132 p). A Fake order is sized in contracts of its instrument's
 * contract size: the margin_contract_size the engine computed the fill's fee, slippage, margin and
 * funding with (the OKX ctVal on an OKX Paper cell). A fill of such an order declares it, so the
 * fill-cost ledger records base-asset quantities. A contract size of exactly 1 (legacy catalogue,
 * Hyperliquid) is already a base-asset quantity: nothing is declared and the fill is recorded as
 * before. An order without a valid contract size declares contracts without a value: the ledger
 * flags that row instead of assuming 1.
 */
final class FakeFillQuantityUnit
{
    public const QUANTITY_UNIT = 'quantity_unit';
    public const CONTRACT_VALUE = 'contract_value';
    public const CONTRACTS = 'contracts';

    /** @return array{quantity_unit?: string, contract_value?: string} */
    public static function metadata(ExchangeOrderDto $order): array
    {
        $contractSize = $order->metadata['margin_contract_size'] ?? null;
        $value = null;
        if (\is_string($contractSize) || \is_int($contractSize)
            || (\is_float($contractSize) && \is_finite($contractSize))
        ) {
            try {
                $value = BigDecimal::of((string) $contractSize);
            } catch (\Throwable) {
                $value = null;
            }
        }
        if ($value === null || !$value->isPositive()) {
            return [self::QUANTITY_UNIT => self::CONTRACTS];
        }
        if ($value->isEqualTo(BigDecimal::one())) {
            return [];
        }
        $value = $value->stripTrailingZeros();

        return [
            self::QUANTITY_UNIT => self::CONTRACTS,
            self::CONTRACT_VALUE => $value->getScale() < 0 ? (string) $value->toScale(0) : (string) $value,
        ];
    }
}
