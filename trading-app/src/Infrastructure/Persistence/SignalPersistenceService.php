<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Common\Dto\SignalDto;
use App\Entity\Signal;
use App\Repository\SignalRepository;
use Psr\Log\LoggerInterface;

/**
 * Service de persistance des signaux de trading
 * 
 * Ce service convertit les SignalDto en entités Signal et les persiste en base de données.
 * Il gère la logique d'upsert pour éviter les doublons et maintient la traçabilité des signaux.
 */
final class SignalPersistenceService
{
    public function __construct(
        private readonly SignalRepository $signalRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Persiste un signal unique
     */
    public function persistSignal(SignalDto $signalDto): void
    {
        try {
            $entity = $this->createSignalEntity($signalDto);
            $this->signalRepository->upsert($entity);
            
            $this->logger->info('Signal persisted', [
                'symbol' => $signalDto->symbol,
                'timeframe' => $signalDto->timeframe->value,
                'side' => $signalDto->side->value,
                'score' => $signalDto->score,
                'kline_time' => $signalDto->klineTime->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to persist signal', [
                'symbol' => $signalDto->symbol,
                'timeframe' => $signalDto->timeframe->value,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Persiste plusieurs signaux en batch
     */
    public function persistSignals(array $signalDtos): void
    {
        if (empty($signalDtos)) {
            return;
        }

        $this->logger->info('Persisting signals batch', [
            'count' => count($signalDtos)
        ]);

        foreach ($signalDtos as $signalDto) {
            if (!$signalDto instanceof SignalDto) {
                $this->logger->warning('Invalid signal DTO in batch', [
                    'type' => gettype($signalDto)
                ]);
                continue;
            }

            $this->persistSignal($signalDto);
        }
    }

    /**
     * Persiste un signal avec validation MTF
     */
    public function persistMtfSignal(
        SignalDto $signalDto, 
        array $mtfContext = [], 
        array $validationResults = []
    ): void {
        // Enrichir les métadonnées avec le contexte MTF
        $enrichedMeta = array_merge($signalDto->meta, [
            'mtf_context' => $mtfContext,
            'validation_results' => $validationResults,
            'persisted_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'mtf_validation' => true
        ]);

        $enrichedSignal = new SignalDto(
            symbol: $signalDto->symbol,
            timeframe: $signalDto->timeframe,
            klineTime: $signalDto->klineTime,
            side: $signalDto->side,
            score: $signalDto->score,
            trigger: $signalDto->trigger,
            meta: $enrichedMeta
        );

        $this->persistSignal($enrichedSignal);
    }

    /**
     * Crée une entité Signal à partir d'un SignalDto
     */
    private function createSignalEntity(SignalDto $signalDto): Signal
    {
        $entity = new Signal();
        $entity
            ->setSymbol($signalDto->symbol)
            ->setTimeframe($signalDto->timeframe)
            ->setKlineTime($signalDto->klineTime)
            ->setSide($signalDto->side)
            ->setScore($signalDto->score)
            ->setMeta(array_merge($signalDto->meta, [
                'trigger' => $signalDto->trigger,
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'signal_hash' => md5(serialize($signalDto->toArray()))
            ]));

        return $entity;
    }

    /**
     * Récupère les signaux récents pour un symbole et timeframe
     */
    public function getRecentSignals(string $symbol, \App\Domain\Common\Enum\Timeframe $timeframe, int $limit = 100): array
    {
        return $this->signalRepository->findRecentSignals($symbol, $timeframe, $limit);
    }

    /**
     * Récupère les signaux forts (score élevé)
     */
    public function getStrongSignals(
        string $symbol, 
        \App\Domain\Common\Enum\Timeframe $timeframe, 
        float $minScore = 0.7
    ): array {
        return $this->signalRepository->findStrongSignals($symbol, $timeframe, $minScore);
    }

    /**
     * Récupère les signaux par côté (LONG/SHORT)
     */
    public function getSignalsBySide(
        string $symbol, 
        \App\Domain\Common\Enum\Timeframe $timeframe, 
        string $side
    ): array {
        return $this->signalRepository->findBySide($symbol, $timeframe, $side);
    }

    /**
     * Nettoie les anciens signaux (maintenance)
     */
    public function cleanupOldSignals(int $daysToKeep = 30): int
    {
        $cutoffDate = new \DateTimeImmutable("-$daysToKeep days", new \DateTimeZone('UTC'));
        
        // Cette méthode devrait être implémentée dans le repository
        // Pour l'instant, on retourne 0
        $this->logger->info('Signal cleanup requested', [
            'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
            'days_to_keep' => $daysToKeep
        ]);
        
        return 0;
    }
}
