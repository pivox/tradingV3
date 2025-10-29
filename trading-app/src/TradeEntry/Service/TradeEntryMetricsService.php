<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

final class TradeEntryMetricsService
{
    private array $counters = [
        'submitted' => 0,
        'errors' => 0,
    ];

    public function __construct() {}

    public function incr(string $metric, array $labels = []): void
    {
        if (!array_key_exists($metric, $this->counters)) {
            $this->counters[$metric] = 0;
        }

        $this->counters[$metric]++;
    }

    public function snapshot(): array
    {
        return $this->counters;
    }

    public function reset(): void
    {
        foreach ($this->counters as $metric => $_) {
            $this->counters[$metric] = 0;
        }
    }
}
