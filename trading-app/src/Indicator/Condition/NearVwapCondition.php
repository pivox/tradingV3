<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: null, name: 'near_vwap')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'near_vwap')]

final class NearVwapCondition extends AbstractCondition
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.conditionsLogger')]
        private readonly LoggerInterface $conditionsLogger,
        private float $defaultTolerance = 0.0015,
    ) {}

    public function getName(): string { return 'near_vwap'; }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $vwap = $context['vwap'] ?? null;
        if (!is_float($close) || !is_float($vwap) || $vwap == 0.0) {
            $meta = $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'VWAP',
            ]);
            $this->logFailure($context, null, $this->defaultTolerance, 'missing_data', [
                'has_close' => is_float($close),
                'has_vwap' => is_float($vwap),
            ]);
            return $this->result($this->getName(), false, null, $this->defaultTolerance, $meta);
        }
        $tol = $context['near_vwap_tolerance'] ?? $this->defaultTolerance;
        if (!is_float($tol)) $tol = (float) $tol;
        $ratio = abs(($close / $vwap) - 1.0);
        $passed = $ratio <= $tol;
        if (!$passed) {
            $this->logFailure($context, $ratio, $tol, 'threshold', [
                'close' => $close,
                'vwap' => $vwap,
            ]);
        }
        return $this->result($this->getName(), $passed, $ratio, $tol, $this->baseMeta($context, [
            'vwap' => $vwap,
            'tolerance' => $tol,
        ]));
    }

    private function logFailure(array $context, ?float $value, ?float $threshold, string $reason, array $extra = []): void
    {
        $this->conditionsLogger->info('[Condition] near_vwap failed', array_merge([
            'symbol' => $context['symbol'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
            'value' => $value,
            'threshold' => $threshold,
            'reason' => $reason,
        ], $extra));
    }
}

