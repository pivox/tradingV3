<?php

namespace App\Risk;

use App\Service\Indicator\AtrCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * HighConvictionGuard
 *
 * Lit un YAML de configuration (config/signals/high_conviction.yml)
 * et évalue si un trade est "High Conviction" pour autoriser un levier élevé
 * sans dépasser le risque nominal ni les garde-fous (liquidation, stop min, etc.).
 */
class HighConvictionGuard
{
    private array $config;

    public function __construct(
        private readonly AtrCalculator $atrCalculator,
        private readonly PositionSizer $positionSizer,
        private readonly LoggerInterface $logger,
        string $configPath = __DIR__ . '/../../config/signals/high_conviction.yml'
    ) {
        $this->config = file_exists($configPath)
            ? (Yaml::parseFile($configPath) ?? [])
            : [];
    }

    /**
     * @param array $signals   Tableau des signaux par TF (ex: ['context_4h'=>..., 'context_1h'=>..., 'exec_15m'=>..., 'exec_5m'=>..., 'micro_1m'=>...])
     * @param array $indicators Tableau des indicateurs agrégés (ADX, OBV, VWAP, Volume, ATR, Bollinger, Choppiness, RR estimé...) par TF
     * @param array $ctx        Contexte marché (symbol, side LONG/SHORT, price, equity, stop_price, timeframe courant, tick/step sizes, leverage_max_exchange, liquidation_price_calc callable, macro_calendar feed, etc.)
     */
    public function evaluate(array $signals, array $indicators, array $ctx): HighConvictionDecision
    {
        $reasons = [];
        $ok = true;

        // 1) Multi-timeframe alignment
        if (!$this->checkMtfAlignment($signals, $reasons)) { $ok = false; }

        // 2) Trend strength (ADX)
        if (!$this->checkTrendStrength($indicators, $reasons)) { $ok = false; }

        // 3) Breakout with volume
        if (!$this->checkBreakoutWithVolume($indicators, $reasons)) { $ok = false; }

        // 4) Volatility expansion
        if (!$this->checkVolatilityExpansion($indicators, $reasons)) { $ok = false; }

        // 5) No macro event imminent
        if (!$this->checkNoMacroEvent($ctx, $reasons)) { $ok = false; }

        // 6) Micro-TF confirmation (5m & 1m)
        if (!$this->checkMicroTfConfirmation($signals, $indicators, $reasons)) { $ok = false; }

        // 7) Risk/Reward guard
        if (!$this->checkRiskReward($indicators, $reasons)) { $ok = false; }

        // 8) Stop minimal & liquidation guard
        if (!$this->checkStopMinAndLiquidation($ctx, $reasons)) { $ok = false; }

        // 9) Compute suggested leverage within caps
        $suggestedLev = $this->suggestLeverage($ctx, $indicators);

        // Hard caps from config
        $levCap = (float)($this->config['risk_management']['leverage_cap'] ?? 50.0);
        $suggestedLev = min($suggestedLev, $levCap);

        // Never exceed exchange maximum
        $exchangeMax = (float)($ctx['leverage_max_exchange'] ?? $levCap);
        $suggestedLev = min($suggestedLev, $exchangeMax);

        // Risk % caps — we never exceed base risk
        $maxRiskPct = (float)($this->config['risk_management']['max_risk_pct'] ?? 2.0);
        $baseRiskPct = (float)($this->config['risk_management']['base_risk_pct'] ?? 2.0);
        $riskPct = min($baseRiskPct, $maxRiskPct);

        // Downsize if liquidation guard would break with suggestedLev
        if (!$this->validateLiquidationWithLeverage($ctx, $suggestedLev)) {
            $reasons[] = 'Levier suggéré réduit pour respecter la liquidation_guard.';
            // simple backoff: halve until valid or reach 1x
            while ($suggestedLev > 1 && !$this->validateLiquidationWithLeverage($ctx, $suggestedLev)) {
                $suggestedLev = max(1.0, $suggestedLev / 2.0);
            }
        }

        $decision = new HighConvictionDecision(
            eligible: $ok,
            suggestedLeverage: $ok ? $suggestedLev : null,
            riskPct: $riskPct,
            reasons: $reasons,
        );

        $this->logger->info('HighConvictionGuard decision', [
            'eligible' => $decision->eligible,
            'suggested_lev' => $decision->suggestedLeverage,
            'risk_pct' => $decision->riskPct,
            'reasons' => $decision->reasons,
        ]);

        return $decision;
    }

