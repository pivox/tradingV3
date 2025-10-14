<?php

declare(strict_types=1);

namespace App\Domain\Trading\Service;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Leverage\Dto\LeverageCalculationDto;
use App\Domain\Leverage\Service\LeverageCalculationService;
use App\Domain\Leverage\Service\LeverageConfigService;
use App\Domain\Position\Dto\PositionOpeningDto;
use App\Domain\Position\Service\PositionConfigService;
use App\Domain\Position\Service\PositionExecutionService;
use App\Domain\Position\Service\PositionOpeningService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

class TradingDecisionService
{
    public function __construct(
        private readonly LeverageConfigService $leverageConfigService,
        private readonly LeverageCalculationService $leverageCalculationService,
        private readonly PositionConfigService $positionConfigService,
        private readonly PositionOpeningService $positionOpeningService,
        private readonly PositionExecutionService $positionExecutionService,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock
    ) {
    }

    public function makeTradingDecision(
        string $symbol,
        SignalSide $side,
        float $currentPrice,
        float $atr,
        float $accountBalance,
        float $riskPercentage,
        bool $isHighConviction = false,
        float $timeframeMultiplier = 1.0
    ): array {
        dump('[Trading Decision] Making trading decision', [
            'symbol' => $symbol,
            'side' => $side->value,
            'current_price' => $currentPrice,
            'atr' => $atr,
            'account_balance' => $accountBalance,
            'risk_percentage' => $riskPercentage,
            'is_high_conviction' => $isHighConviction
        ]);
        $this->logger->info('[Trading Decision] Making trading decision', [
            'symbol' => $symbol,
            'side' => $side->value,
            'current_price' => $currentPrice,
            'atr' => $atr,
            'account_balance' => $accountBalance,
            'risk_percentage' => $riskPercentage,
            'is_high_conviction' => $isHighConviction
        ]);

        try {
            // 1. Calculer le leverage
            $leverageCalculation = $this->calculateLeverage(
                $symbol,
                $riskPercentage,
                $currentPrice,
                $atr,
                $isHighConviction,
                $timeframeMultiplier
            );

            // 2. Calculer l'ouverture de position
            $positionOpening = $this->calculatePositionOpening(
                $symbol,
                $side,
                $currentPrice,
                $atr,
                $accountBalance,
                $leverageCalculation
            );
            dump($positionOpening);

            // 3. Exécuter la position (ou simuler en dry run)
            $executionResult = $this->executePosition($positionOpening);

            $this->logger->info('[Trading Decision] Trading decision completed successfully', [
                'symbol' => $symbol,
                'leverage' => $leverageCalculation->finalLeverage,
                'position_size' => $positionOpening->positionSize,
                'risk_amount' => $positionOpening->riskAmount
            ]);

            return [
                'status' => 'success',
                'symbol' => $symbol,
                'leverage_calculation' => $leverageCalculation,
                'position_opening' => $positionOpening,
                'execution_result' => $executionResult,
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
            ];

        } catch (\Exception $e) {
            $this->logger->error('[Trading Decision] Failed to make trading decision', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'error',
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
            ];
        }
    }

    private function calculateLeverage(
        string $symbol,
        float $riskPercentage,
        float $currentPrice,
        float $atr,
        bool $isHighConviction,
        float $timeframeMultiplier
    ): LeverageCalculationDto {
        $leverageConfig = $this->leverageConfigService->getConfig();
        
        // Calculer la distance de stop basée sur l'ATR
        $stopLossPercentage = ($atr * 2.0) / $currentPrice * 100; // 2x ATR comme stop

        // Appel direct au service avec les bons arguments
        $finalLeverageDto = $this->leverageCalculationService->calculateLeverage(
            $symbol,
            $riskPercentage,
            $stopLossPercentage,
            $leverageConfig,
            $isHighConviction
        );

        return $finalLeverageDto;
    }

    private function calculatePositionOpening(
        string $symbol,
        SignalSide $side,
        float $currentPrice,
        float $atr,
        float $accountBalance,
        LeverageCalculationDto $leverageCalculation
    ): PositionOpeningDto {
        $positionConfig = $this->positionConfigService->getConfig();

        return $this->positionOpeningService->calculatePositionOpening(
            $symbol,
            $side,
            $currentPrice,
            $atr,
            $accountBalance,
            $leverageCalculation,
            $positionConfig
        );
    }

    private function executePosition(PositionOpeningDto $positionOpening): array
    {
        $positionConfig = $this->positionConfigService->getConfig();

        return $this->positionExecutionService->executePosition($positionOpening, $positionConfig);
    }
}

