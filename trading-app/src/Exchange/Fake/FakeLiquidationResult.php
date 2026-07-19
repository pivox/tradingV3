<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Enum\ExchangePositionSide;

final readonly class FakeLiquidationResult
{
    public const READY = 'ready';
    public const UNSUPPORTED = 'unsupported';
    public const INVALID = 'invalid';

    public const SAFE = 'safe';
    public const GUARD = 'guard';
    public const LIQUIDATE = 'liquidate';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $status,
        public ?string $reason,
        public string $markState,
        public ExchangePositionSide $side,
        public string $marginMode,
        public ?string $quantity,
        public ?string $entryPrice,
        public ?string $isolatedMargin,
        public ?string $contractSize,
        public ?string $maintenanceMarginRate,
        public ?string $markPrice,
        public ?string $liquidationPrice,
        public ?string $guardPrice,
        public ?string $guardBufferAmount,
        public FakeLiquidationPolicy $policy,
    ) {
        if (!\in_array($this->status, [self::READY, self::UNSUPPORTED, self::INVALID], true)) {
            throw new \InvalidArgumentException('fake_liquidation_status_invalid');
        }
        if (!\in_array($this->markState, [self::SAFE, self::GUARD, self::LIQUIDATE, self::UNKNOWN], true)) {
            throw new \InvalidArgumentException('fake_liquidation_mark_state_invalid');
        }
    }

    /** @return array<string,string|null> */
    public function toAuditMetadata(): array
    {
        return [
            'liquidation_model_version' => $this->policy->modelVersion,
            'liquidation_status' => $this->status,
            'liquidation_reason' => $this->reason,
            'liquidation_mark_state' => $this->markState,
            'liquidation_margin_mode' => $this->marginMode,
            'liquidation_position_side' => $this->side->value,
            'liquidation_quantity_decimal' => $this->quantity,
            'liquidation_entry_price_decimal' => $this->entryPrice,
            'liquidation_isolated_margin_decimal' => $this->isolatedMargin,
            'liquidation_contract_size_decimal' => $this->contractSize,
            'liquidation_maintenance_margin_rate' => $this->maintenanceMarginRate,
            'liquidation_mark_price_decimal' => $this->markPrice,
            'liquidation_price_decimal' => $this->liquidationPrice,
            'liquidation_guard_price_decimal' => $this->guardPrice,
            'liquidation_guard_buffer_amount_decimal' => $this->guardBufferAmount,
            'liquidation_guard_buffer_rate' => $this->policy->guardBufferRate,
            'liquidation_fee_rate' => $this->policy->liquidationFeeRate,
            'liquidation_fee_currency' => $this->policy->feeCurrency,
            'liquidation_fee_model_version' => $this->policy->feeModelVersion,
            'liquidation_mark_price_source' => $this->policy->markPriceSource,
            'liquidation_cross_margin_status' => $this->policy->crossMarginStatus,
        ];
    }
}
