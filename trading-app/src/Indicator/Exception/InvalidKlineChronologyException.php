<?php

declare(strict_types=1);

namespace App\Indicator\Exception;

final class InvalidKlineChronologyException extends \RuntimeException
{
    public function __construct(
        public readonly string $timeframe,
        public readonly string $reason,
        public readonly ?int $previousTimestamp = null,
        public readonly ?int $currentTimestamp = null,
    ) {
        parent::__construct(sprintf(
            'Invalid closed kline chronology for %s: %s%s',
            $timeframe,
            $reason,
            $previousTimestamp !== null || $currentTimestamp !== null
                ? sprintf(' (%s -> %s)', $previousTimestamp ?? 'null', $currentTimestamp ?? 'null')
                : '',
        ));
    }
}
