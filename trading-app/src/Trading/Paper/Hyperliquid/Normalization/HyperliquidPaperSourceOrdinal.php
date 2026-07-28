<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;

final class HyperliquidPaperSourceOrdinal
{
    private const SCHEMA_VERSION = 1;
    private const MAX_NATURAL_IDENTITY_BYTES = 1_024;
    private const MAX_DECIMAL_LENGTH = 128;
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    private const CANDLE_PAYLOAD_KEYS = [
        'close',
        'close_time',
        'confirmed',
        'high',
        'interval',
        'low',
        'native_symbol',
        'open',
        'origin',
        'start_time',
        'trade_count',
        'volume',
    ];

    /** @var list<string> */
    private const MODELLED_BOOK_PAYLOAD_KEYS = [
        'ask_price',
        'ask_size',
        'bid_price',
        'bid_size',
        'model_name',
        'model_version',
        'origin',
        'source_candle_start',
        'synthetic',
    ];

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
        $this->assertCanonicalEvent($naturalIdentity, $event);
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
                $instance->assertCanonicalEvent($latest['natural_identity'], $event);
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

    private function assertCanonicalEvent(
        string $naturalIdentity,
        PaperMarketEvent $event,
    ): void {
        try {
            $expected = match ($event->channel) {
                PaperMarketDataChannel::CANDLE_1M,
                PaperMarketDataChannel::CANDLE_5M,
                PaperMarketDataChannel::CANDLE_15M,
                PaperMarketDataChannel::CANDLE_1H => $this->canonicalCandleIdentity($event),
                PaperMarketDataChannel::TOP_OF_BOOK => $this->canonicalBookIdentity(
                    $naturalIdentity,
                    $event,
                ),
                default => throw new \InvalidArgumentException(),
            };

            if (!hash_equals($expected, $naturalIdentity)) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new \LogicException(
                'hyperliquid_paper_source_ordinal_transaction_invalid',
            );
        }
    }

    private function canonicalCandleIdentity(PaperMarketEvent $event): string
    {
        $payload = $event->payload;
        self::assertExactKeys($payload, self::CANDLE_PAYLOAD_KEYS);
        $coin = $payload['native_symbol'] ?? null;
        $interval = $payload['interval'] ?? null;
        $startTime = $payload['start_time'] ?? null;
        $closeTime = $payload['close_time'] ?? null;
        $tradeCount = $payload['trade_count'] ?? null;
        if (!\is_string($coin)
            || !\is_string($interval)
            || !\is_string($startTime)
            || !\is_string($closeTime)
            || !\is_string($tradeCount)
            || !$this->unsignedInteger($startTime)
            || !$this->unsignedInteger($closeTime)
            || !$this->unsignedInteger($tradeCount)
            || $this->channelForInterval($interval) !== $event->channel
            || ($payload['confirmed'] ?? null) !== true
            || ($payload['origin'] ?? null) !== 'rest_candle_snapshot'
        ) {
            throw new \InvalidArgumentException();
        }

        $instruments = new HyperliquidPaperInstrumentMap();
        if ($instruments->normalizedSymbol($coin) !== $event->symbol) {
            throw new \InvalidArgumentException();
        }
        $this->assertCandleBoundary($interval, $startTime, $closeTime);
        $this->assertEventTimestamps($event, $closeTime);

        $open = $this->canonicalDecimal($payload['open'] ?? null, positive: true);
        $high = $this->canonicalDecimal($payload['high'] ?? null, positive: true);
        $low = $this->canonicalDecimal($payload['low'] ?? null, positive: true);
        $close = $this->canonicalDecimal($payload['close'] ?? null, positive: true);
        $this->canonicalDecimal($payload['volume'] ?? null, positive: false);
        if ($high->isLessThan($open)
            || $high->isLessThan($close)
            || $high->isLessThan($low)
            || $low->isGreaterThan($open)
            || $low->isGreaterThan($close)
            || $low->isGreaterThan($high)
        ) {
            throw new \InvalidArgumentException();
        }

        return implode('|', [$coin, $interval, $startTime, $closeTime]);
    }

