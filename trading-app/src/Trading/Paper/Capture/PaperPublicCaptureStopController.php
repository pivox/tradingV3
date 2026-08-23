<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
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

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly PaperLiveMarketDataSourceInterface $source,
    ) {
    }

    public function start(int $durationSeconds): void
    {
        if ($durationSeconds < self::MIN_DURATION_SECONDS
            || $durationSeconds > self::MAX_DURATION_SECONDS
            || $this->timer !== null
        ) {
            throw new \InvalidArgumentException('paper_public_capture_duration_invalid');
        }

        $listener = function (): void {
            $this->requestStop();
        };
        $this->timer = $this->loop->addTimer($durationSeconds, $listener);

        if (function_exists('pcntl_signal')) {
            foreach ([\SIGINT, \SIGTERM] as $signal) {
                $this->signalListeners[$signal] = $listener;
                $this->loop->addSignal($signal, $listener);
            }
        }
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
    }

    private function requestStop(): void
    {
        if ($this->stopRequested) {
            return;
        }
        $this->stopRequested = true;
        try {
            $this->source->requestHealthyOperatorStop();
        } catch (\Throwable) {
            try {
                $this->source->stop();
            } catch (\Throwable) {
                // The dataset capture will observe an abnormal terminal state.
            }
        }
    }
}
