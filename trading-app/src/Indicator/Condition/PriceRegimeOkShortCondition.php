<?php
namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1h', '4h'], side: 'short', name: 'price_regime_ok_short')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'price_regime_ok_short')]

final class PriceRegimeOkShortCondition extends AbstractCondition
{
    public function __construct(
        private readonly float $adxMin = 20.0,
        private readonly float $eps = 1e-9,
        private readonly int $adxPeriodKey = 14
    ) {}

    public function getName(): string { return 'price_regime_ok_short'; }

    public function evaluate(array $context): ConditionResult
    {
        $close  = $context['close'] ?? null;
        $ema50  = $context['ema'][50] ?? null;
        $ema200 = $context['ema'][200] ?? null;
        $adx    = $context['adx'][$this->adxPeriodKey] ?? $context['adx'] ?? null;

        if (!is_float($close) || !is_float($ema50) || !is_float($ema200) || !is_float($adx)) {
            return $this->result($this->getName(), false, null, null,
                $this->baseMeta($context, ['missing_data' => true]));
        }

        $below200 = ($ema200 - $close) > $this->eps;
        $below50  = ($ema50  - $close) > $this->eps;

        // Directionnel SHORT:
        // OK si close<EMA200  OU  (close<EMA50 ET ADX>=seuil)
        $ok = $below200 || ($below50 && $adx >= $this->adxMin);

        return $this->result(
            $this->getName(),
            $ok,
            $ok ? 1.0 : 0.0,
            null,
            $this->baseMeta($context, [
                'side'     => 'short',
                'close'    => $close,
                'ema50'    => $ema50,
                'ema200'   => $ema200,
                'adx'      => $adx,
                'adxMin'   => $this->adxMin,
                'below200' => $below200,
                'below50'  => $below50,
            ])
        );
    }
}
