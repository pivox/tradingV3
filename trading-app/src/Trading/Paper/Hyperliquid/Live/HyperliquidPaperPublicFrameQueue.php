<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

final class HyperliquidPaperPublicFrameQueue
{
    /** @var list<string> */
    private array $frames = [];

    private int $bytes = 0;

    public function enqueue(#[\SensitiveParameter] string $frame): void
    {
        $frameBytes = \strlen($frame);
        if (\count($this->frames) >= HyperliquidPaperLivePolicy::MAX_QUEUED_FRAMES
            || $frameBytes > HyperliquidPaperLivePolicy::MAX_QUEUED_BYTES - $this->bytes
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'market_data_backpressure_exhausted',
            );
        }

        // A heartbeat response must not expire behind a burst of market frames.
        // Market frames themselves retain their original FIFO order.
        if ($this->isPong($frame)) {
            array_unshift($this->frames, $frame);
        } else {
            $this->frames[] = $frame;
        }
        $this->bytes += $frameBytes;
    }

    public function dequeue(): ?string
    {
        $frame = array_shift($this->frames);
        if ($frame === null) {
            return null;
        }

        $this->bytes -= \strlen($frame);

        return $frame;
    }

    public function peek(): ?string
    {
        return $this->frames[0] ?? null;
    }

    public function count(): int
    {
        return \count($this->frames);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function clear(): void
    {
        $this->frames = [];
        $this->bytes = 0;
    }

    private function isPong(string $frame): bool
    {
        if (!str_contains($frame, 'pong')) {
            return false;
        }

        try {
            return json_decode($frame, true, 512, \JSON_THROW_ON_ERROR) === [
                'channel' => 'pong',
            ];
        } catch (\JsonException) {
            return false;
        }
    }
}
