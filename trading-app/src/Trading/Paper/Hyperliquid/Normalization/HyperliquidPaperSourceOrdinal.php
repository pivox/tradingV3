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
use Brick\Math\RoundingMode;

final class HyperliquidPaperSourceOrdinal
{
    private const SCHEMA_VERSION = 2;
    private const MAX_NATURAL_IDENTITY_BYTES = 1_024;
    private const MAX_DECIMAL_LENGTH = 128;
    private const MODEL_CALCULATION_SCALE = 18;
    private const MODEL_MIN_SPREAD_BPS = '2';
    private const MODEL_MAX_SPREAD_BPS = '50';
    private const MODEL_VOLATILITY_MULTIPLIER = '0.15';
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

    /** @var list<string> */
    private const MODEL_INPUT_KEYS = [
        'ask',
        'atr',
        'bid',
        'model_name',
        'model_version',
        'size',
        'spread_bps',
    ];

    /** @var list<string> */
    private const MODEL_WITNESS_KEYS = [
        'atr',
        'source_candle',
        'spread_bps',
    ];

    /** @var list<string> */
    private const SOURCE_CANDLE_WITNESS_KEYS = [
        'close',
        'close_time',
        'high',
        'interval',
        'low',
        'native_symbol',
        'open',
        'start_time',
        'trade_count',
        'volume',
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
     *         event: PaperMarketEvent,
     *         validation_witness: array<string, mixed>|null
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

    /** @param array<array-key, mixed>|null $validationWitness */
    public function commit(
        string $scope,
        string $naturalIdentity,
        string $assignmentDigest,
        PaperMarketEvent $event,
        #[\SensitiveParameter]
        ?array $validationWitness = null,
    ): void {
        $this->assertEventScope($scope, $event);
        $this->assertCanonicalEvent($naturalIdentity, $event, $validationWitness);
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
                'validation_witness' => $validationWitness,
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
     *             event: array<string, mixed>,
     *             validation_witness: array<string, mixed>|null
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
                    'validation_witness' => $latest['validation_witness'],
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
                    [
                        'natural_identity',
                        'assignment_digest',
                        'event',
                        'validation_witness',
                    ],
                );
                if (!\is_string($latest['natural_identity'])
                    || !\is_string($latest['assignment_digest'])
                    || !\is_array($latest['event'])
                    || array_is_list($latest['event'])
                    || ($latest['validation_witness'] !== null
                        && !\is_array($latest['validation_witness']))
                ) {
                    throw new \InvalidArgumentException();
                }

                $instance->assertNaturalIdentity($latest['natural_identity']);
                $instance->assertAssignmentDigest($latest['assignment_digest']);
                /** @var array<string, mixed> $eventState */
                $eventState = $latest['event'];
                $event = PaperMarketEvent::fromArray($eventState);
                $instance->assertEventScope($scope, $event);
                /** @var array<string, mixed>|null $validationWitness */
                $validationWitness = $latest['validation_witness'];
                $instance->assertCanonicalEvent(
                    $latest['natural_identity'],
                    $event,
                    $validationWitness,
                );
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
                        'validation_witness' => $validationWitness,
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

    /**
     * @param array<array-key, mixed> $book
     *
     * @return array{
     *     source_candle: array{
     *         native_symbol: string,
     *         interval: string,
     *         start_time: string,
     *         close_time: string,
     *         open: string,
     *         high: string,
     *         low: string,
     *         close: string,
     *         volume: string,
     *         trade_count: string
     *     },
     *     spread_bps: string,
     *     atr: string
     * }
     */
    public static function modelValidationWitness(
        HyperliquidCandle $candle,
        #[\SensitiveParameter]
        array $book,
    ): array {
        try {
            self::assertExactKeys($book, self::MODEL_INPUT_KEYS);
            if (($book['model_name'] ?? null) !== HyperliquidPrudentBookModel::NAME
                || ($book['model_version'] ?? null) !== HyperliquidPrudentBookModel::VERSION
                || !$candle->volume->isGreaterThan(0)
                || $candle->tradeCount < 1
            ) {
                throw new \InvalidArgumentException();
            }

            $bid = self::canonicalDecimal($book['bid'] ?? null, positive: true);
            $ask = self::canonicalDecimal($book['ask'] ?? null, positive: true);
            $size = self::canonicalDecimal($book['size'] ?? null, positive: true);
            $spreadBps = self::canonicalDecimal(
                $book['spread_bps'] ?? null,
                positive: false,
            );
            $atr = self::canonicalDecimal($book['atr'] ?? null, positive: false);

            $volatilityRange = $candle->range()->isGreaterThanOrEqualTo($atr)
                ? $candle->range()
                : $atr;
            $volatilityBps = $volatilityRange
                ->multipliedBy(10_000)
                ->dividedBy(
                    $candle->close,
                    self::MODEL_CALCULATION_SCALE,
                    RoundingMode::HALF_EVEN,
                );
            $expectedSpread = $volatilityBps
                ->multipliedBy(self::MODEL_VOLATILITY_MULTIPLIER)
                ->toScale(self::MODEL_CALCULATION_SCALE, RoundingMode::HALF_EVEN);
            if ($expectedSpread->isLessThan(self::MODEL_MIN_SPREAD_BPS)) {
                $expectedSpread = BigDecimal::of(self::MODEL_MIN_SPREAD_BPS);
            } elseif ($expectedSpread->isGreaterThan(self::MODEL_MAX_SPREAD_BPS)) {
                $expectedSpread = BigDecimal::of(self::MODEL_MAX_SPREAD_BPS);
            }

            $halfSpreadRatio = $expectedSpread->dividedBy(
                20_000,
                self::MODEL_CALCULATION_SCALE,
                RoundingMode::HALF_EVEN,
            );
            $expectedBid = $candle->close
                ->multipliedBy(BigDecimal::one()->minus($halfSpreadRatio))
                ->toScale(self::MODEL_CALCULATION_SCALE, RoundingMode::HALF_EVEN);
            $expectedAsk = $candle->close
                ->multipliedBy(BigDecimal::one()->plus($halfSpreadRatio))
                ->toScale(self::MODEL_CALCULATION_SCALE, RoundingMode::HALF_EVEN);
            $expectedSize = $candle->volume->dividedBy(
                $candle->tradeCount,
                self::MODEL_CALCULATION_SCALE,
                RoundingMode::HALF_EVEN,
            );
            if ((string) $spreadBps !== self::canonical($expectedSpread)
                || (string) $bid !== self::canonical($expectedBid)
                || (string) $ask !== self::canonical($expectedAsk)
                || (string) $size !== self::canonical($expectedSize)
            ) {
                throw new \InvalidArgumentException();
            }

            return [
                'source_candle' => [
                    'native_symbol' => $candle->coin,
                    'interval' => $candle->interval,
                    'start_time' => (string) $candle->startTime,
                    'close_time' => (string) $candle->closeTime,
                    'open' => (string) $candle->open,
                    'high' => (string) $candle->high,
                    'low' => (string) $candle->low,
                    'close' => (string) $candle->close,
                    'volume' => (string) $candle->volume,
                    'trade_count' => (string) $candle->tradeCount,
                ],
                'spread_bps' => (string) $spreadBps,
                'atr' => (string) $atr,
            ];
        } catch (\Throwable) {
            throw new \InvalidArgumentException('hyperliquid_paper_modelled_book_invalid');
        }
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

    /** @param array<array-key, mixed>|null $validationWitness */
    private function assertCanonicalEvent(
        string $naturalIdentity,
        PaperMarketEvent $event,
        ?array $validationWitness,
    ): void {
        try {
            $expected = match ($event->channel) {
                PaperMarketDataChannel::CANDLE_1M,
                PaperMarketDataChannel::CANDLE_5M,
                PaperMarketDataChannel::CANDLE_15M,
                PaperMarketDataChannel::CANDLE_1H => $this->canonicalCandleIdentity(
                    $event,
                    $validationWitness,
                ),
                PaperMarketDataChannel::TOP_OF_BOOK => $this->canonicalBookIdentity(
                    $naturalIdentity,
                    $event,
                    $validationWitness,
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

    /** @param array<array-key, mixed>|null $validationWitness */
    private function canonicalCandleIdentity(
        PaperMarketEvent $event,
        ?array $validationWitness,
    ): string
    {
        if ($validationWitness !== null) {
            throw new \InvalidArgumentException();
        }
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

        $open = self::canonicalDecimal($payload['open'] ?? null, positive: true);
        $high = self::canonicalDecimal($payload['high'] ?? null, positive: true);
        $low = self::canonicalDecimal($payload['low'] ?? null, positive: true);
        $close = self::canonicalDecimal($payload['close'] ?? null, positive: true);
        self::canonicalDecimal($payload['volume'] ?? null, positive: false);
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

    /** @param array<array-key, mixed>|null $validationWitness */
    private function canonicalBookIdentity(
        string $naturalIdentity,
        PaperMarketEvent $event,
        ?array $validationWitness,
    ): string
    {
        if ($validationWitness === null || array_is_list($validationWitness)) {
            throw new \InvalidArgumentException();
        }
        self::assertExactKeys($validationWitness, self::MODEL_WITNESS_KEYS);
        $sourceCandle = $validationWitness['source_candle'] ?? null;
        if (!\is_array($sourceCandle) || array_is_list($sourceCandle)) {
            throw new \InvalidArgumentException();
        }
        self::assertExactKeys($sourceCandle, self::SOURCE_CANDLE_WITNESS_KEYS);

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

        $candle = HyperliquidCandle::fromApiRow([
            'T' => $this->nonnegativeInt($sourceCandle['close_time'] ?? null),
            'c' => $sourceCandle['close'] ?? null,
            'h' => $sourceCandle['high'] ?? null,
            'i' => $sourceCandle['interval'] ?? null,
            'l' => $sourceCandle['low'] ?? null,
            'n' => $this->nonnegativeInt($sourceCandle['trade_count'] ?? null),
            'o' => $sourceCandle['open'] ?? null,
            's' => $sourceCandle['native_symbol'] ?? null,
            't' => $this->nonnegativeInt($sourceCandle['start_time'] ?? null),
            'v' => $sourceCandle['volume'] ?? null,
        ], $coin, $interval);
        if ($candle->coin !== $coin
            || $candle->interval !== $interval
            || (string) $candle->startTime !== $startTime
            || (string) $candle->closeTime !== $closeTime
            || (string) $candle->startTime !== $payload['source_candle_start']
        ) {
            throw new \InvalidArgumentException();
        }
        $this->assertEventTimestamps($event, $closeTime);

        if (($payload['bid_size'] ?? null) !== ($payload['ask_size'] ?? null)) {
            throw new \InvalidArgumentException();
        }
        $validatedWitness = self::modelValidationWitness($candle, [
            'bid' => $payload['bid_price'] ?? null,
            'ask' => $payload['ask_price'] ?? null,
            'size' => $payload['bid_size'] ?? null,
            'spread_bps' => $validationWitness['spread_bps'] ?? null,
            'atr' => $validationWitness['atr'] ?? null,
            'model_name' => $payload['model_name'],
            'model_version' => $payload['model_version'],
        ]);
        if (CanonicalJson::encode($validatedWitness) !== CanonicalJson::encode($validationWitness)) {
            throw new \InvalidArgumentException();
        }

        return implode('|', $identityParts);
    }

    private function nonnegativeInt(mixed $value): int
    {
        if (!\is_string($value)
            || !$this->unsignedInteger($value)
            || BigInteger::of($value)->isGreaterThan(\PHP_INT_MAX)
        ) {
            throw new \InvalidArgumentException();
        }

        return (int) $value;
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

    private static function canonicalDecimal(mixed $value, bool $positive): BigDecimal
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

    private static function canonical(BigDecimal $value): string
    {
        return (string) $value->stripTrailingZeros();
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
