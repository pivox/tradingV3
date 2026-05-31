<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MtfValidationEngineMetrics
{
    public const CONDITION_REGISTRY_FALLBACK_COUNT = 'mtf.validation.engine.fallback_count';

    /** @var array<string,int> */
    private array $counters = [
        self::CONDITION_REGISTRY_FALLBACK_COUNT => 0,
    ];

    public function __construct(
        #[Autowire(service: 'monolog.logger.mtf')]
        private readonly LoggerInterface $mtfLogger,
        #[Autowire('%env(int:MTF_VALIDATION_FALLBACK_ALERT_THRESHOLD)%')]
        private readonly int $fallbackAlertThreshold = 1,
    ) {
    }

    public function recordConditionRegistryFallback(
        string $symbol,
        string $timeframe,
        string $phase,
        ?string $mode,
        \Throwable $error,
    ): int {
        $count = $this->increment(self::CONDITION_REGISTRY_FALLBACK_COUNT);
        $threshold = max(1, $this->fallbackAlertThreshold);
        $context = [
            'metric' => self::CONDITION_REGISTRY_FALLBACK_COUNT,
            'fallback_count' => $count,
            'alert_threshold' => $threshold,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'phase' => $phase,
            'mode' => $mode,
            'source_engine' => 'condition_registry',
            'fallback_engine' => 'yaml',
            'exception' => $error::class,
            'error' => $error->getMessage(),
        ];

        $this->mtfLogger->warning('[MTF] Validation engine fallback recorded', $context);

        if ($count >= $threshold) {
            $this->mtfLogger->critical('[MTF] Validation engine fallback threshold reached', $context + [
                'alert' => self::CONDITION_REGISTRY_FALLBACK_COUNT,
            ]);
        }

        return $count;
    }

    /**
     * @return array<string,int>
     */
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

    private function increment(string $metric): int
    {
        if (!isset($this->counters[$metric])) {
            $this->counters[$metric] = 0;
        }

        return ++$this->counters[$metric];
    }
}
