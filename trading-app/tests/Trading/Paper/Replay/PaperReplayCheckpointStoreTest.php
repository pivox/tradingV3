<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Replay;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\Replay\PaperReplayCheckpoint;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperReplayCheckpoint::class)]
#[CoversClass(PaperReplayCheckpointStore::class)]
final class PaperReplayCheckpointStoreTest extends TestCase
{
    private string $testRoot;
    private string $datasetDirectory;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paper-replay-checkpoint-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create private replay checkpoint test directory.');
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            self::fail('Unable to resolve replay checkpoint test directory.');
        }
        $this->testRoot = $resolved;
        $this->datasetDirectory = $this->testRoot . '/dataset-okx-001';
        self::assertTrue(mkdir($this->datasetDirectory, 0700));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    public function testCheckpointIsImmutableStrictAndRoundTripsInUtc(): void
    {
        $checkpoint = new PaperReplayCheckpoint(
            datasetId: 'dataset-okx-001',
            consumerId: 'paper.worker-01',
            eventId: str_repeat('a', 64),
            eventIndex: 17,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-19T12:00:00.123456+02:00'),
            eventsFileSha256: str_repeat('b', 64),
        );

        self::assertSame(self::checkpointData(), $checkpoint->toArray());
        self::assertEquals($checkpoint, PaperReplayCheckpoint::fromArray($checkpoint->toArray()));
        self::assertTrue((new \ReflectionClass(PaperReplayCheckpoint::class))->isReadOnly());
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidCheckpointArrayProvider')]
    public function testFromArrayRejectsNonExactShapesAndValues(array $data, string $error): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        PaperReplayCheckpoint::fromArray($data);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidCheckpointArrayProvider(): iterable
    {
        $valid = self::checkpointData();

        $missing = $valid;
        unset($missing['event_id']);
        yield 'missing field' => [$missing, 'paper_replay_checkpoint_shape_invalid'];
        yield 'extra field' => [$valid + ['payload' => 'private'], 'paper_replay_checkpoint_shape_invalid'];
        yield 'schema type' => [array_replace($valid, ['schema_version' => '1']), 'paper_replay_checkpoint_shape_invalid'];
        yield 'schema value' => [array_replace($valid, ['schema_version' => 2]), 'paper_replay_checkpoint_schema_version_unsupported'];
        yield 'dataset id' => [array_replace($valid, ['dataset_id' => '../dataset']), 'paper_replay_checkpoint_dataset_id_invalid'];
        yield 'consumer id' => [array_replace($valid, ['consumer_id' => 'UPPER']), 'paper_replay_consumer_id_invalid'];
        yield 'event id' => [array_replace($valid, ['event_id' => str_repeat('A', 64)]), 'paper_replay_checkpoint_event_id_invalid'];
        yield 'negative index' => [array_replace($valid, ['event_index' => -1]), 'paper_replay_checkpoint_event_index_invalid'];
        yield 'index type' => [array_replace($valid, ['event_index' => '17']), 'paper_replay_checkpoint_shape_invalid'];
        yield 'timestamp offset' => [array_replace($valid, ['exchange_timestamp' => '2026-07-19T12:00:00.123456+02:00']), 'paper_replay_checkpoint_timestamp_invalid'];
        yield 'timestamp precision' => [array_replace($valid, ['exchange_timestamp' => '2026-07-19T10:00:00Z']), 'paper_replay_checkpoint_timestamp_invalid'];
        yield 'uppercase checksum' => [array_replace($valid, ['events_file_sha256' => str_repeat('B', 64)]), 'paper_replay_checkpoint_checksum_invalid'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidCheckpointConstructorProvider')]
    public function testConstructorValidatesEveryIdentityField(array $overrides, string $error): void
    {
        $values = array_replace([
            'datasetId' => 'dataset-okx-001',
            'consumerId' => 'paper.worker-01',
            'eventId' => str_repeat('a', 64),
            'eventIndex' => 17,
            'exchangeTimestamp' => new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
            'eventsFileSha256' => str_repeat('b', 64),
        ], $overrides);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        new PaperReplayCheckpoint(...$values);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidCheckpointConstructorProvider(): iterable
    {
        yield 'dataset' => [['datasetId' => 'ab'], 'paper_replay_checkpoint_dataset_id_invalid'];
        yield 'consumer traversal' => [['consumerId' => '../../escape'], 'paper_replay_consumer_id_invalid'];
        yield 'event identity' => [['eventId' => 'not-an-event'], 'paper_replay_checkpoint_event_id_invalid'];
        yield 'event index' => [['eventIndex' => -1], 'paper_replay_checkpoint_event_index_invalid'];
        yield 'checksum' => [['eventsFileSha256' => str_repeat('0', 63)], 'paper_replay_checkpoint_checksum_invalid'];
        yield 'timestamp not canonically readable' => [[
            'exchangeTimestamp' => new \DateTimeImmutable('+10000-07-19T10:00:00.123456Z'),
        ], 'paper_replay_checkpoint_timestamp_invalid'];
    }

    public function testLoadReturnsNullWhenCheckpointIsAbsent(): void
    {
        self::assertNull((new PaperReplayCheckpointStore())->load(
            $this->datasetDirectory,
            'paper.worker-01',
        ));
    }

    public function testRejectsCheckpointDirectoryUnlessItsModeIsExactlyPrivate(): void
    {
        self::assertTrue(mkdir($this->datasetDirectory . '/checkpoints', 0777));
        self::assertTrue(chmod($this->datasetDirectory . '/checkpoints', 0777));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_replay_checkpoint_directory_invalid');

        (new PaperReplayCheckpointStore())->load($this->datasetDirectory, 'paper.worker-01');
    }

    public function testCheckpointDirectoryCreationRequiresDurableParentSync(): void
    {
        $filesystem = new ParentSyncFailingCheckpointFilesystem();

        try {
            (new PaperReplayCheckpointStore($filesystem))->save($this->datasetDirectory, self::checkpoint());
            self::fail('Checkpoint directory creation must fail when its parent cannot be synced.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_directory_invalid', $exception->getMessage());
        }

        self::assertTrue($filesystem->parentSyncAttempted);
        self::assertDirectoryExists($this->datasetDirectory . '/checkpoints');
        self::assertSame(0700, fileperms($this->datasetDirectory . '/checkpoints') & 0777);
    }

    public function testSavesCanonicalJsonAtomicallyWithPrivateModeAndLoadsIt(): void
    {
        $checkpoint = self::checkpoint();
        $store = new PaperReplayCheckpointStore();

        $store->save($this->datasetDirectory, $checkpoint);

        $path = $this->checkpointPath();
        self::assertSame(CanonicalJson::encode($checkpoint->toArray()) . "\n", file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertEquals($checkpoint, $store->load($this->datasetDirectory, $checkpoint->consumerId));
        self::assertSame([], glob(dirname($path) . '/.paper-replay-checkpoint-[0-9a-f]*') ?: []);
        $lockPath = dirname($path) . '/.paper-replay-checkpoint-paper.worker-01.lock';
        self::assertFileExists($lockPath);
        self::assertSame(0600, fileperms($lockPath) & 0777);
        self::assertSame(1, lstat($lockPath)['nlink'] ?? null);
    }

    public function testRejectsOutOfOrderSaveWithoutReplacingTheLatestCheckpoint(): void
    {
        $latest = self::checkpointAt(19, 'c', '2026-07-19T10:00:02.123456Z');
        $older = self::checkpointAt(18, 'd', '2026-07-19T10:00:01.123456Z');
        $store = new PaperReplayCheckpointStore();
        $store->save($this->datasetDirectory, $latest);

        try {
            $store->save($this->datasetDirectory, $older);
            self::fail('A lower checkpoint index must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_regression', $exception->getMessage());
        }

        self::assertEquals($latest, $store->load($this->datasetDirectory, $latest->consumerId));
    }

    public function testRejectsConsumerLockSymlinkWithoutFollowingOrRemovingIt(): void
    {
        $checkpoints = $this->datasetDirectory . '/checkpoints';
        self::assertTrue(mkdir($checkpoints, 0700));
        $outside = $this->testRoot . '/outside-lock';
        self::assertSame(8, file_put_contents($outside, 'sentinel'));
        $lock = $checkpoints . '/.paper-replay-checkpoint-paper.worker-01.lock';
        self::assertTrue(symlink($outside, $lock));

        try {
            (new PaperReplayCheckpointStore())->save($this->datasetDirectory, self::checkpoint());
            self::fail('A symlinked consumer lock must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_symlink_rejected', $exception->getMessage());
        }

        self::assertTrue(is_link($lock));
        self::assertSame('sentinel', file_get_contents($outside));
        self::assertFileDoesNotExist($this->checkpointPath());
    }

    public function testConcurrentSavesSerializeMonotonicityForTheSameConsumer(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the checkpoint serialization test.');
        }

        $published = $this->testRoot . '/higher-published';
        $release = $this->testRoot . '/release-higher';
        $lowerResult = $this->testRoot . '/lower-result';
        $higher = self::checkpointAt(19, 'c', '2026-07-19T10:00:02.123456Z');
        $lower = self::checkpointAt(18, 'd', '2026-07-19T10:00:01.123456Z');

        $higherPid = pcntl_fork();
        self::assertNotSame(-1, $higherPid);
        if ($higherPid === 0) {
            try {
                (new PaperReplayCheckpointStore(
                    new PostRenameBlockingCheckpointFilesystem($published, $release),
                ))->save($this->datasetDirectory, $higher);
                exit(0);
            } catch (\Throwable) {
                exit(10);
            }
        }

        $this->waitForFile($published);
        $lowerPid = pcntl_fork();
        self::assertNotSame(-1, $lowerPid);
        if ($lowerPid === 0) {
            try {
                (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $lower);
                file_put_contents($lowerResult, 'saved');
                exit(0);
            } catch (\RuntimeException $exception) {
                file_put_contents($lowerResult, $exception->getMessage());
                exit(0);
            } catch (\Throwable) {
                exit(11);
            }
        }

        usleep(100_000);
        self::assertSame(0, file_put_contents($release, ''));
        pcntl_waitpid($higherPid, $higherStatus);
        pcntl_waitpid($lowerPid, $lowerStatus);

        self::assertTrue(pcntl_wifexited($higherStatus));
        self::assertSame(0, pcntl_wexitstatus($higherStatus));
        self::assertTrue(pcntl_wifexited($lowerStatus));
        self::assertSame(0, pcntl_wexitstatus($lowerStatus));
        self::assertSame('paper_replay_checkpoint_regression', file_get_contents($lowerResult));
        self::assertEquals(
            $higher,
            (new PaperReplayCheckpointStore())->load($this->datasetDirectory, $higher->consumerId),
        );
    }

    public function testFailedReplacementKeepsThePreviousCheckpointAndCleansTheTemporaryFile(): void
    {
        $original = self::checkpoint();
        (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $original);
        $replacement = new PaperReplayCheckpoint(
            datasetId: $original->datasetId,
            consumerId: $original->consumerId,
            eventId: str_repeat('c', 64),
            eventIndex: 18,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-19T10:00:01.123456Z'),
            eventsFileSha256: $original->eventsFileSha256,
        );
        $store = new PaperReplayCheckpointStore(new SyncFailingCheckpointFilesystem());

        try {
            $store->save($this->datasetDirectory, $replacement);
            self::fail('A failed durable write must not replace the checkpoint.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_write_failed', $exception->getMessage());
        }

        self::assertEquals($original, (new PaperReplayCheckpointStore())->load(
            $this->datasetDirectory,
            $original->consumerId,
        ));
        self::assertSame([], glob(dirname($this->checkpointPath()) . '/.paper-replay-checkpoint-[0-9a-f]*') ?: []);
    }

    public function testRejectsTemporaryPathSubstitutionBeforeAtomicPublication(): void
    {
        $original = self::checkpoint();
        (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $original);
        $replacement = new PaperReplayCheckpoint(
            datasetId: $original->datasetId,
            consumerId: $original->consumerId,
            eventId: str_repeat('c', 64),
            eventIndex: 18,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-19T10:00:01.123456Z'),
            eventsFileSha256: $original->eventsFileSha256,
        );

        try {
            (new PaperReplayCheckpointStore(new TemporarySwapCheckpointFilesystem()))->save(
                $this->datasetDirectory,
                $replacement,
            );
            self::fail('A substituted temporary pathname must never be published.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_write_failed', $exception->getMessage());
        }

        self::assertEquals($original, (new PaperReplayCheckpointStore())->load(
            $this->datasetDirectory,
            $original->consumerId,
        ));
    }

    public function testMoveTimeTemporarySwapRestoresThePreviousCheckpoint(): void
    {
        $original = self::checkpoint();
        (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $original);
        $replacement = self::checkpointAt(18, 'c', '2026-07-19T10:00:01.123456Z');

        try {
            (new PaperReplayCheckpointStore(new MoveSwapCheckpointFilesystem()))->save(
                $this->datasetDirectory,
                $replacement,
            );
            self::fail('A move-time tempfile substitution must not remain published.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_publication_failed', $exception->getMessage());
        }

        self::assertEquals(
            $original,
            (new PaperReplayCheckpointStore())->load($this->datasetDirectory, $original->consumerId),
        );
    }

    public function testPostRenameDirectorySyncFailureReportsUncertainPublication(): void
    {
        $original = self::checkpoint();
        (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $original);
        $replacement = self::checkpointAt(18, 'c', '2026-07-19T10:00:01.123456Z');

        try {
            (new PaperReplayCheckpointStore(new DirectorySyncFailingCheckpointFilesystem()))->save(
                $this->datasetDirectory,
                $replacement,
            );
            self::fail('A post-rename durability failure must be reported as uncertain.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_publication_uncertain', $exception->getMessage());
        }

        self::assertEquals(
            $replacement,
            (new PaperReplayCheckpointStore())->load($this->datasetDirectory, $replacement->consumerId),
        );
    }

    public function testPostRenameCheckpointDirectorySwapRemovesForgedVisibleDestination(): void
    {
        $replacement = self::checkpointAt(18, 'c', '2026-07-19T10:00:01.123456Z');
        $checkpoints = $this->datasetDirectory . '/checkpoints';
        $displaced = $this->testRoot . '/displaced-checkpoints';

        try {
            (new PaperReplayCheckpointStore(
                new PostRenameCheckpointDirectorySwapFilesystem($checkpoints, $displaced),
            ))->save($this->datasetDirectory, $replacement);
            self::fail('A forged destination in a substituted checkpoint directory must be removed.');
        } catch (\RuntimeException $exception) {
            self::assertContains($exception->getMessage(), [
                'paper_replay_checkpoint_publication_failed',
                'paper_replay_checkpoint_publication_uncertain',
            ]);
        }

        self::assertFileDoesNotExist($this->checkpointPath());
    }

    public function testDoesNotTreatAStatFailureForAnExistingCheckpointAsAbsent(): void
    {
        $checkpoint = self::checkpoint();
        (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $checkpoint);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_replay_checkpoint_invalid');

        (new PaperReplayCheckpointStore(new LoadStatFailingCheckpointFilesystem()))->load(
            $this->datasetDirectory,
            $checkpoint->consumerId,
        );
    }

    public function testLoadRejectsAFileThatGrowsBeyondTheBoundAfterInitialPathStat(): void
    {
        $checkpoint = self::checkpoint();
        (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $checkpoint);
        $filesystem = new GrowingCheckpointFilesystem($this->checkpointPath());

        try {
            (new PaperReplayCheckpointStore($filesystem))->load(
                $this->datasetDirectory,
                $checkpoint->consumerId,
            );
            self::fail('A checkpoint grown after lstat must not be accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_invalid', $exception->getMessage());
        }

        self::assertTrue($filesystem->grewAfterPathStat);
        self::assertLessThanOrEqual(16_384, $filesystem->largestReadRequest);
    }

    public function testLoadUsesOnlyBoundedFilesystemReads(): void
    {
        $checkpoint = self::checkpoint();
        (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $checkpoint);
        $filesystem = new ReadTrackingCheckpointFilesystem();

        self::assertEquals(
            $checkpoint,
            (new PaperReplayCheckpointStore($filesystem))->load(
                $this->datasetDirectory,
                $checkpoint->consumerId,
            ),
        );
        self::assertGreaterThan(0, $filesystem->largestReadRequest);
        self::assertLessThanOrEqual(16_384, $filesystem->largestReadRequest);
    }

    public function testLoadRejectsPathGrowthInjectedAfterTheReadValidation(): void
    {
        $checkpoint = self::checkpoint();
        (new PaperReplayCheckpointStore())->save($this->datasetDirectory, $checkpoint);
        $filesystem = new PostReadGrowingCheckpointFilesystem($this->checkpointPath());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_replay_checkpoint_invalid');

        (new PaperReplayCheckpointStore($filesystem))->load(
            $this->datasetDirectory,
            $checkpoint->consumerId,
        );
    }

    public function testRejectsInvalidConsumerBeforeConstructingAnyCheckpointPath(): void
    {
        try {
            (new PaperReplayCheckpointStore())->load($this->datasetDirectory, '../../private-sentinel');
            self::fail('Traversal-shaped consumer IDs must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_replay_consumer_id_invalid', $exception->getMessage());
            self::assertStringNotContainsString($this->datasetDirectory, (string) $exception);
            self::assertStringNotContainsString('private-sentinel', (string) $exception);
        }

        self::assertDirectoryDoesNotExist($this->datasetDirectory . '/checkpoints');
    }

    public function testRejectsSymlinkedCheckpointWithoutFollowingIt(): void
    {
        $checkpoints = $this->datasetDirectory . '/checkpoints';
        self::assertTrue(mkdir($checkpoints, 0700));
        $outside = $this->testRoot . '/outside.json';
        self::assertSame(7, file_put_contents($outside, 'outside'));
        self::assertTrue(symlink($outside, $this->checkpointPath()));

        try {
            (new PaperReplayCheckpointStore())->save($this->datasetDirectory, self::checkpoint());
            self::fail('Checkpoint symlinks must never be replaced or followed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_replay_checkpoint_symlink_rejected', $exception->getMessage());
        }

        self::assertSame('outside', file_get_contents($outside));
    }

    public function testMalformedAndMismatchedFilesFailWithStableRedactedErrors(): void
    {
        $checkpoints = $this->datasetDirectory . '/checkpoints';
        self::assertTrue(mkdir($checkpoints, 0700));
        self::assertSame(30, file_put_contents($this->checkpointPath(), '{"payload":"private-sentinel"}'));
        chmod($this->checkpointPath(), 0600);

        $this->assertLoadFailureIsRedacted('paper_replay_checkpoint_invalid', 'private-sentinel');

        $mismatched = array_replace(self::checkpointData(), ['consumer_id' => 'paper.worker-02']);
        file_put_contents($this->checkpointPath(), CanonicalJson::encode($mismatched) . "\n");

        $this->assertLoadFailureIsRedacted('paper_replay_checkpoint_mismatch', null);
    }

    /** @return array<string, mixed> */
    private static function checkpointData(): array
    {
        return [
            'schema_version' => 1,
            'dataset_id' => 'dataset-okx-001',
            'consumer_id' => 'paper.worker-01',
            'event_id' => str_repeat('a', 64),
            'event_index' => 17,
            'exchange_timestamp' => '2026-07-19T10:00:00.123456Z',
            'events_file_sha256' => str_repeat('b', 64),
        ];
    }

    private static function checkpoint(): PaperReplayCheckpoint
    {
        return PaperReplayCheckpoint::fromArray(self::checkpointData());
    }

    private static function checkpointAt(int $index, string $eventIdCharacter, string $timestamp): PaperReplayCheckpoint
    {
        return new PaperReplayCheckpoint(
            datasetId: 'dataset-okx-001',
            consumerId: 'paper.worker-01',
            eventId: str_repeat($eventIdCharacter, 64),
            eventIndex: $index,
            exchangeTimestamp: new \DateTimeImmutable($timestamp),
            eventsFileSha256: str_repeat('b', 64),
        );
    }

    private function checkpointPath(): string
    {
        return $this->datasetDirectory . '/checkpoints/paper.worker-01.json';
    }

    private function assertLoadFailureIsRedacted(string $error, ?string $secret): void
    {
        try {
            (new PaperReplayCheckpointStore())->load($this->datasetDirectory, 'paper.worker-01');
            self::fail('Invalid checkpoint contents must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame($error, $exception->getMessage());
            if ($secret !== null) {
                self::assertStringNotContainsString($secret, (string) $exception);
            }
            self::assertStringNotContainsString($this->datasetDirectory, (string) $exception);
        }
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 5.0;
        while (!file_exists($path)) {
            if (microtime(true) >= $deadline) {
                self::fail('Timed out waiting for concurrent checkpoint test marker.');
            }
            usleep(10_000);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

final class SyncFailingCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    /** @param resource $handle */
    public function sync($handle, string $operation): bool
    {
        return $operation === 'paper_replay_checkpoint_sync' ? false : parent::sync($handle, $operation);
    }
}

final class TemporarySwapCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    private ?string $temporaryPath = null;
    private bool $swapped = false;

    public function createPrivateFile(#[\SensitiveParameter] string $path, string $operation)
    {
        $this->temporaryPath = $path;

        return parent::createPrivateFile($path, $operation);
    }

    /** @param resource $handle
     *  @return array<string, mixed>|false
     */
    public function stat($handle, string $operation): array|false
    {
        if ($operation === 'paper_replay_checkpoint_validation'
            && !$this->swapped
            && $this->temporaryPath !== null
        ) {
            $this->swapped = true;
            if (!unlink($this->temporaryPath)
                || file_put_contents($this->temporaryPath, 'forged') !== 6
                || !chmod($this->temporaryPath, 0600)
            ) {
                throw new \RuntimeException('Unable to inject checkpoint tempfile substitution.');
            }
        }

        return parent::stat($handle, $operation);
    }
}

final class LoadStatFailingCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        if ($operation === 'paper_replay_checkpoint_load' && str_ends_with($path, '.json')) {
            return false;
        }

        return parent::pathStat($path, $operation);
    }
}

final class ParentSyncFailingCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    public bool $parentSyncAttempted = false;

    /** @param resource $handle */
    public function sync($handle, string $operation): bool
    {
        if ($operation === 'paper_replay_checkpoint_directory_parent_sync') {
            $this->parentSyncAttempted = true;

            return false;
        }

        return parent::sync($handle, $operation);
    }
}

final class GrowingCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    public bool $grewAfterPathStat = false;
    public int $largestReadRequest = 0;

    public function __construct(private readonly string $checkpointPath)
    {
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        $statistics = parent::pathStat($path, $operation);
        if ($operation === 'paper_replay_checkpoint_load'
            && $path === $this->checkpointPath
            && !$this->grewAfterPathStat
        ) {
            $this->grewAfterPathStat = true;
            if (file_put_contents($path, str_repeat('x', 16_385), FILE_APPEND) !== 16_385) {
                throw new \RuntimeException('Unable to grow checkpoint after lstat.');
            }
        }

        return $statistics;
    }

    /** @param resource $handle */
    public function read($handle, int $length, string $operation): string|false
    {
        $this->largestReadRequest = max($this->largestReadRequest, $length);

        return parent::read($handle, $length, $operation);
    }
}

final class ReadTrackingCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    public int $largestReadRequest = 0;

    /** @param resource $handle */
    public function read($handle, int $length, string $operation): string|false
    {
        $this->largestReadRequest = max($this->largestReadRequest, $length);

        return parent::read($handle, $length, $operation);
    }
}

final class PostReadGrowingCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    private int $loadPathStats = 0;

    public function __construct(private readonly string $checkpointPath)
    {
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        $statistics = parent::pathStat($path, $operation);
        if ($operation === 'paper_replay_checkpoint_load' && $path === $this->checkpointPath) {
            ++$this->loadPathStats;
            if ($this->loadPathStats === 2 && file_put_contents($path, 'x', FILE_APPEND) !== 1) {
                throw new \RuntimeException('Unable to grow checkpoint after read validation.');
            }
        }

        return $statistics;
    }
}

