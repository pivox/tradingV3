# Canonical effective trading configuration

Issue #133 replaces the COMMON-002 preparatory deep merge with a strict runtime
boundary. A request is identified by all of these exact values:

- `mode_id` and semantic `mode_version`;
- `setup_id` and semantic `setup_version`;
- `exchange`, `environment`, and `side`.

Aliases, version ranges, inferred defaults, and fallback profiles are rejected.
In particular, `regular`, `scalper`, `scalper_micro`, BitMart, and the historical
`scalping -> scalper` mapping are not modern effective-config identities.

## Contract and layer order

The resolver first loads and validates the immutable mode and setup contracts. It
requires bidirectional compatibility at the exact versions, identical setup and
request sides, an executable mode/setup, a publishable compiled setup snapshot,
and a resolved condition-catalog SHA-256.

Only then may it compose these six mandatory layers, in this exact order:

```text
base < mode < setup < exchange < mode_exchange < environment
```

There are no optional runtime layers and no `missing_optional_layers` result.
The mode layer is the validated mode contract. The setup layer is the canonical
compiler snapshot: it carries the full recursive AST (including confirmations,
filters, no-trade rules, and every execution decision), missing-data and typed
condition contracts, exact versions and hashes, source pins, contract provenance,
and blockers. It does not reconstruct a lossy subset from the raw YAML.
Historical compatibility pointers are provenance only and are never imports. The remaining
files are loaded from `config/trading`. A future executable pair file is named
`mode_exchange/{mode_id}.{mode_version}.{exchange}.yaml`, so an override cannot
float across mode versions.

Composition is ownership-aware rather than a generic deep merge:

- base owns schema, units, and ultimate safety guards;
- mode owns the mode envelope and risk decisions;
- setup owns hypothesis, side, regime/context/trigger, invalidation, entry zone,
  stop, and targets;
- exchange owns capabilities, fees, funding, precision, and limits;
- mode/exchange owns only a finite allowlist of typed override paths;
- environment owns allowlists, notional, dry-run/write gates, and kill switch.

Wrong-owner and unknown keys, duplicate ownership, missing targets, and
list/scalar type changes fail closed. The resulting readonly
`EffectiveTradingConfigSnapshot` exposes normalized JSON-compatible data, a
stable SHA-256, exact identities, condition-catalog hash, ordered source files,
and leaf provenance. Array accessors return copies, so callers cannot mutate the
snapshot.

The compiler also hashes the complete canonical setup payload. Composition
verifies that hash before trusting any setup field, including the recursive AST
and condition identities; changing even one nested decision invalidates the
snapshot.

Mode/exchange overrides are deliberately narrow and field-specific:

- trade budget, exposure cap, leverage, concurrency, and both daily-loss-cap
  amounts may only stay equal or decrease, and must remain finite and
  non-negative;
- daily-loss-cap currency cannot change, and order policy cannot be altered;
- maker/taker rates must be finite values from zero through one;
- funding interval must remain an ISO-8601 duration.

No pair override may loosen risk, notional, concurrency, loss, order, or safety
policy. A structured override replaces provenance recursively: previous parent
and descendant ownership is cleared and every resulting leaf is attributed to
the mode/exchange layer.

## Current execution status and safety

The published #300/#301/#310 contracts remain draft or blocked and unresolved.
They therefore produce a structured non-executable result; no strategy values
were invented to make this boundary runnable. `crash_short@1.1.0` has no
compatible modern mode and is rejected before execution.

Supported modern venue targets are fake local/test, OKX demo, and Hyperliquid
testnet. Mainnet may remain public/read-only, but effective execution keeps
`mainnet_write_enabled=false`. Every #133 environment, including demo/testnet,
must declare `write_enabled=false`, `require_stop_loss=true`, and an active kill
switch. Every exchange layer must declare `capabilities.stop_loss=true`. Base
safety also requires `demo_testnet_write_enabled=false`,
`require_stop_loss=true`, and `kill_switch_enabled=true`. Activation belongs to
a later issue; #133 never enables writes. No secrets belong in any layer.

`GET /api/trading/config/effective` requires all seven identity query fields.
Known blocked contracts return HTTP 422 with `executable=false`, blockers, and no
config/hash. Unknown or mismatched identity returns a structured HTTP 400. A
successful future response includes the hash, ordered layers/files, and
provenance.

## Immutable history

