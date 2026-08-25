<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalIndicatorWindowSource;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalIndicatorWindowSource::class)]
final class PaperCanonicalIndicatorWindowSourceTest extends TestCase
{
    public function testReturnsExactAvailableContiguousCanonicalWindow(): void
    {
        $events = $this->candles(250);
        $clock = new PaperReplayClock($events[249]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $source = new PaperCanonicalIndicatorWindowSource(
            $market,
            clock: $clock,
        );

        $windows = $source->windowsFor($this->cell(), $events[249], ['1m']);

        self::assertNotNull($windows);
        self::assertCount(250, $windows['1m']);
        self::assertSame($events[0]->eventId, $windows['1m'][0]['source_record_id']);
        self::assertSame($events[249]->eventId, $windows['1m'][249]['source_record_id']);
        self::assertSame('2026-08-01T04:10:00.000000Z', $windows['1m'][249]['available_at']);
    }

    public function testNotYetReceivedTriggerReturnsNoEvidence(): void
    {
        $events = $this->candles(250);
        $clock = new PaperReplayClock($events[248]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $source = new PaperCanonicalIndicatorWindowSource(
            $market,
            clock: $clock,
        );

        self::assertNull($source->windowsFor($this->cell(), $events[249], ['1m']));
    }

    public function testInsufficientAvailableHistoryReturnsNoEvidence(): void
    {
        $events = $this->candles(249);
        $clock = new PaperReplayClock($events[248]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $source = new PaperCanonicalIndicatorWindowSource($market, $clock);

        self::assertNull($source->windowsFor($this->cell(), $events[248], ['1m']));
    }

    public function testCandleReceivedBeforeItsCloseIsNotYetAvailable(): void
    {
        $events = $this->candles(251);
        $last = $events[250];
        $events[250] = PaperMarketEvent::create(
            $last->sourceNetwork,
            $last->sourceVenue,
            $last->symbol,
            $last->channel,
            $last->exchangeTimestamp,
            $last->exchangeTimestamp,
            $last->sequence,
            $last->payload,
        );
        $clock = new PaperReplayClock($events[250]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $source = new PaperCanonicalIndicatorWindowSource($market, $clock);

        self::assertNull($source->windowsFor($this->cell(), $events[250], ['1m']));
    }

    public function testRejectsAWindowWithMissingMarketHistory(): void
    {
        $events = $this->candles(251);
        unset($events[125]);
        $events = array_values($events);
        $clock = new PaperReplayClock($events[249]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $source = new PaperCanonicalIndicatorWindowSource($market, $clock);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_indicator_window_invalid');

        $source->windowsFor($this->cell(), $events[249], ['1m']);
    }

    public function testReturnsOneThousandHourlySourceRecordsForFourHourProjection(): void
    {
        $events = $this->candles(1000, PaperMarketDataChannel::CANDLE_1H, '1h');
        $clock = new PaperReplayClock($events[999]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $source = new PaperCanonicalIndicatorWindowSource($market, $clock);

        $windows = $source->windowsFor($this->cell(), $events[999], ['4h']);

        self::assertNotNull($windows);
        self::assertSame(['1h'], array_keys($windows));
        self::assertCount(1000, $windows['1h']);
        self::assertSame($events[0]->eventId, $windows['1h'][0]['source_record_id']);
        self::assertSame($events[999]->eventId, $windows['1h'][999]['source_record_id']);
    }

    public function testFourHourProjectionUsesLatestAlignedBaseBehindHourlySuffix(): void
    {
        $hourly = $this->candles(
            1002,
            PaperMarketDataChannel::CANDLE_1H,
            '1h',
            '2026-06-01T00:00:00.000000Z',
        );
        $minutes = $this->candles(250);
        $trigger = $minutes[249];
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([...$hourly, ...$minutes]);
        $source = new PaperCanonicalIndicatorWindowSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        );

        $windows = $source->windowsFor($this->cell(), $trigger, ['1m', '4h']);

        self::assertNotNull($windows);
        self::assertCount(1000, $windows['1h']);
        self::assertSame($hourly[0]->eventId, $windows['1h'][0]['source_record_id']);
        self::assertSame($hourly[999]->eventId, $windows['1h'][999]['source_record_id']);
        self::assertSame($trigger->eventId, $windows['1m'][249]['source_record_id']);
    }

    public function testRejectsAnOlderTriggerAgainstANewerProjectedPrefix(): void
    {
        $events = $this->candles(251);
        $clock = new PaperReplayClock($events[250]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $source = new PaperCanonicalIndicatorWindowSource($market, $clock);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_not_current');

        $source->windowsFor($this->cell(), $events[249], ['1m']);
    }

    public function testRejectsTriggerEnvelopeWithForgedReceiptTime(): void
    {
        $events = $this->candles(1);
        $projected = $events[0];
        $forged = PaperMarketEvent::create(
            $projected->sourceNetwork,
            $projected->sourceVenue,
            $projected->symbol,
            $projected->channel,
            $projected->exchangeTimestamp,
            $projected->exchangeTimestamp,
            $projected->sequence,
            $projected->payload,
        );
        self::assertSame($projected->eventId, $forged->eventId);
        self::assertNotEquals($projected->receivedTimestamp, $forged->receivedTimestamp);

        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $source = new PaperCanonicalIndicatorWindowSource(
            $market,
            new PaperReplayClock($forged->receivedTimestamp),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_not_current');

        $source->windowsFor($this->cell(), $forged, ['1m']);
    }

    /** @return list<PaperMarketEvent> */
    private function candles(
        int $count,
        PaperMarketDataChannel $channel = PaperMarketDataChannel::CANDLE_1M,
        string $timeframe = '1m',
        string $startAt = '2026-08-01T00:00:00.000000Z',
    ): array {
        $duration = match ($timeframe) {
            '1m' => 60,
            '1h' => 3600,
            default => throw new \LogicException('Unsupported test timeframe.'),
        };
        $events = [];
        $start = new \DateTimeImmutable($startAt);
        for ($index = 0; $index < $count; ++$index) {
            $open = $start->modify('+' . ($index * $duration) . ' seconds');
            $events[] = PaperMarketEvent::create(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                'BTCUSDT',
                $channel,
                $open,
                $open->modify('+' . $duration . ' seconds'),
                (string) ($index + 1),
                [
                    'native_symbol' => 'BTC-USDT-SWAP',
                    'bar' => $timeframe,
                    'open' => '30000',
                    'high' => '30100',
                    'low' => '29900',
                    'close' => '30050',
                    'volume_contracts' => '10',
                    'volume_base' => '12.5',
                    'volume_quote' => '375625',
                    'confirmed' => true,
                    'origin' => 'rest_history',
                ],
            );
        }

        return $events;
    }

    private function cell(): PaperExecutionCell
    {
        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'long',
                'sha256:' . str_repeat('b', 64),
                'sha256:' . str_repeat('c', 64),
            ),
            'paper-indicator-window-run',
        );
    }
}
