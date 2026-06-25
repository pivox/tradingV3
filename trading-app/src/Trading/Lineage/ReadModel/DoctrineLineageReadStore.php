<?php

declare(strict_types=1);

namespace App\Trading\Lineage\ReadModel;

use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Repository\OrderIntentRepository;
use App\Repository\TradeLifecycleEventRepository;
use App\Repository\TradeLineageRepository;

final readonly class DoctrineLineageReadStore implements LineageReadStoreInterface
{
    public function __construct(
        private TradeLineageRepository $lineages,
        private OrderIntentRepository $orderIntents,
        private TradeLifecycleEventRepository $events,
    ) {
    }

    public function count(LineageReadCriteria $criteria): int
    {
        if ($criteria->kind === 'order_intent_id') {
            return count($this->find($criteria));
        }

        return $this->lineages->countByReadCriteria($criteria);
    }

    public function find(LineageReadCriteria $criteria): array
    {
        if ($criteria->kind === 'order_intent_id') {
            $orderIntentId = (int) $criteria->value;
            $lineage = $this->lineages->findOneByOrderIntentId($orderIntentId);

            return $lineage instanceof TradeLineage ? [$lineage] : $this->lineagesFromOrderIntents($criteria);
        }

        return $this->lineages->findPageByReadCriteria($criteria);
    }

    public function findUnmatchedEvents(LineageReadCriteria $criteria): array
    {
        return $this->events->findUnmatchedByReadCriteria($criteria);
    }

    public function findEventsForLineage(TradeLineage $lineage, int $limit, int $offset = 0): array
    {
        return $this->events->findForLineageIdentifiers(
            internalTradeId: $lineage->getInternalTradeId(),
            clientOrderId: $lineage->getClientOrderId(),
            exchangeOrderId: $lineage->getExchangeOrderId(),
            positionId: $lineage->getPositionId(),
            exchange: $lineage->getExchange(),
            marketType: $lineage->getMarketType(),
            limit: $limit,
            offset: $offset,
        );
    }

    public function countEventsForLineage(TradeLineage $lineage): int
    {
        return $this->events->countForLineageIdentifiers(
            internalTradeId: $lineage->getInternalTradeId(),
            clientOrderId: $lineage->getClientOrderId(),
            exchangeOrderId: $lineage->getExchangeOrderId(),
            positionId: $lineage->getPositionId(),
            exchange: $lineage->getExchange(),
            marketType: $lineage->getMarketType(),
        );
    }

    public function hasCloseEventForLineage(TradeLineage $lineage): bool
    {
        return $this->events->hasCloseEventForLineageIdentifiers(
            internalTradeId: $lineage->getInternalTradeId(),
            clientOrderId: $lineage->getClientOrderId(),
            exchangeOrderId: $lineage->getExchangeOrderId(),
            positionId: $lineage->getPositionId(),
            exchange: $lineage->getExchange(),
            marketType: $lineage->getMarketType(),
        );
    }

    /**
     * @return TradeLineage[]
     */
    private function lineagesFromOrderIntents(LineageReadCriteria $criteria): array
    {
        $lineages = [];
        foreach ($this->orderIntents->findPageByReadCriteria($criteria) as $intent) {
            if (!$intent instanceof OrderIntent || $intent->getInternalTradeId() === null) {
                continue;
            }
            $lineage = $this->lineages->findOneByInternalTradeId($intent->getInternalTradeId());
            if ($lineage instanceof TradeLineage) {
                $lineages[] = $lineage;
            }
        }

        return $lineages;
    }
}
