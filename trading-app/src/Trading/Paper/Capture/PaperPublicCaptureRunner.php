<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PaperPublicCaptureRunner
{
    private const RECORDING_MANIFEST_CHECKPOINT_INTERVAL = 100;

    public function __construct(
        private PaperPublicLiveManifestFactory $manifests,
        private PaperPublicDatasetCapture $capture,
        private PaperPublicLiveSourceFactoryInterface $okxSourceFactory,
        private PaperPublicLiveSourceFactoryInterface $hyperliquidSourceFactory,
        #[Autowire('%env(resolve:PAPER_MARKET_DATA_ROOT)%')]
        private string $dataRoot,
    ) {
    }

    public function run(
        string $venue,
        string $datasetId,
        int $durationSeconds,
        ?LoopInterface $loop = null,
    ): PaperPublicCaptureResult {
        $venueIdentity = PaperMarketDataVenue::tryFrom($venue);
        if ($venueIdentity === null) {
            throw new \InvalidArgumentException('paper_public_capture_venue_invalid');
        }
        if ($durationSeconds < PaperPublicCaptureStopController::MIN_DURATION_SECONDS
            || $durationSeconds > PaperPublicCaptureStopController::MAX_DURATION_SECONDS
        ) {
            throw new \InvalidArgumentException('paper_public_capture_duration_invalid');
        }

        $manifest = $this->manifests->create($venueIdentity, $datasetId);
        $recorder = new PaperDatasetRecorder(
            $this->dataRoot,
            $manifest,
            recordingManifestCheckpointInterval: self::RECORDING_MANIFEST_CHECKPOINT_INTERVAL,
        );
        if ($recorder->manifest()->state !== PaperDatasetState::RECORDING) {
            throw new \LogicException('paper_public_capture_dataset_terminal');
        }

        $factory = match ($venueIdentity) {
            PaperMarketDataVenue::OKX => $this->okxSourceFactory,
            PaperMarketDataVenue::HYPERLIQUID => $this->hyperliquidSourceFactory,
        };
        $captureLoop = $loop ?? Loop::get();
        $source = $factory->create($recorder->datasetDirectory(), $captureLoop);
        if ($source->venue() !== $venueIdentity) {
            throw new \LogicException('paper_public_capture_source_identity_mismatch');
        }

        $stops = new PaperPublicCaptureStopController($captureLoop, $source);
        $stops->startAfterInitialSnapshots($durationSeconds, array_keys($manifest->symbols));
        $durableTail = $recorder->lastDurableEvent();
        if ($durableTail !== null) {
            $stops->observe($durableTail);
        }
        try {
            $completed = $this->capture->run(
                $recorder,
                $source,
                static function (PaperMarketEvent $event) use ($stops): void {
                    $stops->observe($event);
                },
            );
        } finally {
            $stops->close();
        }

        return PaperPublicCaptureResult::fromManifest($completed);
    }
}