final class PostRenameBlockingCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    public function __construct(
        private readonly string $publishedMarker,
        private readonly string $releaseMarker,
    ) {
    }

    public function move(
        #[\SensitiveParameter] string $source,
        #[\SensitiveParameter] string $destination,
        string $operation,
    ): bool {
        $moved = parent::move($source, $destination, $operation);
        if ($operation === 'paper_replay_checkpoint_publish' && $moved) {
            if (file_put_contents($this->publishedMarker, '') !== 0) {
                throw new \RuntimeException('Unable to publish concurrency marker.');
            }
            $deadline = microtime(true) + 5.0;
            while (!file_exists($this->releaseMarker)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Timed out waiting to release checkpoint publication.');
                }
                usleep(10_000);
            }
        }

        return $moved;
    }
}

final class MoveSwapCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    private bool $swapped = false;

    public function move(
        #[\SensitiveParameter] string $source,
        #[\SensitiveParameter] string $destination,
        string $operation,
    ): bool {
        if ($operation === 'paper_replay_checkpoint_publish' && !$this->swapped) {
            $this->swapped = true;
            if (!unlink($source)
                || file_put_contents($source, 'forged') !== 6
                || !chmod($source, 0600)
            ) {
                throw new \RuntimeException('Unable to inject move-time checkpoint substitution.');
            }
        }

        return parent::move($source, $destination, $operation);
    }
}

final class DirectorySyncFailingCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    /** @param resource $handle */
    public function sync($handle, string $operation): bool
    {
        return $operation === 'paper_replay_checkpoint_directory_sync'
            ? false
            : parent::sync($handle, $operation);
    }
}

final class PostRenameCheckpointDirectorySwapFilesystem extends PaperDatasetRecorderFilesystem
{
    private bool $swapped = false;

    public function __construct(
        private readonly string $checkpointDirectory,
        private readonly string $displacedDirectory,
    ) {
    }

    public function move(
        #[\SensitiveParameter] string $source,
        #[\SensitiveParameter] string $destination,
        string $operation,
    ): bool {
        $moved = parent::move($source, $destination, $operation);
        if ($operation === 'paper_replay_checkpoint_publish' && $moved && !$this->swapped) {
            $this->swapped = true;
            if (!rename($this->checkpointDirectory, $this->displacedDirectory)
                || !mkdir($this->checkpointDirectory, 0700)
                || file_put_contents($destination, 'forged') !== 6
                || !chmod($destination, 0600)
            ) {
                throw new \RuntimeException('Unable to inject checkpoint directory substitution.');
            }
        }

        return $moved;
    }
}
