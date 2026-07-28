<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperSourceOrdinal::class)]
final class HyperliquidPaperSourceOrdinalTest extends TestCase
{
    private const MAINNET_CANDLE_SCOPE = 'mainnet/hyperliquid/BTCUSDT/candle_1m';

    public function testPreviewIsNonMutatingAndCommitProducesDenseNetworkScopedOrdinals(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $timestamp = new \DateTimeImmutable('2026-07-21T01:00:59.999000Z');
        $payload = $this->candlePayload(0, 59_999);
        $naturalIdentity = 'BTC|1m|0|59999';
        $digest = HyperliquidPaperSourceOrdinal::assignmentDigest(
            $naturalIdentity,
            $timestamp,
            $payload,
        );

        self::assertSame([
            'sequence' => '1',
            'replayed' => false,
            'event' => null,
        ], $ordinals->preview(self::MAINNET_CANDLE_SCOPE, $naturalIdentity, $digest));
        self::assertSame([
            'sequence' => '1',
            'replayed' => false,
            'event' => null,
        ], $ordinals->preview(self::MAINNET_CANDLE_SCOPE, $naturalIdentity, $digest));
        self::assertSame(['schema_version' => 1, 'scopes' => []], $ordinals->snapshot());

        $first = $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::MAINNET,
            'BTCUSDT',
            0,
        );
        $second = $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::MAINNET,
            'BTCUSDT',
            60_000,
        );
        $otherNetwork = $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::TESTNET,
            'BTCUSDT',
            0,
        );
        $otherSymbol = $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::MAINNET,
            'ETHUSDT',
            0,
        );

        self::assertSame('1', $first->sequence);
        self::assertSame('2', $second->sequence);
        self::assertSame('1', $otherNetwork->sequence);
        self::assertSame('1', $otherSymbol->sequence);
    }

    public function testLatestIdenticalAssignmentReplaysTheExactEventAndConflictFailsClosed(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $event = $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::MAINNET,
            'BTCUSDT',
            0,
        );
        $payload = $event->payload;
        $naturalIdentity = 'BTC|1m|0|59999';
        $digest = HyperliquidPaperSourceOrdinal::assignmentDigest(
            $naturalIdentity,
            $event->exchangeTimestamp,
            $payload,
        );

        self::assertSame([
            'sequence' => '1',
            'replayed' => true,
            'event' => $event,
        ], $ordinals->preview(self::MAINNET_CANDLE_SCOPE, $naturalIdentity, $digest));

        try {
            $ordinals->preview(
                self::MAINNET_CANDLE_SCOPE,
                $naturalIdentity,
                str_repeat('0', 64),
            );
            self::fail('A natural identity cannot be reassigned to another digest.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_natural_identity_conflict', $exception->getMessage());
        }

        self::assertSame('2', $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::MAINNET,
            'BTCUSDT',
            60_000,
        )->sequence);
    }

    public function testAssignmentDigestIsCanonicalDeterministicAndInputSensitive(): void
    {
        $timestamp = new \DateTimeImmutable('2026-07-21T03:00:59.999000+02:00');
        $identity = 'BTC|1m|0|59999';

        $first = HyperliquidPaperSourceOrdinal::assignmentDigest(
            $identity,
            $timestamp,
            ['z' => '2', 'a' => ['y' => '4', 'x' => '3']],
        );
        $reordered = HyperliquidPaperSourceOrdinal::assignmentDigest(
            $identity,
            $timestamp,
            ['a' => ['x' => '3', 'y' => '4'], 'z' => '2'],
        );

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $first);
        self::assertSame($first, $reordered);
        self::assertNotSame(
            $first,
            HyperliquidPaperSourceOrdinal::assignmentDigest(
                'BTC|1m|60000|119999',
                $timestamp,
                ['a' => ['x' => '3', 'y' => '4'], 'z' => '2'],
            ),
        );
    }

    public function testSnapshotHasExactSortedBoundedAndJsonSafeShape(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::TESTNET,
            'ETHUSDT',
            0,
        );
        for ($index = 0; $index < 100; ++$index) {
            $this->commitCandle(
                $ordinals,
                PaperMarketDataNetwork::MAINNET,
                'BTCUSDT',
                $index * 60_000,
            );
        }

        $snapshot = $ordinals->snapshot();

        self::assertSame(['schema_version', 'scopes'], array_keys($snapshot));
        self::assertSame([
            'mainnet/hyperliquid/BTCUSDT/candle_1m',
            'testnet/hyperliquid/ETHUSDT/candle_1m',
        ], array_keys($snapshot['scopes']));
        self::assertSame(
            ['last_sequence', 'latest'],
            array_keys($snapshot['scopes'][self::MAINNET_CANDLE_SCOPE]),
        );
        self::assertSame('100', $snapshot['scopes'][self::MAINNET_CANDLE_SCOPE]['last_sequence']);
        self::assertSame(
            'BTC|1m|5940000|5999999',
            $snapshot['scopes'][self::MAINNET_CANDLE_SCOPE]['latest']['natural_identity'],
        );
        self::assertStringNotContainsString(
            'BTC|1m|0|59999',
            json_encode($snapshot, \JSON_THROW_ON_ERROR),
        );
        self::assertJson(json_encode($snapshot, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(
            'authorization',
            strtolower(json_encode($snapshot, \JSON_THROW_ON_ERROR)),
        );
    }

    public function testRestoreReplaysLatestAndContinuesWithTheByteIdenticalNextSequence(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $latest = $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::MAINNET,
            'BTCUSDT',
            0,
        );
        $restored = HyperliquidPaperSourceOrdinal::restore($ordinals->snapshot());

        $digest = HyperliquidPaperSourceOrdinal::assignmentDigest(
            'BTC|1m|0|59999',
            $latest->exchangeTimestamp,
            $latest->payload,
        );
        $replay = $restored->preview(
            self::MAINNET_CANDLE_SCOPE,
            'BTC|1m|0|59999',
            $digest,
        );

        self::assertTrue($replay['replayed']);
        self::assertSame($latest->toArray(), $replay['event']?->toArray());
        self::assertSame('2', $this->commitCandle(
            $restored,
            PaperMarketDataNetwork::MAINNET,
            'BTCUSDT',
            60_000,
        )->sequence);
    }

    public function testRestoreRejectsCorruptUnknownExtraListAndScalarState(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $this->commitCandle(
            $ordinals,
            PaperMarketDataNetwork::MAINNET,
            'BTCUSDT',
            0,
        );
        $valid = $ordinals->snapshot();

        $cases = [];
        $cases['root extra key'] = $valid + ['extra' => true];
        $cases['wrong schema'] = ['schema_version' => 2, 'scopes' => $valid['scopes']];
        $cases['scalar scopes'] = ['schema_version' => 1, 'scopes' => 'secret'];
        $cases['list scopes'] = ['schema_version' => 1, 'scopes' => [$valid['scopes']]];

        $wrongScope = $valid;
        $wrongScope['scopes']['mainnet/hyperliquid/DOGEUSDT/candle_1m']
            = $wrongScope['scopes'][self::MAINNET_CANDLE_SCOPE];
        unset($wrongScope['scopes'][self::MAINNET_CANDLE_SCOPE]);
        $cases['wrong scope'] = $wrongScope;

        $extraScopeState = $valid;
        $extraScopeState['scopes'][self::MAINNET_CANDLE_SCOPE]['extra'] = null;
        $cases['extra scope key'] = $extraScopeState;

        $listScopeState = $valid;
        $listScopeState['scopes'][self::MAINNET_CANDLE_SCOPE] = ['1', null];
        $cases['list scope state'] = $listScopeState;

        $latestNull = $valid;
        $latestNull['scopes'][self::MAINNET_CANDLE_SCOPE]['latest'] = null;
        $cases['missing latest'] = $latestNull;

        $extraLatest = $valid;
        $extraLatest['scopes'][self::MAINNET_CANDLE_SCOPE]['latest']['extra'] = 'secret';
        $cases['extra latest key'] = $extraLatest;

        $badDigest = $valid;
        $badDigest['scopes'][self::MAINNET_CANDLE_SCOPE]['latest']['assignment_digest']
            = str_repeat('0', 64);
        $cases['digest mismatch'] = $badDigest;

        $badIdentity = $valid;
        $badIdentity['scopes'][self::MAINNET_CANDLE_SCOPE]['latest']['natural_identity']
            = 'BTC|1m|60000|119999';
        $badIdentity['scopes'][self::MAINNET_CANDLE_SCOPE]['latest']['assignment_digest']
            = HyperliquidPaperSourceOrdinal::assignmentDigest(
                'BTC|1m|60000|119999',
                $this->eventFrom($badIdentity)->exchangeTimestamp,
                $this->eventFrom($badIdentity)->payload,
            );
        $cases['semantic natural identity mismatch'] = $badIdentity;

        $badSequence = $valid;
        $badSequence['scopes'][self::MAINNET_CANDLE_SCOPE]['last_sequence'] = '2';
        $cases['sequence mismatch'] = $badSequence;

        $zeroSequence = $valid;
        $zeroSequence['scopes'][self::MAINNET_CANDLE_SCOPE]['last_sequence'] = '0';
        $cases['nonpositive sequence'] = $zeroSequence;

        $oversizeSequence = $valid;
        $oversizeSequence['scopes'][self::MAINNET_CANDLE_SCOPE]['last_sequence']
            = str_repeat('9', PaperMarketEvent::MAX_SEQUENCE_DIGITS + 1);
        $cases['oversize sequence'] = $oversizeSequence;

        foreach ($cases as $label => $state) {
            try {
                /** @var array<string, mixed> $state */
                HyperliquidPaperSourceOrdinal::restore($state);
                self::fail('Expected invalid ordinal state rejection for ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'hyperliquid_paper_source_ordinal_state_invalid',
                    $exception->getMessage(),
                    $label,
                );
            }
        }
    }

    public function testRestoreRejectsValidEventsWithWrongNetworkVenueSymbolOrChannel(): void
    {
        $timestamp = new \DateTimeImmutable('1970-01-01T00:00:59.999000Z');
        $payload = $this->candlePayload(0, 59_999);
        $identity = 'BTC|1m|0|59999';

        foreach ([
            'wrong network' => [
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'BTCUSDT',
                PaperMarketDataChannel::CANDLE_1M,
            ],
            'wrong venue' => [
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                'BTCUSDT',
                PaperMarketDataChannel::CANDLE_1M,
            ],
            'wrong symbol' => [
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'ETHUSDT',
                PaperMarketDataChannel::CANDLE_1M,
            ],
            'wrong channel' => [
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'BTCUSDT',
                PaperMarketDataChannel::CANDLE_5M,
            ],
        ] as $label => [$network, $venue, $symbol, $channel]) {
            $event = PaperMarketEvent::create(
                $network,
                $venue,
                $symbol,
                $channel,
                $timestamp,
                $timestamp,
                '1',
                $payload,
            );
            $state = [
                'schema_version' => 1,
                'scopes' => [
                    self::MAINNET_CANDLE_SCOPE => [
                        'last_sequence' => '1',
                        'latest' => [
                            'natural_identity' => $identity,
                            'assignment_digest' => HyperliquidPaperSourceOrdinal::assignmentDigest(
                                $identity,
                                $event->exchangeTimestamp,
                                $event->payload,
                            ),
                            'event' => $event->toArray(),
                        ],
                    ],
                ],
            ];

            try {
                HyperliquidPaperSourceOrdinal::restore($state);
                self::fail('Expected event scope rejection for ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'hyperliquid_paper_source_ordinal_state_invalid',
                    $exception->getMessage(),
                    $label,
                );
            }
        }
    }

    public function testRestoreBoundsScopesAndSequenceThenPreviewDetectsExhaustion(): void
    {
        $tooMany = ['schema_version' => 1, 'scopes' => []];
        for ($index = 0; $index < 21; ++$index) {
            $tooMany['scopes']['invalid-' . $index] = [];
        }

        try {
            HyperliquidPaperSourceOrdinal::restore($tooMany);
            self::fail('A snapshot cannot exceed the finite network/symbol/channel scope count.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hyperliquid_paper_source_ordinal_state_invalid', $exception->getMessage());
        }

        $maximum = str_repeat('9', PaperMarketEvent::MAX_SEQUENCE_DIGITS);
        $timestamp = new \DateTimeImmutable('1970-01-01T00:00:59.999000Z');
        $payload = $this->candlePayload(0, 59_999);
        $identity = 'BTC|1m|0|59999';
        $event = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            $timestamp,
            $timestamp,
            $maximum,
            $payload,
        );
        $restored = HyperliquidPaperSourceOrdinal::restore([
            'schema_version' => 1,
            'scopes' => [
                self::MAINNET_CANDLE_SCOPE => [
                    'last_sequence' => $maximum,
                    'latest' => [
                        'natural_identity' => $identity,
                        'assignment_digest' => HyperliquidPaperSourceOrdinal::assignmentDigest(
                            $identity,
                            $timestamp,
                            $payload,
                        ),
                        'event' => $event->toArray(),
                    ],
                ],
            ],
        ]);

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessage('hyperliquid_paper_source_ordinal_exhausted');
        $restored->preview(
            self::MAINNET_CANDLE_SCOPE,
            'BTC|1m|60000|119999',
            str_repeat('1', 64),
        );
    }

    public function testInvalidScopeAndAssignmentsFailWithStableCodes(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        foreach ([
            'mainnet/hyperliquid/BTCUSDT/public_trade',
            'legacy_unknown/hyperliquid/BTCUSDT/candle_1m',
            'mainnet/okx/BTCUSDT/candle_1m',
            'mainnet/hyperliquid/DOGEUSDT/candle_1m',
            'mainnet/hyperliquid/BTCUSDT',
        ] as $scope) {
            try {
                $ordinals->preview($scope, 'BTC|1m|0|59999', str_repeat('1', 64));
                self::fail('Expected invalid scope rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'hyperliquid_paper_source_ordinal_scope_invalid',
                    $exception->getMessage(),
                );
            }
        }

        foreach ([
            ['', str_repeat('1', 64)],
            [str_repeat('x', 1_025), str_repeat('1', 64)],
            ["BTC|1m|0|\n", str_repeat('1', 64)],
            ['BTC|1m|0|59999', 'ABC'],
        ] as [$identity, $digest]) {
            try {
                $ordinals->preview(self::MAINNET_CANDLE_SCOPE, $identity, $digest);
                self::fail('Expected invalid assignment rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'hyperliquid_paper_source_ordinal_assignment_invalid',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testFailedEventConstructionAndInvalidCommitNeverConsumeThePreviewedSequence(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $timestamp = new \DateTimeImmutable('1970-01-01T00:00:59.999000Z');
        $identity = 'BTC|1m|0|59999';
        $payload = $this->candlePayload(0, 59_999);
        $digest = HyperliquidPaperSourceOrdinal::assignmentDigest($identity, $timestamp, $payload);
        $assignment = $ordinals->preview(self::MAINNET_CANDLE_SCOPE, $identity, $digest);

        try {
            PaperMarketEvent::create(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'BTCUSDT',
                PaperMarketDataChannel::CANDLE_1M,
                $timestamp,
                $timestamp,
                $assignment['sequence'],
                ['password' => 'must-never-enter-an-event'],
            );
            self::fail('Sensitive event construction must fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame(['schema_version' => 1, 'scopes' => []], $ordinals->snapshot());
        }

        $event = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            $timestamp,
            $timestamp,
            $assignment['sequence'],
            $payload,
        );
        try {
            $ordinals->commit(
                self::MAINNET_CANDLE_SCOPE,
                $identity,
                str_repeat('0', 64),
                $event,
            );
            self::fail('A mismatched assignment transaction must fail.');
        } catch (\LogicException $exception) {
            self::assertSame(
                'hyperliquid_paper_source_ordinal_transaction_invalid',
                $exception->getMessage(),
            );
        }

        self::assertSame(['schema_version' => 1, 'scopes' => []], $ordinals->snapshot());
        $ordinals->commit(self::MAINNET_CANDLE_SCOPE, $identity, $digest, $event);
        self::assertSame('1', $event->sequence);
    }

    private function commitCandle(
        HyperliquidPaperSourceOrdinal $ordinals,
        PaperMarketDataNetwork $network,
        string $symbol,
        int $startTime,
    ): PaperMarketEvent {
        $closeTime = $startTime + 59_999;
        $nativeCoin = $symbol === 'BTCUSDT' ? 'BTC' : 'ETH';
        $identity = implode('|', [
            $nativeCoin,
            '1m',
            (string) $startTime,
            (string) $closeTime,
        ]);
        $payload = $this->candlePayload($startTime, $closeTime, $nativeCoin);
        $timestamp = $this->timestamp($closeTime);
        $scope = implode('/', [
            $network->value,
            PaperMarketDataVenue::HYPERLIQUID->value,
            $symbol,
            PaperMarketDataChannel::CANDLE_1M->value,
        ]);
        $digest = HyperliquidPaperSourceOrdinal::assignmentDigest(
            $identity,
            $timestamp,
            $payload,
        );
        $assignment = $ordinals->preview($scope, $identity, $digest);
        self::assertFalse($assignment['replayed']);
        $event = PaperMarketEvent::create(
            $network,
            PaperMarketDataVenue::HYPERLIQUID,
            $symbol,
            PaperMarketDataChannel::CANDLE_1M,
            $timestamp,
            $timestamp,
            $assignment['sequence'],
            $payload,
        );
        $ordinals->commit($scope, $identity, $digest, $event);

        return $event;
    }

    /** @return array<string, string|bool> */
    private function candlePayload(int $startTime, int $closeTime, string $coin = 'BTC'): array
    {
        return [
            'native_symbol' => $coin,
            'interval' => '1m',
            'start_time' => (string) $startTime,
            'close_time' => (string) $closeTime,
            'open' => '100',
            'high' => '101',
            'low' => '99',
            'close' => '100',
            'volume' => '5',
            'trade_count' => '10',
            'confirmed' => true,
            'origin' => 'rest_candle_snapshot',
        ];
    }

    private function timestamp(int $milliseconds): \DateTimeImmutable
    {
        $seconds = intdiv($milliseconds, 1_000);
        $microseconds = ($milliseconds % 1_000) * 1_000;
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!U.u',
            $seconds . '.' . sprintf('%06d', $microseconds),
            new \DateTimeZone('UTC'),
        );
        self::assertInstanceOf(\DateTimeImmutable::class, $timestamp);

        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $state */
    private function eventFrom(array $state): PaperMarketEvent
    {
        $event = $state['scopes'][self::MAINNET_CANDLE_SCOPE]['latest']['event'] ?? null;
        self::assertIsArray($event);

        return PaperMarketEvent::fromArray($event);
    }
}
