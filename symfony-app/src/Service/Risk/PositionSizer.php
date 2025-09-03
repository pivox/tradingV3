<?php

declare(strict_types=1);


namespace App\Service\Risk;


use App\Service\Indicator\AtrCalculator;


/**
 * Position sizing with fixed risk %, ATR-based stop, leverage derived (not input).
 * Includes quantization (tick/step), TP1 2R split, and a liquidation guard.
 */
final class PositionSizer
{
    public function __construct(
        private readonly AtrCalculator $atrCalculator,
        private readonly float         $tickSize = 0.01,
        private readonly float         $stepSize = 0.001,
        private readonly float         $fallbackMaxLeverage = 10.0,
        private readonly float         $minLiqRatio = 3.0,
    )
    {
    }

    /**
     * @param 'long'|'short' $side
     * @param array<int, array{high: float, low: float, close: float}> $ohlcExecutionTF recent candles for ATR
     * @return array{
     * qty: float, stop: float, tp1: float, leverage: float, risk_amount: float, r_multiple: float
     * }
     */
    public function size(
        string $side,
        float  $equity,
        float  $riskPct, float $entry,
        array  $ohlcExecutionTF,
        int    $atrPeriod = 14,
        string $atrMethod = 'wilder',
        float  $atrK = 1.5,
        ?float $liqPrice = null
    ): array
    {
        if ($equity <= 0 || $entry <= 0) {
            throw new \InvalidArgumentException('Equity and entry must be > 0');
        }
        $riskAmount = $equity * ($riskPct / 100.0);
        $atr = $this->atrCalculator->compute($ohlcExecutionTF, $atrPeriod, $atrMethod);
        $stop = $this->atrCalculator->stopFromAtr($entry, $atr, $atrK, $side);


        $stopDist = abs($entry - $stop);
        if ($stopDist <= 0) {
            throw new \RuntimeException('Invalid stop distance');
        }


// qty from risk = qty * stopDist
        $qty = $riskAmount / $stopDist;


// Quantize quantity
        $qty = floor($qty / $this->stepSize) * $this->stepSize;
        if ($qty <= 0) {
            throw new \RuntimeException('Quantity quantized to zero; increase equity or reduce risk');
        }


// Derived leverage (rough: notional/ equity)
        $notional = $qty * $entry;
        $leverage = $notional / max(1e-12, $equity);


// Liquidation guard: prefer geometric definition if liqPrice provided, else fallback cap
        if ($liqPrice !== null && $liqPrice > 0.0) {
            $distToLiq = abs($entry - $liqPrice);
            $ratio = $distToLiq / $stopDist; // must be >= minLiqRatio
            if ($ratio < $this->minLiqRatio) {
// Downsize to satisfy ratio
                $scale = max(0.1, $ratio / $this->minLiqRatio);
                $qty = floor(($qty * $scale) / $this->stepSize) * $this->stepSize;
                $notional = $qty * $entry;
                $leverage = $notional / max(1e-12, $equity);
            }
        } else {
            if ($leverage > $this->fallbackMaxLeverage) {
                $scale = $this->fallbackMaxLeverage / $leverage;
                $qty = floor(($qty * $scale) / $this->stepSize) * $this->stepSize;
                $notional = $qty * $entry;
                $leverage = $notional / max(1e-12, $equity);
            }
        }


// TP1 = entry +/- 2R
        $r = $stopDist; // 1R
        $tp1 = $side === 'long' ? $entry + 2.0 * $r : $entry - 2.0 * $r;


        return [
            'qty' => $qty,
            'stop' => $stop,
            'tp1' => $tp1,
            'leverage' => $leverage,
            'risk_amount' => $riskAmount,
            'r_multiple' => 2.0,
        ];
    }
}
