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
        public HyperliquidCompensationReasonCode $reasonCode,
        public HyperliquidLifecycleStatus $entryStatus,
        public float $expectedQuantity,
        public float $provenFilledQuantity,
        public float $closedQuantity,
        public int $quantityPrecision,
        public string $quantityStep,
        public ?string $entryExchangeOrderId,
        public ?string $closeExchangeOrderId,
        public string $correlationId,
    ) {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_outcome_invalid');
        }
        try {
            $expected = new HyperliquidQuantity($expectedQuantity, $quantityPrecision, $quantityStep);
            $proven = new HyperliquidQuantity($provenFilledQuantity, $quantityPrecision, $quantityStep);
            $closed = new HyperliquidQuantity($closedQuantity, $quantityPrecision, $quantityStep);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_quantity_invalid');
        }
        if (!$expected->isPositive() || $proven->compareTo($expected) > 0 || $closed->compareTo($expected) > 0) {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_quantity_invalid');
        }
        if (!(new HyperliquidCorrelationIdValidator())->isValid($correlationId)) {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_correlation_id_invalid');
        }
        foreach ([$entryExchangeOrderId, $closeExchangeOrderId] as $oid) {
            if ($oid !== null && !$this->canonicalOid($oid)) {
                throw new \InvalidArgumentException('hyperliquid_compensation_result_oid_invalid');
            }
        }
        if ($entryExchangeOrderId !== null && $entryExchangeOrderId === $closeExchangeOrderId) {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_oid_invalid');
        }

        $valid = match ($outcome) {
            'entry_rejected' => $reasonCode === HyperliquidCompensationReasonCode::ENTRY_REJECTED
                && $entryStatus === HyperliquidLifecycleStatus::REJECTED
                && $proven->isZero()
                && $closed->isZero()
                && $closeExchangeOrderId === null,
            'entry_canceled' => $reasonCode === HyperliquidCompensationReasonCode::ENTRY_CANCELED
                && $entryStatus === HyperliquidLifecycleStatus::CANCELED
                && $proven->isZero()
                && $closed->isZero()
                && $closeExchangeOrderId === null,
            'exposure_closed' => $reasonCode === HyperliquidCompensationReasonCode::EXPOSURE_CLOSED
                && in_array($entryStatus, [HyperliquidLifecycleStatus::FILLED, HyperliquidLifecycleStatus::CANCELED], true)
                && $proven->isPositive()
                && $proven->equals($closed)
                && $closeExchangeOrderId !== null,
            'unknown_requires_resync' => !in_array($reasonCode, [
                HyperliquidCompensationReasonCode::ENTRY_REJECTED,
                HyperliquidCompensationReasonCode::ENTRY_CANCELED,
                HyperliquidCompensationReasonCode::EXPOSURE_CLOSED,
            ], true) && $closed->isZero(),
        };
        if (!$valid) {
            throw new \InvalidArgumentException('hyperliquid_compensation_result_matrix_invalid');
        }
    }

    private function canonicalOid(string $oid): bool
    {
        if (preg_match('/^[1-9][0-9]*$/D', $oid) !== 1) {
            return false;
        }

        $value = filter_var($oid, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($value) && (string) $value === $oid;
    }
}
