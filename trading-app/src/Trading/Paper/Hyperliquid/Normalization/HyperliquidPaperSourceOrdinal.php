<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigInteger;

final class HyperliquidPaperSourceOrdinal
{
    private const SCHEMA_VERSION = 1;
    private const MAX_NATURAL_IDENTITY_BYTES = 1_024;
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<PaperMarketDataChannel> */
    private const ALLOWED_CHANNELS = [
        PaperMarketDataChannel::CANDLE_1M,
        PaperMarketDataChannel::CANDLE_5M,
        PaperMarketDataChannel::CANDLE_15M,
        PaperMarketDataChannel::CANDLE_1H,
        PaperMarketDataChannel::TOP_OF_BOOK,
    ];

    /**
     * @var array<string, array{
     *     last_sequence: BigInteger,
     *     latest: array{
     *         natural_identity: string,
     *         assignment_digest: string,
     *         event: PaperMarketEvent
     *     }
     * }>
     */
    private array $scopes = [];

    /**
     * Previewing does not mutate sequence or replay state. Commit only a fully constructed event.
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
                throw new \RuntimeException('hyperliquid_paper_natural_identity_conflict');
            }

            return [
                'sequence' => $latest['event']->sequence
                    ?? throw new \LogicException('hyperliquid_paper_source_ordinal_state_invalid'),
                'replayed' => true,
                'event' => $latest['event'],
            ];
        }

        return [
            'sequence' => (string) $this->nextSequence(
                $this->scopes[$scope]['last_sequence'] ?? BigInteger::zero(),
            ),
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
        $this->assertEventNaturalIdentity($naturalIdentity, $event);
        if (!hash_equals(
            self::assignmentDigest($naturalIdentity, $event->exchangeTimestamp, $event->payload),
            $assignmentDigest,
        )) {
            throw new \LogicException('hyperliquid_paper_source_ordinal_transaction_invalid');
        }

        $assignment = $this->preview($scope, $naturalIdentity, $assignmentDigest);
        if ($assignment['replayed'] || $event->sequence !== $assignment['sequence']) {
            throw new \LogicException('hyperliquid_paper_source_ordinal_transaction_invalid');
        }

        $this->scopes[$scope] = [
            'last_sequence' => BigInteger::of($assignment['sequence']),
            'latest' => [
                'natural_identity' => $naturalIdentity,
                'assignment_digest' => $assignmentDigest,
                'event' => $event,
            ],
        ];
    }

    /**
     * @return array{
     *     schema_version: int,
     *     scopes: array<string, array{
     *         last_sequence: string,
     *         latest: array{
     *             natural_identity: string,
     *             assignment_digest: string,
     *             event: array<string, mixed>
     *         }
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
                'latest' => [
                    'natural_identity' => $latest['natural_identity'],
                    'assignment_digest' => $latest['assignment_digest'],
                    'event' => $latest['event']->toArray(),
                ],
            ];
        }
        ksort($scopes, \SORT_STRING);

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
            if (\count($state['scopes']) > self::maximumScopeCount()) {
                throw new \InvalidArgumentException();
            }

            foreach ($state['scopes'] as $scope => $scopeState) {
                if (!\is_string($scope) || !\is_array($scopeState) || array_is_list($scopeState)) {
                    throw new \InvalidArgumentException();
                }
                $instance->assertScope($scope);
                self::assertExactKeys($scopeState, ['last_sequence', 'latest']);
                $lastSequence = self::restoredSequence($scopeState['last_sequence']);
                $latest = $scopeState['latest'];
                if (!\is_array($latest) || array_is_list($latest)) {
                    throw new \InvalidArgumentException();
                }
                self::assertExactKeys(
                    $latest,
                    ['natural_identity', 'assignment_digest', 'event'],
                );
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
                $instance->assertEventNaturalIdentity($latest['natural_identity'], $event);
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
                    'latest' => [
                        'natural_identity' => $latest['natural_identity'],
                        'assignment_digest' => $latest['assignment_digest'],
                        'event' => $event,
                    ],
                ];
            }

            return $instance;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('hyperliquid_paper_source_ordinal_state_invalid');
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
        if (\count($parts) !== 4
            || !\in_array($parts[0], [
                PaperMarketDataNetwork::MAINNET->value,
                PaperMarketDataNetwork::TESTNET->value,
            ], true)
            || $parts[1] !== PaperMarketDataVenue::HYPERLIQUID->value
            || !\in_array(PaperMarketDataChannel::tryFrom($parts[3]), self::ALLOWED_CHANNELS, true)
        ) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_source_ordinal_scope_invalid',
            );
        }

        try {
            (new HyperliquidPaperInstrumentMap())->nativeCoin($parts[2]);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_source_ordinal_scope_invalid',
            );
        }
    }

    private function assertNaturalIdentity(string $naturalIdentity): void
    {
        if ($naturalIdentity === ''
            || \strlen($naturalIdentity) > self::MAX_NATURAL_IDENTITY_BYTES
            || preg_match('//u', $naturalIdentity) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $naturalIdentity) === 1
        ) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_source_ordinal_assignment_invalid',
            );
        }
    }

    private function assertAssignmentDigest(string $assignmentDigest): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $assignmentDigest) !== 1) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_source_ordinal_assignment_invalid',
            );
        }
    }

    private function assertEventScope(string $scope, PaperMarketEvent $event): void
    {
        $this->assertScope($scope);
        if ($scope !== implode('/', [
            $event->sourceNetwork->value,
            $event->sourceVenue->value,
            $event->symbol,
            $event->channel->value,
        ])) {
            throw new \LogicException(
                'hyperliquid_paper_source_ordinal_transaction_invalid',
            );
        }
    }

    private function assertEventNaturalIdentity(
        string $naturalIdentity,
        PaperMarketEvent $event,
    ): void {
        $payload = $event->payload;
        $expected = match ($event->channel) {
            PaperMarketDataChannel::CANDLE_1M,
            PaperMarketDataChannel::CANDLE_5M,
            PaperMarketDataChannel::CANDLE_15M,
            PaperMarketDataChannel::CANDLE_1H => $this->candleNaturalIdentity($event, $payload),
            PaperMarketDataChannel::TOP_OF_BOOK => $this->bookNaturalIdentity($event, $payload),
            default => null,
        };

        if ($expected === null || !hash_equals($expected, $naturalIdentity)) {
            throw new \LogicException(
                'hyperliquid_paper_source_ordinal_transaction_invalid',
            );
        }
    }

    /** @param array<array-key, mixed> $payload */
    private function candleNaturalIdentity(PaperMarketEvent $event, array $payload): ?string
    {
        $coin = $payload['native_symbol'] ?? null;
        $interval = $payload['interval'] ?? null;
        $startTime = $payload['start_time'] ?? null;
        $closeTime = $payload['close_time'] ?? null;
        if (!\is_string($coin)
            || !\is_string($interval)
            || !\is_string($startTime)
            || !\is_string($closeTime)
            || !$this->unsignedInteger($startTime)
            || !$this->unsignedInteger($closeTime)
            || $this->channelForInterval($interval) !== $event->channel
        ) {
            return null;
        }

        try {
            if ((new HyperliquidPaperInstrumentMap())->normalizedSymbol($coin) !== $event->symbol) {
                return null;
            }
        } catch (\InvalidArgumentException) {
            return null;
        }

        return implode('|', [$coin, $interval, $startTime, $closeTime]);
    }

