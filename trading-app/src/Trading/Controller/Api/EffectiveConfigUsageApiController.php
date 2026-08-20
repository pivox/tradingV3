<?php

declare(strict_types=1);

namespace App\Trading\Controller\Api;

use App\TradingCore\Config\Usage\EffectiveConfigUsageReadException;
use App\TradingCore\Config\Usage\EffectiveConfigUsageReadService;
use App\TradingCore\Config\Usage\EffectiveConfigUsageScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class EffectiveConfigUsageApiController extends AbstractController
{
    public function __construct(private readonly EffectiveConfigUsageReadService $readService)
    {
    }

    #[Route('/api/orchestration/runs/{run_id}/effective-config', name: 'api_orchestration_run_effective_config', methods: ['GET'])]
    public function run(string $run_id): JsonResponse
    {
        return $this->read(EffectiveConfigUsageScope::RUN, $run_id);
    }

    #[Route('/api/orchestration/sets/{set_id}/effective-config', name: 'api_orchestration_set_effective_config', methods: ['GET'])]
    public function set(string $set_id): JsonResponse
    {
        return $this->read(EffectiveConfigUsageScope::SET, $set_id);
    }

    #[Route('/api/trading/decisions/{decision_id}/effective-config', name: 'api_trading_decision_effective_config', methods: ['GET'])]
    public function decision(string $decision_id): JsonResponse
    {
        return $this->read(EffectiveConfigUsageScope::DECISION, $decision_id);
    }

    #[Route('/api/trades/{trade_id}/effective-config', name: 'api_trade_effective_config', methods: ['GET'])]
    public function trade(string $trade_id): JsonResponse
    {
        return $this->read(EffectiveConfigUsageScope::TRADE, $trade_id);
    }

    private function read(EffectiveConfigUsageScope $scope, string $identifier): JsonResponse
    {
        try {
            return $this->json($this->readService->read($scope, $identifier));
        } catch (EffectiveConfigUsageReadException $exception) {
            return $this->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus);
        }
    }
}
