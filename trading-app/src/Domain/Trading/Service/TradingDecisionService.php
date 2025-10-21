<?php

declare(strict_types=1);

namespace App\Domain\Trading\Service;

use App\Config\TradingParameters;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Leverage\Dto\LeverageCalculationDto;
use App\Domain\Leverage\Service\LeverageCalculationService;
use App\Domain\Leverage\Service\LeverageConfigService;
use App\Domain\Leverage\Service\SymbolLeverageRegistry;
use App\Domain\Position\Dto\PositionOpeningDto;
use App\Domain\Position\Service\PositionConfigService;
use App\Domain\Position\Service\PositionExecutionService;
use App\Domain\Position\Service\PositionOpeningService;
use App\Indicator\AtrCalculator;
use App\Repository\KlineRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TradingDecisionService
{
    public function __construct(
        private readonly LeverageConfigService $leverageConfigService,
        private readonly LeverageCalculationService $leverageCalculationService,
        private readonly PositionConfigService $positionConfigService,
        private readonly PositionOpeningService $positionOpeningService,
        private readonly PositionExecutionService $positionExecutionService,
        private readonly KlineRepository $klineRepository,
        private readonly AtrCalculator $atrCalculator,
        private readonly TradingParameters $tradingParameters,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly SymbolLeverageRegistry $symbolLeverageRegistry
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
        $this->logger->info('[Trading Decision] Making trading decision', [
            'symbol' => $symbol,
            'side' => $side->value,
            'current_price' => $currentPrice,
            'atr_input' => $atr,
            'account_balance' => $accountBalance,
            'risk_percentage' => $riskPercentage,
            'is_high_conviction' => $isHighConviction,
            'timeframe_multiplier' => $timeframeMultiplier,
        ]);

        try {
            $atrPeriod = max(2, $this->tradingParameters->atrPeriod());
            $this->logger->info('[Trading Decision] Computing ATR(1m)', [
                'symbol' => $symbol,
                'period' => $atrPeriod,
            ]);
            $atrOneMinute = $this->computeAtrOneMinute($symbol, $atrPeriod);
            $this->logger->info('[Trading Decision] ATR(1m) computed', [
                'symbol' => $symbol,
                'atr_1m' => $atrOneMinute,
                'period' => $atrPeriod,
            ]);

            // 1. Calculer le leverage
            $this->logger->info('[Trading Decision] Calculating leverage', [
                'symbol' => $symbol,
                'risk_percentage' => $riskPercentage,
                'current_price' => $currentPrice,
                'atr_1m' => $atrOneMinute,
                'timeframe_multiplier' => $timeframeMultiplier,
                'is_high_conviction' => $isHighConviction,
            ]);
            $leverageCalculation = $this->calculateLeverage(
                $symbol,
                $riskPercentage,
                $currentPrice,
                $atrOneMinute,
                $isHighConviction,
                $timeframeMultiplier
            );
            $this->logger->info('[Trading Decision] Leverage calculated', [
                'symbol' => $symbol,
                'final_leverage' => $leverageCalculation->finalLeverage,
                'calculated_leverage' => $leverageCalculation->calculatedLeverage,
                'exchange_cap' => $leverageCalculation->exchangeCap,
                'symbol_cap' => $leverageCalculation->symbolCap,
                'resolved_symbol_cap' => $this->symbolLeverageRegistry->resolve($symbol),
                'confidence_multiplier' => $leverageCalculation->confidenceMultiplier,
                'is_high_conviction' => $leverageCalculation->isHighConviction,
            ]);

            // 2. Calculer l'ouverture de position
            $this->logger->info('[Trading Decision] Calculating position opening', [
                'symbol' => $symbol,
                'side' => $side->value,
                'current_price' => $currentPrice,
                'atr_1m' => $atrOneMinute,
                'account_balance' => $accountBalance,
            ]);
            $positionOpening = $this->calculatePositionOpening(
                $symbol,
                $side,
                $currentPrice,
                $atrOneMinute,
                $accountBalance,
                $leverageCalculation
            );
            $this->logger->info('[Trading Decision] Position opening calculated', [
                'symbol' => $symbol,
                'position' => $positionOpening->toArray(),
            ]);

            // 3. Exécuter la position (ou simuler en dry run)
            $this->logger->info('[Trading Decision] Executing position', [
                'symbol' => $symbol,
            ]);
            $executionResult = $this->executePosition($positionOpening);
            if (($executionResult['status'] ?? null) !== 'success') {
                $this->logger->error('[Trading Decision] Execution failed', [
                    'symbol' => $symbol,
                    'execution_result' => $executionResult,
                ]);
                return [
                    'status' => 'error',
                    'symbol' => $symbol,
                    'error' => 'Execution failed',
                    'execution_result' => $executionResult,
                    'timestamp' => $this->clock->now()->format('Y-m-d H:i:s')
                ];
            }
            $this->logger->info('[Trading Decision] Execution completed', [
                'symbol' => $symbol,
                'status' => $executionResult['status'] ?? null,
                'main_order_id' => $executionResult['main_order']['order_id'] ?? null,
                'tp_order_id' => $executionResult['take_profit_order']['order_id'] ?? null,
                'sl_order_id' => $executionResult['stop_loss_order']['order_id'] ?? null,
            ]);

            $this->logger->info('[Trading Decision] Trading decision completed successfully', [
                'symbol' => $symbol,
                'leverage' => $leverageCalculation->finalLeverage,
                'position_size' => $positionOpening->positionSize,
                'risk_amount' => $positionOpening->riskAmount,
                'atr_1m' => $atrOneMinute,
                'atr_period' => $atrPeriod
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

    private function computeAtrOneMinute(string $symbol, int $period): float
    {
        $symbolUpper = strtoupper($symbol);
        $klines = $this->klineRepository->findBy(
            ['symbol' => $symbolUpper, 'timeframe' => Timeframe::TF_1M],
            ['openTime' => 'DESC'],
            $period + 1
        );

        if (count($klines) <= $period) {
            $this->logger->warning('[Trading Decision] Insufficient 1m klines for ATR', [
                'symbol' => $symbolUpper,
                'period' => $period,
                'available' => count($klines),
            ]);
            throw new RuntimeException(sprintf('Insufficient 1m klines to compute ATR (need %d, got %d) for %s', $period + 1, count($klines), $symbolUpper));
        }

        $candles = array_reverse($klines);
        $ohlc = [];
        foreach ($candles as $kline) {
            $ohlc[] = [
                'high' => $kline->getHighPrice()->toFloat(),
                'low' => $kline->getLowPrice()->toFloat(),
                'close' => $kline->getClosePrice()->toFloat(),
            ];
        }

        return $this->atrCalculator->compute($ohlc, $period, 'wilder');
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
        $symbolHardCap = $this->symbolLeverageRegistry->resolve($symbol);
        $finalLeverageDto = $this->leverageCalculationService->calculateLeverage(
            $symbol,
            $riskPercentage,
            $stopLossPercentage,
            $leverageConfig,
            $isHighConviction,
            false,
            false,
            $timeframeMultiplier,
            $symbolHardCap
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
