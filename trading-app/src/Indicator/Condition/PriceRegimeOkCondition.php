<?php
namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: null, name: 'price_regime_ok')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'price_regime_ok')]

final class PriceRegimeOkCondition extends AbstractCondition
{
    public function __construct(
        private readonly float $adxMin = 20.0,
        private readonly float $eps = 1e-9, // tolérance
        private readonly int $adxPeriodKey = 14 // ex: $context['adx'][14]
    ) {}

    public function getName(): string { return 'price_regime_ok'; }

    public function evaluate(array $context): ConditionResult
    {
        $close  = $context['close'] ?? null;
        $ema50  = $context['ema'][50] ?? null;
        $ema200 = $context['ema'][200] ?? null;
        $adx    = $context['adx'][$this->adxPeriodKey] ?? $context['adx'] ?? null; // tolère 2 schémas

        if (!is_float($close) || !is_float($ema50) || !is_float($ema200) || !is_float($adx)) {
            return $this->result($this->getName(), false, null, null,
                $this->baseMeta($context, ['missing_data' => true]));
        }

        $above200 = ($close - $ema200) > $this->eps;
        $above50  = ($close - $ema50)  > $this->eps;
        $ok = $above200 || ($above50 && $adx >= $this->adxMin);

        return $this->result(
            $this->getName(),
            $ok,
            $ok ? 1.0 : 0.0,
            null,
            $this->baseMeta($context, [
                'close' => $close,
                'ema50' => $ema50,
                'ema200'=> $ema200,
                'adx'   => $adx,
                'adxMin'=> $this->adxMin,
                'above200'=>$above200,
                'above50'=>$above50,
            ])
        );
    }
}
