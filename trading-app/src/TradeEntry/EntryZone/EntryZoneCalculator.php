<?php
declare(strict_types=1);

namespace App\TradeEntry\EntryZone;

use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\Types\Side;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\TradeEntry\Pricing\TickQuantizer;
use App\Service\TradingConfigService;
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
        private readonly ?TradingConfigService $config = null,
        private readonly ?string $defaultTfOverride = null,
        private readonly ?float $kAtrOverride = null,
        private readonly ?float $wMinOverride = null,
        private readonly ?float $wMaxOverride = null,
        private readonly ?float $asymBiasOverride = null,
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly ?LoggerInterface $flowLogger = null,
    ) {}

    public function compute(string $symbol, ?Side $side = null, ?int $pricePrecision = null): EntryZone
    {
        // Fallback si aucun provider n'est injecté
        if ($this->indicators === null) {
            $this->flowLogger?->debug('entry_zone.open_no_indicators', ['symbol' => $symbol]);
            return new EntryZone(min: PHP_FLOAT_MIN, max: PHP_FLOAT_MAX, rationale: 'open zone (no indicators)');
        }

        // Lecture config (avec valeurs par défaut robustes)
        $cfg = $this->config?->getConfig() ?? [];
        $post = $cfg['post_validation'] ?? [];
        $ez = $post['entry_zone'] ?? [];
        $execTf = $post['execution_timeframe']['default'] ?? null;

        $tf = $this->defaultTfOverride ?? (\is_string($execTf) && $execTf !== '' ? $execTf : self::DEFAULT_TF);
        $kAtr = $this->kAtrOverride ?? (isset($ez['k_atr']) && \is_numeric($ez['k_atr']) ? (float)$ez['k_atr'] : self::K_ATR);
        $wMin = $this->wMinOverride ?? (isset($ez['w_min']) && \is_numeric($ez['w_min']) ? (float)$ez['w_min'] : self::W_MIN);
        $wMax = $this->wMaxOverride ?? (isset($ez['w_max']) && \is_numeric($ez['w_max']) ? (float)$ez['w_max'] : self::W_MAX);
        $preferVwap = isset($ez['vwap_anchor']) ? (bool)$ez['vwap_anchor'] : true;
        $bias = $this->asymBiasOverride ?? (isset($ez['asym_bias']) && \is_numeric($ez['asym_bias']) ? max(0.0, min(0.95, (float)$ez['asym_bias'])) : self::ASYM_BIAS);
        $quantize = isset($ez['quantize_to_exchange_step']) ? (bool)$ez['quantize_to_exchange_step'] : true;

        // 1) Récupère ATR et liste pivot (vwap/sma...)
        $atr = $this->indicators->getAtr('entry_zone', $symbol, $tf);
        $list = $this->indicators->getListPivot('entry_zone', $symbol, $tf);

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
            $this->flowLogger?->debug('entry_zone.open_no_pivot', ['symbol' => $symbol, 'tf' => $tf]);
            // Impossible de calculer une zone sans pivot raisonnable
            return new EntryZone(min: PHP_FLOAT_MIN, max: PHP_FLOAT_MAX, rationale: 'open zone (no pivot)');
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
            $this->flowLogger?->debug('entry_zone.open_invalid_width', ['symbol' => $symbol, 'pivot' => $pivot, 'half' => $half]);
            // Sécurité: si la largeur est invalide, ne pas bloquer
            return new EntryZone(min: PHP_FLOAT_MIN, max: PHP_FLOAT_MAX, rationale: 'open zone (invalid width)');
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
            $tf,
            100.0 * ($lowDelta / max(1e-12, $pivot)),
            100.0 * ($highDelta / max(1e-12, $pivot)),
            $kAtr,
            100.0 * $wMin,
            100.0 * $wMax,
            $bias
        );

        $this->flowLogger?->debug('entry_zone.computed', [
            'symbol' => $symbol,
            'tf' => $tf,
            'pivot' => $pivot,
            'pivot_src' => $pivotSrc,
            'atr' => $atr,
            'half' => $half,
            'bias' => $bias,
            'min' => $low,
            'max' => $high,
        ]);

        return new EntryZone(min: $low, max: $high, rationale: $rationale);
    }
}
