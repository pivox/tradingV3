<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsIndicatorCondition(timeframes: ['15m'], name: VolumeRatioOkCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: VolumeRatioOkCondition::NAME)]
final class VolumeRatioOkCondition extends AbstractCondition
{
    public const NAME = 'volume_ratio_ok';
    private const DEFAULT_THRESHOLD = 1.8;
    private const EPS = 1.0e-9;

    public function __construct(
        #[Autowire(service: 'monolog.logger.mtf')]
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $threshold = $this->resolveThreshold($context);
        $ratio = $context['volume_ratio'] ?? null;

        if (!\is_numeric($ratio)) {
            $this->logger->info('[MtfValidation] volume_ratio_ok missing data', [
                'symbol' => $context['symbol'] ?? null,
                'timeframe' => $context['timeframe'] ?? null,
            ]);
            return $this->result(self::NAME, false, null, $threshold, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $ratio = (float) $ratio;
        $passed = ($ratio + self::EPS) >= $threshold;

        $this->logger->info('[MtfValidation] volume_ratio_ok', [
            'symbol' => $context['symbol'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
            'value' => $ratio,
            'threshold' => $threshold,
            'passed' => $passed,
        ]);

        return $this->result(self::NAME, $passed, $ratio, $threshold, $this->baseMeta($context, [
            'source' => 'volume_ratio',
        ]));
    }

    private function resolveThreshold(array $context): float
    {
        $override = $context['volume_ratio_ok_threshold'] ?? $context['volume_ratio_threshold'] ?? null;
        if (\is_numeric($override)) {
            return (float) $override;
        }

        return self::DEFAULT_THRESHOLD;
    }
}
