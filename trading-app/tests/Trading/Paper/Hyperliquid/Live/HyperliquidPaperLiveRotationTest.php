<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperFundingRateClientInterface;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpointStore;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSource;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicWebSocketTransportFactoryInterface;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperPublicLiveSource::class)]
final class HyperliquidPaperLiveRotationTest extends TestCase
{
    private const ROTATION_SECONDS = 7.5;
    /** Apart from the 1 s recovery and reopen timers that the scripts fire by interval. */
    private const BOOK_BATCH_SECONDS = 0.25;
    private const DATASET = 'paper-hyperliquid-rotation-mainnet';

    /** Book frames of the scripted market: l2Book snapshots, or bbo messages since policy 8. */
    private static string $bookChannel = 'l2Book';

    private string $directory;

    protected function setUp(): void
    {
        $temporary = realpath(sys_get_temp_dir());
        self::assertIsString($temporary);
        $this->directory = $temporary . '/hyperliquid-rotation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        self::$bookChannel = 'l2Book';
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testRotationOutputIsByteIdenticalToAnUnrotatedStream(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $standby = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$standby]));
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $events = self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        self::assertNull($this->checkpoint()->rotation);
        self::assertTrue($active->closed);
        self::assertSame(
            [
                'hyperliquid_paper_public_rotation_started',
                'hyperliquid_paper_public_rotation_standby_ready',
                'hyperliquid_paper_public_rotation_draining',
                'hyperliquid_paper_public_rotation_completed',
            ],
            $logger->rotationMessages(),
        );
        $completed = $logger->last('hyperliquid_paper_public_rotation_completed');
        self::assertSame(4, $completed['continuation_kept']);
        self::assertSame('1006/106', $completed['trade_anchor_btc']);
        self::assertSame('2004/204', $completed['trade_anchor_eth']);
    }

    public function testFailedAttemptsAreRetriedUntilARotationSucceedsByteIdentically(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $noTrades = new RotationFakeTransport(array_values(array_filter(
            self::snapshotAfterM6(),
            static fn (string $frame): bool => !str_contains($frame, '"trades"'),
        )));
        $closing = new RotationFakeTransport([], closeAfterSubscribing: true);
        $good = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $factory = new RotationFakeTransportFactory([$noTrades, $closing, $good]);
        $source = $this->source($active, $loop, $logger, $factory);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $loop, $source, $logger): void {
            // The retries start once the active connection has delivered its whole market;
            // the capture stops once the third attempt has switched over.
            if (++$idle <= 2) {
                $loop->fire(HyperliquidPaperLivePolicy::ROTATION_RETRY_SECONDS);
            } elseif ($logger->messages('_rotation_completed') !== []) {
                $source->requestHealthyOperatorStop();
            }
        };

        // Books are held until the next trade: 9-12 are the two first books and the m1+m2
        // trades, 13 is the m3 book flushed with the m5 trades, so these hooks run in two
        // frames between which the rotation starts.
        $events = self::drive($source, [
            9 => static fn () => $loop->fire(self::ROTATION_SECONDS),
            13 => static fn () => $loop->fire(HyperliquidPaperLivePolicy::ROTATION_OVERLAP_TIMEOUT_SECONDS),
        ]);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(3, $factory->created);
        self::assertTrue($noTrades->closed);
        self::assertTrue($closing->closed);
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        $aborts = $logger->all('hyperliquid_paper_public_rotation_aborted');
        self::assertSame(['overlap_timeout', 'standby_closed'], array_column($aborts, 'reason'));
        self::assertIsString($aborts[0]['missing_streams']);
        self::assertStringContainsString('trades/BTC', $aborts[0]['missing_streams']);
        self::assertStringContainsString('trades/ETH', $aborts[0]['missing_streams']);
        self::assertSame([30.0, 30.0], array_column($aborts, 'retry_in_s'));
        self::assertSame([1, 2], array_column($aborts, 'rotation_attempt'));
        self::assertSame(3, $logger->last('hyperliquid_paper_public_rotation_completed')['rotation_attempt']);
    }

    public function testActiveConnectionLostBeforeOverlapAdoptsTheStandbyThenFailsClosedAtTheDeadline(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $standby = new RotationFakeTransport(array_values(array_filter(
            self::snapshotAfterM6(),
            static fn (string $frame): bool => !str_contains($frame, '"trades"'),
        )));
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$standby]));
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $loop): void {
            if (++$idle === 1) {
                $active->serverClose(1001, 'going away');

                return;
            }
            $loop->fire(HyperliquidPaperLivePolicy::RECOVERY_DEADLINE_SECONDS);
        };

        $failure = null;
        try {
            self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('hyperliquid_public_trade_gap_unrecoverable', $failure->getMessage());
        self::assertSame('failed', $this->checkpoint()->phase);
        self::assertTrue($standby->closed);
        $started = $logger->last('hyperliquid_paper_public_recovery_started');
        self::assertSame('ws_close', $started['trigger']);
        self::assertTrue($started['adopted_standby']);
        self::assertSame('overlapping', $started['adopted_standby_state']);
        $failed = $logger->last('hyperliquid_paper_public_recovery_failed');
        self::assertSame('overlap_missing', $failed['reason']);
        self::assertSame('trades/BTC,trades/ETH', $failed['missing_streams']);
        self::assertSame(0, $failed['snapshot_rows_btc']);
        self::assertSame(
            'ws_close_unrecovered',
            $logger->last('hyperliquid_paper_public_continuity_lost')['trigger'],
        );
    }

    public function testResetWhileStreamingIsRecoveredByteIdentically(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $replacement = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $factory = new RotationFakeTransportFactory([$replacement]);
        $source = $this->source($active, $loop, $logger, $factory);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $source): void {
            if (++$idle === 1) {
                $active->serverClose(1006, 'Underlying connection closed');

                return;
            }
            $source->requestHealthyOperatorStop();
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        self::assertNull($this->checkpoint()->rotation);
        self::assertSame(1, $factory->created);
        self::assertSame([
            'hyperliquid_paper_public_recovery_started',
            'hyperliquid_paper_public_recovery_attempt',
            'hyperliquid_paper_public_recovery_standby_ready',
            'hyperliquid_paper_public_recovery_draining',
            'hyperliquid_paper_public_recovery_completed',
        ], $logger->messages('_recovery_'));
        self::assertSame([], $logger->all('hyperliquid_paper_public_continuity_lost'));
        $started = $logger->last('hyperliquid_paper_public_recovery_started');
        self::assertSame('ws_close', $started['trigger']);
        self::assertSame(1006, $started['ws_close_code']);
        self::assertFalse($started['adopted_standby']);
        self::assertSame('recovered_ws_close', $logger->last('hyperliquid_paper_public_recovery_draining')['cause']);
        $completed = $logger->last('hyperliquid_paper_public_recovery_completed');
        self::assertSame(1, $completed['rotation_attempt']);
        self::assertSame('ws_close', $completed['recovery_trigger']);
        self::assertSame(-0.002, $completed['trade_gap_btc_s']);
        self::assertSame(-0.002, $completed['trade_gap_eth_s']);
        self::assertSame(3, $completed['snapshot_rows_btc']);
        self::assertSame(0.002, $completed['snapshot_span_btc_s']);
        self::assertSame(0.0, $completed['book_gap_btc_s']);
        self::assertSame('', $completed['candles_closed_in_gap']);
        self::assertSame(4, $completed['continuation_kept']);
    }

    public function testTransportErrorWhileStreamingIsRecoveredByteIdentically(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $replacement = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$replacement]));
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $source): void {
            if (++$idle === 1) {
                $active->serverError(new \RuntimeException(
                    'Unable to read from stream: stream_get_contents(): SSL: Connection reset by peer',
                ));

                return;
            }
            $source->requestHealthyOperatorStop();
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        $started = $logger->last('hyperliquid_paper_public_recovery_started');
        self::assertSame('ws_error', $started['trigger']);
        self::assertSame(\RuntimeException::class, $started['exception_class']);
        self::assertSame(
            'recovered_ws_error',
            $logger->last('hyperliquid_paper_public_recovery_draining')['cause'],
        );
    }

    public function testResetWithoutOverlapFailsClosedWithAnExplicitLog(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        // The replacement subscribes too late: its snapshot starts after the last emitted trades.
        $replacement = new RotationFakeTransport([
            self::trades([self::btc(108, 1008), self::btc(109, 1009)]),
            self::trades([self::eth(206, 2006)]),
            ...\array_slice(self::snapshotAfterM6(), 2),
        ]);
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$replacement]));
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $loop): void {
            if (++$idle === 1) {
                $active->serverClose(1006, 'Underlying connection closed');

                return;
            }
            $loop->fire(HyperliquidPaperLivePolicy::RECOVERY_DEADLINE_SECONDS);
        };

        $failure = null;
        try {
            self::drive($source, []);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('hyperliquid_public_trade_gap_unrecoverable', $failure->getMessage());
        self::assertSame('failed', $this->checkpoint()->phase);
        self::assertTrue($replacement->closed);
        $failed = $logger->last('hyperliquid_paper_public_recovery_failed');
        self::assertSame('overlap_missing', $failed['reason']);
        self::assertSame('trades/BTC,trades/ETH', $failed['missing_streams']);
        self::assertSame(0.002, $failed['trade_gap_btc_s']);
        self::assertSame(0.002, $failed['trade_gap_eth_s']);
        self::assertSame(2, $failed['snapshot_rows_btc']);
        self::assertSame(0.001, $failed['snapshot_span_btc_s']);
        self::assertSame(1, $failed['snapshot_rows_eth']);
        self::assertSame(
            'ws_close_unrecovered',
            $logger->last('hyperliquid_paper_public_continuity_lost')['trigger'],
        );
    }

    public function testResetDuringAnOpeningRotationAdoptsTheStandby(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $standby = new RotationFakeTransport(
            [...self::snapshotAfterM6(), ...self::marketFrames(7, 10)],
            deferDelivery: true,
        );
        $factory = new RotationFakeTransportFactory([$standby]);
        $source = $this->source($active, $loop, $logger, $factory);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $standby, $source): void {
            switch (++$idle) {
                case 1:
                    // The rotation standby has not been acknowledged yet when the active dies.
                    $active->serverClose(1006, 'Underlying connection closed');
                    break;
                case 2:
                    $standby->deliver();
                    break;
                default:
                    $source->requestHealthyOperatorStop();
            }
        };

        $events = self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(1, $factory->created);
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        self::assertSame(['hyperliquid_paper_public_rotation_started'], $logger->rotationMessages());
        $started = $logger->last('hyperliquid_paper_public_recovery_started');
        self::assertTrue($started['adopted_standby']);
        self::assertSame('opening', $started['adopted_standby_state']);
        self::assertSame(1, $logger->last('hyperliquid_paper_public_recovery_completed')['rotation_attempt']);
    }

    public function testAFailedRecoveryAttemptIsRetriedOnceMoreByteIdentically(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $closing = new RotationFakeTransport([], closeAfterSubscribing: true);
        $good = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $factory = new RotationFakeTransportFactory([$closing, $good]);
        $source = $this->source($active, $loop, $logger, $factory);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $loop, $source): void {
            switch (++$idle) {
                case 1:
                    $active->serverClose(1006, 'Underlying connection closed');
                    break;
                case 2:
                    $loop->fire(HyperliquidPaperLivePolicy::RECOVERY_RETRY_SECONDS);
                    break;
                default:
                    $source->requestHealthyOperatorStop();
            }
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(2, $factory->created);
        self::assertTrue($closing->closed);
        $aborted = $logger->all('hyperliquid_paper_public_recovery_attempt_aborted');
        self::assertCount(1, $aborted);
        self::assertSame('standby_closed', $aborted[0]['reason']);
        self::assertSame(1, $aborted[0]['rotation_attempt']);
        self::assertSame(HyperliquidPaperLivePolicy::RECOVERY_RETRY_SECONDS, $aborted[0]['retry_in_s']);
        self::assertSame(2, $logger->last('hyperliquid_paper_public_recovery_completed')['rotation_attempt']);
    }

    public function testRecoveryAttemptsAreBounded(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $factory = new RotationFakeTransportFactory([
            new RotationFakeTransport([], closeAfterSubscribing: true),
            new RotationFakeTransport([], closeAfterSubscribing: true),
            new RotationFakeTransport([], closeAfterSubscribing: true),
            new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]),
        ]);
        $source = $this->source($active, $loop, $logger, $factory);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $loop): void {
            if (++$idle === 1) {
                $active->serverClose(1006, 'Underlying connection closed');

                return;
            }
            $loop->fire(HyperliquidPaperLivePolicy::RECOVERY_RETRY_SECONDS);
        };

        $failure = null;
        try {
            self::drive($source, []);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('hyperliquid_public_trade_gap_unrecoverable', $failure->getMessage());
        self::assertSame(HyperliquidPaperLivePolicy::RECOVERY_MAX_ATTEMPTS, $factory->created);
        self::assertCount(3, $logger->all('hyperliquid_paper_public_recovery_attempt'));
        $aborted = $logger->all('hyperliquid_paper_public_recovery_attempt_aborted');
        self::assertSame([1.0, 1.0, null], array_column($aborted, 'retry_in_s'));
        $failed = $logger->last('hyperliquid_paper_public_recovery_failed');
        self::assertSame('attempts_exhausted', $failed['reason']);
        self::assertSame('standby_closed', $failed['last_attempt_reason']);
        self::assertSame(
            'ws_close_unrecovered',
            $logger->last('hyperliquid_paper_public_continuity_lost')['trigger'],
        );
    }

    public function testCrashDuringARecoveryFailsCleanlyAfterReplayingThePendingEvent(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $replacement = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$replacement]));
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $events->rewind();
        for ($count = 1; $count <= 12; ++$count) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($count === 12) {
                $active->serverClose(1006, 'Underlying connection closed');
            }
            $events->next();
        }
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        $rotation = $this->checkpoint()->rotation;
        self::assertNotNull($rotation, 'The recovery marker is durable.');
        self::assertSame('recovery', $rotation['cause']);
        self::assertSame(2, $rotation['target_connection_epoch']);
        // Crash: the process disappears with the event pending and the recovery in progress.

        $restartLogger = new RotationRecordingLogger();
        $restarted = $this->source(
            new RotationFakeTransport([]),
            new RotationTestLoop(),
            $restartLogger,
            new RotationFakeTransportFactory([]),
        );
        $replay = $restarted->events();
        self::assertInstanceOf(\Generator::class, $replay);
        $replay->rewind();
        self::assertSame(
            CanonicalJson::encode($pending->toArray()),
            CanonicalJson::encode($replay->current()->toArray()),
        );
        $restarted->acknowledge($pending->eventId);
        $failure = null;
        try {
            $replay->next();
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('hyperliquid_public_trade_gap_unrecoverable', $failure->getMessage());
        self::assertSame(
            'restart_during_recovery',
            $restartLogger->last('hyperliquid_paper_public_continuity_lost')['trigger'],
        );
        self::assertSame('failed', $this->checkpoint()->phase);
    }

    public function testActiveConnectionLostDuringProvenOverlapHandsOverWithoutAGap(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $standby = new RotationFakeTransport(
            [...self::snapshotAfterM6(), ...self::marketFrames(7, 10)],
            deferDelivery: true,
        );
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$standby]));
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $standby, $active, $source): void {
            if (++$idle === 1) {
                // Hyperliquid retires the old connection right as the standby catches up.
                $standby->deliver();
                $active->serverClose(1006, 'Underlying connection closed');

                return;
            }
            $source->requestHealthyOperatorStop();
        };

        $events = self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(
            'active_lost_ws_close',
            $logger->last('hyperliquid_paper_public_rotation_draining')['cause'],
        );
        self::assertSame([], $logger->all('hyperliquid_paper_public_continuity_lost'));
    }

    public function testStandbyLaggingBehindTheDrainedConnectionIsDeduplicatedAfterTheSwitch(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        // The retiring connection is ahead: it already holds m7..m9 when the rotation starts.
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 9)]);
        // The standby lags: m8..m10 only reach it after the switch.
        $standby = new RotationFakeTransport(
            [...self::snapshotAfterM6(), ...self::marketFrames(7, 7)],
            laterFrames: self::marketFrames(8, 10),
        );
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$standby]));
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $standby, $source): void {
            if (++$idle === 1) {
                $standby->deliverLater();

                return;
            }
            $source->requestHealthyOperatorStop();
        };

        $events = self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        $completed = $logger->last('hyperliquid_paper_public_rotation_completed');
        self::assertSame(0, $completed['continuation_kept']);
        self::assertSame('2005/205', $completed['trade_anchor_eth']);
    }

    public function testRestartDuringARotationFailsCleanlyAfterReplayingThePendingEvent(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $standby = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$standby]));
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $events->rewind();
        for ($count = 1; $count <= 12; ++$count) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($count === 12) {
                $loop->fire(self::ROTATION_SECONDS);
            }
            $events->next();
        }
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        $rotation = $this->checkpoint()->rotation;
        self::assertNotNull($rotation, 'The rotation marker is durable.');
        self::assertSame(2, $rotation['target_connection_epoch']);
        // Crash: the process disappears with the event pending and the rotation in progress.

        $restartLogger = new RotationRecordingLogger();
        $restarted = $this->source(
            new RotationFakeTransport([]),
            new RotationTestLoop(),
            $restartLogger,
            new RotationFakeTransportFactory([]),
        );
        $replay = $restarted->events();
        self::assertInstanceOf(\Generator::class, $replay);
        $replay->rewind();
        self::assertSame(
            CanonicalJson::encode($pending->toArray()),
            CanonicalJson::encode($replay->current()->toArray()),
        );
        $restarted->acknowledge($pending->eventId);
        $failure = null;
        try {
            $replay->next();
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('hyperliquid_public_trade_gap_unrecoverable', $failure->getMessage());
        self::assertSame(
            'restart_during_rotation',
            $restartLogger->last('hyperliquid_paper_public_continuity_lost')['trigger'],
        );
        $checkpoint = $this->checkpoint();
        self::assertSame('failed', $checkpoint->phase);
        self::assertNotNull($checkpoint->rotation);
    }

    public function testHealthyStopDuringARotationAbandonsTheStandbyAndCompletes(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $standby = new RotationFakeTransport(array_values(array_filter(
            self::snapshotAfterM6(),
            static fn (string $frame): bool => !str_contains($frame, '"trades"'),
        )));
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$standby]));
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);

        self::assertTrue($source->isComplete());
        self::assertTrue($standby->closed);
        self::assertNull($this->checkpoint()->rotation);
        self::assertSame(1, $this->checkpoint()->connectionEpoch);
        $abort = $logger->last('hyperliquid_paper_public_rotation_aborted');
        self::assertSame('healthy_stop', $abort['reason']);
        self::assertNull($abort['retry_in_s']);
    }

    public function testAStandbyTradeConflictingWithAnEmittedTradeFailsClosed(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        // Same natural identity (coin, time, tid) as the emitted BTC trade 106, other price.
        $standby = new RotationFakeTransport([
            self::trades([self::btc(104, 1004), self::btc(105, 1005), self::trade('BTC', 106, 1006, '65010')]),
            ...\array_slice(self::snapshotAfterM6(), 1),
        ]);
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$standby]));
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $failure = null;
        try {
            self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('market_event_identity_conflict', $failure->getMessage());
        $checkpoint = $this->checkpoint();
        self::assertSame('failed', $checkpoint->phase);
        self::assertSame('market_event_identity_conflict', $checkpoint->failureReason);
        self::assertTrue($standby->closed);
        self::assertSame([], $logger->all('hyperliquid_paper_public_rotation_completed'));
    }

    public function testWithBestBidAndOfferBooksARotationIsByteIdentical(): void
    {
        self::$bookChannel = 'bbo';
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $standby = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $source = $this->source($active, $loop, $logger, new RotationFakeTransportFactory([$standby]));
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $events = self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        $books = array_values(array_filter(
            $events,
            static fn (PaperMarketEvent $event): bool => $event->channel === PaperMarketDataChannel::TOP_OF_BOOK,
        ));
        self::assertNotSame([], $books);
        self::assertSame(['ws_bbo'], array_values(array_unique(array_map(
            static fn (PaperMarketEvent $event): mixed => $event->payload['origin'],
            $books,
        ))));
    }

    public function testWithBestBidAndOfferBooksAHotStandbyTakeoverIsByteIdentical(): void
    {
        self::$bookChannel = 'bbo';
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $hot = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 10)]);
        $factory = new RotationFakeTransportFactory([$hot, new RotationFakeTransport([])]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $source): void {
            if (++$idle === 1) {
                $active->serverClose(1006, 'Underlying connection closed');

                return;
            }
            $source->requestHealthyOperatorStop();
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        self::assertSame([], $logger->messages('_recovery_'));
        self::assertSame('ws_close', $logger->last('hyperliquid_paper_public_hot_standby_takeover')['trigger']);
    }

    public function testBestBidAndOfferMessagesBecomeOneTopOfBookEach(): void
    {
        self::$bookChannel = 'bbo';
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $transport = new RotationFakeTransport([
            self::book('BTC', 1_000, '65000'),
            self::book('ETH', 1_000, '2500'),
            CanonicalJson::encode(['channel' => 'bbo', 'data' => [
                'bbo' => [['n' => 3, 'px' => '65000', 'sz' => '0.5'], ['n' => 1, 'px' => '65002', 'sz' => '2']],
                'coin' => 'BTC',
                'time' => 1_130,
            ]]),
            self::book('BTC', 1_260, '65001'),
        ]);
        $source = $this->source($transport, $loop, $logger, null);
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $books = array_values(array_filter(
            self::drive($source, []),
            static fn (PaperMarketEvent $event): bool => $event->channel === PaperMarketDataChannel::TOP_OF_BOOK,
        ));

        self::assertSame(
            [
                ['BTCUSDT', '1000', '65000', '1', '65002', '2'],
                ['ETHUSDT', '1000', '2500', '1', '2502', '2'],
                ['BTCUSDT', '1130', '65000', '0.5', '65002', '2'],
                ['BTCUSDT', '1260', '65001', '1', '65003', '2'],
            ],
            array_map(static fn (PaperMarketEvent $event): array => [
                $event->symbol,
                $event->payload['source_time'],
                $event->payload['bid_price'],
                $event->payload['bid_size'],
                $event->payload['ask_price'],
                $event->payload['ask_size'],
            ], $books),
        );
        foreach ($books as $book) {
            self::assertSame('ws_bbo', $book->payload['origin']);
            self::assertSame('1', $book->payload['bid_level_count']);
        }
        self::assertTrue($source->isComplete());
    }

    /** Nothing else arrives after the books: only their age releases them, as one batch. */
    public function testHeldBooksAreFlushedByAgeInAQuietMarket(): void
    {
        self::$bookChannel = 'bbo';
        $loop = new RotationTestLoop();
        $transport = new RotationFakeTransport([
            self::book('BTC', 1_000, '65000'),
            self::book('ETH', 1_010, '2500'),
            self::book('BTC', 1_130, '65001'),
        ]);
        $source = $this->source($transport, $loop, new RotationRecordingLogger(), null);
        $emitted = new \ArrayObject();
        $idle = [];
        $loop->onIdle = static function () use (&$idle, $loop, $source, $emitted): void {
            $idle[] = [\count($emitted), $loop->hasTimer(self::BOOK_BATCH_SECONDS)];
            \count($idle) === 1
                ? $loop->fire(self::BOOK_BATCH_SECONDS)
                : $source->requestHealthyOperatorStop();
        };

        $batchSizes = $this->driveInto($source, [], $emitted);

        // First idle: the two boundaries are out, the books wait with their age timer armed.
        // Once it fires, the three books leave as one durable batch, before any stop.
        self::assertSame([[2, true], [5, false]], $idle);
        self::assertSame([1, 1, 3, 2, 1], $batchSizes);
        self::assertSame(
            [['BTCUSDT', '1000'], ['ETHUSDT', '1010'], ['BTCUSDT', '1130']],
            array_map(
                static fn (PaperMarketEvent $event): array => [$event->symbol, $event->payload['source_time']],
                array_slice($emitted->getArrayCopy(), 2),
            ),
        );
        self::assertTrue($source->isComplete());
    }

    /** A burst of books is cut into batches of at most the recorder's batch bound. */
    public function testABookBatchIsCutAtTheMaximumEventCount(): void
    {
        self::$bookChannel = 'bbo';
        $books = HyperliquidPaperLivePolicy::MAX_BOOK_BATCH_EVENTS + 10;
        $frames = [];
        for ($index = 0; $index < $books; ++$index) {
            $frames[] = self::book($index % 2 === 0 ? 'BTC' : 'ETH', 1_000 + $index, (string) (60_000 + $index));
        }
        $frames[] = self::trades([self::btc(101, 2_000)]);
        $loop = new RotationTestLoop();
        $source = $this->source(new RotationFakeTransport($frames), $loop, new RotationRecordingLogger(), null);
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $emitted = new \ArrayObject();
        $batchSizes = $this->driveInto($source, [], $emitted);

        // Boundaries, a full batch cut at the count, the rest cut by the trade, the trade.
        self::assertSame(
            [1, 1, HyperliquidPaperLivePolicy::MAX_BOOK_BATCH_EVENTS, 10, 1],
            self::batchLengths($batchSizes),
        );
        $sourceTimes = [];
        foreach ($emitted as $event) {
            if ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK) {
                $sourceTimes[] = (int) $event->payload['source_time'];
            }
        }
        self::assertSame(range(1_000, 1_000 + $books - 1), $sourceTimes);
        self::assertTrue($source->isComplete());
    }

    /**
     * A funding refresh while books are held: the books leave first. An event takes its
     * ordinal when it is created, so the pending save of the books must not carry the
     * ordinals of funding events that are neither recorded nor pending yet.
     */
    public function testHeldBooksLeaveBeforeTheFundingRefreshCreatesItsEvents(): void
    {
        self::$bookChannel = 'bbo';
        $loop = new RotationTestLoop();
        $transport = new RotationFakeTransport([
            self::book('BTC', 1_000, '65000'),
            self::book('ETH', 1_010, '2500'),
        ]);
        $source = $this->source(
            $transport,
            $loop,
            new RotationRecordingLogger(),
            null,
            fundingClient: new RotationFundingClient(),
        );
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $loop, $source): void {
            ++$idle === 1
                ? $loop->fire(HyperliquidPaperLivePolicy::FUNDING_REFRESH_SECONDS)
                : $source->requestHealthyOperatorStop();
        };

        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $emitted = [];
        for ($events->rewind(); $events->valid(); $events->next()) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $emitted[] = $event;
            $this->assertDurableOrdinalsDoNotRunAhead($emitted);
            $source->acknowledge($event->eventId);
        }

        self::assertSame(
            [
                'funding_rate', 'funding_rate', 'snapshot_boundary', 'snapshot_boundary',
                'top_of_book', 'top_of_book', 'funding_rate', 'funding_rate',
            ],
            array_map(static fn (PaperMarketEvent $event): string => $event->channel->value, $emitted),
        );
        self::assertTrue($source->isComplete());
    }

    /** @return iterable<string, array{bool}> */
    public static function switchKinds(): iterable
    {
        yield 'planned rotation (promoted hot standby)' => [false];
        yield 'hot standby takeover after a loss' => [true];
    }

    /**
     * The last frame of the retiring connection (m6) is a book, still held when the switch
     * is ready: it is recorded before the switch completes, and the switch is saved at once,
     * with the acknowledgement that the stream had deferred.
     */
    #[DataProvider('switchKinds')]
    public function testHeldBooksOfTheRetiringConnectionAreRecordedBeforeTheSwitch(bool $takeover): void
    {
        self::$bookChannel = 'bbo';
        $reference = $this->referenceEvents();
        $retiringBook = $reference[15];
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $retiringBook->channel);
        self::assertSame(['ETHUSDT', '20000'], [$retiringBook->symbol, $retiringBook->payload['source_time']]);

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $hot = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 10)]);
        $factory = new RotationFakeTransportFactory([$hot, new RotationFakeTransport([])]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true);
        $emitted = new \ArrayObject();
        $logger->probe = function () use ($emitted): array {
            $durable = $this->checkpoint();

            return [
                'emitted' => \count($emitted),
                'connection_epoch' => $durable->connectionEpoch,
                'acknowledged' => $durable->acknowledgedIdentities,
                'pending' => $durable->pendingEvent?->eventId,
            ];
        };
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $takeover, $active, $source): void {
            if ($takeover && ++$idle === 1) {
                $active->serverClose(1006, 'Underlying connection closed');

                return;
            }
            $source->requestHealthyOperatorStop();
        };

        $this->driveInto(
            $source,
            $takeover ? [] : [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)],
            $emitted,
        );

        self::assertSame(self::canonical($reference), self::canonical($emitted->getArrayCopy()));
        self::assertTrue($source->isComplete());
        self::assertSame(
            $takeover ? 'active_lost_ws_close' : 'overlap_proven',
            $logger->last('hyperliquid_paper_public_rotation_draining')['cause'],
        );
        // When the switch completes, the held book is already out (16 events, m6 last) but
        // its acknowledgement is still deferred: on disk it is the pending event.
        $completed = $logger->probeOf('hyperliquid_paper_public_rotation_completed');
        self::assertSame(16, $completed['emitted']);
        $sixteenth = $emitted[15];
        self::assertInstanceOf(PaperMarketEvent::class, $sixteenth);
        self::assertSame($retiringBook->eventId, $sixteenth->eventId);
        self::assertSame(1, $completed['connection_epoch']);
        self::assertSame($retiringBook->eventId, $completed['pending']);
        // The switch is saved at once (the next standby opens after that save) and carries it.
        $opened = $logger->probesOf('hyperliquid_paper_public_hot_standby_opened')[1];
        self::assertSame(2, $opened['connection_epoch']);
        self::assertNull($opened['pending']);
        self::assertContains($retiringBook->eventId, $opened['acknowledged']);
    }

    public function testTheWholeSweepBlockIsRecordedRowByRow(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $rows = [];
        for ($index = 0; $index < 1145; ++$index) {
            $row = self::trade('BTC', 10_000 + $index, 5_000, (string) (85_911 + intdiv($index * 89, 1145)));
            $row['side'] = 'B';
            $rows[] = $row;
        }
        $transport = new RotationFakeTransport([self::trades($rows)]);
        $source = $this->source($transport, $loop, $logger, null);
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $trades = array_values(array_filter(
            self::drive($source, []),
            static fn (PaperMarketEvent $event): bool => $event->channel === PaperMarketDataChannel::PUBLIC_TRADE,
        ));

        self::assertTrue($source->isComplete());
        self::assertCount(1145, $trades);
        self::assertSame(
            array_map(static fn (int $index): string => (string) (10_000 + $index), range(0, 1144)),
            array_map(static fn (PaperMarketEvent $event): string => $event->payload['trade_id'], $trades),
        );
        self::assertSame('85911', $trades[0]->payload['price']);
        self::assertSame('85999', $trades[1144]->payload['price']);
        self::assertSame([], $logger->all('hyperliquid_paper_public_source_failed'));
    }

    public function testHotStandbyTakesOverAtOnceWhenTheActiveConnectionIsLost(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $hot = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 10)]);
        $next = new RotationFakeTransport([]);
        $factory = new RotationFakeTransportFactory([$hot, $next]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $active, $source): void {
            if (++$idle === 1) {
                $active->serverClose(1006, 'Underlying connection closed');

                return;
            }
            $source->requestHealthyOperatorStop();
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        self::assertSame(2, $factory->created);
        self::assertTrue($next->closed);
        self::assertSame([], $logger->messages('_recovery_'));
        self::assertSame([], $logger->all('hyperliquid_paper_public_continuity_lost'));
        self::assertCount(2, $logger->all('hyperliquid_paper_public_hot_standby_opened'));
        self::assertCount(2, $logger->all('hyperliquid_paper_public_hot_standby_ready'));
        self::assertSame('ws_close', $logger->last('hyperliquid_paper_public_hot_standby_takeover')['trigger']);
        self::assertSame(
            'active_lost_ws_close',
            $logger->last('hyperliquid_paper_public_rotation_draining')['cause'],
        );
        $completed = $logger->last('hyperliquid_paper_public_rotation_completed');
        self::assertFalse($completed['standby_hot']);
        // The standby buffer starts at the first snapshot trade (1001 / 2001), well before
        // the last trades the lost connection delivered (1006 / 2004).
        self::assertSame(-0.005, $completed['trade_gap_btc_s']);
        self::assertSame(-0.003, $completed['trade_gap_eth_s']);
        self::assertSame('1006/106', $completed['trade_anchor_btc']);
        self::assertSame(4, $completed['continuation_kept']);
        $dropped = $logger->last('hyperliquid_paper_public_hot_standby_lost');
        self::assertSame('healthy_stop', $dropped['reason']);
        self::assertNull($dropped['retry_in_s']);
    }

    public function testRotationPromotesTheHotStandbyByteIdentically(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $hot = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 10)]);
        $factory = new RotationFakeTransportFactory([$hot, new RotationFakeTransport([])]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true);
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $events = self::drive($source, [12 => static fn () => $loop->fire(self::ROTATION_SECONDS)]);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        self::assertSame(2, $factory->created);
        self::assertTrue($active->ingressStopped);
        $started = $logger->last('hyperliquid_paper_public_rotation_started');
        self::assertTrue($started['promoted_hot_standby']);
        self::assertSame(
            [
                'hyperliquid_paper_public_rotation_started',
                'hyperliquid_paper_public_rotation_draining',
                'hyperliquid_paper_public_rotation_completed',
            ],
            $logger->rotationMessages(),
        );
        $completed = $logger->last('hyperliquid_paper_public_rotation_completed');
        self::assertSame('1006/106', $completed['trade_anchor_btc']);
        self::assertSame('2004/204', $completed['trade_anchor_eth']);
    }

    public function testALostHotStandbyIsReopenedWithoutTouchingTheOutput(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 10)]);
        $first = new RotationFakeTransport([]);
        $second = new RotationFakeTransport([]);
        $factory = new RotationFakeTransportFactory([$first, $second]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $first, $loop, $source): void {
            switch (++$idle) {
                case 1:
                    $first->serverClose(1006, 'Underlying connection closed');
                    break;
                case 2:
                    $loop->fire(HyperliquidPaperLivePolicy::HOT_STANDBY_REOPEN_DELAYS_SECONDS[0]);
                    break;
                default:
                    $source->requestHealthyOperatorStop();
            }
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertTrue($source->isComplete());
        self::assertSame(1, $this->checkpoint()->connectionEpoch);
        self::assertSame(2, $factory->created);
        $lost = $logger->all('hyperliquid_paper_public_hot_standby_lost');
        self::assertSame(['standby_closed', 'healthy_stop'], array_column($lost, 'reason'));
        self::assertSame([1.0, null], array_column($lost, 'retry_in_s'));
        self::assertSame(
            [0, 1],
            array_column($logger->all('hyperliquid_paper_public_hot_standby_opened'), 'consecutive_failures'),
        );
        self::assertCount(2, $logger->all('hyperliquid_paper_public_hot_standby_ready'));
    }

    public function testBothConnectionsLostFallBackToTheSnapshotRecovery(): void
    {
        $reference = $this->referenceEvents();

        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6)]);
        $hot = new RotationFakeTransport([]);
        $replacement = new RotationFakeTransport([...self::snapshotAfterM6(), ...self::marketFrames(7, 10)]);
        $factory = new RotationFakeTransportFactory([$hot, $replacement, new RotationFakeTransport([])]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $hot, $active, $source): void {
            switch (++$idle) {
                case 1:
                    // The standby dies first, then the active before the standby is reopened.
                    $hot->serverClose(1006, 'Underlying connection closed');
                    $active->serverClose(1006, 'Underlying connection closed');
                    break;
                default:
                    $source->requestHealthyOperatorStop();
            }
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonical($reference), self::canonical($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame(2, $this->checkpoint()->connectionEpoch);
        self::assertSame(3, $factory->created);
        self::assertFalse($logger->last('hyperliquid_paper_public_recovery_started')['adopted_standby']);
        self::assertSame(1, $logger->last('hyperliquid_paper_public_recovery_completed')['rotation_attempt']);
        self::assertCount(2, $logger->all('hyperliquid_paper_public_hot_standby_opened'));
    }

    public function testHotStandbyBufferIsPrunedAndStillProvesTheSwitch(): void
    {
        $clock = new MockClock('2026-07-29T10:00:00Z');
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        // 140 updates of the current BTC/1m candle: no event, but many buffered frames.
        $burst = [];
        for ($trades = 2; $trades <= 141; ++$trades) {
            $burst[] = self::candle('BTC', '1m', 0, $trades);
        }
        $reference = $this->referenceEvents([...self::snapshotAtStart(), ...self::marketFrames(1, 6), ...$burst, ...self::marketFrames(7, 10)]);
        // Both connections receive the same market: m7..m10 arrive a minute after the burst.
        $active = new RotationFakeTransport(
            [...self::snapshotAtStart(), ...self::marketFrames(1, 6), ...$burst],
            laterFrames: self::marketFrames(7, 10),
        );
        $hot = new RotationFakeTransport(
            [...self::snapshotAtStart(), ...self::marketFrames(1, 6), ...$burst],
            laterFrames: self::marketFrames(7, 10),
        );
        $factory = new RotationFakeTransportFactory([$hot, new RotationFakeTransport([])]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true, clock: $clock);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $clock, $hot, $active, $source): void {
            switch (++$idle) {
                case 1:
                    $clock->sleep(HyperliquidPaperLivePolicy::HOT_STANDBY_WINDOW_SECONDS + 1.0);
                    $hot->deliverLater();
                    $active->deliverLater();
                    break;
                case 2:
                    $active->serverClose(1006, 'Underlying connection closed');
                    break;
                default:
                    $source->requestHealthyOperatorStop();
            }
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonicalWithoutReceipt($reference), self::canonicalWithoutReceipt($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame([], $logger->messages('_recovery_'));
        $completed = $logger->last('hyperliquid_paper_public_rotation_completed');
        // 162 frames were buffered; the old trades, books and BTC/1m states the active
        // connection had caught up with are gone, the last state of every stream stays.
        self::assertGreaterThanOrEqual(20, $completed['standby_pruned_items']);
        self::assertLessThanOrEqual(142, $completed['standby_items']);
        self::assertSame('1007/107', $completed['trade_anchor_btc']);
        self::assertSame('2005/205', $completed['trade_anchor_eth']);
        self::assertSame(0, $completed['continuation_kept']);
        self::assertSame('', $completed['candles_closed_in_gap']);
    }

    public function testHotStandbyBufferIsPrunedByCountInABusyMarket(): void
    {
        $clock = new MockClock('2026-07-29T10:00:00Z');
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        // Far more candle updates than the soft cap within the time window: the active
        // connection already holds the last state, the standby receives every update.
        $latest = self::candle('BTC', '1m', 0, 1001);
        $batches = [];
        for ($batch = 0; $batch < 5; ++$batch) {
            $frames = [];
            for ($trades = 2 + 200 * $batch; $trades < 202 + 200 * $batch; ++$trades) {
                $frames[] = self::candle('BTC', '1m', 0, $trades);
            }
            $batches[] = $frames;
        }
        $reference = $this->referenceEvents([...self::snapshotAtStart(), ...self::marketFrames(1, 6), $latest, ...self::marketFrames(7, 10)]);
        $active = new RotationFakeTransport(
            [...self::snapshotAtStart(), ...self::marketFrames(1, 6), $latest],
            laterFrames: self::marketFrames(7, 10),
        );
        $hot = new RotationFakeTransport(
            [...self::snapshotAtStart(), ...self::marketFrames(1, 6)],
            laterFrames: [$latest, ...self::marketFrames(7, 10)],
        );
        $factory = new RotationFakeTransportFactory([$hot, new RotationFakeTransport([])]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true, clock: $clock);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $clock, $hot, $active, $source, $batches): void {
            $step = ++$idle;
            if ($step <= \count($batches)) {
                // A second between batches: the standby prunes what the active covers.
                $clock->sleep(1.5);
                $hot->pushFrames($batches[$step - 1]);

                return;
            }
            switch ($step - \count($batches)) {
                case 1:
                    $clock->sleep(1.5);
                    $hot->deliverLater();
                    $active->deliverLater();
                    break;
                case 2:
                    $active->serverClose(1006, 'Underlying connection closed');
                    break;
                default:
                    $source->requestHealthyOperatorStop();
            }
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonicalWithoutReceipt($reference), self::canonicalWithoutReceipt($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame([], $logger->messages('_recovery_'));
        // The standby never hit a cap; only the final healthy stop drops it.
        self::assertSame(['healthy_stop'], array_column($logger->all('hyperliquid_paper_public_hot_standby_lost'), 'reason'));
        $completed = $logger->last('hyperliquid_paper_public_rotation_completed');
        self::assertGreaterThanOrEqual(600, $completed['standby_pruned_items']);
        self::assertLessThanOrEqual(HyperliquidPaperLivePolicy::HOT_STANDBY_SOFT_MAX_ITEMS, $completed['standby_items']);
        self::assertSame('1007/107', $completed['trade_anchor_btc']);
    }

    public function testHotStandbyKeepsTheAnchorOfAQuietCoinWhileNewerFramesAreNotEmittedYet(): void
    {
        $clock = new MockClock('2026-07-29T10:00:00Z');
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $burst = [];
        for ($trades = 2; $trades <= 141; ++$trades) {
            $burst[] = self::candle('BTC', '1m', 0, $trades);
        }
        $reference = $this->referenceEvents([...self::snapshotAtStart(), ...self::marketFrames(1, 6), ...$burst, ...self::marketFrames(7, 10)]);
        // The standby gets m7..m10 a minute later while the active connection dies before
        // emitting them: the old ETH and BTC trades frames must survive the pruning.
        $active = new RotationFakeTransport([...self::snapshotAtStart(), ...self::marketFrames(1, 6), ...$burst]);
        $hot = new RotationFakeTransport(
            [...self::snapshotAtStart(), ...self::marketFrames(1, 6), ...$burst],
            laterFrames: self::marketFrames(7, 10),
        );
        $factory = new RotationFakeTransportFactory([$hot, new RotationFakeTransport([])]);
        $source = $this->source($active, $loop, $logger, $factory, hotStandby: true, clock: $clock);
        $idle = 0;
        $loop->onIdle = static function () use (&$idle, $clock, $hot, $active, $source): void {
            switch (++$idle) {
                case 1:
                    $clock->sleep(HyperliquidPaperLivePolicy::HOT_STANDBY_WINDOW_SECONDS + 1.0);
                    $hot->deliverLater();
                    break;
                case 2:
                    $active->serverClose(1006, 'Underlying connection closed');
                    break;
                default:
                    $source->requestHealthyOperatorStop();
            }
        };

        $events = self::drive($source, []);

        self::assertSame(self::canonicalWithoutReceipt($reference), self::canonicalWithoutReceipt($events));
        self::assertNoDuplicateTrades($events);
        self::assertTrue($source->isComplete());
        self::assertSame([], $logger->messages('_recovery_'));
        $completed = $logger->last('hyperliquid_paper_public_rotation_completed');
        self::assertGreaterThan(0, $completed['standby_pruned_items']);
        self::assertSame('1006/106', $completed['trade_anchor_btc']);
        self::assertSame('2004/204', $completed['trade_anchor_eth']);
        self::assertSame(4, $completed['continuation_kept']);
    }

    /** @return iterable<string, array{int, int}> candle trade count, expected warnings */
    public static function coveredMinuteCandleCounts(): iterable
    {
        yield 'candle lagging behind the trades' => [1, 0];
        yield 'equal' => [2, 0];
        yield 'one trade missing' => [3, 1];
    }

    #[DataProvider('coveredMinuteCandleCounts')]
    public function testATradeCountBelowTheClosedCandleIsLoggedWithoutStoppingTheCapture(
        int $candleTradeCount,
        int $expectedWarnings,
    ): void {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $transport = new RotationFakeTransport([
            self::trades([self::trade('BTC', 1, 30_000, '65000')]),
            self::trades([self::trade('BTC', 2, 61_000, '65001'), self::trade('BTC', 3, 62_000, '65002')]),
            self::candle('BTC', '1m', 60_000, $candleTradeCount),
            self::candle('BTC', '1m', 120_000, 1),
            self::trades([self::trade('BTC', 4, 121_000, '65003')]),
        ]);
        $source = $this->source($transport, $loop, $logger, null);
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $events = self::drive($source, []);

        self::assertTrue($source->isComplete());
        self::assertCount(4, array_filter(
            $events,
            static fn (PaperMarketEvent $event): bool => $event->channel === PaperMarketDataChannel::PUBLIC_TRADE,
        ));
        $warnings = $logger->all('hyperliquid_paper_public_trade_count_below_candle');
        self::assertCount($expectedWarnings, $warnings);
        if ($expectedWarnings === 0) {
            return;
        }
        self::assertSame('BTC', $warnings[0]['coin']);
        self::assertSame(60_000, $warnings[0]['minute_start_ms']);
        self::assertSame('1970-01-01T00:01:00Z', $warnings[0]['minute']);
        self::assertSame(2, $warnings[0]['rows']);
        self::assertSame(3, $warnings[0]['candle_trade_count']);
        self::assertSame(1, $warnings[0]['missing_at_least']);
        self::assertFalse($warnings[0]['fails_closed']);
        self::assertSame('warning', $logger->levelOf('hyperliquid_paper_public_trade_count_below_candle'));
    }

    public function testATradeCountHoleStopsTheCaptureWhenThePolicyFailsClosed(): void
    {
        $logger = new RotationRecordingLogger();
        $loop = new RotationTestLoop();
        $transport = new RotationFakeTransport([
            self::trades([self::trade('BTC', 1, 30_000, '65000')]),
            self::trades([self::trade('BTC', 2, 61_000, '65001'), self::trade('BTC', 3, 62_000, '65002')]),
            self::candle('BTC', '1m', 60_000, 3),
            self::candle('BTC', '1m', 120_000, 1),
            self::trades([self::trade('BTC', 4, 121_000, '65003')]),
        ]);
        $source = $this->source($transport, $loop, $logger, null, tradeCountHoleFailsClosed: true);
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();

        $failure = null;
        try {
            self::drive($source, []);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('hyperliquid_trade_count_below_candle', $failure->getMessage());
        self::assertSame('failed', $this->checkpoint()->phase);
        self::assertTrue($logger->last('hyperliquid_paper_public_trade_count_below_candle')['fails_closed']);
    }

    /**
     * @param list<string>|null $frames the whole market on one connection (default m1..m10)
     * @return list<PaperMarketEvent>
     */
    private function referenceEvents(?array $frames = null): array
    {
        $referenceDirectory = $this->directory . '/reference';
        self::assertTrue(mkdir($referenceDirectory, 0700));
        $loop = new RotationTestLoop();
        $transport = new RotationFakeTransport($frames ?? [...self::snapshotAtStart(), ...self::marketFrames(1, 10)]);
        $source = $this->source($transport, $loop, new RotationRecordingLogger(), null, $referenceDirectory);
        $loop->onIdle = static fn () => $source->requestHealthyOperatorStop();
        $events = self::drive($source, []);
        self::assertTrue($source->isComplete());
        self::assertCount(20, $events);

        return $events;
    }

    /**
     * @param array<int, callable(): void> $afterEvent hooks run after the n-th acknowledgement
     * @return list<PaperMarketEvent>
     */
    private static function drive(HyperliquidPaperPublicLiveSource $source, array $afterEvent): array
    {
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $emitted = [];
        for ($events->rewind(); $events->valid(); $events->next()) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $emitted[] = $event;
            $source->acknowledge($event->eventId);
            ($afterEvent[\count($emitted)] ?? static function (): void {
            })();
        }

        return $emitted;
    }

    /**
     * Like drive(), appending each event to $emitted when it is yielded, so that idle
     * callbacks and log probes see how far the output is, and checking at each event that
     * the durable ordinals do not run ahead of it. Returns the durable batch size reported
     * for each event.
     *
     * @param array<int, callable(): void> $afterEvent hooks run after the n-th acknowledgement
     * @param \ArrayObject<int, PaperMarketEvent> $emitted
     * @return list<int>
     */
    private function driveInto(HyperliquidPaperPublicLiveSource $source, array $afterEvent, \ArrayObject $emitted): array
    {
        $events = $source->events();
        self::assertInstanceOf(\Generator::class, $events);
        $batchSizes = [];
        for ($events->rewind(); $events->valid(); $events->next()) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $emitted[] = $event;
            $this->assertDurableOrdinalsDoNotRunAhead(array_values($emitted->getArrayCopy()));
            $batchSizes[] = $source->pendingDurableBatchSize();
            $source->acknowledge($event->eventId);
            ($afterEvent[\count($emitted)] ?? static function (): void {
            })();
        }

        return $batchSizes;
    }

    /**
     * The durable ordinal cursor only covers events already yielded or in the durable
     * pending batch: each scope's latest event is one of them.
     *
     * @param list<PaperMarketEvent> $emitted
     */
    private function assertDurableOrdinalsDoNotRunAhead(array $emitted): void
    {
        $durable = $this->checkpoint();
        $known = [];
        foreach ($emitted as $event) {
            $known[$event->eventId] = true;
        }
        if ($durable->pendingEvent !== null) {
            $known[$durable->pendingEvent->eventId] = true;
        }
        $remaining = $durable->pendingContinuation['remaining_events'] ?? [];
        self::assertIsArray($remaining);
        foreach ($remaining as $state) {
            self::assertIsArray($state);
            $event = \is_array($state['event'] ?? null) ? $state['event'] : $state;
            /** @var array<string, mixed> $event */
            $known[PaperMarketEvent::fromArray($event)->eventId] = true;
        }
        $scopes = $durable->ordinalState['scopes'] ?? [];
        self::assertIsArray($scopes);
        foreach ($scopes as $scope => $state) {
            self::assertIsArray($state);
            self::assertIsArray($state['latest'] ?? null);
            $latest = $state['latest']['event'] ?? null;
            self::assertIsArray($latest);
            /** @var array<string, mixed> $latest */
            self::assertArrayHasKey(
                PaperMarketEvent::fromArray($latest)->eventId,
                $known,
                'the durable ordinal of ' . $scope . ' runs ahead of the output',
            );
        }
    }

    /**
     * @param list<int> $batchSizes the durable batch size reported at each event
     * @return list<int> the length of each durable batch
     */
    private static function batchLengths(array $batchSizes): array
    {
        $lengths = [];
        $expected = null;
        foreach ($batchSizes as $size) {
            if ($size !== $expected) {
                self::assertNull($expected, 'a durable batch ended early');
                $lengths[] = $size;
            }
            $expected = $size > 1 ? $size - 1 : null;
        }

        return $lengths;
    }

    private function source(
        HyperliquidPaperPublicWebSocketTransportInterface $transport,
        LoopInterface $loop,
        RotationRecordingLogger $logger,
        ?HyperliquidPaperPublicWebSocketTransportFactoryInterface $rotations,
        ?string $directory = null,
        bool $hotStandby = false,
        ?MockClock $clock = null,
        bool $tradeCountHoleFailsClosed = false,
        ?HyperliquidPaperFundingRateClientInterface $fundingClient = null,
    ): HyperliquidPaperPublicLiveSource {
        $directory ??= $this->directory;
        $store = new HyperliquidPaperLiveCheckpointStore($directory);
        $checkpoint = $store->loadOrCreate(
            self::DATASET,
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );

        return new HyperliquidPaperPublicLiveSource(
            $transport,
            new HyperliquidPaperPublicConfig(
                PaperMarketDataNetwork::MAINNET,
                true,
                HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
                HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI,
                $directory,
            ),
            $clock ?? new MockClock('2026-07-29T10:00:00Z'),
            $store,
            $checkpoint,
            $loop,
            fundingClient: $fundingClient,
            logger: $logger,
            rotationTransports: $rotations,
            connectionRotationSeconds: self::ROTATION_SECONDS,
            hotStandby: $hotStandby,
            tradeCountHoleFailsClosed: $tradeCountHoleFailsClosed,
            bookBatchSeconds: self::BOOK_BATCH_SECONDS,
        );
    }

    private function checkpoint(): \App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpoint
    {
        return (new HyperliquidPaperLiveCheckpointStore($this->directory))->loadOrCreate(
            self::DATASET,
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
    }

    /** Snapshot a connection receives when it subscribes at the start of the market. */
    /** @return list<string> */
    private static function snapshotAtStart(): array
    {
        return [
            self::trades([self::btc(101, 1001), self::btc(102, 1002), self::btc(103, 1003)]),
            self::trades([self::eth(201, 2001), self::eth(202, 2002), self::eth(203, 2003)]),
            self::book('BTC', 10_000, '64999'),
            self::book('ETH', 10_000, '2499'),
            ...self::candles(0, 1),
        ];
    }

    /** Snapshot of a connection subscribing after m6: the last three trades per coin. */
    /** @return list<string> */
    private static function snapshotAfterM6(): array
    {
        return [
            self::trades([self::btc(104, 1004), self::btc(105, 1005), self::btc(106, 1006)]),
            self::trades([self::eth(202, 2002), self::eth(203, 2003), self::eth(204, 2004)]),
            self::book('BTC', 20_000, '65000'),
            self::book('ETH', 20_000, '2500'),
            self::candle('BTC', '1m', 0, 2),
            ...array_values(array_filter(
                self::candles(0, 1),
                static fn (string $frame): bool => !str_contains($frame, '"i":"1m","l":"0.5","n":1,"o":"1","s":"BTC"'),
            )),
        ];
    }

    /** @return list<string> the market frames m$from..m$to */
    private static function marketFrames(int $from, int $to): array
    {
        $frames = [
            1 => self::trades([self::btc(104, 1004)]),
            2 => self::trades([self::eth(204, 2004)]),
            3 => self::book('BTC', 20_000, '65000'),
            4 => self::candle('BTC', '1m', 0, 2),
            5 => self::trades([self::btc(105, 1005), self::btc(106, 1006)]),
            6 => self::book('ETH', 20_000, '2500'),
            7 => self::trades([self::eth(205, 2005)]),
            8 => self::candle('BTC', '1m', 60_000, 1),
            9 => self::trades([self::btc(107, 1007)]),
            10 => self::book('BTC', 30_000, '65001'),
        ];

        return array_values(array_filter(
            $frames,
            static fn (int $index): bool => $index >= $from && $index <= $to,
            \ARRAY_FILTER_USE_KEY,
        ));
    }

    /** @return array<string, mixed> */
    private static function btc(int $tid, int $time): array
    {
        return self::trade('BTC', $tid, $time, '65000');
    }

    /** @return array<string, mixed> */
    private static function eth(int $tid, int $time): array
    {
        return self::trade('ETH', $tid, $time, '2500');
    }

    /** @return array<string, mixed> */
    private static function trade(string $coin, int $tid, int $time, string $price): array
    {
        return [
            'coin' => $coin,
            'side' => $tid % 2 === 0 ? 'B' : 'A',
            'px' => $price,
            'sz' => '0.01',
            'hash' => '0x' . hash('sha256', $coin . '-' . $tid),
            'time' => $time,
            'tid' => $tid,
            'users' => ['0xa', '0xb'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function trades(array $rows): string
    {
        return CanonicalJson::encode(['channel' => 'trades', 'data' => $rows]);
    }

    private static function book(string $coin, int $time, string $bid): string
    {
        if (self::$bookChannel === 'bbo') {
            return CanonicalJson::encode([
                'channel' => 'bbo',
                'data' => [
                    'bbo' => [
                        ['n' => 1, 'px' => $bid, 'sz' => '1'],
                        ['n' => 1, 'px' => (string) ((int) $bid + 2), 'sz' => '2'],
                    ],
                    'coin' => $coin,
                    'time' => $time,
                ],
            ]);
        }

        return CanonicalJson::encode([
            'channel' => 'l2Book',
            'data' => [
                'coin' => $coin,
                'levels' => [
                    [['px' => $bid, 'sz' => '1', 'n' => 1]],
                    [['px' => (string) ((int) $bid + 2), 'sz' => '2', 'n' => 1]],
                ],
                'time' => $time,
            ],
        ]);
    }

    /** @return list<string> the current candle of all eight streams */
    private static function candles(int $start, int $trades): array
    {
        $frames = [];
        foreach (['BTC', 'ETH'] as $coin) {
            foreach (['1m', '5m', '15m', '1h'] as $interval) {
                $frames[] = self::candle($coin, $interval, $start, $trades);
            }
        }

        return $frames;
    }

    private static function candle(string $coin, string $interval, int $start, int $trades): string
    {
        $duration = ['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000][$interval];

        return CanonicalJson::encode([
            'channel' => 'candle',
            'data' => [
                'T' => $start + $duration - 1,
                'c' => (string) (1 + $trades),
                'h' => (string) (2 + $trades),
                'i' => $interval,
                'l' => '0.5',
                'n' => $trades,
                'o' => '1',
                's' => $coin,
                't' => $start,
                'v' => (string) (4 * $trades),
            ],
        ]);
    }

    /**
     * @param list<PaperMarketEvent> $events
     * @return list<string>
     */
    private static function canonical(array $events): array
    {
        return array_map(
            static fn (PaperMarketEvent $event): string => CanonicalJson::encode($event->toArray()),
            $events,
        );
    }

    /**
     * Same as canonical() without the receipt time, for markets whose clock moves between
     * the reference and the compared run.
     *
     * @param list<PaperMarketEvent> $events
     * @return list<string>
     */
    private static function canonicalWithoutReceipt(array $events): array
    {
        return array_map(
            static function (PaperMarketEvent $event): string {
                $state = $event->toArray();
                unset($state['received_timestamp']);

                return CanonicalJson::encode($state);
            },
            $events,
        );
    }

    /** @param list<PaperMarketEvent> $events */
    private static function assertNoDuplicateTrades(array $events): void
    {
        $identities = [];
        foreach ($events as $event) {
            if ($event->channel !== PaperMarketDataChannel::PUBLIC_TRADE) {
                continue;
            }
            $identities[] = $event->symbol . '|' . $event->payload['trade_id'] . '|' . $event->payload['block_time'];
        }
        self::assertSame(array_values(array_unique($identities)), $identities);
        self::assertCount(12, $identities);
    }
}

/** A scripted public connection: acknowledges subscriptions, then delivers its frames. */
final class RotationFakeTransport implements HyperliquidPaperPublicWebSocketTransportInterface
{
    public bool $closed = false;
    public bool $ingressStopped = false;
    private int $subscriptions = 0;

    /** @var \Closure(string): void|null */
    private ?\Closure $onMessage = null;

    /** @var \Closure(?int, ?string): void|null */
    private ?\Closure $onClose = null;

    /** @var \Closure(\Throwable): void|null */
    private ?\Closure $onError = null;

    /**
     * @param list<string> $frames delivered right after the subscriptions are acknowledged
     * @param list<string> $laterFrames delivered by deliverLater()
     */
    public function __construct(
        private readonly array $frames,
        private readonly bool $closeAfterSubscribing = false,
        private readonly bool $deferDelivery = false,
        private readonly array $laterFrames = [],
    ) {
    }

    public function deliverLater(): void
    {
        foreach ($this->laterFrames as $frame) {
            $this->push($frame);
        }
    }

    /** @param list<string> $frames */
    public function pushFrames(array $frames): void
    {
        foreach ($frames as $frame) {
            $this->push($frame);
        }
    }

    public function connect(callable $onOpen, callable $onMessage, callable $onClose, callable $onError): void
    {
        $this->onMessage = $onMessage(...);
        $this->onClose = $onClose(...);
        $this->onError = $onError(...);
        $onOpen();
    }

    /** The socket fails under the connection (the transport reports the error and closes). */
    public function serverError(\Throwable $failure): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ($this->onError ?? throw new \LogicException())($failure);
    }

    public function send(array $message): void
    {
        if ($message === ['method' => 'ping'] || $this->closed) {
            return;
        }
        ++$this->subscriptions;
        if ($this->deferDelivery) {
            return;
        }
        $this->push(CanonicalJson::encode(['channel' => 'subscriptionResponse', 'data' => $message]));
        if ($this->subscriptions === 12) {
            if ($this->closeAfterSubscribing) {
                $this->serverClose(1006, 'Underlying connection closed');

                return;
            }
            foreach ($this->frames as $frame) {
                $this->push($frame);
            }
        }
    }

    /** Delivers the deferred acknowledgements and frames at once. */
    public function deliver(): void
    {
        foreach ((new \App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicSubscriptionSet())->subscriptions() as $subscription) {
            $this->push(CanonicalJson::encode(['channel' => 'subscriptionResponse', 'data' => $subscription]));
        }
        foreach ($this->frames as $frame) {
            $this->push($frame);
        }
    }

    public function serverClose(int $code, string $reason): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        ($this->onClose ?? throw new \LogicException())($code, $reason);
    }

    public function pauseReading(): void
    {
    }

    public function resumeReading(): void
    {
    }

    public function stopIngress(): void
    {
        $this->ingressStopped = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    private function push(string $frame): void
    {
        if ($this->closed || $this->ingressStopped) {
            return;
        }
        ($this->onMessage ?? throw new \LogicException())($frame);
    }
}

final class RotationFakeTransportFactory implements HyperliquidPaperPublicWebSocketTransportFactoryInterface
{
    public int $created = 0;

    /** @param list<RotationFakeTransport> $transports */
    public function __construct(private array $transports)
    {
    }

    public function create(
        LoopInterface $loop,
        HyperliquidPaperPublicConfig $config,
    ): HyperliquidPaperPublicWebSocketTransportInterface {
        ++$this->created;

        return array_shift($this->transports) ?? throw new \LogicException('no_standby_scripted');
    }
}

/** Runs queued callbacks; a blocking wait with nothing queued calls onIdle. */
final class RotationTestLoop implements LoopInterface
{
    public ?\Closure $onIdle = null;

    /** @var list<TimerInterface> */
    private array $timers = [];

    /** @var list<\Closure(): void> */
    private array $queued = [];

    private int $stopGeneration = 0;

    public function run(): void
    {
        $generation = $this->stopGeneration;
        if ($this->queued === []) {
            // A network pump slice only polls; it must not end the scripted market.
            if (!$this->hasTimer(HyperliquidPaperLivePolicy::NETWORK_PUMP_SECONDS) && $this->onIdle !== null) {
                ($this->onIdle)();
            }

            return;
        }
        while ($this->queued !== []) {
            $callback = array_shift($this->queued);
            $callback();
            if ($generation !== $this->stopGeneration) {
                return;
            }
        }
    }

    public function stop(): void
    {
        ++$this->stopGeneration;
    }

    public function enqueue(\Closure $callback): void
    {
        $this->queued[] = $callback;
    }

    public function fire(float $interval): void
    {
        foreach ($this->timers as $index => $timer) {
            if ($timer->getInterval() !== $interval) {
                continue;
            }
            array_splice($this->timers, $index, 1);
            ($timer->getCallback())();

            return;
        }

        throw new \LogicException('timer_not_found: ' . $interval);
    }

    public function hasTimer(float $interval): bool
    {
        foreach ($this->timers as $timer) {
            if ($timer->getInterval() === $interval) {
                return true;
            }
        }

        return false;
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer((float) $interval, $callback);
        $this->timers[] = $timer;

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer((float) $interval, $callback, true);
        $this->timers[] = $timer;

        return $timer;
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

    public function addSignal($signal, $listener): void
    {
    }

    public function removeSignal($signal, $listener): void
    {
    }
}

/** Each refresh reads new rates, so that it yields new funding events. */
final class RotationFundingClient implements HyperliquidPaperFundingRateClientInterface
{
    private int $calls = 0;

    public function fundingRates(): array
    {
        ++$this->calls;

        return [
            ['coin' => 'BTC', 'funding_rate' => '0.000012' . $this->calls],
            ['coin' => 'ETH', 'funding_rate' => '-0.000025' . $this->calls],
        ];
    }
}

final class RotationRecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>, level: string, probe: array<string, mixed>|null}> */
    public array $records = [];

    /** @var (\Closure(): array<string, mixed>)|null observes the source when a record is logged */
    public ?\Closure $probe = null;

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'message' => (string) $message,
            'context' => $context,
            'level' => (string) $level,
            'probe' => $this->probe === null ? null : ($this->probe)(),
        ];
    }

    /** @return list<array<string, mixed>> what the probe saw at each record of this message */
    public function probesOf(string $message): array
    {
        $probes = [];
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                $probes[] = $record['probe'] ?? throw new \LogicException('no probe: ' . $message);
            }
        }

        return $probes;
    }

    /** @return array<string, mixed> what the probe saw at the only record of this message */
    public function probeOf(string $message): array
    {
        $probes = $this->probesOf($message);
        if (\count($probes) !== 1) {
            throw new \LogicException('expected one record: ' . $message);
        }

        return $probes[0];
    }

    public function levelOf(string $message): string
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record['level'];
            }
        }

        throw new \LogicException('no log record: ' . $message);
    }

    /** @return list<string> */
    public function rotationMessages(): array
    {
        return $this->messages('_rotation_');
    }

    /** @return list<string> */
    public function messages(string $needle): array
    {
        return array_values(array_filter(
            array_column($this->records, 'message'),
            static fn (string $message): bool => str_contains($message, $needle),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function all(string $message): array
    {
        return array_values(array_column(array_filter(
            $this->records,
            static fn (array $record): bool => $record['message'] === $message,
        ), 'context'));
    }

    /** @return array<string, mixed> */
    public function last(string $message): array
    {
        $all = $this->all($message);
        if ($all === []) {
            throw new \LogicException('no log record: ' . $message);
        }

        return $all[array_key_last($all)];
    }
}
