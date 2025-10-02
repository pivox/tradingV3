<?php
declare(strict_types=1);

namespace App\Service\Risk;

use Psr\Log\LoggerInterface;

/**
 * ReversalGuardService
 *
 * Objectif : détecter un "retournement soudain" (reversal brutal) ET décider d'une action
 *            qui ne ferme pas la position inutilement :
 *            - rien (continuer)
 *            - resserrer le SL (trailing ATR)
 *            - déplacer SL au BreakEven (BE)
 *            - sortie partielle (ex. 50%) + BE
 *            - sortie totale
 *
 * Entrées :
 *   - côté (LONG/SHORT), levier, prix d'entrée, SL courant
 *   - OHLCV (opens, highs, lows, closes, volumes)
 *   - timeframe (1m, 5m, 15m, 1h, 4h) pour adapter les seuils
 *
 * Sortie :
 *   - tableau structuré : trigger, score, reasons[], action, action_meta{ new_stop_loss, partial_pct }, diagnostics{...}
 *
 * Principes clefs anti "fermeture inutile" :
 *   1) Score composite (force du signal) avec seuils
 *   2) Double validation temporelle (2 bougies consécutives) ou seuil fort
 *   3) Cassure structurelle (Donchian/pivot) exigée pour la sortie totale
 *   4) Confirmation volume (spike) et volatilité (corps >= k1*ATR)
 *   5) Sensibilité dynamique selon levier et timeframe (levier faible => plus strict)
 */
final class ReversalGuardService
{
    // =========================
    // === 1) Paramètres généraux
    // =========================

    /** Longueur par défaut des indicateurs */
    private const ATR_PERIOD  = 14;   // ATR(14) classique
    private const RSI_PERIOD  = 14;   // RSI(14)
    private const ADX_PERIOD  = 14;   // ADX(14)
    private const OBV_EMA_K   = 20;   // EMA OBV(20) pour le flip
    private const VOL_SMA_M   = 20;   // SMA du volume pour "volume spike"
    private const DC_P_SCALP  = 10;   // Donchian (1m/5m)
    private const DC_P_HIGHER = 20;   // Donchian (>=15m)
    private const VWAP_WIN_1_5  = 30; // fenêtre de VWAP "rolling" (1m/5m)
    private const VWAP_WIN_15   = 20; // fenêtre (15m)
    private const VWAP_WIN_1H4H = 14; // fenêtre (1h/4h)

    /**
     * Pondérations pour le score composite S_t
     *   w1: corps anormal vs ATR
     *   w2: spike de volume
     *   w3: cassure Donchian/pivot
     *   w4: flip RSI (changement de régime rapide)
     *   w5: flip DI avec ADX>=a_min
     *   w6: OBV vs EMA(OBV)
     *   w7: déviation VWAP significative
     *   w8: mèche de retournement
     */
    private const W = [
        'body_vs_atr'   => 2.0,
        'vol_spike'     => 2.0,
        'donchian'      => 1.5,
        'rsi_flip'      => 1.0,
        'di_flip'       => 1.5,
        'obv_flip'      => 1.0,
        'vwap_dev'      => 1.0,
        'wick_rule'     => 0.5,
    ];

    /**
     * Seuils de score par "famille de timeframe"
     * seuil_base : déclencheur minimal S_t >= seuil_base pour considérer le reversal "valide"
     * seuil_fort : S_t >= seuil_fort => action plus agressive possible (partielle / totale)
     */
    private const SCORE_THRESHOLDS = [
        'scalp'  => ['base' => 5.0, 'fort' => 6.0], // 1m, 5m
        'higher' => ['base' => 6.0, 'fort' => 7.0], // 15m, 1h, 4h
    ];

