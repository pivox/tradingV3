<?php

namespace App\Controller\Web;

use App\Common\Enum\Timeframe;
use App\Indicator\ConditionLoader\TimeframeEvaluator;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Indicator\Registry\ConditionRegistry;
use App\Kline\Port\KlineProviderPort;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\KlineDataService;
use App\Service\TradingConfigService;
use App\Signal\SignalValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/indicators', name: 'indicators_')]
class IndicatorTestController extends AbstractController
{
    private IndicatorContextBuilder $contextBuilder;
    private ConditionRegistry $conditionRegistry;
    private TimeframeEvaluator $timeframeEvaluator;
    private TradingConfigService $tradingConfigService;
    private KlineDataService $klineDataService;
    private ContractRepository $contractRepository;
    private KlineRepository $klineRepository;
    private KlineProviderPort $klineProvider;
    private SignalValidationService $signalValidationService;

    public function __construct(
        IndicatorContextBuilder $contextBuilder,
        ConditionRegistry $conditionRegistry,
        TimeframeEvaluator $timeframeEvaluator,
        TradingConfigService $tradingConfigService,
        KlineDataService $klineDataService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
        KlineProviderPort $klineProvider,
        SignalValidationService $signalValidationService
    ) {
        $this->contextBuilder = $contextBuilder;
        $this->conditionRegistry = $conditionRegistry;
        $this->timeframeEvaluator = $timeframeEvaluator;
        $this->tradingConfigService = $tradingConfigService;
        $this->klineDataService = $klineDataService;
        $this->contractRepository = $contractRepository;
        $this->klineRepository = $klineRepository;
        $this->klineProvider = $klineProvider;
        $this->signalValidationService = $signalValidationService;
    }

    #[Route('/test', name: 'test', methods: ['GET'])]
    public function testPage(): Response
    {
        $tradingConfig = $this->tradingConfigService->getConfig();
        $availableTimeframes = $this->tradingConfigService->getTimeframes();

        // Créer un mapping des timeframes avec leurs règles de validation
        $timeframesWithRules = [];
        foreach ($availableTimeframes as $tf) {
            $rules = $this->tradingConfigService->getTimeframeValidationRules($tf);
            $timeframesWithRules[$tf] = [
                'label' => $this->getTimeframeLabel($tf),
                'rules' => $rules,
                'min_bars' => $rules['min_bars'] ?? 50
            ];
        }

        // Récupérer les contrats actifs depuis la base de données
        $activeContracts = $this->contractRepository->allActiveSymbolNames();

        return $this->render('indicators/test.html.twig', [
            'title' => 'Test des Indicateurs',
            'available_symbols' => $activeContracts,
            'available_timeframes' => $timeframesWithRules,
            'trading_config' => $tradingConfig,
            'example_klines' => $this->klineDataService->getExampleKlinesJson()
        ]);
    }

    #[Route('/evaluate', name: 'evaluate', methods: ['POST'])]
    public function evaluateIndicators(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid JSON',
                    'message' => json_last_error_msg()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation des paramètres
            $symbol = $data['symbol'] ?? 'BTCUSDT';
            $timeframe = $data['timeframe'] ?? '1h';
            $customData = $data['custom_data'] ?? null;
            $klinesJson = $data['klines_json'] ?? null;

            // Validation du timeframe avec trading.yml
            if (!$this->tradingConfigService->isTimeframeValid($timeframe)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid timeframe',
                    'message' => "Le timeframe '$timeframe' n'est pas configuré dans trading.yml",
                    'available_timeframes' => $this->tradingConfigService->getTimeframes()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Créer le contexte
            if ($klinesJson) {
                // Validation des klines JSON
                $validationErrors = $this->klineDataService->validateKlinesJson($klinesJson);
                if (!empty($validationErrors)) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Invalid klines data',
                        'message' => 'Erreurs de validation des klines',
                        'validation_errors' => $validationErrors
                    ], Response::HTTP_BAD_REQUEST);
                }

