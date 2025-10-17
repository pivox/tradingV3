<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Service;

use App\Domain\PostValidation\Dto\MarketDataDto;
use App\Domain\PostValidation\Dto\EntryZoneDto;
use App\Domain\PostValidation\Dto\OrderPlanDto;
use App\Config\TradingParameters;
use Psr\Log\LoggerInterface;

/**
 * Service des garde-fous pour l'étape Post-Validation
 * 
 * Vérifie :
 * - Stale data (ticker > 2s)
 * - Slippage ex-ante
 * - Liquidité suffisante
 * - Risk limits (levier <= bracket max)
 * - Funding spike
 * - Mark/Index gap
 */
final class PostValidationGuards
{
    public function __construct(
        private readonly TradingParameters $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Exécute tous les garde-fous avant l'exécution
     */
    public function executeGuards(
        MarketDataDto $marketData,
        EntryZoneDto $entryZone,
        OrderPlanDto $orderPlan
    ): array {
        $this->logger->info('[PostValidationGuards] Executing guards', [
            'symbol' => $marketData->symbol,
            'side' => $entryZone->side
        ]);

        $guards = [
            'stale_data' => $this->checkStaleData($marketData),
            'slippage' => $this->checkSlippage($marketData, $entryZone, $orderPlan),
            'liquidity' => $this->checkLiquidity($marketData, $orderPlan),
            'risk_limits' => $this->checkRiskLimits($marketData, $orderPlan),
            'funding_spike' => $this->checkFundingSpike($marketData),
            'mark_index_gap' => $this->checkMarkIndexGap($marketData)
        ];

        $allPassed = array_reduce($guards, fn($carry, $guard) => $carry && $guard['passed'], true);

        $this->logger->info('[PostValidationGuards] Guards execution completed', [
            'symbol' => $marketData->symbol,
            'all_passed' => $allPassed,
            'failed_guards' => array_keys(array_filter($guards, fn($guard) => !$guard['passed']))
        ]);

        return [
            'all_passed' => $allPassed,
            'guards' => $guards,
            'failed_count' => count(array_filter($guards, fn($guard) => !$guard['passed']))
        ];
    }

    /**
     * Vérifie si les données sont obsolètes
     */
    private function checkStaleData(MarketDataDto $marketData): array
    {
        $config = $this->getGuardsConfig();
        $staleThreshold = $config['stale_ticker_sec'] ?? 2;
        
        $isStale = $marketData->isStale || $marketData->getPriceAgeSeconds() > $staleThreshold;
        
        $result = [
            'passed' => !$isStale,
            'check' => 'stale_data',
            'value' => $marketData->getPriceAgeSeconds(),
            'threshold' => $staleThreshold,
            'message' => $isStale ? 'Market data is stale' : 'Market data is fresh'
        ];

        $this->logger->debug('[PostValidationGuards] Stale data check', $result);
        return $result;
    }

    /**
     * Vérifie le slippage ex-ante
     */
    private function checkSlippage(
        MarketDataDto $marketData,
        EntryZoneDto $entryZone,
        OrderPlanDto $orderPlan
    ): array {
        $config = $this->getGuardsConfig();
        $maxSlipBps = $config['max_slip_bps'] ?? 5;
        
        $entryPrice = $orderPlan->getEntryPrice();
        $midPrice = $marketData->getMidPrice();
        
        if ($midPrice <= 0) {
            return [
                'passed' => false,
                'check' => 'slippage',
                'value' => 0,
                'threshold' => $maxSlipBps,
                'message' => 'Invalid mid price for slippage calculation'
            ];
        }
        
        $slippageBps = abs(($entryPrice - $midPrice) / $midPrice) * 10000;
        $passed = $slippageBps <= $maxSlipBps;
        
        $result = [
            'passed' => $passed,
            'check' => 'slippage',
            'value' => $slippageBps,
            'threshold' => $maxSlipBps,
            'message' => $passed ? 'Slippage within limits' : 'Slippage exceeds limits'
        ];

        $this->logger->debug('[PostValidationGuards] Slippage check', $result);
        return $result;
    }

    /**
     * Vérifie la liquidité suffisante
     */
    private function checkLiquidity(MarketDataDto $marketData, OrderPlanDto $orderPlan): array
    {
        $config = $this->getGuardsConfig();
        $minLiquidityUsd = $config['min_liquidity_usd'] ?? 10000;
        
        $requiredLiquidity = $orderPlan->getTotalNotional();
        $availableLiquidity = $marketData->depthTopUsd;
        
        $passed = $availableLiquidity >= $requiredLiquidity;
        
        $result = [
            'passed' => $passed,
            'check' => 'liquidity',
            'value' => $availableLiquidity,
            'threshold' => max($requiredLiquidity, $minLiquidityUsd),
            'message' => $passed ? 'Sufficient liquidity' : 'Insufficient liquidity'
        ];

        $this->logger->debug('[PostValidationGuards] Liquidity check', $result);
        return $result;
    }

    /**
     * Vérifie les limites de risque (levier)
     */
    private function checkRiskLimits(MarketDataDto $marketData, OrderPlanDto $orderPlan): array
    {
        $leverage = $orderPlan->leverage;
        $leverageBracket = $marketData->leverageBracket;
        
        $maxLeverage = $this->getMaxLeverageFromBracket($leverageBracket);
        $passed = $leverage <= $maxLeverage;
        
        $result = [
            'passed' => $passed,
            'check' => 'risk_limits',
            'value' => $leverage,
            'threshold' => $maxLeverage,
            'message' => $passed ? 'Leverage within limits' : 'Leverage exceeds bracket limits'
        ];

        $this->logger->debug('[PostValidationGuards] Risk limits check', $result);
        return $result;
    }

    /**
     * Vérifie les spikes de funding
     */
    private function checkFundingSpike(MarketDataDto $marketData): array
    {
        $config = $this->getGuardsConfig();
        $fundingCutoffMin = $config['funding_cutoff_min'] ?? 5;
        $maxFundingRate = $config['max_funding_rate'] ?? 0.01; // 1%
        
        // Vérifier si funding est imminent
        $now = time();
        $nextFunding = $this->getNextFundingTime($now);
        $minutesToFunding = ($nextFunding - $now) / 60;
        
        $fundingImminent = $minutesToFunding <= $fundingCutoffMin;
        $fundingSpike = abs($marketData->fundingRate) > $maxFundingRate;
        
        $passed = !$fundingImminent && !$fundingSpike;
        
        $result = [
            'passed' => $passed,
            'check' => 'funding_spike',
            'value' => $marketData->fundingRate,
            'threshold' => $maxFundingRate,
            'minutes_to_funding' => $minutesToFunding,
            'funding_cutoff' => $fundingCutoffMin,
            'message' => $passed ? 'Funding conditions OK' : 'Funding spike or imminent funding'
        ];

        $this->logger->debug('[PostValidationGuards] Funding spike check', $result);
        return $result;
    }

    /**
     * Vérifie l'écart Mark/Index
     */
    private function checkMarkIndexGap(MarketDataDto $marketData): array
    {
        $config = $this->getGuardsConfig();
        $maxGapBps = $config['mark_index_gap_bps_max'] ?? 15;
        
        $gapBps = $marketData->getMarkIndexGapBps();
        $passed = $gapBps <= $maxGapBps;
        
        $result = [
            'passed' => $passed,
            'check' => 'mark_index_gap',
            'value' => $gapBps,
            'threshold' => $maxGapBps,
            'message' => $passed ? 'Mark/Index gap within limits' : 'Mark/Index gap too wide'
        ];

        $this->logger->debug('[PostValidationGuards] Mark/Index gap check', $result);
        return $result;
    }

    /**
     * Obtient le levier maximum depuis le bracket
     */
    private function getMaxLeverageFromBracket(array $leverageBracket): float
    {
        if (empty($leverageBracket)) {
            return 20.0; // Default
        }
        
        $maxLeverage = 0;
        foreach ($leverageBracket as $bracket) {
            $maxLeverage = max($maxLeverage, (float) $bracket['max_leverage']);
        }
        
        return $maxLeverage;
    }

    /**
     * Calcule le prochain temps de funding (approximation)
     */
    private function getNextFundingTime(int $currentTime): int
    {
        // Funding toutes les 8 heures (00:00, 08:00, 16:00 UTC)
        $hour = (int) gmdate('H', $currentTime);
        $nextHour = (int) (ceil($hour / 8) * 8);
        
        if ($nextHour >= 24) {
            $nextHour = 0;
            $currentTime += 86400; // +1 jour
        }
        
        return mktime($nextHour, 0, 0, (int) gmdate('m', $currentTime), (int) gmdate('d', $currentTime), (int) gmdate('Y', $currentTime));
    }

    /**
     * Obtient la configuration des garde-fous
     */
    private function getGuardsConfig(): array
    {
        $config = $this->config->getTradingConf('post_validation');
        return $config['guards'] ?? [];
    }
}
