<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Ws\ExchangeWsClientInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_ws_client')]
final class FakeExchangeWsClient implements ExchangeWsClientInterface
{
    private const STATE_CONNECTED = 'connected';
    private const STATE_RESYNC_REQUIRED = 'resync_required';

    /** @var array<string,bool> */
    private array $consumedSequences = [];
    private bool $resyncRequired = false;
    private ?string $resyncReason = null;
    private bool $disconnectInjected = false;
    private int $acknowledgedEvents = 0;
    private ?string $lastAcknowledgedSequence = null;
    private int $lastObservedNumericSequence = 0;

    public function __construct(
        private readonly FakeExchangeStateStore $stateStore,
        private readonly ?int $disconnectAfterAcknowledgedEvents = null,
    ) {
        if ($this->disconnectAfterAcknowledgedEvents !== null && $this->disconnectAfterAcknowledgedEvents < 0) {
            throw new \InvalidArgumentException('disconnectAfterAcknowledgedEvents must be greater than or equal to zero');
        }
    }

    public function exchange(): Exchange
    {
        return Exchange::FAKE;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    /**
     * A sequence is acknowledged only when the caller resumes the generator
     * after successfully handling the yielded event.
     *
     * @return \Generator<int,FakeExchangeEvent>
     */
    public function drainPrivateEvents(?string $symbol = null): iterable
    {
        if ($this->stateStore->hasPrivateWsScenario()) {
            yield from $this->drainPrivateWsScenario($symbol);

            return;
        }

        if ($this->resyncRequired) {
            throw $this->resyncReason === 'fake_private_ws_sequence_gap'
                ? FakePrivateWsException::snapshotResyncRequired($this->lastAcknowledgedSequence)
                : FakePrivateWsException::disconnected($this->lastAcknowledgedSequence);
        }

        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;
        foreach ($this->stateStore->events() as $index => $event) {
            $sequence = $this->sequence($event, $index);
            $numericSequence = $this->numericSequence($sequence);
            if ($numericSequence !== null && $numericSequence > $this->lastObservedNumericSequence) {
                $expectedSequence = $this->lastObservedNumericSequence + 1;
                if ($numericSequence !== $expectedSequence) {
                    $this->resyncRequired = true;
                    $this->resyncReason = 'fake_private_ws_sequence_gap';

                    throw FakePrivateWsException::sequenceGap(
                        lastAcknowledgedSequence: $this->lastAcknowledgedSequence,
                        expectedSequence: (string)$expectedSequence,
                        actualSequence: $sequence,
                    );
                }

                $this->lastObservedNumericSequence = $numericSequence;
            }

            if (isset($this->consumedSequences[$sequence])) {
                continue;
            }
            if ($normalizedSymbol !== null && $event->symbol !== $normalizedSymbol) {
                continue;
            }

            if (
                !$this->disconnectInjected
                && $this->disconnectAfterAcknowledgedEvents !== null
                && $this->acknowledgedEvents >= $this->disconnectAfterAcknowledgedEvents
            ) {
                $this->disconnectInjected = true;
                $this->resyncRequired = true;
                $this->resyncReason = 'fake_private_ws_disconnected';

                throw FakePrivateWsException::disconnected($this->lastAcknowledgedSequence);
            }

            yield $event;

            $this->consumedSequences[$sequence] = true;
            $this->lastAcknowledgedSequence = $sequence;
            ++$this->acknowledgedEvents;
        }
    }

    public function requiresResync(): bool
    {
        if ($this->stateStore->hasPrivateWsScenario()) {
            return $this->stateStore->privateWsAudit()['connection_state'] === self::STATE_RESYNC_REQUIRED;
        }

        return $this->resyncRequired;
    }

    public function connectionState(): string
    {
        if ($this->stateStore->hasPrivateWsScenario()) {
            return $this->stateStore->privateWsAudit()['connection_state'];
        }

        return $this->resyncRequired ? self::STATE_RESYNC_REQUIRED : self::STATE_CONNECTED;
    }

    public function reconnect(): void
    {
        if ($this->stateStore->hasPrivateWsScenario()) {
            if ($this->requiresResync()) {
                throw new \LogicException('fake_private_ws_snapshot_resync_required');
            }

            return;
        }

        if ($this->resyncReason === 'fake_private_ws_sequence_gap') {
            throw new \LogicException('fake_private_ws_snapshot_resync_required');
        }

        $this->resyncRequired = false;
        $this->resyncReason = null;
    }

    public function completeSnapshotResync(?ExchangeReconciliationResult $reconciliation = null): void
    {
        if ($this->stateStore->hasPrivateWsScenario()) {
            if (!$reconciliation instanceof ExchangeReconciliationResult) {
                throw new \LogicException('fake_private_ws_global_reconciliation_required');
            }

            $this->stateStore->completePrivateWsSnapshotResync($reconciliation);

            return;
        }

        foreach ($this->stateStore->events() as $index => $event) {
            $sequence = $this->sequence($event, $index);
            $this->consumedSequences[$sequence] = true;
            $this->lastAcknowledgedSequence = $sequence;
            $numericSequence = $this->numericSequence($sequence);
            if ($numericSequence !== null) {
                $this->lastObservedNumericSequence = max($this->lastObservedNumericSequence, $numericSequence);
            }
        }

        $this->resyncRequired = false;
        $this->resyncReason = null;
    }

    /**
     * @return array{
     *     scenario_id:?string,
     *     next_delivery_index:int,
     *     acknowledged_total:int,
     *     duplicate_total:int,
     *     gap_total:int,
     *     conflict_total:int,
     *     resync_total:int,
     *     connection_state:string,
     *     resync_reason:?string,
     *     last_acknowledged_sequence:?string,
     *     last_observed_numeric_sequence:int,
     *     records:list<array<string,string>>
     * }
     */
    public function privateWsAudit(): array
    {
        return $this->stateStore->privateWsAudit();
    }

    /** @return array<string,mixed> */
    public function audit(): array
    {
        return $this->privateWsAudit();
    }

    /**
     * @return \Generator<int,FakeExchangeEvent>
     */
    private function drainPrivateWsScenario(?string $symbol): \Generator
    {
        $lease = $this->stateStore->acquirePrivateWsConsumptionLease();

        try {
            if ($this->requiresResync()) {
                throw FakePrivateWsException::snapshotResyncRequired(
                    $this->stateStore->privateWsLastAcknowledgedSequence(),
                );
            }

            $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;
            while (($delivery = $this->stateStore->privateWsCurrentDelivery()) !== null) {
                if ($normalizedSymbol !== null && strtoupper($delivery->event->symbol) !== $normalizedSymbol) {
                    return;
                }

                $known = $this->stateStore->privateWsAcknowledgedFingerprint($delivery->sequence);
                if ($known !== null) {
                    if (!hash_equals($known, $delivery->fingerprint)) {
                        $this->stateStore->markPrivateWsConflict($delivery);

                        throw FakePrivateWsException::sequenceConflict(
                            $this->stateStore->privateWsLastAcknowledgedSequence(),
                            $delivery->sequence,
                        );
                    }

                    $this->stateStore->skipExactPrivateWsDuplicate($delivery);

                    continue;
                }

                $expected = $this->stateStore->privateWsExpectedNumericSequence();
                $actual = ctype_digit($delivery->sequence) ? (int) $delivery->sequence : null;
                if ($actual !== null && $actual > $expected) {
                    $this->stateStore->markPrivateWsGap((string) $expected, $delivery->sequence, $delivery);

                    throw FakePrivateWsException::sequenceGap(
                        $this->stateStore->privateWsLastAcknowledgedSequence(),
                        (string) $expected,
                        $delivery->sequence,
                    );
                }

                yield $delivery->event;

                $this->stateStore->acknowledgePrivateWsDelivery($delivery);
            }
        } finally {
            $lease->release();
        }
    }

    private function sequence(FakeExchangeEvent $event, int $index): string
    {
        $sequence = $event->payload['event_sequence'] ?? null;

        return \is_scalar($sequence) ? (string)$sequence : 'idx-' . $index;
    }

    private function numericSequence(string $sequence): ?int
    {
        return ctype_digit($sequence) ? (int)$sequence : null;
    }
}
