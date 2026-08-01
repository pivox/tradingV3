# Paper Execution Coordinator Design

## Status and references

- Status: approved design
- Date: 2026-08-01
- Primary issue: #132
- Depends on: Paper network provenance, OKX public Paper source, Hyperliquid
  public historical and live Paper sources
- Followed by: #300, #301, #310, #133 and #302
- Explicitly out of scope: Bitmart changes, strategy tuning, modern-mode
  definitions, population generation and the final #132 export

## Problem

The Paper market-data chain can now record and replay public OKX and
Hyperliquid inputs with explicit network provenance. The Fake exchange also
provides deterministic matching, fills, protection, funding, liquidation and
fault behavior. There is not yet one durable coordinator that connects those
two sides while preserving the complete Paper execution identity.

Directly invoking the Fake adapter is insufficient because a certified Paper
run must establish, before any local execution effect, exactly which network,
market-data venue, immutable configuration, strategy profile and run produced
the effect. It must also recover safely across every database/adapter crash
window without duplicating an order or silently skipping a market event.

## Goals

1. Introduce `PaperExecutionCoordinator` as the only application service that
   turns a normalized Paper market event into a local strategy/execution effect.
2. Make every run belong to one explicit execution cell:

   ```text
   network + market_data_venue + configuration_snapshot_id +
   strategy_profile + run_id
   ```

3. Store immutable, content-addressed configuration snapshots.
4. Route execution exclusively to `FakeExchangeAdapter`.
5. Persist OrderIntent, lineage, lifecycle, fills, costs and Paper provenance in
   a dedicated PostgreSQL Paper database.
6. Provide durable append, idempotent effect, acknowledgement, restart and a
   persistent cell-scoped kill switch.
7. Produce identical durable results for an uninterrupted run and for the same
   run interrupted at any supported recovery boundary.
8. Mark executions using current legacy profiles as `reference_only` and keep
   them outside the future modern baseline.

## Non-goals

- No exchange order, cancellation, position or account call.
- No credential, wallet, signer, signature or private client resolution.
- No fallback from one market-data network or venue to another.
- No aliases, default profile or inferred execution cell field.
- No definition of modern modes, setups or `crash_short` in this change.
- No change to strategy thresholds, sizing, leverage, EntryZone or SL/TP.
- No generation or aggregation of certified trade populations.
- No direct insertion into analytics views.
- No Bitmart source, inventory, runtime or removal work.

## Terminology

- `configuration snapshot`: immutable canonical effective Paper input accepted
  by the coordinator, excluding all secrets.
- `execution cell`: the exact five-dimensional identity listed above.
- `cell ID`: deterministic SHA-256 identity of the canonical execution-cell
  envelope.
- `account namespace`: Fake account/state namespace derived from the complete
  cell ID.
- `source position`: durable capture/replay ordinal consumed by the
  coordinator. It is distinct from venue sequence numbers scoped per stream.
- `journal event`: immutable record of a coordinator fact or requested effect.
- `effect key`: deterministic idempotency key passed to the Fake adapter.
- `reference_only`: technically executable and auditable, but ineligible for a
  modern certified baseline.

## Chosen architecture

Use a transactional append-only journal plus an idempotent effect state
machine. This is smaller than converting the application to full event
sourcing, while closing the crash window left by direct ORM orchestration.

The coordinator owns validation and state transitions. Existing strategy,
provider, OrderIntent, lineage and Fake exchange services remain responsible
for their current domain behavior. They may only be reached through a
coordinator-created context carrying the full cell identity.

The high-level flow is:

```text
verified Paper event
  -> validate cell and source position
  -> persist input + intent/lineage + pending effect atomically
  -> apply deterministic Fake effect
  -> persist acknowledgement + projections atomically
  -> advance durable checkpoint
```

No network client belongs to this graph. Market acquisition remains upstream
of the coordinator, and the execution adapter is always Fake.

### Deterministic prudent plan model

Reference-only Paper decisions use the versioned `paper-prudent-plan-v1`
preparation model. It has no account, credential, HTTP, lock or exchange-state
dependency: its only price inputs are the normalized decision candle and the
explicit execution cell. Entry is the candle close; risk is the greater of the
full candle range and 0.5% of entry; stop and take profit are symmetric at one
risk unit; and base size and leverage are both one.

