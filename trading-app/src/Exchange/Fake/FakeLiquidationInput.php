<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Enum\ExchangePositionSide;

final readonly class FakeLiquidationInput
{
    public function __construct(
        public ExchangePositionSide $side,
        public string $marginMode,
        public ?string $quantity,
        public ?string $entryPrice,
        public ?string $isolatedMargin,
        public ?string $contractSize,
        public ?string $maintenanceMarginRate,
        public ?string $markPrice,
    ) {
    }
}
