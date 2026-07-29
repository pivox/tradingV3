<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final class HyperliquidHistoricalCheckpointStore
{
    private const SCHEMA_VERSION = 1;
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const SYMLINK_FILE_TYPE = 0120000;
    private const FILE_TYPE_MASK = 0170000;
    private const PAGE_FILENAME_PATTERN = '/\A(?:BTC|ETH)-candle_(?:1m|5m|15m|1h)-[0-9]{6}\.ndjson\z/D';
    private const SHA256_PATTERN = '/\A[a-f0-9]{64}\z/D';
    private const WRITER_LOCK_FILENAME = '.writer.lock';
    private const CHECKPOINT_BYTES_PER_PAGE = 512;
    private const SERIALIZE_PRECISION_SETTING = 'serialize_precision';
    private const CANONICAL_SERIALIZE_PRECISION = '-1';
    private const JSON_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_PRESERVE_ZERO_FRACTION;

    private readonly PaperDatasetRecorderFilesystem $filesystem;
    private readonly string $directory;
    private readonly string $pagesDirectory;
    private readonly string $checkpointPath;

    /** @var list<string> */
    private readonly array $coins;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $datasetPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $checkpointsPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $acquisitionPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $directoryPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $pagesPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string} */
    private array $writerLock;

    public function __construct(
        #[\SensitiveParameter] string $datasetDirectory,
        private readonly HyperliquidHistoricalRequest $request,
        ?PaperDatasetRecorderFilesystem $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new PaperDatasetRecorderFilesystem();
        $instrumentMap = new HyperliquidPaperInstrumentMap();
        $coins = [];
        foreach ($request->symbols as $symbol) {
            $coins[] = $instrumentMap->nativeCoin($symbol);
        }
        $coins = array_values(array_unique($coins));
        sort($coins, \SORT_STRING);
        $this->coins = $coins;

        $this->assertNoSymlinkComponents($datasetDirectory);
        $resolvedDataset = realpath($datasetDirectory);
        if ($resolvedDataset === false) {
            throw new HyperliquidHistoricalIntegrityException('hyperliquid_acquisition_directory_invalid');
        }

        $this->datasetPin = $this->openPinnedDirectory($resolvedDataset, requirePrivate: false);
        try {
            $this->checkpointsPin = $this->ensureManagedDirectory($this->datasetPin, 'checkpoints');
            $this->acquisitionPin = $this->ensureManagedDirectory(
                $this->checkpointsPin,
                'hyperliquid-acquisition',
            );
            $this->directoryPin = $this->ensureManagedDirectory(
                $this->acquisitionPin,
                $request->network->value,
            );
            $this->pagesPin = $this->ensureManagedDirectory($this->directoryPin, 'pages');
            $this->directory = $this->directoryPin['path'];
            $this->pagesDirectory = $this->pagesPin['path'];
            $this->checkpointPath = $this->directory . '/checkpoint.json';
            $this->writerLock = $this->acquireWriterLock();
        } catch (\Throwable $failure) {
            $this->closeInitializedResources();

            throw $failure;
        }
    }

    public function __destruct()
    {
        if (isset($this->writerLock) && \is_resource($this->writerLock['handle'])) {
            @flock($this->writerLock['handle'], \LOCK_UN);
            fclose($this->writerLock['handle']);
        }
        foreach (
            ['pagesPin', 'directoryPin', 'acquisitionPin', 'checkpointsPin', 'datasetPin']
            as $property
        ) {
            if (isset($this->{$property}) && \is_resource($this->{$property}['handle'])) {
                fclose($this->{$property}['handle']);
            }
        }
    }

    /** @return array<string, mixed> */
    public function loadOrCreate(): array
    {
        $this->assertManagedDirectories();
        $statistics = $this->pathStatistics(
            $this->checkpointPath,
            'hyperliquid_acquisition_checkpoint_load',
        );
        if ($statistics === false) {
            $state = [
                'dataset_id' => $this->request->datasetId,
                'emit_index' => 0,
                'event_count' => 0,
                'network' => $this->request->network->value,
                'ordinal_state' => (new HyperliquidPaperSourceOrdinal())->snapshot(),
                'page_count' => 0,
                'pending_event' => null,
                'phase' => 'fetching',
                'request_sha256' => $this->request->requestSha256(),
                'schema_version' => self::SCHEMA_VERSION,
                'streams' => [],
            ];
            $this->save($state);

            return $state;
        }

        $state = $this->readState();
        $this->validateState($state);
        $this->assertManagedDirectories();

        return $state;
    }

    /** @param array<string, mixed> $state */
    public function save(#[\SensitiveParameter] array $state): void
    {
        $this->validateState($state);
        $this->atomicWrite(
            $this->checkpointPath,
            $this->encodeState($state) . "\n",
            $this->directoryPin,
        );
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array{file: string, sha256: string, row_count: int}
     */
    public function writePage(string $filename, array $records): array
    {
        if (preg_match(self::PAGE_FILENAME_PATTERN, $filename) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_acquisition_page_name_invalid');
        }

        $contents = '';
        try {
            foreach ($records as $record) {
                $contents .= $this->encodePageRecord($record) . "\n";
                if (strlen($contents) > $this->request->maximumResponseBytes) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_acquisition_page_oversized',
                    );
                }
            }
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_page_invalid',
                0,
                $exception,
            );
        }

        $this->atomicWrite($this->pagesDirectory . '/' . $filename, $contents, $this->pagesPin);

        return [
            'file' => $filename,
            'sha256' => hash('sha256', $contents),
            'row_count' => \count($records),
        ];
    }

    private function encodePageRecord(mixed $record): string
    {
        if (!\is_array($record) || array_is_list($record)) {
            throw new \InvalidArgumentException();
        }

        return CanonicalJson::encode($record);
    }

    /** @return list<array<string, mixed>> */
    public function readPage(string $filename): array
    {
        $this->assertPageFilename($filename);
        $contents = $this->readSnapshot(
            $this->pagesDirectory . '/' . $filename,
            $this->pagesPin,
            allowEmpty: true,
            unreadableError: 'hyperliquid_acquisition_page_unreadable',
            maximumBytes: $this->request->maximumResponseBytes,
            oversizedError: 'hyperliquid_acquisition_page_oversized',
        );

        return $this->decodePageContents($contents);
    }

    /** @param array<string, mixed> $state */
    public function verifyPages(array $state): void
    {
        $this->validateState($state);
        $this->assertManagedDirectories();
        $eventCount = 0;
        foreach ($state['streams'] as $stream) {
            foreach ($stream['pages'] as $page) {
                $this->assertPageFilename($page['file']);
                $contents = $this->readSnapshot(
                    $this->pagesDirectory . '/' . $page['file'],
                    $this->pagesPin,
                    allowEmpty: true,
                    unreadableError: 'hyperliquid_acquisition_page_unreadable',
                    maximumBytes: $this->request->maximumResponseBytes,
                    oversizedError: 'hyperliquid_acquisition_page_oversized',
                );
                if (!hash_equals($page['sha256'], hash('sha256', $contents))) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_acquisition_page_hash_mismatch',
                    );
                }
                $records = $this->decodePageContents($contents);
                if (\count($records) !== $page['row_count']) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_acquisition_checkpoint_invalid',
                    );
                }
                $eventCount += \count($records);
            }
        }
        if ($eventCount !== $state['event_count']) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
    }

    /** @return list<array<string, mixed>> */
    private function decodePageContents(string $contents): array
    {
        if ($contents === '') {
            return [];
        }
        if (!str_ends_with($contents, "\n")) {
            throw new HyperliquidHistoricalIntegrityException('hyperliquid_acquisition_page_invalid');
        }

        $records = [];
        try {
            $lines = explode("\n", substr($contents, 0, -1));
            foreach ($lines as $line) {
                if ($line === '') {
                    throw new \JsonException();
                }
                $record = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($record)
                    || array_is_list($record)
                    || !hash_equals(CanonicalJson::encode($record), $line)
                ) {
                    throw new \JsonException();
                }
                $records[] = $record;
            }
        } catch (\Throwable $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_page_invalid',
                0,
                $exception,
            );
        }

        return $records;
    }

    /** @param array<string, mixed> $state */
    private function validateState(array $state): void
    {
        $this->assertExactKeys($state, [
            'schema_version',
            'network',
            'dataset_id',
            'request_sha256',
            'phase',
            'streams',
            'page_count',
            'event_count',
            'emit_index',
            'ordinal_state',
            'pending_event',
        ]);
        if ($state['schema_version'] !== self::SCHEMA_VERSION
            || !\is_string($state['network'])
            || !\is_string($state['dataset_id'])
            || !\is_string($state['request_sha256'])
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        if (!hash_equals($this->request->network->value, $state['network'])
            || !hash_equals($this->request->datasetId, $state['dataset_id'])
            || !hash_equals($this->request->requestSha256(), $state['request_sha256'])
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_request_mismatch',
            );
        }
        if (!\is_string($state['phase'])
            || !\in_array($state['phase'], ['fetching', 'emitting', 'complete'], true)
            || !\is_array($state['streams'])
            || (array_is_list($state['streams']) && $state['streams'] !== [])
            || !\is_int($state['page_count'])
            || $state['page_count'] < 0
            || $state['page_count'] > $this->request->maximumPages
            || !\is_int($state['event_count'])
            || $state['event_count'] < 0
            || $state['event_count'] > $this->request->maximumEvents
            || !\is_int($state['emit_index'])
            || $state['emit_index'] < 0
            || $state['emit_index'] > $state['event_count']
            || !\is_array($state['ordinal_state'])
            || array_is_list($state['ordinal_state'])
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }

        $pageCount = 0;
        $eventCount = 0;
        foreach ($state['streams'] as $key => $stream) {
            if (!\is_string($key) || !\is_array($stream) || array_is_list($stream)) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_checkpoint_invalid',
                );
            }
            $this->validateStream($key, $stream);
            $pageCount += \count($stream['pages']);
            foreach ($stream['pages'] as $page) {
                if ($page['row_count'] > $this->request->maximumEvents
                    || $eventCount > $this->request->maximumEvents - $page['row_count']
                ) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_acquisition_checkpoint_invalid',
                    );
                }
                $eventCount += $page['row_count'];
            }
        }
        if ($pageCount !== $state['page_count'] || $eventCount !== $state['event_count']) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }

        try {
            $ordinals = HyperliquidPaperSourceOrdinal::restore($state['ordinal_state']);
        } catch (\InvalidArgumentException $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
                0,
                $exception,
            );
        }
        $ordinalState = $ordinals->snapshot();
        $this->validateOrdinalScopes($ordinalState['scopes']);

        $pendingEvent = null;
        if ($state['pending_event'] !== null) {
            $pendingEvent = $this->validatePendingEvent($state['pending_event']);
            $this->validatePendingAssignment(
                $state['pending_event'],
                $pendingEvent,
                $ordinalState['scopes'],
            );
        }
        if (($state['phase'] === 'fetching'
                && ($state['emit_index'] !== 0 || $state['pending_event'] !== null))
            || ($state['phase'] === 'complete'
                && ($state['emit_index'] !== $state['event_count']
                    || $state['pending_event'] !== null))
            || ($state['pending_event'] !== null
                && ($state['phase'] !== 'emitting'
                    || $state['emit_index'] >= $state['event_count']))
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        if (\in_array($state['phase'], ['emitting', 'complete'], true)) {
            $this->assertCompletedRequestedGrid($state['streams']);
        }
    }

    /** @param array<string, mixed> $stream */
    private function validateStream(string $key, array $stream): void
    {
        $this->assertExactKeys(
            $stream,
            ['kind', 'coin', 'interval', 'next_cursor', 'complete', 'pages'],
        );
        if ($stream['kind'] !== 'candle'
            || !\is_string($stream['coin'])
            || !\in_array($stream['coin'], $this->coins, true)
            || !\is_string($stream['interval'])
            || !\in_array($stream['interval'], $this->request->intervals, true)
            || $key !== $stream['coin'] . '/candle_' . $stream['interval']
            || !\is_int($stream['next_cursor'])
            || $stream['next_cursor'] < 0
            || !\is_bool($stream['complete'])
            || !\is_array($stream['pages'])
            || !array_is_list($stream['pages'])
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }

        $previousChain = str_repeat('0', 64);
        foreach ($stream['pages'] as $index => $page) {
            if (!\is_array($page) || array_is_list($page)) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_checkpoint_invalid',
                );
            }
            $this->assertExactKeys($page, ['file', 'sha256', 'row_count', 'chain_sha256']);
            $expectedFile = str_replace('/', '-', $key)
                . '-' . str_pad((string) ($index + 1), 6, '0', \STR_PAD_LEFT)
                . '.ndjson';
            if (!\is_string($page['file'])
                || !hash_equals($expectedFile, $page['file'])
                || !\is_string($page['sha256'])
                || preg_match(self::SHA256_PATTERN, $page['sha256']) !== 1
                || !\is_int($page['row_count'])
                || $page['row_count'] < 0
                || !\is_string($page['chain_sha256'])
                || preg_match(self::SHA256_PATTERN, $page['chain_sha256']) !== 1
            ) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_checkpoint_invalid',
                );
            }
            $chain = hash('sha256', $previousChain . $page['sha256']);
            if (!hash_equals($chain, $page['chain_sha256'])) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_page_chain_mismatch',
                );
            }
            $previousChain = $chain;
        }
    }

    private function validatePendingEvent(mixed $pending): PaperMarketEvent
    {
        if (!\is_array($pending) || array_is_list($pending)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        $this->assertExactKeys($pending, ['natural_identity', 'event']);
        if (!\is_string($pending['natural_identity'])
            || $pending['natural_identity'] === ''
            || !\is_array($pending['event'])
            || array_is_list($pending['event'])
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        try {
            $event = PaperMarketEvent::fromArray($pending['event']);
            if ($event->sourceNetwork !== $this->request->network
                || $event->sourceVenue->value !== 'hyperliquid'
                || !\in_array($event->symbol, $this->request->symbols, true)
            ) {
                throw new \InvalidArgumentException();
            }
            if (!hash_equals(
                CanonicalJson::encode($event->toArray()),
                CanonicalJson::encode($pending['event']),
            )) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
                0,
                $exception,
            );
        }

        return $event;
    }

    /** @param array<string, mixed> $scopes */
    private function validateOrdinalScopes(array $scopes): void
    {
        $allowedChannels = ['candle_1m', 'candle_5m', 'candle_15m', 'candle_1h', 'top_of_book'];
        foreach (array_keys($scopes) as $scope) {
            $parts = explode('/', $scope);
            if (\count($parts) !== 4
                || $parts[0] !== $this->request->network->value
                || $parts[1] !== 'hyperliquid'
                || !\in_array($parts[2], $this->request->symbols, true)
                || !\in_array($parts[3], $allowedChannels, true)
            ) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_checkpoint_invalid',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $pending
     * @param array<string, mixed> $scopes
     */
    private function validatePendingAssignment(
        array $pending,
        PaperMarketEvent $event,
        array $scopes,
    ): void {
        $scope = implode('/', [
            $event->sourceNetwork->value,
            $event->sourceVenue->value,
            $event->symbol,
            $event->channel->value,
        ]);
        $latest = $scopes[$scope]['latest'] ?? null;
        if (!\is_array($latest)
            || !\is_string($latest['natural_identity'] ?? null)
            || !hash_equals($latest['natural_identity'], $pending['natural_identity'])
            || !\is_array($latest['event'] ?? null)
            || !hash_equals(
                CanonicalJson::encode($latest['event']),
                CanonicalJson::encode($pending['event']),
            )
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
    }

    /** @param array<string, mixed> $streams */
    private function assertCompletedRequestedGrid(array $streams): void
    {
        $expected = [];
        foreach ($this->coins as $coin) {
            foreach ($this->request->intervals as $interval) {
                $expected[] = $coin . '/candle_' . $interval;
            }
        }
        sort($expected, \SORT_STRING);
        $actual = array_keys($streams);
        sort($actual, \SORT_STRING);
        if ($actual !== $expected) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        foreach ($streams as $stream) {
            if ($stream['complete'] !== true) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_checkpoint_invalid',
                );
            }
        }
    }

    /** @param array<string, mixed> $state */
    private function encodeState(#[\SensitiveParameter] array $state): string
    {
        $previousPrecision = $this->configureCanonicalSerializePrecision();
        try {
            $normalized = $this->normalizeForJson($state);
            $encoded = json_encode($normalized, self::JSON_FLAGS);
        } catch (\Throwable $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
                0,
                $exception,
            );
        } finally {
            $this->restoreSerializePrecision($previousPrecision);
        }
        if (strlen($encoded) + 1 > $this->maximumCheckpointBytes()) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }

        return $encoded;
    }

    private function configureCanonicalSerializePrecision(): string
    {
        $previous = ini_get(self::SERIALIZE_PRECISION_SETTING);
        if (!\is_string($previous)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        if ($previous !== self::CANONICAL_SERIALIZE_PRECISION
            && (\ini_set(
                self::SERIALIZE_PRECISION_SETTING,
                self::CANONICAL_SERIALIZE_PRECISION,
            ) === false
                || ini_get(self::SERIALIZE_PRECISION_SETTING)
                    !== self::CANONICAL_SERIALIZE_PRECISION)
        ) {
            $this->restoreSerializePrecision($previous);
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }

        return $previous;
    }

    private function restoreSerializePrecision(string $precision): void
    {
        if (ini_get(self::SERIALIZE_PRECISION_SETTING) === $precision) {
            return;
        }
        if (\ini_set(self::SERIALIZE_PRECISION_SETTING, $precision) === false
            || ini_get(self::SERIALIZE_PRECISION_SETTING) !== $precision
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
    }

    private function normalizeForJson(mixed $value): mixed
    {
        if (!\is_array($value)) {
            if (\is_object($value)
                || \is_resource($value)
                || \is_float($value)
            ) {
                throw new \InvalidArgumentException();
            }

            return $value;
        }
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeForJson($item);
        }
        if (!array_is_list($normalized)) {
            ksort($normalized, \SORT_STRING);
        }

        return $normalized;
    }

    private function maximumCheckpointBytes(): int
    {
        return CanonicalJson::MAX_BYTES
            + ($this->request->maximumPages * self::CHECKPOINT_BYTES_PER_PAGE);
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $contents = $this->readSnapshot(
            $this->checkpointPath,
            $this->directoryPin,
            allowEmpty: false,
            unreadableError: 'hyperliquid_acquisition_checkpoint_unreadable',
            maximumBytes: $this->maximumCheckpointBytes(),
            oversizedError: 'hyperliquid_acquisition_checkpoint_invalid',
        );
        try {
            $state = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
                0,
                $exception,
            );
        }
        if (!\is_array($state) || array_is_list($state)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        try {
            if (!hash_equals($this->encodeState($state) . "\n", $contents)) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_checkpoint_invalid',
                );
            }
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
                0,
                $exception,
            );
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string>         $expected
     */
    private function assertExactKeys(array $state, array $expected): void
    {
        $actual = array_keys($state);
        sort($actual, \SORT_STRING);
        sort($expected, \SORT_STRING);
        if ($actual !== $expected) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
    }

    private function assertPageFilename(string $filename): void
    {
        if (preg_match(self::PAGE_FILENAME_PATTERN, $filename) !== 1) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_page_name_invalid',
            );
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $parentPin
     *
     * @return array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool}
     */
    private function ensureManagedDirectory(array $parentPin, string $name): array
    {
        $this->assertPinnedDirectory($parentPin);
        $path = $parentPin['path'] . '/' . $name;
        $statistics = $this->pathStatistics($path, 'hyperliquid_acquisition_directory_validation');
        $created = false;
        if ($statistics === false) {
            $this->assertPinnedDirectory($parentPin);
            if (!$this->filesystem->createDirectory($path, 0700)) {
                $statistics = $this->pathStatistics(
                    $path,
                    'hyperliquid_acquisition_directory_validation',
                );
                if ($statistics === false) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_acquisition_directory_create_failed',
                    );
                }
            } else {
                $created = true;
                $statistics = $this->pathStatistics(
                    $path,
                    'hyperliquid_acquisition_directory_validation',
                );
            }
        }
        if ($statistics === false || $this->isSymlink($statistics) || !$this->isPrivateDirectory($statistics)) {
            throw new HyperliquidHistoricalIntegrityException(
                $statistics !== false && $this->isSymlink($statistics)
                    ? 'hyperliquid_acquisition_file_invalid'
                    : 'hyperliquid_acquisition_directory_invalid',
            );
        }
        $pin = $this->openPinnedDirectory($path, requirePrivate: true, expected: $statistics);
        try {
            $this->assertPinnedDirectory($parentPin);
            $this->assertPinnedDirectory($pin);
            if ($created
                && !$this->filesystem->sync(
                    $parentPin['handle'],
                    'hyperliquid_acquisition_directory_parent_sync',
                )
            ) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_directory_invalid',
                );
            }

            return $pin;
        } catch (\Throwable $failure) {
            fclose($pin['handle']);

            throw $failure;
        }
    }

    /**
     * @param array<string, mixed>|null $expected
     *
     * @return array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool}
     */
    private function openPinnedDirectory(
        string $path,
        bool $requirePrivate,
        ?array $expected = null,
    ): array {
        $before = $this->pathStatistics($path, 'hyperliquid_acquisition_directory_validation');
        if ($before === false || $this->isSymlink($before) || !$this->isDirectory($before)) {
            throw new HyperliquidHistoricalIntegrityException(
                $before !== false && $this->isSymlink($before)
                    ? 'hyperliquid_acquisition_file_invalid'
                    : 'hyperliquid_acquisition_directory_invalid',
            );
        }
        if ($requirePrivate && !$this->isPrivateDirectory($before)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_directory_invalid',
            );
        }
        if ($expected !== null && !$this->sameFile($expected, $before)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_directory_invalid',
            );
        }
        $handle = $this->filesystem->openDirectory(
            $path,
            'hyperliquid_acquisition_directory_open',
        );
        if ($handle === false) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_directory_invalid',
            );
        }
        try {
            $opened = $this->filesystem->stat(
                $handle,
                'hyperliquid_acquisition_directory_validation',
            );
            if ($opened === false
                || !$this->isDirectory($opened)
                || ($requirePrivate && !$this->isPrivateDirectory($opened))
                || !$this->sameFile($before, $opened)
                || !isset($opened['dev'], $opened['ino'])
                || !\is_int($opened['dev'])
                || !\is_int($opened['ino'])
            ) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_directory_invalid',
                );
            }

            return [
                'handle' => $handle,
                'identity' => ['dev' => $opened['dev'], 'ino' => $opened['ino']],
                'path' => $path,
                'private' => $requirePrivate,
            ];
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $pin */
    private function assertPinnedDirectory(array $pin): void
    {
        $opened = $this->filesystem->stat(
            $pin['handle'],
            'hyperliquid_acquisition_directory_validation',
        );
        $current = $this->pathStatistics(
            $pin['path'],
            'hyperliquid_acquisition_directory_validation',
        );
        if ($current !== false && $this->isSymlink($current)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_file_invalid',
            );
        }
        if ($opened === false
            || $current === false
            || !$this->isDirectory($opened)
            || !$this->isDirectory($current)
            || ($pin['private']
                && (!$this->isPrivateDirectory($opened) || !$this->isPrivateDirectory($current)))
            || !$this->sameFile($pin['identity'], $opened)
            || !$this->sameFile($pin['identity'], $current)
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_directory_invalid',
            );
        }
    }

    private function assertManagedDirectories(): void
    {
        $this->assertPinnedDirectory($this->datasetPin);
        $this->assertPinnedDirectory($this->checkpointsPin);
        $this->assertPinnedDirectory($this->acquisitionPin);
        $this->assertPinnedDirectory($this->directoryPin);
        $this->assertPinnedDirectory($this->pagesPin);
        if (isset($this->writerLock)) {
            $this->assertWriterLock();
        }
    }

    /** @return array{handle: resource, identity: array{dev: int, ino: int}, path: string} */
    private function acquireWriterLock(): array
    {
        $path = $this->directory . '/' . self::WRITER_LOCK_FILENAME;
        $this->assertManagedDirectories();
        $statistics = $this->pathStatistics($path, 'hyperliquid_acquisition_lock_validation');
        if ($statistics !== false && !$this->isPrivateRegularFile($statistics)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_lock_invalid',
            );
        }

        $created = $statistics === false;
        $handle = $created
            ? $this->filesystem->createPrivateFile($path, 'hyperliquid_acquisition_lock_create')
            : @fopen($path, 'r+b');
        if ($handle === false && $created) {
            $statistics = $this->pathStatistics($path, 'hyperliquid_acquisition_lock_validation');
            if ($statistics !== false && $this->isPrivateRegularFile($statistics)) {
                $created = false;
                $handle = @fopen($path, 'r+b');
            }
        }
        if ($handle === false) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_lock_invalid',
            );
        }

        $locked = false;
        try {
            $identity = $this->assertHandleMatchesPath(
                $handle,
                $path,
                error: 'hyperliquid_acquisition_lock_invalid',
                operation: 'hyperliquid_acquisition_lock_validation',
            );
            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_lock_unavailable',
                );
            }
            $locked = true;
            $lock = ['handle' => $handle, 'identity' => $identity, 'path' => $path];
            $this->assertWriterLockPin($lock);
            if ($created
                && !$this->filesystem->sync(
                    $this->directoryPin['handle'],
                    'hyperliquid_acquisition_lock_directory_sync',
                )
            ) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_lock_invalid',
                );
            }

            return $lock;
        } catch (\Throwable $failure) {
            if ($locked) {
                @flock($handle, \LOCK_UN);
            }
            fclose($handle);

            throw $failure;
        }
    }

    private function assertWriterLock(): void
    {
        $this->assertWriterLockPin($this->writerLock);
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $lock */
    private function assertWriterLockPin(array $lock): void
    {
        $this->assertHandleMatchesPath(
            $lock['handle'],
            $lock['path'],
            $lock['identity'],
            'hyperliquid_acquisition_lock_invalid',
            'hyperliquid_acquisition_lock_validation',
        );
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $parentPin
     */
    private function readSnapshot(
        string $path,
        array $parentPin,
        bool $allowEmpty,
        string $unreadableError,
        int $maximumBytes,
        string $oversizedError,
    ): string {
        $this->assertManagedDirectories();
        $this->assertPinnedDirectory($parentPin);
        $before = $this->pathStatistics($path, 'hyperliquid_acquisition_file_load');
        if ($before === false || $this->isSymlink($before) || !$this->isPrivateRegularFile($before)) {
            throw new HyperliquidHistoricalIntegrityException(
                $before !== false && $this->isSymlink($before)
                    ? 'hyperliquid_acquisition_file_invalid'
                    : $unreadableError,
            );
        }
        if (!isset($before['size'])
            || !\is_int($before['size'])
            || (!$allowEmpty && $before['size'] === 0)
        ) {
            throw new HyperliquidHistoricalIntegrityException($unreadableError);
        }
        if ($before['size'] > $maximumBytes) {
            throw new HyperliquidHistoricalIntegrityException($oversizedError);
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new HyperliquidHistoricalIntegrityException($unreadableError);
        }
        try {
            $opened = $this->filesystem->stat($handle, 'hyperliquid_acquisition_file_load');
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameFile($before, $opened)
                || !isset($opened['size'])
                || !\is_int($opened['size'])
                || $opened['size'] !== $before['size']
            ) {
                throw new HyperliquidHistoricalIntegrityException($unreadableError);
            }
            $contents = '';
            while (strlen($contents) < $opened['size']) {
                $chunk = $this->filesystem->read(
                    $handle,
                    min(8192, $opened['size'] - strlen($contents)),
                    'hyperliquid_acquisition_file_load',
                );
                if ($chunk === false || $chunk === '') {
                    throw new HyperliquidHistoricalIntegrityException($unreadableError);
                }
                $contents .= $chunk;
            }
            $extra = $this->filesystem->read($handle, 1, 'hyperliquid_acquisition_file_load');
            $afterHandle = $this->filesystem->stat($handle, 'hyperliquid_acquisition_file_load');
            $afterPath = $this->pathStatistics($path, 'hyperliquid_acquisition_file_load');
            $this->assertPinnedDirectory($parentPin);
            $this->assertManagedDirectories();
            if ($extra === false
                || $extra !== ''
                || !$this->sameSnapshot($opened, $afterHandle)
                || !$this->sameSnapshot($opened, $afterPath)
            ) {
                throw new HyperliquidHistoricalIntegrityException($unreadableError);
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $parentPin
     */
    private function atomicWrite(
        string $path,
        #[\SensitiveParameter] string $contents,
        array $parentPin,
    ): void {
        $this->assertManagedDirectories();
        $this->assertPinnedDirectory($parentPin);
        $this->assertDestinationIsSafe($path);
        try {
            $temporaryPath = $parentPin['path']
                . '/.hyperliquid-acquisition-' . bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('hyperliquid_acquisition_write_failed', 0, $exception);
        }
        $handle = $this->filesystem->createPrivateFile(
            $temporaryPath,
            'hyperliquid_acquisition_create',
        );
        if ($handle === false) {
            throw new \RuntimeException('hyperliquid_acquisition_write_failed');
        }

        $renamed = false;
        try {
            $this->writeAll($handle, $contents);
            if (!$this->filesystem->flush($handle, 'hyperliquid_acquisition_flush')
                || !$this->filesystem->sync($handle, 'hyperliquid_acquisition_sync')
            ) {
                throw new \RuntimeException('hyperliquid_acquisition_write_failed');
            }
            $temporaryIdentity = $this->assertHandleMatchesPath($handle, $temporaryPath);
            $this->assertManagedDirectories();
            $this->assertPinnedDirectory($parentPin);
            $this->assertDestinationIsSafe($path);
            $this->assertHandleMatchesPath($handle, $temporaryPath, $temporaryIdentity);
            if (!$this->filesystem->move(
                $temporaryPath,
                $path,
                'hyperliquid_acquisition_publish',
            )) {
                throw new \RuntimeException('hyperliquid_acquisition_write_failed');
            }
            $renamed = true;
            $this->assertManagedDirectories();
            $this->assertPinnedDirectory($parentPin);
            $this->assertHandleMatchesPath($handle, $path, $temporaryIdentity);
            if (!$this->filesystem->sync(
                $parentPin['handle'],
                'hyperliquid_acquisition_directory_sync',
            )) {
                throw new \RuntimeException('hyperliquid_acquisition_write_failed');
            }
            $this->assertManagedDirectories();
            $this->assertPinnedDirectory($parentPin);
            $this->assertHandleMatchesPath($handle, $path, $temporaryIdentity);
        } catch (HyperliquidHistoricalIntegrityException $failure) {
            if (!$renamed) {
                $this->removeTemporaryPath($temporaryPath, $handle);
            }

            throw $failure;
        } catch (\Throwable $failure) {
            if (!$renamed) {
                $this->removeTemporaryPath($temporaryPath, $handle);
            }

            throw new \RuntimeException('hyperliquid_acquisition_write_failed', 0, $failure);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, #[\SensitiveParameter] string $contents): void
    {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = $this->filesystem->write(
                $handle,
                substr($contents, $offset),
                'hyperliquid_acquisition_write',
            );
            if ($written === false || $written <= 0) {
                throw new \RuntimeException('hyperliquid_acquisition_write_failed');
            }
            $offset += $written;
        }
    }

    private function assertDestinationIsSafe(string $path): void
    {
        $statistics = $this->pathStatistics(
            $path,
            'hyperliquid_acquisition_destination_validation',
        );
        if ($statistics !== false
            && ($this->isSymlink($statistics) || !$this->isPrivateRegularFile($statistics))
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_file_invalid',
            );
        }
    }

    /**
     * @param resource                       $handle
     * @param array{dev: int, ino: int}|null $expected
     *
     * @return array{dev: int, ino: int}
     */
    private function assertHandleMatchesPath(
        $handle,
        string $path,
        ?array $expected = null,
        string $error = 'hyperliquid_acquisition_file_invalid',
        string $operation = 'hyperliquid_acquisition_file_validation',
    ): array {
        $opened = $this->filesystem->stat($handle, $operation);
        $current = $this->pathStatistics($path, $operation);
        if ($current !== false && $this->isSymlink($current)) {
            throw new HyperliquidHistoricalIntegrityException($error);
        }
        if ($opened === false
            || $current === false
            || !$this->isPrivateRegularFile($opened)
            || !$this->isPrivateRegularFile($current)
            || !$this->sameFile($opened, $current)
            || ($expected !== null && !$this->sameFile($expected, $opened))
            || !isset($opened['dev'], $opened['ino'])
            || !\is_int($opened['dev'])
            || !\is_int($opened['ino'])
        ) {
            throw new HyperliquidHistoricalIntegrityException($error);
        }

        return ['dev' => $opened['dev'], 'ino' => $opened['ino']];
    }

    /** @param resource $handle */
    private function removeTemporaryPath(string $path, $handle): void
    {
        $statistics = $this->filesystem->stat($handle, 'hyperliquid_acquisition_cleanup');
        if ($statistics === false || !$this->isPrivateRegularFile($statistics)) {
            return;
        }
        $this->filesystem->removeFile(
            $path,
            $statistics,
            'hyperliquid_acquisition_cleanup',
        );
    }

    /** @return array<string, mixed>|false */
    private function pathStatistics(string $path, string $operation): array|false
    {
        $statistics = $this->filesystem->pathStat($path, $operation);
        if ($statistics === false && (file_exists($path) || is_link($path))) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_file_invalid',
            );
        }

        return $statistics;
    }

    /** @param array<string, mixed> $statistics */
    private function isSymlink(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE;
    }

    /** @param array<string, mixed> $statistics */
    private function isDirectory(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_FILE_TYPE;
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateDirectory(array $statistics): bool
    {
        return $this->isDirectory($statistics) && ($statistics['mode'] & 0777) === 0700;
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateRegularFile(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::REGULAR_FILE_TYPE
            && ($statistics['mode'] & 0777) === 0600
            && isset($statistics['nlink'])
            && \is_int($statistics['nlink'])
            && $statistics['nlink'] === 1;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && \is_int($left['dev'])
            && \is_int($left['ino'])
            && \is_int($right['dev'])
            && \is_int($right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }

    /**
     * @param array<string, mixed>       $expected
     * @param array<string, mixed>|false $actual
     */
    private function sameSnapshot(array $expected, array|false $actual): bool
    {
        return $actual !== false
            && $this->isPrivateRegularFile($actual)
            && $this->sameFile($expected, $actual)
            && isset($expected['size'], $actual['size'])
            && \is_int($expected['size'])
            && \is_int($actual['size'])
            && $expected['size'] === $actual['size'];
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_directory_invalid',
                );
            }
            $path = $workingDirectory . DIRECTORY_SEPARATOR . $path;
        }
        $current = DIRECTORY_SEPARATOR;
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                $current = dirname($current);
                continue;
            }
            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $component;
            $statistics = $this->filesystem->pathStat(
                $current,
                'hyperliquid_acquisition_path_validation',
            );
            if ($statistics !== false && $this->isSymlink($statistics)) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_file_invalid',
                );
            }
        }
    }

    private function closeInitializedResources(): void
    {
        if (isset($this->writerLock) && \is_resource($this->writerLock['handle'])) {
            @flock($this->writerLock['handle'], \LOCK_UN);
            fclose($this->writerLock['handle']);
        }
        foreach (
            ['pagesPin', 'directoryPin', 'acquisitionPin', 'checkpointsPin', 'datasetPin']
            as $property
        ) {
            if (isset($this->{$property}) && \is_resource($this->{$property}['handle'])) {
                fclose($this->{$property}['handle']);
            }
        }
    }
}
