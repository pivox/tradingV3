<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['4h', '1h', '15m', '5m'], name: AdxMinForTrendCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: AdxMinForTrendCondition::NAME)]
final class AdxMinForTrendCondition extends AbstractCondition
{
    public const NAME = 'adx_min_for_trend';

    public function __construct(private readonly float $minAdx = 15.0) {}

    public function getName(): string
    {
        return self::NAME;
    }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $tf = $context['timeframe'] ?? null;

        // ADX(14) is the dedicated value emitted by IndicatorContextBuilder.
        $adxRaw = is_array($context['adx'] ?? null) ? ($context['adx'][14] ?? null) : null;
        if ($adxRaw === null && $tf === '1h' && !is_array($context['adx'] ?? null)) {
            $adxRaw = $context['adx'] ?? null;
        }
        $adx = $this->toFloatOrNull($adxRaw);

        if ($adx === null) {
            return $this->result(
                self::NAME,
                false,
                null,
                $this->minAdx,
                $this->baseMeta($context, [
                    'missing_data' => true,
                    'timeframe'    => $tf,
                ]),
            );
        }

        // 2) Nettoyage de la valeur (plage ADX: 0..100)
        if (!is_finite($adx)) {
            return $this->result(
                self::NAME,
                false,
                null,
                $this->minAdx,
                $this->baseMeta($context, [
                    'invalid_numeric' => true,
                    'timeframe'       => $tf,
                ]),
            );
        }
        $adx = max(0.0, min(100.0, $adx));

        // 3) Seuil (context override → sinon défaut)
        $threshold = $this->minAdx;
        $source = 'default';
        if (isset($context['threshold']) && is_numeric($context['threshold'])) {
            $threshold = (float) $context['threshold'];
            $source = 'context';
        }

        $passed = $adx >= $threshold;

        return $this->result(
            self::NAME,
            $passed,
            $adx,
            $threshold,
            $this->baseMeta($context, [
                'adx_14'           => $adx,
                'threshold_source' => $source,
                'timeframe'        => $tf,
            ]),
        );
    }

    /**
     * @param mixed $v
     */
    private function toFloatOrNull(mixed $v): ?float
    {
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        if (is_string($v) && $v !== '' && is_numeric($v)) {
            return (float) $v;
        }
        return null;
    }
}
