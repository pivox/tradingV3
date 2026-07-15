<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

enum FakeExchangeFaultKind: string
{
    case NetworkTimeout = 'network_timeout';
    case TransportError = 'transport_error';
    case Http429 = 'http_429';
    case Http500 = 'http_500';

    public function httpStatus(): ?int
    {
        return match ($this) {
            self::Http429 => 429,
            self::Http500 => 500,
            default => null,
        };
    }
}
