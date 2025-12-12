<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\TradeEntryModeContext;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use App\MtfRunner\Service\MtfRunnerService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RunnerController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TradeEntryModeContext $modeContext,
    ) {
    }

    #[Route('/api/mtf/run')]
//    #[Route('/runner')]
    public function index(
        Request $request,
        MtfRunnerService $mtfRunnerService,
    ): JsonResponse
    {
        $apiStartTime = microtime(true);

        try {
            // Parse JSON request body for POST or query parameters for GET
            $data = [];
            if ($request->getMethod() === 'POST') {
                $data = json_decode($request->getContent(), true) ?? [];
            } else {
                // For GET requests, get parameters from query string
                $data = $request->query->all();
            }

            $symbolsInput = $data['symbols'] ?? [];
            $dryRun = filter_var($data['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $forceRun = filter_var($data['force_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $forceTimeframeCheck = filter_var($data['force_timeframe_check'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $currentTf = $data['current_tf'] ?? null;
            $currentTf = is_string($currentTf) && $currentTf !== '' ? $currentTf : null;
            $workers = max(1, (int)($data['workers'] ?? 1));

            // Normaliser les symboles fournis sans appliquer de fallback/queue
            $symbols = [];
            if (is_string($symbolsInput)) {
                $symbols = array_filter(array_map('trim', explode(',', $symbolsInput)));
            } elseif (is_array($symbolsInput)) {
                foreach ($symbolsInput as $value) {
                    if (is_string($value) && $value !== '') {
                        $symbols[] = trim($value);
                    }
                }
            }
            $symbols = array_values(array_unique($symbols));

            // Injection automatique du profile depuis la configuration si non fourni
            // ROLLBACK: Si besoin de revenir en arrière, supprimer cette logique et remettre:
            // 'profile' => $data['profile'] ?? $data['mtf_profile'] ?? null,
            $defaultProfile = null;
            if (!isset($data['profile']) && !isset($data['mtf_profile'])) {
                $enabledModes = $this->modeContext->getEnabledModes();
                if (!empty($enabledModes)) {
                    $defaultProfile = $enabledModes[0]['name'] ?? null;
                    $this->logger->debug('[Runner Controller] Auto-injecting profile from config', [
                        'profile' => $defaultProfile,
                        'enabled_modes' => array_column($enabledModes, 'name'),
                    ]);
                }
            }

            // Construire la requête Runner (le Runner gère tout : résolution, filtrage, switches, TP/SL, post-processing)
            $runnerRequest = MtfRunnerRequestDto::fromArray([
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'force_timeframe_check' => $forceTimeframeCheck,
                'skip_context' => (bool)($data['skip_context'] ?? false),
                'lock_per_symbol' => (bool)($data['lock_per_symbol'] ?? false),
                'skip_open_state_filter' => (bool)($data['skip_open_state_filter'] ?? false),
                'user_id' => $data['user_id'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'exchange' => $data['exchange'] ?? $data['cex'] ?? null,
                'market_type' => $data['market_type'] ?? $data['type_contract'] ?? null,
                'workers' => $workers,
                'sync_tables' => filter_var($data['sync_tables'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'process_tp_sl' => filter_var($data['process_tp_sl'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'profile' => $data['profile'] ?? $data['mtf_profile'] ?? $defaultProfile,
                'mtf_profile' => $data['mtf_profile'] ?? $defaultProfile,
                'validation_mode' => $data['validation_mode'] ?? null,
                'context_mode' => $data['context_mode'] ?? null,
                'mode' => $data['mode'] ?? null,
            ]);
            $result = $mtfRunnerService->run($runnerRequest);

            // Le résultat est déjà enrichi par MtfRunnerService
            $results = $result['results'] ?? [];
            $errors = $result['errors'] ?? [];
            $runSummary = $result['summary'] ?? [];
            $performanceReport = $result['performance'] ?? [];

            // Déterminer le statut
            $status = 'success';
            if (!empty($errors)) {
                $status = 'partial_success';
            }
            if (is_array($runSummary) && isset($runSummary['status']) && is_string($runSummary['status'])) {
                // Garder le statut métier détaillé retourné par le Runner si disponible
                $status = $runSummary['status'];
            }

            $apiTotalTime = microtime(true) - $apiStartTime;

            $this->logger->info('[Runner Controller] Performance Analysis', [
                'total_api_time' => round($apiTotalTime, 3),
                'symbols_count' => count($symbols),
                'workers' => $workers,
                'performance_report' => $performanceReport,
            ]);

            // Réponse alignée sur l'ancien endpoint runMtfCycle() pour compatibilité
            return $this->json([
                'status' => $status,
                'message' => 'MTF run completed',
                'data' => [
                    'run' => $runSummary,
                    'symbols' => $results,
                    'errors' => $errors,
                    'workers' => $workers,
                    'summary_by_tf' => $result['summary_by_tf'] ?? [],
                    'rejected_by' => $result['rejected_by'] ?? [],
                    'last_validated' => $result['last_validated'] ?? [],
                    'performance' => $performanceReport,
                    'orders_placed' => $result['orders_placed'] ?? [
                        'count' => ['total' => 0, 'submitted' => 0, 'simulated' => 0],
                        'orders' => [],
                    ],
                ],
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('[Runner Controller] Failed to run MTF cycle', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
