<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Demo;

enum OkxDemoWriteKind: string
{
    case ENTRY = 'entry';
    case TAKE_PROFIT = 'take_profit';
    case PROTECTIVE = 'protective';

    public static function fromMetadata(mixed $value): self
    {
        return \is_string($value) ? (self::tryFrom($value) ?? self::ENTRY) : self::ENTRY;
    }
}
