<?php
declare(strict_types=1);

namespace App\MtfValidator\Strategy;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
    private const RR_MIN                  = 2.0;
    private const LIQUIDATION_MIN         = 3.0;   // liquidation ≥ 3x le stop
    private const LEVERAGE_CAP            = 50;    // plafond du levier autorisé si validé
    private const USE_PROXY_IF_MISSING    = true;  // autoriser les proxys si métriques manquantes

    // Proxys (quand il manque ADX/volume/…)
    private const PROXY_ADX_MIN_HIST      = 0.0;   // MACD hist > 0
    private const PROXY_ALIGN_REQ         = true;  // exige ema_fast > ema_slow
    private const PROXY_BREAKOUT_REQ_VWAP = true;  // exige close > vwap (15m & 5m)
    private const PROXY_EXPANSION_MIN_DIF = 0.0;   // ema_fast - ema_slow en hausse

    public function __construct(
        #[Autowire(service: 'monolog.logger.highconviction')] private readonly LoggerInterface $logger,
    ) {}

    // ==== API =================================================================

    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $metrics
     * @return array{ok:bool, flags?:array<string,mixed>, reasons?:string[]}
     */
    public function validate(array $ctx, array $metrics = []): array
    {
        $reasons = [];

        $this->logMissingSignalFields($ctx);

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

        $p1h  = $this->proxyTrendOk($ctx['1h']  ?? [], '1h');
        $p15m = $this->proxyTrendOk($ctx['15m'] ?? [], '15m');
        return $p1h && $p15m;
    }

    /** Proxy de “tendance forte” : EMA fast>slow & MACD hist>0 */
    private function proxyTrendOk(array $node, string $tf): bool
    {
        $emaF = $this->toFloat($node['ema_fast'] ?? null);
        $emaS = $this->toFloat($node['ema_slow'] ?? null);
        $hist = $this->toFloat($node['macd']['hist'] ?? null);

        $hasEma  = \is_finite($emaF) && \is_finite($emaS);
        $hasHist = \is_finite($hist);

        if ($hasEma && $hasHist) {
            return $emaF > $emaS && $hist > self::PROXY_ADX_MIN_HIST;
        }

        $fallbackSide = strtoupper((string)($node['signal'] ?? 'NONE'));
        if ($this->validSide($fallbackSide)) {
            $this->logger->notice('HC proxyTrend fallback on signal', [
                'tf' => $tf,
                'side' => $fallbackSide,
                'missing' => $this->collectMissingFields($node, ['ema_fast','ema_slow','macd.hist']),
            ]);
            return true;
        }

        $this->logger->warning('HC proxyTrend failed: insufficient data', [
            'tf' => $tf,
            'missing' => $this->collectMissingFields($node, ['ema_fast','ema_slow','macd.hist']),
        ]);
        return false;
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
                $side = strtoupper((string)($node['signal'] ?? 'NONE'));
                if ($this->validSide($side)) {
                    $this->logger->notice('HC breakout proxy fallback on signal', [
                        'tf' => $tf,
                        'side' => $side,
                        'missing' => $this->collectMissingFields($node, ['close','vwap','macd.hist']),
                    ]);
                    continue;
                }
                $this->logger->warning('HC breakout proxy failed: insufficient data', [
                    'tf' => $tf,
                    'missing' => $this->collectMissingFields($node, ['close','vwap','macd.hist']),
                ]);
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

        $ok5 = $this->vwapSideOk($ctx['5m'] ?? [], $side15m, '5m');
        $ok1 = $this->vwapSideOk($ctx['1m'] ?? [], $side15m, '1m');
        return $ok5 && $ok1;
    }

    private function vwapSideOk(array $node, string $side, string $tf): bool
    {
        $close = $this->toFloat($node['close'] ?? null);
        $vwap  = $this->toFloat($node['vwap']  ?? null);
        if (!(\is_finite($close) && \is_finite($vwap))) {
            $fallbackSide = strtoupper((string)($node['signal'] ?? 'NONE'));
            if ($fallbackSide === $side) {
                $this->logger->notice('HC VWAP proxy fallback on signal', [
                    'tf' => $tf,
                    'side' => $fallbackSide,
                    'missing' => $this->collectMissingFields($node, ['close','vwap']),
                ]);
                return true;
            }

            $this->logger->warning('HC VWAP proxy failed: insufficient data', [
                'tf' => $tf,
                'side' => $side,
                'missing' => $this->collectMissingFields($node, ['close','vwap']),
            ]);
            return false;
        }

        return $side === 'LONG' ? ($close >= $vwap) : ($close <= $vwap);
    }

    private function toFloat(mixed $v): float
    {
        return \is_numeric($v) ? (float)$v : \NAN;
    }

    private function logMissingSignalFields(array $ctx): void
    {
        $requirements = [
            '4h'  => ['signal','ema_fast','ema_slow','macd.hist'],
            '1h'  => ['signal','ema_fast','ema_slow','macd.hist'],
            '15m' => ['signal','ema_fast','ema_slow','macd.hist','close','vwap'],
            '5m'  => ['signal','ema_fast','ema_slow','macd.hist','close','vwap'],
            '1m'  => ['signal','ema_fast','ema_slow','close','vwap'],
        ];

        $report = [];
        foreach ($requirements as $tf => $fields) {
            $missing = $this->collectMissingFields($ctx[$tf] ?? [], $fields);
            if ($missing !== []) {
                $report[$tf] = $missing;
            }
        }

        if ($report !== []) {
            $this->logger->info('HC signal inputs missing fields', [
                'missing' => $report,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $node
     * @param array<int,string> $fields
     * @return array<int,string>
     */
    private function collectMissingFields(array $node, array $fields): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if ($this->hasField($node, $field)) {
                continue;
            }
            $missing[] = $field;
        }

        return $missing;
    }

    private function hasField(array $node, string $field): bool
    {
        if (!str_contains($field, '.')) {
            return array_key_exists($field, $node) && $node[$field] !== null && $node[$field] !== '';
        }

        [$parent, $child] = explode('.', $field, 2);
        if (!isset($node[$parent]) || !is_array($node[$parent])) {
            return false;
        }

        return array_key_exists($child, $node[$parent]) && $node[$parent][$child] !== null && $node[$parent][$child] !== '';
    }
}
