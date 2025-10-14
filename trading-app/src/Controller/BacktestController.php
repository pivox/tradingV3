<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Common\Dto\BacktestRequestDto;
use App\Domain\Common\Dto\BacktestResultDto;
use App\Domain\Strategy\Service\StrategyBacktester;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/backtest', name: 'backtest_')]
class BacktestController extends AbstractController
{
    public function __construct(
        private StrategyBacktester $strategyBacktester,
        private ValidatorInterface $validator
    ) {
    }

    #[Route('/run', name: 'run', methods: ['POST'])]
    public function runBacktest(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON',
                    'message' => json_last_error_msg()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validation des données requises
            $requiredFields = ['symbol', 'timeframe', 'start_date', 'end_date'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    return new JsonResponse([
                        'error' => 'Missing required field',
                        'field' => $field
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Créer le DTO de requête
            $backtestRequest = BacktestRequestDto::fromArray($data);
            
            // Valider le DTO
            $errors = $this->validator->validate($backtestRequest);
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }
                return new JsonResponse([
                    'error' => 'Validation failed',
                    'messages' => $errorMessages
                ], Response::HTTP_BAD_REQUEST);
            }

            // Exécuter le backtest
            $result = $this->strategyBacktester->runBacktest($backtestRequest);

            return new JsonResponse([
                'success' => true,
                'data' => $result->toArray()
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Backtest failed',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/strategies', name: 'strategies', methods: ['GET'])]
    public function getAvailableStrategies(): JsonResponse
    {
        try {
            $strategies = $this->strategyBacktester->getAvailableStrategies();
            
            $strategyData = array_map(function ($strategy) {
                return [
                    'name' => $strategy->getName(),
                    'description' => $strategy->getDescription(),
                    'parameters' => $strategy->getParameters(),
                    'enabled' => $strategy->isEnabled()
                ];
            }, $strategies);

            return new JsonResponse([
                'success' => true,
                'data' => $strategyData
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to get strategies',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/validate', name: 'validate', methods: ['POST'])]
    public function validateBacktestRequest(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse([
                    'error' => 'Invalid JSON',
                    'message' => json_last_error_msg()
                ], Response::HTTP_BAD_REQUEST);
            }

            $backtestRequest = BacktestRequestDto::fromArray($data);
            $errors = $this->validator->validate($backtestRequest);
            
            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = [
                        'field' => $error->getPropertyPath(),
                        'message' => $error->getMessage()
                    ];
                }
                return new JsonResponse([
                    'valid' => false,
                    'errors' => $errorMessages
                ], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse([
                'valid' => true,
                'message' => 'Request is valid'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Validation failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/example', name: 'example', methods: ['GET'])]
    public function getExampleRequest(): JsonResponse
    {
        $example = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'start_date' => '2024-01-01 00:00:00',
            'end_date' => '2024-12-31 23:59:59',
            'strategies' => ['RSI Strategy', 'MACD Strategy'],
            'parameters' => [
                'RSI Strategy' => [
                    'period' => 14,
                    'oversold_threshold' => 30,
                    'overbought_threshold' => 70
                ],
                'MACD Strategy' => [
                    'fast_period' => 12,
                    'slow_period' => 26,
                    'signal_period' => 9
                ]
            ],
            'initial_capital' => 10000.0,
            'risk_per_trade' => 0.02,
            'include_commissions' => true,
            'commission_rate' => 0.001,
            'name' => 'BTC Backtest Example',
            'description' => 'Example backtest for BTCUSDT with RSI and MACD strategies'
        ];

        return new JsonResponse([
            'success' => true,
            'data' => $example
        ], Response::HTTP_OK);
    }
}


