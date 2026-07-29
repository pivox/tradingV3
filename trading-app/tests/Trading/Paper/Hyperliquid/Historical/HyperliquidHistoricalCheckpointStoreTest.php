<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalCheckpointStore;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalIntegrityException;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalRequest;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidHistoricalCheckpointStore::class)]
final class HyperliquidHistoricalCheckpointStoreTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hyperliquid-checkpoint-test-');
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

    public function testCreateIsPrivateCanonicalAndReloadsIdentically(): void
    {
        $request = $this->request('hyperliquid-checkpoint-create');
        $datasetDirectory = $this->datasetDirectory('create');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);

        $state = $store->loadOrCreate();
        $expected = [
            'dataset_id' => 'hyperliquid-checkpoint-create',
            'emit_index' => 0,
            'event_count' => 0,
            'network' => 'mainnet',
            'ordinal_state' => ['schema_version' => 2, 'scopes' => []],
            'page_count' => 0,
            'pending_event' => null,
            'phase' => 'fetching',
            'request_sha256' => $request->requestSha256(),
            'schema_version' => 1,
            'staged_row_count' => 0,
            'streams' => [],
        ];

        self::assertSame($expected, $state);
        $checkpointDirectory = $this->checkpointDirectory($datasetDirectory);
        $checkpointPath = $checkpointDirectory . '/checkpoint.json';
        foreach ([
            $datasetDirectory . '/checkpoints',
            $datasetDirectory . '/checkpoints/hyperliquid-acquisition',
            $checkpointDirectory,
            $checkpointDirectory . '/pages',
        ] as $directory) {
            self::assertSame(0700, fileperms($directory) & 0777);
        }
        foreach ([$checkpointPath, $checkpointDirectory . '/.writer.lock'] as $file) {
            self::assertSame(0600, fileperms($file) & 0777);
            self::assertSame(1, lstat($file)['nlink'] ?? null);
        }
        self::assertSame(CanonicalJson::encode($expected) . "\n", file_get_contents($checkpointPath));

        unset($store);
        $reloadedStore = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        self::assertSame($state, $reloadedStore->loadOrCreate());
    }

    public function testLoadRejectsCorruptCheckpoint(): void
    {
        $request = $this->request('hyperliquid-checkpoint-corrupt');
        $datasetDirectory = $this->datasetDirectory('corrupt');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $store->loadOrCreate();
        $checkpointPath = $this->checkpointDirectory($datasetDirectory) . '/checkpoint.json';
        self::assertNotFalse(file_put_contents($checkpointPath, "{\"schema_version\":1"));
        unset($store);

        $this->expectException(HyperliquidHistoricalIntegrityException::class);
        $this->expectExceptionMessage('hyperliquid_acquisition_checkpoint_invalid');

        (new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request))->loadOrCreate();
    }

    public function testPageIsCanonicalChecksummedChainedSavedAndReloaded(): void
    {
        $request = $this->request('hyperliquid-checkpoint-page');
        $datasetDirectory = $this->datasetDirectory('page');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $records = [
            ['z' => 'é', 'a' => ['y' => 2, 'x' => 1]],
            ['timestamp' => 2, 'coin' => 'BTC'],
        ];

        $page = $store->writePage('BTC-candle_1m-000001.ndjson', $records);
        $contents = CanonicalJson::encode($records[0]) . "\n"
            . CanonicalJson::encode($records[1]) . "\n";
        self::assertSame([
            'file' => 'BTC-candle_1m-000001.ndjson',
            'sha256' => hash('sha256', $contents),
            'row_count' => 2,
        ], $page);
        $page['chain_sha256'] = hash('sha256', str_repeat('0', 64) . $page['sha256']);
        $state['streams']['BTC/candle_1m'] = [
            'kind' => 'candle',
            'coin' => 'BTC',
            'interval' => '1m',
            'next_cursor' => 1_721_556_060_000,
            'complete' => false,
            'pages' => [$page],
        ];
        $state['page_count'] = 1;
        $state['staged_row_count'] = 2;
        $state['event_count'] = 2;

        $store->save($state);
        $store->verifyPages($state);
        self::assertSame(
            json_decode(CanonicalJson::encode($records), true, 512, \JSON_THROW_ON_ERROR),
            $store->readPage($page['file']),
        );
        self::assertSame($contents, file_get_contents(
            $this->checkpointDirectory($datasetDirectory) . '/pages/' . $page['file'],
        ));
        $pagePath = $this->checkpointDirectory($datasetDirectory) . '/pages/' . $page['file'];
        self::assertSame(0600, fileperms($pagePath) & 0777);
        self::assertSame(1, lstat($pagePath)['nlink'] ?? null);
        unset($store);

        $reloaded = (new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request))->loadOrCreate();
        self::assertSame(
            json_decode(CanonicalJson::encode($state), true, 512, \JSON_THROW_ON_ERROR),
            $reloaded,
        );
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}> */
    public static function immutableRequestMismatchProvider(): iterable
    {
        yield 'network' => [static function (array $state): array {
            $state['network'] = 'testnet';

            return $state;
        }];
        yield 'dataset' => [static function (array $state): array {
            $state['dataset_id'] = 'hyperliquid-checkpoint-other';

            return $state;
        }];
        yield 'request hash' => [static function (array $state): array {
            $state['request_sha256'] = str_repeat('0', 64);

            return $state;
        }];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('immutableRequestMismatchProvider')]
    public function testSaveRejectsImmutableRequestMismatchWithoutReplacingCheckpoint(\Closure $mutate): void
    {
        $request = $this->request('hyperliquid-checkpoint-mismatch');
        $datasetDirectory = $this->datasetDirectory('mismatch-' . spl_object_id($mutate));
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $checkpointPath = $this->checkpointDirectory($datasetDirectory) . '/checkpoint.json';
        $before = file_get_contents($checkpointPath);

        try {
            $store->save($mutate($state));
            self::fail('Immutable request metadata must not be replaceable.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_checkpoint_request_mismatch', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($checkpointPath));
    }

    public function testReloadWithDifferentRequestIsRejected(): void
    {
        $request = $this->request('hyperliquid-checkpoint-request');
        $datasetDirectory = $this->datasetDirectory('different-request');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $store->loadOrCreate();
        unset($store);
        $different = new HyperliquidHistoricalRequest(
            datasetId: $request->datasetId,
            network: $request->network,
            symbols: ['BTCUSDT', 'ETHUSDT'],
            from: $request->from,
            to: $request->to->modify('+1 minute'),
            maximumEvents: 4,
            maximumPages: 4,
            maximumResponseBytes: 1_024,
        );

        $this->expectException(HyperliquidHistoricalIntegrityException::class);
        $this->expectExceptionMessage('hyperliquid_acquisition_checkpoint_request_mismatch');

        (new HyperliquidHistoricalCheckpointStore($datasetDirectory, $different))->loadOrCreate();
    }

    public function testVerifyRejectsPageChecksumMismatch(): void
    {
        [$store, $state, $pagePath] = $this->storedPageFixture('hash-mismatch');
        self::assertNotFalse(file_put_contents($pagePath, "{\"a\":2}\n"));

        $this->expectException(HyperliquidHistoricalIntegrityException::class);
        $this->expectExceptionMessage('hyperliquid_acquisition_page_hash_mismatch');

        $store->verifyPages($state);
    }

    public function testReadRejectsTruncatedPageEvenWhenChecksumMetadataMatches(): void
    {
        [$store, $state, $pagePath] = $this->storedPageFixture('truncated-page');
        $truncated = '{"a":1}';
        self::assertSame(strlen($truncated), file_put_contents($pagePath, $truncated));
        $sha256 = hash('sha256', $truncated);
        $state['streams']['BTC/candle_1m']['pages'][0]['sha256'] = $sha256;
        $state['streams']['BTC/candle_1m']['pages'][0]['chain_sha256']
            = hash('sha256', str_repeat('0', 64) . $sha256);
        $store->save($state);

        $this->expectException(HyperliquidHistoricalIntegrityException::class);
        $this->expectExceptionMessage('hyperliquid_acquisition_page_invalid');

        $store->readPage('BTC-candle_1m-000001.ndjson');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPageFilenameProvider(): iterable
    {
        yield 'wrong symbol separator' => ['BTCUSDT-candle_1m-000001.ndjson'];
        yield 'lowercase coin' => ['btc-candle_1m-000001.ndjson'];
        yield 'wrong interval case' => ['BTC-candle_1H-000001.ndjson'];
        yield 'unsupported interval' => ['BTC-candle_4h-000001.ndjson'];
        yield 'missing six digits' => ['BTC-candle_1m-1.ndjson'];
        yield 'path traversal' => ['../BTC-candle_1m-000001.ndjson'];
        yield 'extra suffix' => ['BTC-candle_1m-000001.ndjson.bak'];
    }

    #[DataProvider('invalidPageFilenameProvider')]
    public function testWritePageRejectsInvalidFilename(string $filename): void
    {
        $store = new HyperliquidHistoricalCheckpointStore(
            $this->datasetDirectory('filename-' . md5($filename)),
            $this->request('hyperliquid-checkpoint-filename-' . substr(md5($filename), 0, 8)),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_acquisition_page_name_invalid');

        $store->writePage($filename, []);
    }

    public function testSymlinkAndHardlinkFilesAreRejectedWithoutWritingThroughThem(): void
    {
        $request = $this->request('hyperliquid-checkpoint-links');
        $datasetDirectory = $this->datasetDirectory('links');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $checkpointDirectory = $this->checkpointDirectory($datasetDirectory);
        $outside = $this->testRoot . '/outside-link-target';
        self::assertSame(7, file_put_contents($outside, 'outside'));
        $pagePath = $checkpointDirectory . '/pages/BTC-candle_1m-000001.ndjson';
        self::assertTrue(symlink($outside, $pagePath));
        try {
            $store->writePage('BTC-candle_1m-000001.ndjson', [['a' => 1]]);
            self::fail('Page symlink must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_file_invalid', $exception->getMessage());
        }
        self::assertSame('outside', file_get_contents($outside));
        self::assertTrue(unlink($pagePath));
        $store->writePage('BTC-candle_1m-000001.ndjson', [['a' => 1]]);
        $pageHardlink = $this->testRoot . '/page-hardlink';
        self::assertTrue(link($pagePath, $pageHardlink));
        try {
            $store->readPage('BTC-candle_1m-000001.ndjson');
            self::fail('Hardlinked page must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_page_unreadable', $exception->getMessage());
        }

        $checkpointPath = $checkpointDirectory . '/checkpoint.json';
        $hardlink = $this->testRoot . '/checkpoint-hardlink';
        self::assertTrue(link($checkpointPath, $hardlink));
        try {
            $store->save($state);
            self::fail('Hardlinked checkpoint must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_file_invalid', $exception->getMessage());
        }
        self::assertSame(2, lstat($checkpointPath)['nlink'] ?? null);
    }

    public function testManagedDirectorySymlinkIsRejectedWithoutWritingThroughIt(): void
    {
        $request = $this->request('hyperliquid-checkpoint-parent-symlink');
        $datasetDirectory = $this->datasetDirectory('parent-symlink');
        $outside = $this->testRoot . '/outside-directory';
        self::assertTrue(mkdir($outside, 0700));
        self::assertTrue(symlink($outside, $datasetDirectory . '/checkpoints'));

        try {
            new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
            self::fail('Managed directory symlink must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_file_invalid', $exception->getMessage());
        }
        self::assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
    }

    public function testOversizedCheckpointAndPageAreRejected(): void
    {
        $request = $this->request('hyperliquid-checkpoint-oversize');
        $datasetDirectory = $this->datasetDirectory('oversize');
        $checkpointPath = $this->checkpointDirectory($datasetDirectory) . '/checkpoint.json';
        (function () use ($datasetDirectory, $request, $checkpointPath): void {
            $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
            $store->loadOrCreate();
            try {
                $store->writePage(
                    'BTC-candle_1m-000001.ndjson',
                    [['body' => str_repeat('x', 1_024)]],
                );
                self::fail('Oversized page must be rejected.');
            } catch (HyperliquidHistoricalIntegrityException $exception) {
                self::assertSame(
                    'hyperliquid_acquisition_page_oversized',
                    $exception->getMessage(),
                );
            }
            self::assertFileDoesNotExist(
                $this->checkpointDirectory($datasetDirectory)
                    . '/pages/BTC-candle_1m-000001.ndjson',
            );
            self::assertNotFalse(file_put_contents(
                $checkpointPath,
                str_repeat('x', CanonicalJson::MAX_BYTES + 2_049),
            ));
            $store->__destruct();
        })();
        gc_collect_cycles();
        try {
            (new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request))->loadOrCreate();
            self::fail('Oversized checkpoint must be rejected before decoding.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_checkpoint_invalid', $exception->getMessage());
        }
    }

    public function testUnsafeDirectoryAndFileModesAreRejected(): void
    {
        $request = $this->request('hyperliquid-checkpoint-modes');
        $unsafeDirectoryDataset = $this->datasetDirectory('unsafe-directory-mode');
        self::assertTrue(mkdir($unsafeDirectoryDataset . '/checkpoints', 0755));
        try {
            new HyperliquidHistoricalCheckpointStore($unsafeDirectoryDataset, $request);
            self::fail('Unsafe managed directory mode must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_directory_invalid', $exception->getMessage());
        }

        $unsafeFileDataset = $this->datasetDirectory('unsafe-file-mode');
        $store = new HyperliquidHistoricalCheckpointStore($unsafeFileDataset, $request);
        $store->loadOrCreate();
        $checkpointPath = $this->checkpointDirectory($unsafeFileDataset) . '/checkpoint.json';
        self::assertTrue(chmod($checkpointPath, 0644));
        unset($store);
        try {
            (new HyperliquidHistoricalCheckpointStore($unsafeFileDataset, $request))->loadOrCreate();
            self::fail('Unsafe checkpoint mode must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_checkpoint_unreadable', $exception->getMessage());
        }
    }

    public function testWriterLockIsPrivateExclusiveAndPreservesCheckpointOnContention(): void
    {
        $request = $this->request('hyperliquid-checkpoint-lock');
        $datasetDirectory = $this->datasetDirectory('lock');
        $first = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $first->loadOrCreate();
        $checkpointDirectory = $this->checkpointDirectory($datasetDirectory);
        $checkpointPath = $checkpointDirectory . '/checkpoint.json';
        $before = file_get_contents($checkpointPath);
        self::assertSame(0600, fileperms($checkpointDirectory . '/.writer.lock') & 0777);

        try {
            new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
            self::fail('A concurrent writer must fail immediately.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_lock_unavailable', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($checkpointPath));
    }

    public function testWriterLockReplacementPreservesLastDurableCheckpoint(): void
    {
        $request = $this->request('hyperliquid-checkpoint-lock-replacement');
        $datasetDirectory = $this->datasetDirectory('lock-replacement');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $state['streams']['BTC/candle_1m'] = $this->emptyStream();
        $checkpointDirectory = $this->checkpointDirectory($datasetDirectory);
        $checkpointPath = $checkpointDirectory . '/checkpoint.json';
        $before = file_get_contents($checkpointPath);
        $lockPath = $checkpointDirectory . '/.writer.lock';
        self::assertTrue(rename($lockPath, $lockPath . '.displaced'));
        self::assertSame(0, file_put_contents($lockPath, ''));
        self::assertTrue(chmod($lockPath, 0600));

        try {
            $store->save($state);
            self::fail('A replaced writer lock must stop publication.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_lock_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($checkpointPath));
    }

    public function testWriterLockReplacementBetweenMetadataAndOpenCannotOwnSecondLock(): void
    {
        $request = $this->request('hyperliquid-checkpoint-lock-open-race');
        $datasetDirectory = $this->datasetDirectory('lock-open-race');
        $writerA = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $writerA->loadOrCreate();
        $checkpointDirectory = $this->checkpointDirectory($datasetDirectory);
        $checkpointPath = $checkpointDirectory . '/checkpoint.json';
        $before = file_get_contents($checkpointPath);
        $lockPath = $checkpointDirectory . '/.writer.lock';
        $raced = false;
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $filesystem->afterPathStatHook = static function (
            string $path,
            string $operation,
        ) use ($lockPath, &$raced): void {
            if ($raced
                || $path !== $lockPath
                || $operation !== 'hyperliquid_acquisition_lock_validation'
            ) {
                return;
            }
            $raced = true;
            self::assertTrue(rename($path, $path . '.writer-a'));
            self::assertSame(0, file_put_contents($path, ''));
            self::assertTrue(chmod($path, 0600));
        };

        try {
            $writerB = new HyperliquidHistoricalCheckpointStore(
                $datasetDirectory,
                $request,
                $filesystem,
            );
            $writerB->loadOrCreate();
            $writerB->__destruct();
            self::fail('Writer B must not lock a replacement inode.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_lock_invalid', $exception->getMessage());
        }

        $replacement = fopen($lockPath, 'r+b');
        self::assertIsResource($replacement);
        self::assertTrue(flock($replacement, \LOCK_EX | \LOCK_NB));
        self::assertTrue(flock($replacement, \LOCK_UN));
        fclose($replacement);
        self::assertSame($before, file_get_contents($checkpointPath));
        $writerA->__destruct();
    }

    public function testExclusiveCreateCollisionFallbackPinsMetadataBeforeOpen(): void
    {
        $request = $this->request('hyperliquid-checkpoint-lock-collision-race');
        $datasetDirectory = $this->datasetDirectory('lock-collision-race');
        $checkpointDirectory = $this->checkpointDirectory($datasetDirectory);
        $lockPath = $checkpointDirectory . '/.writer.lock';
        $collisionOwner = null;
        $raced = false;
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $filesystem->createPrivateFileHook = static function (
            string $path,
            string $operation,
        ) use ($lockPath, &$collisionOwner): void {
            if ($path !== $lockPath
                || $operation !== 'hyperliquid_acquisition_lock_create'
                || \is_resource($collisionOwner)
            ) {
                return;
            }
            $collisionOwner = fopen($path, 'x+b');
            self::assertIsResource($collisionOwner);
            self::assertTrue(chmod($path, 0600));
            self::assertTrue(flock($collisionOwner, \LOCK_EX | \LOCK_NB));
        };
        $filesystem->afterPathStatHook = static function (
            string $path,
            string $operation,
            array|false $statistics,
        ) use ($lockPath, &$collisionOwner, &$raced): void {
            if ($raced
                || !\is_resource($collisionOwner)
                || $statistics === false
                || $path !== $lockPath
                || $operation !== 'hyperliquid_acquisition_lock_validation'
            ) {
                return;
            }
            $raced = true;
            self::assertTrue(rename($path, $path . '.collision-owner'));
            self::assertSame(0, file_put_contents($path, ''));
            self::assertTrue(chmod($path, 0600));
        };

        try {
            $writerB = new HyperliquidHistoricalCheckpointStore(
                $datasetDirectory,
                $request,
                $filesystem,
            );
            $writerB->loadOrCreate();
            $writerB->__destruct();
            self::fail('Collision fallback must not lock a post-metadata replacement.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_lock_invalid', $exception->getMessage());
        }

        self::assertFileDoesNotExist($checkpointDirectory . '/checkpoint.json');
        $replacement = fopen($lockPath, 'r+b');
        self::assertIsResource($replacement);
        self::assertTrue(flock($replacement, \LOCK_EX | \LOCK_NB));
        self::assertTrue(flock($replacement, \LOCK_UN));
        fclose($replacement);
        self::assertIsResource($collisionOwner);
        self::assertTrue(flock($collisionOwner, \LOCK_UN));
        fclose($collisionOwner);
    }

    /** @return iterable<string, array{string}> */
    public static function preRenameFailureOperationProvider(): iterable
    {
        yield 'write' => ['hyperliquid_acquisition_write'];
        yield 'flush' => ['hyperliquid_acquisition_flush'];
        yield 'file sync' => ['hyperliquid_acquisition_sync'];
    }

    #[DataProvider('preRenameFailureOperationProvider')]
    public function testPreRenameFailurePreservesLastDurableCheckpoint(string $operation): void
    {
        $suffix = str_replace('_', '-', $operation);
        $request = $this->request('hyperliquid-checkpoint-pre-' . substr(md5($suffix), 0, 8));
        $datasetDirectory = $this->datasetDirectory('pre-' . $suffix);
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request, $filesystem);
        $state = $store->loadOrCreate();
        $checkpointPath = $this->checkpointDirectory($datasetDirectory) . '/checkpoint.json';
        $before = file_get_contents($checkpointPath);
        $state['streams']['BTC/candle_1m'] = $this->emptyStream();
        $filesystem->failOperation = $operation;

        try {
            $store->save($state);
            self::fail('A pre-rename failure must fail publication.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_acquisition_write_failed', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($checkpointPath));
        self::assertSame([], glob($this->checkpointDirectory($datasetDirectory) . '/.hyperliquid-acquisition-*') ?: []);
    }

    public function testPostRenameDirectorySyncFailureFailsClosedAfterCompleteRename(): void
    {
        $request = $this->request('hyperliquid-checkpoint-post-sync');
        $datasetDirectory = $this->datasetDirectory('post-sync');
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $state = (function () use ($datasetDirectory, $request, $filesystem): array {
            $store = new HyperliquidHistoricalCheckpointStore(
                $datasetDirectory,
                $request,
                $filesystem,
            );
            $state = $store->loadOrCreate();
            $state['streams']['BTC/candle_1m'] = $this->emptyStream();
            $filesystem->failOperation = 'hyperliquid_acquisition_directory_sync';
            try {
                $store->save($state);
                self::fail('A parent directory sync failure must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('hyperliquid_acquisition_write_failed', $exception->getMessage());
            }
            $store->__destruct();

            return $state;
        })();
        gc_collect_cycles();
        $move = array_search('move:checkpoint.json', $filesystem->operations, true);
        $sync = array_search('sync:hyperliquid_acquisition_directory_sync', $filesystem->operations, true);
        self::assertIsInt($move);
        self::assertIsInt($sync);
        self::assertGreaterThan($move, $sync);
        $filesystem->failOperation = null;
        self::assertSame(
            json_decode(CanonicalJson::encode($state), true, 512, \JSON_THROW_ON_ERROR),
            (new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request))->loadOrCreate(),
        );
    }

    public function testStaleTemporaryFileDoesNotAffectRandomExclusivePublication(): void
    {
        $request = $this->request('hyperliquid-checkpoint-stale-temp');
        $datasetDirectory = $this->datasetDirectory('stale-temp');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $state['streams']['BTC/candle_1m'] = $this->emptyStream();
        $stale = $this->checkpointDirectory($datasetDirectory) . '/.hyperliquid-acquisition-stale';
        self::assertSame(5, file_put_contents($stale, 'stale'));
        self::assertTrue(chmod($stale, 0600));

        $store->save($state);

        self::assertSame('stale', file_get_contents($stale));
        self::assertSame(
            json_decode(CanonicalJson::encode($state), true, 512, \JSON_THROW_ON_ERROR),
            $store->loadOrCreate(),
        );
    }

    public function testPendingEventAndOrdinalStateReloadByteIdentically(): void
    {
        [$store, $state] = $this->storedPageFixture('pending-event');
        [$event, $ordinalState, $naturalIdentity] = $this->canonicalPendingAssignment();
        $state = $this->completedGrid($state);
        $state['phase'] = 'emitting';
        $state['emit_index'] = 0;
        $state['ordinal_state'] = $ordinalState;
        $state['pending_event'] = [
            'natural_identity' => $naturalIdentity,
            'event' => $event->toArray(),
        ];
        $store->save($state);
        unset($store);

        $datasetDirectory = $this->testRoot . '/fixture-pending-event';
        $reloadedStore = new HyperliquidHistoricalCheckpointStore(
            $datasetDirectory,
            $this->request('hyperliquid-checkpoint-fixture-pending-event'),
        );
        self::assertSame(
            json_decode(CanonicalJson::encode($state), true, 512, \JSON_THROW_ON_ERROR),
            $reloadedStore->loadOrCreate(),
        );
    }

    public function testEmittingAndCompleteRequireTheExactCompletedRequestedGrid(): void
    {
        [$store, $partial] = $this->storedPageFixture('phase-grid');
        $partial['streams']['BTC/candle_1m']['complete'] = true;
        $partial['phase'] = 'emitting';
        $partial['emit_index'] = $partial['event_count'];
        $before = file_get_contents($this->checkpointPath('phase-grid'));

        foreach (['emitting', 'complete'] as $phase) {
            $candidate = $partial;
            $candidate['phase'] = $phase;
            try {
                $store->save($candidate);
                self::fail($phase . ' must reject a partial acquisition grid.');
            } catch (HyperliquidHistoricalIntegrityException $exception) {
                self::assertSame(
                    'hyperliquid_acquisition_checkpoint_invalid',
                    $exception->getMessage(),
                );
            }
            self::assertSame($before, file_get_contents($this->checkpointPath('phase-grid')));
        }

        $full = $this->completedGrid($partial);
        $full['phase'] = 'emitting';
        $store->save($full);
        self::assertSame($full['event_count'], $full['emit_index']);
        self::assertSame(
            json_decode(CanonicalJson::encode($full), true, 512, \JSON_THROW_ON_ERROR),
            $store->loadOrCreate(),
        );

        $full['phase'] = 'complete';
        $store->save($full);
        self::assertSame(
            json_decode(CanonicalJson::encode($full), true, 512, \JSON_THROW_ON_ERROR),
            $store->loadOrCreate(),
        );
    }

    public function testPendingEventMustBeTheLatestCommittedOrdinalAssignment(): void
    {
        [$store, $state] = $this->storedPageFixture('pending-binding');
        [$event, $ordinalState, $naturalIdentity] = $this->canonicalPendingAssignment();
        $state = $this->completedGrid($state);
        $state['phase'] = 'emitting';
        $state['ordinal_state'] = $ordinalState;
        $state['pending_event'] = [
            'natural_identity' => $naturalIdentity,
            'event' => $event->toArray(),
        ];
        $store->save($state);
        $before = file_get_contents($this->checkpointPath('pending-binding'));

        $cases = [];
        $emptyOrdinal = $state;
        $emptyOrdinal['ordinal_state'] = ['schema_version' => 2, 'scopes' => []];
        $cases['empty ordinal'] = $emptyOrdinal;
        $wrongIdentity = $state;
        $wrongIdentity['pending_event']['natural_identity'] = 'BTC|1m|60000|119999';
        $cases['arbitrary natural identity'] = $wrongIdentity;
        $wrongEvent = $state;
        $wrongEvent['pending_event']['event']['received_timestamp']
            = '1970-01-01T00:01:00.000000Z';
        $cases['noncanonical event forgery'] = $wrongEvent;
        $forgedPayload = $event->payload;
        $forgedPayload['close'] = '100.5';
        $forgedEvent = PaperMarketEvent::create(
            $event->sourceNetwork,
            $event->sourceVenue,
            $event->symbol,
            $event->channel,
            $event->exchangeTimestamp,
            $event->receivedTimestamp,
            $event->sequence,
            $forgedPayload,
        );
        $rehashedForgery = $state;
        $rehashedForgery['pending_event']['event'] = $forgedEvent->toArray();
        $cases['rehashed payload forgery'] = $rehashedForgery;

        $otherOrdinals = new HyperliquidPaperSourceOrdinal();
        $otherEvent = (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            $otherOrdinals,
        ))->candle($this->candle(start: 60_000));
        $snapshotMismatch = $state;
        $snapshotMismatch['pending_event'] = [
            'natural_identity' => 'BTC|1m|60000|119999',
            'event' => $otherEvent->toArray(),
        ];
        $cases['valid event absent from snapshot'] = $snapshotMismatch;

        foreach ($cases as $label => $candidate) {
            try {
                $store->save($candidate);
                self::fail('Pending assignment must reject ' . $label . '.');
            } catch (HyperliquidHistoricalIntegrityException $exception) {
                self::assertSame(
                    'hyperliquid_acquisition_checkpoint_invalid',
                    $exception->getMessage(),
                    $label,
                );
            }
            self::assertSame(
                $before,
                file_get_contents($this->checkpointPath('pending-binding')),
                $label,
            );
        }
    }

    public function testOrdinalScopesAreRestrictedToTheImmutableRequestContext(): void
    {
        $request = $this->request('hyperliquid-checkpoint-ordinal-context');
        $datasetDirectory = $this->datasetDirectory('ordinal-context');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $state = $this->completedGrid($state);
        $state['phase'] = 'emitting';

        $foreignOrdinals = new HyperliquidPaperSourceOrdinal();
        (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::TESTNET,
            $foreignOrdinals,
        ))->candle($this->candle());
        $state['ordinal_state'] = $foreignOrdinals->snapshot();

        $this->expectException(HyperliquidHistoricalIntegrityException::class);
        $this->expectExceptionMessage('hyperliquid_acquisition_checkpoint_invalid');

        $store->save($state);
    }

    public function testOrdinalScopesRejectForeignSymbolVenueAndChannel(): void
    {
        $request = new HyperliquidHistoricalRequest(
            datasetId: 'hyperliquid-checkpoint-ordinal-scope',
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
            maximumEvents: 4,
            maximumPages: 4,
            maximumResponseBytes: 1_024,
        );
        $datasetDirectory = $this->datasetDirectory('ordinal-scope');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $base = $store->loadOrCreate();

        $ethOrdinals = new HyperliquidPaperSourceOrdinal();
        (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            $ethOrdinals,
        ))->candle($this->candle(coin: 'ETH'));
        $foreignSymbol = $base;
        $foreignSymbol['ordinal_state'] = $ethOrdinals->snapshot();

        [, $validSnapshot] = $this->canonicalPendingAssignment();
        $scope = 'mainnet/hyperliquid/BTCUSDT/candle_1m';
        $foreignVenue = $base;
        $foreignVenue['ordinal_state'] = $validSnapshot;
        $foreignVenue['ordinal_state']['scopes']['mainnet/okx/BTCUSDT/candle_1m']
            = $foreignVenue['ordinal_state']['scopes'][$scope];
        unset($foreignVenue['ordinal_state']['scopes'][$scope]);
        $foreignChannel = $base;
        $foreignChannel['ordinal_state'] = $validSnapshot;
        $foreignChannel['ordinal_state']['scopes']['mainnet/hyperliquid/BTCUSDT/public_trade']
            = $foreignChannel['ordinal_state']['scopes'][$scope];
        unset($foreignChannel['ordinal_state']['scopes'][$scope]);

        foreach ([
            'foreign symbol' => $foreignSymbol,
            'foreign venue' => $foreignVenue,
            'foreign channel' => $foreignChannel,
        ] as $label => $candidate) {
            try {
                $store->save($candidate);
                self::fail('Ordinal state must reject ' . $label . '.');
            } catch (HyperliquidHistoricalIntegrityException $exception) {
                self::assertSame(
                    'hyperliquid_acquisition_checkpoint_invalid',
                    $exception->getMessage(),
                    $label,
                );
            }
        }
    }

    public function testCheckpointEncodingIsStableAcrossSerializePrecisionSettings(): void
    {
        $request = $this->request('hyperliquid-checkpoint-precision');
        $datasetDirectory = $this->datasetDirectory('precision');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $state = $this->completedGrid($state);
        $path = $this->checkpointDirectory($datasetDirectory) . '/checkpoint.json';
        $originalPrecision = ini_get('serialize_precision');
        self::assertIsString($originalPrecision);

        try {
            self::assertNotFalse(ini_set('serialize_precision', '3'));
            $store->save($state);
            $lowPrecision = file_get_contents($path);
            self::assertSame('3', ini_get('serialize_precision'));
            self::assertNotFalse(ini_set('serialize_precision', '17'));
            $store->save($state);
            self::assertSame($lowPrecision, file_get_contents($path));
            self::assertSame('17', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', $originalPrecision);
        }
    }

    public function testChainMismatchAndCounterOverflowFailWithStableIntegrityReasons(): void
    {
        [$store, $state] = $this->storedPageFixture('chain-overflow');
        $chainMismatch = $state;
        $chainMismatch['streams']['BTC/candle_1m']['pages'][0]['chain_sha256']
            = str_repeat('0', 64);
        try {
            $store->save($chainMismatch);
            self::fail('A page chain mismatch must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_acquisition_page_chain_mismatch',
                $exception->getMessage(),
            );
        }

        $overflow = $state;
        $first = $overflow['streams']['BTC/candle_1m']['pages'][0];
        $first['row_count'] = \PHP_INT_MAX;
        $secondSha = hash('sha256', 'second');
        $second = [
            'file' => 'BTC-candle_1m-000002.ndjson',
            'sha256' => $secondSha,
            'row_count' => \PHP_INT_MAX,
            'chain_sha256' => hash('sha256', $first['chain_sha256'] . $secondSha),
        ];
        $overflow['streams']['BTC/candle_1m']['pages'] = [$first, $second];
        $overflow['page_count'] = 2;
        $overflow['staged_row_count'] = 0;
        try {
            $store->save($overflow);
            self::fail('Counter addition must reject integer overflow.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_acquisition_checkpoint_invalid',
                $exception->getMessage(),
            );
        }
    }

    public function testPinnedDirectoryReplacementBetweenMetadataAndOpenIsRejected(): void
    {
        $request = $this->request('hyperliquid-checkpoint-directory-open-race');
        $datasetDirectory = $this->datasetDirectory('directory-open-race');
        self::assertTrue(mkdir($datasetDirectory . '/checkpoints', 0700));
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $filesystem->openDirectoryHook = static function (string $directory): void {
            if (!str_ends_with($directory, '/checkpoints')) {
                return;
            }
            self::assertTrue(rename($directory, $directory . '-displaced'));
            self::assertTrue(mkdir($directory, 0700));
        };

        try {
            new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request, $filesystem);
            self::fail('A directory replacement between metadata and open must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_acquisition_directory_invalid',
                $exception->getMessage(),
            );
        }
        self::assertSame(
            [],
            array_values(array_diff(scandir($datasetDirectory . '/checkpoints') ?: [], ['.', '..'])),
        );
    }

    public function testCheckpointReplacementBetweenMetadataAndOpenIsRejected(): void
    {
        $request = $this->request('hyperliquid-checkpoint-file-open-race');
        $datasetDirectory = $this->datasetDirectory('file-open-race');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $store->loadOrCreate();
        $store->__destruct();
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $replaced = false;
        $filesystem->afterPathStatHook = static function (
            string $path,
            string $operation,
        ) use (&$replaced): void {
            if ($replaced
                || $operation !== 'hyperliquid_acquisition_file_load'
                || !str_ends_with($path, '/checkpoint.json')
            ) {
                return;
            }
            $replaced = true;
            self::assertTrue(rename($path, $path . '.displaced'));
            self::assertSame(2, file_put_contents($path, '{}'));
            self::assertTrue(chmod($path, 0600));
        };

        try {
            (new HyperliquidHistoricalCheckpointStore(
                $datasetDirectory,
                $request,
                $filesystem,
            ))->loadOrCreate();
            self::fail('A file replacement between metadata and open must be rejected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_acquisition_checkpoint_unreadable',
                $exception->getMessage(),
            );
        }
    }

    public function testDestinationAndTemporaryReplacementBeforeRenameFailPrePublication(): void
    {
        foreach (['destination', 'temporary'] as $boundary) {
            $request = $this->request(
                'hyperliquid-checkpoint-pre-rename-' . $boundary,
            );
            $datasetDirectory = $this->datasetDirectory('pre-rename-' . $boundary);
            $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
            $store = new HyperliquidHistoricalCheckpointStore(
                $datasetDirectory,
                $request,
                $filesystem,
            );
            $state = $store->loadOrCreate();
            $state['streams']['BTC/candle_1m'] = $this->emptyStream();
            $checkpointPath = $this->checkpointDirectory($datasetDirectory)
                . '/checkpoint.json';
            $before = file_get_contents($checkpointPath);
            $validationCount = 0;
            $replacement = $this->testRoot . '/' . $boundary . '-replacement';
            $filesystem->beforePathStatHook = static function (
                string $path,
                string $operation,
            ) use ($boundary, $checkpointPath, $replacement, &$validationCount): void {
                if ($operation === 'hyperliquid_acquisition_destination_validation'
                    && $path === $checkpointPath
                ) {
                    ++$validationCount;
                    if ($boundary === 'destination' && $validationCount === 2) {
                        self::assertTrue(link($path, $replacement));
                    }

                    return;
                }
                if ($boundary === 'temporary'
                    && $operation === 'hyperliquid_acquisition_file_validation'
                    && str_contains(basename($path), '.hyperliquid-acquisition-')
                ) {
                    ++$validationCount;
                    if ($validationCount === 2) {
                        self::assertTrue(rename($path, $replacement));
                        self::assertSame(8, file_put_contents($path, 'attacker'));
                        self::assertTrue(chmod($path, 0600));
                    }
                }
            };

            try {
                $store->save($state);
                self::fail($boundary . ' replacement must stop publication.');
            } catch (HyperliquidHistoricalIntegrityException $exception) {
                self::assertSame(
                    'hyperliquid_acquisition_file_invalid',
                    $exception->getMessage(),
                    $boundary,
                );
            }
            self::assertSame($before, file_get_contents($checkpointPath), $boundary);
            if ($boundary === 'temporary') {
                $staging = glob(
                    $this->checkpointDirectory($datasetDirectory)
                        . '/.hyperliquid-acquisition-*',
                ) ?: [];
                self::assertCount(1, $staging);
                self::assertSame('attacker', file_get_contents($staging[0]));
            }
            $store->__destruct();
        }
    }

    public function testPostRenameDestinationReplacementIsDetectedByIdentityCheck(): void
    {
        $request = $this->request('hyperliquid-checkpoint-post-rename-race');
        $datasetDirectory = $this->datasetDirectory('post-rename-race');
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request, $filesystem);
        $state = $store->loadOrCreate();
        $state['streams']['BTC/candle_1m'] = $this->emptyStream();
        $checkpointPath = $this->checkpointDirectory($datasetDirectory) . '/checkpoint.json';
        $filesystem->afterMoveHook = static function (string $destination): void {
            if (!str_ends_with($destination, '/checkpoint.json')) {
                return;
            }
            self::assertTrue(rename($destination, $destination . '.published'));
            self::assertSame(8, file_put_contents($destination, 'attacker'));
            self::assertTrue(chmod($destination, 0600));
        };

        try {
            $store->save($state);
            self::fail('A post-rename destination replacement must be detected.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_file_invalid', $exception->getMessage());
        }
        self::assertSame('attacker', file_get_contents($checkpointPath));
    }

    /** @return iterable<string, array{string}> */
    public static function interruptedReadProvider(): iterable
    {
        yield 'early eof' => ['eof'];
        yield 'partial read' => ['partial'];
        yield 'extra byte' => ['extra'];
    }

    #[DataProvider('interruptedReadProvider')]
    public function testInterruptedAndExtraByteReadsFailClosed(string $fault): void
    {
        $request = $this->request('hyperliquid-checkpoint-read-' . $fault);
        $datasetDirectory = $this->datasetDirectory('read-' . $fault);
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $store->loadOrCreate();
        $store->__destruct();
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $readCount = 0;
        $filesystem->readHook = static function (
            $handle,
            int $length,
            string $operation,
        ) use ($fault, &$readCount): ?string {
            if ($operation !== 'hyperliquid_acquisition_file_load') {
                return null;
            }
            ++$readCount;
            if ($fault === 'eof' && $readCount === 1) {
                return '';
            }
            if ($fault === 'partial' && $readCount === 1) {
                $contents = fread($handle, $length);
                self::assertIsString($contents);

                return substr($contents, 0, max(0, strlen($contents) - 1));
            }
            if ($fault === 'extra' && $length === 1) {
                return 'x';
            }

            return null;
        };

        try {
            (new HyperliquidHistoricalCheckpointStore(
                $datasetDirectory,
                $request,
                $filesystem,
            ))->loadOrCreate();
            self::fail($fault . ' must fail a checkpoint snapshot read.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_acquisition_checkpoint_unreadable',
                $exception->getMessage(),
            );
        }
    }

    public function testTemporaryCleanupDoesNotDeleteAReplacementRacedAfterMetadata(): void
    {
        $request = $this->request('hyperliquid-checkpoint-cleanup-race');
        $datasetDirectory = $this->datasetDirectory('cleanup-race');
        $filesystem = new FaultInjectingHyperliquidCheckpointFilesystem();
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request, $filesystem);
        $state = $store->loadOrCreate();
        $state['streams']['BTC/candle_1m'] = $this->emptyStream();
        $checkpointPath = $this->checkpointDirectory($datasetDirectory) . '/checkpoint.json';
        $before = file_get_contents($checkpointPath);
        $filesystem->failOperation = 'hyperliquid_acquisition_sync';
        $filesystem->removeFileHook = static function (string $path): void {
            self::assertTrue(rename($path, $path . '.original'));
            self::assertSame(8, file_put_contents($path, 'attacker'));
            self::assertTrue(chmod($path, 0600));
        };

        try {
            $store->save($state);
            self::fail('The injected pre-publication sync failure must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_acquisition_write_failed', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($checkpointPath));
        $staging = glob(
            $this->checkpointDirectory($datasetDirectory) . '/.hyperliquid-acquisition-*',
        ) ?: [];
        self::assertCount(2, $staging);
        $replacement = array_values(array_filter(
            $staging,
            static fn (string $path): bool => !str_ends_with($path, '.original'),
        ));
        self::assertCount(1, $replacement);
        self::assertSame('attacker', file_get_contents($replacement[0]));
    }

    public function testFailedStateWithStableReasonPersistsAndReloadsPartialGrid(): void
    {
        $request = $this->request('hyperliquid-checkpoint-failed');
        $datasetDirectory = $this->datasetDirectory('failed');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $state['staged_row_count'] = 0;
        $state['phase'] = 'failed';
        $state['failure_reason'] = 'hyperliquid_historical_transport_failed';
        $state['streams']['BTC/candle_1m'] = $this->emptyStream();

        $store->save($state);

        self::assertSame(
            json_decode(CanonicalJson::encode($state), true, 512, \JSON_THROW_ON_ERROR),
            $store->loadOrCreate(),
        );
    }

    public function testFailureReasonIsForbiddenOutsideFailedPhase(): void
    {
        $request = $this->request('hyperliquid-checkpoint-nonfailed-reason');
        $datasetDirectory = $this->datasetDirectory('nonfailed-reason');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $state['staged_row_count'] = 0;
        $state['failure_reason'] = 'hyperliquid_historical_transport_failed';
        $before = file_get_contents($this->checkpointDirectory($datasetDirectory) . '/checkpoint.json');

        try {
            $store->save($state);
            self::fail('Non-failed phases must reject failure_reason.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame(
            $before,
            file_get_contents($this->checkpointDirectory($datasetDirectory) . '/checkpoint.json'),
        );
    }

    public function testFailedStateRejectsMissingAndUnsafeFailureReasons(): void
    {
        $request = $this->request('hyperliquid-checkpoint-failed-reason');
        $datasetDirectory = $this->datasetDirectory('failed-reason');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $base = $store->loadOrCreate();
        $base['staged_row_count'] = 0;
        $base['phase'] = 'failed';
        $cases = [
            'missing' => null,
            'arbitrary text' => 'request failed with response body',
            'path' => 'hyperliquid_failed_/tmp/private',
            'uppercase' => 'hyperliquid_Historical_Failed',
            'oversized' => 'hyperliquid_' . str_repeat('a', 129),
            'non-string' => 7,
        ];

        foreach ($cases as $label => $reason) {
            $state = $base;
            if ($reason !== null) {
                $state['failure_reason'] = $reason;
            }
            try {
                $store->save($state);
                self::fail('Failed state must reject ' . $label . ' reason.');
            } catch (HyperliquidHistoricalIntegrityException $exception) {
                self::assertSame(
                    'hyperliquid_acquisition_checkpoint_invalid',
                    $exception->getMessage(),
                    $label,
                );
            }
        }
    }

    public function testOneStagedCandleCanRepresentTwoPaperEvents(): void
    {
        [$store, $state] = $this->storedPageFixture('two-events');
        $state['staged_row_count'] = 1;
        $state['event_count'] = 2;

        $store->save($state);
        $store->verifyPages($state);

        self::assertSame(
            json_decode(CanonicalJson::encode($state), true, 512, \JSON_THROW_ON_ERROR),
            $store->loadOrCreate(),
        );
    }

    public function testCompletionAcceptsTwoFinalizedEventsForOneStagedCandle(): void
    {
        [$store, $state] = $this->storedPageFixture('complete-two-events');
        $state = $this->completedGrid($state);
        $state['staged_row_count'] = 1;
        $state['event_count'] = 2;
        $state['emit_index'] = 2;
        $state['phase'] = 'complete';

        $store->save($state);

        self::assertSame(
            json_decode(CanonicalJson::encode($state), true, 512, \JSON_THROW_ON_ERROR),
            $store->loadOrCreate(),
        );
    }

    public function testStagedAndEventCountInconsistenciesAreRejected(): void
    {
        [$store, $base] = $this->storedPageFixture('count-invariants');
        $cases = [];
        $wrongSum = $base;
        $wrongSum['staged_row_count'] = 0;
        $cases['staged count differs from pages'] = $wrongSum;
        $fewerEvents = $base;
        $fewerEvents['event_count'] = 0;
        $cases['fewer events than candles'] = $fewerEvents;
        $tooManyEvents = $base;
        $tooManyEvents['event_count'] = 3;
        $cases['more than two events per candle'] = $tooManyEvents;
        $negative = $base;
        $negative['staged_row_count'] = -1;
        $cases['negative staged count'] = $negative;
        $wrongType = $base;
        $wrongType['staged_row_count'] = '1';
        $cases['non-integer staged count'] = $wrongType;
        $overBound = $base;
        $overBound['streams']['BTC/candle_1m']['pages'][0]['row_count'] = 5;
        $overBound['staged_row_count'] = 5;
        $overBound['event_count'] = 5;
        $cases['raw count exceeds event acquisition bound'] = $overBound;

        foreach ($cases as $label => $state) {
            try {
                $store->save($state);
                self::fail('Count validation must reject ' . $label . '.');
            } catch (HyperliquidHistoricalIntegrityException $exception) {
                self::assertSame(
                    'hyperliquid_acquisition_checkpoint_invalid',
                    $exception->getMessage(),
                    $label,
                );
            }
        }
    }

    public function testSchemaV1OrdinalSnapshotAndInvalidStateAreRejected(): void
    {
        $request = $this->request('hyperliquid-checkpoint-invalid-state');
        $datasetDirectory = $this->datasetDirectory('invalid-state');
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $state = $store->loadOrCreate();
        $checkpointPath = $this->checkpointDirectory($datasetDirectory) . '/checkpoint.json';
        $before = file_get_contents($checkpointPath);
        $state['ordinal_state'] = ['schema_version' => 1, 'scopes' => []];

        try {
            $store->save($state);
            self::fail('Legacy ordinal snapshots must not be silently accepted.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame('hyperliquid_acquisition_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($checkpointPath));
    }

    /**
     * @return array{
     *     HyperliquidHistoricalCheckpointStore,
     *     array<string, mixed>,
     *     string
     * }
     */
    private function storedPageFixture(string $name): array
    {
        $datasetId = 'hyperliquid-checkpoint-fixture-' . $name;
        $datasetDirectory = $this->datasetDirectory('fixture-' . $name);
        $store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $this->request($datasetId));
        $state = $store->loadOrCreate();
        $page = $store->writePage('BTC-candle_1m-000001.ndjson', [['a' => 1]]);
        $page['chain_sha256'] = hash('sha256', str_repeat('0', 64) . $page['sha256']);
        $state['streams']['BTC/candle_1m'] = $this->emptyStream();
        $state['streams']['BTC/candle_1m']['pages'] = [$page];
        $state['page_count'] = 1;
        $state['staged_row_count'] = 1;
        $state['event_count'] = 1;
        $store->save($state);

        return [
            $store,
            $state,
            $this->checkpointDirectory($datasetDirectory) . '/pages/' . $page['file'],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyStream(): array
    {
        return [
            'kind' => 'candle',
            'coin' => 'BTC',
            'interval' => '1m',
            'next_cursor' => 1_721_556_060_000,
            'complete' => false,
            'pages' => [],
        ];
    }

    /** @param array<string, mixed> $state
     *  @return array<string, mixed>
     */
    private function completedGrid(array $state): array
    {
        $streams = [];
        foreach (['BTC', 'ETH'] as $coin) {
            foreach (['1m', '5m', '15m', '1h'] as $interval) {
                $key = $coin . '/candle_' . $interval;
                $stream = $state['streams'][$key] ?? [
                    'kind' => 'candle',
                    'coin' => $coin,
                    'interval' => $interval,
                    'next_cursor' => 0,
                    'complete' => true,
                    'pages' => [],
                ];
                $stream['complete'] = true;
                $streams[$key] = $stream;
            }
        }
        $state['streams'] = $streams;

        return $state;
    }

    /**
     * @return array{PaperMarketEvent, array<string, mixed>, string}
     */
    private function canonicalPendingAssignment(): array
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $event = (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            $ordinals,
        ))->candle($this->candle());

        return [$event, $ordinals->snapshot(), 'BTC|1m|0|59999'];
    }

    private function candle(
        string $coin = 'BTC',
        int $start = 0,
    ): HyperliquidCandle
    {
        return HyperliquidCandle::fromApiRow([
            'T' => $start + 59_999,
            'c' => '100',
            'h' => '101',
            'i' => '1m',
            'l' => '99',
            'n' => 10,
            'o' => '100',
            's' => $coin,
            't' => $start,
            'v' => '5',
        ], $coin, '1m');
    }

    private function checkpointPath(string $fixture): string
    {
        return $this->checkpointDirectory($this->testRoot . '/fixture-' . $fixture)
            . '/checkpoint.json';
    }

    private function request(string $datasetId): HyperliquidHistoricalRequest
    {
        return new HyperliquidHistoricalRequest(
            datasetId: $datasetId,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT', 'ETHUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
            maximumEvents: 4,
            maximumPages: 4,
            maximumResponseBytes: 1_024,
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
        return $datasetDirectory . '/checkpoints/hyperliquid-acquisition/mainnet';
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

final class FaultInjectingHyperliquidCheckpointFilesystem extends PaperDatasetRecorderFilesystem
{
    public ?string $failOperation = null;
    public ?\Closure $beforePathStatHook = null;
    public ?\Closure $afterPathStatHook = null;
    public ?\Closure $openDirectoryHook = null;
    public ?\Closure $afterMoveHook = null;
    public ?\Closure $readHook = null;
    public ?\Closure $removeFileHook = null;
    public ?\Closure $createPrivateFileHook = null;

    /** @var list<string> */
    public array $operations = [];

    public function move(string $source, string $destination, string $operation): bool
    {
        $this->operations[] = 'move:' . basename($destination);

        $moved = $this->failOperation !== $operation
            && parent::move($source, $destination, $operation);
        if ($moved && $this->afterMoveHook !== null) {
            ($this->afterMoveHook)($destination, $operation);
        }

        return $moved;
    }

    /** @param resource $handle */
    public function write($handle, string $contents, string $operation): int|false
    {
        return $this->failOperation === $operation
            ? false
            : parent::write($handle, $contents, $operation);
    }

    /** @param resource $handle */
    public function flush($handle, string $operation): bool
    {
        return $this->failOperation !== $operation && parent::flush($handle, $operation);
    }

    public function sync($handle, string $operation): bool
    {
        $this->operations[] = 'sync:' . $operation;

        return $this->failOperation !== $operation && parent::sync($handle, $operation);
    }

    /** @return resource|false */
    public function openDirectory(string $directory, string $operation)
    {
        if ($this->openDirectoryHook !== null) {
            ($this->openDirectoryHook)($directory, $operation);
        }

        return parent::openDirectory($directory, $operation);
    }

    /** @return array<string, mixed>|false */
    public function pathStat(string $path, string $operation): array|false
    {
        if ($this->beforePathStatHook !== null) {
            ($this->beforePathStatHook)($path, $operation);
        }
        $statistics = parent::pathStat($path, $operation);
        if ($this->afterPathStatHook !== null) {
            ($this->afterPathStatHook)($path, $operation, $statistics);
        }

        return $statistics;
    }

    /** @param resource $handle */
    public function read($handle, int $length, string $operation): string|false
    {
        if ($this->readHook !== null) {
            $result = ($this->readHook)($handle, $length, $operation);
            if ($result !== null) {
                return $result;
            }
        }

        return parent::read($handle, $length, $operation);
    }

    /** @param array<string, mixed> $expectedStatistics */
    public function removeFile(string $path, array $expectedStatistics, string $operation): bool
    {
        if ($this->removeFileHook !== null) {
            ($this->removeFileHook)($path, $operation, $expectedStatistics);
        }

        return parent::removeFile($path, $expectedStatistics, $operation);
    }

    /** @return resource|false */
    public function createPrivateFile(string $path, string $operation)
    {
        if ($this->createPrivateFileHook !== null) {
            ($this->createPrivateFileHook)($path, $operation);
        }

        return parent::createPrivateFile($path, $operation);
    }
}
