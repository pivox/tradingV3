<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsIndicatorCondition(timeframes: ['15m'], side: 'long', name: 'rsi_lt_70')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'rsi_lt_70')]

final class RsiLt70Condition extends AbstractCondition
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.conditionsLogger')]
        private readonly LoggerInterface $conditionsLogger,
    ) {}

    public function getName(): string { return 'rsi_lt_70'; }

    public function evaluate(array $context): ConditionResult
    {
        $threshold = (float)($context['rsi_lt_70_threshold'] ?? 70.0);
        $rsi = $context['rsi'] ?? null;
        if (!is_float($rsi)) {
            $this->logFailure($context, null, $threshold, 'missing_data');
            return $this->result($this->getName(), false, null, $threshold, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'RSI',
            ]));
        }
        $passed = $rsi < $threshold;
        if (!$passed) {
            $this->logFailure($context, $rsi, $threshold, 'threshold');
        }
        return $this->result($this->getName(), $passed, $rsi, $threshold, $this->baseMeta($context, [
            'source' => 'RSI',
        ]));
    }

    private function logFailure(array $context, ?float $value, ?float $threshold, string $reason): void
    {
        $this->conditionsLogger->info('[Condition] rsi_lt_70 failed', [
            'symbol' => $context['symbol'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
            'value' => $value,
            'threshold' => $threshold,
            'reason' => $reason,
        ]);
    }
}