    /**
     * Paramètres dynamiques selon levier :
     *  - k1 : anomalie de volatilité (corps >= k1 * ATR)
     *  - k2 : spike de volume (volume >= k2 * SMA(volume))
     *  - a_min : ADX minimal pour considérer un DI flip significatif
     *  - d_vwap : déviation relative à partir du VWAP (en proportion, ex. 0.003 = 0.3%)
     *
     * Deux profils :
     *  - "low_lev"  : positions à levier faible (<5x) => critères plus stricts pour éviter de sortir trop vite
     *  - "high_lev" : positions à levier élevé (>=10x) => critères plus sensibles pour réagir plus vite
     *  - "mid_lev"  : zone grise (5x–9x) => interpolation/choix intermédiaire
     */
    private const SENSITIVITY = [
        'low_lev'  => ['k1' => 2.8, 'k2' => 2.0, 'a_min' => 22.0, 'd_vwap' => 0.0035, 'wick_r' => 1.2],
        'mid_lev'  => ['k1' => 2.5, 'k2' => 1.8, 'a_min' => 20.0, 'd_vwap' => 0.0030, 'wick_r' => 1.2],
        'high_lev' => ['k1' => 2.2, 'k2' => 1.6, 'a_min' => 18.0, 'd_vwap' => 0.0025, 'wick_r' => 1.2],
    ];

    /**
     * Politique d'action standard :
     *  - trailing_k : coefficient ATR pour le resserrement du SL (plus petit si levier élevé => plus agressif)
     *  - partial_pct : pourcentage de sortie partielle sur alerte forte et confirmée
     */
    private const ACTION_POLICY = [
        'low_lev'  => ['trailing_k' => 1.8, 'partial_pct' => 0.30],
        'mid_lev'  => ['trailing_k' => 1.4, 'partial_pct' => 0.40],
        'high_lev' => ['trailing_k' => 1.0, 'partial_pct' => 0.50],
    ];

    public const ACTION_NONE          = 'NONE';
    public const ACTION_TIGHTEN_SL    = 'TIGHTEN_SL';
    public const ACTION_MOVE_SL_BE    = 'MOVE_SL_TO_BE';
    public const ACTION_PARTIAL_EXIT  = 'PARTIAL_EXIT_AND_BE';
    public const ACTION_FULL_EXIT     = 'FULL_EXIT';

    public function __construct(
        private readonly LoggerInterface $positionsLogger, // channel "positions" recommandé
    ) {}

    // ===========================================================
    // === 2) API principale : évaluer et proposer un plan d'action
    // ===========================================================

