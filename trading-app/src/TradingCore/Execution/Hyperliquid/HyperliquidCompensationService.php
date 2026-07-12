<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\HyperliquidSignedActionClientInterface;
use App\Exchange\Hyperliquid\HyperliquidSignedActionResult;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleStatus;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidNormalizedOrderLifecycleDto;
use App\Provider\Hyperliquid\HyperliquidIdentifierLifecycleLookupInterface;
use App\Provider\Hyperliquid\HyperliquidNonceManagerInterface;

final readonly class HyperliquidCompensationService
{
    private const MAX_RECONCILIATION_CYCLES = 3;
    private const RECONCILIATION_SLEEP_MILLISECONDS = 250;
    private const QUANTITY_EPSILON = 0.00000001;

    public function __construct(
        private HyperliquidIdentifierLifecycleLookupInterface $lifecycles,
        private HyperliquidSignedActionClientInterface $signedActions,
        private HyperliquidActionFactory $actions,
        private HyperliquidNonceManagerInterface $nonces,
        private HyperliquidKillSwitchTripInterface $killSwitch,
        private HyperliquidCompensationSleeperInterface $sleeper,
    ) {
    }

    public function compensate(HyperliquidCompensationContext $context): HyperliquidCompensationResult
    {
        $entry = $this->reconcile(
            $context,
            $this->entryIdentifiers($context),
            static fn (HyperliquidNormalizedOrderLifecycleDto $lifecycle): bool => !$lifecycle->requiresResync
                && $lifecycle->status !== HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
        );
        if (!$entry instanceof HyperliquidNormalizedOrderLifecycleDto) {
            return $this->unresolved($context, HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC, 0.0, null);
        }

        if ($entry->filledQuantity > $context->quantity + self::QUANTITY_EPSILON) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId);
        }

        return match ($entry->status) {
            HyperliquidLifecycleStatus::REJECTED => $this->result('entry_rejected', $context, $entry),
            HyperliquidLifecycleStatus::CANCELED => $entry->filledQuantity > self::QUANTITY_EPSILON
                ? $this->close($context, $entry, $entry->filledQuantity)
                : $this->result('entry_canceled', $context, $entry),
            HyperliquidLifecycleStatus::FILLED => $this->close($context, $entry, $entry->filledQuantity),
            HyperliquidLifecycleStatus::ACCEPTED,
            HyperliquidLifecycleStatus::OPEN,
            HyperliquidLifecycleStatus::PARTIALLY_FILLED => $this->cancelThenResolve($context, $entry),
            HyperliquidLifecycleStatus::FAILED,
            HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC => $this->unresolved(
                $context,
                $entry->status,
                $entry->filledQuantity,
                $entry->exchangeOrderId,
            ),
        };
    }

    private function cancelThenResolve(
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $entry,
    ): HyperliquidCompensationResult {
        $cancel = $this->actions->cancel($context->assetId, new CancelOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: $context->symbol,
            clientOrderId: $context->entryWireCloid,
        ));

        $submission = $this->submit($context, $cancel);
        if (!$submission instanceof HyperliquidSignedActionResult || $submission->outcome !== 'accepted') {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId);
        }

        $terminal = $this->reconcile(
            $context,
            $this->entryIdentifiers($context),
            static fn (HyperliquidNormalizedOrderLifecycleDto $lifecycle): bool => !$lifecycle->requiresResync
                && in_array($lifecycle->status, [
                    HyperliquidLifecycleStatus::CANCELED,
                    HyperliquidLifecycleStatus::FILLED,
                ], true),
        );
        if (!$terminal instanceof HyperliquidNormalizedOrderLifecycleDto) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId);
        }
        if ($terminal->filledQuantity > $context->quantity + self::QUANTITY_EPSILON) {
            return $this->unresolved($context, $terminal->status, $terminal->filledQuantity, $terminal->exchangeOrderId);
        }
        if ($terminal->filledQuantity > self::QUANTITY_EPSILON) {
            return $this->close($context, $terminal, $terminal->filledQuantity);
        }

        return $this->result('entry_canceled', $context, $terminal);
    }

    private function close(
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $entry,
        float $quantity,
    ): HyperliquidCompensationResult {
        if (!is_finite($quantity) || $quantity <= self::QUANTITY_EPSILON || $quantity > $context->quantity + self::QUANTITY_EPSILON) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId);
        }

        $request = new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: $context->symbol,
            side: $context->positionSide === ExchangePositionSide::LONG ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY,
            positionSide: $context->positionSide,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::IOC,
            quantity: $quantity,
            price: $context->emergencyCloseSlippageCapPrice,
            stopPrice: null,
            reduceOnly: true,
            postOnly: false,
            leverage: $context->leverage,
            marginMode: $context->marginMode,
            clientOrderId: $context->closeClientOrderId,
        );
        $submission = $this->submit($context, $this->actions->emergencyClose($context->assetId, $request));
        if (!$submission instanceof HyperliquidSignedActionResult || $submission->outcome !== 'accepted') {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId);
        }

        $closeOid = $this->exchangeOrderId($submission);
        $closeCloid = $this->actions->cloid($context->closeClientOrderId);
        $identifiers = $closeOid === null || $closeOid === $closeCloid ? [$closeCloid] : [$closeOid, $closeCloid];
        $confirmation = $this->reconcile(
            $context,
            $identifiers,
            static fn (HyperliquidNormalizedOrderLifecycleDto $lifecycle): bool => !$lifecycle->requiresResync
                && in_array($lifecycle->status, [
                    HyperliquidLifecycleStatus::FILLED,
                    HyperliquidLifecycleStatus::CANCELED,
                    HyperliquidLifecycleStatus::REJECTED,
                ], true),
        );
        if (!$confirmation instanceof HyperliquidNormalizedOrderLifecycleDto
            || $confirmation->status !== HyperliquidLifecycleStatus::FILLED
            || $confirmation->filledQuantity + self::QUANTITY_EPSILON < $quantity
        ) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId);
        }

        return new HyperliquidCompensationResult(
            outcome: 'exposure_closed',
            entryStatus: $entry->status,
            provenFilledQuantity: $entry->filledQuantity,
            closedQuantity: $quantity,
            entryExchangeOrderId: $entry->exchangeOrderId,
            closeExchangeOrderId: $confirmation->exchangeOrderId,
            correlationId: $context->correlationId,
        );
    }

    /**
     * @param list<string> $identifiers
     * @param callable(HyperliquidNormalizedOrderLifecycleDto): bool $accept
     */
    private function reconcile(
        HyperliquidCompensationContext $context,
        array $identifiers,
        callable $accept,
    ): ?HyperliquidNormalizedOrderLifecycleDto {
        for ($cycle = 0; $cycle < self::MAX_RECONCILIATION_CYCLES; ++$cycle) {
            foreach (array_slice(array_values(array_unique($identifiers)), 0, 2) as $identifier) {
                try {
                    $lifecycle = $this->lifecycles->lookup($context->accountAddress, $identifier);
                } catch (\Throwable) {
                    $lifecycle = null;
                }
                if ($lifecycle instanceof HyperliquidNormalizedOrderLifecycleDto && $accept($lifecycle)) {
                    return $lifecycle;
                }
            }
            if ($cycle + 1 < self::MAX_RECONCILIATION_CYCLES) {
                $this->sleeper->sleepMilliseconds(self::RECONCILIATION_SLEEP_MILLISECONDS);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $action */
    private function submit(HyperliquidCompensationContext $context, array $action): ?HyperliquidSignedActionResult
    {
        try {
            $nonce = $this->nonces->nextNonce($context->nonceScope);

            return $this->signedActions->submit($action, $nonce, $context->correlationId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function entryIdentifiers(HyperliquidCompensationContext $context): array
    {
        if ($context->entryExchangeOrderId === null || $context->entryExchangeOrderId === $context->entryWireCloid) {
            return [$context->entryWireCloid];
        }

        return [$context->entryExchangeOrderId, $context->entryWireCloid];
    }

    private function exchangeOrderId(HyperliquidSignedActionResult $result): ?string
    {
        foreach ($result->statuses as $status) {
            if (isset($status['oid']) && is_int($status['oid']) && $status['oid'] > 0) {
                return (string) $status['oid'];
            }
        }

        return null;
    }

    private function result(
        string $outcome,
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $entry,
    ): HyperliquidCompensationResult {
        return new HyperliquidCompensationResult(
            outcome: $outcome,
            entryStatus: $entry->status,
            provenFilledQuantity: $entry->filledQuantity,
            closedQuantity: 0.0,
            entryExchangeOrderId: $entry->exchangeOrderId,
            closeExchangeOrderId: null,
            correlationId: $context->correlationId,
        );
    }

    private function unresolved(
        HyperliquidCompensationContext $context,
        HyperliquidLifecycleStatus $entryStatus,
        float $provenFilledQuantity,
        ?string $entryExchangeOrderId,
    ): HyperliquidCompensationResult {
        $this->killSwitch->trip('hyperliquid_compensation_unconfirmed', $context->redactedAuditContext);

        return new HyperliquidCompensationResult(
            outcome: 'unknown_requires_resync',
            entryStatus: $entryStatus,
            provenFilledQuantity: max(0.0, is_finite($provenFilledQuantity) ? $provenFilledQuantity : 0.0),
            closedQuantity: 0.0,
            entryExchangeOrderId: $entryExchangeOrderId,
            closeExchangeOrderId: null,
            correlationId: $context->correlationId,
        );
    }
}
