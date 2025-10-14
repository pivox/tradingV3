<?php

declare(strict_types=1);

namespace App\Domain\Trade\Service;

use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Dto\OrderPlanDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;
use Brick\Math\BigDecimal;

class OrderPlanner
{
    private const DEFAULT_LEVERAGE = 10;
    private const DEFAULT_RISK_PERCENTAGE = 2.0; // 2% du capital
    private const DEFAULT_RR_RATIO = 2.0; // Risk/Reward ratio

    /**
     * Planifie un ordre basé sur un signal validé
     */
    public function planOrder(SignalDto $signal, IndicatorSnapshotDto $indicators, array $context = []): OrderPlanDto
    {
        $leverage = $this->calculateLeverage($signal, $indicators, $context);
        $stopLoss = $this->calculateStopLoss($signal, $indicators);
        $takeProfit = $this->calculateTakeProfit($signal, $indicators, $stopLoss);
        
        $riskContext = $this->calculateRiskContext($signal, $indicators, $context);
        $executionContext = $this->prepareExecutionContext($signal, $leverage, $context);

        return new OrderPlanDto(
            symbol: $signal->symbol,
            side: $signal->side,
            leverage: $leverage,
            stopLoss: $stopLoss,
            takeProfit: $takeProfit,
            context: array_merge($signal->meta, $context),
            risk: $riskContext,
            execution: $executionContext
        );
    }

    /**
     * Calcule le levier approprié
     */
    private function calculateLeverage(SignalDto $signal, IndicatorSnapshotDto $indicators, array $context): BigDecimal
    {
        $baseLeverage = BigDecimal::of(self::DEFAULT_LEVERAGE);
        
        // Ajuster le levier selon la volatilité (ATR)
        if ($indicators->atr !== null) {
            $volatilityFactor = $this->calculateVolatilityFactor($indicators->atr, $context);
            $baseLeverage = $baseLeverage->multipliedBy($volatilityFactor);
        }
        
        // Ajuster selon le score du signal
        if ($signal->hasScore()) {
            $scoreFactor = BigDecimal::of($signal->score);
            $baseLeverage = $baseLeverage->multipliedBy($scoreFactor);
        }
        
        // Limiter le levier entre 1 et 20
        return $baseLeverage->min(BigDecimal::of(20))->max(BigDecimal::of(1));
    }

    /**
     * Calcule le facteur de volatilité
     */
    private function calculateVolatilityFactor(BigDecimal $atr, array $context): BigDecimal
    {
        $currentPrice = $context['current_price'] ?? '50000'; // Prix par défaut
        $price = BigDecimal::of($currentPrice);
        
        $atrPercentage = $atr->dividedBy($price);
        
        // Si ATR > 3%, réduire le levier
        if ($atrPercentage->isGreaterThan(BigDecimal::of('0.03'))) {
            return BigDecimal::of('0.5');
        }
        
        // Si ATR < 1%, augmenter le levier
        if ($atrPercentage->isLessThan(BigDecimal::of('0.01'))) {
            return BigDecimal::of('1.5');
        }
        
        return BigDecimal::of('1.0');
    }

    /**
     * Calcule le stop loss
     */
    private function calculateStopLoss(SignalDto $signal, IndicatorSnapshotDto $indicators): ?BigDecimal
    {
        if ($indicators->atr === null) {
            return null;
        }
        
        $currentPrice = $signal->meta['current_price'] ?? null;
        if ($currentPrice === null) {
            return null;
        }
        
        $price = BigDecimal::of($currentPrice);
        $atr = $indicators->atr;
        
        if ($signal->isLong()) {
            // Stop loss pour LONG : prix - (2 * ATR)
            return $price->minus($atr->multipliedBy(2));
        } else {
            // Stop loss pour SHORT : prix + (2 * ATR)
            return $price->plus($atr->multipliedBy(2));
        }
    }

    /**
     * Calcule le take profit
     */
    private function calculateTakeProfit(SignalDto $signal, IndicatorSnapshotDto $indicators, ?BigDecimal $stopLoss): ?BigDecimal
    {
        if ($stopLoss === null) {
            return null;
        }
        
        $currentPrice = $signal->meta['current_price'] ?? null;
        if ($currentPrice === null) {
            return null;
        }
        
        $price = BigDecimal::of($currentPrice);
        $risk = $price->minus($stopLoss)->abs();
        $reward = $risk->multipliedBy(self::DEFAULT_RR_RATIO);
        
        if ($signal->isLong()) {
            return $price->plus($reward);
        } else {
            return $price->minus($reward);
        }
    }

