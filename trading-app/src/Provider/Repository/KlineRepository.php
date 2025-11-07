<?php

declare(strict_types=1);

namespace App\Provider\Repository;

use App\Common\Enum\Timeframe;
use App\Provider\Entity\Kline;
use App\Provider\Bitmart\Dto\KlineDto;
use App\Provider\Bitmart\Dto\ListKlinesDto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * @extends ServiceEntityRepository<Kline>
 */
class KlineRepository extends ServiceEntityRepository
{
    private LoggerInterface $logger;

    public function __construct(ManagerRegistry $registry, LoggerInterface $logger)
    {
        parent::__construct($registry, Kline::class);
        $this->logger = $logger;
    }

    private function getConnection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    /**
     * Retourne les plages manquantes (chunks) pour BitMart calculées via la fonction PostgreSQL
     * `get_missing_kline_chunks`.
     *
     * Chaque ligne = un "chunk" (symbol, step en minutes, from/to en secondes epoch)
     *
     * @param string $symbol        ex: 'BTCUSDT'
     * @param string $timeframe     ex: '1m','5m','15m','1h','4h','1d'
     * @param \DateTimeImmutable $startUtc
     * @param \DateTimeImmutable $endUtc
     * @param int $maxPerReq        (par défaut 500)
     * @return array<int, array{symbol:string, step:int, from:int, to:int}>
     */
    public function getMissingKlineChunks(
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        int $maxPerReq = 500
    ): array {
        $conn = $this->getConnection();

        try {
            // Appel direct de la fonction PostgreSQL avec les paramètres
            $rows = $conn->fetchAllAssociative(
                'SELECT symbol, step, "from", "to"
                 FROM get_missing_kline_chunks(?, ?, ?, ?, ?)
                 ORDER BY "from", "to"',
                [
                    $symbol,
                    $timeframe,
                    $startUtc->format('Y-m-d H:i:sP'),
                    $endUtc->format('Y-m-d H:i:sP'),
                    $maxPerReq
                ]
            );

            // Normalisation typée
            return array_map(static fn(array $row): array => [
                'symbol' => (string)$row['symbol'],
                'step'   => (int)$row['step'], // minutes
                'from'   => (int)$row['from'], // epoch seconds
                'to'     => (int)$row['to'],   // epoch seconds
            ], $rows);

        } catch (\Throwable $e) {
            $this->logger->error('Erreur getMissingKlineChunks()', [
                'symbol' => $symbol,
                'tf'     => $timeframe,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }


    /**
     * Retourne les dernières klines pour un symbole/timeframe (ordre décroissant).
     *
     * @return Kline[]
     */
    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 1000): array
    {
        return $this->createQueryBuilder('k')
            ->where('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->orderBy('k.openTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les klines pour un symbole et timeframe
     */
    public function findBySymbolAndTimeframe(string $symbol, Timeframe $timeframe, int $limit = 1000): array
    {
        return $this->getKlines($symbol, $timeframe, $limit);
    }

    /**
     * Récupère la dernière kline pour un symbole et timeframe
     */
    public function findLastBySymbolAndTimeframe(string $symbol, Timeframe $timeframe): ?Kline
    {
        return $this->createQueryBuilder('k')
            ->where('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->orderBy('k.openTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère les klines dans une plage de temps
     */
    public function findBySymbolTimeframeAndDateRange(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate
    ): array {
        return $this->createQueryBuilder('k')
            ->where('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->andWhere('k.openTime >= :startDate')
            ->andWhere('k.openTime <= :endDate')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('k.openTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie s'il y a des gaps dans les données
     */
    public function hasGaps(string $symbol, Timeframe $timeframe): bool
    {
        $qb = $this->createQueryBuilder('k');

        $result = $qb->select('COUNT(k.id) as count')
            ->where('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    /**
     * Récupère les gaps dans les données
     */
    public function findGaps(string $symbol, Timeframe $timeframe): array
    {
        // Cette méthode nécessiterait une requête SQL plus complexe
        // Pour l'instant, on retourne un tableau vide
        // TODO: Implémenter la logique de détection des gaps
        return [];
    }

    /**
     * Sauvegarde ou met à jour une kline
     */
    public function upsert(Kline $kline): void
    {
        $existing = $this->findOneBy([
            'symbol' => $kline->getSymbol(),
            'timeframe' => $kline->getTimeframe(),
            'openTime' => $kline->getOpenTime()
        ]);

        if ($existing) {
            $existing->setClosePrice($kline->getClosePrice());
            $existing->setVolume($kline->getVolume());
            $existing->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->getEntityManager()->flush();
        } else {
            $this->getEntityManager()->persist($kline);
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Sauvegarde plusieurs klines
     */
    public function upsertMultiple(array $klines): void
    {
        foreach ($klines as $kline) {
            $this->upsert($kline);
        }
    }

    /**
     * Sauvegarde plusieurs klines (DTOs) en lot.
     * @param KlineDto[] $klineDtos
     */
    public function saveKlines(ListKlinesDto $listKlinesDto, string $symbol, Timeframe $timeframe): void
    {
        $em = $this->getEntityManager();
        $batchSize = 100;
        $i = 0;

        foreach ($listKlinesDto as $klineDto) {
            $kline = new \App\Provider\Entity\Kline();
            $kline->setSymbol($symbol);
            $kline->setTimeframe($timeframe);
            $kline->setOpenTime($klineDto->openTime);
            $kline->setOpenPrice($klineDto->open);
            $kline->setHighPrice($klineDto->high);
            $kline->setLowPrice($klineDto->low);
            $kline->setClosePrice($klineDto->close);
            $kline->setVolume(\Brick\Math\BigDecimal::of($klineDto->volume ?? '0'));
            $kline->setSource('REST_BACKFILL');
            $kline->setInsertedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $kline->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            $em->persist($kline);

            if (($i % $batchSize) === 0) {
                $em->flush();
                $em->clear();
            }
            $i++;
        }

        $em->flush();
        $em->clear();
    }

    /**
     * Récupère les klines les plus récentes pour le calcul des indicateurs
     */
    public function findRecentForIndicators(string $symbol, Timeframe $timeframe, int $requiredPeriods = 200): array
    {
        return $this->createQueryBuilder('k')
            ->where('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->orderBy('k.openTime', 'DESC')
            ->setMaxResults($requiredPeriods)
            ->getQuery()
            ->getResult();
    }

    public function findWithFilters(?string $symbol = null, ?string $timeframe = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $qb = $this->createQueryBuilder('k')
            ->orderBy('k.openTime', 'DESC')
            ->setMaxResults(1000); // Limiter pour les performances

        if ($symbol) {
            $qb->andWhere('k.symbol LIKE :symbol')
                ->setParameter('symbol', '%' . $symbol . '%');
        }

        if ($timeframe) {
            $qb->andWhere('k.timeframe = :timeframe')
                ->setParameter('timeframe', $timeframe);
        }

        if ($dateFrom) {
            $date = new \DateTimeImmutable($dateFrom);
            $qb->andWhere('k.openTime >= :dateFrom')
                ->setParameter('dateFrom', $date);
        }

        if ($dateTo) {
            $date = new \DateTimeImmutable($dateTo);
            $qb->andWhere('k.openTime <= :dateTo')
                ->setParameter('dateTo', $date);
        }

        return $qb->getQuery()->getResult();
    }

    public function countKlines(string $symbol, Timeframe $timeframe)
    {
        return $this->createQueryBuilder('k')
            ->select('COUNT(k.id)')
            ->where('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getKlinesStats()
    {
        return $this->createQueryBuilder('k')
            ->select('k.symbol, k.timeframe, COUNT(k.id) as count, MAX(k.openTime) as earliest, MIN(k.openTime) as latest')
            ->groupBy('k.symbol, k.timeframe')
            ->orderBy('k.symbol, k.timeframe')
            ->getQuery()
            ->getResult();
    }

    /**
     * UPSERT des klines - évite les doublons
     *
     * @param \App\Contract\Provider\Dto\KlineDto[] $klines
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
                $existingKline = $this->findOneBy([
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

                    $this->getEntityManager()->persist($existingKline);
                } else {
                    // Créer une nouvelle kline
                    $kline = new \App\Provider\Entity\Kline();
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

                    $this->getEntityManager()->persist($kline);
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
            $this->getEntityManager()->flush();
            $this->getEntityManager()->clear(); // Libérer la mémoire
        } catch (\Exception $e) {
            $this->logger->error('Error flushing klines batch', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        return $upsertedCount;
    }

    /**
     * Nettoie les anciennes klines en gardant seulement les N plus récentes par (symbol, timeframe).
     *
     * @param string|null $symbol       Filtrer par symbole (null = tous les symboles)
     * @param int         $keepLimit    Nombre de klines à conserver par (symbol, timeframe)
     * @param bool        $dryRun       Si true, ne supprime pas mais retourne les stats
     * 
     * @return array Statistiques détaillées par timeframe
     *               [
     *                 'timeframes' => [
     *                   '1m' => ['total' => 1500, 'to_keep' => 500, 'to_delete' => 1000, 'symbols' => ['BTCUSDT', 'ETHUSDT']],
     *                   '5m' => [...]
     *                 ],
     *                 'total_to_delete' => 1500,
     *                 'dry_run' => true
     *               ]
     */
    public function cleanupOldKlines(?string $symbol, int $keepLimit, bool $dryRun): array
    {
        $conn = $this->getConnection();
        $stats = [
            'timeframes' => [],
            'total_to_delete' => 0,
            'dry_run' => $dryRun,
        ];

        try {
            // Liste tous les timeframes disponibles
            $timeframes = array_map(fn($tf) => $tf->value, Timeframe::cases());

            foreach ($timeframes as $tf) {
                $tfStats = $this->cleanupForTimeframe($symbol, $tf, $keepLimit, $dryRun, $conn);
                
                if ($tfStats['total'] > 0) {
                    $stats['timeframes'][$tf] = $tfStats;
                    $stats['total_to_delete'] += $tfStats['to_delete'];
                }
            }

            $this->logger->info('[Cleanup] Klines cleanup completed', [
                'dry_run' => $dryRun,
                'symbol' => $symbol ?? 'ALL',
                'keep_limit' => $keepLimit,
                'total_to_delete' => $stats['total_to_delete'],
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('[Cleanup] Erreur lors du nettoyage des klines', [
                'error' => $e->getMessage(),
                'symbol' => $symbol,
            ]);
            throw $e;
        }

        return $stats;
    }

    /**
     * Nettoie les klines pour un timeframe spécifique
     */
    private function cleanupForTimeframe(?string $symbol, string $timeframe, int $keepLimit, bool $dryRun, Connection $conn): array
    {
        $symbolFilter = $symbol ? 'AND symbol = :symbol' : '';
        $params = ['timeframe' => $timeframe, 'keep_limit' => $keepLimit];
        $types = ['timeframe' => \Doctrine\DBAL\ParameterType::STRING, 'keep_limit' => \Doctrine\DBAL\ParameterType::INTEGER];

        if ($symbol) {
            $params['symbol'] = $symbol;
            $types['symbol'] = \Doctrine\DBAL\ParameterType::STRING;
        }

        // Calcul des statistiques par symbole
        $statsSql = <<<SQL
WITH ranked AS (
  SELECT 
    id,
    symbol,
    timeframe,
    open_time,
    ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY open_time DESC) AS rn
  FROM klines
  WHERE timeframe = :timeframe
    {$symbolFilter}
)
SELECT 
  symbol,
  COUNT(*) as total,
  COUNT(*) FILTER (WHERE rn <= :keep_limit) as to_keep,
  COUNT(*) FILTER (WHERE rn > :keep_limit) as to_delete
FROM ranked
GROUP BY symbol
SQL;

        $symbolStats = $conn->fetchAllAssociative($statsSql, $params, $types);

        $tfStats = [
            'total' => 0,
            'to_keep' => 0,
            'to_delete' => 0,
            'symbols' => [],
        ];

        foreach ($symbolStats as $row) {
            $tfStats['total'] += (int)$row['total'];
            $tfStats['to_keep'] += (int)$row['to_keep'];
            $tfStats['to_delete'] += (int)$row['to_delete'];
            
            if ((int)$row['to_delete'] > 0) {
                $tfStats['symbols'][] = [
                    'symbol' => $row['symbol'],
                    'total' => (int)$row['total'],
                    'to_delete' => (int)$row['to_delete'],
                ];
            }
        }

        // Si dry-run, on s'arrête ici
        if ($dryRun || $tfStats['to_delete'] === 0) {
            return $tfStats;
        }

        // Exécution réelle de la suppression
        $deleteSql = <<<SQL
DELETE FROM klines
WHERE id IN (
  SELECT id FROM (
    SELECT 
      id,
      ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY open_time DESC) AS rn
    FROM klines
    WHERE timeframe = :timeframe
      {$symbolFilter}
  ) ranked
  WHERE rn > :keep_limit
)
SQL;

        $deletedCount = $conn->executeStatement($deleteSql, $params, $types);
        
        $this->logger->info('[Cleanup] Klines deleted for timeframe', [
            'timeframe' => $timeframe,
            'symbol' => $symbol ?? 'ALL',
            'deleted_count' => $deletedCount,
        ]);

        return $tfStats;
    }
}
