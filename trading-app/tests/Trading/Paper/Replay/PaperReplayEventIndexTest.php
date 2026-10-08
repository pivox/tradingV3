<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Replay;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayEventIndex;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperReplayEventIndex::class)]
final class PaperReplayEventIndexTest extends TestCase
{
    private const TIMESTAMPS = [
        '1969-12-31T23:59:58.999999Z',
        '1969-12-31T23:59:59.000000Z',
        '1969-12-31T23:59:59.500000Z',
        '1970-01-01T00:00:00.000000Z',
        '1970-01-01T00:00:00.000001Z',
        '2026-09-30T14:33:38.960698Z',
        '2026-09-30T14:33:38.960699Z',
        '2026-09-30T14:33:39.000000Z',
        '2038-01-19T03:14:08.000000Z',
    ];

    private const SEQUENCES = [
        null,
        '0',
        '00',
        '000000',
        '1',
        '01',
        '7',
        '007',
        '9',
        '10',
        '010',
        '99',
        '100',
        '4294967296',
        '9223372036854775807',
        '9223372036854775808',
        '18446744073709551616',
    ];

    public function testBusinessKeyOrderIsExactlyTheReplayComparatorOrder(): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(20261001));
        $events = [];
        for ($index = 0; $index < 1500; ++$index) {
            $sequence = self::SEQUENCES[$random->getInt(0, \count(self::SEQUENCES) - 1)];
            if ($sequence !== null && $random->getInt(0, 9) === 0) {
                $sequence = str_repeat('0', $random->getInt(0, 3))
                    . $random->getInt(1, 9) . str_repeat((string) $random->getInt(0, 9), $random->getInt(0, 124));
            }
            $events[] = $this->event(
                $random->getInt(0, 1) === 0 ? 'BTCUSDT' : 'ETHUSDT',
                PaperMarketDataChannel::cases()[$random->getInt(0, \count(PaperMarketDataChannel::cases()) - 1)],
                $sequence,
                self::TIMESTAMPS[$random->getInt(0, \count(self::TIMESTAMPS) - 1)],
                // Duplicate identities are rejected by the verifier; the input position still breaks ties.
                ['n' => (string) $random->getInt(0, 900)],
            );
        }

        $index = $this->index($events, false);
        $expected = self::oracleOrder($events, self::formerBusinessComparator(...));

        self::assertSame($expected, $this->indexedOrder($index, $events));
    }

    public function testHyperliquidHistoricalKeyOrderIsExactlyTheHistoricalComparatorOrder(): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(20261002));
        $intervals = [60_000, 300_000, 900_000, 3_600_000, 0, -1, PHP_INT_MAX, PHP_INT_MIN];
        $events = [];
        /** @var array<string, int> $intervalsByEventId the former reader keyed intervals by event id */
        $intervalsByEventId = [];
        for ($index = 0; $index < 1500; ++$index) {
            $event = $this->event(
                $random->getInt(0, 1) === 0 ? 'BTCUSDT' : 'ETHUSDT',
                PaperMarketDataChannel::cases()[$random->getInt(0, \count(PaperMarketDataChannel::cases()) - 1)],
                self::SEQUENCES[$random->getInt(0, \count(self::SEQUENCES) - 1)],
                self::TIMESTAMPS[$random->getInt(0, \count(self::TIMESTAMPS) - 1)],
                ['n' => (string) $random->getInt(0, 900)],
            );
            $intervalsByEventId[$event->eventId] ??= $intervals[$random->getInt(0, \count($intervals) - 1)];
            $events[] = $event;
        }

        $index = $this->index($events, true, array_map(
            static fn (PaperMarketEvent $event): int => $intervalsByEventId[$event->eventId],
            $events,
        ));
        $expected = self::oracleOrder(
            $events,
            static fn (array $left, array $right): int => self::compareHyperliquidHistorical(
                $left,
                $right,
                $intervalsByEventId,
            ),
        );

        self::assertSame($expected, $this->indexedOrder($index, $events));
    }

    public function testMaterializesTheValidatedEventsExactlyEvenWithALossyFloatPrecisionSetting(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', self::TIMESTAMPS[5], [
                'price' => 0.1 + 0.2,
                'size' => 1.0E-7,
                'nested' => ['list' => [1, 2.5, 'x', true, null]],
            ]),
            $this->event('ETHUSDT', PaperMarketDataChannel::TOP_OF_BOOK, null, self::TIMESTAMPS[6]),
        ];
        $previous = ini_set('serialize_precision', '10');
        self::assertNotFalse($previous);
        try {
            // Precondition: plain serialize() would lose this double under the host setting.
            self::assertSame('d:0.3;', serialize(0.1 + 0.2));
            $index = $this->index($events, false);
            $materialized = [$index->event(0), $index->event(1)];
            self::assertSame('10', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', $previous);
        }

        foreach ($events as $position => $event) {
            self::assertSame($event->toArray(), $materialized[$position]->toArray());
            self::assertSame(
                CanonicalJson::encode($event->toArray()),
                CanonicalJson::encode($materialized[$position]->toArray()),
            );
            self::assertSame($event->channel, $materialized[$position]->channel);
            self::assertEquals($event->receivedTimestamp, $materialized[$position]->receivedTimestamp);
        }
    }

    public function testRejectsASpillRecordThatNoLongerMatchesItsDigest(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', self::TIMESTAMPS[5]),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', self::TIMESTAMPS[6]),
        ];
        $filesystem = new SpillReadTamperingFilesystem();
        $index = $this->index($events, false, null, $filesystem);

        self::assertSame($events[0]->toArray(), $index->event(0)->toArray());
        $filesystem->tamper = true;
        try {
            $index->event(1);
            self::fail('A spill record that differs from its digest must not be materialized.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_spill_corrupt', $exception->getMessage());
        }
    }

    public function testFailsClosedWhenTheSpillCannotBeWrittenOrRead(): void
    {
        $event = $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', self::TIMESTAMPS[5]);
        foreach (['paper_replay_spill_write', 'paper_replay_spill_read'] as $operation) {
            $filesystem = new SpillFailingFilesystem($operation);
            try {
                $index = $this->index([$event], false, null, $filesystem);
                $index->event(0);
                self::fail('A spill ' . $operation . ' failure must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame('paper_replay_spill_failed', $exception->getMessage(), $operation);
            }
        }
    }

    public function testRejectsUseBeforeSortAndAfterClose(): void
    {
        $event = $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', self::TIMESTAMPS[5]);
        $spill = tmpfile();
        self::assertIsResource($spill);
        $index = new PaperReplayEventIndex($spill, false, new PaperDatasetRecorderFilesystem());
        $index->append($event);

        foreach ([
            static fn (): PaperMarketEvent => $index->event(0),
            static fn (): ?int => $index->positionOf($event->eventId),
        ] as $operation) {
            try {
                $operation();
                self::fail('An unsorted index must not be read.');
            } catch (\LogicException $exception) {
                self::assertSame('paper_replay_event_index_invalid', $exception->getMessage());
            }
        }

        $index->sort();
        self::assertSame(0, $index->positionOf($event->eventId));
        self::assertNull($index->positionOf(str_repeat('f', 64)));
        $index->close();
        self::assertSame(0, $index->count());
        self::assertFalse(\is_resource($spill), 'Closing the index must release its spill file.');
        $this->expectException(\LogicException::class);
        $index->event(0);
    }

    /**
     * @param list<PaperMarketEvent> $events
     * @param list<int>|null         $intervals
     */
    private function index(
        array $events,
        bool $hyperliquidHistorical,
        ?array $intervals = null,
        ?PaperDatasetRecorderFilesystem $filesystem = null,
    ): PaperReplayEventIndex {
        $spill = tmpfile();
        self::assertIsResource($spill);
        $index = new PaperReplayEventIndex(
            $spill,
            $hyperliquidHistorical,
            $filesystem ?? new PaperDatasetRecorderFilesystem(),
        );
        foreach ($events as $position => $event) {
            $index->append($event, $intervals[$position] ?? null);
        }
        $index->sort();

        return $index;
    }

    /**
     * Sorted input positions, identified through each materialized event.
     *
     * @param list<PaperMarketEvent> $events
     *
     * @return list<int>
     */
    private function indexedOrder(PaperReplayEventIndex $index, array $events): array
    {
        self::assertSame(\count($events), $index->count());
        $remaining = [];
        foreach ($events as $position => $event) {
            $remaining[CanonicalJson::encode($event->toArray())][] = $position;
        }
        $order = [];
        for ($position = 0; $position < $index->count(); ++$position) {
            $encoded = CanonicalJson::encode($index->event($position)->toArray());
            self::assertArrayHasKey($encoded, $remaining);
            // Identical events keep their input order (the spill offset is the final key field).
            $inputPosition = array_shift($remaining[$encoded]);
            self::assertIsInt($inputPosition, 'Unexpected duplicate.');
            $order[] = $inputPosition;
        }

        return $order;
    }

    /**
     * @param list<PaperMarketEvent>                                                     $events
     * @param callable(array{event: PaperMarketEvent, input_index: int}, array{event: PaperMarketEvent, input_index: int}): int $comparator
     *
     * @return list<int>
     */
    private static function oracleOrder(array $events, callable $comparator): array
    {
        $entries = [];
        foreach ($events as $position => $event) {
            $entries[] = ['event' => $event, 'input_index' => $position];
        }
        usort($entries, $comparator);

        return array_map(static fn (array $entry): int => $entry['input_index'], $entries);
    }

    /**
     * The pre-streaming PaperReplayReader::compare(), kept verbatim as the oracle.
     *
     * @param array{event: PaperMarketEvent, input_index: int} $left
     * @param array{event: PaperMarketEvent, input_index: int} $right
     */
    public static function formerBusinessComparator(array $left, array $right): int
    {
        $leftEvent = $left['event'];
        $rightEvent = $right['event'];

        $comparison = $leftEvent->exchangeTimestamp <=> $rightEvent->exchangeTimestamp;
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp($leftEvent->channel->value, $rightEvent->channel->value);
        if ($comparison !== 0) {
            return $comparison;
        }

        if ($leftEvent->sequence === null || $rightEvent->sequence === null) {
            if ($leftEvent->sequence !== $rightEvent->sequence) {
                return $leftEvent->sequence === null ? 1 : -1;
            }
        } else {
            $comparison = BigInteger::of($leftEvent->sequence)->compareTo(BigInteger::of($rightEvent->sequence));
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        $comparison = strcmp($leftEvent->eventId, $rightEvent->eventId);
        if ($comparison !== 0) {
            return $comparison;
        }

        return $left['input_index'] <=> $right['input_index'];
    }

    /**
     * The pre-streaming PaperReplayReader::compareHyperliquidHistorical(), kept verbatim as the oracle.
     *
     * @param array{event: PaperMarketEvent, input_index: int} $left
     * @param array{event: PaperMarketEvent, input_index: int} $right
     * @param array<string, int>                                $intervals
     */
    private static function compareHyperliquidHistorical(array $left, array $right, array $intervals): int
    {
        $leftEvent = $left['event'];
        $rightEvent = $right['event'];

        $comparison = $leftEvent->exchangeTimestamp <=> $rightEvent->exchangeTimestamp;
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp($leftEvent->symbol, $rightEvent->symbol);
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = $intervals[$leftEvent->eventId]
            <=> $intervals[$rightEvent->eventId];
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp($leftEvent->channel->value, $rightEvent->channel->value);
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp($leftEvent->eventId, $rightEvent->eventId);
        if ($comparison !== 0) {
            return $comparison;
        }

        return $left['input_index'] <=> $right['input_index'];
    }

    /** @param array<string, mixed> $payload */
    private function event(
        string $symbol,
        PaperMarketDataChannel $channel,
        ?string $sequence,
        string $exchangeTimestamp,
        array $payload = ['price' => '30000.0'],
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            $symbol,
            $channel,
            new \DateTimeImmutable($exchangeTimestamp),
            (new \DateTimeImmutable($exchangeTimestamp))->modify('+1 second'),
            $sequence,
            $payload,
        );
    }
}

