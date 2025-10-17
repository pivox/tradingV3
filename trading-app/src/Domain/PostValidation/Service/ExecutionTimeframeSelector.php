<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Service;

use App\Domain\PostValidation\Dto\MarketDataDto;
use App\Config\TradingParameters;
use Psr\Log\LoggerInterface;

/**
 * Service de sélection dynamique du timeframe d'exécution (1m vs 5m)
 * 
 * Règle déterministe :
 * - Base 5m (réduit bruit, coûts)
 * - Basculer 1m si volatilité élevée + liquidité OK + confirmation directionnelle
 * - Revenir à 5m si ATR retombe ou spreads s'écartent
 */
final class ExecutionTimeframeSelector
{
    public function __construct(
        private readonly TradingParameters $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sélectionne le timeframe d'exécution optimal
     */
    public function selectExecutionTimeframe(
        MarketDataDto $marketData,
        array $mtfContext // Contexte des timeframes 5m et 15m pour l'alignement
    ): string {
        $config = $this->getExecutionTimeframeConfig();
        $defaultTf = $config['default'] ?? '5m';
        
        $this->logger->info('[TimeframeSelector] Selecting execution timeframe', [
            'symbol' => $marketData->symbol,
            'default' => $defaultTf,
            'atr_1m_pct' => $this->getAtrPercentage($marketData->atr1m, $marketData->lastPrice),
            'atr_5m_pct' => $this->getAtrPercentage($marketData->atr5m, $marketData->lastPrice)
        ]);

        // Vérification des conditions pour upshift vers 1m
        if ($this->shouldUpshiftTo1m($marketData, $mtfContext, $config)) {
            $this->logger->info('[TimeframeSelector] Upshifting to 1m', [
                'symbol' => $marketData->symbol,
                'reason' => 'High volatility + good liquidity + MTF alignment'
            ]);
            return '1m';
        }

        // Vérification des conditions pour downshift vers 5m
        if ($this->shouldDownshiftTo5m($marketData, $config)) {
            $this->logger->info('[TimeframeSelector] Downshifting to 5m', [
                'symbol' => $marketData->symbol,
                'reason' => 'Low volatility or poor liquidity'
            ]);
            return '5m';
        }

        $this->logger->info('[TimeframeSelector] Using default timeframe', [
            'symbol' => $marketData->symbol,
            'timeframe' => $defaultTf
        ]);

        return $defaultTf;
    }

    /**
     * Vérifie si on doit upshifter vers 1m
     */
    private function shouldUpshiftTo1m(
        MarketDataDto $marketData,
        array $mtfContext,
        array $config
    ): bool {
        $upshiftConfig = $config['upshift_to_1m'] ?? [];
        
        // Conditions requises pour upshift
        $conditions = [
            'atr_high' => $this->isAtrHigh($marketData->atr1m, $marketData->lastPrice, $upshiftConfig),
            'spread_ok' => $marketData->spreadBps <= ($upshiftConfig['spread_bps_max'] ?? 2.0),
            'depth_ok' => $marketData->depthTopUsd >= ($upshiftConfig['depth_min_usd'] ?? 30000),
            'mtf_alignment' => $this->checkMtfAlignment($mtfContext, $upshiftConfig)
        ];

        $shouldUpshift = array_reduce($conditions, fn($carry, $condition) => $carry && $condition, true);

        $this->logger->debug('[TimeframeSelector] Upshift conditions check', [
            'symbol' => $marketData->symbol,
            'conditions' => $conditions,
            'should_upshift' => $shouldUpshift
        ]);

        return $shouldUpshift;
    }

    /**
     * Vérifie si on doit downshifter vers 5m
     */
    private function shouldDownshiftTo5m(MarketDataDto $marketData, array $config): bool
    {
        $downshiftConfig = $config['downshift_to_5m'] ?? [];
        
        $conditions = [
            'atr_low' => $this->isAtrLow($marketData->atr1m, $marketData->lastPrice, $downshiftConfig),
            'spread_wide' => $marketData->spreadBps > ($downshiftConfig['spread_bps_max'] ?? 4.0)
        ];

        $shouldDownshift = array_reduce($conditions, fn($carry, $condition) => $carry || $condition, false);

        $this->logger->debug('[TimeframeSelector] Downshift conditions check', [
            'symbol' => $marketData->symbol,
            'conditions' => $conditions,
            'should_downshift' => $shouldDownshift
        ]);

        return $shouldDownshift;
    }

    /**
     * Vérifie si l'ATR est élevé (condition pour upshift)
     */
    private function isAtrHigh(float $atr1m, float $lastPrice, array $config): bool
    {
        $atrPctHi = $config['atr_pct_hi'] ?? 0.12; // 0.12% par défaut
        $atrPct = $this->getAtrPercentage($atr1m, $lastPrice);
        
        return $atrPct >= $atrPctHi;
    }

    /**
     * Vérifie si l'ATR est bas (condition pour downshift)
     */
    private function isAtrLow(float $atr1m, float $lastPrice, array $config): bool
    {
        $atrPctLo = $config['atr_pct_lo'] ?? 0.07; // 0.07% par défaut
        $atrPct = $this->getAtrPercentage($atr1m, $lastPrice);
        
        return $atrPct < $atrPctLo;
    }

    /**
     * Vérifie l'alignement des timeframes MTF
     */
    private function checkMtfAlignment(array $mtfContext, array $config): bool
    {
        if (!($config['require_mtf_alignment'] ?? true)) {
            return true;
        }

        // Vérifier que 5m et 15m sont alignés (même signal)
        $tf5m = $mtfContext['5m'] ?? null;
        $tf15m = $mtfContext['15m'] ?? null;

        if (!$tf5m || !$tf15m) {
            return false;
        }

        $alignment = ($tf5m['signal_side'] ?? null) === ($tf15m['signal_side'] ?? null);
        
        $this->logger->debug('[TimeframeSelector] MTF alignment check', [
            'tf_5m_signal' => $tf5m['signal_side'] ?? null,
            'tf_15m_signal' => $tf15m['signal_side'] ?? null,
            'aligned' => $alignment
        ]);

        return $alignment;
    }

    /**
     * Calcule le pourcentage ATR par rapport au prix
     */
    private function getAtrPercentage(float $atr, float $price): float
    {
        return $price > 0 ? ($atr / $price) * 100 : 0.0;
    }

    /**
     * Obtient la configuration ExecutionTimeframe depuis trading.yml
     */
    private function getExecutionTimeframeConfig(): array
    {
        $config = $this->config->getTradingConf('post_validation');
        return $config['execution_timeframe'] ?? [];
    }
}
