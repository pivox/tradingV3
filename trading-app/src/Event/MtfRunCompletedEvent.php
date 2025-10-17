<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché lorsqu'un cycle MTF est terminé
 */
class MtfRunCompletedEvent extends Event
{
    public const NAME = 'mtf.run.completed';

    public function __construct(
        private readonly string $runId,
        private readonly array $symbols,
        private readonly int $symbolsCount,
        private readonly float $executionTime,
        private readonly array $summary,
        private readonly array $results,
    ) {
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getSymbols(): array
    {
        return $this->symbols;
    }

    public function getSymbolsCount(): int
    {
        return $this->symbolsCount;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getSummary(): array
    {
        return $this->summary;
    }

    public function getResults(): array
    {
        return $this->results;
    }
}
