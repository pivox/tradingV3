<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class PaperPublicCaptureStopController
{
    public const MIN_DURATION_SECONDS = 300;
    public const MAX_DURATION_SECONDS = 604_800;

    private ?TimerInterface $timer = null;

    /** @var array<int, \Closure> */
    private array $signalListeners = [];

    private bool $stopRequested = false;

    private ?int $deferredDurationSeconds = null;

    private ?string $completionSnapshotSymbol = null;

    private bool $awaitingPostSnapshotLiveEvent = false;

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly PaperLiveMarketDataSourceInterface $source,
    ) {
    }

    public function start(int $durationSeconds): void
    {
        $this->assertCanStart($durationSeconds);
        $this->registerSignalListeners();
        $this->scheduleTimer($durationSeconds);
    }

    /**
     * The sources emit durable initial boundaries in canonical symbol order. Hyperliquid
     * completes warmup at the final boundary. OKX still performs its REST-to-WebSocket
     * candle bridge afterwards, so its duration begins at the first durable WebSocket
     * event following the final initial or reconnect boundary.
     *
     * @param list<string> $symbols
     */
    public function startAfterInitialSnapshots(int $durationSeconds, array $symbols): void
    {
        $this->assertCanStart($durationSeconds);
        if ($symbols === [] || count($symbols) !== count(array_unique($symbols))) {
            throw new \InvalidArgumentException('paper_public_capture_symbols_invalid');
        }
        sort($symbols, SORT_STRING);
        foreach ($symbols as $symbol) {
            if (preg_match('/\A[A-Z0-9]{2,32}\z/D', $symbol) !== 1) {
                throw new \InvalidArgumentException('paper_public_capture_symbols_invalid');
            }
        }
        $this->completionSnapshotSymbol = $symbols[array_key_last($symbols)];

        $this->deferredDurationSeconds = $durationSeconds;
        $this->registerSignalListeners();
    }

    public function observe(PaperMarketEvent $event): void
    {
        if ($this->stopRequested || $this->deferredDurationSeconds === null) {
            return;
        }
        if ($this->source->venue() === PaperMarketDataVenue::OKX) {
            if ($event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                && in_array($event->payload['reason'] ?? null, ['initial', 'reconnect'], true)
                && $event->symbol === $this->completionSnapshotSymbol
            ) {
                $this->awaitingPostSnapshotLiveEvent = true;

                return;
            }
            $origin = $event->payload['origin'] ?? null;
            if (!$this->awaitingPostSnapshotLiveEvent
                || !\is_string($origin)
                || !str_starts_with($origin, 'ws_')
            ) {
                return;
            }
        } elseif ($event->channel !== PaperMarketDataChannel::SNAPSHOT_BOUNDARY
            || !in_array($event->payload['reason'] ?? null, ['initial', 'reconnect'], true)
            || $event->symbol !== $this->completionSnapshotSymbol
        ) {
            return;
        }

        $durationSeconds = $this->deferredDurationSeconds;
        $this->deferredDurationSeconds = null;
        $this->awaitingPostSnapshotLiveEvent = false;
        $this->scheduleTimer($durationSeconds);
    }

    public function close(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
        foreach ($this->signalListeners as $signal => $listener) {
            $this->loop->removeSignal($signal, $listener);
        }
        $this->signalListeners = [];
        $this->deferredDurationSeconds = null;
        $this->completionSnapshotSymbol = null;
        $this->awaitingPostSnapshotLiveEvent = false;
    }

    private function assertCanStart(int $durationSeconds): void
    {
        if ($durationSeconds < self::MIN_DURATION_SECONDS
            || $durationSeconds > self::MAX_DURATION_SECONDS
            || $this->timer !== null
            || $this->deferredDurationSeconds !== null
            || $this->signalListeners !== []
        ) {
            throw new \InvalidArgumentException('paper_public_capture_duration_invalid');
        }
    }

    private function scheduleTimer(int $durationSeconds): void
    {
        $this->timer = $this->loop->addTimer($durationSeconds, function (): void {
            $this->requestHealthyStop();
        });
    }

    private function registerSignalListeners(): void
    {
        $listener = function (): void {
            $this->requestAbnormalStop();
        };

        if (function_exists('pcntl_signal')) {
            foreach ([\SIGINT, \SIGTERM] as $signal) {
                $this->signalListeners[$signal] = $listener;
                $this->loop->addSignal($signal, $listener);
            }
        }
    }

    private function requestHealthyStop(): void
    {
        if ($this->stopRequested) {
            return;
        }
        $this->stopRequested = true;
        try {
            $this->source->requestHealthyOperatorStop();
            $this->loop->stop();
        } catch (\Throwable) {
            try {
                $this->source->stop();
            } catch (\Throwable) {
                // The dataset capture will observe an abnormal terminal state.
            }
            $this->loop->stop();
        }
    }

    private function requestAbnormalStop(): void
    {
        if ($this->stopRequested) {
            return;
        }
        $this->stopRequested = true;
        try {
            $this->source->stop();
        } catch (\Throwable) {
            // The dataset capture will observe an abnormal terminal state.
        }
        $this->loop->stop();
    }
}