The standard order-mode and execution-timeframe plan preparation is then
applied exactly once before Phase 1 persists the prepared intent. Phase 2
dispatches those exact durable plan bytes to the Fake adapter and rejects any
effect that did not pass that preparation marker. This model remains
`reference_only`; it validates coordinator mechanics and does not define or
tune a modern baseline strategy.

## Immutable configuration snapshots

### Canonical envelope

`PaperConfigurationSnapshot` stores:

- snapshot schema version;
- normalized effective strategy/runtime configuration required by Paper;
- model/version references used by Fake execution;
- creation timestamp as metadata outside the hashed content.

The snapshot builder accepts a typed, allowlisted structure. It rejects unknown
top-level sections and recursively rejects credential-, wallet-, signer-,
signature-, token-, passphrase- and private-key-shaped keys as a second line of
defense. It does not copy environment variables or resolved service secrets.

Canonical JSON rules are shared with the existing Paper canonicalizer: object
keys are sorted recursively, list order is preserved, scalar types are not
coerced, unsupported numbers are rejected and UTF-8 is required.

The identifier is:

```text
configuration_snapshot_id = "sha256:" +
  sha256(canonical_json({schema_version, configuration}))
```

The same semantic input therefore produces the same ID regardless of input key
order. Inserting an existing ID with identical bytes is idempotent. Existing ID
with different canonical bytes is corruption and fails closed. Snapshots cannot
be updated or deleted through the coordinator.

### Relationship to later configuration work

This change defines the immutable consumer contract, not the final producer of
effective configuration. Issues #133/#302 may replace how the canonical input
is assembled, but must produce this same snapshot contract. They must not make
the coordinator accept mutable references or implicit defaults.

## Execution-cell identity

`PaperExecutionCell` requires all five fields at construction:

- `PaperMarketDataNetwork`, explicitly `mainnet` or `testnet`;
- canonical `PaperMarketDataVenue`;
- `configuration_snapshot_id` resolving to an existing snapshot;
- canonical `strategy_profile` registered by exact name;
- non-empty caller-supplied `run_id`.

Aliases and case normalization are not accepted at this boundary. Missing
fields fail before a database write or Fake state lookup.

The cell ID is a SHA-256 digest of a versioned canonical envelope containing
the five fields. The Fake account namespace is derived from the entire cell ID,
not from a partial human-readable tuple:

```text
paper:cell:v1:<cell_digest>
```

This isolates balance, One-Way state, open orders, positions, protection state,
funding and liquidation state across every network, venue, snapshot, profile
and run combination.

A persisted cell is immutable. A request attempting to reuse a `run_id` with a
different cell tuple is rejected as an identity conflict.

## Profile eligibility

The profile registry returns an explicit eligibility classification; there is
no default profile and unknown names are rejected.

For this change, every currently executable legacy profile, including
`regular`, `scalper` and `scalper_micro`, is `reference_only`. The classification
is persisted on the cell and copied to durable trade provenance. It cannot be
promoted by a runtime request.

Modern baseline eligibility remains disabled until the explicit contracts from
#300/#301/#310 exist. A reference-only run may validate the coordinator and
recovery chain, but reports and population builders must exclude it from modern
baseline aggregation.

## Persistence model

### Paper tables

Add the following Paper-owned durable records:

1. `paper_configuration_snapshot`
   - content-addressed primary ID;
   - schema version;
   - canonical JSON and content checksum;
   - non-hashed creation timestamp.
2. `paper_execution_cell`
   - cell ID and all five source fields;
   - account namespace;
   - eligibility classification;
   - creation timestamp and terminal state.
3. `paper_execution_event`
   - cell ID;
   - monotonically allocated journal ordinal;
   - stable event type;
   - source position and source event ID when applicable;
   - effect key when applicable;
   - canonical payload and checksum;
   - append timestamp.
4. `paper_execution_checkpoint`
   - rebuildable projection of the last acknowledged source position,
     journal ordinal and journal checksum;
   - optimistic-lock version;
   - current kill-switch state.

