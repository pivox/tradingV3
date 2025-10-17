<?php

declare(strict_types=1);

namespace App\Domain\Leverage\Service;

use App\Domain\Leverage\Dto\LeverageCalculationDto;
use App\Domain\Leverage\Dto\LeverageConfigDto;
use Psr\Log\LoggerInterface;

class LeverageCalculationService
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function calculateLeverage(
        string $symbol,
        float $riskPercent,
        float $stopPercent,
        LeverageConfigDto $config,
        bool $isHighConviction = false,
        bool $isUpstreamStale = false,
        bool $isTieBreakerUsed = false
    ): LeverageCalculationDto {
        $calculationSteps = [];
        
        // Étape 1: Calcul du levier de base (risk / stop)
        $baseLeverage = $this->calculateBaseLeverage($riskPercent, $stopPercent);
        $calculationSteps['base_leverage'] = [
            'formula' => 'risk_percent / stop_percent',
            'calculation' => "{$riskPercent} / {$stopPercent}",
            'result' => $baseLeverage
        ];

        // Étape 2: Application du multiplicateur de confiance
        $confidenceMultiplier = $this->calculateConfidenceMultiplier(
            $config->confidenceMultiplier,
            $isUpstreamStale,
            $isTieBreakerUsed
        );
        $adjustedLeverage = $baseLeverage * $confidenceMultiplier;
        $calculationSteps['confidence_adjustment'] = [
            'multiplier' => $confidenceMultiplier,
            'calculation' => "{$baseLeverage} * {$confidenceMultiplier}",
            'result' => $adjustedLeverage
        ];

        // Étape 3: Application du cap de conviction si applicable
        $convictionCap = $this->calculateConvictionCap($config, $isHighConviction);
        if ($convictionCap > 0) {
            $adjustedLeverage = min($adjustedLeverage, $convictionCap);
            $calculationSteps['conviction_cap'] = [
                'cap' => $convictionCap,
                'calculation' => "min({$adjustedLeverage}, {$convictionCap})",
                'result' => $adjustedLeverage
            ];
        }

        // Étape 4: Application des caps (exchange et symbole)
        $symbolCap = $config->getSymbolCap($symbol);
        $cappedLeverage = min($adjustedLeverage, $config->exchangeCap, $symbolCap);
        $calculationSteps['caps_application'] = [
            'exchange_cap' => $config->exchangeCap,
            'symbol_cap' => $symbolCap,
            'calculation' => "min({$adjustedLeverage}, {$config->exchangeCap}, {$symbolCap})",
            'result' => $cappedLeverage
        ];

        // Étape 5: Application du floor
        $flooredLeverage = max($cappedLeverage, $config->floor);
        $calculationSteps['floor_application'] = [
            'floor' => $config->floor,
            'calculation' => "max({$cappedLeverage}, {$config->floor})",
            'result' => $flooredLeverage
        ];

        // Étape 6: Arrondi final
        $finalLeverage = $this->roundLeverage($flooredLeverage, $config->rounding);
        $calculationSteps['rounding'] = [
            'precision' => $config->rounding->precision,
            'mode' => $config->rounding->mode,
            'calculation' => "round({$flooredLeverage}, {$config->rounding->precision})",
            'result' => $finalLeverage
        ];

        $this->logger->info('[Leverage Calculation] Calculated leverage', [
            'symbol' => $symbol,
            'final_leverage' => $finalLeverage,
            'steps' => $calculationSteps
        ]);

        return new LeverageCalculationDto(
            symbol: $symbol,
            riskPercent: $riskPercent,
            stopPercent: $stopPercent,
            exchangeCap: $config->exchangeCap,
            symbolCap: $symbolCap,
            confidenceMultiplier: $confidenceMultiplier,
            isHighConviction: $isHighConviction,
            isUpstreamStale: $isUpstreamStale,
            isTieBreakerUsed: $isTieBreakerUsed,
            convictionCapPercent: $config->conviction->capPctOfExchange,
            calculatedLeverage: $baseLeverage,
            finalLeverage: $finalLeverage,
            calculationSteps: $calculationSteps
        );
    }

    private function calculateBaseLeverage(float $riskPercent, float $stopPercent): float
    {
        if ($stopPercent <= 0) {
            throw new \InvalidArgumentException('Stop percent must be greater than 0');
        }
        
        return $riskPercent / $stopPercent;
    }

    private function calculateConfidenceMultiplier(
        \App\Domain\Leverage\Dto\ConfidenceMultiplierConfig $config,
        bool $isUpstreamStale,
        bool $isTieBreakerUsed
    ): float {
        if (!$config->enabled) {
            return 1.0;
        }

        if ($isUpstreamStale) {
            return $config->whenUpstreamStale;
        }

        if ($isTieBreakerUsed) {
            return $config->whenTieBreakerUsed;
        }

        return $config->default;
    }

    private function calculateConvictionCap(LeverageConfigDto $config, bool $isHighConviction): float
    {
        if (!$config->conviction->enabled || !$isHighConviction) {
            return 0; // Pas de cap de conviction
        }

        return $config->conviction->capPctOfExchange * $config->exchangeCap;
    }

    private function roundLeverage(float $leverage, \App\Domain\Leverage\Dto\RoundingConfig $rounding): float
    {
        $multiplier = pow(10, $rounding->precision);
        
        switch ($rounding->mode) {
            case 'floor':
                return floor($leverage * $multiplier) / $multiplier;
            case 'ceil':
                return ceil($leverage * $multiplier) / $multiplier;
            case 'round':
            default:
                return round($leverage, $rounding->precision);
        }
    }
}




