<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\PaperPublicCaptureStopController;
use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;

#[CoversClass(PaperPublicCaptureStopController::class)]
final class PaperPublicCaptureStopControllerTest extends TestCase
{
    public function testTimerRequestsOneHealthyStopAndCloseRemovesRegistrations(): void
    {
        $loop = new CaptureStopLoop();
        $source = new CaptureStopSource();
        $controller = new PaperPublicCaptureStopController($loop, $source);

        $controller->start(300);

        self::assertCount(1, $loop->timers);
        self::assertSame(300.0, $loop->timers[0]->getInterval());
        if (function_exists('pcntl_signal')) {
            self::assertSame([SIGINT, SIGTERM], array_keys($loop->signals));
        } else {
            self::assertSame([], $loop->signals);
        }

        ($loop->timers[0]->getCallback())();
        self::assertSame(1, $source->healthyStopCalls);
        self::assertSame(1, $loop->stopCalls);
        if ($loop->signals !== []) {
            ($loop->signals[SIGTERM])();
        }
        self::assertSame(1, $source->healthyStopCalls);

        $controller->close();
        self::assertSame([], $loop->timers);
        self::assertSame([], $loop->signals);
    }

    public function testAHealthyStopFailureFallsBackToAbnormalStopWithoutEscapingCallback(): void
    {
        $loop = new CaptureStopLoop();
        $source = new CaptureStopSource(new \RuntimeException('private-source-detail'));
        $controller = new PaperPublicCaptureStopController($loop, $source);
        $controller->start(300);

        ($loop->timers[0]->getCallback())();

        self::assertSame(1, $source->healthyStopCalls);
        self::assertSame(1, $source->stopCalls);
    }

    public function testDeferredDurationStartsOnlyAfterTheFinalInitialSnapshotIsDurable(): void
    {
        $loop = new CaptureStopLoop();
        $source = new CaptureStopSource();
        $controller = new PaperPublicCaptureStopController($loop, $source);

        $controller->startAfterInitialSnapshots(300, ['BTCUSDT', 'ETHUSDT']);

        self::assertSame([], $loop->timers);
        if (function_exists('pcntl_signal')) {
            self::assertSame([SIGINT, SIGTERM], array_keys($loop->signals));
        }

        $controller->observe($this->event(PaperMarketDataChannel::PUBLIC_TRADE, 'BTCUSDT', []));
        $controller->observe($this->event(
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            'BTCUSDT',
            ['native_symbol' => 'BTC-USDT-SWAP', 'reason' => 'initial', 'source_epoch' => 1, 'source_seq_id' => '10'],
        ));
        $controller->observe($this->event(
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            'BTCUSDT',
            ['native_symbol' => 'BTC-USDT-SWAP', 'reason' => 'initial', 'source_epoch' => 1, 'source_seq_id' => '10'],
        ));
        self::assertSame([], $loop->timers);

        $controller->observe($this->event(
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            'ETHUSDT',
            ['native_symbol' => 'ETH-USDT-SWAP', 'reason' => 'initial', 'source_epoch' => 1, 'source_seq_id' => '11'],
        ));

        self::assertCount(1, $loop->timers);
        $timer = $loop->firstTimer() ?? self::fail('The live-duration timer was not scheduled.');
        self::assertSame(300.0, $timer->getInterval());
        ($timer->getCallback())();
        self::assertSame(1, $source->healthyStopCalls);
    }

    public function testDeferredDurationResumesFromTheFinalReconnectBoundaryAlone(): void
    {
        $loop = new CaptureStopLoop();
        $controller = new PaperPublicCaptureStopController($loop, new CaptureStopSource());
        $controller->startAfterInitialSnapshots(300, ['ETHUSDT', 'BTCUSDT']);

        $controller->observe($this->event(
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            'ETHUSDT',
            ['native_symbol' => 'ETH-USDT-SWAP', 'reason' => 'reconnect', 'source_epoch' => 2, 'source_seq_id' => '12'],
        ));

        self::assertCount(1, $loop->timers);
        self::assertSame(300.0, ($loop->firstTimer() ?? self::fail('Missing resumed timer.'))->getInterval());
    }

    #[DataProvider('invalidDurations')]
    public function testRejectsUnboundedDurations(int $duration): void
    {
        $controller = new PaperPublicCaptureStopController(new CaptureStopLoop(), new CaptureStopSource());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_public_capture_duration_invalid');

        $controller->start($duration);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidDurations(): iterable
    {
        yield 'zero' => [0];
        yield 'below minimum' => [299];
        yield 'above maximum' => [604801];
    }

    /** @param array<string, mixed> $payload */
    private function event(PaperMarketDataChannel $channel, string $symbol, array $payload): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            $symbol,
            $channel,
            new \DateTimeImmutable('2026-08-24T10:00:00Z'),
            new \DateTimeImmutable('2026-08-24T10:00:00.100000Z'),
            '1',
            $payload,
        );
    }
}

final class CaptureStopSource implements PaperLiveMarketDataSourceInterface
{
    public int $healthyStopCalls = 0;
    public int $stopCalls = 0;

    public function __construct(private readonly ?\Throwable $healthyStopFailure = null)
    {
    }

    public function venue(): PaperMarketDataVenue
    {
        return PaperMarketDataVenue::OKX;
    }

    public function events(): iterable
    {
        return [];
    }

    public function acknowledge(string $eventId): void
    {
    }

    public function stop(): void
    {
        ++$this->stopCalls;
    }

    public function isComplete(): bool
    {
        return false;
    }

    public function requestHealthyOperatorStop(): void
    {
        ++$this->healthyStopCalls;
        if ($this->healthyStopFailure !== null) {
            throw $this->healthyStopFailure;
        }
    }

    public function failureReason(): ?string
    {
        return null;
    }
}

final class CaptureStopLoop implements LoopInterface
{
    /** @var list<TimerInterface> */
    public array $timers = [];

    /** @var array<int, callable> */
    public array $signals = [];
    public int $stopCalls = 0;

    public function addReadStream($stream, $listener): void
    {
    }

    public function addWriteStream($stream, $listener): void
    {
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        return $this->timers[] = new Timer((float) $interval, $callback, false);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        throw new \LogicException('not_supported');
    }

    public function firstTimer(): ?TimerInterface
    {
        return $this->timers[0] ?? null;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (TimerInterface $candidate): bool => $candidate !== $timer,
        ));
    }

    public function futureTick($listener): void
    {
        $listener();
    }

    public function addSignal($signal, $listener): void
    {
        $this->signals[(int) $signal] = $listener;
    }

    public function removeSignal($signal, $listener): void
    {
        if (($this->signals[(int) $signal] ?? null) === $listener) {
            unset($this->signals[(int) $signal]);
        }
    }

    public function run(): void
    {
    }

    public function stop(): void
    {
        ++$this->stopCalls;
    }
}
