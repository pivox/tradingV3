<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volatility\Bollinger;
use App\Indicator\Core\Volume\Vwap;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalIndicatorDatasetBindingBuilder;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalIndicatorProjectionSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalIndicatorWindowSource;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Backtesting\Indicator\CanonicalFourHourAggregator;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjector;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjectorInterface;
use App\TradingCore\Backtesting\Indicator\CanonicalPhpIndicatorCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalIndicatorProjectionSource::class)]
final class PaperCanonicalIndicatorProjectionSourceTest extends TestCase
{
    public function testProjectsExactReplayWindowsAndDatasetBinding(): void
    {
        $events = $this->events(250);
        $clock = new PaperReplayClock($events[249]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $projector = new class($this->projector()) implements CanonicalIndicatorProjectorInterface {
            /** @var array<string, mixed>|null */
            public ?array $request = null;

            public function __construct(private CanonicalIndicatorProjectorInterface $delegate)
            {
            }

            public function project(array $request): CanonicalIndicatorProjection
            {
                $this->request = $request;

                return $this->delegate->project($request);
            }
        };
        $source = new PaperCanonicalIndicatorProjectionSource(
            new PaperCanonicalIndicatorWindowSource($market, $clock),
            new PaperCanonicalIndicatorDatasetBindingBuilder(),
            $projector,
            $clock,
        );

        $projection = $source->projectFor(
            $this->cell(),
            $events[249],
            ['1m'],
            'paper-dataset-recorder.v2',
            str_repeat('a', 64),
            'paper-indicator-request-1',
            'test',
        );

        self::assertNotNull($projection);
        self::assertNotNull($projector->request);
        self::assertSame('2026-08-01T04:10:00.000000Z', $projector->request['evaluated_at']);
        self::assertSame('paper-indicator-request-1', $projector->request['request_id']);
        self::assertSame(['1m'], $projector->request['requested_timeframes']);
        self::assertCount(250, $projector->request['candles_by_timeframe']['1m']);
        self::assertSame('sha256:' . str_repeat('a', 64), $projector->request['dataset_binding']['source_checksum']);
        self::assertSame('okx', $projector->request['dataset_binding']['market_data_venue']);
        self::assertSame(
            $projector->request['dataset_binding'],
            $projection->toArray()['dataset_binding'],
        );
    }

    public function testMissingHistoryDoesNotCallProjector(): void
    {
        $events = $this->events(249);
        $clock = new PaperReplayClock($events[248]->receivedTimestamp);
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore($events);
        $projector = new class implements CanonicalIndicatorProjectorInterface {
            public bool $called = false;

            public function project(array $request): CanonicalIndicatorProjection
            {
                $this->called = true;
                throw new \LogicException('must_not_be_called');
            }
        };
        $source = new PaperCanonicalIndicatorProjectionSource(
            new PaperCanonicalIndicatorWindowSource($market, $clock),
            new PaperCanonicalIndicatorDatasetBindingBuilder(),
            $projector,
            $clock,
        );

        self::assertNull($source->projectFor(
            $this->cell(),
            $events[248],
            ['1m'],
            'paper-dataset-recorder.v2',
            str_repeat('a', 64),
            'paper-indicator-request-2',
            'test',
        ));
        self::assertFalse($projector->called);
    }

    /** @return list<PaperMarketEvent> */
    private function events(int $count): array
    {
        $events = [];
        $start = new \DateTimeImmutable('2026-08-01T00:00:00.000000Z');
        for ($index = 0; $index < $count; ++$index) {
            $open = $start->modify('+' . $index . ' minutes');
            $events[] = PaperMarketEvent::create(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                'BTCUSDT',
                PaperMarketDataChannel::CANDLE_1M,
                $open,
                $open->modify('+1 minute'),
                (string) ($index + 1),
                [
                    'native_symbol' => 'BTC-USDT-SWAP',
                    'bar' => '1m',
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
            'sha256:' . str_repeat('1', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'long',
                'sha256:' . str_repeat('2', 64),
                'sha256:' . str_repeat('3', 64),
            ),
            'paper-projection-run',
        );
    }

    private function projector(): CanonicalIndicatorProjector
    {
        return new CanonicalIndicatorProjector(new CanonicalPhpIndicatorCalculator(
            new Rsi(), new Macd(), new Ema(), new Adx(), new Sma(),
            new AtrCalculator(null), new Vwap(), new Bollinger(),
        ), new CanonicalFourHourAggregator());
    }
}