Every successful production resolution is registered before the resolver returns
its unchanged runtime snapshot. Registration is fail-closed and idempotent: an
exact replay is accepted, while a reused snapshot hash with different identity,
content, or checksum is rejected. The dedicated
`effective_trading_config_snapshot` table is append-only; PostgreSQL rejects both
updates and deletes.

`snapshot_hash` identifies one exact historical document, including provenance.
`config_hash` identifies its effective configuration values, so several
provenance-distinct snapshots may legitimately share one config hash. Both use
the exact canonical form `sha256:` followed by 64 lowercase hexadecimal
characters.

The read-only API exposes:

```text
GET /api/trading/config/effective/snapshots/{snapshot_hash}
GET /api/trading/config/effective/snapshots?config_hash={config_hash}
GET /api/trading/config/effective/diff?left={snapshot_hash}&right={snapshot_hash}
```

For example, a fake/test preview and an exact historical read can be requested
without enabling any private exchange operation:

```bash
curl 'http://localhost:8082/api/trading/config/effective?mode_id=day_trading&mode_version=1.1.0&setup_id=day_trading.trend_continuation.long&setup_version=1.1.0&exchange=fake&environment=test&side=long&execution_capability=fake'
curl 'http://localhost:8082/api/trading/config/effective/snapshots/sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
```

One recursive redactor protects database writes and every successful JSON
response. Sensitive key variants and credential-bearing DSNs are replaced with
`***REDACTED***`; their paths are retained without their values. Historical
reads return only the stored redacted document and never reload or merge current
YAML.

Diffs compare exact snapshot hashes, flatten configuration paths, sort them
lexically, and classify details as `added`, `removed`, `changed`, or
`same_but_different_source`. Unchanged paths are counted but omitted. The future
`invalidated` state, persisted warning/invalid snapshots, and the Front Ops UI
remain deferred. Mainnet private execution remains forbidden.

## Usage navigation

The immutable snapshot actually used by a canonical runtime identity is available
without resolving current YAML:

```text
GET /api/orchestration/runs/{run_id}/effective-config
GET /api/orchestration/sets/{set_id}/effective-config
GET /api/trading/decisions/{decision_id}/effective-config
GET /api/trades/{trade_id}/effective-config
```

The reader performs exact equality lookups over `trade_lineage`, `order_intent`,
and `trade_lifecycle_event`. For a trade, canonical `trade_id` and persistent
`internal_trade_id` are exact sources; venue trade IDs, symbols, and time windows
are never inferred. Run and set responses may contain several snapshots because
their cells can use distinct effective configurations. One decision or trade must
resolve to a single snapshot.

Every matching lineage row must carry
`effective-config-snapshot:sha256:<64 lowercase hex>` and a canonical `config_hash`
matching the immutable registry document. Rows are streamed and aggregated without
materializing the lifecycle-event history. Responses include only
the stored redacted historical document plus explicit lineage/order-intent/event
and distinct identity counts. Failures are closed and stable:

- `400 invalid_effective_config_usage_identifier` for an invalid route identity;
- `404 effective_config_usage_not_found` when no canonical fact exists;
- `422 effective_config_reference_missing` when any matching fact lacks a valid
  reference;
- `409 effective_config_usage_conflict` for an ambiguous decision or trade;
- `409 effective_config_snapshot_unregistered` for a dangling reference;
- `409 effective_config_hash_conflict` when a lineage hash is missing, malformed,
  inconsistent, or disagrees with the registry.

The Front Ops links and presentation of these responses remain owned by #318.

## Legacy quarantine and migration

`TradeEntryConfigProvider` and `MtfValidationConfigProvider` remain available
only for explicitly historical IDs. They reject modern IDs before opening a
legacy YAML file. `CanonicalTradingConfigRuntimeAdapter` is the immediate shared
MTF/TradeEntry request boundary and returns the same snapshot/hash to both sides;
full outcome lineage remains #302.

Callers that currently possess only a legacy profile cannot manufacture missing
mode/setup versions or side. They remain fail closed until their request DTO is
migrated. This is intentional and prevents `trade_entry.yaml`, generic MTF YAML,
or BitMart from becoming an implicit runtime fallback.

Rollback is a code rollback: revert the #133 wiring commit and redeploy. Do not
restore service by adding runtime fallback, aliases, optional layers, or by
editing published contract status/thresholds.
