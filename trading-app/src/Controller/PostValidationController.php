<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\PostValidation\Service\PostValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

/**
 * Contrôleur pour l'API Post-Validation
 */
#[Route('/api/post-validation', name: 'post_validation_')]
class PostValidationController extends AbstractController
{
    public function __construct(
        private readonly PostValidationService $postValidationService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Exécute Post-Validation pour un symbole et un côté
     */
    #[Route('/execute', name: 'execute', methods: ['POST'])]
    public function execute(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $symbol = $data['symbol'] ?? null;
            $side = $data['side'] ?? null;
            $mtfContext = $data['mtf_context'] ?? [];
            $walletEquity = (float) ($data['wallet_equity'] ?? 1000.0);
            $dryRun = (bool) ($data['dry_run'] ?? true);

            if (!$symbol || !$side) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Missing required parameters: symbol and side'
                ], 400);
            }

            if (!in_array($side, ['LONG', 'SHORT'])) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Invalid side. Must be LONG or SHORT'
                ], 400);
            }

            $this->logger->info('[PostValidationController] Executing post-validation', [
                'symbol' => $symbol,
                'side' => $side,
                'dry_run' => $dryRun,
                'wallet_equity' => $walletEquity
            ]);

            // Vérification d'idempotence
            $decisionKey = sprintf('%s:%s:%d', $symbol, '5m', time());
            $existingDecision = $this->postValidationService->checkIdempotence($decisionKey);
            
            if ($existingDecision) {
                return new JsonResponse([
                    'status' => 'success',
                    'message' => 'Decision already exists (idempotent)',
                    'data' => $existingDecision->toArray()
                ]);
            }

            // Exécution Post-Validation
            if ($dryRun) {
                $decision = $this->postValidationService->executePostValidationDryRun(
                    $symbol,
                    $side,
                    $mtfContext,
                    $walletEquity
                );
            } else {
                $decision = $this->postValidationService->executePostValidation(
                    $symbol,
                    $side,
                    $mtfContext,
                    $walletEquity
                );
            }

            return new JsonResponse([
                'status' => 'success',
                'message' => 'Post-validation completed',
                'data' => $decision->toArray()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[PostValidationController] Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Post-validation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtient les statistiques Post-Validation
     */
    #[Route('/statistics', name: 'statistics', methods: ['GET'])]
    public function getStatistics(): JsonResponse
    {
        try {
            $statistics = $this->postValidationService->getStatistics();

            return new JsonResponse([
                'status' => 'success',
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[PostValidationController] Statistics failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Failed to get statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Teste la configuration Post-Validation
     */
    #[Route('/test-config', name: 'test_config', methods: ['GET'])]
    public function testConfig(): JsonResponse
    {
        try {
            // Test avec des données simulées
            $testData = [
                'symbol' => 'BTCUSDT',
                'side' => 'LONG',
                'mtf_context' => [
                    '5m' => ['signal_side' => 'LONG', 'status' => 'valid'],
                    '15m' => ['signal_side' => 'LONG', 'status' => 'valid'],
                    'candle_close_ts' => time(),
                    'conviction_flag' => false
                ],
                'wallet_equity' => 1000.0
            ];

            $decision = $this->postValidationService->executePostValidationDryRun(
                $testData['symbol'],
                $testData['side'],
                $testData['mtf_context'],
                $testData['wallet_equity']
            );

            return new JsonResponse([
                'status' => 'success',
                'message' => 'Configuration test completed',
                'data' => [
                    'test_input' => $testData,
                    'test_output' => $decision->toArray()
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[PostValidationController] Config test failed', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => 'Configuration test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtient la documentation de l'API
     */
    #[Route('/docs', name: 'docs', methods: ['GET'])]
    public function getDocs(): JsonResponse
    {
        $docs = [
            'title' => 'Post-Validation API',
            'description' => 'API pour l\'étape Post-Validation (EntryZone + PositionOpener)',
            'version' => '1.0.0',
            'endpoints' => [
                [
                    'path' => '/api/post-validation/execute',
                    'method' => 'POST',
                    'description' => 'Exécute Post-Validation pour un symbole et un côté',
                    'parameters' => [
                        'symbol' => 'string (required) - Symbole à traiter (ex: BTCUSDT)',
                        'side' => 'string (required) - LONG ou SHORT',
                        'mtf_context' => 'object (optional) - Contexte MTF',
                        'wallet_equity' => 'float (optional) - Capital disponible (défaut: 1000.0)',
                        'dry_run' => 'boolean (optional) - Mode dry-run (défaut: true)'
                    ],
                    'response' => [
                        'status' => 'success|error',
                        'message' => 'string',
                        'data' => 'PostValidationDecisionDto'
                    ]
                ],
                [
                    'path' => '/api/post-validation/statistics',
                    'method' => 'GET',
                    'description' => 'Obtient les statistiques Post-Validation',
                    'response' => [
                        'status' => 'success|error',
                        'data' => 'Statistics object'
                    ]
                ],
                [
                    'path' => '/api/post-validation/test-config',
                    'method' => 'GET',
                    'description' => 'Teste la configuration Post-Validation',
                    'response' => [
                        'status' => 'success|error',
                        'data' => 'Test results'
                    ]
                ]
            ],
            'features' => [
                'Sélection dynamique 1m vs 5m basée sur ATR et liquidité',
                'Récupération du dernier prix (WS -> REST -> K-line)',
                'Calcul de zone d\'entrée avec ancrage VWAP et microstructure',
                'Sizing et levier avec respect des brackets exchange',
                'Plan d\'ordres (maker -> taker fallback)',
                'Garde-fous (stale data, slippage, liquidité, risk limits)',
                'Machine d\'états pour orchestration des séquences',
                'Idempotence et traçabilité complète'
            ]
        ];

        return new JsonResponse($docs);
    }
}

