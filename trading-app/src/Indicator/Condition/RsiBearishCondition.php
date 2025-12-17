<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsIndicatorCondition(timeframes: ['15m', '5m', '1m'], side: 'short', name: 'rsi_bearish')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'rsi_bearish')]
final class RsiBearishCondition extends AbstractCondition
{
    private const NAME = 'rsi_bearish';
    private const DEFAULT_THRESHOLD = 48.0;

    public function __construct(
        #[Autowire(service: 'monolog.logger.conditionsLogger')]
        private readonly LoggerInterface $conditionsLogger,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $rsi = $context['rsi'] ?? null;
        if (!is_float($rsi)) {
            $this->logFailure($context, null, self::DEFAULT_THRESHOLD, 'missing_data');
            return $this->result(self::NAME, false, null, self::DEFAULT_THRESHOLD, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'RSI',
            ]));
        }

        $threshold = $context['threshold']
            ?? $context['rsi_bearish_threshold']
            ?? self::DEFAULT_THRESHOLD;
        $threshold = (float) $threshold;

        $passed = $rsi < $threshold;

        if (!$passed) {
            $this->logFailure($context, $rsi, $threshold, 'threshold');
        }

        return $this->result(self::NAME, $passed, $rsi, $threshold, $this->baseMeta($context, [
            'source' => 'RSI',
        ]));
    }

    private function logFailure(array $context, ?float $value, ?float $threshold, string $reason): void
    {
        $this->conditionsLogger->info('[Condition] rsi_bearish failed', [
            'symbol' => $context['symbol'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
            'value' => $value,
            'threshold' => $threshold,
            'reason' => $reason,
        ]);
    }
}
