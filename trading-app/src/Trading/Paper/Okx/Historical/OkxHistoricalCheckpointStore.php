<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Historical;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;
use Brick\Math\BigInteger;

final class OkxHistoricalCheckpointStore
{
    private const SCHEMA_VERSION = 1;
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const SYMLINK_FILE_TYPE = 0120000;
    private const FILE_TYPE_MASK = 0170000;
    private const PAGE_FILENAME_PATTERN = '/\A[A-Z0-9_-]+-(?:candle_(?:1m|5m|15m|1H)|public_trade)-[0-9]{6}\.ndjson\z/D';
    private const SHA256_PATTERN = '/\A[a-f0-9]{64}\z/D';
    private const UNSIGNED_INTEGER_PATTERN = '/\A(?:0|[1-9][0-9]*)\z/D';
    private const WRITER_LOCK_FILENAME = '.writer.lock';

    private readonly PaperDatasetRecorderFilesystem $filesystem;
    private readonly string $directory;
    private readonly string $pagesDirectory;
    private readonly string $checkpointPath;
    private readonly string $emissionPath;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $datasetPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $checkpointsPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $directoryPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $pagesPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string} */
    private array $writerLock;

    public function __construct(
        #[\SensitiveParameter] string $datasetDirectory,
        private readonly OkxHistoricalRequest $request,
        ?PaperDatasetRecorderFilesystem $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new PaperDatasetRecorderFilesystem();
        $this->assertNoSymlinkComponents($datasetDirectory);
        $resolvedDataset = realpath($datasetDirectory);
        if ($resolvedDataset === false) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_directory_invalid');
        }

        $this->datasetPin = $this->openPinnedDirectory($resolvedDataset, requirePrivate: false);
        try {
            $this->checkpointsPin = $this->ensureManagedDirectory($this->datasetPin, 'checkpoints');
            $this->directoryPin = $this->ensureManagedDirectory($this->checkpointsPin, 'okx-acquisition');
            $this->pagesPin = $this->ensureManagedDirectory($this->directoryPin, 'pages');
            $this->directory = $this->directoryPin['path'];
            $this->pagesDirectory = $this->pagesPin['path'];
            $this->checkpointPath = $this->directory . '/checkpoint.json';
            $this->emissionPath = $this->directory . '/emission.json';
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
        foreach (['pagesPin', 'directoryPin', 'checkpointsPin', 'datasetPin'] as $property) {
            if (isset($this->{$property}) && \is_resource($this->{$property}['handle'])) {
                fclose($this->{$property}['handle']);
            }
        }
    }

    /** @return array<string, mixed> */
    public function loadOrCreate(): array
    {
        $this->assertManagedDirectories();
        $checkpointStatistics = $this->pathStatistics($this->checkpointPath, 'okx_acquisition_checkpoint_load');
        $emissionStatistics = $this->pathStatistics($this->emissionPath, 'okx_acquisition_checkpoint_load');
        if ($checkpointStatistics === false) {
            if ($emissionStatistics !== false) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            $acquisition = [
                'schema_version' => self::SCHEMA_VERSION,
                'dataset_id' => $this->request->datasetId,
                'request_sha256' => $this->request->requestSha256(),
                'streams' => [],
                'page_count' => 0,
                'event_count' => 0,
            ];
            $emission = [
                'schema_version' => self::SCHEMA_VERSION,
                'dataset_id' => $this->request->datasetId,
                'request_sha256' => $this->request->requestSha256(),
                'phase' => 'fetching',
                'emit_index' => 0,
                'ordinal_state' => ['schema_version' => 1, 'scopes' => []],
                'pending_event' => null,
            ];
            $this->saveAcquisition($acquisition);
            $this->saveEmission($emission);

            return array_merge($acquisition, $emission);
        }
        if ($emissionStatistics === false) {
            $state = $this->readState($this->checkpointPath, $this->directoryPin);
            $this->validateAcquisitionState($state);
            if ($state['streams'] !== [] || $state['page_count'] !== 0 || $state['event_count'] !== 0) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            $emission = [
                'schema_version' => self::SCHEMA_VERSION,
                'dataset_id' => $this->request->datasetId,
                'request_sha256' => $this->request->requestSha256(),
                'phase' => 'fetching',
                'emit_index' => 0,
                'ordinal_state' => ['schema_version' => 1, 'scopes' => []],
                'pending_event' => null,
            ];
            $this->saveEmission($emission);

            return array_merge($state, $emission);
        }

        $state = $this->readState($this->checkpointPath, $this->directoryPin);
        $this->validateAcquisitionState($state);
        $emission = $this->readState($this->emissionPath, $this->directoryPin);
        $this->validateEmissionState($emission, $state);
        $this->assertManagedDirectories();

        return array_merge($state, $emission);
    }

    /** @param array<string, mixed> $state */
    public function saveAcquisition(#[\SensitiveParameter] array $state): void
    {
        $this->atomicWrite($this->checkpointPath, CanonicalJson::encode([
            'schema_version' => self::SCHEMA_VERSION,
            'dataset_id' => $state['dataset_id'] ?? null,
            'request_sha256' => $state['request_sha256'] ?? null,
            'streams' => $state['streams'] ?? null,
            'page_count' => $state['page_count'] ?? null,
            'event_count' => $state['event_count'] ?? null,
        ]) . "\n", $this->directoryPin);
    }

    /** @param array<string, mixed> $state */
    public function saveEmission(#[\SensitiveParameter] array $state): void
    {
        $emission = [
            'schema_version' => self::SCHEMA_VERSION,
            'dataset_id' => $this->request->datasetId,
            'request_sha256' => $this->request->requestSha256(),
            'phase' => $state['phase'] ?? null,
            'emit_index' => $state['emit_index'] ?? null,
            'ordinal_state' => $state['ordinal_state'] ?? null,
            'pending_event' => $state['pending_event'] ?? null,
        ];
        if (\is_string($state['failure_reason'] ?? null)) {
            $emission['failure_reason'] = $state['failure_reason'];
        }
        $this->atomicWrite($this->emissionPath, CanonicalJson::encode($emission) . "\n", $this->directoryPin);
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array{file: string, sha256: string, row_count: int}
     */
    public function writePage(string $filename, array $records): array
    {
        if (preg_match(self::PAGE_FILENAME_PATTERN, $filename) !== 1) {
            throw new \InvalidArgumentException('okx_acquisition_page_name_invalid');
        }
        $contents = '';
        foreach ($records as $record) {
            $contents .= CanonicalJson::encode($record) . "\n";
        }
        $this->atomicWrite($this->pagesDirectory . '/' . $filename, $contents, $this->pagesPin);

        return [
            'file' => $filename,
            'sha256' => hash('sha256', $contents),
            'row_count' => \count($records),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function readPage(string $filename): array
    {
        $this->assertPageFilename($filename);
        $contents = $this->readSnapshot(
            $this->pagesDirectory . '/' . $filename,
            $this->pagesPin,
            allowEmpty: true,
            unreadableError: 'okx_acquisition_page_unreadable',
        );
        if ($contents === '') {
            return [];
        }

        return $this->decodePageContents($contents);
    }

    /** @return list<array<string, mixed>> */
    private function decodePageContents(string $contents): array
    {
        $records = [];
        try {
            foreach (explode("\n", rtrim($contents, "\n")) as $line) {
                $record = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($record) || array_is_list($record)) {
                    throw new \JsonException();
                }
                $records[] = $record;
            }
        } catch (\JsonException $exception) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_page_invalid', 0, $exception);
        }

        return $records;
    }

    /** @param array<string, mixed> $state */
    public function verifyPages(array $state): void
    {
        $this->assertManagedDirectories();
        $acquisition = [
            'schema_version' => $state['schema_version'] ?? null,
            'dataset_id' => $state['dataset_id'] ?? null,
            'request_sha256' => $state['request_sha256'] ?? null,
            'streams' => $state['streams'] ?? null,
            'page_count' => $state['page_count'] ?? null,
            'event_count' => $state['event_count'] ?? null,
        ];
        $this->validateAcquisitionState($acquisition);
        $eventCount = 0;
        foreach ($acquisition['streams'] as $stream) {
            $previousChain = str_repeat('0', 64);
            foreach ($stream['pages'] as $page) {
                $this->assertPageFilename($page['file']);
                $contents = $this->readSnapshot(
                    $this->pagesDirectory . '/' . $page['file'],
                    $this->pagesPin,
                    allowEmpty: true,
                    unreadableError: 'okx_acquisition_page_unreadable',
                );
                if (!hash_equals($page['sha256'], hash('sha256', $contents))) {
                    throw new OkxHistoricalIntegrityException('okx_acquisition_page_hash_mismatch');
                }
                $chain = hash('sha256', $previousChain . $page['sha256']);
                if (!hash_equals($page['chain_sha256'], $chain)) {
                    throw new OkxHistoricalIntegrityException('okx_acquisition_page_chain_mismatch');
                }
                $previousChain = $chain;
                $records = $contents === '' ? [] : $this->decodePageContents($contents);
                if (\count($records) !== $page['row_count']) {
                    throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
                }
                foreach ($records as $record) {
                    $timestamp = $record['exchange_timestamp_ms'] ?? null;
                    if (!\is_string($timestamp)
                        || preg_match(self::UNSIGNED_INTEGER_PATTERN, $timestamp) !== 1
                    ) {
                        throw new OkxHistoricalIntegrityException('okx_acquisition_page_invalid');
                    }
                    if ($this->insideRequestRange($timestamp)) {
                        ++$eventCount;
                    }
                }
            }
        }
        if ($eventCount !== $acquisition['event_count']) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
    }

    /** @param array<string, mixed> $state */
    private function validateAcquisitionState(array $state): void
    {
        $this->assertExactKeys($state, [
            'schema_version',
            'dataset_id',
            'request_sha256',
            'streams',
            'page_count',
            'event_count',
        ]);
        if ($state['schema_version'] !== self::SCHEMA_VERSION
            || !\is_string($state['dataset_id'])
            || !\is_string($state['request_sha256'])
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        if (!hash_equals($this->request->datasetId, $state['dataset_id'])) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        if (!hash_equals($this->request->requestSha256(), $state['request_sha256'])) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_request_mismatch');
        }
        if (!\is_array($state['streams'])
            || (array_is_list($state['streams']) && $state['streams'] !== [])
            || !\is_int($state['page_count'])
            || $state['page_count'] < 0
            || $state['page_count'] > $this->request->maximumPages
            || !\is_int($state['event_count'])
            || $state['event_count'] < 0
            || $state['event_count'] > $this->request->maximumEvents
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }

        $pageCount = 0;
        $rowCount = 0;
        foreach ($state['streams'] as $key => $stream) {
            if (!\is_string($key) || !\is_array($stream) || array_is_list($stream)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            $this->validateStream($key, $stream);
            foreach ($stream['pages'] as $page) {
                ++$pageCount;
                $rowCount += $page['row_count'];
            }
        }
        if ($pageCount !== $state['page_count'] || $state['event_count'] > $rowCount) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
    }

    /** @param array<string, mixed> $stream */
    private function validateStream(string $key, array $stream): void
    {
        $kind = $stream['kind'] ?? null;
        $required = ['kind', 'symbol', 'next_cursor', 'complete', 'pages'];
        $allowed = [...$required, 'last_response_sha256', 'durable_frontier'];
        if ($kind === 'candle') {
            $required[] = 'bar';
            $allowed[] = 'bar';
        } elseif ($kind === 'trade') {
            $required[] = 'pagination_type';
            $required[] = 'oldest_timestamp';
            $allowed[] = 'pagination_type';
            $allowed[] = 'oldest_timestamp';
        } else {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $this->assertRequiredAndAllowedKeys($stream, $required, $allowed);

        $symbol = $stream['symbol'];
        if (!\is_string($symbol)
            || !\in_array($symbol, $this->request->symbols, true)
            || !\is_string($stream['next_cursor'])
            || preg_match(self::UNSIGNED_INTEGER_PATTERN, $stream['next_cursor']) !== 1
            || !\is_bool($stream['complete'])
            || !\is_array($stream['pages'])
            || !array_is_list($stream['pages'])
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }

        if ($kind === 'candle') {
            $bar = $stream['bar'];
            if (!\is_string($bar)
                || !\in_array($bar, $this->request->bars, true)
                || $key !== $symbol . '/candle_' . $bar
            ) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
        } elseif ($key !== $symbol . '/public_trade'
            || !\is_int($stream['pagination_type'])
            || !\in_array($stream['pagination_type'], [1, 2], true)
            || ($stream['oldest_timestamp'] !== null
                && (!\is_string($stream['oldest_timestamp'])
                    || preg_match(self::UNSIGNED_INTEGER_PATTERN, $stream['oldest_timestamp']) !== 1))
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }

        if (isset($stream['last_response_sha256'])
            && (!\is_string($stream['last_response_sha256'])
                || preg_match(self::SHA256_PATTERN, $stream['last_response_sha256']) !== 1)
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        if (array_key_exists('durable_frontier', $stream)) {
            $this->validateFrontier($stream['durable_frontier']);
        }

        $previousChain = str_repeat('0', 64);
        foreach ($stream['pages'] as $index => $page) {
            if (!\is_array($page) || array_is_list($page)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            $this->assertExactKeys($page, ['file', 'sha256', 'row_count', 'chain_sha256']);
            $expectedFile = str_replace('/', '-', $key)
                . '-' . str_pad((string) ($index + 1), 6, '0', \STR_PAD_LEFT) . '.ndjson';
            if (!\is_string($page['file'])
                || !hash_equals($expectedFile, $page['file'])
                || !\is_string($page['sha256'])
                || preg_match(self::SHA256_PATTERN, $page['sha256']) !== 1
                || !\is_string($page['chain_sha256'])
                || preg_match(self::SHA256_PATTERN, $page['chain_sha256']) !== 1
                || !\is_int($page['row_count'])
                || $page['row_count'] < 0
            ) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            $chain = hash('sha256', $previousChain . $page['sha256']);
            if (!hash_equals($chain, $page['chain_sha256'])) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_page_chain_mismatch');
            }
            $previousChain = $chain;
        }
    }

    private function validateFrontier(mixed $frontier): void
    {
        if (!\is_array($frontier) || array_is_list($frontier)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $this->assertExactKeys($frontier, ['source_identity', 'natural_identity', 'source_digest']);
        if (!\is_string($frontier['source_identity'])
            || preg_match(self::UNSIGNED_INTEGER_PATTERN, $frontier['source_identity']) !== 1
            || !\is_string($frontier['natural_identity'])
            || $frontier['natural_identity'] === ''
            || !\is_string($frontier['source_digest'])
            || preg_match(self::SHA256_PATTERN, $frontier['source_digest']) !== 1
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
    }

    /**
     * @param array<string, mixed> $emission
     * @param array<string, mixed> $acquisition
     */
    private function validateEmissionState(array $emission, array $acquisition): void
    {
        $phase = $emission['phase'] ?? null;
        $keys = [
            'schema_version',
            'dataset_id',
            'request_sha256',
            'phase',
            'emit_index',
            'ordinal_state',
            'pending_event',
        ];
        if ($phase === 'failed') {
            $keys[] = 'failure_reason';
        }
        $this->assertExactKeys($emission, $keys);
        if ($emission['schema_version'] !== self::SCHEMA_VERSION
            || !\is_string($emission['dataset_id'])
            || !\is_string($emission['request_sha256'])
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        if (!hash_equals($this->request->datasetId, $emission['dataset_id'])) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_request_mismatch');
        }
        if (!hash_equals($this->request->requestSha256(), $emission['request_sha256'])) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_request_mismatch');
        }
        if (!\is_string($phase)
            || !\in_array($phase, ['fetching', 'emitting', 'complete', 'failed'], true)
            || !\is_int($emission['emit_index'])
            || $emission['emit_index'] < 0
            || $emission['emit_index'] > $acquisition['event_count']
            || !\is_array($emission['ordinal_state'])
            || array_is_list($emission['ordinal_state'])
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        try {
            OkxPaperSourceOrdinal::restore($emission['ordinal_state']);
        } catch (\InvalidArgumentException $exception) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid', 0, $exception);
        }

        $pending = $emission['pending_event'];
        if ($pending !== null) {
            $this->validatePendingEvent($pending);
        }
        if (($phase === 'fetching' && ($emission['emit_index'] !== 0 || $pending !== null))
            || ($phase === 'complete'
                && ($emission['emit_index'] !== $acquisition['event_count'] || $pending !== null))
            || ($phase === 'failed' && $pending !== null)
            || ($pending !== null && ($phase !== 'emitting'
                || $emission['emit_index'] >= $acquisition['event_count']))
            || ($phase === 'failed'
                && (!\is_string($emission['failure_reason']) || $emission['failure_reason'] === ''))
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        if (\in_array($phase, ['emitting', 'complete'], true)) {
            $this->assertAcquisitionComplete($acquisition['streams']);
        }
    }

    private function validatePendingEvent(mixed $pending): void
    {
        if (!\is_array($pending) || array_is_list($pending)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $this->assertExactKeys($pending, ['natural_identity', 'event']);
        if (!\is_string($pending['natural_identity'])
            || $pending['natural_identity'] === ''
            || !\is_array($pending['event'])
            || array_is_list($pending['event'])
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        try {
            PaperMarketEvent::fromArray($pending['event']);
        } catch (\Throwable $exception) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid', 0, $exception);
        }
    }

    /** @param array<string, mixed> $streams */
    private function assertAcquisitionComplete(array $streams): void
    {
        $expected = [];
        foreach ($this->request->symbols as $symbol) {
            foreach ($this->request->bars as $bar) {
                $expected[] = $symbol . '/candle_' . $bar;
            }
            $expected[] = $symbol . '/public_trade';
        }
        sort($expected, \SORT_STRING);
        $actual = array_keys($streams);
        sort($actual, \SORT_STRING);
        if ($actual !== $expected) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        foreach ($streams as $stream) {
            if ($stream['complete'] !== true) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
        }
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
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string>         $required
     * @param list<string>         $allowed
     */
    private function assertRequiredAndAllowedKeys(array $state, array $required, array $allowed): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $state)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
        }
        foreach (array_keys($state) as $key) {
            if (!\is_string($key) || !\in_array($key, $allowed, true)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
        }
    }

    private function insideRequestRange(string $timestampMilliseconds): bool
    {
        $value = BigInteger::of($timestampMilliseconds)->multipliedBy(1_000);
        $from = BigInteger::of($this->request->from->format('U'))
            ->multipliedBy(1_000_000)
            ->plus($this->request->from->format('u'));
        $to = BigInteger::of($this->request->to->format('U'))
            ->multipliedBy(1_000_000)
            ->plus($this->request->to->format('u'));

        return $value->isGreaterThanOrEqualTo($from) && $value->isLessThan($to);
    }

    private function assertPageFilename(string $filename): void
    {
        if (preg_match(self::PAGE_FILENAME_PATTERN, $filename) !== 1) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_page_name_invalid');
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
        $statistics = $this->pathStatistics($path, 'okx_acquisition_directory_validation');
        $created = false;
        if ($statistics === false) {
            $this->assertPinnedDirectory($parentPin);
            if (!$this->filesystem->createDirectory($path, 0700)) {
                $statistics = $this->pathStatistics($path, 'okx_acquisition_directory_validation');
                if ($statistics === false) {
                    throw new OkxHistoricalIntegrityException('okx_acquisition_directory_create_failed');
                }
            } else {
                $created = true;
                $statistics = $this->pathStatistics($path, 'okx_acquisition_directory_validation');
            }
        }
        if ($statistics === false || $this->isSymlink($statistics) || !$this->isPrivateDirectory($statistics)) {
            throw new OkxHistoricalIntegrityException(
                $statistics !== false && $this->isSymlink($statistics)
                    ? 'okx_acquisition_file_invalid'
                    : 'okx_acquisition_directory_invalid',
            );
        }
        $pin = $this->openPinnedDirectory($path, requirePrivate: true, expected: $statistics);
        try {
            $this->assertPinnedDirectory($parentPin);
            $this->assertPinnedDirectory($pin);
            if ($created && !$this->filesystem->sync($parentPin['handle'], 'okx_acquisition_directory_parent_sync')) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_directory_invalid');
            }
            $this->assertPinnedDirectory($parentPin);
            $this->assertPinnedDirectory($pin);

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
    private function openPinnedDirectory(string $path, bool $requirePrivate, ?array $expected = null): array
    {
        $before = $this->pathStatistics($path, 'okx_acquisition_directory_validation');
        if ($before === false || $this->isSymlink($before) || !$this->isDirectory($before)) {
            throw new OkxHistoricalIntegrityException(
                $before !== false && $this->isSymlink($before)
                    ? 'okx_acquisition_file_invalid'
                    : 'okx_acquisition_directory_invalid',
            );
        }
        if ($requirePrivate && !$this->isPrivateDirectory($before)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_directory_invalid');
        }
        if ($expected !== null && !$this->sameFile($expected, $before)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_directory_invalid');
        }
        $handle = $this->filesystem->openDirectory($path, 'okx_acquisition_directory_open');
        if ($handle === false) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_directory_invalid');
        }
        try {
            $opened = $this->filesystem->stat($handle, 'okx_acquisition_directory_validation');
            if ($opened === false
                || !$this->isDirectory($opened)
                || ($requirePrivate && !$this->isPrivateDirectory($opened))
                || !$this->sameFile($before, $opened)
                || !isset($opened['dev'], $opened['ino'])
                || !\is_int($opened['dev'])
                || !\is_int($opened['ino'])
            ) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_directory_invalid');
            }
            $pin = [
                'handle' => $handle,
                'identity' => ['dev' => $opened['dev'], 'ino' => $opened['ino']],
                'path' => $path,
                'private' => $requirePrivate,
            ];
            $this->assertPinnedDirectory($pin);

            return $pin;
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $pin */
    private function assertPinnedDirectory(array $pin): void
    {
        $opened = $this->filesystem->stat($pin['handle'], 'okx_acquisition_directory_validation');
        $current = $this->pathStatistics($pin['path'], 'okx_acquisition_directory_validation');
        if ($current !== false && $this->isSymlink($current)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_file_invalid');
        }
        if ($opened === false
            || $current === false
            || !$this->isDirectory($opened)
            || !$this->isDirectory($current)
            || ($pin['private'] && (!$this->isPrivateDirectory($opened) || !$this->isPrivateDirectory($current)))
            || !$this->sameFile($pin['identity'], $opened)
            || !$this->sameFile($pin['identity'], $current)
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_directory_invalid');
        }
    }

    private function assertManagedDirectories(): void
    {
        $this->assertPinnedDirectory($this->datasetPin);
        $this->assertPinnedDirectory($this->checkpointsPin);
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
        $statistics = $this->pathStatistics($path, 'okx_acquisition_lock_validation');
        if ($statistics !== false && !$this->isPrivateRegularFile($statistics)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_lock_invalid');
        }

        $created = $statistics === false;
        $handle = $created
            ? $this->filesystem->createPrivateFile($path, 'okx_acquisition_lock_create')
            : @fopen($path, 'r+b');
        if ($handle === false && $created) {
            $statistics = $this->pathStatistics($path, 'okx_acquisition_lock_validation');
            if ($statistics !== false && $this->isPrivateRegularFile($statistics)) {
                $created = false;
                $handle = @fopen($path, 'r+b');
            }
        }
        if ($handle === false) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_lock_invalid');
        }

        $locked = false;
        try {
            $identity = $this->assertHandleMatchesPath(
                $handle,
                $path,
                error: 'okx_acquisition_lock_invalid',
                operation: 'okx_acquisition_lock_validation',
            );
            $this->assertPinnedDirectory($this->directoryPin);
            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                $this->assertHandleMatchesPath(
                    $handle,
                    $path,
                    $identity,
                    'okx_acquisition_lock_invalid',
                    'okx_acquisition_lock_validation',
                );
                throw new OkxHistoricalIntegrityException('okx_acquisition_lock_unavailable');
            }
            $locked = true;
            $lock = ['handle' => $handle, 'identity' => $identity, 'path' => $path];
            $this->assertPinnedDirectory($this->directoryPin);
            $this->assertWriterLockPin($lock);
            if ($created
                && !$this->filesystem->sync($this->directoryPin['handle'], 'okx_acquisition_lock_directory_sync')
            ) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_lock_invalid');
            }
            $this->assertPinnedDirectory($this->directoryPin);
            $this->assertWriterLockPin($lock);

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
        $this->assertPinnedDirectory($this->directoryPin);
        $this->assertWriterLockPin($this->writerLock);
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $lock */
    private function assertWriterLockPin(array $lock): void
    {
        $this->assertHandleMatchesPath(
            $lock['handle'],
            $lock['path'],
            $lock['identity'],
            'okx_acquisition_lock_invalid',
            'okx_acquisition_lock_validation',
        );
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $parentPin
     *
     * @return array<string, mixed>
     */
    private function readState(string $path, array $parentPin): array
    {
        $contents = $this->readSnapshot(
            $path,
            $parentPin,
            allowEmpty: false,
            unreadableError: 'okx_acquisition_checkpoint_unreadable',
        );
        try {
            $state = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid', 0, $exception);
        }
        if (!\is_array($state) || array_is_list($state)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }

        return $state;
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $parentPin
     */
    private function readSnapshot(
        string $path,
        array $parentPin,
        bool $allowEmpty,
        string $unreadableError,
    ): string {
        $this->assertManagedDirectories();
        $this->assertPinnedDirectory($parentPin);
        $before = $this->pathStatistics($path, 'okx_acquisition_file_load');
        if ($before === false || $this->isSymlink($before) || !$this->isPrivateRegularFile($before)) {
            throw new OkxHistoricalIntegrityException(
                $before !== false && $this->isSymlink($before)
                    ? 'okx_acquisition_file_invalid'
                    : $unreadableError,
            );
        }
        if (!isset($before['size']) || !\is_int($before['size']) || (!$allowEmpty && $before['size'] === 0)) {
            throw new OkxHistoricalIntegrityException($unreadableError);
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new OkxHistoricalIntegrityException($unreadableError);
        }
        try {
            $opened = $this->filesystem->stat($handle, 'okx_acquisition_file_load');
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameFile($before, $opened)
                || !isset($opened['size'])
                || !\is_int($opened['size'])
                || $opened['size'] !== $before['size']
            ) {
                throw new OkxHistoricalIntegrityException($unreadableError);
            }
            $contents = '';
            while (strlen($contents) < $opened['size']) {
                $chunk = $this->filesystem->read(
                    $handle,
                    min(8192, $opened['size'] - strlen($contents)),
                    'okx_acquisition_file_load',
                );
                if ($chunk === false || $chunk === '') {
                    throw new OkxHistoricalIntegrityException($unreadableError);
                }
                $contents .= $chunk;
            }
            $extra = $this->filesystem->read($handle, 1, 'okx_acquisition_file_load');
            $afterHandle = $this->filesystem->stat($handle, 'okx_acquisition_file_load');
            $afterPath = $this->pathStatistics($path, 'okx_acquisition_file_load');
            $this->assertPinnedDirectory($parentPin);
            $this->assertManagedDirectories();
            if ($extra === false
                || $extra !== ''
                || !$this->sameSnapshot($opened, $afterHandle)
                || !$this->sameSnapshot($opened, $afterPath)
            ) {
                throw new OkxHistoricalIntegrityException($unreadableError);
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $parentPin
     */
    private function atomicWrite(string $path, #[\SensitiveParameter] string $contents, array $parentPin): void
    {
        $this->assertManagedDirectories();
        $this->assertPinnedDirectory($parentPin);
        $this->assertDestinationIsSafe($path);
        try {
            $temporaryPath = $parentPin['path'] . '/.okx-acquisition-' . bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('okx_acquisition_write_failed', 0, $exception);
        }
        $handle = $this->filesystem->createPrivateFile($temporaryPath, 'okx_acquisition_create');
        if ($handle === false) {
            throw new \RuntimeException('okx_acquisition_write_failed');
        }

        $renamed = false;
        try {
            $this->writeAll($handle, $contents);
            if (!$this->filesystem->flush($handle, 'okx_acquisition_flush')
                || !$this->filesystem->sync($handle, 'okx_acquisition_sync')
            ) {
                throw new \RuntimeException('okx_acquisition_write_failed');
            }
            $temporaryIdentity = $this->assertHandleMatchesPath($handle, $temporaryPath);
            $this->assertManagedDirectories();
            $this->assertPinnedDirectory($parentPin);
            $this->assertDestinationIsSafe($path);
            $this->assertHandleMatchesPath($handle, $temporaryPath, $temporaryIdentity);
            if (!$this->filesystem->move($temporaryPath, $path, 'okx_acquisition_publish')) {
                throw new \RuntimeException('okx_acquisition_write_failed');
            }
            $renamed = true;
            $this->assertManagedDirectories();
            $this->assertPinnedDirectory($parentPin);
            $this->assertHandleMatchesPath($handle, $path, $temporaryIdentity);
            if (!$this->filesystem->sync($parentPin['handle'], 'okx_acquisition_directory_sync')) {
                throw new \RuntimeException('okx_acquisition_write_failed');
            }
            $this->assertManagedDirectories();
            $this->assertPinnedDirectory($parentPin);
            $this->assertHandleMatchesPath($handle, $path, $temporaryIdentity);
        } catch (OkxHistoricalIntegrityException $failure) {
            if (!$renamed) {
                $this->removeTemporaryPath($temporaryPath);
            }

            throw $failure;
        } catch (\Throwable $failure) {
            if (!$renamed) {
                $this->removeTemporaryPath($temporaryPath);
            }

            throw new \RuntimeException('okx_acquisition_write_failed', 0, $failure);
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
                'okx_acquisition_write',
            );
            if ($written === false || $written <= 0) {
                throw new \RuntimeException('okx_acquisition_write_failed');
            }
            $offset += $written;
        }
    }

    private function assertDestinationIsSafe(string $path): void
    {
        $statistics = $this->pathStatistics($path, 'okx_acquisition_destination_validation');
        if ($statistics !== false && $this->isSymlink($statistics)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_file_invalid');
        }
        if ($statistics !== false && !$this->isPrivateRegularFile($statistics)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_file_invalid');
        }
    }

    /**
     * @param resource                          $handle
     * @param array{dev: int, ino: int}|null $expected
     *
     * @return array{dev: int, ino: int}
     */
    private function assertHandleMatchesPath(
        $handle,
        string $path,
        ?array $expected = null,
        string $error = 'okx_acquisition_file_invalid',
        string $operation = 'okx_acquisition_file_validation',
    ): array {
        $opened = $this->filesystem->stat($handle, $operation);
        $current = $this->pathStatistics($path, $operation);
        if ($current !== false && $this->isSymlink($current)) {
            throw new OkxHistoricalIntegrityException($error);
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
            throw new OkxHistoricalIntegrityException($error);
        }

        return ['dev' => $opened['dev'], 'ino' => $opened['ino']];
    }

    private function removeTemporaryPath(string $path): void
    {
        $statistics = $this->pathStatistics($path, 'okx_acquisition_cleanup');
        if ($statistics === false) {
            return;
        }
        if (isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && \in_array(
                $statistics['mode'] & self::FILE_TYPE_MASK,
                [self::REGULAR_FILE_TYPE, self::SYMLINK_FILE_TYPE],
                true,
            )
        ) {
            @unlink($path);
        }
    }

    /** @return array<string, mixed>|false */
    private function pathStatistics(string $path, string $operation): array|false
    {
        $statistics = $this->filesystem->pathStat($path, $operation);
        if ($statistics === false && (file_exists($path) || is_link($path))) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_file_invalid');
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
                throw new OkxHistoricalIntegrityException('okx_acquisition_directory_invalid');
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
            $statistics = $this->filesystem->pathStat($current, 'okx_acquisition_path_validation');
            if ($statistics !== false && $this->isSymlink($statistics)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_file_invalid');
            }
        }
    }

    private function closeInitializedResources(): void
    {
        if (isset($this->writerLock) && \is_resource($this->writerLock['handle'])) {
            @flock($this->writerLock['handle'], \LOCK_UN);
            fclose($this->writerLock['handle']);
        }
        foreach (['pagesPin', 'directoryPin', 'checkpointsPin', 'datasetPin'] as $property) {
            if (isset($this->{$property}) && \is_resource($this->{$property}['handle'])) {
                fclose($this->{$property}['handle']);
            }
        }
    }
}
