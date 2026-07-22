<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Historical;

use App\Trading\Paper\MarketData\AcknowledgedPaperMarketDataSourceInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use Brick\Math\BigInteger;
use Symfony\Component\Clock\ClockInterface;

final class OkxHistoricalEventStream implements AcknowledgedPaperMarketDataSourceInterface
{
    /** @var array<string, mixed> */
    private array $checkpoint;
    private readonly OkxHistoricalCheckpointStore $store;
    private OkxPaperSourceOrdinal $ordinals;
    private readonly OkxPaperInstrumentMap $instruments;
    private bool $stopped = false;

    public function __construct(
        private readonly OkxPaperPublicRestClientInterface $restClient,
        private readonly ClockInterface $clock,
        private readonly OkxHistoricalRequest $request,
        #[\SensitiveParameter] string $datasetDirectory,
    ) {
        $this->store = new OkxHistoricalCheckpointStore($datasetDirectory, $request);
        $this->checkpoint = $this->store->loadOrCreate();
        $ordinalState = $this->checkpoint['ordinal_state'] ?? null;
        if (!\is_array($ordinalState)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        try {
            $this->ordinals = OkxPaperSourceOrdinal::restore($ordinalState);
        } catch (\InvalidArgumentException $exception) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid', 0, $exception);
        }
        $this->instruments = new OkxPaperInstrumentMap();
    }

    public function venue(): PaperMarketDataVenue
    {
        return PaperMarketDataVenue::OKX;
    }

    /** @return iterable<PaperMarketEvent> */
    public function events(): iterable
    {
        try {
            $this->store->verifyPages($this->checkpoint);
            yield from $this->produceEvents();
        } catch (OkxHistoricalIntegrityException $exception) {
            $this->checkpoint['phase'] = 'failed';
            $this->checkpoint['failure_reason'] = $exception->getMessage();
            $this->checkpoint['pending_event'] = null;
            $this->store->saveEmission($this->checkpoint);

            throw $exception;
        }
    }

