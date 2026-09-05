<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

interface PaperPublicCaptureAttemptExecutorInterface
{
    public function execute(
        string $venue,
        string $datasetId,
        int $durationSeconds,
    ): PaperPublicCaptureAttemptResult;
}
