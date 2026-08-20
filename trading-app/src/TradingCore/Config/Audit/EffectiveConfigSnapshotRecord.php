<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

final readonly class EffectiveConfigSnapshotRecord
{
    /** @param array<string,mixed> $document */
    public function __construct(public array $document, public \DateTimeImmutable $createdAt)
    {
    }
}