    /**
     * @param string   $symbol         Symbole (ex. "BTCUSDT") — pour log/diagnostic
     * @param string   $timeframe      "1m"|"5m"|"15m"|"1h"|"4h"
     * @param string   $side           "LONG" ou "SHORT"
     * @param float    $entryPrice     Prix d'entrée de la position (utilisé pour BE)
     * @param float|null $currentSL    Stop Loss courant (peut être null si pas encore posé)
     * @param float    $leverage       Levier effectif de la position
     * @param float[]  $opens          Série des prix d'ouverture (ordre chronologique)
     * @param float[]  $highs          Série des plus hauts
     * @param float[]  $lows           Série des plus bas
     * @param float[]  $closes         Série des clôtures
     * @param float[]  $volumes        Série des volumes (même longueur que closes)
     *
     * @return array {
     *   trigger: bool,             // vrai si "reversal soudain" détecté
     *   score: float,              // score composite S_t
     *   reasons: string[],         // liste explicative des conditions validées
     *   action: string,            // NONE|TIGHTEN_SL|MOVE_SL_TO_BE|PARTIAL_EXIT_AND_BE|FULL_EXIT
     *   action_meta: array{ new_stop_loss: ?float, partial_pct: ?float },
     *   diagnostics: array{ ... }  // métriques brutes et seuils utilisés (debug/traçabilité)
     * }
     */
    public function evaluateAndAdvise(
        string $symbol,
        string $timeframe,
        string $side,
        float $entryPrice,
        ?float $currentSL,
        float $leverage,
        array $opens,
        array $highs,
        array $lows,
        array $closes,
        array $volumes
    ): array {
        // === Sécurité basique : longueurs cohérentes ===
        $n = min(count($opens), count($highs), count($lows), count($closes), count($volumes));
        if ($n < max(self::ATR_PERIOD + 2, self::VOL_SMA_M + 2, self::ADX_PERIOD + 2)) {
            return [
                'trigger'     => false,
                'score'       => 0.0,
                'reasons'     => ['Données insuffisantes'],
                'action'      => self::ACTION_NONE,
                'action_meta' => ['new_stop_loss' => null, 'partial_pct' => null],
                'diagnostics' => ['n' => $n],
            ];
        }

        // === Sensibilité selon levier ===
        $levProfile = $this->profileForLeverage($leverage); // 'low_lev' | 'mid_lev' | 'high_lev'
        $sens = self::SENSITIVITY[$levProfile];

        // === Famille timeframe (impacte les seuils de score) ===
        $family = in_array($timeframe, ['1m','5m'], true) ? 'scalp' : 'higher';
        $scoreThresholds = self::SCORE_THRESHOLDS[$family];

        // === Période Donchian et fenêtre VWAP selon TF ===
        $dcP   = in_array($timeframe, ['1m','5m'], true) ? self::DC_P_SCALP : self::DC_P_HIGHER;
        $vwWin = match ($timeframe) {
            '1m','5m' => self::VWAP_WIN_1_5,
            '15m'     => self::VWAP_WIN_15,
            default   => self::VWAP_WIN_1H4H, // 1h, 4h
        };

        // === 2.1 Calcul des indicateurs nécessaires ===

        // ATR série (utilisée pour volatilité et trailing SL)
        $atr = $this->atr($highs, $lows, $closes, self::ATR_PERIOD);
        $atrLast = $atr[$n-1];

        // RSI série (changement de régime)
        $rsi = $this->rsi($closes, self::RSI_PERIOD);

        // ADX & DI (force de tendance + flip directionnel)
        [$adx, $plusDI, $minusDI] = $this->adxDi($highs, $lows, $closes, self::ADX_PERIOD);

        // OBV série + EMA(OBV)
        $obv = $this->obv($closes, $volumes);
        $obvEma = $this->ema($obv, self::OBV_EMA_K);

        // SMA(volume) pour spike
        $volSma = $this->sma($volumes, self::VOL_SMA_M);

        // VWAP (rolling) + déviation relative Dvwap = (C - VWAP)/VWAP
        $vwap = $this->rollingVwap($closes, $volumes, $vwWin);
        $vwapLast = $vwap[$n-1];
        $dVwap = $vwapLast > 0.0 ? ($closes[$n-1] - $vwapLast) / $vwapLast : 0.0;

        // Donchian (canal structurel)
        [$dcHigh, $dcLow] = $this->donchian($highs, $lows, $dcP);

        // Composants de la dernière bougie (t = n-1)
        $O = $opens[$n-1];
        $H = $highs[$n-1];
        $L = $lows[$n-1];
        $C = $closes[$n-1];
        $V = $volumes[$n-1];

        // Corps réel de la bougie (|C - O|)
        $body = abs($C - $O);

        // Mèches (supérieure et inférieure) par rapport au corps
        $upperWick = $H - max($C, $O);
        $lowerWick = min($C, $O) - $L;

        // === 2.2 Tests élémentaires (booléens) pour le t = n-1 ===

        // A) Bougie anormale : body >= k1 * ATR
        $abnormalBody = ($atrLast > 0.0) && ($body >= $sens['k1'] * $atrLast);

        // B) Spike de volume : V >= k2 * SMA(volume)
        $volAvg = $volSma[$n-1] ?? 0.0;
        $volumeSpike = ($volAvg > 0.0) && ($V >= $sens['k2'] * $volAvg);

        // C) Cassure structure Donchian : close hors canal récent
        $donchianBreakBull = $C >= ($dcHigh[$n-1] ?? PHP_FLOAT_MIN);
        $donchianBreakBear = $C <= ($dcLow[$n-1]  ?? PHP_FLOAT_MAX);

        // D) Flip RSI (changement rapide & crossing zone 50)
        $rsiNow = $rsi[$n-1] ?? 50.0;
        $rsiPrev = $rsi[$n-2] ?? 50.0;
        $rsiFlipBear = ($rsiNow < 50.0) && (($rsiPrev - $rsiNow) >= 8.0);
        $rsiFlipBull = ($rsiNow > 50.0) && (($rsiNow - $rsiPrev) >= 8.0);

        // E) Flip DI avec ADX >= a_min (force présente)
        $adxNow   = $adx[$n-1] ?? 0.0;
        $pdiNow   = $plusDI[$n-1]  ?? 0.0;
        $mdiNow   = $minusDI[$n-1] ?? 0.0;
        $diFlipBear = ($adxNow >= $sens['a_min']) && ($mdiNow >= $pdiNow + 5.0);
        $diFlipBull = ($adxNow >= $sens['a_min']) && ($pdiNow >= $mdiNow + 5.0);

        // F) OBV casse sa moyenne (flux qui se renverse)
        $obvNow = $obv[$n-1] ?? 0.0;
        $obvEmaNow = $obvEma[$n-1] ?? 0.0;
        $obvFlipBear = ($obvNow < $obvEmaNow);
        $obvFlipBull = ($obvNow > $obvEmaNow);

        // G) Déviation VWAP significative
        $vwapDevBear = ($dVwap <= -$sens['d_vwap']);
        $vwapDevBull = ($dVwap >=  $sens['d_vwap']);

        // H) Filtre "mèche de retournement" : wick >= r * body
        $wickRatio = $sens['wick_r'];
        $wickBear = $upperWick >= $wickRatio * $body; // après hausse -> mèche haute longue
        $wickBull = $lowerWick >= $wickRatio * $body; // après baisse -> mèche basse longue

        // === 2.3 Directionnalité (cohérence avec le côté de la position) ===
        //  - Si je suis LONG, je surveille un retournement BEAR
        //  - Si je suis SHORT, je surveille un retournement BULL
        $watchBear = (strtoupper($side) === 'LONG');
        $watchBull = (strtoupper($side) === 'SHORT');

        // === 2.4 Booleans "REV" (retournement soudain) pour t = n-1 ===
        $revBear = false;
        $revBull = false;
        $reasons = [];

        if ($watchBear) {
            // Condition de base : anomalie de bougie + spike volume
            $base = $abnormalBody && $volumeSpike;

            // Au moins 1 confirmation directionnelle/structurelle
            $confirm = (
                $donchianBreakBear ||
                $rsiFlipBear ||
                $diFlipBear ||
                $obvFlipBear ||
                $vwapDevBear ||
                $wickBear
            );

            $revBear = $base && $confirm;

            if ($revBear) {
                $reasons[] = 'REV_BEAR: body>=k1*ATR + volume spike + (structure/flux/confirme)';
            }
        }

        if ($watchBull) {
            $base = $abnormalBody && $volumeSpike;
            $confirm = (
                $donchianBreakBull ||
                $rsiFlipBull ||
                $diFlipBull ||
                $obvFlipBull ||
                $vwapDevBull ||
                $wickBull
            );
            $revBull = $base && $confirm;

            if ($revBull) {
                $reasons[] = 'REV_BULL: body>=k1*ATR + volume spike + (structure/flux/confirme)';
            }
        }

        $revNow = $revBear || $revBull;

        // === 2.5 Double validation temporelle (t et t-1) ===
        // On évalue REV pour la bougie précédente avec les mêmes règles (plus simple : recalcul rapide)
        $revPrev = $this->evaluatePrev($n, $opens, $highs, $lows, $closes, $volumes, $atr, $volSma,
            $rsi, $adx, $plusDI, $minusDI, $obv, $obvEma, $vwap, $dcHigh, $dcLow,
            $sens, $watchBear, $watchBull, $wickRatio
        );

        $confirmed2Bars = $revNow && $revPrev;
        if ($confirmed2Bars) {
            $reasons[] = 'Confirmation: 2 bougies consécutives';
        }

        // === 2.6 Score composite S_t (pondérations W) ===
        // On somme les contributions booléennes pondérées
        $score = 0.0;
        if ($abnormalBody) $score += self::W['body_vs_atr'];
        if ($volumeSpike)  $score += self::W['vol_spike'];

        $hasDonchian = ($watchBear && $donchianBreakBear) || ($watchBull && $donchianBreakBull);
        if ($hasDonchian) $score += self::W['donchian'];

        $hasRsiFlip  = ($watchBear && $rsiFlipBear) || ($watchBull && $rsiFlipBull);
        if ($hasRsiFlip) $score += self::W['rsi_flip'];

        $hasDiFlip   = ($watchBear && $diFlipBear) || ($watchBull && $diFlipBull);
        if ($hasDiFlip) $score += self::W['di_flip'];

        $hasObvFlip  = ($watchBear && $obvFlipBear) || ($watchBull && $obvFlipBull);
        if ($hasObvFlip) $score += self::W['obv_flip'];

        $hasVwapDev  = ($watchBear && $vwapDevBear) || ($watchBull && $vwapDevBull);
        if ($hasVwapDev) $score += self::W['vwap_dev'];

        $hasWick     = ($watchBear && $wickBear) || ($watchBull && $wickBull);
        if ($hasWick) $score += self::W['wick_rule'];

        // === 2.7 Détermination de l'action (politique prudente par défaut) ===
        $action      = self::ACTION_NONE;
        $actionMeta  = ['new_stop_loss' => null, 'partial_pct' => null];

        if ($revNow) {
            // Politique dépend de la famille TF et du levier (seuils base/fort)
            $baseOk = ($score >= $scoreThresholds['base']);
            $fortOk = ($score >= $scoreThresholds['fort']);

            // Exigences structurelles pour FULL_EXIT : cassure Donchian + conditions fortes
            $pivotBroken = $hasDonchian;
            $policy      = self::ACTION_POLICY[$levProfile];

            // Calcul du "nouveau SL" proposé selon action
            $trailSL = $this->proposeTrailingSL($side, $C, $atrLast, $policy['trailing_k'], $entryPrice, $currentSL);
            $beSL    = $this->proposeBreakEvenSL($side, $entryPrice, $currentSL);

            // Règle anti-fermeture-inutile : si levier faible et TF scalp, on évite la sortie totale
            $avoidFullExit = ($levProfile === 'low_lev') && in_array($timeframe, ['1m','5m'], true);

            if ($fortOk && $pivotBroken && !$avoidFullExit) {
                // Très fort + cassure structurelle => sortie totale
                $action = self::ACTION_FULL_EXIT;
                $actionMeta['new_stop_loss'] = null; // on sort, pas besoin de SL
                $reasons[] = 'Action: FULL_EXIT (score fort + pivot cassé)';
            } elseif ($fortOk && $confirmed2Bars) {
                // Fort + 2 bougies => sortie partielle + BE
                $action = self::ACTION_PARTIAL_EXIT;
                $actionMeta['partial_pct'] = $policy['partial_pct'];
                $actionMeta['new_stop_loss'] = $beSL;
                $reasons[] = sprintf('Action: PARTIAL_EXIT %.0f%% + MOVE_SL_TO_BE', $policy['partial_pct']*100);
            } elseif ($baseOk && $confirmed2Bars) {
                // Base + 2 bougies => déplacer au BE
                $action = self::ACTION_MOVE_SL_BE;
                $actionMeta['new_stop_loss'] = $beSL;
                $reasons[] = 'Action: MOVE_SL_TO_BE (base + 2 bougies)';
            } else {
                // Alerte naissante => resserrer SL (trailing ATR)
                $action = self::ACTION_TIGHTEN_SL;
                $actionMeta['new_stop_loss'] = $trailSL;
                $reasons[] = sprintf('Action: TIGHTEN_SL (trailing %.1f*ATR)', $policy['trailing_k']);
            }
        }

        // === 2.8 Logging (canal "positions") ===
        $this->positionsLogger->info('[ReversalGuard] Évaluation', [
            'symbol'      => $symbol,
            'tf'          => $timeframe,
            'side'        => $side,
            'lev'         => $leverage,
            'score'       => round($score, 2),
            'rev_now'     => $revNow,
            'confirmed2'  => $confirmed2Bars,
            'action'      => $action,
            'meta'        => $actionMeta,
            'reasons'     => $reasons,
        ]);

        // === 2.9 Retour structuré ===
        return [
            'trigger'     => $revNow,
            'score'       => $score,
            'reasons'     => $reasons,
            'action'      => $action,
            'action_meta' => $actionMeta,
            'diagnostics' => [
                'thresholds' => [
                    'family'   => $family,
                    'score'    => $scoreThresholds,
                    'sens'     => $sens,
                    'wick_r'   => $wickRatio,
                    'dcP'      => $dcP,
                    'vwap_win' => $vwWin,
                ],
                'flags' => [
                    'abnormalBody' => $abnormalBody,
                    'volumeSpike'  => $volumeSpike,
                    'donchian'     => $hasDonchian,
                    'rsiFlip'      => $hasRsiFlip,
                    'diFlip'       => $hasDiFlip,
                    'obvFlip'      => $hasObvFlip,
                    'vwapDev'      => $hasVwapDev,
                    'wick'         => $hasWick,
                ],
                'raw' => [
                    'atrLast'  => $atrLast,
                    'body'     => $body,
                    'volAvg'   => $volAvg,
                    'vwap'     => $vwapLast,
                    'dVwap'    => $dVwap,
                    'rsiNow'   => $rsiNow,
                    'adxNow'   => $adxNow,
                    'pdiNow'   => $pdiNow,
                    'mdiNow'   => $mdiNow,
                    'C'        => $C,
                    'O'        => $O,
                    'H'        => $H,
                    'L'        => $L,
                ],
            ],
        ];
    }

