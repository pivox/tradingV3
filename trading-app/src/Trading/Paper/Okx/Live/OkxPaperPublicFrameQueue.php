<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

final class OkxPaperPublicFrameQueue
{
    /** @var list<string> */
    private array $frames = [];

    private int $bytes = 0;

    public function enqueue(#[\SensitiveParameter] string $frame): void
    {
        $frameBytes = strlen($frame);
        if (count($this->frames) >= OkxPaperLivePolicy::MAX_QUEUED_FRAMES
            || $frameBytes > OkxPaperLivePolicy::MAX_QUEUED_BYTES - $this->bytes) {
            throw new OkxPaperLiveIntegrityException('market_data_backpressure_exhausted');
        }

        $this->frames[] = $frame;
        $this->bytes += $frameBytes;
    }

    public function dequeue(): ?string
    {
        $frame = array_shift($this->frames);
        if ($frame === null) {
            return null;
        }

        $this->bytes -= strlen($frame);

        return $frame;
    }

    public function peek(): ?string
    {
        return $this->frames[0] ?? null;
    }

    /** @return list<string> */
    public function frames(): array
    {
        return $this->frames;
    }

    /** @param list<string> $frames */
    public function replace(array $frames): void
    {
        $replacement = new self();
        foreach ($frames as $frame) {
            $replacement->enqueue($frame);
        }
        $this->frames = $replacement->frames;
        $this->bytes = $replacement->bytes;
    }

    public function count(): int
    {
        return count($this->frames);
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
}