    private function checkMtfAlignment(array $signals, array &$reasons): bool
    {
        $cfg = $this->config['conditions']['multi_timeframe_alignment'] ?? [];
        $req = (bool)($cfg['required'] ?? true);
        $keys = $cfg['signals'] ?? ['context_4h','context_1h','exec_15m'];
        $vals = [];
        foreach ($keys as $k) {
            $vals[] = strtoupper($signals[$k]['signal'] ?? 'NONE');
        }
        $allEqual = count(array_unique($vals)) === 1 && !in_array('NONE', $vals, true);
        if (!$allEqual && $req) { $reasons[] = 'Confluence multi-TF non alignée.'; return false; }
        return true;
    }

    private function checkTrendStrength(array $indicators, array &$reasons): bool
    {
        $cfg = $this->config['conditions']['trend_strength'] ?? [];
        $req = (bool)($cfg['required'] ?? true);
        $tfs = $cfg['timeframe'] ?? ['1h','15m'];
        $thr = (float)($cfg['threshold'] ?? 25.0);
        foreach ($tfs as $tf) {
            $adx = (float)($indicators[$tf]['ADX'] ?? 0.0);
            if ($adx < $thr) {
                if ($req) { $reasons[] = "ADX insuffisant sur $tf (".$adx.")"; return false; }
            }
        }
        return true;
    }

    private function checkBreakoutWithVolume(array $indicators, array &$reasons): bool
    {
        $cfg = $this->config['conditions']['breakout_with_volume'] ?? [];
        $req = (bool)($cfg['required'] ?? true);
        $confirmed = (bool)($indicators['15m']['BREAKOUT_CONFIRMED'] ?? false);
        $volSpike = (bool)($indicators['15m']['VOLUME_SPIKE'] ?? false);
        if (!($confirmed && $volSpike) && $req) { $reasons[] = 'Breakout/Volume non confirmés.'; return false; }
        return true;
    }

    private function checkVolatilityExpansion(array $indicators, array &$reasons): bool
    {
        $cfg = $this->config['conditions']['volatility_expansion'] ?? [];
        $req = (bool)($cfg['required'] ?? true);
        $atrUp = (bool)($indicators['15m']['ATR_EXPANDING'] ?? false);
        $squeeze = (bool)($indicators['15m']['BOLLINGER_SQUEEZE'] ?? false);
        $choppyLow = (bool)($indicators['15m']['CHOPPINESS_LOW'] ?? false);
        if (!($atrUp && $squeeze && $choppyLow) && $req) { $reasons[] = 'Pas d\'expansion de volatilité valide.'; return false; }
        return true;
    }

    private function checkNoMacroEvent(array $ctx, array &$reasons): bool
    {
        $cfg = $this->config['conditions']['no_macro_event'] ?? [];
        $req = (bool)($cfg['required'] ?? true);
        $noEvent = (bool)($ctx['macro_calendar']['no_event'] ?? true);
        if (!$noEvent && $req) { $reasons[] = 'Événement macro imminent détecté.'; return false; }
        return true;
    }

    private function checkMicroTfConfirmation(array $signals, array $indicators, array &$reasons): bool
    {
        $cfg = $this->config['conditions']['micro_tf_confirmation'] ?? [];
        $req = (bool)($cfg['required'] ?? true);
        $ok5 = strtoupper($signals['exec_5m']['signal'] ?? 'NONE') !== 'NONE';
        $ok1 = strtoupper($signals['micro_1m']['signal'] ?? 'NONE') !== 'NONE';
        $wickOk = (bool)($indicators['1m']['NO_DEEP_WICKS'] ?? false);
        if (!($ok5 && $ok1 && $wickOk) && $req) { $reasons[] = 'Confirmation micro-TF insuffisante.'; return false; }
        return true;
    }

