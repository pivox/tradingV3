<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Service;

use App\Domain\PostValidation\Dto\EntryZoneDto;
use App\Domain\PostValidation\Dto\MarketDataDto;
use App\Config\TradingParameters;
use Psr\Log\LoggerInterface;

/**
 * Service de calcul de la zone d'entrée selon les spécifications Post-Validation
 * 
 * Ancrages : VWAP intraday, niveaux microstructure (bid/ask, spread, profondeur),
 * contexte ATR (largeur de zone proportionnelle à la volatilité)
 */
final class EntryZoneService
{
    public function __construct(
        private readonly TradingParameters $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Calcule la zone d'entrée pour un symbole et un côté donnés
     */
    public function calculateEntryZone(
        string $symbol,
        string $side,
        MarketDataDto $marketData,
        string $executionTimeframe
    ): EntryZoneDto {
        $this->logger->info('[EntryZone] Calculating entry zone', [
            'symbol' => $symbol,
            'side' => $side,
            'timeframe' => $executionTimeframe
        ]);

        $config = $this->getEntryZoneConfig();
        $atrValue = $this->getAtrValue($marketData, $executionTimeframe);
        
        // Calcul de la largeur de zone basée sur ATR
        $zoneWidth = $this->calculateZoneWidth($atrValue, $marketData->lastPrice, $config);
        
        // Construction de la zone selon le côté
        [$entryMin, $entryMax] = $this->buildEntryZone(
            $side,
            $marketData,
            $zoneWidth,
            $config
        );

        // Filtre de qualité
        $qualityPassed = $this->checkQualityFilters($marketData, $config);

        // Métriques de preuve
        $evidence = $this->buildEvidence($marketData, $atrValue, $zoneWidth, $qualityPassed);

        $entryZone = new EntryZoneDto(
            symbol: $symbol,
            side: $side,
            entryMin: $entryMin,
            entryMax: $entryMax,
            zoneWidth: $zoneWidth,
            vwapAnchor: $marketData->vwap,
            atrValue: $atrValue,
            spreadBps: $marketData->spreadBps,
            depthTopUsd: $marketData->depthTopUsd,
            qualityPassed: $qualityPassed,
            evidence: $evidence,
            timestamp: time()
        );

        $this->logger->info('[EntryZone] Entry zone calculated', [
            'symbol' => $symbol,
            'side' => $side,
            'entry_min' => $entryMin,
            'entry_max' => $entryMax,
            'zone_width_bps' => $entryZone->getZoneWidthBps(),
            'quality_passed' => $qualityPassed
        ]);

        return $entryZone;
    }

    /**
     * Calcule la largeur de zone basée sur ATR
     */
    private function calculateZoneWidth(float $atrValue, float $lastPrice, array $config): float
    {
        $kAtr = $config['k_atr'] ?? 0.35;
        $wMin = $config['w_min'] ?? 0.0005;
        $wMax = $config['w_max'] ?? 0.0100;

        $zoneWidth = $kAtr * $atrValue;
        
        // Clamp entre w_min et w_max
        $zoneWidth = max($wMin, min($wMax, $zoneWidth));
        
        // Convertir en valeur absolue si nécessaire
        if ($zoneWidth < 1.0) {
            $zoneWidth = $zoneWidth * $lastPrice;
        }

        return $zoneWidth;
    }

    /**
     * Construit la zone d'entrée selon le côté (LONG/SHORT)
     */
    private function buildEntryZone(
        string $side,
        MarketDataDto $marketData,
        float $zoneWidth,
        array $config
    ): array {
        $vwap = $marketData->vwap;
        $bid1 = $marketData->bidPrice;
        $ask1 = $marketData->askPrice;
        $lastPrice = $marketData->lastPrice;

        if ($side === 'LONG') {
            // LONG : [max(vwap, bid1) - α*zone_width, min(vwap, ask1) + β*zone_width]
            $alpha = 0.3; // 30% de la zone en dessous
            $beta = 0.7;  // 70% de la zone au-dessus
            
            $entryMin = max($vwap, $bid1) - ($alpha * $zoneWidth);
            $entryMax = min($vwap, $ask1) + ($beta * $zoneWidth);
        } else {
            // SHORT : symétrique autour de VWAP / top-of-book
            $alpha = 0.7; // 70% de la zone au-dessus
            $beta = 0.3;  // 30% de la zone en dessous
            
            $entryMin = max($vwap, $ask1) - ($beta * $zoneWidth);
            $entryMax = min($vwap, $bid1) + ($alpha * $zoneWidth);
        }

        // Quantification aux pas de l'exchange
        if ($config['quantize_to_exchange_step'] ?? true) {
            $tickSize = $marketData->contractDetails['tick_size'] ?? 0.01;
            $entryMin = $this->quantizePrice($entryMin, $tickSize);
            $entryMax = $this->quantizePrice($entryMax, $tickSize);
        }

        return [$entryMin, $entryMax];
    }

    /**
     * Vérifie les filtres de qualité
     */
    private function checkQualityFilters(MarketDataDto $marketData, array $config): bool
    {
        $spreadMaxBps = $config['spread_bps_max'] ?? 2.0;
        $depthMinUsd = $config['depth_min_usd'] ?? 20000;
        $markIndexGapMaxBps = $config['mark_index_gap_bps_max'] ?? 15.0;

        $checks = [
            'spread_ok' => $marketData->spreadBps <= $spreadMaxBps,
            'depth_ok' => $marketData->depthTopUsd >= $depthMinUsd,
            'mark_index_gap_ok' => $marketData->getMarkIndexGapBps() <= $markIndexGapMaxBps,
            'data_fresh' => $marketData->isDataFresh()
        ];

        $allPassed = array_reduce($checks, fn($carry, $check) => $carry && $check, true);

        $this->logger->debug('[EntryZone] Quality filters check', [
            'checks' => $checks,
            'all_passed' => $allPassed
        ]);

        return $allPassed;
    }

    /**
     * Construit les métriques de preuve
     */
    private function buildEvidence(
        MarketDataDto $marketData,
        float $atrValue,
        float $zoneWidth,
        bool $qualityPassed
    ): array {
        return [
            'market_data' => [
                'last_price' => $marketData->lastPrice,
                'bid_ask_spread_bps' => $marketData->spreadBps,
                'depth_top_usd' => $marketData->depthTopUsd,
                'vwap' => $marketData->vwap,
                'mark_index_gap_bps' => $marketData->getMarkIndexGapBps(),
                'data_age_seconds' => $marketData->getPriceAgeSeconds()
            ],
            'atr_metrics' => [
                'atr_1m' => $marketData->atr1m,
                'atr_5m' => $marketData->atr5m,
                'atr_used' => $atrValue,
                'atr_pct_of_price' => $marketData->lastPrice > 0 ? ($atrValue / $marketData->lastPrice) * 100 : 0
            ],
            'zone_metrics' => [
                'zone_width' => $zoneWidth,
                'zone_width_bps' => $marketData->lastPrice > 0 ? ($zoneWidth / $marketData->lastPrice) * 10000 : 0,
                'quality_passed' => $qualityPassed
            ],
            'timestamp' => time()
        ];
    }

    /**
     * Obtient la valeur ATR selon le timeframe d'exécution
     */
    private function getAtrValue(MarketDataDto $marketData, string $executionTimeframe): float
    {
        return $executionTimeframe === '1m' ? $marketData->atr1m : $marketData->atr5m;
    }

    /**
     * Quantifie un prix selon le tick size de l'exchange
     */
    private function quantizePrice(float $price, float $tickSize): float
    {
        if ($tickSize <= 0) {
            return $price;
        }
        
        return round($price / $tickSize) * $tickSize;
    }

    /**
     * Obtient la configuration EntryZone depuis trading.yml
     */
    private function getEntryZoneConfig(): array
    {
        $config = $this->config->getTradingConf('post_validation');
        return $config['entry_zone'] ?? [];
    }
}
