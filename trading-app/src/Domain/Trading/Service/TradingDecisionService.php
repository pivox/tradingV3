<?php

declare(strict_types=1);

namespace App\Domain\Trading\Service;

use App\Config\TradingParameters;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Leverage\Dto\LeverageCalculationDto;
use App\Domain\Leverage\Service\LeverageCalculationService;
use App\Domain\Leverage\Service\LeverageConfigService;
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
            'atr_input' => $atr,
            'account_balance' => $accountBalance,
            'risk_percentage' => $riskPercentage,
            'is_high_conviction' => $isHighConviction
        ]);
        $this->logger->info('[Trading Decision] Making trading decision', [
            'symbol' => $symbol,
            'side' => $side->value,
            'current_price' => $currentPrice,
            'atr_input' => $atr,
            'account_balance' => $accountBalance,
            'risk_percentage' => $riskPercentage,
            'is_high_conviction' => $isHighConviction
        ]);

        try {
            $atrPeriod = max(2, $this->tradingParameters->atrPeriod());
            $atrOneMinute = $this->computeAtrOneMinute($symbol, $atrPeriod);

            // 1. Calculer le leverage
            $leverageCalculation = $this->calculateLeverage(
                $symbol,
                $riskPercentage,
                $currentPrice,
                $atrOneMinute,
                $isHighConviction,
                $timeframeMultiplier
            );

            // 2. Calculer l'ouverture de position
            $positionOpening = $this->calculatePositionOpening(
                $symbol,
                $side,
                $currentPrice,
                $atrOneMinute,
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
