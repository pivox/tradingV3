<?php

declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\Entity\TradeZoneEvent;
use App\Repository\TradeZoneEventRepository;

final class ZoneDeviationAnalyzerService
{
    private const DEFAULT_LOOKBACK_HOURS = 168; // 7 days

    public function __construct(
        private readonly TradeZoneEventRepository $repository,
    ) {}

    public function computePercentile80(string $symbol, ?\DateTimeImmutable $since = null, int $maxSamples = 200): ?float
    {
        $since = $this->resolveSince($since);
        $series = $this->repository->getDeviationSeries($symbol, $since, $maxSamples);
        if ($series === []) {
            return null;
        }

        $values = array_map(
            static fn(array $row): float => isset($row['zoneDevPct']) ? (float)$row['zoneDevPct'] : 0.0,
            $series
        );
        sort($values);

        $count = count($values);
        if ($count === 0) {
            return null;
        }

        $position = 0.8 * ($count - 1);
        $lower = (int)floor($position);
        $upper = (int)ceil($position);
        if ($lower === $upper) {
            return $values[$lower];
        }

        $fraction = $position - $lower;

        return $values[$lower] + $fraction * ($values[$upper] - $values[$lower]);
    }

    public function computeOptimalZone(string $symbol, ?\DateTimeImmutable $since = null): ?float
    {
        $p80 = $this->computePercentile80($symbol, $since);
        if ($p80 === null) {
            return null;
        }

        $buffered = $p80 * 1.05;

        return min(max($buffered, 0.0005), 0.05);
    }

    /**
     * @return array<int, array{symbol: string, ratio: float, avg_dev_pct: float, avg_max_dev_pct: float, events: int}>
     */
    public function findVolatileSymbols(?\DateTimeImmutable $since = null, float $ratioThreshold = 1.4, int $minEvents = 3): array
    {
        $since = $this->resolveSince($since);
        $stats = $this->repository->getAggregatedStats($since);
        $volatile = [];

        foreach ($stats as $row) {
            $ratio = $row['avgMaxDevPct'] > 0.0
                ? $row['avgDevPct'] / max($row['avgMaxDevPct'], 1e-9)
                : null;

            if ($ratio !== null && $ratio >= $ratioThreshold && $row['events'] >= $minEvents) {
                $volatile[] = [
                    'symbol' => $row['symbol'],
                    'ratio' => $ratio,
                    'avg_dev_pct' => $row['avgDevPct'],
                    'avg_max_dev_pct' => $row['avgMaxDevPct'],
                    'events' => $row['events'],
                ];
            }
        }

        usort($volatile, static fn(array $a, array $b): int => $b['ratio'] <=> $a['ratio']);

        return $volatile;
    }

    /**
     * @return array{since: string, symbols: array<int, array<string,mixed>>}
     */
    public function generateDailyReport(?\DateTimeImmutable $since = null): array
    {
        $since = $since ?? $this->resolveSince(null, 24);
        $stats = $this->repository->getAggregatedStats($since);
        $report = [];

        foreach ($stats as $row) {
            $p80 = $this->computePercentile80($row['symbol'], $since);
            $proposed = $p80 !== null ? min($p80 * 1.05, 0.05) : null;
            $ratio = $row['avgMaxDevPct'] > 0.0
                ? $row['avgDevPct'] / max($row['avgMaxDevPct'], 1e-9)
                : null;

            $report[] = [
                'symbol' => $row['symbol'],
                'events' => $row['events'],
                'avg_dev_pct' => $row['avgDevPct'],
                'avg_max_dev_pct' => $row['avgMaxDevPct'],
                'ratio' => $ratio,
                'p80' => $p80,
                'proposed_zone_max_pct' => $proposed,
            ];
        }

        return [
            'since' => $since->format(\DateTimeInterface::ATOM),
            'symbols' => $report,
        ];
    }

    public function shouldRelaxZone(string $symbol, ?\DateTimeImmutable $since = null, float $bufferRatio = 1.05): bool
    {
        $optimal = $this->computeOptimalZone($symbol, $since);
        if ($optimal === null) {
            return false;
        }

        $latest = $this->repository->findRecentForSymbol($symbol, 1);
        if ($latest === []) {
            return false;
        }

        /** @var TradeZoneEvent $last */
        $last = $latest[0];
        $current = $last->getZoneMaxDevPct();
        if ($current <= 0.0) {
            return false;
        }

        return $optimal >= $current * $bufferRatio;
    }

    private function resolveSince(?\DateTimeImmutable $since, int $hours = self::DEFAULT_LOOKBACK_HOURS): \DateTimeImmutable
    {
        if ($since !== null) {
            return $since;
        }

        return (new \DateTimeImmutable(sprintf('-%d hours', max(1, $hours))))
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
