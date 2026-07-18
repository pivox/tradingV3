# Fake Private WS Out-of-Order v1 Design

## Context

PR #274 established the Fake private WebSocket sequence contract:

- a raw event is acknowledged only after its normalized projections succeed;
- a numeric gap switches the client to `resync_required`;
- a gap requires a simulated REST snapshot reconciliation before streaming resumes;
- every normalized event emitted from one raw event is projected atomically.

Golden scenario 16 remains partial because the Fake client cannot yet persist and
replay an explicit duplicated or out-of-order delivery plan. The current
in-memory `consumedSequences` map also treats every repeated sequence as an exact
duplicate, so it cannot distinguish a harmless replay from a conflicting
payload.

This change is local Fake/Paper behavior only. It must not contact an exchange,
enable demo/testnet writes, or change strategy, MTF, EntryZone, sizing, leverage,
SL/TP, fee, slippage, or mainnet guards.

## Goals

- Persist a finite, explicit private-WS delivery fixture in Fake/Paper state.
- Deliver events in fixture order without timestamp sorting.
- Make exact duplicates idempotent before normalization and projection.
- Detect the same sequence with a different canonical payload and fail closed.
- Preserve the #274 gap, acknowledgement, rollback, and snapshot-resync rules.
- Persist enough cursor and audit state to survive a process restart while
  `resync_required`.
- Promote golden scenario 16 to executable with deterministic evidence.

## Non-goals

- General-purpose WebSocket scheduling, timing, jitter, or infinite generators.
- Real exchange WebSocket protocol emulation.
- Automatic conflict repair without a snapshot.
- Changes to the shared normalizer or Doctrine projection semantics.
- Timestamp-based ordering or heuristics.

## Approaches Considered

### 1. Deduplicate in the local projection store

This reuses database uniqueness but is rejected. A sequence conflict must be
detected before normalization, and one raw event may produce multiple normalized
projections. Projection-level deduplication would conflate transport identity
with business-entity identity and would not persist the Fake delivery fixture.

### 2. Persist a cursor in a separate WS state file

This keeps the exchange state store smaller but creates two independently
committed files. A crash could persist an exchange event without its delivery
cursor or vice versa, making restart behavior non-deterministic.

### 3. Persist fixture, cursor, fingerprints, and audit in Fake state

This is the selected approach. The existing checksummed, atomically replaced
`fake-paper-state-v1` envelope already owns canonical Fake events and scenario
faults. Additive private-WS fields keep the delivery contract and its recovery
state in the same transaction domain without changing the shared projection
layer.

## Architecture

### Persisted delivery fixture

Add a focused immutable value object, `FakePrivateWsDelivery`, representing one
raw delivery:

- stable fixture entry ID;
- declared event sequence;
- complete `FakeExchangeEvent` envelope;
- deterministic SHA-256 fingerprint of a canonical serialization containing
  type, upper-case symbol, ISO-8601 occurrence time, and recursively
  key-sorted payload.

Add `FakePrivateWsScenario`, a finite ordered list of deliveries. The fixture is
created explicitly from events or loaded from a JSON fixture used by golden
scenario 16. Repeating the same delivery produces an exact duplicate. Reusing a
sequence with a modified envelope produces a conflict. The list order is the
only delivery order; `occurredAt` is evidence, never a sorting key.

The scenario and runtime cursor are stored additively in
`FakeExchangeStateStore`:

- scenario ID and ordered deliveries;
- next delivery index;
- acknowledged fingerprint by sequence;
- last acknowledged sequence;
- last observed numeric sequence;
- `connected` or `resync_required`;
- stable resync reason;
- audit counters and bounded structured audit records.

Scenario mode is opt-in. When no explicit scenario is configured,
`FakeExchangeWsClient` retains the #274 per-client traversal and disconnect
behavior over the canonical state-store events. Creating a fresh ordinary
client therefore continues to provide an independent replay, as existing tests
and reconciliation tools expect. Only an explicit persisted scenario shares
its cursor across client instances and restarts.

Older `fake-paper-state-v1` payloads omit these fields and hydrate to an empty,
inactive scenario with connected WS state. Existing envelope version and engine
identity remain compatible because the new fields are additive and validated
with defaults. Malformed new fields fail with
`fake_exchange_state_shape_invalid`.

### Delivery and acknowledgement

In explicit scenario mode, `FakeExchangeWsClient` delegates mutable sequence
state to `FakeExchangeStateStore`; it does not keep an independent
restart-sensitive cursor. Outside scenario mode, the existing in-memory
per-client cursor remains unchanged for backward compatibility.

For each fixture delivery:

