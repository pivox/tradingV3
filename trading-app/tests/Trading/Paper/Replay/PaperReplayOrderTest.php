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
use App\Trading\Paper\Replay\PaperReplayOrder;
use Brick\Math\BigInteger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** #132 decision a: a candle is available at its close; the trigger candle comes last at its instant. */
#[CoversClass(PaperReplayOrder::class)]
#[CoversClass(PaperReplayEventIndex::class)]
final class PaperReplayOrderTest extends TestCase
{
    public function testACandleIsAvailableAtItsCloseOnEveryVenue(): void
    {
        $okx = $this->event(PaperMarketDataVenue::OKX, PaperMarketDataChannel::CANDLE_5M, '2026-08-20T11:55:00.000000Z', '2026-08-20T12:00:06.000000Z');
        $hyperliquid = $this->event(PaperMarketDataVenue::HYPERLIQUID, PaperMarketDataChannel::CANDLE_1H, '2026-08-20T11:59:59.999000Z', '2026-08-20T12:00:00.400000Z');
        $book = $this->event(PaperMarketDataVenue::OKX, PaperMarketDataChannel::TOP_OF_BOOK, '2026-08-20T11:59:59.900000Z', '2026-08-20T12:00:00.200000Z');
        $order = PaperReplayOrder::candleAvailability(PaperMarketDataChannel::CANDLE_1M);

        self::assertEquals(new \DateTimeImmutable('2026-08-20T12:00:00Z'), PaperReplayOrder::candleClose($okx));
        self::assertEquals(new \DateTimeImmutable('2026-08-20T12:00:00Z'), PaperReplayOrder::candleClose($hyperliquid));
        self::assertNull(PaperReplayOrder::candleClose($book));
        self::assertEquals(new \DateTimeImmutable('2026-08-20T12:00:00Z'), PaperReplayOrder::availableAt($okx));
        self::assertEquals($book->receivedTimestamp, PaperReplayOrder::availableAt($book));

        foreach ([$okx, $hyperliquid] as $candle) {
            self::assertEquals(new \DateTimeImmutable('2026-08-20T12:00:00Z'), $order->orderTimestamp($candle));
            self::assertEquals(new \DateTimeImmutable('2026-08-20T12:00:00Z'), $order->observationTimestamp($candle));
        }
        self::assertEquals($book->exchangeTimestamp, $order->orderTimestamp($book));
        self::assertEquals($book->receivedTimestamp, $order->observationTimestamp($book));
    }

    public function testTheExchangeTimeOrderKeepsTheFormerPositionsAndObservations(): void
    {
        $order = PaperReplayOrder::exchangeTime();
        $candle = $this->event(PaperMarketDataVenue::OKX, PaperMarketDataChannel::CANDLE_1M, '2026-08-20T11:59:00.000000Z', '2026-08-20T12:00:06.000000Z');
        $book = $this->event(PaperMarketDataVenue::OKX, PaperMarketDataChannel::TOP_OF_BOOK, '2026-08-20T12:00:01.000000Z', '2026-08-20T12:00:00.500000Z');

        self::assertFalse($order->candlesAtClose);
        self::assertFalse($order->isTrigger($candle));
        self::assertEquals($candle->exchangeTimestamp, $order->orderTimestamp($candle));
        self::assertEquals($candle->receivedTimestamp, $order->observationTimestamp($candle));
        self::assertEquals($book->exchangeTimestamp, $order->observationTimestamp($book));
    }

    public function testOnlyACandleChannelCanTrigger(): void
    {
        self::assertNull(PaperReplayOrder::candleAvailability(null)->triggerChannel);
        $order = PaperReplayOrder::candleAvailability(PaperMarketDataChannel::CANDLE_15M);
        self::assertTrue($order->isTrigger($this->event(PaperMarketDataVenue::OKX, PaperMarketDataChannel::CANDLE_15M, '2026-08-20T11:45:00.000000Z', '2026-08-20T12:00:01.000000Z')));
        self::assertFalse($order->isTrigger($this->event(PaperMarketDataVenue::OKX, PaperMarketDataChannel::CANDLE_5M, '2026-08-20T11:55:00.000000Z', '2026-08-20T12:00:01.000000Z')));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_replay_trigger_channel_invalid');
        PaperReplayOrder::candleAvailability(PaperMarketDataChannel::TOP_OF_BOOK);
    }

