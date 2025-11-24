<?php

declare(strict_types=1);

namespace App\Signal;

use App\Common\Dto\SignalDto;
use App\Entity\Signal;
use App\Repository\SignalRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        #[Autowire(service: 'monolog.logger.signals')] private readonly LoggerInterface $signalLogger
    ) {
    }

    /**
     * Persiste un signal unique
     * 
     * Best-effort: si l'EntityManager est fermé ou une erreur survient,
     * on log l'erreur mais on ne remonte pas l'exception pour ne pas
     * interrompre le traitement MTF.
     */
    public function persistSignal(SignalDto $signalDto): void
    {
        try {
            $entity = $this->createSignalEntity($signalDto);
            $this->signalRepository->upsert($entity);

            $this->signalLogger->info('Signal persisted', [
                'symbol' => $signalDto->symbol,
                'timeframe' => $signalDto->timeframe->value,
                'side' => $signalDto->side->value,
                'score' => $signalDto->score,
                'kline_time' => $signalDto->klineTime->format('Y-m-d H:i:s')
            ]);
        } catch (\Throwable $e) {
            // Best-effort: logger l'erreur mais ne pas interrompre le traitement
            $isEntityManagerClosed = stripos($e->getMessage(), 'entitymanager is closed') !== false
                || stripos($e->getMessage(), 'entitymanagerclosed') !== false;
            
            $this->signalLogger->error('Failed to persist signal', [
                'symbol' => $signalDto->symbol,
                'timeframe' => $signalDto->timeframe->value,
                'error' => $e->getMessage(),
                'entity_manager_closed' => $isEntityManagerClosed,
            ]);
            
            // Ne pas relancer l'exception pour ne pas interrompre le traitement MTF
            // Les signaux sont importants mais pas critiques pour la continuité
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

        $this->signalLogger->info('Persisting signals batch', [
            'count' => count($signalDtos)
        ]);

        foreach ($signalDtos as $signalDto) {
            if (!$signalDto instanceof SignalDto) {
                $this->signalLogger->warning('Invalid signal DTO in batch', [
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
            ->setRunId($signalDto->meta['run_id'] ?? null)
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
    public function getRecentSignals(string $symbol, \App\Common\Enum\Timeframe $timeframe, int $limit = 100): array
    {
        return $this->signalRepository->findRecentSignals($symbol, $timeframe, $limit);
    }

    /**
     * Récupère les signaux forts (score élevé)
     */
    public function getStrongSignals(
        string                     $symbol,
        \App\Common\Enum\Timeframe $timeframe,
        float                      $minScore = 0.7
    ): array {
        return $this->signalRepository->findStrongSignals($symbol, $timeframe, $minScore);
    }

    /**
     * Récupère les signaux par côté (LONG/SHORT)
     */
    public function getSignalsBySide(
        string                     $symbol,
        \App\Common\Enum\Timeframe $timeframe,
        string                     $side
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
        $this->signalLogger->info('Signal cleanup requested', [
            'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
            'days_to_keep' => $daysToKeep
        ]);

        return 0;
    }
}
