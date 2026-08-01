# Canonical trade path — issue #302, Lot 2A

## Delivered boundary

The Messenger decision transports one readonly `LineageContext` into `MtfRunDto` and
`TradingDecisionHandler`. A stable decision UUID and decision key exist before
TradeEntry. Modern TradeEntry requests, EntryZone construction, plan construction,
LIMIT/MARKET copies, intent reservation, lineage creation, and lifecycle writes use
that same typed object. Venue, market, symbol, side, mode/setup versions, and hashes
are checked before configuration, indicator, risk, leverage, TP/SL, or execution
calls. Modern configuration fallback is disabled.

The timeframe selector is stateless and accepts an immutable typed metrics envelope
owned by the decision identity. An absent/incomplete envelope returns
`selector_metrics_missing` without invoking timeframe validation or selector rules.
The rules themselves are unchanged; issue #303 remains their owner.

`OrderPlanModel` snapshots and immutable copies preserve canonical identity.
`OrderIntent` and `TradeLifecycleEvent` have nullable structured canonical fields for
legacy readability, while typed modern writers populate them. Intent retry compares
the stored canonical contract and rejects conflicts. Published/reference-only draft
contracts remain non-executable: TradeEntry requires an effective configuration
snapshot with explicit `executable: true`.

Migrations `Version20260801100000` and `Version20260801101000` add only transactional,
nullable columns and perform no inferred backfill. No new query pattern justified an
additional index in this lot.

## Lot 2B — exact remaining certification

Lot 2B must complete and certify the recovery/read side; it must not infer identity
from timestamps, symbols, profiles, or JSON extras.

- `src/MtfRunner/Dto/MtfRunnerRequestDto.php`,
  `src/MtfRunner/Service/MtfRunnerService.php`,
  `src/MtfValidator/Command/MtfRunWorkerCommand.php`, Messenger failure transport,
  and replay/dead-letter commands: prove one identity per symbol after restart and
  across multiple workers, including duplicate/reordered delivery and recovery.
- `src/Entity/FuturesOrder.php`, `src/Entity/FuturesOrderTrade.php`,
  `src/Entity/Position.php`, `src/MtfRunner/Service/FuturesOrderSyncService.php`, fill
  watchers, and position synchronizers: add/certify structured order/fill/position
  identity copied only from an exact typed intent/lineage match; reject conflicting
  external order IDs. Full execution→fill→position E2E belongs here.
- `position_trade_analysis_v2`, `PositionTradeAnalysisV2`, its repository, outcome
  services, investigation export, and read APIs: expose structured identity and an
  explicit `legacy/incomplete` classification; certify no `extra` fallback.
- Run the matrix for two same-symbol mode/setup identities through restart,
  multi-worker handling, intent/order/fill/position/lifecycle/outcome joins, and
  export. Every requested/resolved/validated/planned/submitted/analyzed value and
  stage ID must be byte-identical.

Issue #303 owns condition semantics; issue #304 owns tuning. Neither is implemented
by this lot.
