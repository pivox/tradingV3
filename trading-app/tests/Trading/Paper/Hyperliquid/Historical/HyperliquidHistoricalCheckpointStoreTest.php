<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalCheckpointStore;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalIntegrityException;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalRequest;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
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
        $event = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable('2026-07-21T10:00:59.999000Z'),
            new \DateTimeImmutable('2026-07-21T10:01:00.000000Z'),
            '1',
            ['origin' => 'historical'],
        );
        $state['phase'] = 'emitting';
        $state['streams']['BTC/candle_1m']['complete'] = true;
        $state['emit_index'] = 0;
        $state['pending_event'] = [
            'natural_identity' => 'BTC|1m|1721556000000|1721556059999',
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

    /** @var list<string> */
    public array $operations = [];

    public function move(string $source, string $destination, string $operation): bool
    {
        $this->operations[] = 'move:' . basename($destination);

        return $this->failOperation !== $operation && parent::move($source, $destination, $operation);
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
}
