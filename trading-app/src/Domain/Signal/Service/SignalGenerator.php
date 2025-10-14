<?php

declare(strict_types=1);

namespace App\Domain\Signal\Service;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Signal\Strategy\StrategyInterface;
use App\Infrastructure\Persistence\SignalPersistenceService;
use Psr\Log\LoggerInterface;

class SignalGenerator
{
    /**
     * @param StrategyInterface[] $strategies
     */
    public function __construct(
        private array $strategies = [],
        private readonly ?SignalPersistenceService $signalPersistenceService = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Génère des signaux basés sur les stratégies configurées
     */
    public function generateSignals(
        string $symbol,
        Timeframe $timeframe,
        KlineDto $kline,
        IndicatorSnapshotDto $indicators
    ): array {
        $signals = [];

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($timeframe)) {
                $signal = $strategy->generateSignal($symbol, $timeframe, $kline, $indicators);
                if ($signal !== null) {
                    $signals[] = $signal;
                }
            }
        }

        // Persister les signaux si le service est disponible
        if (!empty($signals) && $this->signalPersistenceService !== null) {
            try {
                $this->signalPersistenceService->persistSignals($signals);
                $this->logger?->info('Signals persisted', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'count' => count($signals)
                ]);
            } catch (\Exception $e) {
                $this->logger?->error('Failed to persist signals', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $signals;
    }

    /**
     * Ajoute une stratégie au générateur
     */
    public function addStrategy(StrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }

    /**
     * Retire une stratégie du générateur
     */
    public function removeStrategy(string $strategyName): void
    {
        $this->strategies = array_filter(
            $this->strategies,
            fn(StrategyInterface $strategy) => $strategy->getName() !== $strategyName
        );
    }

    /**
     * Retourne les stratégies actives
     */
    public function getActiveStrategies(): array
    {
        return $this->strategies;
    }
}