    /**
     * Calcule le contexte de risque
     */
    private function calculateRiskContext(SignalDto $signal, IndicatorSnapshotDto $indicators, array $context): array
    {
        $currentPrice = $context['current_price'] ?? '50000';
        $accountBalance = $context['account_balance'] ?? '10000';
        
        $price = BigDecimal::of($currentPrice);
        $balance = BigDecimal::of($accountBalance);
        
        $riskAmount = $balance->multipliedBy(self::DEFAULT_RISK_PERCENTAGE / 100);
        
        return [
            'risk_percentage' => self::DEFAULT_RISK_PERCENTAGE,
            'risk_amount' => $riskAmount->toFixed(2),
            'account_balance' => $balance->toFixed(2),
            'current_price' => $price->toFixed(12),
            'atr' => $indicators->atr?->toFixed(12),
            'rsi' => $indicators->rsi,
            'volatility_level' => $this->getVolatilityLevel($indicators->atr, $price)
        ];
    }

    /**
     * Prépare le contexte d'exécution
     */
    private function prepareExecutionContext(SignalDto $signal, BigDecimal $leverage, array $context): array
    {
        $currentPrice = $context['current_price'] ?? '50000';
        $price = BigDecimal::of($currentPrice);
        
        return [
            'symbol' => $signal->symbol,
            'side' => $signal->side->toOrderSide(),
            'type' => 'market', // Ordre au marché
            'leverage' => $leverage->toFixed(2),
            'open_type' => 'cross', // Mode cross margin
            'size' => $this->calculatePositionSize($signal, $leverage, $context),
            'price' => $price->toFixed(12),
            'timeframe' => $signal->timeframe->value,
            'signal_score' => $signal->score,
            'trigger' => $signal->trigger
        ];
    }

    /**
     * Calcule la taille de position
     */
    private function calculatePositionSize(SignalDto $signal, BigDecimal $leverage, array $context): string
    {
        $accountBalance = $context['account_balance'] ?? '10000';
        $balance = BigDecimal::of($accountBalance);
        $riskAmount = $balance->multipliedBy(self::DEFAULT_RISK_PERCENTAGE / 100);
        
        // Taille = (risque * levier) / prix
        $currentPrice = $context['current_price'] ?? '50000';
        $price = BigDecimal::of($currentPrice);
        
        $positionValue = $riskAmount->multipliedBy($leverage);
        $size = $positionValue->dividedBy($price);
        
        return $size->toFixed(8);
    }

    /**
     * Détermine le niveau de volatilité
     */
    private function getVolatilityLevel(?BigDecimal $atr, BigDecimal $price): string
    {
        if ($atr === null) {
            return 'unknown';
        }
        
        $atrPercentage = $atr->dividedBy($price);
        
        if ($atrPercentage->isLessThan(BigDecimal::of('0.01'))) {
            return 'low';
        } elseif ($atrPercentage->isLessThan(BigDecimal::of('0.03'))) {
            return 'medium';
        } else {
            return 'high';
        }
    }

    /**
     * Valide un plan d'ordre
     */
    public function validateOrderPlan(OrderPlanDto $orderPlan): array
    {
        $errors = [];
        
        if ($orderPlan->leverage->isLessThan(BigDecimal::of(1)) || $orderPlan->leverage->isGreaterThan(BigDecimal::of(20))) {
            $errors[] = 'Leverage must be between 1 and 20';
        }
        
        if ($orderPlan->hasStopLoss() && $orderPlan->hasTakeProfit()) {
            $rrRatio = $orderPlan->getRiskRewardRatio();
            if ($rrRatio !== null && $rrRatio < 1.0) {
                $errors[] = 'Risk/Reward ratio must be at least 1:1';
            }
        }
        
        $positionSize = $orderPlan->getPositionSize();
        if ($positionSize !== null && $positionSize->isLessThanOrEqualTo(BigDecimal::zero())) {
            $errors[] = 'Position size must be positive';
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors
        ];
    }
}




