<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Provider\CleanupProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller pour les opérations de maintenance de la base de données.
 */
#[Route('/api/maintenance', name: 'api_maintenance_')]
final class MaintenanceController extends AbstractController
{
    public function __construct(
        private readonly CleanupProvider $cleanupProvider
    ) {}

    /**
     * Endpoint principal de nettoyage complet.
     * 
     * Paramètres POST/GET :
     * - symbol (optionnel) : Filtrer par symbole (ex: BTCUSDT)
     * - dry_run (défaut: true) : Mode prévisualisation sans suppression
     * - klines_limit (défaut: 500) : Nombre de klines à garder par (symbol, timeframe)
     * - audit_days (défaut: 3) : Nombre de jours d'audits MTF à garder
     * - signal_days (défaut: 3) : Nombre de jours de signaux à garder
     * 
     * Exemples :
     * POST /api/maintenance/cleanup {"dry_run": true}
     * POST /api/maintenance/cleanup {"symbol": "BTCUSDT", "dry_run": false}
     */
    #[Route('/cleanup', name: 'cleanup', methods: ['POST', 'GET'])]
    public function cleanup(Request $request): JsonResponse
    {
        try {
            // Récupération des paramètres (JSON body ou query string)
            $data = [];
            if ($request->getMethod() === 'POST' && $request->getContent()) {
                $data = json_decode($request->getContent(), true) ?? [];
            } else {
                $data = $request->query->all();
            }

            $symbol = isset($data['symbol']) && trim((string)$data['symbol']) !== '' 
                ? trim((string)$data['symbol']) 
                : null;
            
            $dryRun = isset($data['dry_run']) 
                ? filter_var($data['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
                : true;

            $klinesLimit = isset($data['klines_limit']) 
                ? max(1, (int)$data['klines_limit']) 
                : CleanupProvider::KLINES_KEEP_LIMIT;

            $auditDays = isset($data['audit_days']) 
                ? max(1, (int)$data['audit_days']) 
                : CleanupProvider::MTF_AUDIT_DAYS_KEEP;

            $signalDays = isset($data['signal_days']) 
                ? max(1, (int)$data['signal_days']) 
                : CleanupProvider::SIGNALS_DAYS_KEEP;

            // Exécution du nettoyage
            $report = $this->cleanupProvider->cleanupAll(
                symbol: $symbol,
                klinesKeepLimit: $klinesLimit,
                auditDaysKeep: $auditDays,
                signalDaysKeep: $signalDays,
                dryRun: $dryRun
            );

            return $this->json($report, $dryRun ? 200 : 200);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Cleanup failed',
                'message' => $e->getMessage(),
                'trace' => $this->getParameter('kernel.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Nettoyage ciblé : klines uniquement.
     */
    #[Route('/cleanup/klines', name: 'cleanup_klines', methods: ['POST', 'GET'])]
    public function cleanupKlines(Request $request): JsonResponse
    {
        try {
            $data = [];
            if ($request->getMethod() === 'POST' && $request->getContent()) {
                $data = json_decode($request->getContent(), true) ?? [];
            } else {
                $data = $request->query->all();
            }

            $symbol = isset($data['symbol']) && trim((string)$data['symbol']) !== '' 
                ? trim((string)$data['symbol']) 
                : null;
            
            $dryRun = isset($data['dry_run']) 
                ? filter_var($data['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
                : true;

            $keepLimit = isset($data['keep_limit']) 
                ? max(1, (int)$data['keep_limit']) 
                : CleanupProvider::KLINES_KEEP_LIMIT;

            $result = $this->cleanupProvider->cleanupKlines($symbol, $keepLimit, $dryRun);

            return $this->json([
                'table' => 'klines',
                'result' => $result,
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Klines cleanup failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Nettoyage ciblé : audits MTF uniquement.
     */
    #[Route('/cleanup/mtf-audit', name: 'cleanup_mtf_audit', methods: ['POST', 'GET'])]
    public function cleanupMtfAudit(Request $request): JsonResponse
    {
        try {
            $data = [];
            if ($request->getMethod() === 'POST' && $request->getContent()) {
                $data = json_decode($request->getContent(), true) ?? [];
            } else {
                $data = $request->query->all();
            }

            $symbol = isset($data['symbol']) && trim((string)$data['symbol']) !== '' 
                ? trim((string)$data['symbol']) 
                : null;
            
            $dryRun = isset($data['dry_run']) 
                ? filter_var($data['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
                : true;

            $daysToKeep = isset($data['days_keep']) 
                ? max(1, (int)$data['days_keep']) 
                : CleanupProvider::MTF_AUDIT_DAYS_KEEP;

            $result = $this->cleanupProvider->cleanupMtfAudit($symbol, $daysToKeep, $dryRun);

            return $this->json([
                'table' => 'mtf_audit',
                'result' => $result,
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'MTF audit cleanup failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Nettoyage ciblé : signaux uniquement.
     */
    #[Route('/cleanup/signals', name: 'cleanup_signals', methods: ['POST', 'GET'])]
    public function cleanupSignals(Request $request): JsonResponse
    {
        try {
            $data = [];
            if ($request->getMethod() === 'POST' && $request->getContent()) {
                $data = json_decode($request->getContent(), true) ?? [];
            } else {
                $data = $request->query->all();
            }

            $symbol = isset($data['symbol']) && trim((string)$data['symbol']) !== '' 
                ? trim((string)$data['symbol']) 
                : null;
            
            $dryRun = isset($data['dry_run']) 
                ? filter_var($data['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
                : true;

            $daysToKeep = isset($data['days_keep']) 
                ? max(1, (int)$data['days_keep']) 
                : CleanupProvider::SIGNALS_DAYS_KEEP;

            $result = $this->cleanupProvider->cleanupSignals($symbol, $daysToKeep, $dryRun);

            return $this->json([
                'table' => 'signals',
                'result' => $result,
            ]);

        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Signals cleanup failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtenir les paramètres par défaut du nettoyage.
     */
    #[Route('/cleanup/defaults', name: 'cleanup_defaults', methods: ['GET'])]
    public function getDefaults(): JsonResponse
    {
        return $this->json([
            'defaults' => [
                'klines_keep_limit' => CleanupProvider::KLINES_KEEP_LIMIT,
                'mtf_audit_days_keep' => CleanupProvider::MTF_AUDIT_DAYS_KEEP,
                'signals_days_keep' => CleanupProvider::SIGNALS_DAYS_KEEP,
            ],
            'description' => [
                'klines_keep_limit' => 'Nombre de klines à conserver par (symbol, timeframe)',
                'mtf_audit_days_keep' => 'Nombre de jours d\'audits MTF à conserver',
                'signals_days_keep' => 'Nombre de jours de signaux à conserver',
            ],
        ]);
    }
}

