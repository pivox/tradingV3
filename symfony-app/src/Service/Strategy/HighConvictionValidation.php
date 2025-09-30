<?php
declare(strict_types=1);

namespace App\Service\Strategy;

/**
 * HighConvictionValidation
 *
 * Entrée attendue :
 *  - $ctx (array) : sortie de contract_pipeline::signals
 *      [
 *        '4h'  => ['signal'=>'LONG|SHORT', 'ema_fast'=>..., 'ema_slow'=>..., 'macd'=>['hist'=>...], ...],
 *        '1h'  => ['signal'=>'LONG|SHORT', 'ema_fast'=>..., 'ema_slow'=>..., 'macd'=>['hist'=>...], ...],
 *        '15m' => ['signal'=>'LONG|SHORT', 'vwap'=>..., 'close'=>..., 'ema_fast'=>..., 'ema_slow'=>..., 'macd'=>['hist'=>...]],
 *        '5m'  => ['signal'=>'LONG|SHORT', 'vwap'=>..., 'close'=>..., 'ema_fast'=>..., 'ema_slow'=>..., 'macd'=>['hist'=>...]],
 *        '1m'  => ['signal'=>'LONG|SHORT', 'vwap'=>..., 'close'=>..., 'ema_fast'=>..., 'ema_slow'=>...]
 *      ]
 *
 *  - $metrics (array) optionnel : indicateurs complémentaires si disponibles
 *      [
 *        'adx_1h'=>float, 'adx_15m'=>float,
 *        'breakout_confirmed'=>bool, 'vol_high'=>bool,
 *        'expansion_after_contraction'=>bool,
 *        'macro_no_event'=>bool,
 *        'valid_retest'=>bool,         // validation du pullback micro (5m/1m)
 *        'rr'=>float,                  // risk/reward ex-ante
 *        'liq_ratio'=>float            // (distance_liquidation / distance_stop)
 *      ]
 *
 * Sortie :
 *  - ['ok'=>bool, 'flags'=>['high_conviction'=>bool, 'leverage_cap'=>int], 'reasons'=>string[]]
 */
final class HighConvictionValidation
{
    // ==== Seuils (constantes) =================================================
    private const ADX_THRESHOLD           = 25.0;
    private const RR_MIN                  = 5.0;
    private const LIQUIDATION_MIN         = 3.0;   // liquidation ≥ 3x le stop
    private const LEVERAGE_CAP            = 50;    // plafond du levier autorisé si validé
    private const USE_PROXY_IF_MISSING    = true;  // autoriser les proxys si métriques manquantes

    // Proxys (quand il manque ADX/volume/…)
    private const PROXY_ADX_MIN_HIST      = 0.0;   // MACD hist > 0
    private const PROXY_ALIGN_REQ         = true;  // exige ema_fast > ema_slow
    private const PROXY_BREAKOUT_REQ_VWAP = true;  // exige close > vwap (15m & 5m)
    private const PROXY_EXPANSION_MIN_DIF = 0.0;   // ema_fast - ema_slow en hausse

    // ==== API =================================================================

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $metrics
     * @return array{ok:bool, flags?:array<string,mixed>, reasons?:string[]}
     */
    public function validate(array $ctx, array $metrics = []): array
    {
        $reasons = [];

        // 1) Confluence MTF : 4h, 1h, 15m doivent être alignés (LONG/SHORT identiques)
        $s4  = $this->signal($ctx, '4h');
        $s1  = $this->signal($ctx, '1h');
        $s15 = $this->signal($ctx, '15m');
        if (!$this->validSide($s4) || $s4 !== $s1 || $s1 !== $s15) {
            $reasons[] = 'multi_timeframe_alignment';
        }

        // 2) Force de tendance (ADX) sur 1h et 15m (ou proxy EMA/MACD si manquant)
        $adxOk = $this->checkAdxOrProxy($metrics, $ctx);
        if (!$adxOk) {
            $reasons[] = 'trend_strength_insufficient';
        }

        // 3) Cassure confirmée par le volume (ou proxy close>VWAP + MACD hist>0)
        $breakoutOk = $this->checkBreakoutWithVolume($metrics, $ctx);
        if (!$breakoutOk) {
            $reasons[] = 'breakout_with_volume_not_confirmed';
        }

        // 4) Expansion de volatilité après contraction (ou proxy pente EMA/MACD)
        $expansionOk = $this->checkVolatilityExpansion($metrics, $ctx);
        if (!$expansionOk) {
            $reasons[] = 'volatility_expansion_missing';
        }

        // 5) Pas d’événement macro imminent
        $macroOk = (bool)($metrics['macro_no_event'] ?? false);
        if (!$macroOk) {
            $reasons[] = 'macro_event_veto';
        }

        // 6) Pullback / retest propre validé en micro TF (5m + 1m)
        $retestOk = (bool)($metrics['valid_retest'] ?? false);
        if (!$retestOk && self::USE_PROXY_IF_MISSING) {
            // Proxy = 5m & 1m alignés avec 15m (même sens) + close au-dessus/au-dessous VWAP selon le sens
            $retestOk = $this->proxyValidRetest($ctx, $s15);
        }
        if (!$retestOk) {
            $reasons[] = 'micro_tf_retest_not_valid';
        }

        // 7) RR minimal
        $rr = $this->toFloat($metrics['rr'] ?? null);
        if (!\is_finite($rr) || $rr < self::RR_MIN) {
            $reasons[] = 'rr_guard';
        }

        // 8) Garde liquidation
        $liq = $this->toFloat($metrics['liq_ratio'] ?? null);
        if (!\is_finite($liq) || $liq < self::LIQUIDATION_MIN) {
            $reasons[] = 'liquidation_guard';
        }

        // Décision
        $ok = empty($reasons);
        return $ok
            ? ['ok' => true,  'flags' => ['high_conviction' => true, 'leverage_cap' => self::LEVERAGE_CAP]]
            : ['ok' => false, 'reasons' => $reasons];
    }

