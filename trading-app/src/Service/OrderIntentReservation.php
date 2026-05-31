<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OrderIntent;

final class OrderIntentReservation
{
    private function __construct(
        public readonly OrderIntent $intent,
        public readonly bool $created,
        public readonly bool $blocked,
        public readonly ?string $reason = null,
    ) {
    }

    public static function created(OrderIntent $intent): self
    {
        return new self($intent, true, false);
    }

    public static function existing(OrderIntent $intent): self
    {
        return new self($intent, false, false);
    }

    public static function blocked(OrderIntent $intent, string $reason): self
    {
        return new self($intent, false, true, $reason);
    }
}
