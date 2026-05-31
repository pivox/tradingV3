<?php

declare(strict_types=1);

namespace App\Provider\Repository;

use App\Common\Enum\Timeframe;
use App\Provider\Context\ExchangeContext;
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
        int $maxPerReq = 500,
        ?ExchangeContext $context = null,
    ): array {
        $conn = $this->getConnection();
        $step = Timeframe::from($timeframe)->getStepInMinutes();

        try {
            $rows = $conn->fetchAllAssociative(
                <<<SQL
WITH expected AS (
    SELECT generate_series(
        CAST(:start_utc AS timestamptz),
        CAST(:end_utc AS timestamptz),
        (:step_minutes || ' minutes')::interval
    ) AS open_time
),
missing AS (
    SELECT
        e.open_time,
        ROW_NUMBER() OVER (ORDER BY e.open_time) AS rn
    FROM expected e
    LEFT JOIN klines k
        ON k.exchange = :exchange
       AND k.market_type = :market_type
       AND k.symbol = :symbol
       AND k.timeframe = :timeframe
       AND k.open_time = e.open_time
    WHERE k.id IS NULL
),
chunked AS (
    SELECT
        open_time,
        FLOOR((rn - 1) / :max_per_req) AS chunk_id
    FROM missing
)
SELECT
    :symbol AS symbol,
    :step_minutes AS step,
    EXTRACT(EPOCH FROM MIN(open_time))::bigint AS "from",
    EXTRACT(EPOCH FROM MAX(open_time))::bigint AS "to"
FROM chunked
GROUP BY chunk_id
ORDER BY "from", "to"
SQL,
                [
                    'exchange' => ExchangeContext::exchangeValue($context),
                    'market_type' => ExchangeContext::marketTypeValue($context),
                    'symbol' => strtoupper($symbol),
                    'timeframe' => $timeframe,
                    'start_utc' => $startUtc->format('Y-m-d H:i:sP'),
                    'end_utc' => $endUtc->format('Y-m-d H:i:sP'),
                    'step_minutes' => $step,
                    'max_per_req' => max(1, $maxPerReq),
                ],
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
    public function getKlines(
        string $symbol,
        Timeframe $timeframe,
        int $limit = 1000,
        ?ExchangeContext $context = null,
    ): array
    {
        return $this->createQueryBuilder('k')
            ->where('k.exchange = :exchange')
            ->andWhere('k.marketType = :marketType')
            ->andWhere('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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
    public function findBySymbolAndTimeframe(
        string $symbol,
        Timeframe $timeframe,
        int $limit = 1000,
        ?ExchangeContext $context = null,
    ): array
    {
        return $this->getKlines($symbol, $timeframe, $limit, $context);
    }

    /**
     * Récupère la dernière kline pour un symbole et timeframe
     */
    public function findLastBySymbolAndTimeframe(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): ?Kline
    {
        return $this->createQueryBuilder('k')
            ->where('k.exchange = :exchange')
            ->andWhere('k.marketType = :marketType')
            ->andWhere('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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
        \DateTimeImmutable $endDate,
        ?ExchangeContext $context = null,
    ): array {
        return $this->createQueryBuilder('k')
            ->where('k.exchange = :exchange')
            ->andWhere('k.marketType = :marketType')
            ->andWhere('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->andWhere('k.openTime >= :startDate')
            ->andWhere('k.openTime <= :endDate')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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
    public function hasGaps(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null): bool
    {
        $qb = $this->createQueryBuilder('k');

        $result = $qb->select('COUNT(k.id) as count')
            ->where('k.exchange = :exchange')
            ->andWhere('k.marketType = :marketType')
            ->andWhere('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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
    public function upsert(Kline $kline, ?ExchangeContext $context = null): void
    {
        if ($context !== null) {
            $kline->setExchange($context->exchange);
            $kline->setMarketType($context->marketType);
        }

        $existing = $this->findOneBy([
            'exchange' => $kline->getExchange(),
            'marketType' => $kline->getMarketType(),
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
    public function saveKlines(
        ListKlinesDto $listKlinesDto,
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): void
    {
        $em = $this->getEntityManager();
        $batchSize = 100;
        $i = 0;

        foreach ($listKlinesDto as $klineDto) {
            $kline = new \App\Provider\Entity\Kline();
            $kline->setExchange(ExchangeContext::exchangeValue($context));
            $kline->setMarketType(ExchangeContext::marketTypeValue($context));
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
    public function findRecentForIndicators(
        string $symbol,
        Timeframe $timeframe,
        int $requiredPeriods = 200,
        ?ExchangeContext $context = null,
    ): array
    {
        return $this->createQueryBuilder('k')
            ->where('k.exchange = :exchange')
            ->andWhere('k.marketType = :marketType')
            ->andWhere('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->orderBy('k.openTime', 'DESC')
            ->setMaxResults($requiredPeriods)
            ->getQuery()
            ->getResult();
    }

    public function findWithFilters(
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?ExchangeContext $context = null,
    ): array
    {
        $qb = $this->createQueryBuilder('k')
            ->where('k.exchange = :exchange')
            ->andWhere('k.marketType = :marketType')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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

    public function countKlines(string $symbol, Timeframe $timeframe, ?ExchangeContext $context = null)
    {
        return $this->createQueryBuilder('k')
            ->select('COUNT(k.id)')
            ->where('k.exchange = :exchange')
            ->andWhere('k.marketType = :marketType')
            ->andWhere('k.symbol = :symbol')
            ->andWhere('k.timeframe = :timeframe')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getKlinesStats(?ExchangeContext $context = null)
    {
        return $this->createQueryBuilder('k')
            ->select('k.symbol, k.timeframe, COUNT(k.id) as count, MAX(k.openTime) as earliest, MIN(k.openTime) as latest')
            ->where('k.exchange = :exchange')
            ->andWhere('k.marketType = :marketType')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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
    public function upsertKlines(array $klines, ?ExchangeContext $context = null): int
    {
        if (empty($klines)) {
            return 0;
        }

        $this->logger->info('Starting klines upsert', [
            'count' => \count($klines),
            'exchange' => ExchangeContext::exchangeValue($context),
            'market_type' => ExchangeContext::marketTypeValue($context),
            'symbol' => $klines[0]->symbol ?? 'unknown',
            'timeframe' => $klines[0]->timeframe->value ?? 'unknown',
        ]);

        $conn = $this->getConnection();
        $batchSize = 50;
        $totalUpserted = 0;

        foreach (array_chunk($klines, $batchSize) as $batch) {
            $placeholders = [];
            $params = [];

            foreach ($batch as $klineDto) {
                if (!$klineDto instanceof \App\Contract\Provider\Dto\KlineDto) {
                    continue;
                }

                $placeholders[] = "(nextval('klines_id_seq'),?,?,?,?,?,?,?,?,?,?,?,?,?)";

                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $openTime = $klineDto->openTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
                $nowStr = $now->format('Y-m-d H:i:sP');

                $params[] = ExchangeContext::exchangeValue($context);
                $params[] = ExchangeContext::marketTypeValue($context);
                $params[] = $klineDto->symbol;
                $params[] = $klineDto->timeframe->value;
                $params[] = $openTime;
                $params[] = $klineDto->open->toScale(12)->__toString();
                $params[] = $klineDto->high->toScale(12)->__toString();
                $params[] = $klineDto->low->toScale(12)->__toString();
                $params[] = $klineDto->close->toScale(12)->__toString();
                $params[] = $klineDto->volume->toScale(12)->__toString();
                $params[] = $klineDto->source;
                $params[] = $nowStr;
                $params[] = $nowStr;
            }

            if ($placeholders === []) {
                continue;
            }

            $sql = '
                INSERT INTO klines (
                    id,
                    exchange,
                    market_type,
                    symbol,
                    timeframe,
                    open_time,
                    open_price,
                    high_price,
                    low_price,
                    close_price,
                    volume,
                    source,
                    inserted_at,
                    updated_at
                ) VALUES ' . \implode(',', $placeholders) . '
                ON CONFLICT (exchange, market_type, symbol, timeframe, open_time) DO UPDATE SET
                    open_price = EXCLUDED.open_price,
                    high_price = EXCLUDED.high_price,
                    low_price  = EXCLUDED.low_price,
                    close_price = EXCLUDED.close_price,
                    volume     = EXCLUDED.volume,
                    source     = EXCLUDED.source,
                    updated_at = EXCLUDED.updated_at
            ';

            try {
                $conn->executeStatement($sql, $params);
                $totalUpserted += \count($batch);
            } catch (\Throwable $e) {
                $this->logger->error('Error upserting klines batch via DBAL', [
                    'error' => $e->getMessage(),
                    'batch_count' => \count($batch),
                ]);
                throw $e;
            }
        }

        $this->logger->info('Klines upsert completed', [
            'total_upserted' => $totalUpserted,
            'total_input' => \count($klines),
            'exchange' => ExchangeContext::exchangeValue($context),
            'market_type' => ExchangeContext::marketTypeValue($context),
        ]);

        return $totalUpserted;
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
    public function cleanupOldKlines(
        ?string $symbol,
        int $keepLimit,
        bool $dryRun,
        ?ExchangeContext $context = null,
    ): array
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
                $tfStats = $this->cleanupForTimeframe($symbol, $tf, $keepLimit, $dryRun, $conn, $context);
                
                if ($tfStats['total'] > 0) {
                    $stats['timeframes'][$tf] = $tfStats;
                    $stats['total_to_delete'] += $tfStats['to_delete'];
                }
            }

            $this->logger->info('[Cleanup] Klines cleanup completed', [
                'dry_run' => $dryRun,
                'exchange' => ExchangeContext::exchangeValue($context),
                'market_type' => ExchangeContext::marketTypeValue($context),
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
    private function cleanupForTimeframe(
        ?string $symbol,
        string $timeframe,
        int $keepLimit,
        bool $dryRun,
        Connection $conn,
        ?ExchangeContext $context = null,
    ): array
    {
        $symbolFilter = $symbol ? 'AND symbol = :symbol' : '';
        $params = [
            'exchange' => ExchangeContext::exchangeValue($context),
            'market_type' => ExchangeContext::marketTypeValue($context),
            'timeframe' => $timeframe,
            'keep_limit' => $keepLimit,
        ];
        $types = [
            'exchange' => \Doctrine\DBAL\ParameterType::STRING,
            'market_type' => \Doctrine\DBAL\ParameterType::STRING,
            'timeframe' => \Doctrine\DBAL\ParameterType::STRING,
            'keep_limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ];

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
  WHERE exchange = :exchange
    AND market_type = :market_type
    AND timeframe = :timeframe
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
    WHERE exchange = :exchange
      AND market_type = :market_type
      AND timeframe = :timeframe
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
