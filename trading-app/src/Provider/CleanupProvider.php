<?php

declare(strict_types=1);

namespace App\Provider;

use App\Provider\Repository\KlineRepository;
use App\MtfValidator\Repository\MtfAuditRepository;
use App\Repository\SignalRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Provider pour les opérations de nettoyage de la base de données.
 * 
 * Responsabilités :
 * - Nettoyer les klines anciennes (garder N par timeframe/symbole)
 * - Nettoyer les audits MTF anciens (garder N jours)
 * - Nettoyer les signaux anciens (garder N jours)
 */
final readonly class CleanupProvider
{
    // Constantes de configuration par défaut
    public const int KLINES_KEEP_LIMIT = 500;
    public const int MTF_AUDIT_DAYS_KEEP = 3;
    public const int SIGNALS_DAYS_KEEP = 3;

    public function __construct(
        private KlineRepository $klineRepository,
        private MtfAuditRepository $mtfAuditRepository,
        private SignalRepository $signalRepository,
        private Connection $connection,
        private LoggerInterface $logger
    ) {}

    /**
     * Exécute le nettoyage complet de toutes les tables.
     *
     * @param string|null $symbol          Filtrer par symbole (null = tous)
     * @param int         $klinesKeepLimit Nombre de klines à garder par (symbol, timeframe)
     * @param int         $auditDaysKeep   Nombre de jours d'audits à garder
     * @param int         $signalDaysKeep  Nombre de jours de signaux à garder
     * @param bool        $dryRun          Mode prévisualisation (pas de suppression)
     * 
     * @return array Rapport complet du nettoyage
     */
    public function cleanupAll(
        ?string $symbol = null,
        int $klinesKeepLimit = self::KLINES_KEEP_LIMIT,
        int $auditDaysKeep = self::MTF_AUDIT_DAYS_KEEP,
        int $signalDaysKeep = self::SIGNALS_DAYS_KEEP,
        bool $dryRun = true
    ): array {
        $this->logger->info('[CleanupProvider] Starting cleanup', [
            'symbol' => $symbol ?? 'ALL',
            'klines_keep_limit' => $klinesKeepLimit,
            'audit_days_keep' => $auditDaysKeep,
            'signal_days_keep' => $signalDaysKeep,
            'dry_run' => $dryRun,
        ]);

        $startTime = microtime(true);
        $report = [
            'dry_run' => $dryRun,
            'symbol' => $symbol,
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'klines' => [],
            'mtf_audit' => [],
            'signals' => [],
            'summary' => [],
            'errors' => [],
        ];

        try {
            // Si pas en dry-run, on utilise une transaction
            if (!$dryRun) {
                $this->connection->beginTransaction();
            }

            // 1. Nettoyage des klines
            try {
                $report['klines'] = $this->klineRepository->cleanupOldKlines(
                    $symbol,
                    $klinesKeepLimit,
                    $dryRun
                );
            } catch (\Throwable $e) {
                $report['errors'][] = sprintf('Klines cleanup failed: %s', $e->getMessage());
                $this->logger->error('[CleanupProvider] Klines cleanup failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            // 2. Nettoyage des audits MTF
            try {
                $report['mtf_audit'] = $this->mtfAuditRepository->cleanupOldAudits(
                    $symbol,
                    $auditDaysKeep,
                    $dryRun
                );
            } catch (\Throwable $e) {
                $report['errors'][] = sprintf('MTF audit cleanup failed: %s', $e->getMessage());
                $this->logger->error('[CleanupProvider] MTF audit cleanup failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            // 3. Nettoyage des signaux
            try {
                $report['signals'] = $this->signalRepository->cleanupOldSignals(
                    $symbol,
                    $signalDaysKeep,
                    $dryRun
                );
            } catch (\Throwable $e) {
                $report['errors'][] = sprintf('Signals cleanup failed: %s', $e->getMessage());
                $this->logger->error('[CleanupProvider] Signals cleanup failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            // Commit ou rollback selon les erreurs
            if (!$dryRun && $this->connection->isTransactionActive()) {
                if (!empty($report['errors'])) {
                    $this->connection->rollBack();
                    $this->logger->warning('[CleanupProvider] Transaction rolled back due to errors', [
                        'error_count' => count($report['errors']),
                    ]);
                } else {
                    $this->connection->commit();
                }
            }

            // Calcul du résumé
            $report['summary'] = [
                'total_to_delete' => (
                    ($report['klines']['total_to_delete'] ?? 0) +
                    ($report['mtf_audit']['to_delete'] ?? 0) +
                    ($report['signals']['to_delete'] ?? 0)
                ),
                'klines_to_delete' => $report['klines']['total_to_delete'] ?? 0,
                'mtf_audit_to_delete' => $report['mtf_audit']['to_delete'] ?? 0,
                'signals_to_delete' => $report['signals']['to_delete'] ?? 0,
                'execution_time_ms' => (int)((microtime(true) - $startTime) * 1000),
                'has_errors' => !empty($report['errors']),
            ];

            $this->logger->info('[CleanupProvider] Cleanup completed', [
                'summary' => $report['summary'],
            ]);

        } catch (\Throwable $e) {
            // Rollback en cas d'erreur
            if (!$dryRun && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $report['errors'][] = sprintf('Global cleanup failed: %s', $e->getMessage());
            $this->logger->error('[CleanupProvider] Global cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        return $report;
    }

    /**
     * Nettoie uniquement les klines.
     */
    public function cleanupKlines(
        ?string $symbol = null,
        int $keepLimit = self::KLINES_KEEP_LIMIT,
        bool $dryRun = true
    ): array {
        return $this->klineRepository->cleanupOldKlines($symbol, $keepLimit, $dryRun);
    }

    /**
     * Nettoie uniquement les audits MTF.
     */
    public function cleanupMtfAudit(
        ?string $symbol = null,
        int $daysToKeep = self::MTF_AUDIT_DAYS_KEEP,
        bool $dryRun = true
    ): array {
        return $this->mtfAuditRepository->cleanupOldAudits($symbol, $daysToKeep, $dryRun);
    }

    /**
     * Nettoie uniquement les signaux.
     */
    public function cleanupSignals(
        ?string $symbol = null,
        int $daysToKeep = self::SIGNALS_DAYS_KEEP,
        bool $dryRun = true
    ): array {
        return $this->signalRepository->cleanupOldSignals($symbol, $daysToKeep, $dryRun);
    }
}

