<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsIndicatorCondition(timeframes: ['1m', '5m', '15m', '1h', '4h'], side: 'short', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class MacdHistDecreasingNCondition extends AbstractCondition
{
    public const NAME = 'macd_hist_decreasing_n';

    public function __construct(
        #[Autowire(service: 'monolog.logger.conditionsLogger')]
        private readonly LoggerInterface $conditionsLogger,
    ) {}

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return 'Histogramme MACD décroissant sur N pas (bougies clôturées). Anti-bruit via eps.';
    }

    public function evaluate(array $context): ConditionResult
    {
        $rawSeries = $context['macd_hist_series'] ?? null;

        $n = $this->asInt($context['n'] ?? $context['macd_hist_decreasing_n'] ?? 2, 2, 1, 50);

        // eps = diminution minimale par pas (anti-bruit). eps=0 => "strictement décroissant".
        $eps = $this->asFloat($context['eps'] ?? $context['macd_hist_decreasing_eps'] ?? 0.0, 0.0, 0.0, 1e9);

        // Si ton indicateur renvoie oldest-first, mets series_order=oldest_first côté moteur/YAML.
        $seriesOrder = (string)($context['series_order'] ?? $context['macd_hist_series_order'] ?? 'latest_first');
        $seriesOrder = \in_array($seriesOrder, ['latest_first', 'oldest_first'], true) ? $seriesOrder : 'latest_first';

        if (!\is_array($rawSeries)) {
            return $this->failMissing($context, $n, $eps, 'missing_series', [
                'series_type' => \get_debug_type($rawSeries),
            ]);
        }

        $series = \array_values($rawSeries);
        if ($seriesOrder === 'oldest_first') {
            $series = \array_reverse($series);
        }

        if (\count($series) < ($n + 1)) {
            return $this->failMissing($context, $n, $eps, 'missing_data', [
                'series_count' => \count($series),
                'required' => $n + 1,
                'series_order' => $seriesOrder,
            ]);
        }

        // Normaliser uniquement ce qu'on consomme (n+1 points)
        $slice = \array_slice($series, 0, $n + 1);
        $norm = [];
        foreach ($slice as $i => $v) {
            if (!\is_numeric($v)) {
                return $this->failMissing($context, $n, $eps, 'non_numeric', [
                    'idx' => $i,
                    'value_raw' => $v,
                    'value_type' => \get_debug_type($v),
                    'series_order' => $seriesOrder,
                ]);
            }
            $norm[$i] = (float) $v;
        }

        $passed = true;
        $failedAt = null;
        $failedA = null;
        $failedB = null;
        $failedDelta = null;

        // latest-first : on veut a < b - eps  <=>  delta = a - b < -eps
        for ($i = 0; $i < $n; $i++) {
            $a = $norm[$i];
            $b = $norm[$i + 1];
            $delta = $a - $b;

            if (!($delta < -$eps)) {
                $passed = false;
                $failedAt = $i;
                $failedA = $a;
                $failedB = $b;
                $failedDelta = $delta;
                break;
            }
        }

        $avgStep = ($norm[0] - $norm[$n]) / \max(1, $n);
        $lastStep = $norm[0] - $norm[1];

        $meta = $this->baseMeta($context, [
            'n' => $n,
            'eps' => $eps,
            'series_order' => $seriesOrder,

            'avg_step' => $avgStep,
            'last_step' => $lastStep,

            'latest' => $norm[0],
            'previous' => $norm[1],
            'nth' => $norm[$n],

            'failed_at' => $failedAt,
            'failed_a' => $failedA,
            'failed_b' => $failedB,
            'failed_delta' => $failedDelta,

            'required' => 'delta(a-b) < -eps',
            'avg_step_e' => sprintf('%.18e', $avgStep),
            'last_step_e' => sprintf('%.18e', $lastStep),
            'source' => 'MACD',
        ]);

        // Dans ConditionResult: threshold = eps (pas n)
        $result = $this->result(self::NAME, $passed, $avgStep, $eps, $meta);

        if (!$passed) {
            $this->conditionsLogger->info('[Condition] macd_hist_decreasing_n failed', [
                'symbol' => $context['symbol'] ?? null,
                'timeframe' => $context['timeframe'] ?? null,

                'n' => $n,
                'eps' => $eps,
                'series_order' => $seriesOrder,

                'a' => $failedA,
                'b' => $failedB,
                'delta' => $failedDelta,
                'delta_e' => \is_float($failedDelta) ? sprintf('%.18e', $failedDelta) : null,

                'required' => 'delta(a-b) < -eps',
                'reason' => 'not_decreasing_enough',
            ]);
        }

        return $result;
    }

    private function failMissing(array $context, int $n, float $eps, string $reason, array $extra): ConditionResult
    {
        $this->conditionsLogger->info('[Condition] macd_hist_decreasing_n failed', \array_merge([
            'symbol' => $context['symbol'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
            'n' => $n,
            'eps' => $eps,
            'reason' => $reason,
        ], $extra));

        return $this->result(self::NAME, false, null, $eps, $this->baseMeta($context, \array_merge([
            'missing_data' => true,
            'n' => $n,
            'eps' => $eps,
            'source' => 'MACD',
        ], $extra)));
    }

    private function asInt(mixed $v, int $default, int $min, int $max): int
    {
        if (!\is_numeric($v)) {
            return $default;
        }
        $i = (int) $v;
        return \max($min, \min($max, $i));
    }

    private function asFloat(mixed $v, float $default, float $min, float $max): float
    {
        if (!\is_numeric($v)) {
            return $default;
        }
        $f = (float) $v;
        if (!\is_finite($f)) {
            return $default;
        }
        return \max($min, \min($max, $f));
    }
}
