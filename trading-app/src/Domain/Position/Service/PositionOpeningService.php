<?php

declare(strict_types=1);

namespace App\Domain\Position\Service;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Leverage\Dto\LeverageCalculationDto;
use App\Domain\Position\Dto\PositionConfigDto;
use App\Domain\Position\Dto\PositionOpeningDto;
use Psr\Log\LoggerInterface;

class PositionOpeningService
{
    public function __construct(
        private readonly LoggerInterface $logger
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

        // Calculer la distance de stop en pourcentage
        $stopDistance = abs($currentPrice - $stopLossPrice) / $currentPrice;

        // Calculer la taille de position basée sur le risque
        $riskAmount = $accountBalance * ($leverageCalculation->riskPercent / 100);
        $positionSize = $this->calculatePositionSize($riskAmount, $stopDistance, $leverageCalculation->finalLeverage);

        // Appliquer les limites de taille de position
        $positionSize = $this->applyPositionSizeLimits($positionSize, $config, $accountBalance);

        // Calculer les métriques de risque
        $riskMetrics = $this->calculateRiskMetrics(
            $positionSize,
            $currentPrice,
            $stopLossPrice,
            $takeProfitPrice,
            $accountBalance,
            $leverageCalculation->finalLeverage
        );

        // Calculer le profit potentiel
        $potentialProfit = $this->calculatePotentialProfit($positionSize, $currentPrice, $takeProfitPrice, $side);

        // Paramètres d'exécution
        $executionParams = [
            'order_type' => $config->orderType,
            'time_in_force' => $config->timeInForce,
            'enable_partial_fills' => $config->enablePartialFills,
            'min_order_size' => $config->minOrderSize,
            'max_order_size' => $config->maxOrderSize,
            'open_type' => $config->openType,
        ];

        $this->logger->info('[Position Opening] Calculated position opening', [
            'symbol' => $symbol,
            'side' => $side->value,
            'position_size' => $positionSize,
            'leverage' => $leverageCalculation->finalLeverage,
            'risk_amount' => $riskAmount
        ]);

        return new PositionOpeningDto(
            symbol: $symbol,
            side: $side,
            leverage: $leverageCalculation->finalLeverage,
            positionSize: $positionSize,
            entryPrice: $currentPrice,
            stopLossPrice: $stopLossPrice,
            takeProfitPrice: $takeProfitPrice,
            riskAmount: $riskAmount,
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

    private function calculatePositionSize(float $riskAmount, float $stopDistance, float $leverage): float
    {
        if ($stopDistance <= 0) {
            throw new \InvalidArgumentException('Stop distance must be greater than 0');
        }

        // Taille de position = (Montant de risque / Distance de stop) * Levier
        return ($riskAmount / $stopDistance) * $leverage;
    }

    private function applyPositionSizeLimits(float $positionSize, PositionConfigDto $config, float $accountBalance): float
    {
        // Limite basée sur le pourcentage maximum de la balance
        $maxSizeByBalance = $accountBalance * ($config->maxPositionSize / 100);
        
        // Limite absolue
        $maxSizeAbsolute = $config->maxOrderSize;
        
        // Limite minimale
        $minSize = $config->minOrderSize;

        return max($minSize, min($positionSize, $maxSizeByBalance, $maxSizeAbsolute));
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



