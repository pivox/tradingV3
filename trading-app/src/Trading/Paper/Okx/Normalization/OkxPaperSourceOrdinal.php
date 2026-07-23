<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Normalization;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use Brick\Math\BigInteger;

final class OkxPaperSourceOrdinal
{
    private const SCHEMA_VERSION = 1;
    private const MAX_NATURAL_IDENTITY_BYTES = 1_024;
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @var array<string, array{
     *     last_sequence: BigInteger,
     *     gap_pending: bool,
     *     latest: array{
     *         natural_identity: string,
     *         assignment_digest: string,
     *         event: PaperMarketEvent
     *     }|null
     * }>
     */
    private array $scopes = [];

    /**
     * Previewing never changes ordinal or replay state. Call commit() only after the event is valid.
     *
     * @return array{sequence: string, replayed: bool, event: PaperMarketEvent|null}
     */
    public function preview(string $scope, string $naturalIdentity, string $assignmentDigest): array
    {
        $this->assertScope($scope);
        $this->assertNaturalIdentity($naturalIdentity);
        $this->assertAssignmentDigest($assignmentDigest);

        $latest = $this->scopes[$scope]['latest'] ?? null;
        if ($latest !== null && hash_equals($latest['natural_identity'], $naturalIdentity)) {
            if (!hash_equals($latest['assignment_digest'], $assignmentDigest)) {
                throw new \RuntimeException('okx_paper_natural_identity_conflict');
            }

            return [
                'sequence' => $latest['event']->sequence
                    ?? throw new \LogicException('okx_paper_source_ordinal_state_invalid'),
                'replayed' => true,
                'event' => $latest['event'],
            ];
        }

        $state = $this->scopes[$scope] ?? null;
        $nextSequence = $this->nextSequence($state['last_sequence'] ?? BigInteger::zero());
        if ($state !== null && $state['gap_pending']) {
            $nextSequence = $this->nextSequence($nextSequence);
        }

        return [
            'sequence' => (string) $nextSequence,
            'replayed' => false,
            'event' => null,
        ];
    }

    public function commit(
        string $scope,
        string $naturalIdentity,
        string $assignmentDigest,
        PaperMarketEvent $event,
    ): void {
        $this->assertEventScope($scope, $event);
        if (!hash_equals(
            self::assignmentDigest($naturalIdentity, $event->exchangeTimestamp, $event->payload),
            $assignmentDigest,
        )) {
            throw new \LogicException('okx_paper_source_ordinal_transaction_invalid');
        }

        $assignment = $this->preview($scope, $naturalIdentity, $assignmentDigest);
        if ($assignment['replayed'] || $event->sequence !== $assignment['sequence']) {
            throw new \LogicException('okx_paper_source_ordinal_transaction_invalid');
        }

        $this->scopes[$scope] = [
            'last_sequence' => BigInteger::of($assignment['sequence']),
            'gap_pending' => false,
            'latest' => [
                'natural_identity' => $naturalIdentity,
                'assignment_digest' => $assignmentDigest,
                'event' => $event,
            ],
        ];
    }

    /** Reserve exactly one ordinal in a source scope after a proven raw-source gap. */
    public function reserveGap(string $scope): void
    {
        $this->assertScope($scope);
        $state = $this->scopes[$scope] ?? null;
        if ($state === null || $state['latest'] === null) {
            throw new \LogicException('okx_paper_source_gap_reservation_invalid');
        }
        if ($state['gap_pending']) {
            return;
        }

        $state['gap_pending'] = true;
        $this->scopes[$scope] = $state;
    }

