<?php

declare(strict_types=1);

namespace App\Trading\Storage;

use App\Provider\Context\ExchangeContext;
use App\Trading\Dto\PositionDto;
use App\Trading\Dto\PositionHistoryEntryDto;

interface PositionStateRepositoryInterface
{
    public function findLocalOpenPosition(
        string $symbol,
        string $side,
        ?ExchangeContext $context = null,
    ): ?PositionDto;

    /**
     * @param string[]|null $symbols
     * @return PositionDto[]
     */
    public function findLocalOpenPositions(
        ?array $symbols = null,
        ?ExchangeContext $context = null,
    ): array;

    public function saveOpenPosition(PositionDto $position): void;

    public function saveClosedPosition(PositionHistoryEntryDto $history): void;

    /**
     * @param string[]|null $symbols
     * @return PositionHistoryEntryDto[]
     */
    public function findLocalClosedPositions(
        ?array $symbols = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?ExchangeContext $context = null,
    ): array;
}