The event journal is append-only at the application and database-permission
boundary. State changes such as `effect_requested`, `effect_acknowledged`,
`cell_killed` and `cell_resumed` append new facts. They do not rewrite prior
facts. The checkpoint is a mutable projection and is validated against the
journal before restart.

### Existing trade facts

Additive migrations propagate these fields through the minimum durable facts:

- `paper_network`;
- `paper_execution_cell_id`;
- `configuration_snapshot_id`;
- `paper_eligibility`.

They apply to OrderIntent, trade lineage, lifecycle events, fills/cost ledger
and trade-zone events where present. `exchange` remains `fake`, while
`market_data_venue` remains the public data venue.

Existing rows remain readable. New columns may be nullable at the database
compatibility boundary, but coordinator-created rows require all modern Paper
provenance fields. Rows without them are legacy/unknown and cannot become a new
baseline silently.

## Ordering, duplicates, gaps and corruption

The coordinator consumes the recorder/replay source position, not raw exchange
sequence values from unrelated channels.

For each cell, the next position is exact:

- expected next position: process normally;
- already journaled event ID with the same payload checksum: acknowledge as an
  idempotent duplicate without strategy or Fake effect;
- already journaled event ID with a different checksum: corruption;
- lower unrecognized position: out-of-order corruption;
- higher position: gap, with no processing or checkpoint advance.

Gap, conflict and checkpoint-checksum failures stop the cell. They cannot be
waived, reordered or skipped by a fallback. Recovery may resume only after the
same missing position is supplied or an operator starts a new explicit cell.

## Transaction and effect protocol

### Phase 1: durable request

Within one PostgreSQL transaction, the coordinator:

1. locks and validates the cell checkpoint;
2. appends the accepted source event;
3. advances the controlled provider/strategy path;
4. persists any OrderIntent and lineage with full cell provenance;
5. appends an `effect_requested` event with a deterministic effect key;
6. commits without yet considering the effect acknowledged.

An event that produces no order still receives a durable processed fact and an
acknowledged source position.

### Phase 2: idempotent Fake effect

After Phase 1 commits, pending effects are dispatched only through an injected
`FakeExchangeAdapter`. Construction fails if the adapter reports any other
exchange.

The effect key is derived from the versioned cell ID, durable intent identity,
effect type and effect ordinal. The Fake state store uses the cell account
namespace and effect key to return the original result for retries. It must not
perform a second balance, order, fill or position mutation.

Matching, fills, protections, funding and liquidation triggered by market
events follow the same deterministic key rule.

### Phase 3: acknowledgement and projections

Within a second PostgreSQL transaction, the coordinator:

1. appends the adapter result as `effect_acknowledged`;
2. persists lifecycle, fill and cost projections with full provenance;
3. appends the source acknowledgement;
4. advances the checkpoint and checksum;
5. commits.

If the process crashes after the Fake effect but before Phase 3, restart finds
the pending durable request, retries the same effect key, receives the same
result and completes Phase 3 once. If it crashes during either database
transaction, PostgreSQL atomicity leaves that phase entirely committed or
entirely absent.

## Restart and replay equality

On startup, the coordinator:

1. runs the Paper database and migration guards;
2. loads the explicit cell and immutable snapshot;
3. recomputes cell ID, namespace and snapshot checksum;
4. validates the checkpoint against the journal tail;
5. completes pending effects in journal order;
6. requests exactly the next unacknowledged source position.

Replay equality compares stable durable content, excluding operational append
timestamps. Given the same complete cell tuple in isolated test database
instances, the uninterrupted and crash/restart scenarios must produce the same
normalized intents, effect sequence, lifecycle, fills, costs and final
checkpoint state.

No startup path may infer a missing venue/profile, switch a network, load a
different configuration snapshot or reset Fake state.

## Kill switch

The kill switch is explicit, durable and cell-scoped.

Activation appends `cell_killed` and updates the checkpoint projection in one
transaction. Once active:

- new strategy decisions and new order effects are rejected;
- source consumption does not advance past an event requiring a new effect;
- already requested effects may be reconciled and acknowledged so recovery
  remains consistent;
