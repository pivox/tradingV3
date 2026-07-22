<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Historical;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Okx\Historical\OkxHistoricalCheckpointStore;
use App\Trading\Paper\Okx\Historical\OkxHistoricalIntegrityException;
use App\Trading\Paper\Okx\Historical\OkxHistoricalRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxHistoricalCheckpointStore::class)]
final class OkxHistoricalCheckpointStoreTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'okx-checkpoint-test-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create test directory.');
        }
        $resolved = realpath($path);
        self::assertIsString($resolved);
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}> */
    public static function invalidAcquisitionStateProvider(): iterable
    {
        yield 'unknown key' => [static function (array $state): array {
            $state['unexpected'] = true;

            return $state;
        }];
        yield 'streams is a list' => [static function (array $state): array {
            $state['streams'] = [['kind' => 'candle']];

            return $state;
        }];
        yield 'negative page count' => [static function (array $state): array {
            $state['page_count'] = -1;

            return $state;
        }];
        yield 'page count exceeds request bound' => [static function (array $state): array {
            $state['page_count'] = 3;

            return $state;
        }];
        yield 'event count exceeds request bound' => [static function (array $state): array {
            $state['event_count'] = 4;

            return $state;
        }];
        yield 'page count disagrees with pages' => [static function (array $state): array {
            $state['page_count'] = 1;

            return $state;
        }];
        yield 'event count disagrees with pages' => [static function (array $state): array {
            $state['event_count'] = 1;

            return $state;
        }];
        yield 'stream key disagrees with stream' => [static function (array $state): array {
            $state['streams']['BTCUSDT/candle_1m'] = [
                'kind' => 'candle',
                'symbol' => 'BTCUSDT',
                'bar' => '5m',
                'next_cursor' => '1',
                'complete' => false,
                'pages' => [],
            ];

            return $state;
        }];
        yield 'page descriptor has wrong row count type' => [static function (array $state): array {
            $state['streams']['BTCUSDT/candle_1m'] = [
                'kind' => 'candle',
                'symbol' => 'BTCUSDT',
                'bar' => '1m',
                'next_cursor' => '1',
                'complete' => false,
                'pages' => [[
                    'file' => 'BTCUSDT-candle_1m-000001.ndjson',
                    'sha256' => str_repeat('0', 64),
                    'chain_sha256' => str_repeat('0', 64),
                    'row_count' => '0',
                ]],
            ];
            $state['page_count'] = 1;

            return $state;
        }];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalidAcquisitionStateProvider')]
    public function testLoadRejectsSemanticallyInvalidAcquisitionState(\Closure $mutate): void
    {
        $request = $this->request('okx-checkpoint-acquisition-001');
        $directory = $this->datasetDirectory('acquisition');
        (new OkxHistoricalCheckpointStore($directory, $request))->loadOrCreate();
        $path = $this->checkpointDirectory($directory) . '/checkpoint.json';
        $state = $this->readJson($path);
        $this->writeJson($path, $mutate($state));

        $this->expectException(OkxHistoricalIntegrityException::class);
        $this->expectExceptionMessage('okx_acquisition_checkpoint_invalid');

        (new OkxHistoricalCheckpointStore($directory, $request))->loadOrCreate();
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}> */
    public static function invalidEmissionStateProvider(): iterable
    {
        yield 'unknown key' => [static function (array $state): array {
            $state['unexpected'] = true;

            return $state;
        }];
        yield 'phase is unknown' => [static function (array $state): array {
            $state['phase'] = 'unknown';

            return $state;
        }];
        yield 'emit index is negative' => [static function (array $state): array {
            $state['emit_index'] = -1;

            return $state;
        }];
        yield 'emit index exceeds event count' => [static function (array $state): array {
            $state['emit_index'] = 1;

            return $state;
        }];
        yield 'fetching has a pending event' => [static function (array $state): array {
            $state['pending_event'] = ['natural_identity' => 'x', 'event' => []];

            return $state;
        }];
        yield 'complete has a pending event' => [static function (array $state): array {
            $state['phase'] = 'complete';
            $state['pending_event'] = ['natural_identity' => 'x', 'event' => []];

            return $state;
        }];
        yield 'failed has no failure reason' => [static function (array $state): array {
            $state['phase'] = 'failed';

            return $state;
        }];
        yield 'fetching has a failure reason' => [static function (array $state): array {
            $state['failure_reason'] = 'failure';

            return $state;
        }];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalidEmissionStateProvider')]
    public function testLoadRejectsSemanticallyInvalidEmissionState(\Closure $mutate): void
    {
        $request = $this->request('okx-checkpoint-emission-001');
        $directory = $this->datasetDirectory('emission');
        (new OkxHistoricalCheckpointStore($directory, $request))->loadOrCreate();
        $path = $this->checkpointDirectory($directory) . '/emission.json';
        $state = $this->readJson($path);
        $this->writeJson($path, $mutate($state));

        $this->expectException(OkxHistoricalIntegrityException::class);
        $this->expectExceptionMessage('okx_acquisition_checkpoint_invalid');

        (new OkxHistoricalCheckpointStore($directory, $request))->loadOrCreate();
    }

    public function testEmissionOverlayFromAnotherDatasetIsRejected(): void
    {
        $firstRequest = $this->request('okx-checkpoint-overlay-first');
        $secondRequest = $this->request('okx-checkpoint-overlay-second');
        $firstDirectory = $this->datasetDirectory('overlay-first');
        $secondDirectory = $this->datasetDirectory('overlay-second');
        (new OkxHistoricalCheckpointStore($firstDirectory, $firstRequest))->loadOrCreate();
        (new OkxHistoricalCheckpointStore($secondDirectory, $secondRequest))->loadOrCreate();
        self::assertTrue(copy(
            $this->checkpointDirectory($secondDirectory) . '/emission.json',
            $this->checkpointDirectory($firstDirectory) . '/emission.json',
        ));

        $this->expectException(OkxHistoricalIntegrityException::class);
        $this->expectExceptionMessage('okx_acquisition_checkpoint_request_mismatch');

        (new OkxHistoricalCheckpointStore($firstDirectory, $firstRequest))->loadOrCreate();
    }

    public function testLoadRecreatesMissingEmissionForValidVirginAcquisitionCheckpoint(): void
    {
        $request = $this->request('okx-checkpoint-missing-virgin-emission');
        $directory = $this->datasetDirectory('missing-virgin-emission');
        $store = new OkxHistoricalCheckpointStore($directory, $request);
        $initial = $store->loadOrCreate();
        $emissionPath = $this->checkpointDirectory($directory) . '/emission.json';
        self::assertTrue(unlink($emissionPath));
        unset($store);

        $recreated = (new OkxHistoricalCheckpointStore($directory, $request))->loadOrCreate();

        self::assertEquals($initial, $recreated);
        self::assertFileExists($emissionPath);
    }

    public function testLoadRejectsMissingEmissionForNonVirginAcquisitionCheckpoint(): void
    {
        $request = $this->request('okx-checkpoint-missing-non-virgin-emission');
        $directory = $this->datasetDirectory('missing-non-virgin-emission');
        $store = new OkxHistoricalCheckpointStore($directory, $request);
        $state = $store->loadOrCreate();
        $state['streams']['BTCUSDT/candle_1m'] = [
            'kind' => 'candle',
            'symbol' => 'BTCUSDT',
            'bar' => '1m',
            'next_cursor' => '1',
            'complete' => false,
            'pages' => [],
        ];
        $store->saveAcquisition($state);
        self::assertTrue(unlink($this->checkpointDirectory($directory) . '/emission.json'));
        unset($store);

        $this->expectException(OkxHistoricalIntegrityException::class);
        $this->expectExceptionMessage('okx_acquisition_checkpoint_invalid');

        (new OkxHistoricalCheckpointStore($directory, $request))->loadOrCreate();
    }

    public function testManagedParentSymlinkIsRejectedWithoutWritingThroughIt(): void
    {
        $request = $this->request('okx-checkpoint-parent-symlink');
        $directory = $this->datasetDirectory('parent-symlink');
        $outside = $this->testRoot . '/outside';
        self::assertTrue(mkdir($outside, 0700));
        self::assertTrue(symlink($outside, $directory . '/checkpoints'));

        try {
            new OkxHistoricalCheckpointStore($directory, $request);
            self::fail('A managed parent symlink must be rejected.');
        } catch (OkxHistoricalIntegrityException $exception) {
            self::assertSame('okx_acquisition_file_invalid', $exception->getMessage());
        }

        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
    }

    public function testWriterLockIsPrivateExclusiveAndDoesNotMutateCheckpointOnContention(): void
    {
        $request = $this->request('okx-checkpoint-writer-lock');
        $directory = $this->datasetDirectory('writer-lock');
        $checkpointDirectory = $this->checkpointDirectory($directory);
        $lockPath = $checkpointDirectory . '/.writer.lock';
        $checkpointPath = $checkpointDirectory . '/checkpoint.json';
        $emissionPath = $checkpointDirectory . '/emission.json';
        [$checkpointBefore, $emissionBefore] = (function () use (
            $directory,
            $request,
            $checkpointDirectory,
            $lockPath,
            $checkpointPath,
            $emissionPath,
        ): array {
            $first = new OkxHistoricalCheckpointStore($directory, $request);
            $first->loadOrCreate();
            self::assertFileExists($lockPath);
            self::assertSame(0600, fileperms($lockPath) & 0777);
            self::assertSame(1, lstat($lockPath)['nlink'] ?? null);
            $checkpointBefore = file_get_contents($checkpointPath);
            $emissionBefore = file_get_contents($emissionPath);

            try {
                new OkxHistoricalCheckpointStore($directory, $request);
                self::fail('A second acquisition writer must fail immediately.');
            } catch (OkxHistoricalIntegrityException $exception) {
                self::assertSame('okx_acquisition_lock_unavailable', $exception->getMessage());
            }

            self::assertSame($checkpointBefore, file_get_contents($checkpointPath));
            self::assertSame($emissionBefore, file_get_contents($emissionPath));
            self::assertSame([], glob($checkpointDirectory . '/pages/*.ndjson') ?: []);

            return [$checkpointBefore, $emissionBefore];
        })();
        self::assertSame($checkpointBefore, file_get_contents($checkpointPath));
        self::assertSame($emissionBefore, file_get_contents($emissionPath));
        self::assertSame([], glob($checkpointDirectory . '/pages/*.ndjson') ?: []);
    }

    public function testWriterLockIsReleasedWhenStoreLifetimeEnds(): void
    {
        $request = $this->request('okx-checkpoint-writer-lock-release');
        $directory = $this->datasetDirectory('writer-lock-release');
        $checkpointPath = $this->checkpointDirectory($directory) . '/checkpoint.json';
        $first = new OkxHistoricalCheckpointStore($directory, $request);
        $first->loadOrCreate();
        unset($first);

        $reopened = new OkxHistoricalCheckpointStore($directory, $request);
        self::assertEquals($this->readJson($checkpointPath), array_intersect_key(
            $reopened->loadOrCreate(),
            array_flip(['schema_version', 'dataset_id', 'request_sha256', 'streams', 'page_count', 'event_count']),
        ));
    }

    public function testWriterLockSymlinkIsRejectedWithoutWritingThroughIt(): void
    {
        $request = $this->request('okx-checkpoint-writer-lock-symlink');
        $directory = $this->datasetDirectory('writer-lock-symlink');
        $checkpointDirectory = $this->createCheckpointDirectories($directory);
        $outside = $this->testRoot . '/writer-lock-symlink-target';
        self::assertSame(7, file_put_contents($outside, 'outside'));
        self::assertTrue(symlink($outside, $checkpointDirectory . '/.writer.lock'));

        try {
            new OkxHistoricalCheckpointStore($directory, $request);
            self::fail('A writer lock symlink must be rejected.');
        } catch (OkxHistoricalIntegrityException $exception) {
            self::assertSame('okx_acquisition_lock_invalid', $exception->getMessage());
        }

        self::assertSame('outside', file_get_contents($outside));
        self::assertFileDoesNotExist($checkpointDirectory . '/checkpoint.json');
        self::assertFileDoesNotExist($checkpointDirectory . '/emission.json');
    }

    public function testWriterLockWithUnsafePermissionsIsRejectedBeforeCheckpointCreation(): void
    {
        $request = $this->request('okx-checkpoint-writer-lock-permissions');
        $directory = $this->datasetDirectory('writer-lock-permissions');
        $checkpointDirectory = $this->createCheckpointDirectories($directory);
        $lockPath = $checkpointDirectory . '/.writer.lock';
        self::assertSame(0, file_put_contents($lockPath, ''));
        self::assertTrue(chmod($lockPath, 0644));

        try {
            new OkxHistoricalCheckpointStore($directory, $request);
            self::fail('A non-private writer lock must be rejected.');
        } catch (OkxHistoricalIntegrityException $exception) {
            self::assertSame('okx_acquisition_lock_invalid', $exception->getMessage());
        }

        self::assertSame(0644, fileperms($lockPath) & 0777);
        self::assertFileDoesNotExist($checkpointDirectory . '/checkpoint.json');
        self::assertFileDoesNotExist($checkpointDirectory . '/emission.json');
    }

    public function testWriterLockReplacementIsDetectedBeforeCheckpointPublication(): void
    {
        $request = $this->request('okx-checkpoint-writer-lock-replaced');
        $directory = $this->datasetDirectory('writer-lock-replaced');
        $store = new OkxHistoricalCheckpointStore($directory, $request);
        $state = $store->loadOrCreate();
        $checkpointDirectory = $this->checkpointDirectory($directory);
        $lockPath = $checkpointDirectory . '/.writer.lock';
        $checkpointPath = $checkpointDirectory . '/checkpoint.json';
        $checkpointBefore = file_get_contents($checkpointPath);
        self::assertTrue(rename($lockPath, $lockPath . '.displaced'));
        self::assertSame(0, file_put_contents($lockPath, ''));
        self::assertTrue(chmod($lockPath, 0600));

        try {
            $store->saveAcquisition($state);
            self::fail('A replaced writer lock must be rejected before publication.');
        } catch (OkxHistoricalIntegrityException $exception) {
            self::assertSame('okx_acquisition_lock_invalid', $exception->getMessage());
        }

        self::assertSame($checkpointBefore, file_get_contents($checkpointPath));
    }

    public function testManagedParentReplacementIsDetectedBeforePagePublication(): void
    {
        $request = $this->request('okx-checkpoint-parent-replacement');
        $directory = $this->datasetDirectory('parent-replacement');
        $store = new OkxHistoricalCheckpointStore($directory, $request);
        $pages = $this->checkpointDirectory($directory) . '/pages';
        $displaced = $this->checkpointDirectory($directory) . '/pages-displaced';
        $outside = $this->testRoot . '/replacement-target';
        self::assertTrue(mkdir($outside, 0700));
        self::assertTrue(rename($pages, $displaced));
        self::assertTrue(symlink($outside, $pages));

        try {
            $store->writePage('BTCUSDT-candle_1m-000001.ndjson', []);
            self::fail('A replaced managed parent must be rejected.');
        } catch (OkxHistoricalIntegrityException $exception) {
            self::assertSame('okx_acquisition_file_invalid', $exception->getMessage());
        }

        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
    }

    public function testPredictableStagingSymlinkIsIgnoredByExclusiveRandomStaging(): void
    {
        $request = $this->request('okx-checkpoint-random-staging');
        $directory = $this->datasetDirectory('random-staging');
        $checkpointDirectory = $this->checkpointDirectory($directory);
        self::assertTrue(mkdir($directory . '/checkpoints', 0700));
        self::assertTrue(mkdir($checkpointDirectory, 0700));
        self::assertTrue(mkdir($checkpointDirectory . '/pages', 0700));
        $outside = $this->testRoot . '/staging-target';
        self::assertSame(7, file_put_contents($outside, 'outside'));
        self::assertTrue(symlink($outside, $checkpointDirectory . '/checkpoint.json.staging'));

        try {
            (new OkxHistoricalCheckpointStore($directory, $request))->loadOrCreate();
        } catch (\Throwable $exception) {
            self::fail('A predictable staging symlink must not affect publication: ' . $exception->getMessage());
        }

        self::assertSame('outside', file_get_contents($outside));
        self::assertTrue(is_link($checkpointDirectory . '/checkpoint.json.staging'));
        self::assertFileExists($checkpointDirectory . '/checkpoint.json');
    }

    public function testAtomicPublicationSynchronizesPinnedParentDirectoryAfterRename(): void
    {
        $request = $this->request('okx-checkpoint-parent-sync');
        $directory = $this->datasetDirectory('parent-sync');
        $filesystem = new RecordingOkxCheckpointFilesystem();

        (new OkxHistoricalCheckpointStore($directory, $request, $filesystem))->loadOrCreate();

        $checkpointMove = array_search('move:checkpoint.json', $filesystem->operations, true);
        $checkpointParentSync = array_search('sync:okx_acquisition_directory_sync', $filesystem->operations, true);
        self::assertIsInt($checkpointMove);
        self::assertIsInt($checkpointParentSync);
        self::assertGreaterThan($checkpointMove, $checkpointParentSync);
    }

    private function request(string $datasetId): OkxHistoricalRequest
    {
        return new OkxHistoricalRequest(
            datasetId: $datasetId,
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
            maximumEvents: 3,
            maximumPages: 2,
        );
    }

    private function datasetDirectory(string $name): string
    {
        $directory = $this->testRoot . '/' . $name;
        self::assertTrue(mkdir($directory, 0700));

        return $directory;
    }

    private function checkpointDirectory(string $datasetDirectory): string
    {
        return $datasetDirectory . '/checkpoints/okx-acquisition';
    }

    private function createCheckpointDirectories(string $datasetDirectory): string
    {
        $checkpointDirectory = $this->checkpointDirectory($datasetDirectory);
        self::assertTrue(mkdir($datasetDirectory . '/checkpoints', 0700));
        self::assertTrue(mkdir($checkpointDirectory, 0700));
        self::assertTrue(mkdir($checkpointDirectory . '/pages', 0700));

        return $checkpointDirectory;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $state = json_decode((string) file_get_contents($path), true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function writeJson(string $path, array $state): void
    {
        self::assertNotFalse(file_put_contents($path, json_encode($state, \JSON_THROW_ON_ERROR) . "\n"));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                unlink($directory);
            }

            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeDirectory($directory . '/' . $entry);
        }
        rmdir($directory);
    }
}

final class RecordingOkxCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    /** @var list<string> */
    public array $operations = [];

    public function move(string $source, string $destination, string $operation): bool
    {
        $this->operations[] = 'move:' . basename($destination);

        return parent::move($source, $destination, $operation);
    }

    public function sync($handle, string $operation): bool
    {
        $this->operations[] = 'sync:' . $operation;

        return parent::sync($handle, $operation);
    }
}
