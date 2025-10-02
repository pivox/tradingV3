<?php

declare(strict_types=1);

namespace App\Service\Strategy;

use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Lightweight macro calendar checker driven by config/highconviction_macro.yaml.
 * Format attendu :
 * events:
 *   - at: '2025-01-10T13:30:00Z'
 *     label: 'CPI US'
 *     window_minutes: 90
 *     symbols: ['BTCUSDT','ETHUSDT']   # optionnel
 *     impact: high
 */
final class MacroCalendarService
{
    private const CONFIG_PATH = '/config/highconviction_macro.yaml';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
        #[Autowire(service: 'monolog.logger.highconviction')] private readonly LoggerInterface $highconviction,
    ) {}

    /**
     * Retourne true si aucune news bloquante dans le lookahead demandé.
     * @return array{no_event: bool, blocking_event: array<string,mixed>|null}
     */
    public function evaluateWindow(DateTimeImmutable $from, int $lookaheadMinutes = 90, ?string $symbol = null): array
    {
        $events = $this->loadEvents();
        if ($events === []) {
            return ['no_event' => true, 'blocking_event' => null];
        }

        $windowEnd = $from->add(new DateInterval('PT'.max(1, $lookaheadMinutes).'M'));
        foreach ($events as $event) {
            $at = $this->parseDate($event['at'] ?? '');
            if ($at === null) {
                continue;
            }

            $windowMinutes = $this->toInt($event['window_minutes'] ?? 60);
            $effectiveStart = $at->sub(new DateInterval('PT'.max(1, $windowMinutes).'M'));
            $effectiveEnd   = $at->add(new DateInterval('PT'.max(1, $windowMinutes).'M'));

            if ($windowEnd < $effectiveStart || $from > $effectiveEnd) {
                continue;
            }

            if ($symbol !== null && isset($event['symbols']) && \is_array($event['symbols'])) {
                $symbols = array_map('strtoupper', $event['symbols']);
                if (!in_array(strtoupper($symbol), $symbols, true)) {
                    continue;
                }
            }

            $this->highconviction->info('[HC] Macro event detected in window', [
                'now' => $from->format(DateTimeImmutable::ATOM),
                'event' => $event,
            ]);

            return ['no_event' => false, 'blocking_event' => $event + ['at' => $at->format(DateTimeImmutable::ATOM)]];
        }

        return ['no_event' => true, 'blocking_event' => null];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function loadEvents(): array
    {
        $path = $this->projectDir.self::CONFIG_PATH;
        if (!is_file($path)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($path) ?? [];
        } catch (\Throwable $error) {
            $this->highconviction->warning('[HC] Failed to parse macro calendar file', [
                'path' => $path,
                'error' => $error->getMessage(),
            ]);
            return [];
        }

        $events = $parsed['events'] ?? [];
        return is_array($events) ? array_values(array_filter($events, 'is_array')) : [];
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? max(1, (int) $value) : 60;
    }
}
