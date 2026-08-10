<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'long', name: 'macd_hist_increasing_n')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'macd_hist_increasing_n')]

final class MacdHistIncreasingNCondition extends AbstractCondition
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.conditionsLogger')]
        private readonly LoggerInterface $conditionsLogger,
        private int $defaultN = 2,
    ) {}

    public function getName(): string { return 'macd_hist_increasing_n'; }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $nRaw = $context['macd_hist_increasing_n'] ?? $this->defaultN;
        $n = is_numeric($nRaw) ? max(1, min(50, (int) $nRaw)) : $this->defaultN;
        $seriesOrder = $context['series_order'] ?? null;
        if ($seriesOrder !== 'oldest_to_newest') {
            return $this->failMissing($context, $n, 'invalid_series_order', [
                'series_order' => $seriesOrder,
                'required_series_order' => 'oldest_to_newest',
            ]);
        }
        $rawSeries = $context['macd_hist_series'] ?? null;
        if (!is_array($rawSeries)) {
            return $this->failMissing($context, $n, 'missing_series', [
                'series_type' => get_debug_type($rawSeries),
            ]);
        }
        $series = array_values($rawSeries);
        if (count($series) < $n + 1) {
            return $this->failMissing($context, $n, 'insufficient_points', [
                'series_count' => count($series),
                'required' => $n + 1,
            ]);
        }
        $slice = array_slice($series, -($n + 1));
        $normalized = [];
        foreach ($slice as $index => $value) {
            if (!is_int($value) && !is_float($value)) {
                return $this->failMissing($context, $n, 'non_numeric', [
                    'idx' => $index,
                    'value_type' => get_debug_type($value),
                ]);
            }
            $value = (float) $value;
            if (!is_finite($value)) {
                return $this->failMissing($context, $n, 'non_finite', ['idx' => $index]);
            }
            $normalized[] = $value;
        }

        $passed = true;
        $failedAt = null;
        for ($index = 1; $index <= $n; $index++) {
            if (!($normalized[$index] > $normalized[$index - 1])) {
                $passed = false;
                $failedAt = $index;
                break;
            }
        }
        $latest = $normalized[$n];
        $previous = $normalized[$n - 1];
        $lastStep = $latest - $previous;
        $result = $this->result($this->getName(), $passed, $lastStep, 0.0, $this->baseMeta($context, [
            'points_considered' => count($normalized),
            'required_increases' => $n,
            'series_order' => $seriesOrder,
            'latest' => $latest,
            'previous' => $previous,
            'last_step' => $lastStep,
            'failed_at' => $failedAt,
            'latest_e' => sprintf('%.18e', $latest),
            'previous_e' => sprintf('%.18e', $previous),
            'last_step_e' => sprintf('%.18e', $lastStep),
        ]));

        if (!$passed) {
            $this->logFailure($context, $lastStep, 0.0, 'not_increasing_enough', [
                'points_considered' => count($normalized),
                'required_increases' => $n,
                'latest' => $latest,
                'previous' => $previous,
                'failed_at' => $failedAt,
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function failMissing(array $context, int $n, string $reason, array $extra): ConditionResult
    {
        $this->logFailure($context, null, 0.0, $reason, $extra);

        return $this->result($this->getName(), false, null, 0.0, $this->baseMeta($context, array_merge([
            'missing_data' => true,
            'reason' => $reason,
            'required_increases' => $n,
            'source' => 'MACD',
        ], $extra)));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function logFailure(array $context, ?float $value, ?float $threshold, string $reason, array $extra = []): void
    {
        $this->conditionsLogger->info('[Condition] macd_hist_increasing_n failed', array_merge([
            'symbol' => $context['symbol'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
            'value' => $value,
            'threshold' => $threshold,
            'reason' => $reason,
        ], $extra));
    }
}
