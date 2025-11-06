<?php

namespace App\Controller\Web;

use App\Common\Enum\Timeframe;
use App\Contract\Indicator\IndicatorMainProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\KlineDataService;
use App\Service\TradingConfigService;
use App\Config\SignalConfig;
use App\Contract\Signal\SignalValidationServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/indicators', name: 'indicators_')]
class IndicatorTestController extends AbstractController
{
    private IndicatorMainProviderInterface $indicatorMain;
    private TradingConfigService $tradingConfigService;
    private SignalConfig $signalConfig;
    private KlineDataService $klineDataService;
    private ContractRepository $contractRepository;
    private KlineRepository $klineRepository;
    private MainProviderInterface $provider;
    private SignalValidationServiceInterface $signalValidationService;

    public function __construct(
        IndicatorMainProviderInterface $indicatorMain,
        TradingConfigService $tradingConfigService,
        SignalConfig $signalConfig,
        KlineDataService $klineDataService,
        ContractRepository $contractRepository,
        KlineRepository $klineRepository,
        MainProviderInterface $provider,
        SignalValidationServiceInterface $signalValidationService
    ) {
        $this->indicatorMain = $indicatorMain;
        $this->tradingConfigService = $tradingConfigService;
        $this->signalConfig = $signalConfig;
        $this->klineDataService = $klineDataService;
        $this->contractRepository = $contractRepository;
        $this->klineRepository = $klineRepository;
        $this->provider = $provider;
        $this->signalValidationService = $signalValidationService;
    }

    #[Route('/test', name: 'test', methods: ['GET'])]
    public function testPage(): Response
    {
        $tradingConfig = $this->tradingConfigService->getConfig();
        $availableTimeframes = $this->signalConfig->getTimeframes();

        // Créer un mapping des timeframes avec leurs règles de validation
        $timeframesWithRules = [];
        foreach ($availableTimeframes as $tf) {
            $minBars = $this->signalConfig->getMinBars($tf);
            $timeframesWithRules[$tf] = [
                'label' => $this->getTimeframeLabel($tf),
                'rules' => [],
                'min_bars' => $minBars
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

            // Validation du timeframe avec signal.yaml
            if (!$this->signalConfig->isTimeframeValid($timeframe)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid timeframe',
                    'message' => "Le timeframe '$timeframe' n'est pas configuré dans signal.yaml",
                    'available_timeframes' => $this->signalConfig->getTimeframes()
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

            $engine = $this->indicatorMain->getEngine();
            // Évaluer toutes les conditions élémentaires
            $conditionResults = $engine->evaluateAllConditions($context);

            // Évaluer les règles spécifiques au timeframe via le moteur MTF
            $timeframeEvaluation = $engine->evaluateYaml($timeframe, $context);
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
            $engine = $this->indicatorMain->getEngine();
            $results = $engine->evaluateConditions($context, [$conditionName]);

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
            $engine = $this->indicatorMain->getEngine();
            $conditionNames = $engine->listConditionNames();

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
                    $engine = $this->indicatorMain->getEngine();
                    $conditionsResults = $engine->evaluateAllConditions($context);
                    $timeframeEvaluation = $engine->evaluateYaml($timeframe, $context);
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
                    $validationResultDto = $this->signalValidationService->validate($timeframe, $klines, $knownSignals, $contractEntity);
                    $validationResult = $validationResultDto->toArray();

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
                    $lowerTf = strtolower($timeframe);
                    $knownSignals[$timeframe] = $validationResult['signals'][$lowerTf] ?? [];

                    $results[$timeframe] = [
                        'status' => $validationResultDto->status,
                        'signal' => $validationResultDto->finalSignalValue(),
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
                    if ($validationResultDto->status === 'FAILED' && $overallStatus === 'valid') {
                        $overallStatus = 'partial';
                    } elseif ($validationResultDto->status === 'VALIDATED') {
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
            if (!$this->signalConfig->isTimeframeValid($timeframe)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid timeframe',
                    'message' => "Le timeframe '$timeframe' n'est pas configuré dans signal.yaml",
                    'available_timeframes' => $this->signalConfig->getTimeframes()
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
                    $engine = $this->indicatorMain->getEngine();
                    $conditionsResults = $engine->evaluateAllConditions($context);
                    $timeframeEvaluation = $engine->evaluateYaml($timeframe, $context);
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
        $klines = [];
        $n = 100;
        for ($i = 0; $i < $n; $i++) {
            $close = $basePrice + ($i+1) * 100;
            $klines[] = [
                'open' => $close - 50,
                'high' => $close + 100,
                'low'  => $close - 100,
                'close'=> $close,
                'volume'=> 1000 + ($i*100),
                'open_time' => (new \DateTimeImmutable('-'.($n-$i).' minutes', new \DateTimeZone('UTC'))),
            ];
        }
        return $this->indicatorMain->getEngine()->buildContext($symbol, $timeframe, $klines);
    }

    private function createContextFromCustomData(string $symbol, string $timeframe, array $customData): array
    {
        $closes = $customData['closes'] ?? [];
        $highs  = $customData['highs'] ?? $closes;
        $lows   = $customData['lows'] ?? $closes;
        $vols   = $customData['volumes'] ?? array_fill(0, count($closes), 0.0);
        $n = min(count($closes), count($highs), count($lows), count($vols));
        $klines = [];
        for ($i=0; $i<$n; $i++) {
            $klines[] = [
                'open' => $closes[$i],
                'high' => $highs[$i],
                'low' => $lows[$i],
                'close' => $closes[$i],
                'volume' => $vols[$i],
                'open_time' => (new \DateTimeImmutable('-'.($n-$i).' minutes', new \DateTimeZone('UTC'))),
            ];
        }
        return $this->indicatorMain->getEngine()->buildContext($symbol, $timeframe, $klines);
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

        $klines = $this->klineDataService->parseKlinesFromJson($klinesJson, $symbol, $timeframeEnum);
        return $this->indicatorMain->getEngine()->buildContext($symbol, $timeframe, $klines);
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

        $engine = $this->indicatorMain->getEngine();
        $context = $engine->buildContext($symbol, $timeframe, $klines);

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
                        $fetchedKlines = $this->provider->getKlineProvider()->getKlinesInWindow(
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