    // =============================
    // === 3) Helpers de décision ===
    // =============================

    private function profileForLeverage(float $lev): string
    {
        return $lev < 5.0 ? 'low_lev' : ($lev >= 10.0 ? 'high_lev' : 'mid_lev');
    }

    /**
     * Propose un SL "trailing" basé sur ATR :
     *  - LONG : SL = max(SL_actuel, C - k*ATR), sans dépasser l'Entry si on veut éviter de rendre des gains
     *  - SHORT: SL = min(SL_actuel, C + k*ATR)
     */
    private function proposeTrailingSL(
        string $side,
        float $close,
        float $atr,
        float $k,
        float $entry,
        ?float $currentSL
    ): float {
        if ($atr <= 0.0) {
            return $currentSL ?? $entry; // fallback
        }
        if (strtoupper($side) === 'LONG') {
            $candidate = $close - $k * $atr;
            // On ne recule JAMAIS un stop : uniquement resserrer (max avec SL courant)
            $tight = max($currentSL ?? -INF, $candidate);
            // Option prudente : ne pas dépasser l'entry tant qu'on n'a pas encore franchi le BE
            if ($currentSL === null || $currentSL <= $entry) {
                $tight = min($tight, $entry);
            }
            return $tight;
        }

        $candidate = $close + $k * $atr;
        $tight = min($currentSL ?? INF, $candidate);
        if ($currentSL === null || $currentSL >= $entry) {
            $tight = max($tight, $entry);
        }

        return $tight;
    }