    /**
     * Returns JSON-serializable, bounded checkpoint state. Only the latest accepted event is kept
     * for each finite OKX venue/symbol/channel scope; Task 3 owns durable dataset-wide deduplication.
     *
     * @return array{
     *     schema_version: int,
     *     scopes: array<string, array{
     *         last_sequence: string,
     *         gap_pending: bool,
     *         latest: array{
     *             natural_identity: string,
     *             assignment_digest: string,
     *             event: array<string, mixed>
     *         }|null
     *     }>
     * }
     */
    public function snapshot(): array
    {
        $scopes = [];
        foreach ($this->scopes as $scope => $state) {
            $latest = $state['latest'];
            $scopes[$scope] = [
                'last_sequence' => (string) $state['last_sequence'],
                'gap_pending' => $state['gap_pending'],
                'latest' => $latest === null ? null : [
                    'natural_identity' => $latest['natural_identity'],
                    'assignment_digest' => $latest['assignment_digest'],
                    'event' => $latest['event']->toArray(),
                ],
            ];
        }
        ksort($scopes, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'scopes' => $scopes,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function restore(#[\SensitiveParameter] array $state): self
    {
        try {
            self::assertExactKeys($state, ['schema_version', 'scopes']);
            if ($state['schema_version'] !== self::SCHEMA_VERSION
                || !\is_array($state['scopes'])
                || (array_is_list($state['scopes']) && $state['scopes'] !== [])
            ) {
                throw new \InvalidArgumentException();
            }

            $instance = new self();
            $maximumScopes = \count(PaperMarketDataChannel::cases())
                * \count((new OkxPaperInstrumentMap())->nativeInstrumentIds());
            if (\count($state['scopes']) > $maximumScopes) {
                throw new \InvalidArgumentException();
            }

            foreach ($state['scopes'] as $scope => $scopeState) {
                if (!\is_string($scope) || !\is_array($scopeState) || array_is_list($scopeState)) {
                    throw new \InvalidArgumentException();
                }
                $instance->assertScope($scope);
                self::assertExactKeys($scopeState, ['last_sequence', 'gap_pending', 'latest']);
                if (!\is_bool($scopeState['gap_pending'])) {
                    throw new \InvalidArgumentException();
                }

                $lastSequence = self::restoredSequence($scopeState['last_sequence']);
                $gapPending = $scopeState['gap_pending'];
                $latest = $scopeState['latest'];
                if ($latest === null) {
                    throw new \InvalidArgumentException();
                }
                if (!\is_array($latest) || array_is_list($latest)) {
                    throw new \InvalidArgumentException();
                }
                self::assertExactKeys($latest, ['natural_identity', 'assignment_digest', 'event']);
                if (!\is_string($latest['natural_identity'])
                    || !\is_string($latest['assignment_digest'])
                    || !\is_array($latest['event'])
                    || array_is_list($latest['event'])
                ) {
                    throw new \InvalidArgumentException();
                }

                $instance->assertNaturalIdentity($latest['natural_identity']);
                $instance->assertAssignmentDigest($latest['assignment_digest']);
                /** @var array<string, mixed> $eventState */
                $eventState = $latest['event'];
                $event = PaperMarketEvent::fromArray($eventState);
                $instance->assertEventScope($scope, $event);
                if ($event->sequence === null) {
                    throw new \InvalidArgumentException();
                }
                $eventSequence = self::restoredSequence($event->sequence);
                if (!$eventSequence->isEqualTo($lastSequence)
                    || !hash_equals(
                        self::assignmentDigest(
                            $latest['natural_identity'],
                            $event->exchangeTimestamp,
                            $event->payload,
                        ),
                        $latest['assignment_digest'],
                    )
                ) {
                    throw new \InvalidArgumentException();
                }

                $instance->scopes[$scope] = [
                    'last_sequence' => $lastSequence,
                    'gap_pending' => $gapPending,
                    'latest' => [
                        'natural_identity' => $latest['natural_identity'],
                        'assignment_digest' => $latest['assignment_digest'],
                        'event' => $event,
                    ],
                ];
            }

            return $instance;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('okx_paper_source_ordinal_state_invalid');
        }
    }

    /** @param array<array-key, mixed> $payload */
    public static function assignmentDigest(
        #[\SensitiveParameter]
        string $naturalIdentity,
        #[\SensitiveParameter]
        \DateTimeImmutable $exchangeTimestamp,
        #[\SensitiveParameter]
        array $payload,
    ): string {
        $exchangeTimestampUtc = \DateTimeImmutable::createFromInterface($exchangeTimestamp)
            ->setTimezone(new \DateTimeZone('UTC'));

        return hash('sha256', CanonicalJson::encode([
            'natural_identity' => $naturalIdentity,
            'exchange_timestamp' => $exchangeTimestampUtc->format(self::TIMESTAMP_FORMAT),
            'payload' => $payload,
        ]));
    }

    private function assertScope(string $scope): void
    {
        $parts = explode('/', $scope);
        if (\count($parts) !== 3 || $parts[0] !== PaperMarketDataVenue::OKX->value) {
            throw new \InvalidArgumentException('okx_paper_source_ordinal_scope_invalid');
        }

        try {
            (new OkxPaperInstrumentMap())->nativeInstrumentId($parts[1]);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('okx_paper_source_ordinal_scope_invalid');
        }
        if (PaperMarketDataChannel::tryFrom($parts[2]) === null) {
            throw new \InvalidArgumentException('okx_paper_source_ordinal_scope_invalid');
        }
    }

    private function assertNaturalIdentity(string $naturalIdentity): void
    {
        if ($naturalIdentity === ''
            || \strlen($naturalIdentity) > self::MAX_NATURAL_IDENTITY_BYTES
            || preg_match('//u', $naturalIdentity) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $naturalIdentity) === 1
        ) {
            throw new \InvalidArgumentException('okx_paper_source_ordinal_assignment_invalid');
        }
    }

    private function assertAssignmentDigest(string $assignmentDigest): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $assignmentDigest) !== 1) {
            throw new \InvalidArgumentException('okx_paper_source_ordinal_assignment_invalid');
        }
    }

    private function assertEventScope(string $scope, PaperMarketEvent $event): void
    {
        $this->assertScope($scope);
        if ($scope !== implode('/', [
            $event->sourceVenue->value,
            $event->symbol,
            $event->channel->value,
        ])) {
            throw new \LogicException('okx_paper_source_ordinal_transaction_invalid');
        }
    }

    private function nextSequence(BigInteger $lastSequence): BigInteger
    {
        $next = $lastSequence->plus(1);
        if (\strlen((string) $next) > PaperMarketEvent::MAX_SEQUENCE_DIGITS) {
            throw new \OverflowException('okx_paper_source_ordinal_exhausted');
        }

        return $next;
    }

    private static function restoredSequence(mixed $sequence): BigInteger
    {
        if (!\is_string($sequence)
            || \strlen($sequence) > PaperMarketEvent::MAX_SEQUENCE_DIGITS
            || preg_match('/\A[1-9][0-9]*\z/D', $sequence) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        return BigInteger::of($sequence);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $expectedKeys
     */
    private static function assertExactKeys(array $value, array $expectedKeys): void
    {
        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException();
        }
    }
}