    private function canonicalBookIdentity(
        string $naturalIdentity,
        PaperMarketEvent $event,
    ): string
    {
        $payload = $event->payload;
        self::assertExactKeys($payload, self::MODELLED_BOOK_PAYLOAD_KEYS);
        $identityParts = explode('|', $naturalIdentity);
        if (\count($identityParts) !== 6) {
            throw new \InvalidArgumentException();
        }
        [$coin, $interval, $startTime, $closeTime, $modelName, $modelVersion]
            = $identityParts;
        if (!$this->unsignedInteger($startTime)
            || !$this->unsignedInteger($closeTime)
            || $this->channelForInterval($interval) === null
            || ($payload['source_candle_start'] ?? null) !== $startTime
            || ($payload['model_name'] ?? null) !== $modelName
            || ($payload['model_version'] ?? null) !== $modelVersion
            || ($payload['origin'] ?? null) !== 'historical_candle_model'
            || ($payload['synthetic'] ?? null) !== true
            || $modelName !== HyperliquidPrudentBookModel::NAME
            || $modelVersion !== HyperliquidPrudentBookModel::VERSION
        ) {
            throw new \InvalidArgumentException();
        }

        $instruments = new HyperliquidPaperInstrumentMap();
        if ($instruments->normalizedSymbol($coin) !== $event->symbol) {
            throw new \InvalidArgumentException();
        }
        $this->assertCandleBoundary($interval, $startTime, $closeTime);
        $this->assertEventTimestamps($event, $closeTime);

        $bid = $this->canonicalDecimal($payload['bid_price'] ?? null, positive: true);
        $ask = $this->canonicalDecimal($payload['ask_price'] ?? null, positive: true);
        $bidSize = $this->canonicalDecimal($payload['bid_size'] ?? null, positive: true);
        $askSize = $this->canonicalDecimal($payload['ask_size'] ?? null, positive: true);
        if (!$bid->isLessThan($ask) || !$bidSize->isEqualTo($askSize)) {
            throw new \InvalidArgumentException();
        }

        return implode('|', $identityParts);
    }

    private function assertCandleBoundary(
        string $interval,
        string $startTime,
        string $closeTime,
    ): void {
        $intervalMilliseconds = (new HyperliquidPaperInstrumentMap())
            ->intervalMilliseconds($interval);
        if (!BigInteger::of($startTime)
            ->plus($intervalMilliseconds - 1)
            ->isEqualTo(BigInteger::of($closeTime))
        ) {
            throw new \InvalidArgumentException();
        }
    }

    private function assertEventTimestamps(PaperMarketEvent $event, string $closeTime): void
    {
        if ($event->exchangeTimestamp->format(self::TIMESTAMP_FORMAT)
                !== $event->receivedTimestamp->format(self::TIMESTAMP_FORMAT)
            || $this->epochMilliseconds($event->exchangeTimestamp) !== $closeTime
        ) {
            throw new \InvalidArgumentException();
        }
    }

    private function epochMilliseconds(\DateTimeImmutable $timestamp): string
    {
        $seconds = $timestamp->format('U');
        $microseconds = $timestamp->format('u');
        if (!$this->unsignedInteger($seconds)
            || preg_match('/\A[0-9]{6}\z/D', $microseconds) !== 1
            || !str_ends_with($microseconds, '000')
        ) {
            throw new \InvalidArgumentException();
        }

        return (string) BigInteger::of($seconds)
            ->multipliedBy(1_000)
            ->plus((int) substr($microseconds, 0, 3));
    }

    private function canonicalDecimal(mixed $value, bool $positive): BigDecimal
    {
        if (!\is_string($value)
            || \strlen($value) > self::MAX_DECIMAL_LENGTH
            || preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        $decimal = BigDecimal::of($value);
        if ((string) $decimal->stripTrailingZeros() !== $value
            || ($positive ? !$decimal->isGreaterThan(0) : $decimal->isLessThan(0))
        ) {
            throw new \InvalidArgumentException();
        }

        return $decimal;
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