    /** Déplace le SL au BreakEven (prix d'entrée) en respectant la direction */
    private function proposeBreakEvenSL(string $side, float $entry, ?float $currentSL): float
    {
        if (strtoupper($side) === 'LONG') {
            return max($currentSL ?? -INF, $entry);
        }
        return min($currentSL ?? INF, $entry);
    }

    /**
     * Évalue REV pour la bougie précédente (t = n-2) pour la "double validation"
     * On réutilise les séries et seuils déjà calculés pour éviter dupli logiques.
     */
    private function evaluatePrev(
        int $n,
        array $opens, array $highs, array $lows, array $closes, array $volumes,
        array $atr, array $volSma, array $rsi, array $adx, array $plusDI, array $minusDI,
        array $obv, array $obvEma, array $vwap, array $dcHigh, array $dcLow,
        array $sens, bool $watchBear, bool $watchBull, float $wickRatio
    ): bool {
        $i  = $n - 2;
        $O  = $opens[$i];
        $H  = $highs[$i];
        $L  = $lows[$i];
        $C  = $closes[$i];
        $V  = $volumes[$i];

        $atrI = $atr[$i] ?? 0.0;
        $volAvg = $volSma[$i] ?? 0.0;
        $body = abs($C - $O);
        $upperWick = $H - max($C, $O);
        $lowerWick = min($C, $O) - $L;

        $abnormalBody = ($atrI > 0.0) && ($body >= $sens['k1'] * $atrI);
        $volumeSpike  = ($volAvg > 0.0) && ($V >= $sens['k2'] * $volAvg);

        $donchianBreakBear = $C <= ($dcLow[$i]  ?? PHP_FLOAT_MAX);
        $donchianBreakBull = $C >= ($dcHigh[$i] ?? PHP_FLOAT_MIN);

        $rsiNow  = $rsi[$i]   ?? 50.0;
        $rsiPrev = $rsi[$i-1] ?? 50.0;
        $rsiFlipBear = ($rsiNow < 50.0) && (($rsiPrev - $rsiNow) >= 8.0);
        $rsiFlipBull = ($rsiNow > 50.0) && (($rsiNow - $rsiPrev) >= 8.0);

        $adxNow = $adx[$i] ?? 0.0;
        $pdiNow = $plusDI[$i]  ?? 0.0;
        $mdiNow = $minusDI[$i] ?? 0.0;
        $diFlipBear = ($adxNow >= $sens['a_min']) && ($mdiNow >= $pdiNow + 5.0);
        $diFlipBull = ($adxNow >= $sens['a_min']) && ($pdiNow >= $mdiNow + 5.0);

        $obvNow    = $obv[$i]    ?? 0.0;
        $obvEmaNow = $obvEma[$i] ?? 0.0;
        $obvFlipBear = ($obvNow < $obvEmaNow);
        $obvFlipBull = ($obvNow > $obvEmaNow);

        $vwapNow = $vwap[$i] ?? 0.0;
        $dVwap = $vwapNow > 0.0 ? ($C - $vwapNow) / $vwapNow : 0.0;
        $vwapDevBear = ($dVwap <= -$sens['d_vwap']);
        $vwapDevBull = ($dVwap >=  $sens['d_vwap']);

        $wickBear = $upperWick >= $wickRatio * $body;
        $wickBull = $lowerWick >= $wickRatio * $body;

        $base = $abnormalBody && $volumeSpike;
        $confirmBear = $donchianBreakBear || $rsiFlipBear || $diFlipBear || $obvFlipBear || $vwapDevBear || $wickBear;
        $confirmBull = $donchianBreakBull || $rsiFlipBull || $diFlipBull || $obvFlipBull || $vwapDevBull || $wickBull;

        return ($watchBear && $base && $confirmBear) || ($watchBull && $base && $confirmBull);
    }

