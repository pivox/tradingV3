<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Sizing;

use App\Service\Trading\Opening\Config\TradingConfig;
use App\Service\Trading\Opening\Leverage\LeveragePlan;
use App\Service\Trading\Opening\Market\MarketSnapshot;
use RuntimeException;

final class PositionSizer
{
    public function decide(
        string $side,
        TradingConfig $config,
        MarketSnapshot $snapshot,
        LeveragePlan $plan
    ): SizingDecision {
        $sideLower = strtolower($side);

        $notionalBudget = $config->budgetCapUsdt * $plan->target;
        $contractsBudget = $this->quantizeDown(
            ($notionalBudget / max(1e-9, $snapshot->markPrice * $snapshot->contractSize)),
            $snapshot->qtyStep
        );

        $contractsRisk = $this->quantizeDown(
            ($config->riskAbsUsdt / max(1e-9, $snapshot->stopDistance * $snapshot->contractSize)),
            $snapshot->qtyStep
        );

        $upperBound = $snapshot->marketMaxVolume !== null
            ? min($snapshot->maxVolume, $snapshot->marketMaxVolume)
            : $snapshot->maxVolume;

        $candidate = max(
            $snapshot->minVolume,
            min($contractsBudget, $contractsRisk, $upperBound)
        );

        $contracts = (int) $candidate;
        if ($contracts <= 0) {
            throw new RuntimeException('Impossible de dimensionner un ordre >= 1 contrat');
        }

        $qtyNotional = max(1e-9, $contracts * $snapshot->contractSize);

        if ($sideLower === 'long') {
            $slRaw = $snapshot->markPrice - ($config->riskAbsUsdt / $qtyNotional);
            $tpRaw = $snapshot->markPrice + ($config->tpAbsUsdt / $qtyNotional);
        } else {
            $slRaw = $snapshot->markPrice + ($config->riskAbsUsdt / $qtyNotional);
            $tpRaw = $snapshot->markPrice - ($config->tpAbsUsdt / $qtyNotional);
        }

        $stopLoss = $this->quantizeToStep($slRaw, $snapshot->tickSize);
        $takeProfit = $this->quantizeToStep($tpRaw, $snapshot->tickSize);

        return new SizingDecision(
            contracts: $contracts,
            stopLoss: $stopLoss,
            takeProfit: $takeProfit,
            qtyNotional: $qtyNotional,
            leverage: $plan->target
        );
    }

    private function quantizeDown(float $value, float $step): float
    {
        if ($step <= 0.0) {
            return $value;
        }

        return floor($value / $step) * $step;
    }

    private function quantizeToStep(float $value, float $step): float
    {
        if ($step <= 0.0) {
            return $value;
        }

        return round($value / $step) * $step;
    }
}
