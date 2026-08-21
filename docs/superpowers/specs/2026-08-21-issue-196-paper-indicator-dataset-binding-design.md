# Issue #196 canonical Paper indicator dataset binding

## Scope

This slice turns the already validated Paper indicator windows into the exact
dataset binding required by `CanonicalIndicatorProjector`. It ports the small,
pure checksum graph owned by the Python backtest dataset v1 contract; it does
not invoke Python at runtime and does not create a second checksum convention.

The slice also adds an unwired projection source that composes the existing
window source, the binding builder and the canonical PHP projector. It does not
implement the complete strategy evidence provider and does not activate modern
Paper replay.

## Contract

The derived dataset contains exactly the native candle records visible in the
selected indicator windows. Its source identity remains the complete verified
Paper dataset:

- `source = paper_market_dataset`;
- `source_schema_version = paper-market-dataset.v2`;
- `source_build_version` is the exact recorder version supplied by the
  verified manifest;
- `source_checksum = sha256:<events_file_sha256>`;
- network, public venue and perpetual market match the modern Paper cell and
  trigger.

The builder independently validates every native candle, source identity,
stream order, uniqueness and continuity. It then reproduces the Python v1
artifacts in canonical order:

1. `candles.ndjson`, with one canonical record and one newline per row;
2. `quality-report.json`, with exact contiguous stream coverage and one final
   newline;
3. the manifest core containing build/schema/source/coverage/quality facts;
4. `dataset_checksum`, the SHA-256 of the canonical object containing that
   manifest core plus both artifact checksums; and
5. `dataset_id = backtest-dataset-<dataset checksum hex>`.

Only the eight projector binding fields are exposed. The recorder-owned Paper
dataset ID remains separate and is not rewritten as the derived backtest
dataset ID. A cross-runtime golden vector fixes byte-for-byte parity with
`python-orchestrator.app.backtesting.DatasetBuilder` and `DatasetSerializer`.

## Projection boundary

`PaperCanonicalIndicatorProjectionSource` receives the exact source build
version and raw events-file SHA-256 that a future verified evidence provider
will obtain from the dataset manifest. It requests the no-lookahead windows at
the shared replay clock, builds their binding, and calls the existing canonical
PHP projector with `fake` snapshot identity in `local` or `test` only.

Missing history returns no projection. Invalid source facts, discontinuous or
duplicate records, projector drift and unsupported environments fail closed.
The service is deliberately not injected into `PaperExecutionCoordinator` in
this slice, so `paper_modern_strategy_bridge_unavailable` remains the runtime
gate and no exchange write path is enabled.