    /** @param array<array-key, mixed> $payload */
    private function bookNaturalIdentity(PaperMarketEvent $event, array $payload): ?string
    {
        $interval = $payload['source_interval'] ?? null;
        $startTime = $payload['source_candle_start'] ?? null;
        $closeTime = $payload['source_candle_close'] ?? null;
        $modelName = $payload['model_name'] ?? null;
        $modelVersion = $payload['model_version'] ?? null;
        if (!\is_string($interval)
            || !\is_string($startTime)
            || !\is_string($closeTime)
            || !\is_string($modelName)
            || !\is_string($modelVersion)
            || !$this->unsignedInteger($startTime)
            || !$this->unsignedInteger($closeTime)
            || $this->channelForInterval($interval) === null
            || $modelName !== HyperliquidPrudentBookModel::NAME
            || $modelVersion !== HyperliquidPrudentBookModel::VERSION
        ) {
            return null;
        }

        try {
            $coin = (new HyperliquidPaperInstrumentMap())->nativeCoin($event->symbol);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return implode('|', [
            $coin,
            $interval,
            $startTime,
            $closeTime,
            $modelName,
            $modelVersion,
        ]);
    }

    private function channelForInterval(string $interval): ?PaperMarketDataChannel
    {
        return match ($interval) {
            '1m' => PaperMarketDataChannel::CANDLE_1M,
            '5m' => PaperMarketDataChannel::CANDLE_5M,
            '15m' => PaperMarketDataChannel::CANDLE_15M,
            '1h' => PaperMarketDataChannel::CANDLE_1H,
            default => null,
        };
    }

    private function unsignedInteger(string $value): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1;
    }

    private function nextSequence(BigInteger $lastSequence): BigInteger
    {
        $next = $lastSequence->plus(1);
        if (\strlen((string) $next) > PaperMarketEvent::MAX_SEQUENCE_DIGITS) {
            throw new \OverflowException('hyperliquid_paper_source_ordinal_exhausted');
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

    private static function maximumScopeCount(): int
    {
        return 2 * 2 * \count(self::ALLOWED_CHANNELS);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $expectedKeys
     */
    private static function assertExactKeys(array $value, array $expectedKeys): void
    {
        $actualKeys = array_keys($value);
        sort($actualKeys, \SORT_STRING);
        sort($expectedKeys, \SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException();
        }
    }
}
