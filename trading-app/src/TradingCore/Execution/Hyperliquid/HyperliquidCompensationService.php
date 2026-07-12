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
use App\Provider\Hyperliquid\HyperliquidIdentifierBindingException;
use App\Provider\Hyperliquid\HyperliquidNonceManagerInterface;

final readonly class HyperliquidCompensationService
{
    private const MAX_RECONCILIATION_CYCLES = 3;
    private const RECONCILIATION_SLEEP_MILLISECONDS = 250;
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
        $tripGuard = new HyperliquidCompensationTripGuard($this->killSwitch, $context->redactedAuditContext);

        try {
            return $this->doCompensate($context, $tripGuard);
        } catch (HyperliquidCompensationRuntimeFailure $failure) {
            return $this->unresolved(
                $context,
                HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
                0.0,
                $context->entryExchangeOrderId,
                $failure->reasonCode,
                $tripGuard,
            );
        } catch (\LogicException $exception) {
            $tripGuard->trip();

            throw $exception;
        }
    }

    private function doCompensate(
        HyperliquidCompensationContext $context,
        HyperliquidCompensationTripGuard $tripGuard,
    ): HyperliquidCompensationResult {
        try {
            if ($this->killSwitch->isTripped()) {
                return new HyperliquidCompensationResult(
                    outcome: 'unknown_requires_resync',
                    reasonCode: HyperliquidCompensationReasonCode::KILL_SWITCH_ALREADY_TRIPPED,
                    entryStatus: HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
                    expectedQuantity: $context->quantity,
                    provenFilledQuantity: 0.0,
                    closedQuantity: 0.0,
                    quantityPrecision: $context->quantityPrecision,
                    quantityStep: $context->quantityStep,
                    entryExchangeOrderId: $context->entryExchangeOrderId,
                    closeExchangeOrderId: null,
                    correlationId: $context->correlationId,
                );
            }
        } catch (\RuntimeException) {
            throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::KILL_SWITCH_STATE_UNAVAILABLE);
        }

        $entry = $this->reconcile(
            $context,
            $this->entryIdentifiers($context),
            $context->entryExchangeOrderId,
            $context->entryWireCloid,
            static fn (HyperliquidNormalizedOrderLifecycleDto $lifecycle): bool => !$lifecycle->requiresResync
                && $lifecycle->status !== HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
        );
        if (!$entry instanceof HyperliquidNormalizedOrderLifecycleDto) {
            return $this->unresolved($context, HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC, 0.0, null, HyperliquidCompensationReasonCode::ENTRY_RECONCILIATION_UNCONFIRMED, $tripGuard);
        }

        if (!$this->validEntryLifecycle($context, $entry)) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::ENTRY_LIFECYCLE_CONTRADICTORY, $tripGuard);
        }

        return match ($entry->status) {
            HyperliquidLifecycleStatus::REJECTED => $this->result('entry_rejected', $context, $entry),
            HyperliquidLifecycleStatus::CANCELED => $this->quantity($context, $entry->filledQuantity)->isPositive()
                ? $this->close($context, $entry, $this->quantity($context, $entry->filledQuantity), $tripGuard)
                : $this->result('entry_canceled', $context, $entry),
            HyperliquidLifecycleStatus::FILLED => $this->close($context, $entry, $this->quantity($context, $entry->filledQuantity), $tripGuard),
            HyperliquidLifecycleStatus::ACCEPTED,
            HyperliquidLifecycleStatus::OPEN,
            HyperliquidLifecycleStatus::PARTIALLY_FILLED => $this->cancelThenResolve($context, $entry, $tripGuard),
            HyperliquidLifecycleStatus::FAILED,
            HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC => $this->unresolved(
                $context,
                $entry->status,
                $entry->filledQuantity,
                $entry->exchangeOrderId,
                HyperliquidCompensationReasonCode::ENTRY_LIFECYCLE_CONTRADICTORY,
                $tripGuard,
            ),
        };
    }

    private function cancelThenResolve(
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $entry,
        HyperliquidCompensationTripGuard $tripGuard,
    ): HyperliquidCompensationResult {
        $cancel = $this->actions->cancel($context->assetId, new CancelOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: $context->symbol,
            clientOrderId: $context->entryWireCloid,
        ));

        $nonce = $this->nextNonce($context);
        try {
            $submission = $this->signedActions->submit($cancel, $nonce, $context->correlationId);
        } catch (\RuntimeException) {
            throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::CANCEL_SUBMISSION_UNCONFIRMED);
        }
        if (!$submission instanceof HyperliquidSignedActionResult || !$this->validAcceptedCancel($submission, $context)) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::CANCEL_SUBMISSION_UNCONFIRMED, $tripGuard);
        }

        $terminal = $this->reconcile(
            $context,
            $this->entryIdentifiers($context),
            $context->entryExchangeOrderId,
            $context->entryWireCloid,
            static fn (HyperliquidNormalizedOrderLifecycleDto $lifecycle): bool => !$lifecycle->requiresResync
                && in_array($lifecycle->status, [
                    HyperliquidLifecycleStatus::CANCELED,
                    HyperliquidLifecycleStatus::FILLED,
                ], true),
        );
        if (!$terminal instanceof HyperliquidNormalizedOrderLifecycleDto) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::CANCEL_CONFIRMATION_UNCONFIRMED, $tripGuard);
        }
        if (!$this->validEntryLifecycle($context, $terminal)) {
            return $this->unresolved($context, $terminal->status, $terminal->filledQuantity, $terminal->exchangeOrderId, HyperliquidCompensationReasonCode::ENTRY_LIFECYCLE_CONTRADICTORY, $tripGuard);
        }
        $filled = $this->quantity($context, $terminal->filledQuantity);
        if ($filled->isPositive()) {
            return $this->close($context, $terminal, $filled, $tripGuard);
        }

        return $this->result('entry_canceled', $context, $terminal);
    }

    private function close(
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $entry,
        HyperliquidQuantity $quantity,
        HyperliquidCompensationTripGuard $tripGuard,
    ): HyperliquidCompensationResult {
        if (!$quantity->isPositive() || $quantity->compareTo($context->canonicalQuantity) > 0) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::ENTRY_LIFECYCLE_CONTRADICTORY, $tripGuard);
        }

        $priorClose = $this->existingClose($context);
        if ($priorClose instanceof HyperliquidNormalizedOrderLifecycleDto) {
            if ($priorClose->status === HyperliquidLifecycleStatus::FILLED
                && $this->validCloseLifecycle($context, $priorClose, $quantity, null)
            ) {
                return $this->closedResult($context, $entry, $quantity, $priorClose);
            }

            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::CLOSE_PREEXISTING_UNCONFIRMED, $tripGuard);
        }

        $request = new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: $context->symbol,
            side: $context->positionSide === ExchangePositionSide::LONG ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY,
            positionSide: $context->positionSide,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::IOC,
            quantity: $quantity->toFloat(),
            price: $context->emergencyCloseSlippageCapPrice,
            stopPrice: null,
            reduceOnly: true,
            postOnly: false,
            leverage: $context->leverage,
            marginMode: $context->marginMode,
            clientOrderId: $context->closeClientOrderId,
        );
        $action = $this->actions->emergencyClose($context->assetId, $request);
        $nonce = $this->nextNonce($context);
        try {
            $submission = $this->signedActions->submit($action, $nonce, $context->correlationId);
        } catch (\RuntimeException) {
            return $this->resolveAmbiguousClose($context, $entry, $quantity, $tripGuard);
        }
        if ($submission->outcome === 'ambiguous') {
            return $this->resolveAmbiguousClose($context, $entry, $quantity, $tripGuard);
        }
        if ($submission->outcome !== 'accepted') {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::CLOSE_SUBMISSION_UNCONFIRMED, $tripGuard);
        }

        $closeOid = $this->acceptedCloseOid($submission, $context);
        if ($closeOid === null) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::CLOSE_SUBMISSION_UNCONFIRMED, $tripGuard);
        }

        $closeCloid = $this->actions->cloid($context->closeClientOrderId);
        $identifiers = $closeOid === $closeCloid ? [$closeCloid] : [$closeOid, $closeCloid];
        $confirmation = $this->reconcile(
            $context,
            $identifiers,
            $closeOid,
            $closeCloid,
            static fn (HyperliquidNormalizedOrderLifecycleDto $lifecycle): bool => !$lifecycle->requiresResync
                && in_array($lifecycle->status, [
                    HyperliquidLifecycleStatus::FILLED,
                    HyperliquidLifecycleStatus::CANCELED,
                    HyperliquidLifecycleStatus::REJECTED,
                ], true),
        );
        if (!$confirmation instanceof HyperliquidNormalizedOrderLifecycleDto
            || $confirmation->status !== HyperliquidLifecycleStatus::FILLED
            || !$this->validCloseLifecycle($context, $confirmation, $quantity, $closeOid)
        ) {
            return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::CLOSE_CONFIRMATION_UNCONFIRMED, $tripGuard);
        }

        return $this->closedResult($context, $entry, $quantity, $confirmation);
    }

    private function resolveAmbiguousClose(
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $entry,
        HyperliquidQuantity $quantity,
        HyperliquidCompensationTripGuard $tripGuard,
    ): HyperliquidCompensationResult {
        $confirmation = $this->reconcile(
            $context,
            [$context->closeClientOrderId],
            null,
            $context->closeClientOrderId,
            static fn (HyperliquidNormalizedOrderLifecycleDto $lifecycle): bool => !$lifecycle->requiresResync,
        );
        if ($confirmation instanceof HyperliquidNormalizedOrderLifecycleDto
            && $confirmation->status === HyperliquidLifecycleStatus::FILLED
            && $this->validCloseLifecycle($context, $confirmation, $quantity, null)
        ) {
            return $this->closedResult($context, $entry, $quantity, $confirmation);
        }

        return $this->unresolved($context, $entry->status, $entry->filledQuantity, $entry->exchangeOrderId, HyperliquidCompensationReasonCode::CLOSE_SUBMISSION_UNCONFIRMED, $tripGuard);
    }

    private function existingClose(HyperliquidCompensationContext $context): ?HyperliquidNormalizedOrderLifecycleDto
    {
        $runtimeFailure = false;
        for ($cycle = 0; $cycle < self::MAX_RECONCILIATION_CYCLES; ++$cycle) {
            try {
                $lifecycle = $this->lifecycles->lookup(
                    $context->accountAddress,
                    $context->closeClientOrderId,
                    null,
                    $context->closeClientOrderId,
                );
            } catch (HyperliquidIdentifierBindingException) {
                throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::IDENTIFIER_CONTRADICTION);
            } catch (\RuntimeException) {
                $runtimeFailure = true;
                $lifecycle = null;
            }
            if ($lifecycle instanceof HyperliquidNormalizedOrderLifecycleDto) {
                return $lifecycle;
            }
            if ($cycle + 1 < self::MAX_RECONCILIATION_CYCLES) {
                $this->sleepBetweenCycles();
            }
        }
        if ($runtimeFailure) {
            throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::PROVIDER_RUNTIME_FAILURE);
        }

        return null;
    }

    private function closedResult(
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $entry,
        HyperliquidQuantity $quantity,
        HyperliquidNormalizedOrderLifecycleDto $confirmation,
    ): HyperliquidCompensationResult {
        return new HyperliquidCompensationResult(
            outcome: 'exposure_closed',
            reasonCode: HyperliquidCompensationReasonCode::EXPOSURE_CLOSED,
            entryStatus: $entry->status,
            expectedQuantity: $context->quantity,
            provenFilledQuantity: $entry->filledQuantity,
            closedQuantity: $quantity->toFloat(),
            quantityPrecision: $context->quantityPrecision,
            quantityStep: $context->quantityStep,
            entryExchangeOrderId: $entry->exchangeOrderId,
            closeExchangeOrderId: $confirmation->exchangeOrderId,
            correlationId: $context->correlationId,
        );
    }

    private function validEntryLifecycle(
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $lifecycle,
    ): bool {
        try {
            $quantity = $this->quantity($context, $lifecycle->quantity);
            $filled = $this->quantity($context, $lifecycle->filledQuantity);
        } catch (\InvalidArgumentException) {
            return false;
        }
        if (!$quantity->equals($context->canonicalQuantity)) {
            return false;
        }

        return match ($lifecycle->status) {
            HyperliquidLifecycleStatus::REJECTED,
            HyperliquidLifecycleStatus::OPEN,
            HyperliquidLifecycleStatus::ACCEPTED => $filled->isZero(),
            HyperliquidLifecycleStatus::CANCELED => $filled->compareTo($context->canonicalQuantity) <= 0,
            HyperliquidLifecycleStatus::FILLED => $filled->equals($context->canonicalQuantity),
            HyperliquidLifecycleStatus::PARTIALLY_FILLED => $filled->isPositive()
                && $filled->compareTo($context->canonicalQuantity) < 0,
            HyperliquidLifecycleStatus::FAILED,
            HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC => false,
        };
    }

    private function validCloseLifecycle(
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $lifecycle,
        HyperliquidQuantity $requested,
        ?string $expectedOid,
    ): bool {
        try {
            return $this->matchesIdentifiers($lifecycle, $expectedOid, $context->closeClientOrderId)
                && $this->quantity($context, $lifecycle->quantity)->equals($requested)
                && $this->quantity($context, $lifecycle->filledQuantity)->equals($requested);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function quantity(HyperliquidCompensationContext $context, float $quantity): HyperliquidQuantity
    {
        return new HyperliquidQuantity($quantity, $context->quantityPrecision, $context->quantityStep);
    }

    /**
     * @param list<string> $identifiers
     * @param callable(HyperliquidNormalizedOrderLifecycleDto): bool $accept
     */
    private function reconcile(
        HyperliquidCompensationContext $context,
        array $identifiers,
        ?string $expectedOid,
        string $expectedCloid,
        callable $accept,
    ): ?HyperliquidNormalizedOrderLifecycleDto {
        $runtimeFailure = false;
        for ($cycle = 0; $cycle < self::MAX_RECONCILIATION_CYCLES; ++$cycle) {
            foreach (array_slice(array_values(array_unique($identifiers)), 0, 2) as $identifier) {
                try {
                    $lifecycle = $this->lifecycles->lookup(
                        $context->accountAddress,
                        $identifier,
                        $expectedOid,
                        $expectedCloid,
                    );
                } catch (HyperliquidIdentifierBindingException) {
                    throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::IDENTIFIER_CONTRADICTION);
                } catch (\RuntimeException) {
                    $runtimeFailure = true;
                    $lifecycle = null;
                }
                if ($lifecycle instanceof HyperliquidNormalizedOrderLifecycleDto) {
                    if (!$this->matchesIdentifiers($lifecycle, $expectedOid, $expectedCloid)) {
                        throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::IDENTIFIER_CONTRADICTION);
                    }
                    if ($accept($lifecycle)) {
                        return $lifecycle;
                    }
                }
            }
            if ($cycle + 1 < self::MAX_RECONCILIATION_CYCLES) {
                $this->sleepBetweenCycles();
            }
        }
        if ($runtimeFailure) {
            throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::PROVIDER_RUNTIME_FAILURE);
        }

        return null;
    }

    private function nextNonce(HyperliquidCompensationContext $context): int
    {
        try {
            return $this->nonces->nextNonce($context->nonceScope);
        } catch (\RuntimeException) {
            throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::NONCE_FAILURE);
        }
    }

    private function sleepBetweenCycles(): void
    {
        try {
            $this->sleeper->sleepMilliseconds(self::RECONCILIATION_SLEEP_MILLISECONDS);
        } catch (\RuntimeException) {
            throw new HyperliquidCompensationRuntimeFailure(HyperliquidCompensationReasonCode::SLEEPER_FAILURE);
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

    private function validAcceptedCancel(
        HyperliquidSignedActionResult $result,
        HyperliquidCompensationContext $context,
    ): bool {
        return $result->actionType === 'cancelByCloid'
            && $result->outcome === 'accepted'
            && $result->correlationId === $context->correlationId
            && $result->statuses === [['kind' => 'success']];
    }

    private function acceptedCloseOid(
        HyperliquidSignedActionResult $result,
        HyperliquidCompensationContext $context,
    ): ?string {
        if ($result->actionType !== 'order'
            || $result->outcome !== 'accepted'
            || $result->correlationId !== $context->correlationId
            || count($result->statuses) !== 1
        ) {
            return null;
        }
        $status = $result->statuses[0];
        if (!in_array($status['kind'] ?? null, ['resting', 'filled'], true)
            || !isset($status['oid'])
            || !is_int($status['oid'])
            || $status['oid'] <= 0
        ) {
            return null;
        }

        return (string) $status['oid'];
    }

    private function matchesIdentifiers(
        HyperliquidNormalizedOrderLifecycleDto $lifecycle,
        ?string $expectedOid,
        string $expectedCloid,
    ): bool {
        return $lifecycle->clientOrderId === $expectedCloid
            && ($expectedOid === null || $lifecycle->exchangeOrderId === $expectedOid)
            && preg_match('/^[1-9][0-9]*$/D', $lifecycle->exchangeOrderId) === 1;
    }

    private function result(
        string $outcome,
        HyperliquidCompensationContext $context,
        HyperliquidNormalizedOrderLifecycleDto $entry,
    ): HyperliquidCompensationResult {
        return new HyperliquidCompensationResult(
            outcome: $outcome,
            reasonCode: $outcome === 'entry_rejected'
                ? HyperliquidCompensationReasonCode::ENTRY_REJECTED
                : HyperliquidCompensationReasonCode::ENTRY_CANCELED,
            entryStatus: $entry->status,
            expectedQuantity: $context->quantity,
            provenFilledQuantity: $entry->filledQuantity,
            closedQuantity: 0.0,
            quantityPrecision: $context->quantityPrecision,
            quantityStep: $context->quantityStep,
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
        HyperliquidCompensationReasonCode $reasonCode,
        HyperliquidCompensationTripGuard $tripGuard,
    ): HyperliquidCompensationResult {
        $tripGuard->trip();

        return new HyperliquidCompensationResult(
            outcome: 'unknown_requires_resync',
            reasonCode: $reasonCode,
            entryStatus: $entryStatus,
            expectedQuantity: $context->quantity,
            provenFilledQuantity: $this->boundedProvenQuantity($context, $provenFilledQuantity),
            closedQuantity: 0.0,
            quantityPrecision: $context->quantityPrecision,
            quantityStep: $context->quantityStep,
            entryExchangeOrderId: $this->canonicalOidOrNull($entryExchangeOrderId),
            closeExchangeOrderId: null,
            correlationId: $context->correlationId,
        );
    }

    private function boundedProvenQuantity(HyperliquidCompensationContext $context, float $quantity): float
    {
        try {
            $candidate = $this->quantity($context, $quantity);

            return $candidate->compareTo($context->canonicalQuantity) <= 0 ? $candidate->toFloat() : 0.0;
        } catch (\InvalidArgumentException) {
            return 0.0;
        }
    }

    private function canonicalOidOrNull(?string $oid): ?string
    {
        if ($oid === null || preg_match('/^[1-9][0-9]*$/D', $oid) !== 1) {
            return null;
        }
        $value = filter_var($oid, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($value) && (string) $value === $oid ? $oid : null;
    }
}
