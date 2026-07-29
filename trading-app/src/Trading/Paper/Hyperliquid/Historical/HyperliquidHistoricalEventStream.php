<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPrudentBookModel;
use App\Trading\Paper\MarketData\AcknowledgedPaperMarketDataSourceInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final class HyperliquidHistoricalEventStream implements AcknowledgedPaperMarketDataSourceInterface
{
    private const PAGE_SIZE = 500;

    /** @var array<string, mixed> */
    private array $checkpoint;
    private readonly HyperliquidHistoricalCheckpointStore $store;
    private readonly HyperliquidPaperInstrumentMap $instruments;
    private HyperliquidPaperSourceOrdinal $ordinals;
    private bool $stopped = false;

    public function __construct(
        private readonly HyperliquidPaperPublicRestClientInterface $restClient,
        private readonly HyperliquidHistoricalRequest $request,
        #[\SensitiveParameter] string $datasetDirectory,
        private readonly ?\Closure $durabilityObserver = null,
    ) {
        $this->instruments = new HyperliquidPaperInstrumentMap();
        $this->store = new HyperliquidHistoricalCheckpointStore($datasetDirectory, $request);
        $this->checkpoint = $this->store->loadOrCreate();
        try {
            /** @var array<string, mixed> $ordinalState */
            $ordinalState = $this->checkpoint['ordinal_state'];
            $this->ordinals = HyperliquidPaperSourceOrdinal::restore($ordinalState);
        } catch (\Throwable $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
                0,
                $exception,
            );
        }
    }

    public function venue(): PaperMarketDataVenue
    {
        return PaperMarketDataVenue::HYPERLIQUID;
    }

    /** @return iterable<PaperMarketEvent> */
    public function events(): iterable
    {
        try {
            $this->store->verifyPages($this->checkpoint);
            yield from $this->produceEvents();
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            $reason = \in_array(
                $exception->getMessage(),
                HyperliquidHistoricalCheckpointStore::ALLOWED_FAILURE_REASONS,
                true,
            )
                ? $exception->getMessage()
                : 'hyperliquid_acquisition_checkpoint_invalid';
            $this->checkpoint['phase'] = 'failed';
            $this->checkpoint['failure_reason'] = $reason;
            $this->checkpoint['pending_event'] = null;
            $this->store->save($this->checkpoint);

            throw $exception;
        }
    }

    /** @return iterable<PaperMarketEvent> */
    private function produceEvents(): iterable
    {
        $phase = $this->checkpoint['phase'] ?? null;
        if ($phase === 'failed') {
            $reason = $this->checkpoint['failure_reason'] ?? null;
            throw new HyperliquidHistoricalIntegrityException(
                \is_string($reason) ? $reason : 'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        if ($phase === 'fetching') {
            if (!$this->fetchAllStreams()) {
                return;
            }
            $this->validateFetchedDataset();
            $this->checkpoint['phase'] = 'emitting';
            $this->store->save($this->checkpoint);
            $this->observeDurability('after_emitting_save');
        }
        if (($this->checkpoint['phase'] ?? null) === 'complete') {
            return;
        }
        if (($this->checkpoint['phase'] ?? null) !== 'emitting') {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }

        $pending = $this->checkpoint['pending_event'] ?? null;
        if ($pending !== null) {
            if (!\is_array($pending) || !\is_array($pending['event'] ?? null)) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_checkpoint_invalid',
                );
            }
            /** @var array<string, mixed> $eventState */
            $eventState = $pending['event'];
            yield PaperMarketEvent::fromArray($eventState);
            if ($this->hasPendingEvent()) {
                throw new \LogicException(
                    'hyperliquid_acquisition_pending_event_not_acknowledged',
                );
            }
        }

        $skip = $this->checkpoint['emit_index'] ?? null;
        if (!\is_int($skip) || $skip < 0) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        $index = 0;
        $normalizer = new HyperliquidPaperMarketEventNormalizer(
            $this->request->network,
            $this->ordinals,
        );
        foreach ($this->mergedModelInputs() as $input) {
            if ($this->stopped) {
                return;
            }
            if ($index++ < $skip) {
                continue;
            }
            try {
                $event = $input['channel'] === PaperMarketDataChannel::TOP_OF_BOOK
                    ? $normalizer->modelledTopOfBook($input['candle'], $input['book'])
                    : $normalizer->candle($input['candle']);
            } catch (\Throwable $exception) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_page_invalid',
                    0,
                    $exception,
                );
            }
            if (!$event instanceof PaperMarketEvent) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_page_invalid',
                );
            }
            try {
                $eventState = json_decode(
                    CanonicalJson::encode($event->toArray()),
                    true,
                    512,
                    \JSON_THROW_ON_ERROR,
                );
                if (!\is_array($eventState) || array_is_list($eventState)) {
                    throw new \JsonException();
                }
                $durableEvent = PaperMarketEvent::fromArray($eventState);
            } catch (\Throwable $exception) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_acquisition_page_invalid',
                    0,
                    $exception,
                );
            }
            $this->checkpoint['ordinal_state'] = $this->ordinals->snapshot();
            $this->checkpoint['pending_event'] = [
                'natural_identity' => $input['natural_identity'],
                'event' => $eventState,
            ];
            $this->store->save($this->checkpoint);
            $this->observeDurability('after_pending_save:' . $this->checkpoint['emit_index']);
            yield $durableEvent;
            if ($this->hasPendingEvent()) {
                throw new \LogicException(
                    'hyperliquid_acquisition_pending_event_not_acknowledged',
                );
            }
            if ($this->stopped) {
                return;
            }
        }

        $this->checkpoint['phase'] = 'complete';
        $this->store->save($this->checkpoint);
    }

    public function acknowledge(string $eventId): void
    {
        $pending = $this->checkpoint['pending_event'] ?? null;
        $pendingId = \is_array($pending) && \is_array($pending['event'] ?? null)
            ? ($pending['event']['event_id'] ?? null)
            : null;
        if (!\is_string($pendingId) || !hash_equals($pendingId, $eventId)) {
            throw new \LogicException('hyperliquid_acquisition_acknowledgement_invalid');
        }
        $emitIndex = $this->checkpoint['emit_index'] ?? null;
        if (!\is_int($emitIndex) || $emitIndex < 0) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        $this->checkpoint['emit_index'] = $emitIndex + 1;
        $this->checkpoint['pending_event'] = null;
        $this->store->save($this->checkpoint);
        $this->observeDurability('after_ack_save:' . $this->checkpoint['emit_index']);
    }

    private function hasPendingEvent(): bool
    {
        return $this->checkpoint['pending_event'] !== null;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isComplete(): bool
    {
        return ($this->checkpoint['phase'] ?? null) === 'complete';
    }

    private function fetchAllStreams(): bool
    {
        foreach ($this->request->symbols as $symbol) {
            $coin = $this->instruments->nativeCoin($symbol);
            foreach ($this->request->intervals as $interval) {
                if ($this->stopped) {
                    return false;
                }
                $this->fetchStream($coin, $interval);
            }
        }

        return !$this->stopped;
    }

    private function fetchStream(string $coin, string $interval): void
    {
        $key = $coin . '/candle_' . $interval;
        $step = $this->instruments->intervalMilliseconds($interval);
        $first = $this->firstGridTime($step);
        $to = $this->exclusiveToMilliseconds();
        $streams = $this->streams();
        if (!isset($streams[$key])) {
            $streams[$key] = [
                'kind' => 'candle',
                'coin' => $coin,
                'interval' => $interval,
                'next_cursor' => $first,
                'complete' => $first >= $to,
                'pages' => [],
            ];
            ksort($streams, \SORT_STRING);
            $this->checkpoint['streams'] = $streams;
            $this->store->save($this->checkpoint);
        }
        /** @var array<string, mixed> $stream */
        $stream = $this->streams()[$key];
        [$model, $expectedCursor] = $this->revalidateStreamPrefix($stream, $first, $step);
        if (($stream['next_cursor'] ?? null) !== $expectedCursor) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        if (($stream['complete'] ?? null) === true) {
            return;
        }

        while ($expectedCursor < $to) {
            if ($this->stopped) {
                return;
            }
            $this->assertPageAvailable();
            $end = min($to - 1, $this->pageEnd($expectedCursor, $step));
            $rows = $this->restClient->candleSnapshot(
                $coin,
                $interval,
                $expectedCursor,
                $end,
                $this->request->maximumResponseBytes,
                $this->request->maximumRetries,
            );
            if (\count($rows) > self::PAGE_SIZE) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_history_candle_response_limit_exceeded',
                );
            }
            if ($rows === []) {
                throw new HyperliquidHistoricalIntegrityException(
                    $stream['pages'] === []
                        ? 'hyperliquid_history_retention_incomplete'
                        : 'hyperliquid_history_candle_grid_gap',
                );
            }
            if ($this->isRepeatedPage($stream, $rows)) {
                throw new HyperliquidHistoricalIntegrityException(
                    'hyperliquid_history_repeated_page',
                );
            }

            $pageEvents = 0;
            $cursor = $expectedCursor;
            foreach ($rows as $index => $row) {
                $candle = $this->responseCandle($row, $coin, $interval);
                if ($candle->startTime !== $cursor) {
                    $reason = $candle->startTime > $cursor
                        ? ($stream['pages'] === [] && $index === 0
                            ? 'hyperliquid_history_retention_incomplete'
                            : 'hyperliquid_history_candle_grid_gap')
                        : 'hyperliquid_history_candle_response_inconsistent';
                    throw new HyperliquidHistoricalIntegrityException($reason);
                }
                if ($candle->startTime > $end) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_history_candle_response_inconsistent',
                    );
                }
                ++$pageEvents;
                if ($model->push($candle) !== null) {
                    ++$pageEvents;
                }
                $cursor = $this->nextCursor($candle->startTime, $step);
            }
            $this->assertEventCapacity($pageEvents);
            $pageNumber = \count($stream['pages']) + 1;
            $this->observeDurability(
                'before_page_write:' . $key . ':' . $pageNumber,
            );
            $stream = $this->persistPage($key, $stream, $rows, $pageEvents);
            $expectedCursor = $cursor;
            $stream['next_cursor'] = $expectedCursor;
            $stream['complete'] = $expectedCursor >= $to;
            $this->putStream($key, $stream);
            $this->observeDurability(
                'after_page_save:' . $key . ':' . $pageNumber,
            );
        }
    }

    /**
     * @param array<string, mixed> $stream
     *
     * @return array{HyperliquidPrudentBookModel, int}
     */
    private function revalidateStreamPrefix(array $stream, int $first, int $step): array
    {
        $model = new HyperliquidPrudentBookModel();
        $cursor = $first;
        foreach ($stream['pages'] as $page) {
            foreach ($this->readPage($page) as $row) {
                $candle = $this->responseCandle($row, $stream['coin'], $stream['interval']);
                if ($candle->startTime !== $cursor) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_history_candle_grid_gap',
                    );
                }
                $model->push($candle);
                $cursor = $this->nextCursor($cursor, $step);
            }
        }

        return [$model, $cursor];
    }

    /** @param array<string, mixed> $stream
     *  @param list<array<string, mixed>> $rows
     *
     *  @return array<string, mixed>
     */
    private function persistPage(
        string $key,
        array $stream,
        array $rows,
        int $pageEvents,
    ): array {
        $pages = $stream['pages'];
        $number = \count($pages) + 1;
        $filename = str_replace('/', '-', $key)
            . '-' . str_pad((string) $number, 6, '0', \STR_PAD_LEFT)
            . '.ndjson';
        $descriptor = $this->store->writePage($filename, $rows);
        $this->observeDurability('after_page_write:' . $key . ':' . $number);
        $previousChain = $pages === []
            ? str_repeat('0', 64)
            : $pages[array_key_last($pages)]['chain_sha256'];
        if (!\is_string($previousChain)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        $descriptor['chain_sha256'] = hash(
            'sha256',
            $previousChain . $descriptor['sha256'],
        );
        $pages[] = $descriptor;
        $stream['pages'] = $pages;
        ++$this->checkpoint['page_count'];
        $this->checkpoint['staged_row_count'] += \count($rows);
        $this->checkpoint['event_count'] += $pageEvents;

        return $stream;
    }

    /** @param array<string, mixed> $stream */
    private function putStream(string $key, array $stream): void
    {
        $streams = $this->streams();
        $streams[$key] = $stream;
        ksort($streams, \SORT_STRING);
        $this->checkpoint['streams'] = $streams;
        $this->store->save($this->checkpoint);
    }

    private function validateFetchedDataset(): void
    {
        $expectedKeys = [];
        $staged = 0;
        $events = 0;
        foreach ($this->request->symbols as $symbol) {
            $coin = $this->instruments->nativeCoin($symbol);
            foreach ($this->request->intervals as $interval) {
                $key = $coin . '/candle_' . $interval;
                $expectedKeys[] = $key;
                $stream = $this->streams()[$key] ?? null;
                if (!\is_array($stream) || ($stream['complete'] ?? null) !== true) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_history_candle_grid_gap',
                    );
                }
                $step = $this->instruments->intervalMilliseconds($interval);
                $cursor = $this->firstGridTime($step);
                $model = new HyperliquidPrudentBookModel();
                foreach ($stream['pages'] as $page) {
                    foreach ($this->readPage($page) as $row) {
                        $candle = $this->responseCandle($row, $coin, $interval);
                        if ($candle->startTime !== $cursor) {
                            throw new HyperliquidHistoricalIntegrityException(
                                'hyperliquid_history_candle_grid_gap',
                            );
                        }
                        ++$staged;
                        ++$events;
                        if ($model->push($candle) !== null) {
                            ++$events;
                        }
                        $cursor = $this->nextCursor($cursor, $step);
                    }
                }
                if ($cursor < $this->exclusiveToMilliseconds()) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_history_candle_grid_gap',
                    );
                }
            }
        }
        sort($expectedKeys, \SORT_STRING);
        $actualKeys = array_keys($this->streams());
        sort($actualKeys, \SORT_STRING);
        if ($actualKeys !== $expectedKeys
            || $staged !== ($this->checkpoint['staged_row_count'] ?? null)
            || $events !== ($this->checkpoint['event_count'] ?? null)
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
    }

    /**
     * @return iterable<array{
     *     candle: HyperliquidCandle,
     *     book: array<string, string>|null,
     *     channel: PaperMarketDataChannel,
     *     interval: string,
     *     natural_identity: string
     * }>
     */
    private function mergedModelInputs(): iterable
    {
        $iterators = [];
        foreach ($this->streams() as $key => $stream) {
            $iterator = $this->modelInputsForStream($stream);
            $iterator->rewind();
            if ($iterator->valid()) {
                $iterators[$key] = $iterator;
            }
        }
        while ($iterators !== []) {
            $selectedKey = null;
            $selected = null;
            foreach ($iterators as $key => $iterator) {
                $candidate = $iterator->current();
                if (!\is_array($candidate)) {
                    throw new HyperliquidHistoricalIntegrityException(
                        'hyperliquid_acquisition_page_invalid',
                    );
                }
                if ($selected === null
                    || strcmp($this->modelInputSortKey($candidate), $this->modelInputSortKey($selected)) < 0
                ) {
                    $selectedKey = $key;
                    $selected = $candidate;
                }
            }
            yield $selected;
            $iterators[$selectedKey]->next();
            if (!$iterators[$selectedKey]->valid()) {
                unset($iterators[$selectedKey]);
            }
        }
    }

    /**
     * @param array<string, mixed> $stream
     *
     * @return \Generator<int, array{
     *     candle: HyperliquidCandle,
     *     book: array<string, string>|null,
     *     channel: PaperMarketDataChannel,
     *     interval: string,
     *     natural_identity: string
     * }>
     */
    private function modelInputsForStream(array $stream): \Generator
    {
        $model = new HyperliquidPrudentBookModel();
        foreach ($stream['pages'] as $page) {
            foreach ($this->readPage($page) as $row) {
                $candle = $this->responseCandle($row, $stream['coin'], $stream['interval']);
                $identity = implode('|', [
                    $candle->coin,
                    $candle->interval,
                    (string) $candle->startTime,
                    (string) $candle->closeTime,
                ]);
                yield [
                    'candle' => $candle,
                    'book' => null,
                    'channel' => $this->candleChannel($candle->interval),
                    'interval' => $candle->interval,
                    'natural_identity' => $identity,
                ];
                $book = $model->push($candle);
                if ($book !== null) {
                    yield [
                        'candle' => $candle,
                        'book' => $book,
                        'channel' => PaperMarketDataChannel::TOP_OF_BOOK,
                        'interval' => $candle->interval,
                        'natural_identity' => $identity . '|'
                            . HyperliquidPrudentBookModel::NAME . '|'
                            . HyperliquidPrudentBookModel::VERSION,
                    ];
                }
            }
        }
    }

    /** @param array{
     *     candle: HyperliquidCandle,
     *     book: array<string, string>|null,
     *     channel: PaperMarketDataChannel,
     *     interval: string,
     *     natural_identity: string
     * } $input
     */
    private function modelInputSortKey(array $input): string
    {
        return implode('|', [
            str_pad((string) $input['candle']->closeTime, 20, '0', \STR_PAD_LEFT),
            $this->instruments->normalizedSymbol($input['candle']->coin),
            str_pad(
                (string) $this->instruments->intervalMilliseconds($input['interval']),
                10,
                '0',
                \STR_PAD_LEFT,
            ),
            $input['channel']->value,
            $input['natural_identity'],
        ]);
    }

    /** @return array<string, mixed> */
    private function streams(): array
    {
        $streams = $this->checkpoint['streams'] ?? null;
        if (!\is_array($streams)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }

        return $streams;
    }

    /** @param array<string, mixed> $page
     *  @return list<array<string, mixed>>
     */
    private function readPage(array $page): array
    {
        $file = $page['file'] ?? null;
        if (!\is_string($file)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }

        return $this->store->readPage($file);
    }

    /**
     * @param array<string, mixed>        $stream
     * @param list<array<string, mixed>> $rows
     */
    private function isRepeatedPage(array $stream, array $rows): bool
    {
        $pages = $stream['pages'] ?? [];
        if ($pages === []) {
            return false;
        }
        $last = $pages[array_key_last($pages)];

        return hash_equals(
            hash('sha256', CanonicalJson::encode($this->readPage($last))),
            hash('sha256', CanonicalJson::encode($rows)),
        );
    }

    private function responseCandle(mixed $row, string $coin, string $interval): HyperliquidCandle
    {
        try {
            return HyperliquidCandle::fromApiRow($row, $coin, $interval);
        } catch (\Throwable $exception) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_history_candle_response_inconsistent',
                0,
                $exception,
            );
        }
    }

    private function assertPageAvailable(): void
    {
        $pageCount = $this->checkpoint['page_count'] ?? null;
        if (!\is_int($pageCount) || $pageCount < 0) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_checkpoint_invalid',
            );
        }
        if ($pageCount >= $this->request->maximumPages) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_history_page_bound_exceeded',
            );
        }
    }

    private function assertEventCapacity(int $additional): void
    {
        $eventCount = $this->checkpoint['event_count'] ?? null;
        if (!\is_int($eventCount)
            || $eventCount < 0
            || $additional > $this->request->maximumEvents - $eventCount
        ) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_history_event_bound_exceeded',
            );
        }
    }

    private function pageEnd(int $cursor, int $step): int
    {
        $span = (self::PAGE_SIZE - 1) * $step;
        if ($cursor > \PHP_INT_MAX - $span) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_history_candle_cursor_not_progressing',
            );
        }

        return $cursor + $span;
    }

    private function nextCursor(int $cursor, int $step): int
    {
        if ($cursor > \PHP_INT_MAX - $step) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_history_candle_cursor_not_progressing',
            );
        }
        $next = $cursor + $step;
        if ($next <= $cursor) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_history_candle_cursor_not_progressing',
            );
        }

        return $next;
    }

    private function firstGridTime(int $step): int
    {
        $microseconds = $this->epochMicroseconds($this->request->from);
        $stepMicroseconds = $step * 1_000;

        return intdiv($microseconds + $stepMicroseconds - 1, $stepMicroseconds) * $step;
    }

    private function exclusiveToMilliseconds(): int
    {
        $microseconds = $this->epochMicroseconds($this->request->to);

        return intdiv($microseconds + 999, 1_000);
    }

    private function epochMicroseconds(\DateTimeImmutable $timestamp): int
    {
        $seconds = (int) $timestamp->format('U');
        $microseconds = (int) $timestamp->format('u');
        if ($seconds < 0 || $seconds > intdiv(\PHP_INT_MAX - $microseconds, 1_000_000)) {
            throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_history_candle_cursor_not_progressing',
            );
        }

        return ($seconds * 1_000_000) + $microseconds;
    }

    private function candleChannel(string $interval): PaperMarketDataChannel
    {
        return match ($interval) {
            '1m' => PaperMarketDataChannel::CANDLE_1M,
            '5m' => PaperMarketDataChannel::CANDLE_5M,
            '15m' => PaperMarketDataChannel::CANDLE_15M,
            '1h' => PaperMarketDataChannel::CANDLE_1H,
            default => throw new HyperliquidHistoricalIntegrityException(
                'hyperliquid_acquisition_page_invalid',
            ),
        };
    }

    private function observeDurability(string $boundary): void
    {
        if ($this->durabilityObserver !== null) {
            ($this->durabilityObserver)($boundary);
        }
    }
}