                // Vérifier si on a assez de données
                $minBars = $this->tradingConfigService->getMinBars($timeframe);
                if (count($klinesJson) < $minBars) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Insufficient data',
                        'message' => "Pas assez de données. Minimum requis: $minBars, fourni: " . count($klinesJson),
                        'min_required' => $minBars,
                        'provided' => count($klinesJson)
                    ], Response::HTTP_BAD_REQUEST);
                }

                $context = $this->createContextFromKlinesJson($symbol, $timeframe, $klinesJson);
            } elseif ($customData) {
                $context = $this->createContextFromCustomData($symbol, $timeframe, $customData);
            } else {
                $context = $this->createRealisticContext($symbol, $timeframe);
            }

            // Évaluer toutes les conditions élémentaires
            $conditionResults = $this->conditionRegistry->evaluate($context);

            // Évaluer les règles spécifiques au timeframe via le moteur MTF
            $timeframeEvaluation = $this->timeframeEvaluator->evaluate($timeframe, $context);
            $timeframeValidation = $this->formatTimeframeEvaluation($timeframeEvaluation);

            $timeframeRules = $this->tradingConfigService->getTimeframeValidationRules($timeframe);

            // Générer un résumé
            $summary = $this->generateSummary($conditionResults);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'context' => $context,
                    'conditions_results' => $conditionResults,
                    'timeframe_validation' => $timeframeValidation,
                    'summary' => $summary,
                    'trading_config' => [
                        'timeframe' => $timeframe,
                        'rules' => $timeframeRules,
                        'min_bars' => $this->tradingConfigService->getMinBars($timeframe)
                    ],
                    'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Evaluation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/condition/{conditionName}', name: 'condition_detail', methods: ['GET'])]
    public function conditionDetail(string $conditionName): JsonResponse
    {
        try {
            // Créer un contexte de test
            $context = $this->createRealisticContext('BTCUSDT', '1h');

            // Évaluer la condition spécifique
            $results = $this->conditionRegistry->evaluate($context, [$conditionName]);

            if (!isset($results[$conditionName])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Condition not found',
                    'message' => "La condition '$conditionName' n'existe pas"
                ], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'condition_name' => $conditionName,
                    'result' => $results[$conditionName],
                    'context' => $context,
                    'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Condition evaluation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/available-conditions', name: 'available_conditions', methods: ['GET'])]
    public function availableConditions(): JsonResponse
    {
        try {
            $conditionNames = $this->conditionRegistry->names();

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'conditions' => $conditionNames,
                    'count' => count($conditionNames),
                    'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to get conditions',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/available-contracts', name: 'available_contracts', methods: ['GET'])]
    public function availableContracts(): JsonResponse
    {
        try {
            $activeContracts = $this->contractRepository->allActiveSymbolNames();

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'contracts' => $activeContracts,
                    'count' => count($activeContracts),
                    'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to get contracts',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/replay', name: 'replay', methods: ['POST'])]
    public function replayTest(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid JSON',
                    'message' => json_last_error_msg()
                ], Response::HTTP_BAD_REQUEST);
            }

            $symbol = $data['symbol'] ?? 'BTCUSDT';
            $timeframe = $data['timeframe'] ?? '1h';
            $iterations = (int) ($data['iterations'] ?? 10);

            $results = [];

            for ($i = 0; $i < $iterations; $i++) {
                // Créer un contexte avec des données légèrement différentes
                    $context = $this->createRealisticContext($symbol, $timeframe, $i);
                    $conditionsResults = $this->conditionRegistry->evaluate($context);
                    $timeframeEvaluation = $this->timeframeEvaluator->evaluate($timeframe, $context);
                    $timeframeValidation = $this->formatTimeframeEvaluation($timeframeEvaluation);
                    $summary = $this->generateSummary($conditionsResults);

                    $results[] = [
                        'iteration' => $i + 1,
                        'summary' => $summary,
                        'timeframe_validation' => $timeframeValidation,
                        'conditions_results' => $conditionsResults,
                        'context' => $context
                    ];
            }

            // Analyser la stabilité des résultats
            $stability = $this->analyzeStability($results);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'results' => $results,
                    'stability' => $stability,
                    'iterations' => $iterations,
                    'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Replay test failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/validate-cascade', name: 'validate_cascade', methods: ['POST'])]
    public function validateContractCascade(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid JSON',
                    'message' => 'Données JSON invalides'
                ], Response::HTTP_BAD_REQUEST);
            }

            $date = $data['date'] ?? null;
            $contract = $data['contract'] ?? null;

            if (!$date || !$contract) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing parameters',
                    'message' => 'Paramètres manquants: date et contract requis'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation de la date
            try {
                $targetDate = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid date',
                    'message' => 'Format de date invalide. Utilisez YYYY-MM-DD'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Vérifier que la date n'est pas dans le futur
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($targetDate > $now) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Future date',
                    'message' => 'La date ne peut pas être dans le futur'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation du contrat
            $availableSymbols = $this->contractRepository->allActiveSymbolNames();
            if (!in_array($contract, $availableSymbols)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid contract',
                    'message' => "Contrat invalide: $contract",
                    'available_contracts' => $availableSymbols
                ], Response::HTTP_BAD_REQUEST);
            }

            // Récupérer le contrat depuis la base de données
            $contractEntity = $this->contractRepository->findOneBy(['symbol' => $contract]);
            if (!$contractEntity) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Contract not found',
                    'message' => "Contrat $contract non trouvé en base de données"
                ], Response::HTTP_BAD_REQUEST);
            }

            // Timeframes en cascade (du plus long au plus court)
            $timeframes = ['4h', '1h', '15m', '5m', '1m'];
            $results = [];
            $overallStatus = 'valid';
            $knownSignals = []; // Signaux connus pour le contexte MTF

            foreach ($timeframes as $timeframe) {
                try {
                    // Récupérer les klines pour ce timeframe
                    $timeframeEnum = match($timeframe) {
                        '1m' => Timeframe::TF_1M, '5m' => Timeframe::TF_5M, '15m' => Timeframe::TF_15M,
                        '1h' => Timeframe::TF_1H, '4h' => Timeframe::TF_4H,
                        default => throw new \InvalidArgumentException("Invalid timeframe: $timeframe")
                    };

                    $tradingConfig = $this->tradingConfigService->getConfig();
                    $minBars = $tradingConfig['timeframes'][$timeframe]['guards']['min_bars'] ?? 220;

                    $intervalMinutes = $timeframeEnum->getStepInMinutes();
                    $startDate = $targetDate->sub(new \DateInterval('PT' . ($minBars * $intervalMinutes) . 'M'));

                    $existingKlines = $this->klineRepository->findBySymbolAndTimeframe($contract, $timeframeEnum, $minBars);
                    usort($existingKlines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());

                    $this->fillMissingKlines($contract, $timeframeEnum, $existingKlines, $startDate, $targetDate);

                    $klines = $this->klineRepository->findBySymbolAndTimeframe($contract, $timeframeEnum, $minBars);
                    usort($klines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());

                    // Utiliser SignalValidationService pour la validation
                    $validationResult = $this->signalValidationService->validate($timeframe, $klines, $knownSignals, $contractEntity);

                    // Extraire les informations des klines utilisées
                    $klinesInfo = [
                        'count' => count($klines),
                        'ids' => array_map(fn($k) => $k->getId(), $klines),
                        'timestamps' => array_map(fn($k) => $k->getOpenTime()->format('Y-m-d H:i:s'), $klines),
                        'date_range' => [
                            'from' => !empty($klines) ? $klines[0]->getOpenTime()->format('Y-m-d H:i:s') : null,
                            'to' => !empty($klines) ? end($klines)->getOpenTime()->format('Y-m-d H:i:s') : null
                        ]
                    ];

                    // Mettre à jour les signaux connus pour le contexte MTF
                    $knownSignals[$timeframe] = $validationResult['signals'][$timeframe] ?? [];

                    $results[$timeframe] = [
                        'status' => $validationResult['status'],
                        'signal' => $validationResult['final']['signal'],
                        'validation_result' => $validationResult,
                        'klines_used' => $klinesInfo,
                        'context_summary' => [
                            'context_aligned' => $validationResult['context']['aligned'] ?? false,
                            'context_dir' => $validationResult['context']['dir'] ?? 'NONE',
                            'context_signals' => $validationResult['context']['signals'] ?? [],
                            'fully_populated' => $validationResult['context']['fully_populated'] ?? false,
                            'fully_aligned' => $validationResult['context']['fully_aligned'] ?? false,
                        ]
                    ];

                    // Mettre à jour le statut global basé sur le statut MTF
                    if ($validationResult['status'] === 'FAILED' && $overallStatus === 'valid') {
                        $overallStatus = 'partial';
                    } elseif ($validationResult['status'] === 'VALIDATED') {
                        $overallStatus = 'valid';
                    }

                } catch (\Exception $e) {
                    $results[$timeframe] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                        'signal' => 'NONE',
                        'validation_result' => null,
                        'klines_used' => null,
                        'context_summary' => []
                    ];
                    $overallStatus = 'error';
                }
            }

            // Calculer les statistiques globales
            $validatedTimeframes = count(array_filter($results, fn($r) => $r['status'] === 'VALIDATED'));
            $pendingTimeframes = count(array_filter($results, fn($r) => $r['status'] === 'PENDING'));
            $failedTimeframes = count(array_filter($results, fn($r) => $r['status'] === 'FAILED'));
            $errorTimeframes = count(array_filter($results, fn($r) => $r['status'] === 'error'));

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'contract' => $contract,
                    'date' => $targetDate->format('Y-m-d H:i:s'),
                    'overall_status' => $overallStatus,
                    'timeframes_results' => $results,
                    'summary' => [
                        'total_timeframes' => count($timeframes),
                        'validated_timeframes' => $validatedTimeframes,
                        'pending_timeframes' => $pendingTimeframes,
                        'failed_timeframes' => $failedTimeframes,
                        'error_timeframes' => $errorTimeframes,
                        'validation_rate' => count($timeframes) > 0 ? round(($validatedTimeframes / count($timeframes)) * 100, 2) : 0
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server error',
                'message' => 'Erreur serveur: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/revalidate', name: 'revalidate', methods: ['POST'])]
    public function revalidateContracts(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid JSON',
                    'message' => json_last_error_msg()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation des paramètres
            $date = $data['date'] ?? null;
            $contracts = $data['contracts'] ?? null;
            $timeframe = $data['timeframe'] ?? '1h';

            if (!$date) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing date parameter',
                    'message' => 'Le paramètre "date" est requis (format: YYYY-MM-DD)'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!$contracts) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing contracts parameter',
                    'message' => 'Le paramètre "contracts" est requis (liste séparée par des virgules)'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation du format de date
            try {
                $targetDate = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid date format',
                    'message' => 'Format de date invalide. Utilisez YYYY-MM-DD'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Vérifier que la date n'est pas dans le futur
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($targetDate > $now) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Future date not allowed',
                    'message' => 'La date de revalidation ne peut pas être dans le futur'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation du timeframe
            if (!$this->tradingConfigService->isTimeframeValid($timeframe)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid timeframe',
                    'message' => "Le timeframe '$timeframe' n'est pas configuré dans trading.yml",
                    'available_timeframes' => $this->tradingConfigService->getTimeframes()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Parse des contrats
            $contractList = array_map('trim', explode(',', $contracts));
            $contractList = array_filter($contractList); // Supprimer les entrées vides

            if (empty($contractList)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'No valid contracts',
                    'message' => 'Aucun contrat valide fourni'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation des contrats contre la vraie liste des contrats actifs
            $availableSymbols = $this->contractRepository->allActiveSymbolNames();
            $invalidContracts = array_diff($contractList, $availableSymbols);

            if (!empty($invalidContracts)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid contracts',
                    'message' => 'Contrats invalides: ' . implode(', ', $invalidContracts),
                    'available_contracts' => $availableSymbols
                ], Response::HTTP_BAD_REQUEST);
            }

            // Revalidation des contrats
            $results = [];
            $totalContracts = count($contractList);
            $successfulValidations = 0;
            $failedValidations = 0;

            foreach ($contractList as $contract) {
                try {
                    // Créer un contexte historique pour la date donnée
                    $context = $this->createHistoricalContext($contract, $timeframe, $targetDate);

                    // Évaluer toutes les conditions
                    $conditionsResults = $this->conditionRegistry->evaluate($context);
                    $timeframeEvaluation = $this->timeframeEvaluator->evaluate($timeframe, $context);
                    $timeframeValidation = $this->formatTimeframeEvaluation($timeframeEvaluation);

                    // Générer un résumé
                    $summary = $this->generateSummary($conditionsResults);

                    // Déterminer le statut global
                    $overallStatus = $this->determineOverallStatus($timeframeValidation, $summary);

                    $results[$contract] = [
                        'contract' => $contract,
                        'date' => $targetDate->format('Y-m-d'),
                        'timeframe' => $timeframe,
                        'status' => $overallStatus,
                        'summary' => $summary,
                        'timeframe_validation' => $timeframeValidation,
                        'conditions_results' => $conditionsResults,
                        'context' => $context,
                        'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                    ];

                    if ($overallStatus === 'valid') {
                        $successfulValidations++;
                    } else {
                        $failedValidations++;
                    }

                } catch (\Exception $e) {
                    $results[$contract] = [
                        'contract' => $contract,
                        'date' => $targetDate->format('Y-m-d'),
                        'timeframe' => $timeframe,
                        'status' => 'error',
                        'error' => $e->getMessage(),
                        'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                    ];
                    $failedValidations++;
                }
            }

            // Générer un résumé global
            $globalSummary = [
                'total_contracts' => $totalContracts,
                'successful_validations' => $successfulValidations,
                'failed_validations' => $failedValidations,
                'success_rate' => $totalContracts > 0 ? round(($successfulValidations / $totalContracts) * 100, 2) : 0,
                'date' => $targetDate->format('Y-m-d'),
                'timeframe' => $timeframe
            ];

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'global_summary' => $globalSummary,
                    'contracts_results' => $results,
                    'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Revalidation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function createRealisticContext(string $symbol, string $timeframe, int $variation = 0): array
    {
        $basePrice = 50000 + ($variation * 100);

        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes([
                $basePrice, $basePrice + 100, $basePrice + 200, $basePrice + 300, $basePrice + 400,
                $basePrice + 500, $basePrice + 600, $basePrice + 700, $basePrice + 800, $basePrice + 900,
                $basePrice + 1000, $basePrice + 1100, $basePrice + 1200, $basePrice + 1300, $basePrice + 1400,
                $basePrice + 1500, $basePrice + 1600, $basePrice + 1700, $basePrice + 1800, $basePrice + 1900,
                $basePrice + 2000, $basePrice + 2100, $basePrice + 2200, $basePrice + 2300, $basePrice + 2400,
                $basePrice + 2500, $basePrice + 2600, $basePrice + 2700, $basePrice + 2800, $basePrice + 2900,
                $basePrice + 3000, $basePrice + 3100, $basePrice + 3200, $basePrice + 3300, $basePrice + 3400,
                $basePrice + 3500, $basePrice + 3600, $basePrice + 3700, $basePrice + 3800, $basePrice + 3900,
                $basePrice + 4000, $basePrice + 4100, $basePrice + 4200, $basePrice + 4300, $basePrice + 4400,
                $basePrice + 4500, $basePrice + 4600, $basePrice + 4700, $basePrice + 4800, $basePrice + 4900,
                $basePrice + 5000, $basePrice + 5100, $basePrice + 5200, $basePrice + 5300, $basePrice + 5400,
                $basePrice + 5500, $basePrice + 5600, $basePrice + 5700, $basePrice + 5800, $basePrice + 5900,
                $basePrice + 6000, $basePrice + 6100, $basePrice + 6200, $basePrice + 6300, $basePrice + 6400,
                $basePrice + 6500, $basePrice + 6600, $basePrice + 6700, $basePrice + 6800, $basePrice + 6900,
                $basePrice + 7000, $basePrice + 7100, $basePrice + 7200, $basePrice + 7300, $basePrice + 7400,
                $basePrice + 7500, $basePrice + 7600, $basePrice + 7700, $basePrice + 7800, $basePrice + 7900,
                $basePrice + 8000, $basePrice + 8100, $basePrice + 8200, $basePrice + 8300, $basePrice + 8400,
                $basePrice + 8500, $basePrice + 8600, $basePrice + 8700, $basePrice + 8800, $basePrice + 8900,
                $basePrice + 9000, $basePrice + 9100, $basePrice + 9200, $basePrice + 9300, $basePrice + 9400,
                $basePrice + 9500, $basePrice + 9600, $basePrice + 9700, $basePrice + 9800, $basePrice + 9900
            ])
            ->highs([
                $basePrice + 100, $basePrice + 200, $basePrice + 300, $basePrice + 400, $basePrice + 500,
                $basePrice + 600, $basePrice + 700, $basePrice + 800, $basePrice + 900, $basePrice + 1000,
                $basePrice + 1100, $basePrice + 1200, $basePrice + 1300, $basePrice + 1400, $basePrice + 1500,
                $basePrice + 1600, $basePrice + 1700, $basePrice + 1800, $basePrice + 1900, $basePrice + 2000,
                $basePrice + 2100, $basePrice + 2200, $basePrice + 2300, $basePrice + 2400, $basePrice + 2500,
                $basePrice + 2600, $basePrice + 2700, $basePrice + 2800, $basePrice + 2900, $basePrice + 3000,
                $basePrice + 3100, $basePrice + 3200, $basePrice + 3300, $basePrice + 3400, $basePrice + 3500,
                $basePrice + 3600, $basePrice + 3700, $basePrice + 3800, $basePrice + 3900, $basePrice + 4000,
                $basePrice + 4100, $basePrice + 4200, $basePrice + 4300, $basePrice + 4400, $basePrice + 4500,
                $basePrice + 4600, $basePrice + 4700, $basePrice + 4800, $basePrice + 4900, $basePrice + 5000,
                $basePrice + 5100, $basePrice + 5200, $basePrice + 5300, $basePrice + 5400, $basePrice + 5500,
                $basePrice + 5600, $basePrice + 5700, $basePrice + 5800, $basePrice + 5900, $basePrice + 6000,
                $basePrice + 6100, $basePrice + 6200, $basePrice + 6300, $basePrice + 6400, $basePrice + 6500,
                $basePrice + 6600, $basePrice + 6700, $basePrice + 6800, $basePrice + 6900, $basePrice + 7000,
                $basePrice + 7100, $basePrice + 7200, $basePrice + 7300, $basePrice + 7400, $basePrice + 7500,
                $basePrice + 7600, $basePrice + 7700, $basePrice + 7800, $basePrice + 7900, $basePrice + 8000,
                $basePrice + 8100, $basePrice + 8200, $basePrice + 8300, $basePrice + 8400, $basePrice + 8500,
                $basePrice + 8600, $basePrice + 8700, $basePrice + 8800, $basePrice + 8900, $basePrice + 9000,
                $basePrice + 9100, $basePrice + 9200, $basePrice + 9300, $basePrice + 9400, $basePrice + 9500,
                $basePrice + 9600, $basePrice + 9700, $basePrice + 9800, $basePrice + 9900, $basePrice + 10000
            ])
            ->lows([
                $basePrice - 100, $basePrice, $basePrice + 100, $basePrice + 200, $basePrice + 300,
                $basePrice + 400, $basePrice + 500, $basePrice + 600, $basePrice + 700, $basePrice + 800,
                $basePrice + 900, $basePrice + 1000, $basePrice + 1100, $basePrice + 1200, $basePrice + 1300,
                $basePrice + 1400, $basePrice + 1500, $basePrice + 1600, $basePrice + 1700, $basePrice + 1800,
                $basePrice + 1900, $basePrice + 2000, $basePrice + 2100, $basePrice + 2200, $basePrice + 2300,
                $basePrice + 2400, $basePrice + 2500, $basePrice + 2600, $basePrice + 2700, $basePrice + 2800,
                $basePrice + 2900, $basePrice + 3000, $basePrice + 3100, $basePrice + 3200, $basePrice + 3300,
                $basePrice + 3400, $basePrice + 3500, $basePrice + 3600, $basePrice + 3700, $basePrice + 3800,
                $basePrice + 3900, $basePrice + 4000, $basePrice + 4100, $basePrice + 4200, $basePrice + 4300,
                $basePrice + 4400, $basePrice + 4500, $basePrice + 4600, $basePrice + 4700, $basePrice + 4800,
                $basePrice + 4900, $basePrice + 5000, $basePrice + 5100, $basePrice + 5200, $basePrice + 5300,
                $basePrice + 5400, $basePrice + 5500, $basePrice + 5600, $basePrice + 5700, $basePrice + 5800,
                $basePrice + 5900, $basePrice + 6000, $basePrice + 6100, $basePrice + 6200, $basePrice + 6300,
                $basePrice + 6400, $basePrice + 6500, $basePrice + 6600, $basePrice + 6700, $basePrice + 6800,
                $basePrice + 6900, $basePrice + 7000, $basePrice + 7100, $basePrice + 7200, $basePrice + 7300,
                $basePrice + 7400, $basePrice + 7500, $basePrice + 7600, $basePrice + 7700, $basePrice + 7800,
                $basePrice + 7900, $basePrice + 8000, $basePrice + 8100, $basePrice + 8200, $basePrice + 8300,
                $basePrice + 8400, $basePrice + 8500, $basePrice + 8600, $basePrice + 8700, $basePrice + 8800,
                $basePrice + 8900, $basePrice + 9000, $basePrice + 9100, $basePrice + 9200, $basePrice + 9300,
                $basePrice + 9400, $basePrice + 9500, $basePrice + 9600, $basePrice + 9700, $basePrice + 9800
            ])
            ->volumes([
                1000, 1100, 1200, 1300, 1400, 1500, 1600, 1700, 1800, 1900,
                2000, 2100, 2200, 2300, 2400, 2500, 2600, 2700, 2800, 2900,
                3000, 3100, 3200, 3300, 3400, 3500, 3600, 3700, 3800, 3900,
                4000, 4100, 4200, 4300, 4400, 4500, 4600, 4700, 4800, 4900,
                5000, 5100, 5200, 5300, 5400, 5500, 5600, 5700, 5800, 5900,
                6000, 6100, 6200, 6300, 6400, 6500, 6600, 6700, 6800, 6900,
                7000, 7100, 7200, 7300, 7400, 7500, 7600, 7700, 7800, 7900,
                8000, 8100, 8200, 8300, 8400, 8500, 8600, 8700, 8800, 8900,
                9000, 9100, 9200, 9300, 9400, 9500, 9600, 9700, 9800, 9900,
                10000, 10100, 10200, 10300, 10400, 10500, 10600, 10700, 10800, 10900
            ])
            ->withDefaults()
            ->build();
    }

    private function createContextFromCustomData(string $symbol, string $timeframe, array $customData): array
    {
        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($customData['closes'] ?? [])
            ->highs($customData['highs'] ?? [])
            ->lows($customData['lows'] ?? [])
            ->volumes($customData['volumes'] ?? [])
            ->withDefaults()
            ->build();
    }

    private function generateSummary(array $results): array
    {
        $total = count($results);
        $passed = 0;
        $failed = 0;
        $errors = 0;

        foreach ($results as $name => $result) {
            if (isset($result['meta']['error']) && $result['meta']['error']) {
                $errors++;
            } elseif ($result['passed']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'total_conditions' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'errors' => $errors,
            'success_rate' => $total > 0 ? round(($passed / $total) * 100, 2) : 0
        ];
    }

    private function analyzeStability(array $results): array
    {
        $successRates = array_map(fn($r) => $r['summary']['success_rate'], $results);
        $passedCounts = array_map(fn($r) => $r['summary']['passed'], $results);

        return [
            'success_rate_avg' => round(array_sum($successRates) / count($successRates), 2),
            'success_rate_min' => min($successRates),
            'success_rate_max' => max($successRates),
            'success_rate_std' => round($this->calculateStandardDeviation($successRates), 2),
            'passed_avg' => round(array_sum($passedCounts) / count($passedCounts), 2),
            'stability_score' => round(100 - $this->calculateStandardDeviation($successRates), 2)
        ];
    }

    private function calculateStandardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values);
        return sqrt($variance);
    }

    private function createContextFromKlinesJson(string $symbol, string $timeframe, array $klinesJson): array
    {
        $timeframeEnum = Timeframe::from($timeframe);
        $klines = $this->klineDataService->parseKlinesFromJson($klinesJson, $symbol, $timeframeEnum);
        $ohlcvData = $this->klineDataService->extractOhlcvData($klines);

        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($ohlcvData['closes'])
            ->highs($ohlcvData['highs'])
            ->lows($ohlcvData['lows'])
            ->volumes($ohlcvData['volumes'])
            ->withDefaults()
            ->build();
    }

    private function formatTimeframeEvaluation(array $evaluation): array
    {
        $longConditions = $evaluation['long']['conditions'] ?? [];
        $shortConditions = $evaluation['short']['conditions'] ?? [];

        $longPassed = array_keys(array_filter($longConditions, fn ($result) => ($result['passed'] ?? false) === true));
        $shortPassed = array_keys(array_filter($shortConditions, fn ($result) => ($result['passed'] ?? false) === true));

        return [
            'long' => [
                'required_conditions' => array_keys($longConditions),
                'passed_conditions' => $longPassed,
                'failed_conditions' => $evaluation['long']['failed'] ?? [],
                'all_passed' => $evaluation['passed']['long'] ?? false,
                'composite_evaluation' => $evaluation['long']['requirements'] ?? [],
            ],
            'short' => [
                'required_conditions' => array_keys($shortConditions),
                'passed_conditions' => $shortPassed,
                'failed_conditions' => $evaluation['short']['failed'] ?? [],
                'all_passed' => $evaluation['passed']['short'] ?? false,
                'composite_evaluation' => $evaluation['short']['requirements'] ?? [],
            ],
        ];
    }

    private function getTimeframeLabel(string $timeframe): string
    {
        $labels = [
            '1m' => '1 minute',
            '5m' => '5 minutes',
            '15m' => '15 minutes',
            '30m' => '30 minutes',
            '1h' => '1 heure',
            '4h' => '4 heures',
            '1d' => '1 jour'
        ];

        return $labels[$timeframe] ?? $timeframe;
    }

    private function createHistoricalContext(string $symbol, string $timeframe, \DateTimeImmutable $targetDate): array
    {
        // Convertir le timeframe string en enum
        $timeframeEnum = match($timeframe) {
            '1m' => Timeframe::TF_1M,
            '5m' => Timeframe::TF_5M,
            '15m' => Timeframe::TF_15M,
            '1h' => Timeframe::TF_1H,
            '4h' => Timeframe::TF_4H,
            default => throw new \InvalidArgumentException("Invalid timeframe: $timeframe")
        };

        // Récupérer le nombre minimum de bars requis depuis la configuration
        $tradingConfig = $this->tradingConfigService->getConfig();
        $minBars = $tradingConfig['timeframes'][$timeframe]['guards']['min_bars'] ?? 220;

        // Calculer la date de début pour récupérer suffisamment de klines
        $intervalMinutes = $timeframeEnum->getStepInMinutes();
        $startDate = $targetDate->sub(new \DateInterval('PT' . ($minBars * $intervalMinutes) . 'M'));

        // Récupérer les klines existantes depuis la base de données
        $existingKlines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframeEnum, $minBars);

        // Trier par ordre chronologique
        usort($existingKlines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());

        // Détecter et combler les trous dans les klines
        $this->fillMissingKlines($symbol, $timeframeEnum, $existingKlines, $startDate, $targetDate);

        // Recharger les klines après comblement
        $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframeEnum, $minBars);
        usort($klines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());

        // Si on n'a toujours pas assez de klines, utiliser des données simulées
        if (count($klines) < $minBars) {
            return $this->createSimulatedContext($symbol, $timeframe, $targetDate);
        }

        // Construire le contexte avec les vraies klines
        $closes = array_map(fn($k) => $k->getClosePriceFloat(), $klines);
        $highs = array_map(fn($k) => $k->getHighPriceFloat(), $klines);
        $lows = array_map(fn($k) => $k->getLowPriceFloat(), $klines);
        $volumes = array_map(fn($k) => $k->getVolumeFloat(), $klines);

        // Extraire les IDs et timestamps des klines pour le debug
        $klineIds = array_map(fn($k) => $k->getId(), $klines);
        $klineTimestamps = array_map(fn($k) => $k->getOpenTime()->format('Y-m-d H:i:s'), $klines);

        $context = $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes($volumes)
            ->withDefaults()
            ->build();

        // Ajouter les informations des klines utilisées
        $context['klines_used'] = [
            'count' => count($klines),
            'ids' => $klineIds,
            'timestamps' => $klineTimestamps,
            'date_range' => [
                'from' => !empty($klines) ? $klines[0]->getOpenTime()->format('Y-m-d H:i:s') : null,
                'to' => !empty($klines) ? end($klines)->getOpenTime()->format('Y-m-d H:i:s') : null
            ]
        ];

        return $context;
    }

    private function determineTimeframeStatus(array $conditionsResults): string
    {
        if (empty($conditionsResults)) {
            return 'error';
        }

        $passedCount = count(array_filter($conditionsResults, fn($c) => $c['passed']));
        $totalCount = count($conditionsResults);

        if ($passedCount === $totalCount) {
            return 'valid';
        } elseif ($passedCount === 0) {
            return 'invalid';
        } else {
            return 'partial';
        }
    }

    private function determineOverallStatus(array $timeframeValidation, array $summary): string
    {
        // Déterminer le statut global basé sur la validation du timeframe et le résumé
        if ($summary['errors'] > 0) {
            return 'error';
        }

        // Vérifier si au moins une direction (long ou short) est valide
        if (($timeframeValidation['long']['all_passed'] ?? false) ||
            ($timeframeValidation['short']['all_passed'] ?? false)) {
            return 'valid';
        }

        // Vérifier le taux de succès des conditions
        if ($summary['success_rate'] >= 70) {
            return 'partial';
        }

        return 'invalid';
    }

    private function fillMissingKlines(string $symbol, Timeframe $timeframe, array $existingKlines, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): void
    {
        try {
            // Utiliser la fonction PostgreSQL existante pour détecter les trous
            $missingChunks = $this->klineRepository->getMissingKlineChunks(
                $symbol,
                $timeframe->value,
                $startDate,
                $endDate,
                500 // max_per_request
            );

            if (empty($missingChunks)) {
                return; // Pas de trous détectés
            }

            // Récupérer les klines manquantes depuis BitMart
            $allNewKlines = [];
            foreach ($missingChunks as $chunk) {
                try {
                    $chunkStart = \DateTimeImmutable::createFromFormat('U', (string)$chunk['from']);
                    $chunkEnd = \DateTimeImmutable::createFromFormat('U', (string)$chunk['to']);

                    if ($chunkStart && $chunkEnd) {
                        $fetchedKlines = $this->klineProvider->fetchKlinesInWindow(
                            $symbol,
                            $timeframe,
                            $chunkStart,
                            $chunkEnd,
                            $chunk['step'] * 500 // Limite basée sur le step
                        );

                        if (!empty($fetchedKlines)) {
                            $allNewKlines = array_merge($allNewKlines, $fetchedKlines);
                        }
                    }
                } catch (\Exception $e) {
                    // Log l'erreur mais continue avec les autres chunks
                    error_log("Error fetching klines for $symbol {$timeframe->value} chunk {$chunk['from']}-{$chunk['to']}: " . $e->getMessage());
                }
            }

            // Sauvegarder les nouvelles klines si on en a récupéré
            if (!empty($allNewKlines)) {
                $this->klineRepository->saveKlines($allNewKlines);
            }

        } catch (\Exception $e) {
            // Log l'erreur mais ne pas faire échouer la revalidation
            error_log("Error in fillMissingKlines for $symbol {$timeframe->value}: " . $e->getMessage());
        }
    }

    private function createSimulatedContext(string $symbol, string $timeframe, \DateTimeImmutable $targetDate): array
    {
        // Fallback vers les données simulées si on ne peut pas récupérer les vraies klines
        $basePrice = $this->getHistoricalPriceForSymbol($symbol, $targetDate);

        // Utiliser l'heure UTC pour plus de précision dans la variation
        $hourVariation = (int) $targetDate->format('H'); // 0-23
        $dayVariation = (int) $targetDate->format('j'); // 1-31
        $monthVariation = (int) $targetDate->format('n'); // 1-12

        // Combiner les variations pour plus de réalisme
        $combinedVariation = $dayVariation + ($hourVariation * 0.1) + ($monthVariation * 0.01);

        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($this->generateHistoricalCloses($basePrice, $combinedVariation))
            ->highs($this->generateHistoricalHighs($basePrice, $combinedVariation))
            ->lows($this->generateHistoricalLows($basePrice, $combinedVariation))
            ->volumes($this->generateHistoricalVolumes($combinedVariation))
            ->withDefaults()
            ->build();
    }

    private function getHistoricalPriceForSymbol(string $symbol, \DateTimeImmutable $date): float
    {
        // Prix historiques approximatifs basés sur le symbole et la date
        $basePrices = [
            'BTCUSDT' => 45000,
            'ETHUSDT' => 3000,
            'ADAUSDT' => 0.5,
            'DOTUSDT' => 7,
            'LINKUSDT' => 15,
            'SOLUSDT' => 100,
            'MATICUSDT' => 0.8,
            'AVAXUSDT' => 25
        ];

        $basePrice = $basePrices[$symbol] ?? 100;

        // Ajuster le prix basé sur la date (simulation d'évolution temporelle)
        $daysSinceEpoch = $date->getTimestamp() / 86400;
        $variation = sin($daysSinceEpoch / 365) * 0.2; // Variation saisonnière de ±20%

        return $basePrice * (1 + $variation);
    }

    private function generateHistoricalCloses(float $basePrice, float $variation): array
    {
        $closes = [];
        for ($i = 0; $i < 100; $i++) {
            $closes[] = $basePrice + ($variation * $i * 0.1) + (sin($i / 10) * $basePrice * 0.05);
        }
        return $closes;
    }

    private function generateHistoricalHighs(float $basePrice, float $variation): array
    {
        $highs = [];
        for ($i = 0; $i < 100; $i++) {
            $highs[] = $basePrice + ($variation * $i * 0.1) + (sin($i / 10) * $basePrice * 0.05) + ($basePrice * 0.02);
        }
        return $highs;
    }

    private function generateHistoricalLows(float $basePrice, float $variation): array
    {
        $lows = [];
        for ($i = 0; $i < 100; $i++) {
            $lows[] = $basePrice + ($variation * $i * 0.1) + (sin($i / 10) * $basePrice * 0.05) - ($basePrice * 0.02);
        }
        return $lows;
    }

    private function generateHistoricalVolumes(float $variation): array
    {
        $volumes = [];
        for ($i = 0; $i < 100; $i++) {
            $volumes[] = 1000 + ($variation * $i) + (sin($i / 5) * 500);
        }
        return $volumes;
    }
}
