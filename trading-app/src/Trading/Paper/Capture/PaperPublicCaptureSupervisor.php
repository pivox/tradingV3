<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;

final readonly class PaperPublicCaptureSupervisor
{
    public function __construct(
        private PaperPublicCaptureAttemptExecutorInterface $executor,
    ) {
    }

    public function run(
        string $venue,
        string $datasetPrefix,
        int $durationSeconds,
        int $maximumAttempts,
    ): PaperPublicCaptureSupervisorResult {
        $venueIdentity = PaperMarketDataVenue::tryFrom($venue)
            ?? throw new \InvalidArgumentException('paper_public_capture_venue_invalid');
        if ($durationSeconds < PaperPublicCaptureStopController::MIN_DURATION_SECONDS
            || $durationSeconds > PaperPublicCaptureStopController::MAX_DURATION_SECONDS
        ) {
            throw new \InvalidArgumentException('paper_public_capture_duration_invalid');
        }
        if ($maximumAttempts < 1 || $maximumAttempts > 99) {
            throw new \InvalidArgumentException('paper_public_capture_attempts_invalid');
        }
        PaperDatasetManifest::assertDatasetId($datasetPrefix);
        $runId = bin2hex(random_bytes(16));

        for ($attempt = 1; $attempt <= $maximumAttempts; ++$attempt) {
            $datasetId = sprintf(
                '%s-run-%s-attempt-%03d-mainnet',
                $datasetPrefix,
                $runId,
                $attempt,
            );
            PaperDatasetManifest::assertDatasetId($datasetId);
            if ($this->executor->execute(
                $venueIdentity->value,
                $datasetId,
                $durationSeconds,
            )->succeeded()) {
                return PaperPublicCaptureSupervisorResult::success(
                    $venueIdentity->value,
                    $attempt,
                    $datasetId,
                );
            }
        }

        return PaperPublicCaptureSupervisorResult::exhausted(
            $venueIdentity->value,
            $maximumAttempts,
        );
    }
}