    public function testCandlesClosingTogetherAreAllReplayedBeforeTheTriggerAndAfterTheBooksOfTheirInterval(): void
    {
        foreach ([PaperMarketDataVenue::OKX, PaperMarketDataVenue::HYPERLIQUID] as $venue) {
            foreach ([
                PaperMarketDataChannel::CANDLE_1M,
                PaperMarketDataChannel::CANDLE_5M,
                PaperMarketDataChannel::CANDLE_15M,
                PaperMarketDataChannel::CANDLE_1H,
            ] as $trigger) {
                $events = [
                    $this->candle($venue, PaperMarketDataChannel::CANDLE_1H, '2026-08-20T11:00:00Z'),
                    $this->candle($venue, PaperMarketDataChannel::CANDLE_15M, '2026-08-20T11:45:00Z'),
                    $this->candle($venue, PaperMarketDataChannel::CANDLE_5M, '2026-08-20T11:55:00Z'),
                    $this->candle($venue, PaperMarketDataChannel::CANDLE_1M, '2026-08-20T11:59:00Z'),
                    $this->event($venue, PaperMarketDataChannel::TOP_OF_BOOK, '2026-08-20T11:59:30.000000Z', '2026-08-20T11:59:30.100000Z'),
                    $this->event($venue, PaperMarketDataChannel::TOP_OF_BOOK, '2026-08-20T11:59:59.998000Z', '2026-08-20T12:00:00.050000Z'),
                    $this->event($venue, PaperMarketDataChannel::TOP_OF_BOOK, '2026-08-20T12:00:00.000000Z', '2026-08-20T12:00:00.060000Z'),
                    $this->event($venue, PaperMarketDataChannel::TOP_OF_BOOK, '2026-08-20T12:00:00.001000Z', '2026-08-20T12:00:00.070000Z'),
                ];
                $index = $this->index($events, PaperReplayOrder::candleAvailability($trigger));
                $channels = [];
                for ($position = 0; $position < $index->count(); ++$position) {
                    $event = $index->event($position);
                    $channels[] = $event->channel === PaperMarketDataChannel::TOP_OF_BOOK
                        ? 'book@' . $event->exchangeTimestamp->format('H:i:s.v')
                        : $event->channel->value;
                }
                $closing = array_values(array_filter(
                    ['candle_15m', 'candle_1h', 'candle_1m', 'candle_5m'],
                    static fn (string $channel): bool => $channel !== $trigger->value,
                ));

                // Same instant: other channels by name (candles, then the book), the trigger last.
                self::assertSame([
                    'book@11:59:30.000',
                    'book@11:59:59.998',
                    ...$closing,
                    'book@12:00:00.000',
                    $trigger->value,
                    'book@12:00:00.001',
                ], $channels, $venue->value . ' ' . $trigger->value);
            }
        }
    }

    public function testAvailabilityKeyOrderIsExactlyTheAvailabilityComparatorOrder(): void
    {
        $random = new \Random\Randomizer(new \Random\Engine\Mt19937(20261001));
        $instants = [
            '2026-08-20T10:59:00.000000Z',
            '2026-08-20T10:59:59.999000Z',
            '2026-08-20T11:00:00.000000Z',
            '2026-08-20T11:00:00.000001Z',
            '2026-08-20T11:55:00.000000Z',
            '2026-08-20T11:59:00.000000Z',
            '2026-08-20T11:59:59.999000Z',
            '2026-08-20T12:00:00.000000Z',
        ];
        $sequences = [null, '0', '7', '007', '10', '9223372036854775808'];
        $events = [];
        for ($position = 0; $position < 1200; ++$position) {
            $events[] = $this->event(
                $random->getInt(0, 1) === 0 ? PaperMarketDataVenue::OKX : PaperMarketDataVenue::HYPERLIQUID,
                PaperMarketDataChannel::cases()[$random->getInt(0, \count(PaperMarketDataChannel::cases()) - 1)],
                $instants[$random->getInt(0, \count($instants) - 1)],
                null,
                $sequences[$random->getInt(0, \count($sequences) - 1)],
                ['n' => (string) $random->getInt(0, 900)],
                $random->getInt(0, 1) === 0 ? 'BTCUSDT' : 'ETHUSDT',
            );
        }

        foreach ([null, PaperMarketDataChannel::CANDLE_1M, PaperMarketDataChannel::CANDLE_15M] as $trigger) {
            $order = PaperReplayOrder::candleAvailability($trigger);
            $entries = [];
            foreach ($events as $position => $event) {
                $entries[] = ['event' => $event, 'input_index' => $position];
            }
            usort($entries, static fn (array $left, array $right): int => self::availabilityComparator($order, $left, $right));

            self::assertSame(
                array_map(static fn (array $entry): int => $entry['input_index'], $entries),
                $this->indexedOrder($this->index($events, $order), $events),
                (string) $trigger?->value,
            );
        }
    }

