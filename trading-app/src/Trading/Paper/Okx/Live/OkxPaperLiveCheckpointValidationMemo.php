<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinalSnapshotMemo;

/**
 * Per-store memo of pure checkpoint sub-validations.
 *
 * Every entry records an input that the full validation accepted, keyed so that
 * only exactly the same input (strict ===) can hit. A hit therefore returns what
 * the full validation of that input returned, while a changed or unknown input is
 * validated in full. Entries are bounded by the checkpoint shape. The memo belongs
 * to one OkxPaperLiveCheckpointStore instance and is never shared.
 */
final class OkxPaperLiveCheckpointValidationMemo
{
    private const MAX_REMEMBERED_EVENTS = 4;

    public readonly OkxPaperSourceOrdinalSnapshotMemo $ordinals;

    /**
     * Last retained-row list validated per pagination stream, and the set of its
     * compact (string) rows, each of which passed the per-row validation.
     *
     * @var array<string, array{rows: list<mixed>, validated: array<string, true>}>
     */
    private array $retainedRows = [];

    /**
     * Last acknowledged-identity list validated per logical stream, and the natural
     * identity hash that each of its compact (string) entries expanded to.
     *
     * @var array<string, array{entries: list<mixed>, identities: array<string, string>}>
     */
    private array $identityEntries = [];

    /** @var list<array{digest: string, event: PaperMarketEvent}> */
    private array $events = [];

    public function __construct()
    {
        $this->ordinals = new OkxPaperSourceOrdinalSnapshotMemo();
    }

    /** @param list<mixed> $rows */
    public function retainedRowsValidated(string $stream, #[\SensitiveParameter] array $rows): bool
    {
        return ($this->retainedRows[$stream]['rows'] ?? null) === $rows;
    }

    /** @return array<string, true> */
    public function validatedRetainedRows(string $stream): array
    {
        return $this->retainedRows[$stream]['validated'] ?? [];
    }

    /**
     * @param list<mixed>         $rows
     * @param array<string, true> $validated
     */
    public function rememberRetainedRows(
        string $stream,
        #[\SensitiveParameter] array $rows,
        #[\SensitiveParameter] array $validated,
    ): void {
        $this->retainedRows[$stream] = ['rows' => $rows, 'validated' => $validated];
    }

    /** @param list<mixed> $entries */
    public function identityEntriesValidated(string $stream, #[\SensitiveParameter] array $entries): bool
    {
        return ($this->identityEntries[$stream]['entries'] ?? null) === $entries;
    }

    /** @return array<string, string> */
    public function validatedIdentityEntries(string $stream): array
    {
        return $this->identityEntries[$stream]['identities'] ?? [];
    }

    /**
     * @param list<mixed>           $entries
     * @param array<string, string> $identities
     */
    public function rememberIdentityEntries(
        string $stream,
        #[\SensitiveParameter] array $entries,
        #[\SensitiveParameter] array $identities,
    ): void {
        $this->identityEntries[$stream] = ['entries' => $entries, 'identities' => $identities];
    }

    /**
     * PaperMarketEvent::fromArray() is a pure validation and events are immutable,
     * so an identical serialized event (same SHA-256 of its PHP serialization,
     * which encodes every key, value, type and order) yields the same event.
     *
     * @param array<array-key, mixed> $eventState
     */
    public function event(#[\SensitiveParameter] array $eventState): PaperMarketEvent
    {
        $digest = hash('sha256', serialize($eventState));
        foreach ($this->events as $remembered) {
            if (hash_equals($remembered['digest'], $digest)) {
                return $remembered['event'];
            }
        }
        /** @var array<string, mixed> $eventState */
        $event = PaperMarketEvent::fromArray($eventState);
        array_unshift($this->events, ['digest' => $digest, 'event' => $event]);
        if (\count($this->events) > self::MAX_REMEMBERED_EVENTS) {
            array_pop($this->events);
        }

        return $event;
    }
}
