<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

use App\TradeEntry\Types\Side;

final class TradeEntryRequest
{
    public function __construct(
        public string $symbol,
        public Side $side,
        // Données marché minimales
        public float $entryPriceBase,    // prix de référence (milieu de zone ou meilleur limit)
        public float $atrValue,          // ATR déjà calculé (simple) 
        public float $pivotPrice,        // VWAP ou MA21 (passé en entrée pour rester simple)
        // Gestion du risque
        public float $riskPct,           // ex. 2.0 (% capital)
        public float $budgetUsdt,        // marge initiale allouée (ex. 100 USDT)
        public float $equityUsdt,        // capital total (pour risk_abs)
        // Contexte exécution (facultatif)
        public ?float $rsi = null,       // pour filtre RSI<cap
        public ?float $volumeRatio = null, // >=1.5 recommandé
        public ?bool $pullbackConfirmed = null,
        // Contraintes simples
        public float $tickSize = 0.1,    // quantization prix
        // Paramètres de zone/TTL
        public int $zoneTtlSec = 240,    // 3–5 min
        public float $kLow = 1.2,        // zone bas = pivot - kLow*ATR
        public float $kHigh = 0.4,       // zone haut = pivot + kHigh*ATR
        // Paramètres SL/TP
        public float $kStopAtr = 1.5,    // distance SL en ATR
        public float $tp1R = 2.0,        // TP1 = 2R
        public int $tp1SizePct = 60,     // 60% à TP1
        // Caps levier
        public float $levMin = 2.0,
        public float $levMax = 20.0,
        public float $kDynamic = 10.0,   // borne dynamique min(kDynamic/stopPct, levMax)
        // Filtres d'exécution
        public float $rsiCap = 70.0,
        public bool $requirePullback = true,
        public float $minVolumeRatio = 1.5
    ) {}
}
