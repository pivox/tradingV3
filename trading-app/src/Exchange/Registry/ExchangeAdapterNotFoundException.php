<?php

declare(strict_types=1);

namespace App\Exchange\Registry;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

final class ExchangeAdapterNotFoundException extends \RuntimeException
{
    /**
     * @param string[] $available
     */
    public static function forContext(Exchange $exchange, MarketType $marketType, array $available): self
    {
        return new self(sprintf(
            'No exchange adapter registered for "%s::%s". Available adapters: %s',
            $exchange->value,
            $marketType->value,
            $available === [] ? '(none)' : implode(', ', $available),
        ));
    }
}
