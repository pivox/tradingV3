<?php

declare(strict_types=1);

namespace App\MtfValidator\Repository;

use App\MtfValidator\Entity\MtfAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;
use Doctrine\DBAL\Connection;

/**
 * @extends ServiceEntityRepository<MtfAudit>
 */
class MtfAuditRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly Connection $conn
    )
    {
        parent::__construct($registry, MtfAudit::class);
    }

    /**
     * Récupère les audits pour un symbole
     */
    public function findBySymbol(string $symbol, int $limit = 20): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.symbol = :symbol')
            ->setParameter('symbol', $symbol)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les audits pour un run_id
     */
    public function findByRunId(UuidInterface $runId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.runId = :runId')
            ->setParameter('runId', $runId)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les audits récents
     */
    public function findRecentAudits(int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les audits par étape
     */
    public function findByStep(string $step, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.step = :step')
            ->setParameter('step', $step)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les audits dans une plage de dates
     */
    public function findByDateRange(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate
    ): array {
        return $this->createQueryBuilder('m')
            ->where('m.createdAt >= :startDate')
            ->andWhere('m.createdAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les audits par symbole
     */
    public function countBySymbol(string $symbol): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.symbol = :symbol')
            ->setParameter('symbol', $symbol)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les audits par étape
     */
    public function countByStep(string $step): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.step = :step')
            ->setParameter('step', $step)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Supprime les audits anciens
     */
    public function deleteOldAudits(\DateTimeImmutable $cutoffDate): int
    {
        return $this->createQueryBuilder('m')
            ->delete()
            ->where('m.createdAt < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }
    /**
     * Top des conditions bloquantes (tous sides confondus), avec filtres optionnels.
     *
     * @param string[]|null           $symbols     Liste de symboles (IN), null = tous
     * @param string[]|null           $timeframes  Liste de TF (IN), null = tous
     * @param \DateTimeInterface|null $since       Filtre created_at > :since (exclusif)
     * @param \DateTimeInterface|null $from        Fenêtre created_at BETWEEN :from AND :to (inclusif)
     * @param \DateTimeInterface|null $to
     * @param int                     $limit       Nb max de lignes (classement)
     */
    public function topBlockingConditionsAllSides(
        ?array $symbols = null,
        ?array $timeframes = null,
        ?\DateTimeInterface $since = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $limit = 100
    ): array {
        [$where, $params, $types] = $this->buildFilters($symbols, $timeframes, $since, $from, $to);

        $sql = <<<SQL
WITH base AS (
  SELECT
    COALESCE(NULLIF(timeframe, ''), details->> 'timeframe') AS timeframe,
    step,
    symbol,
    created_at,
    COALESCE(
      candle_open_ts,
      NULLIF(details->> 'kline_time', '')::timestamp AT TIME ZONE 'UTC'
    ) AS candle_ts,
    jsonb_array_elements_text(COALESCE(details->'conditions_failed','[]'::jsonb)) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%'
  {$where}
)
SELECT
  condition_name,
  timeframe,
  COUNT(*)                AS fail_count,
  COUNT(DISTINCT symbol)  AS nb_symbols,
  MAX(candle_ts)          AS last_candle_ts,
  MAX(created_at)         AS last_row_ts
FROM base
GROUP BY condition_name, timeframe
ORDER BY fail_count DESC, timeframe
LIMIT :limit
SQL;

        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Top des conditions bloquantes ventilées par side (long/short).
     */
    public function topBlockingConditionsBySide(
        ?array $symbols = null,
        ?array $timeframes = null,
        ?\DateTimeInterface $since = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $limit = 100
    ): array {
        [$where, $params, $types] = $this->buildFilters($symbols, $timeframes, $since, $from, $to);

        $sql = <<<SQL
WITH long_side AS (
  SELECT 'long'::text AS side,
         COALESCE(NULLIF(timeframe, ''), details->> 'timeframe') AS timeframe,
         jsonb_array_elements_text(COALESCE(details->'failed_conditions_long','[]'::jsonb)) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
short_side AS (
  SELECT 'short'::text AS side,
         COALESCE(NULLIF(timeframe, ''), details->> 'timeframe') AS timeframe,
         jsonb_array_elements_text(COALESCE(details->'failed_conditions_short','[]'::jsonb)) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
u AS (SELECT * FROM long_side UNION ALL SELECT * FROM short_side)
SELECT side, condition_name, timeframe, COUNT(*) AS fail_count
FROM u
GROUP BY side, condition_name, timeframe
ORDER BY fail_count DESC, side, timeframe
LIMIT :limit
SQL;

        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Poids (%) de chaque condition au sein des échecs par timeframe.
     */
    public function conditionWeightsPerTimeframe(
        ?array $symbols = null,
        ?array $timeframes = null,
        ?\DateTimeInterface $since = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        [$where, $params, $types] = $this->buildFilters($symbols, $timeframes, $since, $from, $to);

        $sql = <<<SQL
WITH base AS (
  SELECT
    COALESCE(NULLIF(timeframe, ''), details->> 'timeframe') AS timeframe,
    -- Use conditions_failed when present and non-empty; otherwise fallback to union of long/short
    jsonb_array_elements_text(
      CASE
        WHEN jsonb_exists(details, 'conditions_failed')
             AND jsonb_typeof(details->'conditions_failed') = 'array'
             AND jsonb_array_length(details->'conditions_failed') > 0
        THEN details->'conditions_failed'
        ELSE COALESCE(details->'failed_conditions_long','[]'::jsonb) || COALESCE(details->'failed_conditions_short','[]'::jsonb)
      END
    ) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
agg AS (
  SELECT timeframe, condition_name, COUNT(*) AS fail_count
  FROM base
  GROUP BY timeframe, condition_name
)
SELECT
  timeframe,
  condition_name,
  fail_count,
  SUM(fail_count) OVER (PARTITION BY timeframe) AS total_fails,
  ROUND(
    CASE WHEN SUM(fail_count) OVER (PARTITION BY timeframe) > 0
         THEN 100.0 * fail_count / (SUM(fail_count) OVER (PARTITION BY timeframe))
         ELSE 0 END,
    2
  ) AS fail_pct
FROM agg
ORDER BY timeframe, fail_count DESC
SQL;

        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * ROLLUP (condition -> timeframe -> side) pour avoir sous-totaux & total.
     */
    public function rollupByConditionTimeframeSide(
        ?array $symbols = null,
        ?array $timeframes = null,
        ?\DateTimeInterface $since = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        [$where, $params, $types] = $this->buildFilters($symbols, $timeframes, $since, $from, $to);

        $sql = <<<SQL
WITH long_side AS (
  SELECT 'long'::text AS side,
         COALESCE(NULLIF(timeframe, ''), details->> 'timeframe') AS timeframe,
         jsonb_array_elements_text(COALESCE(details->'failed_conditions_long','[]'::jsonb)) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
short_side AS (
  SELECT 'short'::text AS side,
         COALESCE(NULLIF(timeframe, ''), details->> 'timeframe') AS timeframe,
         jsonb_array_elements_text(COALESCE(details->'failed_conditions_short','[]'::jsonb)) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
u AS (SELECT * FROM long_side UNION ALL SELECT * FROM short_side)
SELECT
  condition_name,
  timeframe,
  side,
  COUNT(*) AS fail_count
FROM u
GROUP BY ROLLUP (condition_name, timeframe, side)
ORDER BY condition_name NULLS LAST, timeframe NULLS LAST, side NULLS LAST
SQL;

        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Rapport des conditions bloquantes agrégées par timeframe.
     * Utile pour identifier quel timeframe est le plus problématique.
     */
    public function blockingByTimeframe(
        ?array $symbols = null,
        ?array $timeframes = null,
        ?\DateTimeInterface $since = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $limit = 100
    ): array {
        [$where, $params, $types] = $this->buildFilters($symbols, $timeframes, $since, $from, $to);

        $sql = <<<SQL
WITH base AS (
  SELECT
    COALESCE(NULLIF(timeframe, ''), details->>'timeframe') AS timeframe,
    symbol,
    step,
    created_at,
    COALESCE(
      candle_open_ts,
      NULLIF(details->>'kline_time', '')::timestamp AT TIME ZONE 'UTC'
    ) AS candle_ts
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%'
  {$where}
)
SELECT
  timeframe,
  COUNT(*)                AS total_failures,
  COUNT(DISTINCT symbol)  AS nb_symbols,
  MAX(candle_ts)          AS last_failure_candle,
  MAX(created_at)         AS last_failure_ts,
  MIN(created_at)         AS first_failure_ts
FROM base
GROUP BY timeframe
ORDER BY total_failures DESC, timeframe
LIMIT :limit
SQL;

        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Liste les dernières validations réussies.
     * Récupère les audits marqués comme SUCCESS ou VALIDATED.
     */
    public function recentSuccessfulValidations(
        ?array $symbols = null,
        ?array $timeframes = null,
        ?\DateTimeInterface $since = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $limit = 100
    ): array {
        $clauses = [];
        $params  = [];
        $types   = [];

        // Filtre sur les steps de succès
        $clauses[] = "AND (step LIKE '%SUCCESS%' OR step LIKE '%VALIDATED%' OR step = 'COMPLETED')";

        if (!empty($symbols)) {
            $clauses[]    = 'AND symbol IN (:symbols)';
            $params['symbols'] = $symbols;
            $types['symbols']  = ArrayParameterType::STRING;
        }

        if (!empty($timeframes)) {
            $clauses[]       = 'AND timeframe IN (:timeframes)';
            $params['timeframes'] = $timeframes;
            $types['timeframes']  = ArrayParameterType::STRING;
        }

        if ($from && $to) {
            $clauses[]       = 'AND created_at BETWEEN :from AND :to';
            $params['from']  = $from->format('Y-m-d H:i:sP');
            $params['to']    = $to->format('Y-m-d H:i:sP');
            $types['from']   = ParameterType::STRING;
            $types['to']     = ParameterType::STRING;
        } elseif ($since) {
            $clauses[]       = 'AND created_at > :since';
            $params['since'] = $since->format('Y-m-d H:i:sP');
            $types['since']  = ParameterType::STRING;
        }

        $where = '';
        if ($clauses) {
            $where = "\n  " . implode("\n  ", $clauses) . "\n";
        }

        $sql = <<<SQL
SELECT
  symbol,
  COALESCE(NULLIF(timeframe, ''), details->>'timeframe') AS timeframe,
  step,
  COALESCE(details->>'side', 'n/a') AS side,
  created_at,
  COALESCE(
    candle_open_ts,
    NULLIF(details->>'kline_time', '')::timestamp AT TIME ZONE 'UTC'
  ) AS candle_ts,
  run_id,
  cause
FROM mtf_audit
WHERE 1=1
  {$where}
ORDER BY created_at DESC
LIMIT :limit
SQL;

        $params['limit'] = $limit;
        $types['limit']  = ParameterType::INTEGER;

        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Retourne les validations par symbole avec indicateur "in_window" basé sur expectedLastOpenTime.
     * Version SQL optimisée de getLatestValidationSuccessesPerSymbol avec calcul de fenêtre temporelle.
     *
     * @param string|null $symbolSearch Filtre partiel sur le symbole (ILIKE)
     *
     * @return array<int,array{
     *     symbol: string,
     *     in_window_4h: int,
     *     in_window_1h: int,
     *     in_window_15m: int,
     *     in_window_5m: int,
     *     in_window_1m: int,
     *     validation_at_4h: string|null,
     *     validation_at_1h: string|null,
     *     validation_at_15m: string|null,
     *     validation_at_5m: string|null,
     *     validation_at_1m: string|null,
     * }>
     */
    public function getValidationSummaryWithInWindow(
        ?string $symbolSearch = null
    ): array {
        $params = [];
        $types = [];

        $symbolClause = '';
        if ($symbolSearch !== null && $symbolSearch !== '') {
            $params['search'] = '%' . strtoupper($symbolSearch) . '%';
            $types['search'] = ParameterType::STRING;
            $symbolClause = ' AND UPPER(symbol) LIKE :search';
        }

        // Calcul SQL du timestamp de clôture attendu de la dernière bougie fermée
        // Logique équivalente à expectedLastOpenTime() :
        // 1. Aligner sur la borne inférieure: floor(extract(epoch from now()) / seconds) * seconds
        // 2. Retirer un intervalle pour obtenir l'openTime de la dernière bougie fermée
        // 3. Le closeTime attendu = openTime + intervalle = aligned - intervalle + intervalle = aligned
        // Mais pour être explicite, on calcule: aligned - intervalle + intervalle = aligned
        // En fait, le closeTime attendu est simplement la borne alignée actuelle

        $sql = <<<SQL
WITH latest_validations AS (
    SELECT 
        symbol,
        timeframe,
        step,
        candle_open_ts,
        ROW_NUMBER() OVER (PARTITION BY symbol, timeframe ORDER BY candle_open_ts DESC, created_at DESC) AS rn
    FROM mtf_audit
    WHERE candle_open_ts IS NOT NULL
      AND (
        (timeframe = '4h' AND UPPER(step) = '4H_VALIDATION_SUCCESS') OR
        (timeframe = '1h' AND UPPER(step) = '1H_VALIDATION_SUCCESS') OR
        (timeframe = '15m' AND UPPER(step) = '15M_VALIDATION_SUCCESS') OR
        (timeframe = '5m' AND UPPER(step) = '5M_VALIDATION_SUCCESS') OR
        (timeframe = '1m' AND UPPER(step) = '1M_VALIDATION_SUCCESS')
      )
      {$symbolClause}
)
SELECT * FROM (
    SELECT
        symbol,
        -- 4h: Calculer l'openTime attendu de la dernière bougie fermée (expectedLastOpenTime)
        -- Logique: aligner sur borne, retirer intervalle pour obtenir l'openTime de la dernière bougie fermée
        MAX(CASE 
            WHEN timeframe = '4h' AND rn = 1 THEN
                CASE 
                    WHEN candle_open_ts = (
                        to_timestamp(FLOOR(EXTRACT(EPOCH FROM timezone('UTC', now())) / 14400) * 14400 - 14400) AT TIME ZONE 'UTC'
                    )
                    THEN 1 
                    ELSE 0 
                END
            ELSE 0
        END) AS in_window_4h,
        
        to_char(MAX(CASE WHEN timeframe = '4h' AND rn = 1 THEN candle_open_ts END), 'YYYY-MM-DD HH24:MI:SS') AS validation_at_4h,
        
        -- 1h
        MAX(CASE 
            WHEN timeframe = '1h' AND rn = 1 THEN
                CASE 
                    WHEN candle_open_ts = (
                        to_timestamp(FLOOR(EXTRACT(EPOCH FROM timezone('UTC', now())) / 3600) * 3600 - 3600) AT TIME ZONE 'UTC'
                    )
                    THEN 1 
                    ELSE 0 
                END
            ELSE 0
        END) AS in_window_1h,
        
        to_char(MAX(CASE WHEN timeframe = '1h' AND rn = 1 THEN candle_open_ts END), 'YYYY-MM-DD HH24:MI:SS') AS validation_at_1h,
        
        -- 15m
        MAX(CASE 
            WHEN timeframe = '15m' AND rn = 1 THEN
                CASE 
                    WHEN candle_open_ts = (
                        to_timestamp(FLOOR(EXTRACT(EPOCH FROM timezone('UTC', now())) / 900) * 900 - 900) AT TIME ZONE 'UTC'
                    )
                    THEN 1 
                    ELSE 0 
                END
            ELSE 0
        END) AS in_window_15m,
        
        to_char(MAX(CASE WHEN timeframe = '15m' AND rn = 1 THEN candle_open_ts END), 'YYYY-MM-DD HH24:MI:SS') AS validation_at_15m,
        
        -- 5m
        MAX(CASE 
            WHEN timeframe = '5m' AND rn = 1 THEN
                CASE 
                    WHEN candle_open_ts = (
                        to_timestamp(FLOOR(EXTRACT(EPOCH FROM timezone('UTC', now())) / 300) * 300 - 300) AT TIME ZONE 'UTC'
                    )
                    THEN 1 
                    ELSE 0 
                END
            ELSE 0
        END) AS in_window_5m,
        
        to_char(MAX(CASE WHEN timeframe = '5m' AND rn = 1 THEN candle_open_ts END), 'YYYY-MM-DD HH24:MI:SS') AS validation_at_5m,
        
        -- 1m
        MAX(CASE 
            WHEN timeframe = '1m' AND rn = 1 THEN
                CASE 
                    WHEN candle_open_ts = (
                        to_timestamp(FLOOR(EXTRACT(EPOCH FROM timezone('UTC', now())) / 60) * 60 - 60) AT TIME ZONE 'UTC'
                    )
                    THEN 1 
                    ELSE 0 
                END
            ELSE 0
        END) AS in_window_1m,
        
        to_char(MAX(CASE WHEN timeframe = '1m' AND rn = 1 THEN candle_open_ts END), 'YYYY-MM-DD HH24:MI:SS') AS validation_at_1m
        
    FROM latest_validations
    GROUP BY symbol
) AS t
ORDER BY (
    COALESCE(in_window_4h, 0) +
    COALESCE(in_window_1h, 0) +
    COALESCE(in_window_15m, 0) +
    COALESCE(in_window_5m, 0) +
    COALESCE(in_window_1m, 0)
) DESC, symbol
SQL;

        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        
        return array_map(function (array $row): array {
            return [
                'symbol' => (string)($row['symbol'] ?? ''),
                'in_window_4h' => (int)($row['in_window_4h'] ?? 0),
                'in_window_1h' => (int)($row['in_window_1h'] ?? 0),
                'in_window_15m' => (int)($row['in_window_15m'] ?? 0),
                'in_window_5m' => (int)($row['in_window_5m'] ?? 0),
                'in_window_1m' => (int)($row['in_window_1m'] ?? 0),
                'validation_at_4h' => $row['validation_at_4h'] ?? null,
                'validation_at_1h' => $row['validation_at_1h'] ?? null,
                'validation_at_15m' => $row['validation_at_15m'] ?? null,
                'validation_at_5m' => $row['validation_at_5m'] ?? null,
                'validation_at_1m' => $row['validation_at_1m'] ?? null,
            ];
        }, $rows);
    }

    /**
     * Retourne, par symbole, la dernière validation réussie pour chaque timeframe.
     * Inclut en option le dernier audit "READY" (MTF_CONTEXT ou *_READY) pour 1m.
     *
     * @param string|null $symbolSearch Filtre partiel sur le symbole (ILIKE)
     * @param string[]    $timeframes   Timeframes à inclure, ordre respecté dans la réponse
     *
     * @return array<int,array{
     *     symbol:string,
     *     timeframes: array<string,null|array{event_ts:?string,created_at:?string}>,
     *     ready: null|array{event_ts:?string,created_at:?string}
     * }>
     */
    public function getLatestValidationSuccessesPerSymbol(
        ?string $symbolSearch = null,
        array $timeframes = ['4h','1h','15m','5m','1m']
    ): array {
        $allowedTimeframes = ['4h','1h','15m','5m','1m'];
        $normalizedTfs = array_values(array_intersect($allowedTimeframes, array_map('strtolower', $timeframes)));
        if ($normalizedTfs === []) {
            return [];
        }

        $params = [];
        $types  = [];

        if ($symbolSearch !== null && $symbolSearch !== '') {
            $params['search'] = '%' . strtoupper($symbolSearch) . '%';
            $types['search']  = ParameterType::STRING;
        }

        $stepValues = array_map(
            static fn(string $tf): string => strtoupper($tf) . '_VALIDATION_SUCCESS',
            $normalizedTfs
        );
        $params['step_values'] = $stepValues;
        $types['step_values']  = ArrayParameterType::STRING;

        $symbolClause = '';
        if (isset($params['search'])) {
            $symbolClause = ' AND UPPER(symbol) LIKE :search';
        }

        $caseExpression = <<<SQL
CASE
  WHEN UPPER(step) = '4H_VALIDATION_SUCCESS' THEN '4h'
  WHEN UPPER(step) = '1H_VALIDATION_SUCCESS' THEN '1h'
  WHEN UPPER(step) = '15M_VALIDATION_SUCCESS' THEN '15m'
  WHEN UPPER(step) = '5M_VALIDATION_SUCCESS' THEN '5m'
  WHEN UPPER(step) = '1M_VALIDATION_SUCCESS' THEN '1m'
  ELSE NULL
END
SQL;

        $successSql = <<<SQL
WITH success_events AS (
  SELECT
    id,
    symbol,
    {$caseExpression} AS timeframe,
    -- event_ts = candle_open_ts + durée du timeframe (pour obtenir le closeTime)
    COALESCE(
      CASE 
        WHEN {$caseExpression} = '4h' THEN candle_open_ts + INTERVAL '4 hours'
        WHEN {$caseExpression} = '1h' THEN candle_open_ts + INTERVAL '1 hour'
        WHEN {$caseExpression} = '15m' THEN candle_open_ts + INTERVAL '15 minutes'
        WHEN {$caseExpression} = '5m' THEN candle_open_ts + INTERVAL '5 minutes'
        WHEN {$caseExpression} = '1m' THEN candle_open_ts + INTERVAL '1 minute'
        ELSE NULL
      END,
      NULLIF(details->>'kline_time', '')::timestamp AT TIME ZONE 'UTC'
    ) AS event_ts,
    candle_open_ts,
    created_at,
    cause
  FROM mtf_audit
  WHERE UPPER(step) IN (:step_values)
  {$symbolClause}
),
ranked_success AS (
  SELECT
    id,
    symbol,
    timeframe,
    event_ts,
    candle_open_ts,
    created_at,
    cause,
    ROW_NUMBER() OVER (PARTITION BY symbol, timeframe ORDER BY event_ts DESC, created_at DESC) AS rn
  FROM success_events
  WHERE timeframe IS NOT NULL
)
SELECT id, symbol, timeframe, event_ts, candle_open_ts, created_at, cause
FROM ranked_success
WHERE rn = 1
SQL;

        $successRows = $this->conn->executeQuery($successSql, $params, $types)->fetchAllAssociative();

        $failureStepValues = array_map(
            static fn(string $tf): string => strtoupper($tf) . '_VALIDATION_FAILED',
            $normalizedTfs
        );
        $params['failure_step_values'] = $failureStepValues;
        $types['failure_step_values'] = ArrayParameterType::STRING;

        $failureSql = <<<SQL
WITH failure_events AS (
  SELECT
    id,
    symbol,
    {$caseExpression} AS timeframe,
    -- event_ts = candle_open_ts + durée du timeframe (pour obtenir le closeTime)
    COALESCE(
      CASE 
        WHEN {$caseExpression} = '4h' THEN candle_open_ts + INTERVAL '4 hours'
        WHEN {$caseExpression} = '1h' THEN candle_open_ts + INTERVAL '1 hour'
        WHEN {$caseExpression} = '15m' THEN candle_open_ts + INTERVAL '15 minutes'
        WHEN {$caseExpression} = '5m' THEN candle_open_ts + INTERVAL '5 minutes'
        WHEN {$caseExpression} = '1m' THEN candle_open_ts + INTERVAL '1 minute'
        ELSE NULL
      END,
      NULLIF(details->>'kline_time', '')::timestamp AT TIME ZONE 'UTC'
    ) AS event_ts,
    candle_open_ts,
    created_at,
    cause
  FROM mtf_audit
  WHERE UPPER(step) IN (:failure_step_values)
  {$symbolClause}
),
ranked_failure AS (
  SELECT
    id,
    symbol,
    timeframe,
    event_ts,
    candle_open_ts,
    created_at,
    cause,
    ROW_NUMBER() OVER (PARTITION BY symbol, timeframe ORDER BY event_ts DESC, created_at DESC) AS rn
  FROM failure_events
  WHERE timeframe IS NOT NULL
)
SELECT id, symbol, timeframe, event_ts, candle_open_ts, created_at, cause
FROM ranked_failure
WHERE rn = 1
SQL;

        $failureRows = $this->conn->executeQuery($failureSql, $params, $types)->fetchAllAssociative();

        $readySql = <<<SQL
WITH ready_events AS (
  SELECT
    id,
    symbol,
    -- Pour READY, on utilise directement candle_open_ts (pas de timeframe spécifique)
    COALESCE(candle_open_ts, NULLIF(details->>'kline_time', '')::timestamp AT TIME ZONE 'UTC') AS event_ts,
    created_at
  FROM mtf_audit
  WHERE (
    UPPER(step) = 'MTF_CONTEXT'
    OR UPPER(step) LIKE '%READY%'
  )
  {$symbolClause}
),
ranked_ready AS (
  SELECT
    id,
    symbol,
    event_ts,
    created_at,
    ROW_NUMBER() OVER (PARTITION BY symbol ORDER BY event_ts DESC, created_at DESC) AS rn
  FROM ready_events
)
SELECT id, symbol, event_ts, created_at
FROM ranked_ready
WHERE rn = 1
SQL;

        $readyRows = $this->conn->executeQuery($readySql, $params, $types)->fetchAllAssociative();

        $results = [];

        foreach ($successRows as $row) {
            $symbol = (string)($row['symbol'] ?? '');
            $timeframe = (string)($row['timeframe'] ?? '');
            if ($symbol === '' || !in_array($timeframe, $normalizedTfs, true)) {
                continue;
            }

            if (!isset($results[$symbol])) {
                $results[$symbol] = [
                    'symbol' => $symbol,
                    'timeframes' => [],
                    'ready' => null,
                ];
            }

            $eventDt = $this->toDateTime($row['event_ts'] ?? null);
            $createdDt = $this->toDateTime($row['created_at'] ?? null);
            $latestDt = $eventDt;
            if ($createdDt !== null && ($latestDt === null || $createdDt > $latestDt)) {
                $latestDt = $createdDt;
            }

            $results[$symbol]['timeframes'][$timeframe] = [
                'status' => 'success',
                'event_ts' => $this->normalizeTimestamp($row['event_ts'] ?? null),
                'created_at' => $this->normalizeTimestamp($row['created_at'] ?? null),
                'cause' => $row['cause'] ?? null,
                'audit_id' => isset($row['id']) ? (int)$row['id'] : null,
                '_latest_ts' => $latestDt?->getTimestamp(),
            ];
        }

        foreach ($failureRows as $row) {
            $symbol = (string)($row['symbol'] ?? '');
            $timeframe = (string)($row['timeframe'] ?? '');
            if ($symbol === '' || !in_array($timeframe, $normalizedTfs, true)) {
                continue;
            }

            if (!isset($results[$symbol])) {
                $results[$symbol] = [
                    'symbol' => $symbol,
                    'timeframes' => [],
                    'ready' => null,
                ];
            }

            $eventDt = $this->toDateTime($row['event_ts'] ?? null);
            $createdDt = $this->toDateTime($row['created_at'] ?? null);
            $latestDt = $eventDt;
            if ($createdDt !== null && ($latestDt === null || $createdDt > $latestDt)) {
                $latestDt = $createdDt;
            }
            $latestTimestamp = $latestDt?->getTimestamp();

            $existing = $results[$symbol]['timeframes'][$timeframe] ?? null;
            $existingTimestamp = is_array($existing) ? ($existing['_latest_ts'] ?? null) : null;

            if ($latestTimestamp === null) {
                if ($existing === null) {
                    $results[$symbol]['timeframes'][$timeframe] = [
                        'status' => 'failed',
                        'event_ts' => $this->normalizeTimestamp($row['event_ts'] ?? null),
                        'created_at' => $this->normalizeTimestamp($row['created_at'] ?? null),
                        'cause' => $row['cause'] ?? null,
                        'audit_id' => isset($row['id']) ? (int)$row['id'] : null,
                        '_latest_ts' => null,
                    ];
                }
            } elseif (
                $existing === null
                || $existingTimestamp === null
                || $latestTimestamp >= $existingTimestamp
            ) {
                $results[$symbol]['timeframes'][$timeframe] = [
                    'status' => 'failed',
                    'event_ts' => $this->normalizeTimestamp($row['event_ts'] ?? null),
                    'created_at' => $this->normalizeTimestamp($row['created_at'] ?? null),
                    'cause' => $row['cause'] ?? null,
                    'audit_id' => isset($row['id']) ? (int)$row['id'] : null,
                    '_latest_ts' => $latestTimestamp,
                ];
            }
        }

        foreach ($readyRows as $row) {
            $symbol = (string)($row['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            if (!isset($results[$symbol])) {
                $results[$symbol] = [
                    'symbol' => $symbol,
                    'timeframes' => [],
                    'ready' => null,
                ];
            }

            $results[$symbol]['ready'] = [
                'event_ts' => $this->normalizeTimestamp($row['event_ts'] ?? null),
                'created_at' => $this->normalizeTimestamp($row['created_at'] ?? null),
                'audit_id' => isset($row['id']) ? (int)$row['id'] : null,
            ];
        }

        if ($results === []) {
            return [];
        }

        ksort($results);

        // Vérifier pour chaque symbole/timeframe si la dernière bougie close existe
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tfSecondsMap = [
            '4h' => 14400,
            '1h' => 3600,
            '15m' => 900,
            '5m' => 300,
            '1m' => 60,
        ];

        foreach ($results as &$entry) {
            $ordered = [];
            foreach ($normalizedTfs as $tf) {
                $tfData = $entry['timeframes'][$tf] ?? null;
                if ($tfData !== null && is_array($tfData) && $tfData['status'] === 'success') {
                    // event_ts est maintenant le closeTime (candle_open_ts + durée), donc l'openTime est event_ts - durée
                    $eventTs = $this->toDateTime($tfData['event_ts'] ?? null);
                    if ($eventTs !== null) {
                        $tfSeconds = $tfSecondsMap[$tf] ?? 0;
                        if ($tfSeconds > 0) {
                            $expectedOpenTime = $eventTs->modify("-{$tfSeconds} seconds");
                            $klineId = $this->findKlineId($entry['symbol'], $tf, $expectedOpenTime);
                            $tfData['kline_id'] = $klineId;
                            // Conserver l'indicateur d'existence si possible via klines (id non null)
                            $tfData['kline_exists'] = $klineId !== null ? true : ($tfData['kline_exists'] ?? null);
                        }
                    }
                }
                $ordered[$tf] = $tfData;
                if (is_array($ordered[$tf]) && array_key_exists('_latest_ts', $ordered[$tf])) {
                    unset($ordered[$tf]['_latest_ts']);
                }
            }
            $entry['timeframes'] = $ordered;
        }
        unset($entry);

        return array_values($results);
    }

    /**
     * Vérifie si une bougie existe dans hot_kline pour un symbole/timeframe/openTime donné.
     * 
     * @param string $symbol
     * @param string $timeframe
     * @param \DateTimeImmutable $openTime
     * @return bool|null null si erreur, true si existe et close, false si n'existe pas ou pas close
     */
    private function checkKlineExists(string $symbol, string $timeframe, \DateTimeImmutable $openTime): ?bool
    {
        try {
            $sql = <<<SQL
SELECT is_closed
FROM hot_kline
WHERE symbol = :symbol
  AND timeframe = :timeframe
  AND open_time = :open_time
LIMIT 1
SQL;
            
            $result = $this->conn->fetchOne($sql, [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open_time' => $openTime->format('Y-m-d H:i:s'),
            ], [
                'symbol' => ParameterType::STRING,
                'timeframe' => ParameterType::STRING,
                'open_time' => ParameterType::STRING,
            ]);

            if ($result === false) {
                // La bougie n'existe pas
                return false;
            }

            // La bougie existe, retourner si elle est close
            return (bool)$result;
        } catch (\Throwable) {
            // En cas d'erreur, retourner null
            return null;
        }
    }

    /**
     * Métriques de santé MTF sur une période donnée.
     * Retourne succès/échecs par timeframe, symboles problématiques, etc.
     */
    public function healthMetrics(
        ?\DateTimeInterface $since = null,
        ?array $symbols = null,
        ?array $timeframes = null
    ): array {
        $clauses = [];
        $params  = [];
        $types   = [];

        if ($since) {
            $clauses[]       = 'AND created_at > :since';
            $params['since'] = $since->format('Y-m-d H:i:sP');
            $types['since']  = ParameterType::STRING;
        }

        if (!empty($symbols)) {
            $clauses[]    = 'AND symbol IN (:symbols)';
            $params['symbols'] = $symbols;
            $types['symbols']  = ArrayParameterType::STRING;
        }

        if (!empty($timeframes)) {
            $clauses[]       = 'AND timeframe IN (:timeframes)';
            $params['timeframes'] = $timeframes;
            $types['timeframes']  = ArrayParameterType::STRING;
        }

        $where = '';
        if ($clauses) {
            $where = "\n  " . implode("\n  ", $clauses) . "\n";
        }

        $sql = <<<SQL
WITH stats AS (
  SELECT
    COALESCE(NULLIF(timeframe, ''), details->>'timeframe') AS timeframe,
    CASE
      WHEN step LIKE '%VALIDATION_FAILED%' THEN 'failed'
      WHEN step LIKE '%SUCCESS%' OR step LIKE '%VALIDATED%' THEN 'success'
      ELSE 'other'
    END AS status,
    symbol
  FROM mtf_audit
  WHERE 1=1
  {$where}
)
SELECT
  timeframe,
  COUNT(*) FILTER (WHERE status = 'success') AS success_count,
  COUNT(*) FILTER (WHERE status = 'failed')  AS failed_count,
  COUNT(DISTINCT symbol) AS symbols_count,
  ROUND(
    CASE WHEN COUNT(*) > 0
         THEN 100.0 * COUNT(*) FILTER (WHERE status = 'success') / COUNT(*)
         ELSE 0 END,
    2
  ) AS success_rate_pct
FROM stats
WHERE status IN ('success', 'failed')
GROUP BY timeframe
ORDER BY timeframe NULLS LAST
SQL;

        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Rapport de calibration : calcule le fail_pct moyen pour évaluer la qualité du système.
     * 
     * Formule : fail_pct_moyen = (∑ fail_count) / (∑ total_fails) × 100
     * 
     * Interprétation :
     *  - 0-5%   : Bon équilibre
     *  - 6-9%   : Marché neutre/cohérent
     *  - 10-15% : Règles trop strictes
     *  - >20%   : Mauvaise calibration
     *  - =0%    : Blocage total
     */
    public function calibrationReport(
        ?array $symbols = null,
        ?array $timeframes = null,
        ?\DateTimeInterface $since = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        [$where, $params, $types] = $this->buildFilters($symbols, $timeframes, $since, $from, $to);

        $sql = <<<SQL
WITH base AS (
  SELECT
    COALESCE(NULLIF(timeframe, ''), details->>'timeframe') AS timeframe,
    jsonb_array_elements_text(
      CASE
        WHEN jsonb_exists(details, 'conditions_failed')
             AND jsonb_typeof(details->'conditions_failed') = 'array'
             AND jsonb_array_length(details->'conditions_failed') > 0
        THEN details->'conditions_failed'
        ELSE COALESCE(details->'failed_conditions_long','[]'::jsonb) || COALESCE(details->'failed_conditions_short','[]'::jsonb)
      END
    ) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
agg AS (
  SELECT timeframe, condition_name, COUNT(*) AS fail_count
  FROM base
  GROUP BY timeframe, condition_name
),
totals AS (
  SELECT timeframe, SUM(fail_count) AS total_fails
  FROM agg
  GROUP BY timeframe
)
SELECT
  a.timeframe,
  a.condition_name,
  a.fail_count,
  t.total_fails,
  ROUND(
    CASE WHEN t.total_fails > 0
         THEN 100.0 * a.fail_count / t.total_fails
         ELSE 0 END,
    2
  ) AS fail_pct
FROM agg a
JOIN totals t ON a.timeframe = t.timeframe
ORDER BY a.timeframe, a.fail_count DESC
SQL;

        return $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Construit WHERE + paramètres pour (symbols, timeframes, since, between).
     * Règle de priorité dates : BETWEEN (:from,:to) si fourni, sinon > :since.
     */
    private function buildFilters(
        ?array $symbols,
        ?array $timeframes,
        ?\DateTimeInterface $since,
        ?\DateTimeInterface $from,
        ?\DateTimeInterface $to
    ): array {
        $clauses = [];
        $params  = [];
        $types   = [];

        if (!empty($symbols)) {
            $clauses[]    = 'AND symbol IN (:symbols)';
            $params['symbols'] = $symbols;
            $types['symbols']  = ArrayParameterType::STRING;
        }

        if (!empty($timeframes)) {
            $clauses[]       = 'AND timeframe IN (:timeframes)';
            $params['timeframes'] = $timeframes;
            $types['timeframes']  = ArrayParameterType::STRING;
        }

        if ($from && $to) {
            $clauses[]       = 'AND created_at BETWEEN :from AND :to';
            $params['from']  = $from->format('Y-m-d H:i:sP');
            $params['to']    = $to->format('Y-m-d H:i:sP');
            $types['from']   = ParameterType::STRING; // DBAL convertira en timestamp tz
            $types['to']     = ParameterType::STRING;
        } elseif ($since) {
            $clauses[]       = 'AND created_at > :since';
            $params['since'] = $since->format('Y-m-d H:i:sP');
            $types['since']  = ParameterType::STRING;
        }

        $where = '';
        if ($clauses) {
            $where = "\n  " . implode("\n  ", $clauses) . "\n";
        }

        return [$where, $params, $types];
    }

    /**
     * Nettoie les anciens audits MTF en ne gardant que les N derniers jours.
     *
     * @param string|null $symbol      Filtrer par symbole (null = tous les symboles)
     * @param int         $daysToKeep  Nombre de jours à conserver
     * @param bool        $dryRun      Si true, ne supprime pas mais retourne les stats
     * 
     * @return array Statistiques détaillées
     *               [
     *                 'total' => 5000,
     *                 'to_delete' => 4500,
     *                 'to_keep' => 500,
     *                 'cutoff_date' => '2025-10-30 12:00:00',
     *                 'symbols_affected' => ['BTCUSDT' => 1000, 'ETHUSDT' => 500],
     *                 'dry_run' => true
     *               ]
     */
    public function cleanupOldAudits(?string $symbol, int $daysToKeep, bool $dryRun): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$daysToKeep} days", new \DateTimeZone('UTC'));
        
        $symbolFilter = $symbol ? 'AND symbol = :symbol' : '';
        $params = ['cutoff' => $cutoffDate->format('Y-m-d H:i:sP')];
        $types = ['cutoff' => ParameterType::STRING];

        if ($symbol) {
            $params['symbol'] = $symbol;
            $types['symbol'] = ParameterType::STRING;
        }

        try {
            // Calcul des statistiques
            $statsSql = <<<SQL
SELECT 
  COUNT(*) as total,
  COUNT(*) FILTER (WHERE created_at < :cutoff) as to_delete,
  COUNT(*) FILTER (WHERE created_at >= :cutoff) as to_keep
FROM mtf_audit
WHERE 1=1
  {$symbolFilter}
SQL;

            $statsRow = $this->conn->fetchAssociative($statsSql, $params, $types);

            // Statistiques par symbole
            $symbolStatsSql = <<<SQL
SELECT 
  symbol,
  COUNT(*) as count
FROM mtf_audit
WHERE created_at < :cutoff
  {$symbolFilter}
GROUP BY symbol
ORDER BY count DESC
SQL;

            $symbolStats = $this->conn->fetchAllAssociative($symbolStatsSql, $params, $types);
            $symbolsAffected = [];
            foreach ($symbolStats as $row) {
                $symbolsAffected[$row['symbol']] = (int)$row['count'];
            }

            $stats = [
                'total' => (int)$statsRow['total'],
                'to_delete' => (int)$statsRow['to_delete'],
                'to_keep' => (int)$statsRow['to_keep'],
                'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
                'symbols_affected' => $symbolsAffected,
                'dry_run' => $dryRun,
            ];

            // Si dry-run, on s'arrête ici
            if ($dryRun || $stats['to_delete'] === 0) {
                return $stats;
            }

            // Exécution réelle de la suppression
            $deleteSql = <<<SQL
DELETE FROM mtf_audit
WHERE created_at < :cutoff
  {$symbolFilter}
SQL;

            $deletedCount = $this->conn->executeStatement($deleteSql, $params, $types);

            $this->getEntityManager()->getConnection()->getConfiguration()->getSQLLogger()?->stopQuery();

            return $stats;

        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('Erreur lors du nettoyage des audits MTF: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $dt = \DateTimeImmutable::createFromInterface($value);
        } elseif (is_string($value)) {
            try {
                $dt = new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return null;
            }
        } else {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Retourne l'ID de la bougie (table klines) si elle existe pour (symbol, timeframe, open_time).
     */
    private function findKlineId(string $symbol, string $timeframe, \DateTimeImmutable $openTime): ?int
    {
        try {
            $sql = <<<SQL
SELECT id
FROM klines
WHERE symbol = :symbol
  AND timeframe = :timeframe
  AND open_time = :open_time
LIMIT 1
SQL;
            $id = $this->conn->fetchOne($sql, [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'open_time' => $openTime->format('Y-m-d H:i:sP'),
            ]);
            if ($id === false || $id === null) {
                return null;
            }
            return (int)$id;
        } catch (\Throwable) {
            return null;
        }
    }
}
