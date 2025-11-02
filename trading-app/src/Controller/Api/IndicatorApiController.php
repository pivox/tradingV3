<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Common\Enum\Timeframe;
use App\Contract\Indicator\IndicatorProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/indicators')]
final class IndicatorApiController extends AbstractController
{
    public function __construct(
        private readonly IndicatorProviderInterface $indicatorProvider,
    ) {
    }

    #[Route('/available', name: 'api_indicators_available', methods: ['GET'])]
    public function listAvailable(): JsonResponse
    {
        return $this->json([
            'indicators' => $this->indicatorProvider->listAvailableIndicators(),
        ]);
    }

    #[Route('/pivots', name: 'api_indicator_pivots', methods: ['GET'])]
    public function getPivots(Request $request): JsonResponse
    {
        $symbol = trim((string) $request->query->get('symbol', ''));
        $timeframe = trim((string) $request->query->get('timeframe', ''));

        if ($symbol === '' || $timeframe === '') {
            return $this->json(['error' => 'Les paramètres symbol et timeframe sont requis.'], 400);
        }

        try {
            $tfEnum = Timeframe::from($timeframe);
        } catch (\ValueError) {
            return $this->json(['error' => sprintf('Timeframe invalide: %s', $timeframe)], 400);
        }

        $dto = $this->indicatorProvider->getListPivot(symbol: $symbol, tf: $tfEnum->value);
        if ($dto === null) {
            return $this->json(['error' => 'Impossible de calculer les points pivots pour ce couple symbol/timeframe.'], 404);
        }

        $payload = $dto->toArray()['pivot_levels'] ?? null;
        if ($payload === null) {
            return $this->json(['error' => 'Aucun point pivot disponible pour cette requête.'], 404);
        }

        return $this->json([
            'symbol' => $symbol,
            'timeframe' => $tfEnum->value,
            'pivot_levels' => $payload,
        ]);
    }

    #[Route('/values', name: 'api_indicator_values', methods: ['GET'])]
    public function getIndicators(Request $request): JsonResponse
    {
        $symbol = trim((string) $request->query->get('symbol', ''));
        $timeframe = trim((string) $request->query->get('timeframe', ''));

        if ($symbol === '' || $timeframe === '') {
            return $this->json(['error' => 'Les paramètres symbol et timeframe sont requis.'], 400);
        }

        try {
            $tfEnum = Timeframe::from($timeframe);
        } catch (\ValueError) {
            return $this->json(['error' => sprintf('Timeframe invalide: %s', $timeframe)], 400);
        }

        $dto = $this->indicatorProvider->getListPivot(symbol: $symbol, tf: $tfEnum->value);
        if ($dto === null) {
            return $this->json(['error' => 'Impossible de récupérer les indicateurs pour ce couple symbol/timeframe.'], 404);
        }

        return $this->json([
            'symbol' => $symbol,
            'timeframe' => $tfEnum->value,
            'indicators' => $dto->toArray(),
            'descriptions' => $dto->getDescriptions(),
        ]);
    }

    #[Route('/atr', name: 'api_indicator_atr', methods: ['GET'])]
    public function getAtr(Request $request): JsonResponse
    {
        $symbol = trim((string) $request->query->get('symbol', ''));
        if ($symbol === '') {
            return $this->json(['error' => 'Le paramètre symbol est requis.'], 400);
        }

        $atr1m = $this->indicatorProvider->getAtr(symbol: $symbol, tf: Timeframe::TF_1M->value);
        $atr5m = $this->indicatorProvider->getAtr(symbol: $symbol, tf: Timeframe::TF_5M->value);

        $missing = [];
        if ($atr1m === null) {
            $missing[] = Timeframe::TF_1M->value;
        }
        if ($atr5m === null) {
            $missing[] = Timeframe::TF_5M->value;
        }

        $response = [
            'symbol' => $symbol,
            'atr' => [
                Timeframe::TF_1M->value => $atr1m,
                Timeframe::TF_5M->value => $atr5m,
            ],
        ];

        if ($missing !== []) {
            $response['missing_timeframes'] = $missing;
        }

        return $this->json($response);
    }
}
