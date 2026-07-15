<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
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
        return $this->resyncRequired;
    }

    public function connectionState(): string
    {
        return $this->resyncRequired ? self::STATE_RESYNC_REQUIRED : self::STATE_CONNECTED;
    }

    public function reconnect(): void
    {
        if ($this->resyncReason === 'fake_private_ws_sequence_gap') {
            throw new \LogicException('fake_private_ws_snapshot_resync_required');
        }

        $this->resyncRequired = false;
        $this->resyncReason = null;
    }

    public function completeSnapshotResync(): void
    {
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
