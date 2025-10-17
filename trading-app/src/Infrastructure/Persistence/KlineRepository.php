<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Enum\Timeframe;
use App\Entity\Kline;
use App\Repository\KlineRepository as BaseKlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class KlineRepository
{
    public function __construct(
        private readonly BaseKlineRepository $baseRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * UPSERT des klines - évite les doublons
     *
     * @param KlineDto[] $klines
     */
    public function upsertKlines(array $klines): int
    {
        if (empty($klines)) {
            return 0;
        }

        $this->logger->info('Starting klines upsert', [
            'count' => count($klines),
            'symbol' => $klines[0]->symbol ?? 'unknown',
            'timeframe' => $klines[0]->timeframe->value ?? 'unknown'
        ]);

        $upsertedCount = 0;
        $batchSize = 50; // Traiter par lots de 50

        foreach (array_chunk($klines, $batchSize) as $batch) {
            $upsertedCount += $this->upsertBatch($batch);
        }

        $this->logger->info('Klines upsert completed', [
            'total_upserted' => $upsertedCount,
            'total_input' => count($klines)
        ]);

        return $upsertedCount;
    }

    /**
     * UPSERT d'un lot de klines
     *
     * @param KlineDto[] $batch
     */
    private function upsertBatch(array $batch): int
    {
        $upsertedCount = 0;

        foreach ($batch as $klineDto) {
            try {
                // Chercher si la kline existe déjà
                $existingKline = $this->baseRepository->findOneBy([
                    'symbol' => $klineDto->symbol,
                    'timeframe' => $klineDto->timeframe,
                    'openTime' => $klineDto->openTime
                ]);

                if ($existingKline) {
                    // Mettre à jour la kline existante
                    $existingKline->setOpenPrice($klineDto->open->toScale(12));
                    $existingKline->setHighPrice($klineDto->high->toScale(12));
                    $existingKline->setLowPrice($klineDto->low->toScale(12));
                    $existingKline->setClosePrice($klineDto->close->toScale(12));
                    $existingKline->setVolume($klineDto->volume->toScale(12));
                    $existingKline->setSource($klineDto->source);
                    $existingKline->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                    
                    $this->entityManager->persist($existingKline);
                } else {
                    // Créer une nouvelle kline
                    $kline = new Kline();
                    $kline->setSymbol($klineDto->symbol);
                    $kline->setTimeframe($klineDto->timeframe);
                    $kline->setOpenTime($klineDto->openTime);
                    $kline->setOpenPrice($klineDto->open->toScale(12));
                    $kline->setHighPrice($klineDto->high->toScale(12));
                    $kline->setLowPrice($klineDto->low->toScale(12));
                    $kline->setClosePrice($klineDto->close->toScale(12));
                    $kline->setVolume($klineDto->volume->toScale(12));
                    $kline->setSource($klineDto->source);
                    $kline->setInsertedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                    $kline->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                    
                    $this->entityManager->persist($kline);
                }

                $upsertedCount++;

            } catch (\Exception $e) {
                $this->logger->error('Error upserting kline', [
                    'symbol' => $klineDto->symbol,
                    'timeframe' => $klineDto->timeframe->value,
                    'open_time' => $klineDto->openTime->format('Y-m-d H:i:s'),
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Flush le batch
        try {
            $this->entityManager->flush();
            $this->entityManager->clear(); // Libérer la mémoire
        } catch (\Exception $e) {
            $this->logger->error('Error flushing klines batch', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        return $upsertedCount;
    }

    /**
     * Récupère les klines pour un symbole et timeframe
     */
    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        return $this->baseRepository->findBy(
            ['symbol' => $symbol, 'timeframe' => $timeframe],
            ['openTime' => 'DESC'],
            $limit
        );
    }

    /**
     * Récupère la dernière kline pour un symbole et timeframe
     */
    public function getLastKline(string $symbol, Timeframe $timeframe): ?Kline
    {
        return $this->baseRepository->findOneBy(
            ['symbol' => $symbol, 'timeframe' => $timeframe],
            ['openTime' => 'DESC']
        );
    }

    /**
     * Vérifie s'il y a des gaps dans les klines
     */
    public function hasGaps(string $symbol, Timeframe $timeframe): bool
    {
        // Logique simplifiée - on peut l'améliorer plus tard
        $klines = $this->getKlines($symbol, $timeframe, 100);
        
        if (count($klines) < 2) {
            return false;
        }

        $expectedInterval = $timeframe->getStepInSeconds();
        
        for ($i = 0; $i < count($klines) - 1; $i++) {
            $current = $klines[$i]->getOpenTime();
            $next = $klines[$i + 1]->getOpenTime();
            
            $actualInterval = $current->getTimestamp() - $next->getTimestamp();
            
            if ($actualInterval > $expectedInterval) {
                return true;
            }
        }

        return false;
    }

    /**
     * Récupère les gaps dans les klines
     */
    public function getGaps(string $symbol, Timeframe $timeframe): array
    {
        $gaps = [];
        $klines = $this->getKlines($symbol, $timeframe, 100);
        
        if (count($klines) < 2) {
            return $gaps;
        }

        $expectedInterval = $timeframe->getStepInSeconds();
        
        for ($i = 0; $i < count($klines) - 1; $i++) {
            $current = $klines[$i]->getOpenTime();
            $next = $klines[$i + 1]->getOpenTime();
            
            $actualInterval = $current->getTimestamp() - $next->getTimestamp();
            
            if ($actualInterval > $expectedInterval) {
                $gaps[] = [
                    'start' => $next,
                    'end' => $current,
                    'missing_periods' => intval($actualInterval / $expectedInterval) - 1
                ];
            }
        }

        return $gaps;
    }

    /**
     * Compte le nombre de klines pour un symbole et timeframe
     */
    public function countKlines(string $symbol, Timeframe $timeframe): int
    {
        return $this->baseRepository->count([
            'symbol' => $symbol,
            'timeframe' => $timeframe
        ]);
    }

    /**
     * Récupère les statistiques des klines
     */
    public function getKlinesStats(): array
    {
        $qb = $this->baseRepository->createQueryBuilder('k');
        
        $stats = $qb
            ->select([
                'k.symbol',
                'k.timeframe',
                'COUNT(k.id) as count',
                'MIN(k.openTime) as earliest',
                'MAX(k.openTime) as latest'
            ])
            ->groupBy('k.symbol', 'k.timeframe')
            ->orderBy('k.symbol', 'ASC')
            ->addOrderBy('k.timeframe', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $stats;
    }
}




