<?php

declare(strict_types=1);

namespace App\Provider\Registry\Exception;

use App\Provider\Context\ExchangeContext;
use RuntimeException;

final class ProviderNotFoundException extends RuntimeException
{
    /**
     * @param string[] $availableKeys
     */
    public static function forContext(ExchangeContext $context, array $availableKeys): self
    {
        return new self(sprintf(
            'No provider bundle registered for context "%s". Available contexts: %s',
            (string) $context,
            implode(', ', $availableKeys)
        ));
    }
}

