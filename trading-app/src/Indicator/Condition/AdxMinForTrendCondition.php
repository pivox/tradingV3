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

    public function evaluate(array $context): ConditionResult
    {
        $tf = $context['timeframe'] ?? null;

        // 1) Récupération ADX(1h) souple: accepte float|int|string numériques
        $adx1hRaw = $context['adx'][$this->minAdx] ?? null;
        if ($adx1hRaw === null && $tf === '1h') {
            $adx1hRaw = $context['adx'] ?? null; // fallback sur 1h
        }
        $adx1h = $this->toFloatOrNull($adx1hRaw);

        if ($adx1h === null) {
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
        if (!is_finite($adx1h)) {
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
        $adx1h = max(0.0, min(100.0, $adx1h));

        // 3) Seuil (context override → sinon défaut)
        // Accepte 'threshold' (syntaxe YAML standard) ou 'adx_1h_min_threshold' (legacy)
        $threshold = $this->minAdx;
        $source = 'default';
        if (isset($context['threshold']) && is_numeric($context['threshold'])) {
            $threshold = (float) $context['threshold'];
            $source = 'context';
        } elseif (array_key_exists('adx_1h_min_threshold', $context) && is_numeric($context['adx_1h_min_threshold'])) {
            $threshold = (float) $context['adx_1h_min_threshold'];
            $source = 'context';
        }

        $passed = $adx1h >= $threshold;

        return $this->result(
            self::NAME,
            $passed,
            $adx1h,
            $threshold,
            $this->baseMeta($context, [
                'adx_1h'           => $adx1h,
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
