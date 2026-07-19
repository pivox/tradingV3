<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeLiquidationPolicy
{
    public const MODEL_VERSION = 'fake-isolated-linear-liquidation-v1';
    public const FEE_MODEL_VERSION = 'fake-liquidation-notional-fee-v1';
    public const MARK_PRICE_SOURCE = 'fake-controlled-mark-v1';

    public string $modelVersion;

    public string $feeModelVersion;

    public string $feeCurrency;

    public string $markPriceSource;

    public string $supportedMarginMode;

    public string $crossMarginStatus;

    public function __construct(
        public string $guardBufferRate = '0.010000000000',
        public string $liquidationFeeRate = '0.005000000000',
    ) {
        $this->modelVersion = self::MODEL_VERSION;
        $this->feeModelVersion = self::FEE_MODEL_VERSION;
        $this->feeCurrency = 'USDT';
        $this->markPriceSource = self::MARK_PRICE_SOURCE;
        $this->supportedMarginMode = 'isolated';
        $this->crossMarginStatus = 'unsupported';
    }
}
