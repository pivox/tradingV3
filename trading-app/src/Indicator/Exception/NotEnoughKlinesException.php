<?php

declare(strict_types=1);

namespace App\Indicator\Exception;

/**
 * Exception levée lorsque le nombre de klines est insuffisant pour calculer les indicateurs
 */
final class NotEnoughKlinesException extends \RuntimeException
{
    public function __construct(
        private readonly string $symbol,
        private readonly string $timeframe,
        private readonly int $required,
        private readonly int $actual,
        ?\Throwable $previous = null
    ) {
        $message = sprintf(
            'Not enough klines for %s/%s: required %d, got %d',
            $symbol,
            $timeframe,
            $required,
            $actual
        );

        parent::__construct($message, 0, $previous);
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getTimeframe(): string
    {
        return $this->timeframe;
    }

    public function getRequired(): int
    {
        return $this->required;
    }

    public function getActual(): int
    {
        return $this->actual;
    }
}

