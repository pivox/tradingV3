# Canonical trading identity core (#302)

## Implemented contract

Modern orchestrated requests may carry one immutable `trading_identity` containing
`mode_id`, `mode_version`, `setup_id`, `setup_version`, `config_hash`,
`condition_catalog_hash`, `side`, and `effective_config_reference`. Python validates
the identifiers and SHA-256 values in a frozen Pydantic model. Symfony merges that
configuration identity with the explicit run, set, exchange, market, and symbol at
the HTTP boundary and transports a readonly `LineageContext` through the runner and
Messenger.

Canonical parsing is fail-closed. Missing required values use
`canonical_identity_missing:<field>`; contradictions use
`canonical_identity_mismatch:<field>`. Requested/resolved/validated mode and version,
side, config hash, setup version, and condition-catalog hash must equal their canonical
value when present. No canonical mode is derived from `profile`, `mtf_profile`, an
enabled mode, or `app.trade_entry_default_mode`.

`withDecision()`, `withIntent()`, and `withExecution()` create a new context while
preserving all prior values and hashes. `toArray()`/`fromArray()` are symmetric and do
not emit mutable profile aliases for modern identities. Messenger logs use the
redacted representation only.

`trade_lineage` stores the canonical configuration and decision fields in structured
columns. The migration deliberately keeps them nullable for legacy rows and performs
no backfill from symbol, time, profile, or JSON metadata. New modern writes are checked
before persistence. The partial unique decision index prevents two durable modern
lineages from claiming one decision.

## Migration and rollback

Apply `Version20260801090000`. It adds nullable columns plus
`ux_trade_lineage_decision_id` and `idx_trade_lineage_canonical_contract` without
rewriting historical data. Rollback drops only those indexes and columns. Roll back
application code before the migration `down`; otherwise Doctrine will still expect
the columns.

## Intentionally uncovered follow-up boundaries

This coherent core does not complete all of #302. The following boundaries need
separate RED→GREEN changes before modern identities can be certified end-to-end:

- Per-symbol identity for multi-symbol runs and worker/restart recovery:
  `src/MtfRunner/Dto/MtfRunnerRequestDto.php`, `src/MtfRunner/Service/MtfRunnerService.php`,
  `src/MtfValidator/Command/MtfRunWorkerCommand.php`, and dead-letter/replay tooling.
- Decision-key creation and TradeEntry algorithm boundaries:
  `src/MtfValidator/Service/TradingDecisionHandler.php`,
  `src/TradeEntry/Dto/TradeEntryRequest.php`, `EntryZone/EntryZoneCalculator.php`,
  `OrderPlan/OrderPlanBuilder.php`, `RiskSizer/*`, `Service/Leverage/*`, and TP/SL calls.
- Structured intent, lifecycle, fill, order, and position columns:
  `src/Entity/OrderIntent.php`, `src/Entity/TradeLifecycleEvent.php`, their writers,
  fill watchers, execution coordinator, and a follow-up non-guessing migration.
- Selector typed-metric gate and immutable per-decision indicator context:
  `src/MtfValidator/Execution/ExecutionSelector*.php` and indicator context owners.
- Certified analytics/outcome/read API: a new version of
  `position_trade_analysis_v2`, `PositionTradeAnalysisV2`, its repository/outcome
  exporters, with explicit `legacy/incomplete` status and no JSON-extra inference.
- Required synchronous/Messenger/restart E2E matrices, two-mode/two-setup same-symbol
  isolation, and EntryZone/leverage/TP-SL pre-algorithm rejection tests.

Until those follow-ups land, published #300/#301 contracts remain non-executable and
modern rows must not be described as end-to-end certified.
