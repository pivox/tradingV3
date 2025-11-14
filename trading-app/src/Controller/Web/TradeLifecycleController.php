<?php

namespace App\Controller\Web;

use App\Repository\TradeLifecycleEventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class TradeLifecycleController extends AbstractController
{
    public function __construct(
        private readonly TradeLifecycleEventRepository $tradeLifecycleEventRepository,
    ) {
    }

    #[Route('/trade-lifecycle', name: 'trade_lifecycle_index')]
    public function index(Request $request): Response
    {
        $rawFilters = [
            'symbol' => $request->query->get('symbol'),
            'eventType' => $request->query->get('eventType'),
            'side' => $request->query->get('side'),
            'runId' => $request->query->get('runId'),
            'orderId' => $request->query->get('orderId'),
            'clientOrderId' => $request->query->get('clientOrderId'),
            'positionId' => $request->query->get('positionId'),
            'planId' => $request->query->get('planId'),
            'timeframe' => $request->query->get('timeframe'),
            'configProfile' => $request->query->get('configProfile'),
            'configVersion' => $request->query->get('configVersion'),
            'exchange' => $request->query->get('exchange'),
            'accountId' => $request->query->get('accountId'),
            'reasonCode' => $request->query->get('reasonCode'),
        ];

        $limit = (int) $request->query->get('limit', 100);
        $limit = max(10, min($limit, 500));

        $criteria = $this->normalizeFilters($rawFilters);
        $events = $this->tradeLifecycleEventRepository->findRecentBy($criteria, $limit);

        return $this->render('TradeLifecycle/index.html.twig', [
            'events' => $events,
            'filters' => $rawFilters,
            'limit' => $limit,
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $criteria = [];

        $map = [
            'symbol' => static fn (string $value): string => strtoupper($value),
            'eventType' => static fn (string $value): string => strtolower($value),
            'side' => static fn (string $value): string => strtoupper($value),
            'runId' => static fn (string $value): string => $value,
            'orderId' => static fn (string $value): string => strtoupper($value),
            'clientOrderId' => static fn (string $value): string => strtoupper($value),
            'positionId' => static fn (string $value): string => $value,
            'planId' => static fn (string $value): string => $value,
            'timeframe' => static fn (string $value): string => strtolower($value),
            'configProfile' => static fn (string $value): string => $value,
            'configVersion' => static fn (string $value): string => $value,
            'exchange' => static fn (string $value): string => $value,
            'accountId' => static fn (string $value): string => $value,
            'reasonCode' => static fn (string $value): string => $value,
        ];

        foreach ($filters as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalizer = $map[$field] ?? static fn (string $innerValue): string => $innerValue;
            $criteria[$field] = $normalizer((string) $value);
        }

        return $criteria;
    }
}
