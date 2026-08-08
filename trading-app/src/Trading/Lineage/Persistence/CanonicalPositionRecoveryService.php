<?php

declare(strict_types=1);

namespace App\Trading\Lineage\Persistence;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\Entity\OrderIntent;
use App\Provider\Context\ExchangeContext;
use App\Trading\Lineage\LineageContextException;

final readonly class CanonicalPositionRecoveryService
{
    public function __construct(
        private FuturesOrderRecoverySource $orders,
        private FuturesOrderTradeRecoverySource $fills,
        private OrderIntentRecoverySource $intents,
    ) {}

    public function resolve(CanonicalPositionEvidence $evidence, ExchangeContext $exchangeContext): ?CanonicalPositionPredecessor
    {
        if (!$evidence->hasAnyIdentifier()) {
            return null;
        }

        $fill = $evidence->exchangeFillId !== null
            ? $this->fills->findOneByTradeId($evidence->exchangeFillId, $exchangeContext)
            : null;
        if ($evidence->exchangeFillId !== null && !$fill instanceof FuturesOrderTrade) {
            throw new LineageContextException('canonical_identity_missing:position_fill_predecessor');
        }
        if ($fill instanceof FuturesOrderTrade && $fill->lineageClassification() === 'incomplete') {
            throw new LineageContextException('canonical_identity_incomplete:position_fill_predecessor');
        }

        $byOrder = $evidence->exchangeOrderId !== null
            ? $this->orders->findOneByOrderId($evidence->exchangeOrderId, $exchangeContext)
            : null;
        $byClient = $evidence->clientOrderId !== null
            ? $this->orders->findOneByClientOrderId($evidence->clientOrderId, $exchangeContext)
            : null;
        if ($evidence->exchangeOrderId !== null && !$byOrder instanceof FuturesOrder) {
            throw new LineageContextException('canonical_identity_missing:position_order_predecessor');
        }
        if ($evidence->clientOrderId !== null && !$byClient instanceof FuturesOrder) {
            throw new LineageContextException('canonical_identity_missing:position_client_order_predecessor');
        }
        $fromFill = $fill?->getFuturesOrder();
        $candidates = array_values(array_filter([$byOrder, $byClient, $fromFill], static fn (mixed $value): bool => $value instanceof FuturesOrder));
        $order = $candidates[0] ?? null;
        foreach ($candidates as $candidate) {
            if ($candidate !== $order) {
                throw new LineageContextException('canonical_identity_mismatch:position_predecessor');
            }
        }
        if ($order instanceof FuturesOrder) {
            if ($evidence->exchangeOrderId !== null && $order->getOrderId() !== $evidence->exchangeOrderId) {
                throw new LineageContextException('canonical_identity_mismatch:exchange_order_id');
            }
            if ($evidence->clientOrderId !== null && $order->getClientOrderId() !== $evidence->clientOrderId) {
                throw new LineageContextException('canonical_identity_mismatch:client_order_id');
            }
            if ($fill instanceof FuturesOrderTrade && $fill->getTradeId() !== $evidence->exchangeFillId) {
                throw new LineageContextException('canonical_identity_mismatch:exchange_trade_id');
            }
        }

        $intent = $this->intent($evidence, $exchangeContext);
        $modernIntent = $intent instanceof OrderIntent && $intent->hasAnyCanonicalIdentity();
        if (!$order instanceof FuturesOrder) {
            if ($modernIntent || ($fill instanceof FuturesOrderTrade && $fill->lineageClassification() !== 'legacy')) {
                throw new LineageContextException('canonical_identity_missing:position_order_predecessor');
            }

            return null;
        }

        $classification = $order->lineageClassification();
        if ($classification === 'incomplete') {
            throw new LineageContextException('canonical_identity_incomplete:position_order_predecessor');
        }
        if ($classification === 'legacy') {
            if ($modernIntent || ($fill instanceof FuturesOrderTrade && $fill->lineageClassification() !== 'legacy')) {
                throw new LineageContextException('canonical_identity_missing:position_order_predecessor');
            }

            return null;
        }
        if ($intent instanceof OrderIntent && $order->getOrderIntent() !== $intent) {
            throw new LineageContextException('canonical_identity_mismatch:position_intent_predecessor');
        }
        if ($evidence->exchangePositionId === null || trim($evidence->exchangePositionId) === '') {
            throw new LineageContextException('canonical_identity_missing:exchange_position_id');
        }
        $context = $order->requireLineageContext();
        if ($fill instanceof FuturesOrderTrade && $fill->requireLineageContext()->toArray() !== $context->toArray()) {
            throw new LineageContextException('canonical_identity_mismatch:position_predecessor');
        }

        return new CanonicalPositionPredecessor($order, $fill, $evidence->exchangePositionId, $context);
    }

    private function intent(CanonicalPositionEvidence $evidence, ExchangeContext $context): ?OrderIntent
    {
        $orderMatches = $evidence->exchangeOrderId !== null
            ? $this->intents->findByOrderIdForRecovery($evidence->exchangeOrderId, $context)
            : [];
        if (count($orderMatches) > 1) {
            throw new LineageContextException('canonical_identity_mismatch:position_intent_predecessor');
        }
        $byOrder = $orderMatches[0] ?? null;
        $byClient = $evidence->clientOrderId !== null
            ? $this->intents->findOneByClientOrderId($evidence->clientOrderId, $context)
            : null;
        if ($byOrder instanceof OrderIntent && $byClient instanceof OrderIntent && $byOrder !== $byClient) {
            throw new LineageContextException('canonical_identity_mismatch:position_intent_predecessor');
        }

        $intent = $byOrder ?? $byClient;
        if ($intent instanceof OrderIntent && $intent->getClientOrderId() !== ($evidence->clientOrderId ?? $intent->getClientOrderId())) {
            throw new LineageContextException('canonical_identity_mismatch:position_intent_predecessor');
        }

        return $intent;
    }
}