    /** @return iterable<PaperMarketEvent> */
    private function produceEvents(): iterable
    {
        if (($this->checkpoint['phase'] ?? null) === 'fetching') {
            $this->request->assertTradeRangeAvailable($this->clock);
            $this->fetchAllStreams();
            $this->validateFetchedDataset();
            $this->checkpoint['phase'] = 'emitting';
            $this->store->saveEmission($this->checkpoint);
        }

        if (($this->checkpoint['phase'] ?? null) === 'complete') {
            return;
        }
        if (($this->checkpoint['phase'] ?? null) !== 'emitting') {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }

        $pending = $this->checkpoint['pending_event'] ?? null;
        if ($pending !== null) {
            if (!\is_array($pending) || !\is_array($pending['event'] ?? null)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            /** @var array<string, mixed> $eventState */
            $eventState = $pending['event'];
            yield PaperMarketEvent::fromArray($eventState);
            if ($this->checkpoint['pending_event'] !== null) {
                throw new \LogicException('okx_acquisition_pending_event_not_acknowledged');
            }
        }

        $skip = $this->checkpoint['emit_index'] ?? null;
        if (!\is_int($skip) || $skip < 0) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $index = 0;
        $normalizer = new OkxPaperMarketEventNormalizer($this->clock, ordinals: $this->ordinals);
        foreach ($this->mergedRecords() as $record) {
            if ($this->wasStopped()) {
                return;
            }
            if ($index++ < $skip) {
                continue;
            }
            $event = $this->normalize($normalizer, $record);
            $this->checkpoint['ordinal_state'] = $this->ordinals->snapshot();
            $this->checkpoint['pending_event'] = [
                'natural_identity' => $record['natural_identity'],
                'event' => $event->toArray(),
            ];
            $this->store->saveEmission($this->checkpoint);
            yield $event;
            if ($this->hasPendingEvent()) {
                throw new \LogicException('okx_acquisition_pending_event_not_acknowledged');
            }
            if ($this->wasStopped()) {
                return;
            }
        }

        $this->checkpoint['phase'] = 'complete';
        $this->store->saveEmission($this->checkpoint);
    }

    private function hasPendingEvent(): bool
    {
        return $this->checkpoint['pending_event'] !== null;
    }

    private function wasStopped(): bool
    {
        return $this->stopped;
    }

    public function acknowledge(string $eventId): void
    {
        $pending = $this->checkpoint['pending_event'] ?? null;
        if (!\is_array($pending) || !\is_array($pending['event'] ?? null)) {
            throw new \LogicException('okx_acquisition_acknowledgement_invalid');
        }
        $pendingId = $pending['event']['event_id'] ?? null;
        if (!\is_string($pendingId) || !hash_equals($pendingId, $eventId)) {
            throw new \LogicException('okx_acquisition_acknowledgement_invalid');
        }
        $emitIndex = $this->checkpoint['emit_index'] ?? null;
        if (!\is_int($emitIndex) || $emitIndex < 0) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $this->checkpoint['emit_index'] = $emitIndex + 1;
        $this->checkpoint['pending_event'] = null;
        $this->store->saveEmission($this->checkpoint);
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isComplete(): bool
    {
        return ($this->checkpoint['phase'] ?? null) === 'complete';
    }

    private function fetchAllStreams(): void
    {
        foreach ($this->request->symbols as $symbol) {
            foreach ($this->request->bars as $bar) {
                $this->fetchCandles($symbol, $bar);
            }
            $this->fetchTrades($symbol);
        }
    }

    private function fetchCandles(string $symbol, string $bar): void
    {
        $key = $symbol . '/candle_' . $bar;
        $stream = $this->stream($key, [
            'kind' => 'candle',
            'symbol' => $symbol,
            'bar' => $bar,
            'next_cursor' => $this->exclusiveUpperBoundMilliseconds($this->request->to),
            'complete' => false,
            'pages' => [],
        ]);
        if ($stream['complete'] === true) {
            return;
        }

        $native = $this->instruments->nativeInstrumentId($symbol);
        while (!$stream['complete']) {
            $this->assertPageAvailable();
            $cursor = $this->requiredUnsignedString($stream['next_cursor'] ?? null);
            $rows = $this->restClient->historyCandles($native, $bar, $cursor, 300);
            if (\count($rows) > 300) {
                throw new OkxHistoricalIntegrityException('okx_history_candle_response_limit_exceeded');
            }
            if ($rows === []) {
                $this->acceptEmptyCandlePage($key, $stream);
                return;
            }
            $responseSha256 = hash('sha256', CanonicalJson::encode($rows));
            if (($stream['last_response_sha256'] ?? null) === $responseSha256) {
                throw new OkxHistoricalIntegrityException('okx_history_repeated_page');
            }
            $records = [];
            $oldest = null;
            $previousTimestamp = null;
            foreach ($rows as $row) {
                if (!\is_array($row) || !array_is_list($row)) {
                    throw new OkxHistoricalIntegrityException('okx_history_candle_response_inconsistent');
                }
                $timestamp = $this->requiredUnsignedString($row[0] ?? null);
                if (BigInteger::of($timestamp)->isGreaterThanOrEqualTo(BigInteger::of($cursor))
                    || ($previousTimestamp !== null
                        && BigInteger::of($timestamp)->isGreaterThan(BigInteger::of($previousTimestamp)))
                ) {
                    throw new OkxHistoricalIntegrityException('okx_history_candle_response_inconsistent');
                }
                $previousTimestamp = $timestamp;
                $oldest = $oldest === null || BigInteger::of($timestamp)->isLessThan(BigInteger::of($oldest))
                    ? $timestamp
                    : $oldest;
                if (($row[8] ?? null) === '1') {
                    $records[] = $this->record('candle', $symbol, $native, $bar, $timestamp, $row);
                }
            }
            if (!BigInteger::of($oldest)->isLessThan(BigInteger::of($cursor))) {
                throw new OkxHistoricalIntegrityException('okx_history_candle_cursor_not_progressing');
            }
            $stream = $this->persistPage($key, $stream, $records);
            $stream['last_response_sha256'] = $responseSha256;
            $stream['next_cursor'] = $oldest;
            $stream['complete'] = $this->eventPrecedesFrom($oldest);
            $this->putStream($key, $stream);
        }
    }

    private function fetchTrades(string $symbol): void
    {
        $key = $symbol . '/public_trade';
        $stream = $this->stream($key, [
            'kind' => 'trade',
            'symbol' => $symbol,
            'pagination_type' => 2,
            'next_cursor' => $this->exclusiveUpperBoundMilliseconds($this->request->to),
            'oldest_timestamp' => null,
            'complete' => false,
            'pages' => [],
        ]);
        if ($stream['complete'] === true) {
            return;
        }

        $native = $this->instruments->nativeInstrumentId($symbol);
        while (!$stream['complete']) {
            $this->assertPageAvailable();
            $type = $stream['pagination_type'] ?? null;
            if (!\is_int($type) || !\in_array($type, [1, 2], true)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            $cursor = $this->requiredUnsignedString($stream['next_cursor'] ?? null);
            $rows = $this->restClient->historyTrades($native, $type, $cursor, 100);
            if (\count($rows) > 100) {
                throw new OkxHistoricalIntegrityException('okx_history_trade_response_limit_exceeded');
            }
            if ($rows === []) {
                throw new OkxHistoricalIntegrityException('okx_history_trade_range_incomplete');
            }
            $responseSha256 = hash('sha256', CanonicalJson::encode($rows));
            if (($stream['last_response_sha256'] ?? null) === $responseSha256) {
                throw new OkxHistoricalIntegrityException('okx_history_repeated_page');
            }
            $records = [];
            $oldestId = null;
            $oldestTimestamp = null;
            $newestTimestamp = null;
            $previousTimestamp = null;
            $previousId = null;
            foreach ($rows as $row) {
                if (!\is_array($row) || array_is_list($row)) {
                    throw new OkxHistoricalIntegrityException('okx_history_trade_response_inconsistent');
                }
                if (($row['instId'] ?? null) !== $native) {
                    throw new OkxHistoricalIntegrityException('okx_history_trade_instrument_mismatch');
                }
                $tradeId = $this->requiredUnsignedString($row['tradeId'] ?? null);
                $timestamp = $this->requiredUnsignedString($row['ts'] ?? null);
                if ($type === 2 && BigInteger::of($timestamp)->isGreaterThanOrEqualTo(BigInteger::of($cursor))) {
                    throw new OkxHistoricalIntegrityException('okx_history_trade_response_inconsistent');
                }
                if ($type === 1 && BigInteger::of($tradeId)->isGreaterThan(BigInteger::of($cursor))) {
                    throw new OkxHistoricalIntegrityException('okx_history_trade_cursor_regression');
                }
                if ($previousTimestamp !== null) {
                    $timestampOrder = BigInteger::of($timestamp)->compareTo(BigInteger::of($previousTimestamp));
                    if ($timestampOrder > 0
                        || ($timestampOrder === 0 && $previousId !== null
                            && BigInteger::of($tradeId)->isGreaterThan(BigInteger::of($previousId)))
                    ) {
                        throw new OkxHistoricalIntegrityException('okx_history_trade_response_inconsistent');
                    }
                }
                $previousTimestamp = $timestamp;
                $previousId = $tradeId;
                $oldestId = $oldestId === null || BigInteger::of($tradeId)->isLessThan(BigInteger::of($oldestId))
                    ? $tradeId
                    : $oldestId;
                $oldestTimestamp = $oldestTimestamp === null
                    || BigInteger::of($timestamp)->isLessThan(BigInteger::of($oldestTimestamp))
                    ? $timestamp
                    : $oldestTimestamp;
                $newestTimestamp = $newestTimestamp === null
                    || BigInteger::of($timestamp)->isGreaterThan(BigInteger::of($newestTimestamp))
                    ? $timestamp
                    : $newestTimestamp;
                $records[] = $this->record('trade', $symbol, $native, null, $tradeId, $row);
            }
            $priorOldestTimestamp = $stream['oldest_timestamp'] ?? null;
            if (\is_string($priorOldestTimestamp)
                && BigInteger::of($newestTimestamp)->isGreaterThan(BigInteger::of($priorOldestTimestamp))
            ) {
                throw new OkxHistoricalIntegrityException('okx_history_trade_cursor_not_progressing');
            }
            if ($type === 1 && !BigInteger::of($oldestId)->isLessThan(BigInteger::of($cursor))) {
                throw new OkxHistoricalIntegrityException('okx_history_trade_cursor_not_progressing');
            }
            $stream = $this->persistPage($key, $stream, $records);
            $stream['last_response_sha256'] = $responseSha256;
            $stream['pagination_type'] = 1;
            $stream['next_cursor'] = $oldestId;
            $stream['oldest_timestamp'] = $oldestTimestamp;
            $stream['complete'] = $this->eventPrecedesFrom($oldestTimestamp);
            $this->putStream($key, $stream);
        }
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function stream(string $key, array $defaults): array
    {
        $streams = $this->checkpoint['streams'] ?? null;
        if (!\is_array($streams)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        if (!isset($streams[$key])) {
            $streams[$key] = $defaults;
            $this->checkpoint['streams'] = $streams;
            $this->store->saveAcquisition($this->checkpoint);
        }
        if (!\is_array($streams[$key])) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }

        return $streams[$key];
    }

    /** @param array<string, mixed> $stream */
    private function putStream(string $key, array $stream): void
    {
        $streams = $this->checkpoint['streams'];
        if (!\is_array($streams)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $streams[$key] = $stream;
        ksort($streams, \SORT_STRING);
        $this->checkpoint['streams'] = $streams;
        $this->store->saveAcquisition($this->checkpoint);
    }

    /**
     * @param array<string, mixed>       $stream
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>
     */
    private function persistPage(string $key, array $stream, array $records): array
    {
        [$records, $frontier] = $this->acceptRecordsAfterDurableFrontier($stream, $records);
        $pages = $stream['pages'] ?? null;
        if (!\is_array($pages)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $pageCount = $this->checkpoint['page_count'] ?? null;
        $eventCount = $this->checkpoint['event_count'] ?? null;
        if (!\is_int($pageCount) || !\is_int($eventCount) || $eventCount < 0) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $acceptedEvents = 0;
        foreach ($records as $record) {
            if ($this->insideRange($this->recordTimestamp($record))) {
                ++$acceptedEvents;
            }
        }
        if ($eventCount + $acceptedEvents > $this->request->maximumEvents) {
            throw new OkxHistoricalIntegrityException('okx_history_event_bound_exceeded');
        }
        $number = \count($pages) + 1;
        $filename = str_replace('/', '-', $key) . '-' . str_pad((string) $number, 6, '0', \STR_PAD_LEFT) . '.ndjson';
        $page = $this->store->writePage($filename, $records);
        $previousChain = $pages === [] ? str_repeat('0', 64) : $pages[array_key_last($pages)]['chain_sha256'];
        if (!\is_string($previousChain)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        $page['chain_sha256'] = hash('sha256', $previousChain . $page['sha256']);
        $pages[] = $page;
        $stream['pages'] = $pages;
        if ($frontier !== null) {
            $stream['durable_frontier'] = $frontier;
        }
        $this->checkpoint['page_count'] = $pageCount + 1;
        $this->checkpoint['event_count'] = $eventCount + $acceptedEvents;

        return $stream;
    }

    /** @param array<string, mixed> $stream */
    private function acceptEmptyCandlePage(string $key, array $stream): void
    {
        $stream = $this->persistPage($key, $stream, []);
        $stream['complete'] = true;
        $this->putStream($key, $stream);
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @param array<string, mixed> $stream
     *
     * @return array{list<array<string, mixed>>, array{source_identity: string, natural_identity: string, source_digest: string}|null}
     */
    private function acceptRecordsAfterDurableFrontier(array $stream, array $records): array
    {
        $seen = [];
        $frontier = $this->validatedFrontier($stream['durable_frontier'] ?? null);
        $previousSourceIdentity = $frontier['source_identity'] ?? null;
        $unique = [];
        foreach ($records as $index => $record) {
            $identity = $record['natural_identity'] ?? null;
            $digest = $record['source_digest'] ?? null;
            $sourceIdentity = $record['source_identity'] ?? null;
            if (!\is_string($identity) || !\is_string($digest) || !\is_string($sourceIdentity)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_page_invalid');
            }
            if (isset($seen[$identity])) {
                if (!hash_equals($seen[$identity], $digest)) {
                    throw new OkxHistoricalIntegrityException('okx_history_natural_identity_conflict');
                }
                continue;
            }
            $seen[$identity] = $digest;
            if ($frontier !== null) {
                $order = BigInteger::of($sourceIdentity)->compareTo(BigInteger::of($frontier['source_identity']));
                if ($order === 0 && hash_equals($identity, $frontier['natural_identity'])) {
                    if (!hash_equals($digest, $frontier['source_digest'])) {
                        throw new OkxHistoricalIntegrityException('okx_history_natural_identity_conflict');
                    }
                    if ($index !== 0) {
                        throw new OkxHistoricalIntegrityException('okx_history_non_adjacent_overlap');
                    }
                    continue;
                }
                if ($order >= 0) {
                    throw new OkxHistoricalIntegrityException('okx_history_non_adjacent_overlap');
                }
            }
            if ($previousSourceIdentity !== null
                && BigInteger::of($sourceIdentity)->isGreaterThanOrEqualTo(BigInteger::of($previousSourceIdentity))
            ) {
                throw new OkxHistoricalIntegrityException('okx_history_non_adjacent_overlap');
            }
            $unique[] = $record;
            $previousSourceIdentity = $sourceIdentity;
        }

        if ($unique !== []) {
            $last = $unique[array_key_last($unique)];
            $frontier = [
                'source_identity' => $last['source_identity'],
                'natural_identity' => $last['natural_identity'],
                'source_digest' => $last['source_digest'],
            ];
        }

        return [$unique, $frontier];
    }

    /** @return array{source_identity: string, natural_identity: string, source_digest: string}|null */
    private function validatedFrontier(mixed $frontier): ?array
    {
        if ($frontier === null) {
            return null;
        }
        if (!\is_array($frontier)
            || !\is_string($frontier['source_identity'] ?? null)
            || !\is_string($frontier['natural_identity'] ?? null)
            || !\is_string($frontier['source_digest'] ?? null)
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }

        return $frontier;
    }

    private function validateFetchedDataset(): void
    {
        $count = 0;
        $candleTimestamps = [];
        foreach ($this->allRecords() as $record) {
            $timestamp = $this->recordTimestamp($record);
            if (!$this->insideRange($timestamp)) {
                continue;
            }
            ++$count;
            if ($count > $this->request->maximumEvents) {
                throw new OkxHistoricalIntegrityException('okx_history_event_bound_exceeded');
            }
            if (($record['kind'] ?? null) === 'candle') {
                $key = ($record['symbol'] ?? '') . '/' . ($record['bar'] ?? '');
                $candleTimestamps[$key][$timestamp] = true;
            }
        }
        foreach ($this->request->symbols as $symbol) {
            foreach (['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1H' => 3_600_000] as $bar => $step) {
                $from = $this->microseconds($this->request->from);
                $to = $this->microseconds($this->request->to);
                $stepMicroseconds = $step * 1_000;
                $first = $from->plus($stepMicroseconds - 1)
                    ->quotient($stepMicroseconds)
                    ->multipliedBy($stepMicroseconds);
                for ($timestamp = $first; $timestamp->isLessThan($to); $timestamp = $timestamp->plus($stepMicroseconds)) {
                    $timestampMilliseconds = (string) $timestamp->quotient(1_000);
                    if (!isset($candleTimestamps[$symbol . '/' . $bar][$timestampMilliseconds])) {
                        throw new OkxHistoricalIntegrityException('okx_history_candle_grid_incomplete');
                    }
                }
            }
        }
        $validationOrdinals = new OkxPaperSourceOrdinal();
        $validator = new OkxPaperMarketEventNormalizer(
            $this->clock,
            ordinals: $validationOrdinals,
        );
        try {
            foreach ($this->mergedRecords() as $record) {
                $this->normalize($validator, $record);
            }
        } catch (\Throwable $exception) {
            throw new OkxHistoricalIntegrityException('okx_history_normalization_failed', 0, $exception);
        }
        if (($this->checkpoint['event_count'] ?? null) !== $count) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
    }

    /** @return iterable<array<string, mixed>> */
    private function mergedRecords(): iterable
    {
        $iterators = [];
        foreach ($this->streamRecordIterators() as $key => $iterator) {
            $iterator->rewind();
            if ($iterator->valid()) {
                $iterators[$key] = $iterator;
            }
        }
        while ($iterators !== []) {
            $selectedKey = null;
            $selectedRecord = null;
            foreach ($iterators as $key => $iterator) {
                $candidate = $iterator->current();
                if (!\is_array($candidate)) {
                    throw new OkxHistoricalIntegrityException('okx_acquisition_page_invalid');
                }
                if ($selectedRecord === null || strcmp($this->sortKey($candidate), $this->sortKey($selectedRecord)) < 0) {
                    $selectedKey = $key;
                    $selectedRecord = $candidate;
                }
            }
            yield $selectedRecord;
            $iterators[$selectedKey]->next();
            if (!$iterators[$selectedKey]->valid()) {
                unset($iterators[$selectedKey]);
            }
        }
    }

    /** @return array<string, \Generator<int, array<string, mixed>>> */
    private function streamRecordIterators(): array
    {
        $result = [];
        $streams = $this->checkpoint['streams'] ?? null;
        if (!\is_array($streams)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        foreach ($streams as $key => $stream) {
            if (!\is_string($key) || !\is_array($stream) || !\is_array($stream['pages'] ?? null)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            $result[$key] = $this->recordsForPages($stream['pages']);
        }

        return $result;
    }

    /**
     * Holds at most one API page for one stream while the k-way merge is running.
     *
     * @param list<array<string, mixed>> $pages
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function recordsForPages(array $pages): \Generator
    {
        foreach (array_reverse($pages) as $page) {
            if (!\is_array($page) || !\is_string($page['file'] ?? null)) {
                throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
            }
            foreach (array_reverse($this->store->readPage($page['file'])) as $record) {
                if ($this->insideRange($this->recordTimestamp($record))) {
                    yield $record;
                }
            }
        }
    }

    /** @return iterable<array<string, mixed>> */
    private function allRecords(): iterable
    {
        foreach ($this->checkpoint['streams'] as $stream) {
            foreach ($stream['pages'] as $page) {
                yield from $this->store->readPage($page['file']);
            }
        }
    }

    /** @param array<string, mixed> $record */
    private function normalize(OkxPaperMarketEventNormalizer $normalizer, array $record): PaperMarketEvent
    {
        $row = $record['row'] ?? null;
        if (!\is_array($row)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_page_invalid');
        }
        if (($record['kind'] ?? null) === 'trade') {
            return $normalizer->historyTrade($row);
        }
        $native = $record['native_symbol'] ?? null;
        $bar = $record['bar'] ?? null;
        if (!\is_string($native) || !\is_string($bar)) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_page_invalid');
        }
        return $normalizer->historyCandle($native, $bar, $row)
            ?? throw new OkxHistoricalIntegrityException('okx_history_candle_grid_incomplete');
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function record(
        string $kind,
        string $symbol,
        string $native,
        ?string $bar,
        string $sourceIdentity,
        array $row,
    ): array {
        $timestamp = $kind === 'candle' ? ($row[0] ?? null) : ($row['ts'] ?? null);
        if (!\is_string($timestamp)) {
            throw new OkxHistoricalIntegrityException('okx_history_response_inconsistent');
        }
        $naturalIdentity = $kind === 'candle'
            ? implode('|', [$native, 'candle', $bar, $sourceIdentity])
            : implode('|', [$native, 'trade', $sourceIdentity]);

        return [
            'kind' => $kind,
            'symbol' => $symbol,
            'native_symbol' => $native,
            'bar' => $bar,
            'exchange_timestamp_ms' => $timestamp,
            'source_identity' => $sourceIdentity,
            'natural_identity' => $naturalIdentity,
            'source_digest' => hash('sha256', CanonicalJson::encode($row)),
            'row' => $row,
        ];
    }

    /** @param array<string, mixed> $record */
    private function recordTimestamp(array $record): string
    {
        return $this->requiredUnsignedString($record['exchange_timestamp_ms'] ?? null);
    }

    private function insideRange(string $timestamp): bool
    {
        $value = BigInteger::of($timestamp)->multipliedBy(1_000);

        return $value->isGreaterThanOrEqualTo($this->microseconds($this->request->from))
            && $value->isLessThan($this->microseconds($this->request->to));
    }

    /** @param array<string, mixed> $record */
    private function sortKey(array $record): string
    {
        $milliseconds = str_pad($this->recordTimestamp($record), 20, '0', \STR_PAD_LEFT);
        $kind = $record['kind'] ?? null;
        $channel = $kind === 'trade' ? 'public_trade' : 'candle_' . ($record['bar'] ?? '');
        $symbol = $record['symbol'] ?? null;
        $identity = $record['natural_identity'] ?? null;
        $sourceIdentity = $record['source_identity'] ?? null;
        if (!\is_string($channel)
            || !\is_string($symbol)
            || !\is_string($identity)
            || !\is_string($sourceIdentity)
        ) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_page_invalid');
        }
        $numericIdentity = str_pad((string) \strlen($sourceIdentity), 6, '0', \STR_PAD_LEFT) . ':' . $sourceIdentity;

        return implode('|', [$milliseconds, $channel, $symbol, $numericIdentity, $identity]);
    }

    private function assertPageAvailable(): void
    {
        $count = $this->checkpoint['page_count'] ?? null;
        if (!\is_int($count) || $count < 0) {
            throw new OkxHistoricalIntegrityException('okx_acquisition_checkpoint_invalid');
        }
        if ($count >= $this->request->maximumPages) {
            throw new OkxHistoricalIntegrityException('okx_history_page_bound_exceeded');
        }
    }

    private function eventPrecedesFrom(string $timestampMilliseconds): bool
    {
        return BigInteger::of($timestampMilliseconds)->multipliedBy(1_000)
            ->isLessThan($this->microseconds($this->request->from));
    }

    private function exclusiveUpperBoundMilliseconds(\DateTimeImmutable $timestamp): string
    {
        return (string) $this->microseconds($timestamp)->plus(999)->quotient(1_000);
    }

    private function microseconds(\DateTimeImmutable $timestamp): BigInteger
    {
        return BigInteger::of($timestamp->format('U'))
            ->multipliedBy(1_000_000)
            ->plus($timestamp->format('u'));
    }

    private function requiredUnsignedString(mixed $value): string
    {
        if (!\is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new OkxHistoricalIntegrityException('okx_history_response_inconsistent');
        }

        return $value;
    }
}
