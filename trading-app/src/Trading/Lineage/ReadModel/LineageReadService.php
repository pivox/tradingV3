<?php

declare(strict_types=1);

namespace App\Trading\Lineage\ReadModel;

use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use Symfony\Component\HttpFoundation\Response;

final readonly class LineageReadService
{
    private const EVENT_LIMIT = 100;

    public function __construct(
        private LineageReadStoreInterface $store,
    ) {
    }

    public function search(LineageReadCriteria $criteria, ?int $eventLimit = null, int $eventOffset = 0): LineageReadPage
    {
        $eventLimit = $this->boundedEventLimit($eventLimit);
        $eventOffset = max(0, $eventOffset);
        $total = $this->store->count($criteria);
        if ($criteria->isConflictSensitive() && $total > 1) {
            throw new LineageReadException(
                'identifier_conflict',
                'The identifier matches more than one lineage in the requested venue.',
                Response::HTTP_CONFLICT,
            );
        }

        $lineages = $this->store->find($criteria);
        if ($lineages === []) {
            if ($total > 0) {
                return new LineageReadPage(
                    items: [],
                    total: $total,
                    limit: $criteria->limit,
                    offset: $criteria->offset,
                );
            }

            $unmatchedEvents = $this->store->findUnmatchedEvents($criteria);
            $items = array_map(fn (TradeLifecycleEvent $event): array => $this->serializeUnmatchedEvent($event), $unmatchedEvents);

            return new LineageReadPage(
                items: $items,
                total: count($items),
                limit: $criteria->limit,
                offset: $criteria->offset,
            );
        }

        $items = [];
        foreach ($lineages as $lineage) {
            $events = $this->store->findEventsForLineage($lineage, $eventLimit, $eventOffset);
            $items[] = $this->serializeLineage($lineage, $events, $eventLimit, $eventOffset);
        }

        return new LineageReadPage(
            items: $items,
            total: $total,
            limit: $criteria->limit,
            offset: $criteria->offset,
        );
    }

    /**
     * @param TradeLifecycleEvent[] $events
     * @return array<string,mixed>
     */
    private function serializeLineage(TradeLineage $lineage, array $events, int $eventLimit, int $eventOffset): array
    {
        $qualityFlags = $this->qualityFlags($lineage);
        $status = $this->completenessStatus($qualityFlags);

        return [
            'completeness_status' => $status,
            'lineage_classification' => $this->lineageClassification($lineage),
            'quality_flags' => $qualityFlags,
            'lineage' => $this->lineagePayload($lineage),
            'order_intent' => $lineage->getOrderIntent() !== null ? $this->orderIntentPayload($lineage->getOrderIntent()) : null,
            'lifecycle_events' => array_map(fn (TradeLifecycleEvent $event): array => $this->eventPayload($event), $events),
            'lifecycle_events_pagination' => $this->eventPagination($lineage, $events, $eventLimit, $eventOffset),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeUnmatchedEvent(TradeLifecycleEvent $event): array
    {
        return [
            'completeness_status' => 'unmatched',
            'lineage_classification' => $this->eventLineageClassification($event),
            'quality_flags' => ['unmatched'],
            'lineage' => null,
            'order_intent' => null,
            'lifecycle_events' => [$this->eventPayload($event)],
            'lifecycle_events_pagination' => [
                'limit' => self::EVENT_LIMIT,
                'total' => 1,
                'has_more' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function qualityFlags(TradeLineage $lineage): array
    {
        $flags = [];

        if ($lineage->getOrigin() === 'legacy') {
            $flags[] = 'legacy';
        }

        if ($lineage->getOrderIntent() === null) {
            $flags[] = 'missing_order_intent';
        }

        if ($lineage->getExchangeOrderId() === null) {
            $flags[] = 'missing_exchange_order_id';
        }

        if ($lineage->getPositionId() === null && $lineage->getInternalPositionId() === null) {
            $flags[] = 'missing_position_id';
        }

        if (!$this->store->hasCloseEventForLineage($lineage)) {
            $flags[] = 'missing_close_event';
        }

        if (
            $lineage->getOrigin() !== 'legacy'
            && (
                $lineage->getOrchestrationRunId() === null
                || $lineage->getCorrelationRunId() === null
                || $lineage->getOrchestrationSetId() === null
                || $lineage->getOrchestrationDashboardId() === null
            )
        ) {
            $flags[] = 'partial';
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param list<string> $qualityFlags
     */
    private function completenessStatus(array $qualityFlags): string
    {
        foreach ([
            'legacy',
            'missing_order_intent',
            'missing_exchange_order_id',
            'missing_position_id',
            'missing_close_event',
            'partial',
        ] as $status) {
            if (in_array($status, $qualityFlags, true)) {
                return $status;
            }
        }

        return 'complete';
    }

    /**
     * @param TradeLifecycleEvent[] $events
     * @return array<string,mixed>
     */
    private function eventPagination(TradeLineage $lineage, array $events, int $eventLimit, int $eventOffset): array
    {
        $total = $this->store->countEventsForLineage($lineage);

        return [
            'limit' => $eventLimit,
            'offset' => $eventOffset,
            'total' => $total,
            'has_more' => $eventOffset + count($events) < $total,
        ];
    }

    private function boundedEventLimit(?int $limit): int
    {
        if ($limit === null) {
            return self::EVENT_LIMIT;
        }

        return min(self::EVENT_LIMIT, max(1, $limit));
    }

    /**
     * @return array<string,mixed>
     */
    private function lineagePayload(TradeLineage $lineage): array
    {
        return [
            'id' => $lineage->getId(),
            'internal_trade_id' => $lineage->getInternalTradeId(),
            'internal_position_id' => $lineage->getInternalPositionId(),
            'order_intent_id' => $lineage->getOrderIntent()?->getId(),
            'client_order_id' => $lineage->getClientOrderId(),
            'exchange_order_id' => $lineage->getExchangeOrderId(),
            'position_id' => $lineage->getPositionId(),
            'run_id' => $lineage->getRunId(),
            'correlation_run_id' => $lineage->getCorrelationRunId(),
            'orchestration_run_id' => $lineage->getOrchestrationRunId(),
            'orchestration_set_id' => $lineage->getOrchestrationSetId(),
            'orchestration_dashboard_id' => $lineage->getOrchestrationDashboardId(),
            'exchange' => $lineage->getExchange(),
            'market_type' => $lineage->getMarketType(),
            'symbol' => $lineage->getSymbol(),
            'side' => $lineage->getSide(),
            'profile' => $lineage->getProfile(),
            'origin' => $lineage->getOrigin(),
            'replay_of_run_id' => $lineage->getReplayOfRunId(),
            'replay_of_correlation_id' => $lineage->getReplayOfCorrelationId(),
            'attempt_number' => $lineage->getAttemptNumber(),
            'config_hash' => $lineage->getConfigHash(),
            'condition_catalog_hash' => $lineage->getConditionCatalogHash(),
            'mode_id' => $lineage->getModeId(),
            'mode_version' => $lineage->getModeVersion(),
            'setup_id' => $lineage->getSetupId(),
            'setup_version' => $lineage->getSetupVersion(),
            'decision_id' => $lineage->getDecisionId(),
            'decision_key' => $lineage->getDecisionKey(),
            'effective_config_reference' => $lineage->getEffectiveConfigReference(),
            'created_at' => $this->formatDate($lineage->getCreatedAt()),
            'updated_at' => $this->formatDate($lineage->getUpdatedAt()),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function orderIntentPayload(OrderIntent $intent): array
    {
        return [
            'id' => $intent->getId(),
            'exchange' => $intent->getExchange(),
            'market_type' => $intent->getMarketType(),
            'symbol' => $intent->getSymbol(),
            'side' => $intent->getSide(),
            'status' => $intent->getStatus(),
            'client_order_id' => $intent->getClientOrderId(),
            'order_id' => $intent->getOrderId(),
            'exchange_order_id' => $intent->getExchangeOrderId(),
            'internal_trade_id' => $intent->getInternalTradeId(),
            'internal_position_id' => $intent->getInternalPositionId(),
            'correlation_run_id' => $intent->getCorrelationRunId(),
            'orchestration_run_id' => $intent->getOrchestrationRunId(),
            'orchestration_set_id' => $intent->getOrchestrationSetId(),
            'orchestration_dashboard_id' => $intent->getOrchestrationDashboardId(),
            'origin' => $intent->getOrigin(),
            'mode_id' => $intent->getModeId(),
            'mode_version' => $intent->getModeVersion(),
            'setup_id' => $intent->getSetupId(),
            'setup_version' => $intent->getSetupVersion(),
            'config_hash' => $intent->getConfigHash(),
            'condition_catalog_hash' => $intent->getConditionCatalogHash(),
            'canonical_side' => $intent->getCanonicalSide(),
            'decision_id' => $intent->getDecisionId(),
            'decision_key' => $intent->getDecisionKey(),
            'intent_id' => $intent->getIntentId(),
            'effective_config_reference' => $intent->getEffectiveConfigReference(),
            'created_at' => $this->formatDate($intent->getCreatedAt()),
            'updated_at' => $this->formatDate($intent->getUpdatedAt()),
            'sent_at' => $this->formatDate($intent->getSentAt()),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function eventPayload(TradeLifecycleEvent $event): array
    {
        return [
            'id' => $event->getId(),
            'event_type' => $event->getEventType(),
            'symbol' => $event->getSymbol(),
            'run_id' => $event->getRunId(),
            'client_order_id' => $event->getClientOrderId(),
            'exchange_order_id' => $event->getOrderId(),
            'position_id' => $event->getPositionId(),
            'internal_trade_id' => $event->getInternalTradeId(),
            'internal_position_id' => $event->getInternalPositionId(),
            'correlation_run_id' => $event->getCorrelationRunId(),
            'orchestration_run_id' => $event->getOrchestrationRunId(),
            'orchestration_set_id' => $event->getOrchestrationSetId(),
            'orchestration_dashboard_id' => $event->getOrchestrationDashboardId(),
            'origin' => $event->getOrigin(),
            'exchange' => $event->getExchange(),
            'market_type' => $event->getMarketType(),
            'side' => $event->getSide(),
            'mode_id' => $event->getModeId(),
            'mode_version' => $event->getModeVersion(),
            'setup_id' => $event->getSetupId(),
            'setup_version' => $event->getSetupVersion(),
            'config_hash' => $event->getConfigHash(),
            'condition_catalog_hash' => $event->getConditionCatalogHash(),
            'decision_id' => $event->getDecisionId(),
            'decision_key' => $event->getDecisionKey(),
            'intent_id' => $event->getIntentId(),
            'trade_id' => $event->getTradeId(),
            'effective_config_reference' => $event->getEffectiveConfigReference(),
            'qty' => $event->getQty(),
            'price' => $event->getPrice(),
            'reason_code' => $event->getReasonCode(),
            'happened_at' => $this->formatDate($event->getHappenedAt()),
        ];
    }

    private function lineageClassification(TradeLineage $lineage): string
    {
        $values = [
            $lineage->getModeId(), $lineage->getModeVersion(), $lineage->getSetupId(),
            $lineage->getSetupVersion(), $lineage->getConfigHash(), $lineage->getConditionCatalogHash(),
            $lineage->getSide(), $lineage->getDecisionId(), $lineage->getDecisionKey(),
        ];
        $present = array_filter($values, static fn (?string $value): bool => $value !== null && trim($value) !== '');
        if ($present === []) {
            return 'legacy';
        }
        if (count($present) !== count($values)) {
            return 'incomplete';
        }

        $intent = $lineage->getOrderIntent();
        if ($intent === null || !$intent->hasCompleteCanonicalIdentity()) {
            return 'incomplete';
        }
        $checks = [
            [$lineage->getModeId(), $intent->getModeId()],
            [$lineage->getModeVersion(), $intent->getModeVersion()],
            [$lineage->getSetupId(), $intent->getSetupId()],
            [$lineage->getSetupVersion(), $intent->getSetupVersion()],
            [$lineage->getConfigHash(), $intent->getConfigHash()],
            [$lineage->getConditionCatalogHash(), $intent->getConditionCatalogHash()],
            [$lineage->getDecisionId(), $intent->getDecisionId()],
            [$lineage->getDecisionKey(), $intent->getDecisionKey()],
        ];
        foreach ($checks as [$left, $right]) {
            if ($left !== $right) {
                return 'incomplete';
            }
        }

        return 'canonical';
    }

    private function eventLineageClassification(TradeLifecycleEvent $event): string
    {
        $values = [
            $event->getModeId(), $event->getModeVersion(), $event->getSetupId(),
            $event->getSetupVersion(), $event->getConfigHash(), $event->getConditionCatalogHash(),
            $event->getSide(), $event->getDecisionId(), $event->getDecisionKey(),
        ];
        $present = array_filter($values, static fn (?string $value): bool => $value !== null && trim($value) !== '');

        return $present === [] ? 'legacy' : (count($present) === count($values) ? 'canonical' : 'incomplete');
    }

    private function formatDate(?\DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }
}
