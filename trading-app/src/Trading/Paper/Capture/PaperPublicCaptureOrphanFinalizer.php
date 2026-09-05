<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PaperPublicCaptureOrphanFinalizer
{
    private const MAX_MANIFEST_BYTES = 200_000;

    public function __construct(
        #[Autowire('%env(resolve:PAPER_MARKET_DATA_ROOT)%')]
        private string $dataRoot,
        private PaperDatasetManifestCodec $codec = new PaperDatasetManifestCodec(),
    ) {
    }

    public function finalize(string $datasetId): bool
    {
        try {
            PaperDatasetManifest::assertDatasetId($datasetId);
            $datasetPath = $this->dataRoot . '/' . $datasetId;
            if (!file_exists($datasetPath) && !is_link($datasetPath)) {
                return true;
            }
            $manifestPath = $datasetPath . '/manifest.json';
            $size = @filesize($manifestPath);
            if (!\is_int($size) || $size < 1 || $size > self::MAX_MANIFEST_BYTES) {
                return false;
            }
            $contents = @file_get_contents($manifestPath);
            if (!\is_string($contents) || \strlen($contents) !== $size) {
                return false;
            }
            $manifest = $this->codec->decode($contents);
            if (!hash_equals($datasetId, $manifest->datasetId)) {
                return false;
            }
            $recorder = new PaperDatasetRecorder(
                $this->dataRoot,
                $manifest,
                codec: $this->codec,
            );
            if ($recorder->manifest()->state === PaperDatasetState::RECORDING) {
                $recorder->markIncomplete();
            }

            return $recorder->manifest()->state !== PaperDatasetState::RECORDING;
        } catch (\Throwable) {
            return false;
        }
    }
}