    private function checkRiskReward(array $indicators, array &$reasons): bool
    {
        $cfg = $this->config['conditions']['rr_guard'] ?? [];
        $req = (bool)($cfg['required'] ?? true);
        $thr = (float)($cfg['threshold'] ?? 5.0);
        $rr = (float)($indicators['current']['RR'] ?? 0.0);
        if ($rr < $thr && $req) { $reasons[] = 'RR insuffisant ('.$rr.' < '.$thr.')'; return false; }
        return true;
    }

    private function checkStopMinAndLiquidation(array $ctx, array &$reasons): bool
    {
        $stopMinPct = (float)($this->config['risk_management']['stop_min_pct'] ?? 0.25);
        $stopPct = (float)($ctx['stop_pct'] ?? 0.0);
        if ($stopPct < $stopMinPct) { $reasons[] = 'Stop trop serré (< stop_min_pct).'; return false; }

        $lgThr = (float)($this->config['conditions']['liquidation_guard']['threshold'] ?? 3.0);
        $lg = (float)($ctx['liquidation_guard_ratio'] ?? 0.0);
        if ($lg < $lgThr) { $reasons[] = 'Liquidation guard insuffisant.'; return false; }
        return true;
    }

    private function suggestLeverage(array $ctx, array $indicators): float
    {
        // Idée simple : map RR et ADX sur une plage [baseLev .. cap]
        $baseLev = (float)($ctx['leverage_base'] ?? 3.0); // ex. x3
        $cap = (float)($this->config['risk_management']['leverage_cap'] ?? 50.0);
        $rr = (float)($indicators['current']['RR'] ?? 1.0);
        $adx = (float)($indicators['15m']['ADX'] ?? 20.0);

        // Normalisations
        $rrScore = max(0.0, min(1.0, ($rr - 3.0) / 5.0));   // RR 3->8
        $adxScore = max(0.0, min(1.0, ($adx - 20.0) / 20.0)); // ADX 20->40

        $score = 0.6*$rrScore + 0.4*$adxScore; // pondération
        $lev = $baseLev + $score * ($cap - $baseLev);
        return max(1.0, min($lev, $cap));
    }

    private function validateLiquidationWithLeverage(array $ctx, float $lev): bool
    {
        // Utilise un callback passé dans $ctx pour recalculer la liquidation avec un levier donné
        if (!isset($ctx['calc_liquidation_with_lev']) || !is_callable($ctx['calc_liquidation_with_lev'])) {
            return true; // si pas dispo, on ne bloque pas ici (d'autres guards existent)
        }
        $ratio = (float)call_user_func($ctx['calc_liquidation_with_lev'], $lev);
        $lgThr = (float)($this->config['conditions']['liquidation_guard']['threshold'] ?? 3.0);
        return $ratio >= $lgThr;
    }
}


// ===================== Usage (exemple) =====================
// $guard = new HighConvictionGuard($atrCalculator, $positionSizer, $logger);
// $decision = $guard->evaluate($signalsPayload, $indicatorSnapshot, [
//     'symbol' => $symbol,
//     'side' => strtoupper($signalsPayload['final']['signal'] ?? 'NONE'),
//     'price' => $lastPrice,
//     'equity' => $equity,
//     'stop_pct' => $stopPct,
//     'leverage_max_exchange' => 100,
//     'macro_calendar' => ['no_event' => true],
//     'liquidation_guard_ratio' => $liquidationGuardRatio,
//     'calc_liquidation_with_lev' => function (float $lev) use ($symbol, $lastPrice, $stopPct) {
//         // Retourner un ratio (distance_liq / distance_stop)
//         // Stub à remplacer par ta vraie formule exchange
//         $distanceStop = max(1e-9, $lastPrice * $stopPct / 100.0);
//         $distanceLiq = $lastPrice / max(1.0, $lev); // purement illustratif
//         return ($distanceLiq / $distanceStop);
//     },
// ]);
//
// if ($decision->eligible) {
//     $lev = $decision->suggestedLeverage; // déjà capé par guard
//     // Appliquer un downsize via PositionSizer si nécessaire pour respecter risk % & budget USDT
//     // puis passer à PositionOpener
// }
