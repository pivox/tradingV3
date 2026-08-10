<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: null, name: 'rsi_gt_30')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'rsi_gt_30')]

final class RsiGt30Condition extends AbstractCondition
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.conditionsLogger')]
        private readonly LoggerInterface $conditionsLogger,
    ) {}

    public function getName(): string { return 'rsi_gt_30'; }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $rsi = $context['rsi'] ?? null;
        $threshold = (float) ($context['rsi_threshold'] ?? 30.0);
        if (!is_float($rsi)) {
            $this->logFailure($context, null, $threshold, 'missing_data');
            return $this->result($this->getName(), false, null, $threshold, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $rsi > $threshold;
        if (!$passed) {
            $this->logFailure($context, $rsi, $threshold, 'threshold');
        }
        return $this->result($this->getName(), $passed, $rsi, $threshold, $this->baseMeta($context, ['source' => 'RSI']));
    }

    /** @param array<string, mixed> $context */
    private function logFailure(array $context, ?float $value, ?float $threshold, string $reason): void
    {
        $this->conditionsLogger->info('[Condition] rsi_gt_30 failed', [
            'symbol' => $context['symbol'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
            'value' => $value,
            'threshold' => $threshold,
            'reason' => $reason,
        ]);
    }
}