- audit and read-only replay verification remain available;
- no exchange or implicit Fake cancellation is performed.

Restart preserves the killed state. Resume requires an explicit command that
appends `cell_resumed`; constructing a new coordinator does not resume it.

## Runtime and database safety

The existing `PaperRuntimeGuard` remains mandatory and is strengthened by the
cell contract:

- `execution_mode=paper`;
- resolved execution exchange exactly `fake`;
- Paper execution explicitly enabled;
- mainnet and demo/testnet exchange writes disabled;
- only BTCUSDT and ETHUSDT in this scope;
- explicit network and market-data venue match every input event;
- no venue fallback or execution gateway lookup after startup.

The coordinator graph must not contain an HTTP/WS exchange client, credential
provider, signer or wallet service. Tests use sentinels that fail immediately
if any such dependency is resolved or called.

The database must be exactly `trading_paper` outside tests. Integration tests
must use a dedicated database whose name ends in `_paper_test`. The guard also
requires all migrations to be applied. Normal development and production
databases are rejected before cell or snapshot persistence.

## Error handling and observability

Failures expose stable reason codes and identifiers, never raw payloads,
credentials, URLs or adapter exception text. Logs may include:

- cell ID;
- snapshot ID;
- network and market-data venue;
- source position and journal ordinal;
- safe event/effect type;
- eligibility;
- stable failure code.

Expected failure classes include invalid cell, snapshot conflict, forbidden
configuration field, provenance mismatch, duplicate conflict, out-of-order,
gap, checkpoint corruption, non-Fake adapter, killed cell and Paper database
violation.

Metrics separate requested, acknowledged, retried and failed effects. A retry
is not counted as a second order or fill.

## Testing strategy

Implementation follows test-driven development with local fixtures and no real
network.

### Unit and contract tests

- canonical snapshot equality across key ordering;
- scalar/list sensitivity and rejected unsafe fields;
- deterministic snapshot, cell, namespace and effect identities;
- exact profile/network/venue parsing without aliases or defaults;
- all current profiles classified `reference_only`;
- duplicate, conflicting duplicate, out-of-order and gap decisions;
- journal/checkpoint checksum verification;
- kill/resume state transitions;
- construction refusal for any non-Fake adapter.

### PostgreSQL integration tests

- additive migrations and provenance constraints;
- `_paper_test` guard and rejection of every other database name;
- atomic Phase 1 and Phase 3 rollbacks;
- crash injection before and after both commits and immediately after the Fake
  effect;
- pending-effect recovery with exactly one Fake mutation;
- checkpoint restart and corruption refusal;
- cell namespace isolation across each identity dimension;
- complete OrderIntent, lineage, lifecycle, fill, cost and network provenance.

### End-to-end replay tests

Small checked-in OKX and Hyperliquid mainnet/testnet fixtures run through the
common Paper source contract. Tests assert:

- capture/replay equality;
- no mixed-network cell or dataset;
- no venue fallback;
- uninterrupted versus restarted durable equality;
- zero credential, wallet, signature, exchange client and network calls;
- reference-only exclusion from modern baseline eligibility.

## Delivery and validation

The implementation is delivered on
`issue/132-paper-execution-coordinator`. Before review:

- targeted PHPUnit tests pass;
- the complete PHPUnit suite passes;
- PHPStan passes;
- Symfony container and YAML lint pass;
- MkDocs strict build passes;
- `git diff --check` passes;
- tests prove the PostgreSQL database name ends in `_paper_test`;
- no real network is used in CI.

The PR receives one GitHub Codex review request. All actionable threads are
fixed and resolved, fixes are pushed, CI is rerun on the new HEAD, and the PR
is merged only when no blocking thread remains.

## Acceptance criteria

This design is complete when one explicit Paper execution cell can process a
local verified event stream through the existing strategy path and
`FakeExchangeAdapter`, persist the full durable trade chain, survive every
defined restart boundary without duplicate effects, enforce its kill switch,
and remain ineligible for the modern baseline while using a legacy profile.

That result unlocks the modern mode/setup/configuration contracts. It does not
itself authorize population generation or the final #132 analysis.
