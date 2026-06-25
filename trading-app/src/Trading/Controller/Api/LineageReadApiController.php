<?php

declare(strict_types=1);

namespace App\Trading\Controller\Api;

use App\Trading\Lineage\ReadModel\LineageReadCriteria;
use App\Trading\Lineage\ReadModel\LineageReadException;
use App\Trading\Lineage\ReadModel\LineageReadPage;
use App\Trading\Lineage\ReadModel\LineageReadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DATA-001 #189 — read-only persistent lineage navigation.
 *
 * This API never reconstructs relations by symbol-only or timestamp windows. Venue-scoped
 * identifiers require `exchange + market_type`; ambiguous exact identifiers return
 * `identifier_conflict` instead of choosing an arbitrary lineage.
 */
#[Route('/api/lineage/v1', name: 'api_lineage_v1_')]
final class LineageReadApiController extends AbstractController
{
    private const IDENTIFIER_PARAMS = [
        'orchestration_run_id',
        'correlation_run_id',
        'orchestration_set_id',
        'orchestration_dashboard_id',
        'internal_trade_id',
        'internal_position_id',
        'order_intent_id',
        'client_order_id',
        'exchange_order_id',
        'position_id',
    ];

    private const VENUE_REQUIRED = [
        'client_order_id' => true,
        'exchange_order_id' => true,
        'position_id' => true,
    ];

    public function __construct(
        private readonly LineageReadService $lineageReadService,
    ) {
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $criteria = $this->criteriaFromRequest($request);
        if ($criteria instanceof JsonResponse) {
            return $criteria;
        }

        return $this->pageResponse($criteria);
    }

    #[Route('/{internalTradeId}/events', name: 'events', methods: ['GET'])]
    public function events(string $internalTradeId, Request $request): JsonResponse
    {
        $criteria = LineageReadCriteria::forIdentifier(
            'internal_trade_id',
            $internalTradeId,
            1,
            0,
        );
        $eventLimit = $this->intQuery($request, 'limit', LineageReadCriteria::MAX_LIMIT);
        $eventOffset = $this->intQuery($request, 'offset', 0);

        try {
            $page = $this->lineageReadService->search($criteria, $eventLimit, $eventOffset);
        } catch (LineageReadException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->getCode());
        }

        if ($page->total === 0 || $page->items === []) {
            return $this->error('lineage_not_found', 'No lineage was found for the requested identifier.', Response::HTTP_NOT_FOUND);
        }

        $item = $page->items[0];

        return $this->json([
            'internal_trade_id' => $internalTradeId,
            'completeness_status' => $item['completeness_status'],
            'quality_flags' => $item['quality_flags'],
            'pagination' => $item['lifecycle_events_pagination'],
            'data' => $item['lifecycle_events'],
        ]);
    }

    #[Route('/{internalTradeId}', name: 'detail', methods: ['GET'])]
    public function detail(string $internalTradeId, Request $request): JsonResponse
    {
        $criteria = LineageReadCriteria::forIdentifier(
            'internal_trade_id',
            $internalTradeId,
            1,
            0,
        );

        try {
            $page = $this->lineageReadService->search($criteria);
        } catch (LineageReadException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->getCode());
        }

        if ($page->total === 0 || $page->items === []) {
            return $this->error('lineage_not_found', 'No lineage was found for the requested identifier.', Response::HTTP_NOT_FOUND);
        }

        return $this->json($page->items[0]);
    }

    private function pageResponse(LineageReadCriteria $criteria): JsonResponse
    {
        try {
            $page = $this->lineageReadService->search($criteria);
        } catch (LineageReadException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->getCode());
        }

        if ($page->total === 0) {
            return $this->error('lineage_not_found', 'No lineage was found for the requested identifier.', Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->withFilters($page, $criteria));
    }

    private function criteriaFromRequest(Request $request): LineageReadCriteria|JsonResponse
    {
        $present = [];
        foreach (self::IDENTIFIER_PARAMS as $param) {
            $value = trim((string) $request->query->get($param, ''));
            if ($value !== '') {
                $present[$param] = $value;
            }
        }

        if ($present === []) {
            return $this->error(
                'missing_identifier',
                'Provide exactly one lineage identifier query parameter.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (count($present) > 1) {
            return $this->error(
                'multiple_identifiers',
                'Provide only one lineage identifier query parameter per request.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $kind = (string) array_key_first($present);
        $value = $present[$kind];
        $limit = $this->intQuery($request, 'limit', LineageReadCriteria::MAX_LIMIT);
        $offset = $this->intQuery($request, 'offset', 0);

        if (isset(self::VENUE_REQUIRED[$kind])) {
            $exchange = trim((string) $request->query->get('exchange', ''));
            $marketType = trim((string) $request->query->get('market_type', ''));
            if ($exchange === '' || $marketType === '') {
                return $this->error(
                    'missing_venue',
                    'exchange and market_type are required for venue-scoped identifiers.',
                    Response::HTTP_BAD_REQUEST,
                );
            }

            return LineageReadCriteria::forVenueIdentifier($kind, $value, $exchange, $marketType, $limit, $offset);
        }

        return LineageReadCriteria::forIdentifier($kind, $value, $limit, $offset);
    }

    /**
     * @return array<string,mixed>
     */
    private function withFilters(LineageReadPage $page, LineageReadCriteria $criteria): array
    {
        $payload = $page->toArray();
        $payload['filters'] = [
            'identifier' => $criteria->kind,
            'value' => $criteria->value,
            'exchange' => $criteria->exchange,
            'market_type' => $criteria->marketType,
        ];

        return $payload;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($code === 'identifier_conflict') {
            $payload['completeness_status'] = 'identifier_conflict';
            $payload['quality_flags'] = ['identifier_conflict'];
        }

        return $this->json($payload, $status);
    }

    private function intQuery(Request $request, string $key, int $default): int
    {
        $raw = $request->query->get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        return (int) $raw;
    }
}
