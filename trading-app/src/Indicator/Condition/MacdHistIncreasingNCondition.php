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

    public function evaluate(array $context): ConditionResult
    {
        $series = $context['macd_hist_last3'] ?? null;
        $n = $context['macd_hist_increasing_n'] ?? $this->defaultN;
        if (!is_array($series)) {
            $this->logFailure($context, null, (float) $n, 'missing_data');
            return $this->result($this->getName(), false, null, (float) $n, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'MACD',
            ]));
        }
        $count = count($series);
        if ($count < 2) {
            $this->logFailure($context, null, (float) $n, 'insufficient_points', [
                'points_considered' => $count,
            ]);
            return $this->result($this->getName(), false, null, (float) $n, $this->baseMeta($context, [
                'insufficient_points' => true,
            ]));
        }
        // Vérifier les dernières n hausses consécutives
        $required = max(1, (int) $n);
        $inc = 0;
        for ($i = $count - 2; $i >= 0 && $inc < $required; $i--) {
            if (!is_float($series[$i]) || !is_float($series[$i+1])) {
                break;
            }
            if ($series[$i+1] > $series[$i]) {
                $inc++;
            } else {
                break;
            }
        }
        $passed = ($inc >= $required);
        $latest = $count >= 1 ? ($series[$count - 1] ?? null) : null;
        $previous = $count >= 2 ? ($series[$count - 2] ?? null) : null;
        $lastStep = (is_float($latest) && is_float($previous)) ? ($latest - $previous) : null;
        $result = $this->result($this->getName(), $passed, $lastStep, (float) $required, $this->baseMeta($context, [
            'points_considered' => $count,
            'required_increases' => $required,
            'latest' => $latest,
            'previous' => $previous,
            'last_step' => $lastStep,
            'latest_e' => is_float($latest) ? sprintf('%.18e', $latest) : null,
            'previous_e' => is_float($previous) ? sprintf('%.18e', $previous) : null,
            'last_step_e' => is_float($lastStep) ? sprintf('%.18e', $lastStep) : null,
        ]));

        if (!$passed) {
            $this->logFailure($context, $lastStep, (float) $required, 'not_increasing_enough', [
                'points_considered' => $count,
                'required_increases' => $required,
                'latest' => $latest,
                'previous' => $previous,
            ]);
        }

        return $result;
    }

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