    // ==== Helpers =============================================================

    private function signal(array $ctx, string $tf): ?string
    {
        $sig = $ctx[$tf]['signal'] ?? null;
        return \is_string($sig) ? \strtoupper($sig) : null;
    }

    private function validSide(?string $s): bool
    {
        return \in_array($s, ['LONG', 'SHORT'], true);
    }

    /** Vérifie ADX(1h,15m) > seuil, sinon fallback proxy (MACD hist>0 + EMA fast>slow) */
    private function checkAdxOrProxy(array $metrics, array $ctx): bool
    {
        $adx1h  = $this->toFloat($metrics['adx_1h'] ?? null);
        $adx15m = $this->toFloat($metrics['adx_15m'] ?? null);
        if (\is_finite($adx1h) && \is_finite($adx15m)) {
            return $adx1h > self::ADX_THRESHOLD && $adx15m > self::ADX_THRESHOLD;
        }
        if (!self::USE_PROXY_IF_MISSING) return false;

        $p1h  = $this->proxyTrendOk($ctx['1h']  ?? []);
        $p15m = $this->proxyTrendOk($ctx['15m'] ?? []);
        return $p1h && $p15m;
    }

    /** Proxy de “tendance forte” : EMA fast>slow & MACD hist>0 */
    private function proxyTrendOk(array $node): bool
    {
        $emaF = $this->toFloat($node['ema_fast'] ?? null);
        $emaS = $this->toFloat($node['ema_slow'] ?? null);
        $hist = $this->toFloat($node['macd']['hist'] ?? null);
        return (\is_finite($emaF) && \is_finite($emaS) && $emaF > $emaS)
            && (\is_finite($hist) && $hist > self::PROXY_ADX_MIN_HIST);
    }

    /** Cassure + volume : direct via metrics, sinon proxy close>VWAP & MACD hist>0 sur 15m et 5m */
    private function checkBreakoutWithVolume(array $metrics, array $ctx): bool
    {
        $confirmed = (bool)($metrics['breakout_confirmed'] ?? false);
        if ($confirmed) return true;
        if (!self::USE_PROXY_IF_MISSING) return false;

        foreach (['15m','5m'] as $tf) {
            $node = $ctx[$tf] ?? [];
            $close = $this->toFloat($node['close'] ?? null);
            $vwap  = $this->toFloat($node['vwap']  ?? null);
            $hist  = $this->toFloat($node['macd']['hist'] ?? null);
            if (!(\is_finite($close) && \is_finite($vwap) && \is_finite($hist))) {
                return false;
            }
            if (!($close > $vwap && $hist > 0.0)) {
                return false;
            }
        }
        return true;
    }

    /** Expansion après contraction : direct via metrics, sinon proxy pente (ema_fast - ema_slow) croissante en 15m */
    private function checkVolatilityExpansion(array $metrics, array $ctx): bool
    {
        $exp = (bool)($metrics['expansion_after_contraction'] ?? false);
        if ($exp) return true;
        if (!self::USE_PROXY_IF_MISSING) return false;

        $n15 = $ctx['15m'] ?? [];
        $emaF = $this->toFloat($n15['ema_fast'] ?? null);
        $emaS = $this->toFloat($n15['ema_slow'] ?? null);
        // On n’a qu’un instantané -> on check simplement l'écart > 0 et MACD hist > 0 comme proxy
        $hist = $this->toFloat($n15['macd']['hist'] ?? null);
        return (\is_finite($emaF) && \is_finite($emaS) && ($emaF - $emaS) > self::PROXY_EXPANSION_MIN_DIF)
            && (\is_finite($hist) && $hist > 0.0);
    }

    /** Proxy micro retest : 5m et 1m alignés avec 15m + close positionné côté VWAP en cohérence avec le sens */
    private function proxyValidRetest(array $ctx, ?string $side15m): bool
    {
        if (!$this->validSide($side15m)) return false;
        $s5  = $this->signal($ctx, '5m');
        $s1  = $this->signal($ctx, '1m');

        if ($s5 !== $side15m || $s1 !== $side15m) return false;

        $ok5 = $this->vwapSideOk($ctx['5m'] ?? [], $side15m);
        $ok1 = $this->vwapSideOk($ctx['1m'] ?? [], $side15m);
        return $ok5 && $ok1;
    }

    private function vwapSideOk(array $node, string $side): bool
    {
        $close = $this->toFloat($node['close'] ?? null);
        $vwap  = $this->toFloat($node['vwap']  ?? null);
        if (!(\is_finite($close) && \is_finite($vwap))) return false;

        return $side === 'LONG' ? ($close >= $vwap) : ($close <= $vwap);
    }

    private function toFloat(mixed $v): float
    {
        return \is_numeric($v) ? (float)$v : \NAN;
    }
}