    /**
     * @param array{event: PaperMarketEvent, input_index: int} $left
     * @param array{event: PaperMarketEvent, input_index: int} $right
     */
    private static function availabilityComparator(PaperReplayOrder $order, array $left, array $right): int
    {
        $leftEvent = $left['event'];
        $rightEvent = $right['event'];

        return ($order->orderTimestamp($leftEvent) <=> $order->orderTimestamp($rightEvent))
            ?: ((int) $order->isTrigger($leftEvent) <=> (int) $order->isTrigger($rightEvent))
            ?: strcmp($leftEvent->channel->value, $rightEvent->channel->value)
            ?: self::compareSequences($leftEvent->sequence, $rightEvent->sequence)
            ?: strcmp($leftEvent->eventId, $rightEvent->eventId)
            ?: $left['input_index'] <=> $right['input_index'];
    }

    private static function compareSequences(?string $left, ?string $right): int
    {
        if ($left === null || $right === null) {
            return $left === $right ? 0 : ($left === null ? 1 : -1);
        }

        return BigInteger::of($left)->compareTo(BigInteger::of($right));
    }

    /** @param list<PaperMarketEvent> $events */
    private function index(array $events, PaperReplayOrder $order): PaperReplayEventIndex
    {
        $spill = tmpfile();
        self::assertIsResource($spill);
        $index = new PaperReplayEventIndex($spill, false, new PaperDatasetRecorderFilesystem(), $order);
        foreach ($events as $event) {
            $index->append($event);
        }
        $index->sort();

        return $index;
    }

    /**
     * @param list<PaperMarketEvent> $events
     * @return list<int>
     */
    private function indexedOrder(PaperReplayEventIndex $index, array $events): array
    {
        $remaining = [];
        foreach ($events as $position => $event) {
            $remaining[CanonicalJson::encode($event->toArray())][] = $position;
        }
        $order = [];
        for ($position = 0; $position < $index->count(); ++$position) {
            $inputPosition = array_shift($remaining[CanonicalJson::encode($index->event($position)->toArray())]);
            self::assertIsInt($inputPosition);
            $order[] = $inputPosition;
        }

        return $order;
    }

    private function candle(PaperMarketDataVenue $venue, PaperMarketDataChannel $channel, string $openAt): PaperMarketEvent
    {
        $seconds = match ($channel) {
            PaperMarketDataChannel::CANDLE_1M => 60,
            PaperMarketDataChannel::CANDLE_5M => 300,
            PaperMarketDataChannel::CANDLE_15M => 900,
            PaperMarketDataChannel::CANDLE_1H => 3600,
            default => throw new \LogicException('candle channel required'),
        };
        $open = new \DateTimeImmutable($openAt);
        $exchange = $venue === PaperMarketDataVenue::OKX
            ? $open
            : $open->modify('+' . $seconds . ' seconds -1 millisecond');

        return $this->event(
            $venue,
            $channel,
            $exchange->format('Y-m-d\TH:i:s.u\Z'),
            $open->modify('+' . ($seconds + 6) . ' seconds')->format('Y-m-d\TH:i:s.u\Z'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function event(
        PaperMarketDataVenue $venue,
        PaperMarketDataChannel $channel,
        string $exchangeTimestamp,
        ?string $receivedTimestamp,
        ?string $sequence = null,
        array $payload = ['price' => '30000.0'],
        string $symbol = 'BTCUSDT',
    ): PaperMarketEvent {
        $exchange = new \DateTimeImmutable($exchangeTimestamp);

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            $venue,
            $symbol,
            $channel,
            $exchange,
            $receivedTimestamp === null ? $exchange->modify('+1 second') : new \DateTimeImmutable($receivedTimestamp),
            $sequence,
            $payload,
        );
    }
}
