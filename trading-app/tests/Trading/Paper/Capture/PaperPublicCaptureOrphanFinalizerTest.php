<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\PaperPublicCaptureOrphanFinalizer;
use App\Trading\Paper\Capture\PaperPublicLiveManifestFactory;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperPublicCaptureOrphanFinalizer::class)]
final class PaperPublicCaptureOrphanFinalizerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/paper-orphan-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0700, true));
        $resolved = realpath($this->root);
        self::assertIsString($resolved);
        $this->root = $resolved;
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testTerminalizesARecordingDatasetAfterAbruptChildExit(): void
    {
        $datasetId = 'orphaned-okx-attempt-mainnet';
        $manifest = (new PaperPublicLiveManifestFactory())->create(
            PaperMarketDataVenue::OKX,
            $datasetId,
        );
        new PaperDatasetRecorder($this->root, $manifest);

        self::assertTrue(
            (new PaperPublicCaptureOrphanFinalizer($this->root))->finalize($datasetId),
        );
        $stored = (new PaperDatasetManifestCodec())->decode(
            (string) file_get_contents($this->root . '/' . $datasetId . '/manifest.json'),
        );
        self::assertSame(PaperDatasetState::INCOMPLETE, $stored->state);
    }

    public function testMissingDatasetNeedsNoOrphanFinalization(): void
    {
        self::assertTrue(
            (new PaperPublicCaptureOrphanFinalizer($this->root))->finalize(
                'missing-okx-attempt-mainnet',
            ),
        );
    }

    public function testFailsClosedWhenAnExistingDatasetCannotBeAuthenticated(): void
    {
        self::assertTrue(mkdir($this->root . '/broken-okx-attempt-mainnet', 0700));

        self::assertFalse(
            (new PaperPublicCaptureOrphanFinalizer($this->root))->finalize(
                'broken-okx-attempt-mainnet',
            ),
        );
    }
}
