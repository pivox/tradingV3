<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

final readonly class PaperPublicCaptureAttemptResult
{
    public function __construct(
        public int $exitCode,
        public ?int $termSignal = null,
        public ?int $pid = null,
        public string $startedAt = '',
        public string $endedAt = '',
        public string $stdoutTail = '',
        public string $stderrTail = '',
        public ?bool $orphanFinalized = null,
    ) {
        if ($exitCode < 0 || $exitCode > 255) {
            throw new \InvalidArgumentException('paper_public_capture_attempt_exit_code_invalid');
        }
        if ($termSignal !== null && ($termSignal < 1 || $termSignal > 255)) {
            throw new \InvalidArgumentException('paper_public_capture_attempt_signal_invalid');
        }
        if ($pid !== null && $pid < 1) {
            throw new \InvalidArgumentException('paper_public_capture_attempt_pid_invalid');
        }
        if (\strlen($stdoutTail) > 8192 || \strlen($stderrTail) > 8192) {
            throw new \InvalidArgumentException('paper_public_capture_attempt_output_invalid');
        }
    }

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
