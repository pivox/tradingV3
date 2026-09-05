<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

final readonly class PaperPublicCaptureAttemptResult
{
    public function __construct(public int $exitCode)
    {
        if ($exitCode < 0 || $exitCode > 255) {
            throw new \InvalidArgumentException('paper_public_capture_attempt_exit_code_invalid');
        }
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
