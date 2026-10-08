<?php

declare(strict_types=1);

namespace App\Provider\Context;

use App\Common\Enum\Exchange;

final class UnsupportedExchangeException extends \InvalidArgumentException
{
    public function __construct(public readonly string $rawValue)
    {
        parent::__construct(sprintf(
            'Unsupported exchange "%s". Accepted values: %s.',
            $rawValue,
            implode(', ', self::accepted()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function accepted(): array
    {
        return array_map(static fn (Exchange $exchange): string => $exchange->value, Exchange::cases());
    }
}
