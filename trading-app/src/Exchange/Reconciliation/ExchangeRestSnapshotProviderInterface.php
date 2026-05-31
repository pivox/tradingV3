<?php

declare(strict_types=1);

namespace App\Exchange\Reconciliation;

use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;

interface ExchangeRestSnapshotProviderInterface
{
    /**
     * @return ExchangeOrderDto[]
     */
    public function getOrdersSnapshot(?string $symbol = null): array;

    /**
     * @return ExchangeFillDto[]
     */
    public function getFillsSnapshot(?string $symbol = null): array;

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool;
}
