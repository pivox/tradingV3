<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleStatus;

final readonly class HyperliquidCompensationResult
{
    private const OUTCOMES = [
        'entry_rejected',
        'entry_canceled',
        'exposure_closed',
        'unknown_requires_resync',
    ];

    public function __construct(
        public string $outcome,
        public HyperliquidLifecycleStatus $entryStatus,
        public float $provenFilledQuantity,
        public float $closedQuantity,
        public ?string $entryExchangeOrderId,
        public ?string $closeExchangeOrderId,
        public string $correlationId,
    ) {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_outcome_invalid');
        }
        if (!is_finite($provenFilledQuantity) || $provenFilledQuantity < 0.0
            || !is_finite($closedQuantity) || $closedQuantity < 0.0
            || $closedQuantity > $provenFilledQuantity + 0.00000001
        ) {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_quantity_invalid');
        }
        if (trim($correlationId) === '') {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_correlation_id_invalid');
        }
    }
}
