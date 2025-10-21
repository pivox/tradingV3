<?php

declare(strict_types=1);

namespace App\Domain\Position\Service;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Leverage\Dto\LeverageCalculationDto;
use App\Domain\Position\Dto\PositionConfigDto;
use App\Domain\Position\Dto\PositionOpeningDto;
use App\Entity\Contract;
use App\Repository\ContractRepository;
use Psr\Log\LoggerInterface;

class PositionOpeningService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ContractRepository $contractRepository,
    ) {
    }

    public function calculatePositionOpening(
        string $symbol,
        SignalSide $side,
        float $currentPrice,
        float $atr,
        float $accountBalance,
        LeverageCalculationDto $leverageCalculation,
        PositionConfigDto $config
    ): PositionOpeningDto {
        // Calculer les prix de stop loss et take profit
        $stopLossPrice = $this->calculateStopLossPrice($currentPrice, $atr, $side, $config->slAtrMultiplier);
        $takeProfitPrice = $this->calculateTakeProfitPrice($currentPrice, $atr, $side, $config->tpAtrMultiplier);

        // Déterminer la marge allouée (budget vs risque %)
        $riskAmountPct = $accountBalance * ($leverageCalculation->riskPercent / 100);

        $marginBudget = null;
        if (strtolower($config->budgetMode) === 'fixed_or_available' && $config->fixedUsdtIfAvailable > 0) {
            $marginBudget = min($config->fixedUsdtIfAvailable, $accountBalance);
        }
        if ($marginBudget === null) {
            $marginBudget = min($riskAmountPct, $accountBalance);
        }
        if ($marginBudget <= 0) {
            throw new \InvalidArgumentException('Calculated margin budget must be greater than 0');
        }

        $positionSize = $this->calculatePositionSizeFromBudget(
            $marginBudget,
            $leverageCalculation->finalLeverage,
            $currentPrice
        );

        $maxByMargin = $this->calculateMaxContractsByMargin(
            $accountBalance,
            $leverageCalculation->finalLeverage,
            $currentPrice
        );

        if ($maxByMargin < $config->minOrderSize) {
            throw new \InvalidArgumentException(sprintf(
                'Insufficient balance (%f) to reach minimum order size %f at leverage %f',
                $accountBalance,
                $config->minOrderSize,
                $leverageCalculation->finalLeverage
            ));
        }

        // Appliquer les limites de taille de position
        $desiredPositionSize = $positionSize;
        $contractMaxOrderSize = $this->resolveContractMaxOrderSize($symbol);
        if ($contractMaxOrderSize !== null && $contractMaxOrderSize > 0) {
            $maxByMargin = min($maxByMargin, (int) floor($contractMaxOrderSize));
        }

        $positionSize = $this->applyPositionSizeLimits(
            $positionSize,
            $config,
            $accountBalance,
            $maxByMargin,
            $desiredPositionSize,
            $contractMaxOrderSize
        );

        // Calculer les métriques de risque
        $riskMetrics = $this->calculateRiskMetrics(
            $positionSize,
            $currentPrice,
            $stopLossPrice,
            $takeProfitPrice,
            $accountBalance,
            $leverageCalculation->finalLeverage
        );

        // Recalculer le risque engagé et le profit potentiel après clamps
        $stopLossDistanceAbs = abs($currentPrice - $stopLossPrice);
        $actualRiskAmount = $positionSize * $stopLossDistanceAbs;
        $potentialProfit = $this->calculatePotentialProfit($positionSize, $currentPrice, $takeProfitPrice, $side);

        // Paramètres d'exécution
        $executionParams = [
            'order_type' => $config->orderType,
            'time_in_force' => $config->timeInForce,
            'enable_partial_fills' => $config->enablePartialFills,
            'min_order_size' => $config->minOrderSize,
            'max_order_size' => $config->maxOrderSize,
            'open_type' => $config->openType,
            'margin_budget' => $marginBudget,
            'risk_amount_pct' => $riskAmountPct,
            'contract_max_order_size' => $contractMaxOrderSize,
        ];

        $this->logger->info('[Position Opening] Calculated position opening', [
            'symbol' => $symbol,
            'side' => $side->value,
            'position_size' => $positionSize,
            'leverage' => $leverageCalculation->finalLeverage,
            'risk_amount' => $actualRiskAmount,
            'margin_budget' => $marginBudget,
            'contract_max_order_size' => $contractMaxOrderSize,
        ]);

        return new PositionOpeningDto(
            symbol: $symbol,
            side: $side,
            leverage: $leverageCalculation->finalLeverage,
            positionSize: $positionSize,
            entryPrice: $currentPrice,
            stopLossPrice: $stopLossPrice,
            takeProfitPrice: $takeProfitPrice,
            riskAmount: $actualRiskAmount,
            potentialProfit: $potentialProfit,
            riskMetrics: $riskMetrics,
            executionParams: $executionParams
        );
    }

    private function calculateStopLossPrice(float $currentPrice, float $atr, SignalSide $side, float $atrMultiplier): float
    {
        $stopDistance = $atr * $atrMultiplier;
        
        return match ($side) {
            SignalSide::LONG => $currentPrice - $stopDistance,
            SignalSide::SHORT => $currentPrice + $stopDistance,
            default => throw new \InvalidArgumentException('Invalid signal side for stop loss calculation')
        };
    }

    private function calculateTakeProfitPrice(float $currentPrice, float $atr, SignalSide $side, float $atrMultiplier): float
    {
        $profitDistance = $atr * $atrMultiplier;
        
        return match ($side) {
            SignalSide::LONG => $currentPrice + $profitDistance,
            SignalSide::SHORT => $currentPrice - $profitDistance,
            default => throw new \InvalidArgumentException('Invalid signal side for take profit calculation')
        };
    }

    private function calculatePositionSizeFromBudget(float $budget, float $leverage, float $entryPrice): float
    {
        if ($budget <= 0) {
            throw new \InvalidArgumentException('Budget must be greater than 0');
        }

        if ($leverage <= 0) {
            throw new \InvalidArgumentException('Leverage must be greater than 0');
        }

        if ($entryPrice <= 0) {
            throw new \InvalidArgumentException('Entry price must be greater than 0');
        }

        return ($budget * $leverage) / $entryPrice;
    }

    private function calculateMaxContractsByMargin(float $accountBalance, float $leverage, float $entryPrice): int
    {
        if ($leverage <= 0) {
            throw new \InvalidArgumentException('Leverage must be greater than 0');
        }

        if ($entryPrice <= 0) {
            throw new \InvalidArgumentException('Entry price must be greater than 0');
        }

        $maxContracts = (int) floor(($accountBalance * $leverage) / $entryPrice);

        return max(0, $maxContracts);
    }

    private function resolveContractMaxOrderSize(string $symbol): ?float
    {
        try {
            $contract = $this->contractRepository->findBySymbol(strtoupper($symbol));
        } catch (\Throwable $e) {
            $this->logger->warning('[Position Opening] Unable to load contract info', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$contract instanceof Contract) {
            return null;
        }

        $candidates = array_filter([
            $this->parseContractNumeric($contract->getMaxSize()),
            $this->parseContractNumeric($contract->getMaxVolume()),
            $this->parseContractNumeric($contract->getMarketMaxVolume()),
        ], static fn (?float $value) => $value !== null && $value > 0.0);

        if (empty($candidates)) {
            return null;
        }

        return min($candidates);
    }

    private function parseContractNumeric(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        $floatValue = (float) $trimmed;
        return $floatValue > 0.0 ? $floatValue : null;
    }

    private function applyPositionSizeLimits(
        float $positionSize,
        PositionConfigDto $config,
        float $accountBalance,
        int $maxContractsByMargin,
        float $desiredPositionSize,
        ?float $contractMaxOrderSize
    ): float {
        // Limite basée sur le pourcentage maximum de la balance
        $maxSizeByBalance = $accountBalance * ($config->maxPositionSize / 100);

        // Limite absolue
        $maxSizeAbsolute = $config->maxOrderSize;

        // Limite minimale
        $minSize = $config->minOrderSize;

        $isFixedBudget = strtolower($config->budgetMode) === 'fixed_or_available';
        if ($isFixedBudget && $desiredPositionSize > $maxSizeByBalance) {
            $maxSizeByBalance = $desiredPositionSize;
        }
        if ($isFixedBudget && $desiredPositionSize > $maxSizeAbsolute) {
            $maxSizeAbsolute = $desiredPositionSize;
        }

        if ($contractMaxOrderSize !== null && $contractMaxOrderSize > 0) {
            $maxSizeByBalance = min($maxSizeByBalance, $contractMaxOrderSize);
            $maxSizeAbsolute = $maxSizeAbsolute > 0.0
                ? min($maxSizeAbsolute, $contractMaxOrderSize)
                : $contractMaxOrderSize;
            $maxContractsByMargin = min($maxContractsByMargin, (int) floor($contractMaxOrderSize));
        }

        $clamped = min($positionSize, $maxSizeByBalance, $maxSizeAbsolute, $maxContractsByMargin);
        $clamped = max(0.0, $clamped);

        if ($clamped < $minSize) {
            if ($minSize > $maxContractsByMargin) {
                throw new \InvalidArgumentException(sprintf(
                    'Insufficient margin to place minimum order size %f (max contracts %d)',
                    $minSize,
                    $maxContractsByMargin
                ));
            }
            $clamped = $minSize;
        }

        return $clamped;
    }

    private function calculateRiskMetrics(
        float $positionSize,
        float $entryPrice,
        float $stopLossPrice,
        float $takeProfitPrice,
        float $accountBalance,
        float $leverage
    ): array {
        $positionValue = $positionSize * $entryPrice;
        $marginRequired = $positionValue / $leverage;
        $marginPercent = ($marginRequired / $accountBalance) * 100;

        $stopLossDistance = abs($entryPrice - $stopLossPrice);
        $takeProfitDistance = abs($takeProfitPrice - $entryPrice);
        $riskRewardRatio = $takeProfitDistance / $stopLossDistance;

        return [
            'position_value' => $positionValue,
            'margin_required' => $marginRequired,
            'margin_percent' => $marginPercent,
            'stop_loss_distance' => $stopLossDistance,
            'take_profit_distance' => $takeProfitDistance,
            'risk_reward_ratio' => $riskRewardRatio,
            'leverage_used' => $leverage
        ];
    }

    private function calculatePotentialProfit(float $positionSize, float $entryPrice, float $takeProfitPrice, SignalSide $side): float
    {
        $priceDifference = abs($takeProfitPrice - $entryPrice);
        
        return match ($side) {
            SignalSide::LONG => $positionSize * $priceDifference,
            SignalSide::SHORT => $positionSize * $priceDifference,
            default => 0.0
        };
    }
}
