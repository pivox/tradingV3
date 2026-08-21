# Issue #196 canonical Paper strategy-input boundary

## Scope

This slice defines the strict boundary between verified Paper evidence and the
existing canonical Shadow runtime. It does not source indicator windows,
instrument metadata, costs, or portfolio state and it does not enable modern
Paper replay.

## Contract

`PaperCanonicalStrategyEvidence` carries the canonical runtime inputs without
converting them through `PreparedTradeEntry` or `OrderPlanModel`. Indicator
evidence is the validated `CanonicalIndicatorProjection` object rather than an
unbound snapshot map. It also carries the exact source Paper dataset ID and
event-file checksum selected by the evidence provider. The assembler preserves
those objects unchanged and binds them to the modern Paper cell, the triggering
market event and the coordinator-owned replay identity.

Before the canonical runtime can receive the request, the assembler requires:

- an exact v2 Paper cell identity and Paper execution capability;
- exact mode, setup, versions, side, config hash and condition-catalog hash;
- public venue/network parity across config, lineage, policy and portfolio;
- the cell account namespace and run identifier;
- one decision key across lineage and request;
- an executable immutable effective-config snapshot;
- the exact configured execution-timeframe candle, confirmed and source-bound;
- `snapshot_identity.exchange = fake` with `environment = local|test`, kept
  distinct from the projection dataset's public venue and network provenance;
- exact dataset `source_network`, `market_data_venue`, perpetual market, symbol
  and execution-candle opening time parity with the triggering event;
- exact Paper dataset ID and event-file checksum parity between the coordinator
  request and provider evidence;
- `dataset_binding.source_checksum = sha256:<events_file_sha256>`, binding the
  projected history to the exact verified Paper event bytes;
- the event symbol, perpetual market and canonical plan identity to match.

Missing evidence returns no input. Identity, provenance or trigger drift raises
a stable fail-closed reason. No default price, spread, slippage, indicator,
instrument, balance or portfolio value is synthesized.

The projection's `dataset_id`/`dataset_checksum` pair identifies the derived
backtest artifact and remains distinct from the recorder-owned Paper dataset
ID. The bridge therefore does not equate those two IDs. The raw content link is
the source checksum copied by `PaperBacktestDatasetAdapter` from the verified
Paper manifest's `events_file_sha256`.

## Production safety

The evidence-provider interface intentionally has no production implementation
in this slice. `PaperExecutionCoordinator` therefore keeps
`$canonicalStrategy: null`, and modern replay remains blocked by
`paper_modern_strategy_bridge_unavailable`. When that strategy is supplied,
the coordinator passes the dataset ID and event-file checksum already bound in
its durable execution store; a conflicting caller dataset ID fails closed
before canonical preparation.

The next slice must build the evidence from verified indicator windows, public
market snapshots and private Fake/Paper risk state. It must also bridge the
projector's `fake/local|test` execution identity to the Paper Shadow evaluation
contract without rewriting or losing the separate public dataset provenance,
then emit the durable canonical effect already supported by the coordinator.
Only that complete source may be wired into production.
