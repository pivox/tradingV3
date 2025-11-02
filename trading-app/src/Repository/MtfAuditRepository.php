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
    COALESCE(NULLIF(timeframe, ''), details->> 'timeframe') AS timeframe,
    step,
    symbol,
    created_at,
    COALESCE(
      candle_close_ts,
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
      candle_close_ts,
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
    candle_close_ts,
    NULLIF(details->>'kline_time', '')::timestamp AT TIME ZONE 'UTC'
  ) AS candle_ts,
  run_id
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
}
