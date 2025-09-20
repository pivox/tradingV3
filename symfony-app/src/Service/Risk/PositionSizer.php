<?php
declare(strict_types=1);

namespace App\Service\Risk;

final class PositionSizer
{
    /**
     * Calcule la taille à partir d’un risque fixe (%) et d’un SL donné.
     *
     * @return array{
     *   qty: float,
     *   nominal: float,
     *   stopPct: float,
     *   leverage: float,
     *   riskAmount: float
     * }
     */
    public function sizeFromFixedRisk(
        string $symbol,
        string $side,            // 'LONG' | 'SHORT' (non utilisé ici mais conservé pour logs/cohérence)
        float $entryPrice,
        float $stopPrice,
        float $equity,
        float $riskPct,
        float $maxLeverageCap,
        float $stepSize
    ): array {
        if ($entryPrice <= 0.0) {
            throw new \InvalidArgumentException('entryPrice must be > 0');
        }
        if ($equity <= 0.0) {
            throw new \InvalidArgumentException('equity must be > 0');
        }
        if ($riskPct <= 0.0 || $riskPct >= 100.0) {
            throw new \InvalidArgumentException('riskPct must be in (0, 100)');
        }

        // 1) % distance du stop (R en % du prix d’entrée)
        $stopPct = abs($entryPrice - $stopPrice) / $entryPrice; // ex.: 0.01 = 1%
        if ($stopPct <= 0.0) {
            throw new \InvalidArgumentException('stopPct computed as 0; check entry/stop');
        }

        // 2) Montant risqué en devise
        $riskAmount = $equity * ($riskPct / 100.0);

        // 3) Taille "brute" (contrats / coins) pour risquer riskAmount si SL touché
        // Perte par unité ≈ stopPct * entryPrice  =>  qty = riskAmount / (stopPct * entryPrice)
        $qtyRaw = $riskAmount / ($stopPct * $entryPrice);

        // 4) Levier implicite et cap
        // leverage_implicit = (riskPct/100) / stopPct   (comme demandé)
        $levImplicit = ($riskPct / 100.0) / $stopPct;

        if ($maxLeverageCap > 0.0 && $levImplicit > $maxLeverageCap) {
            // On downsize la quantité pour respecter le cap de levier
            $scale = $maxLeverageCap / $levImplicit;
            $qtyRaw *= $scale;
            $levImplicit = $maxLeverageCap;
        }

        // 5) Quantization de la quantité par stepSize (floor)
        $qty = $qtyRaw;
        if ($stepSize > 0.0) {
            $steps = floor($qtyRaw / $stepSize);
            $qty   = $steps * $stepSize;
        }

        // Si arrondi à 0, on ne peut pas trader proprement
        if ($qty <= 0.0) {
            return [
                'qty'        => 0.0,
                'nominal'    => 0.0,
                'stopPct'    => $stopPct,
                'leverage'   => $levImplicit,
                'riskAmount' => $riskAmount,
            ];
        }

        $nominal = $qty * $entryPrice;

        return [
            'qty'        => $qty,
            'nominal'    => $nominal,
            'stopPct'    => $stopPct,
            'leverage'   => $levImplicit,
            'riskAmount' => $riskAmount,
        ];
    }
}
