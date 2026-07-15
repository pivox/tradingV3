<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Contract\Provider\Dto\PositionDto;

final readonly class PositionSnapshotItem
{
    public \DateTimeImmutable $openedAt;

    public function __construct(
        public string $symbol,
        public string $side,
        public string $size,
        public string $entryPrice,
        public string $markPrice,
        \DateTimeImmutable $openedAt,
    ) {
        $this->openedAt = $openedAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function fromProviderDto(PositionDto $position): self
    {
        return new self(
            symbol: $position->symbol,
            side: $position->side->value,
            size: (string) $position->size,
            entryPrice: (string) $position->entryPrice,
            markPrice: (string) $position->markPrice,
            openedAt: $position->openedAt,
        );
    }
}
