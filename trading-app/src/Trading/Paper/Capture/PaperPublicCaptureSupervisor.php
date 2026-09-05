<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class PaperPublicCaptureSupervisor
{
    public function __construct(
        private PaperPublicCaptureAttemptExecutorInterface $executor,
        private LoggerInterface $logger = new NullLogger(),
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
            $result = $this->executor->execute(
                $venueIdentity->value,
                $datasetId,
                $durationSeconds,
            );
            $this->logger->info('paper_public_capture_attempt_finished', [
                'source_venue' => $venueIdentity->value,
                'dataset_id' => $datasetId,
                'attempt' => $attempt,
                'exit_code' => $result->exitCode,
                'term_signal' => $result->termSignal,
                'pid' => $result->pid,
                'started_at' => $result->startedAt,
                'ended_at' => $result->endedAt,
                'stdout_tail' => $result->stdoutTail,
                'stderr_tail' => $result->stderrTail,
                'orphan_finalized' => $result->orphanFinalized,
            ]);
            if ($result->orphanFinalized === false) {
                return PaperPublicCaptureSupervisorResult::orphanFinalizationFailed(
                    $venueIdentity->value,
                    $attempt,
                );
            }
            if ($result->termSignal !== null) {
                return PaperPublicCaptureSupervisorResult::interrupted(
                    $venueIdentity->value,
                    $attempt,
                );
            }
            if ($result->succeeded()) {
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
