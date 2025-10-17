<?php
declare(strict_types=1);

namespace App\Risk;

use App\Service\Trading\BitmartAccountGateway;

final class PositionSizer
{
    public function __construct(private readonly BitmartAccountGateway $trading) {}

    /**
     * @return array{qty: float, leverage: int, stop_pct: float, risk_used: float, caps: array<string, float>}
     */
    public function size(
        float $entryPrice,
        float $atr,
        float $riskPct = 0.05,      // ex: 0.05 = 5%
        float $kAtr = 1.5,          // SL = k * ATR
        bool  $useBudgetCap100 = true,
        int   $maxLeverage = 25,    // sécurité
        float $feeBuffer = 0.001,   // 0.1% pour couvrir frais/slippage
        int   $stepSizeContracts = 1
    ): array {
        if ($entryPrice <= 0.0 || $atr <= 0.0) {
            throw new \InvalidArgumentException('entryPrice/ATR must be > 0');
        }
        $stopPct = ($kAtr * $atr) / $entryPrice;        // fraction (ex: 0.0107)
        if ($stopPct <= 0.0) {
            throw new \RuntimeException('Computed stop_pct <= 0');
        }

        // ---- Budget "risque"
        $equity   = $this->trading->getEquity();
        $riskUSDT = $equity * $riskPct;                 // ex: 10000 * 0.02 = 200
        if ($useBudgetCap100) {
            $avail = floor($this->trading->getAvailableUSDT()); // futures "available"
            $minRisk = 5.0;
            $budget  = min(max($avail, $minRisk), 100.0);       // cap utilisateur
            $riskUSDT = min($riskUSDT, $budget);                // ex: 26
        } else {
            $avail = null;
            $budget = $riskUSDT;
        }

        // ---- Qty par R (risque)
        $riskPerUnit = $entryPrice * $stopPct;          // USDT de perte par contrat si SL touché
        if ($riskPerUnit <= 0.0) {
            throw new \RuntimeException('Risk per unit <= 0');
        }
        $qtyByRisk = $riskUSDT / $riskPerUnit;          // ex: 26 / 0.002415 = 10765

        // ---- Levier "théorique" dérivé du risque (peut être insuffisant côté marge)
        $levTheoretical = (int)ceil($riskPct / $stopPct);       // ex: ceil(0.02/0.01075)=2
        $lev = max(1, min($levTheoretical, $maxLeverage));

        // ---- Cap par MARGE (notionnel/leverage)
        // marge req ~ (qty * entry) / lev * (1 + feeBuffer)
        $available = (float)($avail ?? $this->trading->getAvailableUSDT());
        $den = $entryPrice * (1.0 + $feeBuffer);
        $qtyByMargin = $den > 0.0 ? floor(($available * $lev) / $den) : 0.0; // entier contrats

        // ---- Prendre le plus restrictif
        $qtyRaw = floor(min($qtyByRisk, $qtyByMargin));

        // ---- Quantization (step contrats)
        if ($stepSizeContracts > 1) {
            $qtyRaw = floor($qtyRaw / $stepSizeContracts) * $stepSizeContracts;
        }

        if ($qtyRaw <= 0.0) {
            throw new \RuntimeException(sprintf(
                "Taille calculée nulle. Avail=%.4f, lev=%dx, entry=%.8f, qtyRisk=%.4f, qtyMargin=%.4f. " .
                "Augmente available, leverage, ou réduis le risque (kAtr) / entry.",
                (float)$available, $lev, $entryPrice, $qtyByRisk, $qtyByMargin
            ));
        }

        // Option : si tu souhaites essayer d’augmenter le levier pour débloquer la marge,
        // tout en respectant $maxLeverage, dé-commente ce bloc :

        while ($qtyRaw <= 0 && $lev < $maxLeverage) {
            $lev++;
            $qtyByMargin = $den > 0.0 ? floor(($available * $lev) / $den) : 0.0;
            $qtyRaw = floor(min($qtyByRisk, $qtyByMargin));
            if ($stepSizeContracts > 1) {
                $qtyRaw = floor($qtyRaw / $stepSizeContracts) * $stepSizeContracts;
            }
        }
        if ($qtyRaw <= 0.0) {
            throw new \RuntimeException("Impossible de trouver une taille > 0 même au levier max={$maxLeverage}.");
        }


        $out = [
            'qty'       => (float)$qtyRaw,
            'leverage'  => $lev,
            'stop_pct'  => $stopPct,
            'risk_used' => $riskUSDT,
            'caps'      => [
                'qty_by_risk'   => $qtyByRisk,
                'qty_by_margin' => $qtyByMargin,
                'available'     => $available,
            ],
        ];
         //dd($out); // debug si besoin
        return $out;
    }

}