1. Read the entry at the persisted cursor.
2. If its sequence has an acknowledged fingerprint:
   - matching fingerprint: increment `duplicate_total`, append a redacted
     `duplicate` audit record, advance the fixture cursor, and do not yield;
   - different fingerprint: persist `resync_required`, increment
     `conflict_total`, append a `conflict` audit record, and throw
     `fake_private_ws_sequence_conflict`.
3. If its numeric sequence is greater than `last_observed + 1`, persist
   `resync_required`, increment `gap_total`, append a `gap` audit record, and
   throw the existing `fake_private_ws_sequence_gap`.
4. Yield the raw event without advancing or acknowledging it.
5. Only when the generator resumes after successful normalization and atomic
   projection, persist its fingerprint, advance the cursor, update sequence
   watermarks, and increment `acknowledged_total`.

Destroying the client after yield but before generator resume leaves the cursor
unchanged. A new client therefore retries the same raw delivery. A projection
exception or DB rollback has the same effect.

### Snapshot resynchronization

The existing simulated REST snapshot and `ExchangeReconciliationService` remain
the canonical rebuild path. `completeSnapshotResync()` is only the transport
acknowledgement after that reconciliation succeeds.

Completion obtains the maximum numeric event sequence represented by the
current Fake REST state and:

- records fingerprints for fixture deliveries covered by that watermark;
- advances past every covered delivery, even if the delivery plan was `1,3,2`;
- updates observed and acknowledged watermarks monotonically;
- clears `resync_required`;
- increments `resync_total` and appends a `resync_completed` audit record.

No delivery after the gap is projected before this call. Events appended after
the snapshot watermark remain eligible and must be contiguous with the rebuilt
watermark.

### Audit and metrics

Expose a read-only `privateWsAudit()` snapshot from the state store and client:

- `acknowledged_total`;
- `duplicate_total`;
- `gap_total`;
- `conflict_total`;
- `resync_total`;
- current connection state and reason;
- last acknowledged/observed sequence;
- bounded records containing kind, sequence, expected/actual sequence when
  relevant, fixture ID, and fingerprint prefix.

Records contain no raw payload, credential, URL, or request data. Tests assert
counter persistence and deterministic record content.

## Errors and Fail-Closed Rules

- Exact duplicate: no exception, no normalization, no projection, audited once
  per duplicate delivery.
- Future numeric sequence: existing `fake_private_ws_sequence_gap`, persisted
  `resync_required`.
- Same sequence with different fingerprint:
  `fake_private_ws_sequence_conflict`, persisted `resync_required`.
- Drain while a snapshot resync is required:
  `fake_private_ws_snapshot_resync_required`.
- Plain reconnect after gap or conflict: rejected; only completed snapshot
  reconciliation may clear the state.
- Invalid or non-finite fixture: rejected at configuration/hydration; an empty
  fixture is allowed and yields nothing.
- No timestamp-only sort or implicit repair.

## Golden Scenario 16

Add a persistable JSON fixture whose finite delivery order proves:

1. sequence 1 is projected;
2. sequence 1 is delivered again and deduplicated;
3. sequence 3 creates a gap before projection;
4. sequence 2 remains unprojected while resync is required;
5. simulated REST reconciliation plus snapshot completion rebuilds canonical
   state without duplicate projections;
6. a newly appended contiguous event resumes normally;
7. the complete normalized result is identical across two fresh runs.

A separate conflict fixture reuses a sequence with a changed payload and proves
the explicit failure code and persisted audit state. The golden catalogue entry
becomes `executable`, its gap list becomes empty, and the runner reports
deterministic duplicate/gap/resync evidence.

## Test Strategy

Use TDD and retain all #274 regressions.

- Exact duplicate is skipped before normalization/projection.
- Delivery order `1,3,2` requires snapshot resync and projects nothing after
  the gap before reconciliation.
- Same sequence with a conflicting payload fails explicitly.
- Client destruction before acknowledgement retries the same event after
  restart.
- Projection rollback retries without duplicate business projections.
- Multiple normalized projections from one raw event remain atomic.
- File-backed restart preserves `resync_required`, cursor, fingerprints,
  counters, and records.
- Snapshot completion skips covered fixture entries and resumes at the next
  contiguous sequence.
- Golden scenario 16 executes twice with identical output.
- Legacy persisted state without private-WS fields still restores.

Targeted validation covers Fake WS/state/golden tests. Broad validation covers
Exchange, Fake providers, TradingCore, and TradeEntry execution, followed by
targeted PHPStan, Symfony container lint when DI changes, JSON fixture parsing,
MkDocs strict, secret/redaction scans, mainnet-mutation scans, and
`git diff --check`.

## Rollback

Revert the atomic Prompt 3 PR. Existing state files remain readable because the
new private-WS payload fields are additive; after rollback they are ignored by
the older hydrate path. No exchange activation or external mutation requires
cleanup.