    // ====================================
    // === 4) Indicateurs (implémentations)
    // ====================================

    /** True Range & ATR (EMA) */
    private function atr(array $highs, array $lows, array $closes, int $period): array
    {
        $n = min(count($highs), count($lows), count($closes));
        $tr = array_fill(0, $n, 0.0);

        for ($i = 0; $i < $n; $i++) {
            $hl = $highs[$i] - $lows[$i];
            $hc = $i > 0 ? abs($highs[$i] - $closes[$i-1]) : 0.0;
            $lc = $i > 0 ? abs($lows[$i]  - $closes[$i-1]) : 0.0;
            $tr[$i] = max($hl, $hc, $lc);
        }
        return $this->ema($tr, $period);
    }

    /** RSI (Wilder) */
    private function rsi(array $closes, int $period): array
    {
        $n = count($closes);
        if ($n === 0) return [];

        $gains = array_fill(0, $n, 0.0);
        $losses = array_fill(0, $n, 0.0);
        for ($i = 1; $i < $n; $i++) {
            $diff = $closes[$i] - $closes[$i-1];
            $gains[$i]  = $diff > 0 ? $diff : 0.0;
            $losses[$i] = $diff < 0 ? -$diff : 0.0;
        }
        // Moyennes exponentielles (type Wilder)
        $avgGain = $this->ema($gains, $period);
        $avgLoss = $this->ema($losses, $period);
        $rsi = [];
        for ($i = 0; $i < $n; $i++) {
            $lg = $avgGain[$i] ?? 0.0;
            $ll = $avgLoss[$i] ?? 0.0;
            if ($ll == 0.0) {
                $rsi[] = 100.0;
            } else {
                $rs = $lg / $ll;
                $rsi[] = 100.0 - (100.0 / (1.0 + $rs));
            }
        }
        return $rsi;
    }

