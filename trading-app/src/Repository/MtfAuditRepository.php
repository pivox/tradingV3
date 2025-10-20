<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MtfAudit;
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
    timeframe,
    step,
    symbol,
    created_at,
    candle_close_ts,
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
  MAX(candle_close_ts)    AS last_candle_ts,
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
  SELECT 'long'::text AS side, timeframe,
         jsonb_array_elements_text(COALESCE(details->'failed_conditions_long','[]'::jsonb)) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
short_side AS (
  SELECT 'short'::text AS side, timeframe,
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
    timeframe,
    jsonb_array_elements_text(COALESCE(details->'conditions_failed','[]'::jsonb)) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
tot AS (
  SELECT timeframe, COUNT(*) AS total_fails
  FROM base
  GROUP BY timeframe
)
SELECT
  b.timeframe,
  b.condition_name,
  COUNT(*)                                   AS fail_count,
  t.total_fails,
  ROUND(100.0 * COUNT(*) / t.total_fails, 2) AS fail_pct
FROM base b
JOIN tot  t USING (timeframe)
GROUP BY b.timeframe, b.condition_name, t.total_fails
ORDER BY b.timeframe, fail_count DESC
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
  SELECT 'long'::text AS side, timeframe,
         jsonb_array_elements_text(COALESCE(details->'failed_conditions_long','[]'::jsonb)) AS condition_name
  FROM mtf_audit
  WHERE step LIKE '%VALIDATION_FAILED%' {$where}
),
short_side AS (
  SELECT 'short'::text AS side, timeframe,
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
}
