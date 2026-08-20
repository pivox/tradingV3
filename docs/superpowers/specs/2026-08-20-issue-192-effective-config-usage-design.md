# Issue #192 — Effective config usage links

## Goal

Expose the immutable effective-config snapshot actually used by an orchestration run,
set, trading decision, or trade. The lookup must use canonical lineage persisted by #189
and must never resolve current YAML or infer an association from symbol or time.

This is the backend usage-link lot from #192. The Front Ops presentation remains owned by
#318.

## Selected approach

Add a dedicated DBAL read store over `trade_lineage`, `order_intent`, and
`trade_lifecycle_event`, then let a domain service validate and resolve the referenced
snapshot through `EffectiveConfigSnapshotRegistryInterface`.

Alternatives rejected:

- Reusing `LineageReadService`: its page limits are appropriate for navigation but could
  silently truncate aggregate usage.
- Trusting embedded `effective_config_snapshot` JSON: this would create a second historical
  source of truth and bypass the immutable registry checksum checks.

## API

The following read-only routes return the same envelope:

- `GET /api/orchestration/runs/{run_id}/effective-config`
- `GET /api/orchestration/sets/{set_id}/effective-config`
- `GET /api/trading/decisions/{decision_id}/effective-config`
- `GET /api/trades/{trade_id}/effective-config`

The envelope contains:

```json
{
  "scope": "run",
  "identifier": "run-123",
  "count": 1,
  "snapshots": [
    {
      "snapshot": {"document_kind": "historical_snapshot"},
      "usage": {
        "lineages": 1,
        "order_intents": 1,
        "lifecycle_events": 3,
        "decision_ids": 1,
        "trade_ids": 1,
        "internal_trade_ids": 1
      }
    }
  ]
}
```

Runs and sets may legitimately contain multiple effective configs. Decisions and trades
must resolve to exactly one snapshot; multiple distinct references are a conflict.

`trade_id` means the canonical execution trade identifier in `order_intent` and
`trade_lifecycle_event`. `trade_lineage.internal_trade_id` is also an exact match source,
because #189 defines it as the persistent internal trade identity. No venue trade ID or
timestamp fallback is allowed.

## Data flow and validation

1. Validate that the route identifier is non-empty and fits the persistent column length.
2. Query all three canonical lineage tables with an exact equality predicate.
3. If no rows exist, return `404 effective_config_usage_not_found`.
4. Require every matching row to contain an exact reference of the form
   `effective-config-snapshot:sha256:<64 lowercase hexadecimal characters>`.
5. If any matching row lacks a valid reference, return
   `422 effective_config_reference_missing` rather than returning a partial result.
6. Group rows by snapshot hash and aggregate explicitly named source/entity counts.
7. Resolve every hash through the immutable snapshot registry. A missing registry record is
   a `409 effective_config_snapshot_unregistered` integrity conflict.
8. Require every row to carry a canonical `config_hash` equal to the stored document
   `config_hash`. Missing, malformed, or mismatched hashes return
   `409 effective_config_hash_conflict`.
9. For decision/trade scopes, reject more than one distinct snapshot with
   `409 effective_config_usage_conflict`.
10. Return only the registry's redacted historical document. Embedded lineage JSON is never
    returned or trusted.

Duplicate observations across tables are not collapsed because their meaning is explicit:
lineages, order intents, and lifecycle events are separate counters. Identity counters use
distinct non-empty values within one snapshot group.

## Components

- `EffectiveConfigUsageCriteria`: validated scope and identifier, plus the exact store
  predicate for that scope.
- `EffectiveConfigUsageFact`: one normalized, immutable persistence observation.
- `EffectiveConfigUsageStoreInterface`: streams every matching fact without pagination.
- `DoctrineEffectiveConfigUsageStore`: executes fixed, parameterized `UNION ALL` queries
  through a forward-only DBAL iterator.
- `EffectiveConfigUsageReadService`: fail-closed validation, grouping, registry lookup, and
  response assembly.
- `EffectiveConfigUsageApiController`: the four routes and stable HTTP error mapping.

The store owns SQL only. The service validates and aggregates each streamed fact immediately;
it retains counters and distinct canonical identities, never the event history. Memory is
therefore bounded by the response's distinct snapshots/identities rather than lifecycle-event
cardinality. The controller owns only HTTP translation.

## Indexes and migration safety

Add PostgreSQL concurrent partial indexes for lookup columns not already covered as a
leading index column:

- run and set identifiers;
- decision identifiers;
- canonical trade identifiers;
- effective-config references only if reverse usage lookup is introduced later.

Existing valid leading indexes are not duplicated. The migration is non-transactional and
uses the established invalid-index recovery pattern from `Version20260801093000`.

This lot intentionally does not add reverse snapshot usage lookup; #318 can consume the four
identity routes, and a reverse listing can be added with its own bounded pagination contract.

## Tests

- Unit service tests cover one snapshot, multiple run/set snapshots, distinct counters,
  malformed/missing references, unregistered snapshots, config-hash mismatch, and
  decision/trade ambiguity.
- Controller tests cover all routes and the 400/404/409/422 mappings.
- DBAL integration tests prove exact matching and aggregation input across all three tables
  on PostgreSQL.
- Migration tests verify concurrent/non-transactional index DDL and rollback declarations.
- Existing effective-config history, lineage, container, static analysis, and schema gates
  remain green.

## Scope boundaries

- Read-only; no config editing or activation.
- No current-config re-resolution.
- No legacy alias, symbol/time inference, or partial success.
- No Front Ops UI (#318).
- No mainnet private execution.
