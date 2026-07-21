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
    }

    public function testLoadReturnsNullWhenCheckpointIsAbsent(): void
    {
        self::assertNull((new PaperReplayCheckpointStore())->load(
            $this->datasetDirectory,
            'paper.worker-01',
        ));
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
        self::assertSame([], glob(dirname($path) . '/.paper-replay-checkpoint-*') ?: []);
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
        self::assertSame([], glob(dirname($this->checkpointPath()) . '/.paper-replay-checkpoint-*') ?: []);
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