    /** ADX + +DI / -DI (Wilder) */
    private function adxDi(array $highs, array $lows, array $closes, int $period): array
    {
        $n = min(count($highs), count($lows), count($closes));
        if ($n === 0) return [[], [], []];

        $dmPlus = array_fill(0, $n, 0.0);
        $dmMinus = array_fill(0, $n, 0.0);
        $trArr = array_fill(0, $n, 0.0);

        for ($i = 1; $i < $n; $i++) {
            $upMove   = $highs[$i] - $highs[$i-1];
            $downMove = $lows[$i-1] - $lows[$i];

            $dmPlus[$i]  = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $dmMinus[$i] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;

            $hl = $highs[$i] - $lows[$i];
            $hc = abs($highs[$i] - $closes[$i-1]);
            $lc = abs($lows[$i]  - $closes[$i-1]);
            $trArr[$i] = max($hl, $hc, $lc);
        }

        $smDmPlus  = $this->ema($dmPlus, $period);
        $smDmMinus = $this->ema($dmMinus, $period);
        $smTr      = $this->ema($trArr, $period);

        $plusDI  = [];
        $minusDI = [];
        for ($i = 0; $i < $n; $i++) {
            $tr = $smTr[$i] ?? 0.0;
            $plusDI[]  = $tr > 0 ? 100.0 * (($smDmPlus[$i]  ?? 0.0) / $tr) : 0.0;
            $minusDI[] = $tr > 0 ? 100.0 * (($smDmMinus[$i] ?? 0.0) / $tr) : 0.0;
        }

        $dx = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $plusDI[$i];
            $m = $minusDI[$i];
            $sum = $p + $m;
            $dx[] = $sum > 0 ? 100.0 * abs(($p - $m) / $sum) : 0.0;
        }
        $adx = $this->ema($dx, $period);

