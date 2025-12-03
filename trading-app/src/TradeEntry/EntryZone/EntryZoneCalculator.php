<?php
declare(strict_types=1);

namespace App\TradeEntry\EntryZone;

use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\Types\Side;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\TradeEntry\Pricing\TickQuantizer;
use App\Config\{TradeEntryConfig, TradeEntryConfigProvider, TradeEntryModeContext};
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EntryZoneCalculator
{
    private const DEFAULT_TF = '5m';     // Fallback si config absente
    private const K_ATR = 0.35;          // Fallback k_atr
    private const W_MIN = 0.0005;        // 0.05% largeur min (fallback)
    private const W_MAX = 0.0100;        // 1.00%  largeur max (fallback)
    private const ASYM_BIAS = 0.0;       // 0 = symétrique; 0.25 => 62.5%/37.5%

    public function __construct(
        private readonly ?IndicatorProviderInterface $indicators = null,
        private readonly ?TradeEntryConfigProvider $configProvider = null,
        private readonly ?TradeEntryConfig $defaultConfig = null, // Fallback si mode non fourni
        private readonly ?TradeEntryModeContext $modeContext = null,
        private readonly ?string $defaultTfOverride = null,
        private readonly ?float $kAtrOverride = null,
        private readonly ?float $wMinOverride = null,
        private readonly ?float $wMaxOverride = null,
        private readonly ?float $asymBiasOverride = null,
        #[Autowire(service: 'monolog.logger.positions')] private readonly ?LoggerInterface $positionsLogger = null,
    ) {}

    public function compute(string $symbol, ?Side $side = null, ?int $pricePrecision = null, ?string $decisionKey = null, ?string $mode = null): EntryZone
    {
        // Lecture config selon le mode (même mécanisme que validations.{mode}.yaml)
        $config = $this->getConfigForMode($mode);
        $post = $config?->getPostValidation() ?? [];
        $postEntryZone = (array)($post['entry_zone'] ?? []);
        $execTf = $post['execution_timeframe']['default'] ?? null;

        // Config "entry" (mode trade_entry.*.yaml)
        $entryBlock = $config?->getEntry() ?? [];
        $entryZoneBlock = (array)($entryBlock['entry_zone'] ?? []);

        // Fusion: la config "entry" a priorité sur post_validation
        $zoneCfg = array_merge($postEntryZone, $entryZoneBlock);

        $pivotTf = $this->defaultTfOverride
            ?? ($zoneCfg['pivot_tf'] ?? (\is_string($execTf) && $execTf !== '' ? $execTf : null))
            ?? self::DEFAULT_TF;
        $atrTf = $zoneCfg['offset_atr_tf'] ?? $pivotTf;

        $kAtr = $this->kAtrOverride
            ?? (isset($zoneCfg['k_atr']) && \is_numeric($zoneCfg['k_atr']) ? (float)$zoneCfg['k_atr']
                : (isset($zoneCfg['offset_k']) && \is_numeric($zoneCfg['offset_k']) ? (float)$zoneCfg['offset_k'] : self::K_ATR));
        $wMin = $this->wMinOverride
            ?? (isset($zoneCfg['w_min']) && \is_numeric($zoneCfg['w_min']) ? (float)$zoneCfg['w_min'] : self::W_MIN);
        $wMax = $this->wMaxOverride
            ?? (isset($zoneCfg['w_max']) && \is_numeric($zoneCfg['w_max']) ? (float)$zoneCfg['w_max']
                : (isset($zoneCfg['max_deviation_pct']) && \is_numeric($zoneCfg['max_deviation_pct']) ? (float)$zoneCfg['max_deviation_pct'] : self::W_MAX));

        // Gestion du pivot : entry.entry_zone.from a priorité, sinon fallback vwap_anchor
        $from = \is_string($zoneCfg['from'] ?? null) ? strtolower(trim((string)$zoneCfg['from'])) : null;
        $preferVwap = match ($from) {
            'sma21' => false,
            'ma21' => false,
            'vwap' => true,
            default => isset($zoneCfg['vwap_anchor']) ? (bool)$zoneCfg['vwap_anchor'] : true,
        };

        $bias = $this->asymBiasOverride
            ?? (isset($zoneCfg['asym_bias']) && \is_numeric($zoneCfg['asym_bias'])
                ? max(0.0, min(0.95, (float)$zoneCfg['asym_bias'])) : self::ASYM_BIAS);
        $quantize = isset($zoneCfg['quantize_to_exchange_step'])
            ? (bool)$zoneCfg['quantize_to_exchange_step']
            : true;
        $ttlSec = isset($zoneCfg['ttl_sec']) && is_numeric($zoneCfg['ttl_sec'])
            ? max(0, (int)$zoneCfg['ttl_sec'])
            : 240;

        // Fallback si aucun provider n'est injecté
        if ($this->indicators === null) {
            $this->positionsLogger?->info('entry_zone.open_no_indicators', [
                'symbol' => $symbol,
                'decision_key' => $decisionKey,
                'reason' => 'no_indicator_provider',
            ]);
            return new EntryZone(
                min: PHP_FLOAT_MIN,
                max: PHP_FLOAT_MAX,
                rationale: 'open zone (no indicators)',
                metadata: ['timeframe' => $pivotTf]
            );
        }

        // 1) Récupère ATR et liste pivot (vwap/sma...)
        $atr = $this->indicators->getAtr(symbol: $symbol, tf: $atrTf);
        $list = $this->indicators->getListPivot(symbol: $symbol, tf: $pivotTf);

        $ind = $list?->toArray() ?? [];
        $pivot = null;
        $pivotSrc = null;

        // Sélection pivot selon config: vwap_anchor ? vwap→sma21 : sma21→vwap
        $tryVwap = function(array $ind): ?float {
            if (isset($ind['vwap']) && \is_finite((float)$ind['vwap']) && (float)$ind['vwap'] > 0.0) {
                return (float)$ind['vwap'];
            }
            return null;
        };
        $trySma21 = function(array $ind): ?float {
            $sma = $ind['sma'] ?? null;
            if (\is_array($sma) && isset($sma[21]) && \is_finite((float)$sma[21]) && (float)$sma[21] > 0.0) {
                return (float)$sma[21];
            }
            return null;
        };

        if ($preferVwap) {
            $pivot = $tryVwap($ind);
            $pivotSrc = $pivot !== null ? 'vwap' : null;
            if ($pivot === null) {
                $pivot = $trySma21($ind);
                $pivotSrc = $pivot !== null ? 'sma21' : $pivotSrc;
            }
        } else {
            $pivot = $trySma21($ind);
            $pivotSrc = $pivot !== null ? 'sma21' : null;
            if ($pivot === null) {
                $pivot = $tryVwap($ind);
                $pivotSrc = $pivot !== null ? 'vwap' : $pivotSrc;
            }
        }

        if (!\is_finite($pivot) || $pivot === null || $pivot <= 0.0) {
            $this->positionsLogger?->info('entry_zone.open_no_pivot', [
                'symbol' => $symbol,
                'tf' => $pivotTf,
                'decision_key' => $decisionKey,
                'reason' => 'pivot_not_available',
            ]);
            // Impossible de calculer une zone sans pivot raisonnable
            return new EntryZone(
                min: PHP_FLOAT_MIN,
                max: PHP_FLOAT_MAX,
                rationale: 'open zone (no pivot)',
                metadata: ['timeframe' => $pivotTf, 'k_atr' => $kAtr]
            );
        }

        // 2) Calcule la demi-largeur basée sur ATR avec bornes relatives au prix
        $halfFromAtr = (\is_finite((float)$atr) && $atr !== null && $atr > 0.0)
            ? $kAtr * (float)$atr
            : 0.0;

        $minHalf = $pivot * $wMin;
        $maxHalf = $pivot * $wMax;
        $half = max($halfFromAtr, $minHalf);
        $half = min($half, $maxHalf);

        if (!\is_finite($half) || $half <= 0.0) {
            $this->positionsLogger?->info('entry_zone.open_invalid_width', [
                'symbol' => $symbol,
                'pivot' => $pivot,
                'half' => $half,
                'decision_key' => $decisionKey,
                'reason' => 'invalid_zone_width',
            ]);
            // Sécurité: si la largeur est invalide, ne pas bloquer
            return new EntryZone(
                min: PHP_FLOAT_MIN,
                max: PHP_FLOAT_MAX,
                rationale: 'open zone (invalid width)',
                metadata: ['timeframe' => $tf, 'pivot' => $pivot, 'k_atr' => $kAtr]
            );
        }

        // 3) Asymétrie optionnelle selon le side
        $lowDelta = $half;
        $highDelta = $half;
        if ($side instanceof Side) {
            if ($side === Side::Long) {
                $lowDelta = $half * (1.0 + $bias);
                $highDelta = $half * (1.0 - $bias);
            } elseif ($side === Side::Short) {
                $lowDelta = $half * (1.0 - $bias);
                $highDelta = $half * (1.0 + $bias);
            }
        }

        $low  = $pivot - $lowDelta;
        $high = $pivot + $highDelta;

        // 4) Quantification au pas d'échange si demandé (bornes inclusives)
        if ($quantize && \is_int($pricePrecision)) {
            $low  = TickQuantizer::quantize($low, $pricePrecision);      // vers le bas
            $high = TickQuantizer::quantizeUp($high, $pricePrecision);   // vers le haut
        }

        $rationale = sprintf(
            '%s@%s -%.2f%%/+%.2f%% (k_atr=%.2f, clamp=%.2f%%..%.2f%%, bias=%.2f)',
            $pivotSrc ?? 'pivot',
            $pivotTf,
            100.0 * ($lowDelta / max(1e-12, $pivot)),
            100.0 * ($highDelta / max(1e-12, $pivot)),
            $kAtr,
            100.0 * $wMin,
            100.0 * $wMax,
            $bias
        );

        $this->positionsLogger?->debug('entry_zone.computed', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'pivot_tf' => $pivotTf,
            'atr_tf' => $atrTf,
            'pivot' => $pivot,
            'pivot_src' => $pivotSrc,
            'atr' => $atr,
            'half' => $half,
            'bias' => $bias,
            'min' => $low,
            'max' => $high,
            'reason' => 'entry_zone_calculated',
        ]);

        return new EntryZone(
            min: $low,
            max: $high,
            rationale: $rationale,
            createdAt: new \DateTimeImmutable(),
            ttlSec: $ttlSec,
            metadata: [
                'timeframe' => $pivotTf,
                'atr_tf' => $atrTf,
                'pivot' => $pivot,
                'pivot_source' => $pivotSrc,
                'atr' => $atr,
                'k_atr' => $kAtr,
                'width_pct' => ($high - $low) / max($pivot, 1e-8),
            ],
        );
    }

    /**
     * Charge la config selon le mode (même mécanisme que validations.{mode}.yaml)
     * @param string|null $mode Mode de configuration (ex: 'regular', 'scalping')
     * @return TradeEntryConfig|null
     */
    private function getConfigForMode(?string $mode): ?TradeEntryConfig
    {
        if ($this->configProvider === null) {
            return $this->defaultConfig;
        }

        $resolvedMode = $this->modeContext?->resolve($mode) ?? ($mode ?? '');
        if ($resolvedMode === '') {
            return $this->defaultConfig;
        }

        try {
            return $this->configProvider->getConfigForMode($resolvedMode);
        } catch (\RuntimeException $e) {
            $this->positionsLogger?->warning('entry_zone_calculator.mode_not_found', [
                'mode' => $resolvedMode,
                'error' => $e->getMessage(),
                'fallback' => 'default_config',
            ]);
            if ($this->modeContext !== null) {
                $fallbackMode = $this->modeContext->resolve(null);
                if ($fallbackMode !== $resolvedMode) {
                    return $this->configProvider->getConfigForMode($fallbackMode);
                }
            }
            return $this->defaultConfig;
        }
    }
}
