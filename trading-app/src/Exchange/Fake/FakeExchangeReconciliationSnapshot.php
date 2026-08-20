<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;

final readonly class FakeExchangeReconciliationSnapshot
{
    /**
     * @param ExchangeOrderDto[] $orders
     * @param ExchangePositionDto[] $positions
     * @param ExchangeFillDto[] $fills
     * @param array<string,mixed>|null $pendingPrivateWsProof
     */
    public function __construct(
        public array $orders,
        public array $positions,
        public array $fills,
        public int $eventSequenceWatermark,
        public ?array $pendingPrivateWsProof,
    ) {
    }
}