        return [$adx, $plusDI, $minusDI];
    }

    /** OBV (On Balance Volume) cumulatif */
    private function obv(array $closes, array $volumes): array
    {
        $n = min(count($closes), count($volumes));
        $out = [];
        $acc = 0.0;
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $out[] = $acc;
                continue;
            }
            if ($closes[$i] > $closes[$i-1]) $acc += $volumes[$i];
            elseif ($closes[$i] < $closes[$i-1]) $acc -= $volumes[$i];
            // égal => pas de changement
            $out[] = $acc;
        }
        return $out;
    }

    /** SMA simple */
    private function sma(array $values, int $period): array
    {
        $n = count($values);
        if ($n === 0) return [];
        $out = array_fill(0, $n, 0.0);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $values[$i];
            if ($i >= $period) $sum -= $values[$i - $period];
            $out[$i] = ($i + 1 >= $period) ? $sum / $period : $sum / max(1, $i + 1);
        }
        return $out;
    }

    /** EMA standard */
    private function ema(array $values, int $period): array
    {
        $n = count($values);
        if ($n === 0) return [];
        $out = [];
        $alpha = 2.0 / (float)($period + 1);
        $prev = $values[0];
        $out[] = $prev;
        for ($i = 1; $i < $n; $i++) {
            $prev = $alpha * $values[$i] + (1 - $alpha) * $prev;
            $out[] = $prev;
        }
        return $out;
    }

    /**
     * VWAP "rolling" sur fenêtre N :
     *  vwap[i] = sum_{j=i-N+1..i}(typical_price[j]*volume[j]) / sum_{j=i-N+1..i}(volume[j])
     *  où typical_price = (H+L+C)/3 — ici, on approx avec C (absence de H/L dans cette fonction)
     *  NOTE : on reçoit H/L séparés, mais le design plus haut appelle avec closes/volumes seulement.
     */
    private function rollingVwap(array $closes, array $volumes, int $window): array
    {
        $n = min(count($closes), count($volumes));
        $out = array_fill(0, $n, 0.0);
        $sumPV = 0.0; // somme prix*volume
        $sumV  = 0.0; // somme volume
        $qPV = [];
        $qV = [];
        for ($i = 0; $i < $n; $i++) {
            $pv = $closes[$i] * $volumes[$i];
            $sumPV += $pv; $sumV += $volumes[$i];
            $qPV[] = $pv;  $qV[]  = $volumes[$i];

            if (count($qPV) > $window) {
                $sumPV -= array_shift($qPV);
                $sumV  -= array_shift($qV);
            }
            $out[$i] = $sumV > 0.0 ? $sumPV / $sumV : $closes[$i];
        }
        return $out;
    }

    /** Donchian Channel sur p périodes : [maxHigh, minLow] */
    private function donchian(array $highs, array $lows, int $p): array
    {
        $n = min(count($highs), count($lows));
        $dcHigh = array_fill(0, $n, 0.0);
        $dcLow  = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            $from = max(0, $i - $p + 1);
            $maxH = -INF; $minL = INF;
            for ($j = $from; $j <= $i; $j++) {
                if ($highs[$j] > $maxH) $maxH = $highs[$j];
                if ($lows[$j]  < $minL) $minL = $lows[$j];
            }
            $dcHigh[$i] = $maxH;
            $dcLow[$i]  = $minL;
        }
        return [$dcHigh, $dcLow];
    }
}