final class SpillReadTamperingFilesystem extends PaperDatasetRecorderFilesystem
{
    public bool $tamper = false;

    /** @param resource $handle */
    public function read($handle, int $length, string $operation): string|false
    {
        $chunk = parent::read($handle, $length, $operation);
        if ($this->tamper && $operation === 'paper_replay_spill_read' && \is_string($chunk) && $chunk !== '') {
            $chunk[\strlen($chunk) - 2] = $chunk[\strlen($chunk) - 2] === '1' ? '2' : '1';
        }

        return $chunk;
    }
}

final class SpillFailingFilesystem extends PaperDatasetRecorderFilesystem
{
    public function __construct(private readonly string $failingOperation)
    {
    }

    /** @param resource $handle */
    public function write($handle, #[\SensitiveParameter] string $contents, string $operation): int|false
    {
        return $operation === $this->failingOperation ? false : parent::write($handle, $contents, $operation);
    }

    /** @param resource $handle */
    public function read($handle, int $length, string $operation): string|false
    {
        return $operation === $this->failingOperation ? false : parent::read($handle, $length, $operation);
    }

    /** @return resource|false */
    public function createPrivateFile(#[\SensitiveParameter] string $path, string $operation)
    {
        return $operation === $this->failingOperation ? false : parent::createPrivateFile($path, $operation);
    }
}
