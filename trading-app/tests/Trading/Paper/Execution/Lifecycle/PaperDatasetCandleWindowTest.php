<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Lifecycle;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Trading\Paper\Execution\Lifecycle\PaperDatasetCandleWindow;
use App\Trading\Paper\Execution\Lifecycle\PaperLifecycleMainProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** #132 j: MFE/MAE candles come from the verified replay dataset only, never after the close. */
#[CoversClass(PaperDatasetCandleWindow::class)]
#[CoversClass(PaperLifecycleMainProvider::class)]
final class PaperDatasetCandleWindowTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/paper_candle_window_' . bin2hex(random_bytes(5));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testOnlyCandlesEntirelyInsideTheTradeWindowAreServed(): void
    {
        $window = $this->window([
            self::candle('BTCUSDT', '2026-08-20T12:00:00Z', '100', '104', '99', '101'),
            self::candle('BTCUSDT', '2026-08-20T12:01:00Z', '101', '106', '100', '105'),
            self::candle('BTCUSDT', '2026-08-20T12:02:00Z', '105', '107', '97', '98'),
            self::candle('BTCUSDT', '2026-08-20T12:03:00Z', '98', '120', '96', '119'),
            self::candle('BTCUSDT', '2026-08-20T12:04:00Z', '119', '150', '90', '100'),
            self::candle('ETHUSDT', '2026-08-20T12:02:00Z', '10', '11', '9', '10'),
            self::book('2026-08-20T12:02:30Z'),
        ]);

        // Entry fill 12:00:30, exit fill 12:04:10: the 12:00 candle opened before the entry, the
        // 12:04 candle closes after the exit (it straddles the close), 12:05+ does not exist yet.
        $candles = $window->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:30Z'), self::at('2026-08-20T12:04:10Z'));

        self::assertSame(['12:01', '12:02', '12:03'], self::opens($candles));
        foreach ($candles as $candle) {
            self::assertLessThanOrEqual(self::at('2026-08-20T12:04:10Z'), $candle->openTime->modify('+60 seconds'));
        }
        self::assertSame('120', (string) $candles[2]->high);
        self::assertSame(['12:01', '12:02'], self::opens($window->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:30Z'), self::at('2026-08-20T12:04:10Z'), 2)));
        self::assertSame(['12:02', '12:03'], self::opens($window->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:02:00Z'), self::at('2026-08-20T12:04:00Z'))));
        self::assertSame([], $window->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:04:00Z'), self::at('2026-08-20T12:04:59Z')));
        self::assertSame(['12:02'], self::opens($window->getKlinesInWindow('ETHUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:00Z'), self::at('2026-08-20T12:10:00Z'))));
        self::assertSame([], $window->getKlinesInWindow('SOLUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:00Z'), self::at('2026-08-20T12:10:00Z')));
    }

    public function testACandleWithoutStartTimeOpensAtItsExchangeTimestamp(): void
    {
        $candle = self::candle('BTCUSDT', '2026-08-20T12:01:00Z', '100', '101', '99', '100');
        unset($candle['payload']['start_time']);
        $candle['exchange_timestamp'] = '2026-08-20T12:01:00.000000Z';

        $window = $this->window([$candle]);

        self::assertSame(['12:01'], self::opens($window->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:00Z'), self::at('2026-08-20T12:05:00Z'))));
    }

    public function testTheDatasetMustStillMatchItsVerifiedChecksum(): void
    {
        $path = $this->write([self::candle('BTCUSDT', '2026-08-20T12:01:00Z', '100', '101', '99', '100')]);
        $window = new PaperDatasetCandleWindow();
        $window->bind($path, str_repeat('0', 64));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_lifecycle_dataset_checksum_mismatch');
        $window->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:00Z'), self::at('2026-08-20T12:05:00Z'));
    }

    public function testAnUnboundWindowServesNothing(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_lifecycle_dataset_unbound');
        (new PaperDatasetCandleWindow())->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:00Z'), self::at('2026-08-20T12:05:00Z'));
    }

    public function testConflictingCopiesOfOneCandleFailClosedAndIdenticalCopiesAreOne(): void
    {
        $candle = self::candle('BTCUSDT', '2026-08-20T12:01:00Z', '100', '101', '99', '100');
        $window = $this->window([$candle, $candle]);
        self::assertCount(1, $window->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:00Z'), self::at('2026-08-20T12:05:00Z')));

        $other = $candle;
        $other['payload']['high'] = '130';
        $conflicting = $this->window([$candle, $other]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_lifecycle_candle_conflict');
        $conflicting->getKlinesInWindow('BTCUSDT', Timeframe::TF_1M, self::at('2026-08-20T12:00:00Z'), self::at('2026-08-20T12:05:00Z'));
    }

    public function testOnlyTheOneMinuteWindowIsServedAndNothingIsWritten(): void
    {
        $window = $this->window([self::candle('BTCUSDT', '2026-08-20T12:01:00Z', '100', '101', '99', '100')]);
        $calls = [
            'window_5m' => static fn () => $window->getKlinesInWindow('BTCUSDT', Timeframe::TF_5M, self::at('2026-08-20T12:00:00Z'), self::at('2026-08-20T12:05:00Z')),
            'klines' => static fn () => $window->getKlines('BTCUSDT', Timeframe::TF_1M),
            'last' => static fn () => $window->getLastKline('BTCUSDT', Timeframe::TF_1M),
            'gaps' => static fn () => $window->hasGaps('BTCUSDT', Timeframe::TF_1M),
            'gap_list' => static fn () => $window->getGaps('BTCUSDT', Timeframe::TF_1M),
            'save' => static fn () => $window->saveKlines([], 'BTCUSDT', Timeframe::TF_1M),
        ];
        foreach ($calls as $label => $call) {
            try {
                $call();
                self::fail($label . ' must fail closed');
            } catch (\LogicException $exception) {
                self::assertStringStartsWith('paper_lifecycle_', $exception->getMessage(), $label);
            }
        }
    }

    public function testTheLifecycleProviderServesTheseCandlesAndNothingElse(): void
    {
        $window = $this->window([self::candle('BTCUSDT', '2026-08-20T12:01:00Z', '100', '101', '99', '100')]);
        $provider = new PaperLifecycleMainProvider($window);

        self::assertSame($provider, $provider->forContext());
        self::assertSame($window, $provider->forContext()->getKlineProvider());
        foreach (['getContractProvider', 'getOrderProvider', 'getAccountProvider', 'getSystemProvider'] as $method) {
            try {
                $provider->{$method}();
                self::fail($method . ' must fail closed');
            } catch (\LogicException $exception) {
                self::assertSame('paper_lifecycle_provider_unsupported', $exception->getMessage(), $method);
            }
        }
    }

    /** @param list<array<string, mixed>> $events */
    private function window(array $events): PaperDatasetCandleWindow
    {
        $path = $this->write($events);
        $window = new PaperDatasetCandleWindow();
        $window->bind($path, (string) hash_file('sha256', $path));

        return $window;
    }

    /** @param list<array<string, mixed>> $events */
    private function write(array $events): string
    {
        $path = $this->directory . '/events.ndjson';
        file_put_contents($path, implode('', array_map(
            static fn (array $event): string => json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            $events,
        )));

        return $path;
    }

    /** @return array<string, mixed> */
    private static function candle(string $symbol, string $openAt, string $open, string $high, string $low, string $close): array
    {
        $open_ = new \DateTimeImmutable($openAt);

        return [
            'channel' => 'candle_1m',
            'event_id' => hash('sha256', $symbol . $openAt . $high),
            'exchange_timestamp' => $open_->modify('+59 seconds +999 milliseconds')->format('Y-m-d\TH:i:s.u\Z'),
            'payload' => [
                'close' => $close,
                'confirmed' => true,
                'high' => $high,
                'interval' => '1m',
                'low' => $low,
                'open' => $open,
                'start_time' => $open_->format('Uv'),
                'volume' => '10',
            ],
            'symbol' => $symbol,
        ];
    }

    /** @return array<string, mixed> */
    private static function book(string $at): array
    {
        return [
            'channel' => 'top_of_book',
            'event_id' => hash('sha256', 'book' . $at),
            'exchange_timestamp' => $at,
            'payload' => ['bid_price' => '100', 'ask_price' => '100.1'],
            'symbol' => 'BTCUSDT',
        ];
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    /**
     * @param list<KlineDto> $candles
     * @return list<string>
     */
    private static function opens(array $candles): array
    {
        return array_map(static fn (KlineDto $candle): string => $candle->openTime->format('H:i'), $candles);
    }
}
