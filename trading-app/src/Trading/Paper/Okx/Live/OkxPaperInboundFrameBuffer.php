<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

/**
 * Websocket frames read while the durable queue is full: in memory only, in
 * arrival order. Keeping the socket read during a backlog is what keeps OKX from
 * closing a slow consumer (1006); a crash loses these frames exactly like the
 * socket buffer, and the restart recovers them from REST.
 */
final class OkxPaperInboundFrameBuffer
{
    /** Accounted per frame on top of its bytes: string and list node overhead. */
    public const FRAME_OVERHEAD_BYTES = 128;

    /** @var \SplQueue<array{string, float}> */
    private \SplQueue $frames;

    private int $bytes = 0;

    public function __construct()
    {
        $this->frames = new \SplQueue();
    }

    public function append(#[\SensitiveParameter] string $frame, float $receivedAt): void
    {
        $this->frames->enqueue([$frame, $receivedAt]);
        $this->bytes += \strlen($frame) + self::FRAME_OVERHEAD_BYTES;
    }

    public function shift(): ?string
    {
        if ($this->frames->isEmpty()) {
            return null;
        }
        [$frame] = $this->frames->dequeue();
        $this->bytes -= \strlen($frame) + self::FRAME_OVERHEAD_BYTES;

        return $frame;
    }

    public function count(): int
    {
        return $this->frames->count();
    }

    /** Accounted bytes (frames and their overhead). */
    public function bytes(): int
    {
        return $this->bytes;
    }

    public function oldestReceivedAt(): ?float
    {
        return $this->frames->isEmpty() ? null : $this->frames->bottom()[1];
    }

    public function clear(): void
    {
        $this->frames = new \SplQueue();
        $this->bytes = 0;
    }
}
