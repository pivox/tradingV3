<?php


declare(strict_types=1);


namespace App\Service\Trading;


/**
 * Construit un plan d'ordres pour le scalping à partir des éléments calculés par PositionSizer.
 * - Quantization prix (tick) & quantité (step)
 * - Split TP1 (portion) + runner
 * - Respect postOnly / reduceOnly
 */
final class OrderPlanner
{
    public function __construct(
        private readonly float $tickSize = 0.01, // granularité de prix
        private readonly float $stepSize = 0.001 // granularité de quantité
    )
    {
    }


    /**
     * Construit le plan OCO (TP1 + SL) avec split de la position.
     * @param 'long'|'short' $side
     */
    public function buildScalpingPlan(
        string $symbol,
        string $side,
        float  $entry,
        float  $qty,
        float  $stop,
        float  $tp1,
        float  $tp1Portion = 0.60,
        bool   $postOnly = true,
        bool   $reduceOnly = true,
    ): ScalpingPlan
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Qty must be > 0');
        }
        if (!in_array($side, ['long', 'short'], true)) {
            throw new \InvalidArgumentException('Side must be long|short');
        }


// Quantization prix selon le tick size
        $qEntry = $this->quantizePrice($entry);
        $qStop = $this->quantizePrice($stop);
        $qTp1 = $this->quantizePrice($tp1);


// Quantization quantité + split TP1/Runner
        $qQty = $this->quantizeQty($qty);
        $rawTpQty = $qQty * $tp1Portion;
        $tp1Qty = $this->quantizeQty(max(0.0, min($qQty, $rawTpQty)));
        $runner = $this->quantizeQty(max(0.0, $qQty - $tp1Qty));


        if ($tp1Qty + $runner <= 0.0) {
            throw new \RuntimeException('Quantities collapsed to zero after quantization');
        }


// Sécurité directionnelle: SL et TP doivent être du bon côté de l'entrée
        if ($side === 'long') {
            if (!($qStop < $qEntry && $qTp1 > $qEntry)) {
                throw new \RuntimeException('Long: stop must be < entry and TP1 > entry');
            }
        } else { // short
            if (!($qStop > $qEntry && $qTp1 < $qEntry)) {
                throw new \RuntimeException('Short: stop must be > entry and TP1 < entry');
            }
        }


        return new ScalpingPlan(
            symbol: $symbol,
            side: $side,
            entryPrice: $qEntry,
            totalQty: $qQty,
            tp1Price: $qTp1,
            stopPrice: $qStop,
            tp1Qty: $tp1Qty,
            runnerQty: $runner,
            postOnly: $postOnly,
            reduceOnly: $reduceOnly,
        );
    }


    private function quantizePrice(float $price): float
    {
        if ($this->tickSize <= 0) {
            return $price;
        }
        return floor($price / $this->tickSize) * $this->tickSize;
    }


    private function quantizeQty(float $qty): float
    {
        if ($this->stepSize <= 0) {
            return $qty;
        }
        return floor($qty / $this->stepSize) * $this->stepSize;
    }
}
