# Research Canonical Signal Worker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Evaluate the unchanged day-trading long baseline on explicitly sourced Binance candles using canonical PHP indicator/rule arithmetic, at a throughput suitable for the ten-pair historical campaign.

**Architecture:** A persistent local NDJSON worker owns bounded rolling windows and aggregates, resolves the immutable fake/local baseline once, and returns canonical rule decisions plus compact price/indicator evidence for later research variants. Source-neutral numeric calculation is shared with the existing indicator calculator, but its strict Paper provenance entry points are not broadened. No order is created by this worker.

**Tech Stack:** PHP 8.4, Symfony Console, existing canonical PHP indicator/rule services, PHPUnit; Python CLI integration is a later consumer of the tested protocol.

## Scope and fixed semantics

The user approved candle research as a distinct simulation, followed by Paper
validation. This plan is phase B1 of that campaign, not the finished backtest.
Day-trading long rules, mode, risk budgets, costs and `1.1.0` files remain byte
identical. Only source data and an explicit research calculation envelope are
new. No aliasing Binance to OKX/Hyperliquid, no relaxed canonical snapshot hash
validation, no fake canonical order plan and no new exchange execution route.

At 15-minute cadence the full requested period is approximately 1.32 million
symbol evaluations. Starting Symfony per evaluation or sending five full windows
for every tick is unsuitable. The process must remain alive, receive consecutive
blocks of one-minute candles, build higher timeframes and reuse closed aggregate
contexts until they change. It must not persist a full multi-year in-memory array.

## Files and public boundaries

- Modify `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalPhpIndicatorCalculator.php`: retain `calculate(CanonicalIndicatorWindow)` and delegate its arithmetic to the source-neutral value kernel.
- Create `trading-app/src/TradingCore/Backtesting/Indicator/CanonicalIndicatorNumericSeries.php`: exactly 250 validated values per input series (close/high/low/volume/open timestamps), no venue claim or execution authority.
- Create `trading-app/src/TradingCore/Backtesting/Research/ResearchCandle.php`: strict immutable one-minute source observation.
- Create `trading-app/src/TradingCore/Backtesting/Research/ResearchRollingWindows.php`: chronological aggregation and bounded windows for 1m/5m/15m/1h/4h.
- Create `trading-app/src/TradingCore/Backtesting/Research/ResearchSignalSession.php`: source binding, canonical baseline resolution and shared projection/rule decision.
- Create `trading-app/src/Command/ResearchSignalWorkerCommand.php`: bounded NDJSON framing only, command `app:research:signals`.
- Create tests under `trading-app/tests/TradingCore/Backtesting/Research/` and `trading-app/tests/Command/ResearchSignalWorkerCommandTest.php`; extend the existing calculator test for numeric parity.

Read the actual canonical calculator/projector and rule runtime fixtures before
patching. Reuse their real dependency setup; do not invent stub indicators or
mock every rule. No change is permitted to the old candle/window/projector
allowlists: Binance and additional symbols must remain rejected there.

## Task 1 — Share numeric calculation without sharing provenance

- [ ] Add a failing parity test with the existing validated canonical window:

```php
$expected = $calculator->calculate($canonicalWindow);
$series = CanonicalIndicatorNumericSeries::fromCanonicalWindow($canonicalWindow);
self::assertSame($expected, $calculator->calculateNumericSeries($series));
```

- [ ] Define the value-only object with exact list sizes, finite/nonnegative
  volumes, positive prices, OHLC consistency and increasing timestamps. The
  research wrapper additionally enforces timeframe grid and observation cutoff.
- [ ] Extract only existing arithmetic into `calculateNumericSeries()`; preserve
  operation order, series lengths and numeric outputs. Existing `calculate()`
  remains the same typed entry point and constructs the value object itself.
- [ ] Cover wrong lengths, mismatched series, non-finite/invalid prices, negative
  volume, duplicate timestamps and insufficient total volume. Existing golden
  calculator/projection outputs must remain unchanged.
- [ ] Run affected indicator tests plus strict lint before continuing.

## Task 2 — Research observations and rolling windows

`ResearchCandle::fromArray()` accepts exactly `symbol`, `open_ms`, `open`, `high`,
`low`, `close`, `volume`, `source_record_id`. Values are decimal strings, symbol
belongs to the approved ten, timestamp is an integer minute boundary and the ID
is a lowercase 64-hex digest. The source binding is session-owned, not repeated
or freely overridden by individual candles. Closed availability is
`open_ms + 60_000`, never the inclusive Binance close timestamp.

- [ ] TDD: append consecutive minutes to `ResearchRollingWindows` and assert
  no 5m value appears before its fifth minute closes; an aggregate preserves first
  open, maximum high, minimum low, last close and sum of volumes.
- [ ] Test UTC alignment at day/year boundaries and derive 4h from closed source
  minutes/hours. Compare aggregate values to the existing canonical 4h aggregator
  on the same underlying prices/volumes, without claiming identical provenance.
- [ ] Gap, duplicate or backwards candle fails before updating state. Do not
  silently reset warmup or pretend a gap was a quiet market. Runs over verified
  disjoint segments must open distinct sessions and rewarm explicitly.
- [ ] Hold at most the warmup needed for 250 complete candles of each timeframe,
  plus current aggregation state. Emit evaluation at each closed 15m boundary
  only after all mandatory windows are warm; earlier decisions are counted as
  `warmup_unavailable`, not zero-return trades.
- [ ] Reject source availability after the frozen exclusive campaign cutoff.
  Confirm appending future data cannot alter any prior emitted decision.

## Task 3 — Canonical signal session

Initial frame is `research-signal-open.v1` with an exact key set:
`schema_version`, `session_id`, `dataset_id`, `dataset_sha256`, `source_venue`,
`source_network`, `market_type`, `symbol`, `start_ms`, `end_ms`, `score_start_ms`.
Venue is `binance_usdm`, network is `mainnet`, market is `perpetual`; source data
hash is lowercase SHA-256 and is returned with every result. Execution context
is independently `fake/local`, `day_trading@1.1.0`,
`day_trading.trend_continuation.long@1.1.0`, capability `backtest`.

- [ ] Resolve and retain the authentic effective snapshot, setup file hash,
  condition catalog hash, config hash and mode risk values at open. No caller may
  supply an altered effective snapshot or variant AST to this session.
- [ ] For each closed 15m tick, compute numeric context once, cache higher-
  timeframe values until their last closed bar changes, and construct the exact
  snapshot identities/kline times expected by `CanonicalSetupRuleRuntime`.
- [ ] Call the actual runtime and preserve reason/trace. Output schema
  `research-signal-result.v1` binds session, source, baseline identities/hashes,
  symbol, evaluated time, rule pass/reason, numeric context hash and compact
  scalar contexts needed by the entry/stop policies. Preserve the canonical
  trace hash and section/filter verdicts for every evaluation; full traces are
  retained for passed signals and a bounded diagnostic sample of each rejection.
  This keeps rejection denominators auditable without emitting the same large
  failing rule tree millions of times. The source is retained for exact replay.
- [ ] Golden tests compare baseline rule outcomes and normalized traces with
  the existing canonical rule command/runtime on equivalent contexts. Normalize
  only observational cache-hit fields, not reasons, values or business hashes.
- [ ] Tests verify all ten symbols, wrong source fields/hashes, source/execution
  separation, immutable baseline file hashes, no future contexts and independent
  deterministic results across fresh sessions.

## Task 4 — NDJSON protocol and throughput

- [ ] Command tests feed open, one or more `research-signal-candles.v1` frames,
  then `research-signal-close.v1`. A candle frame contains `schema_version`,
  `session_id`, `candles` and at most 1,440 consecutive records. Close contains
  only schema/session. Frames must match the live session; no re-open/reset with
  an unfinished session and no silent success on EOF before explicit close.
- [ ] Cap each line at 2 MiB and validate arrays/counts before iteration. Stdout
  is protocol JSON only; failures terminate nonzero with a structured error.
  Flush after each input batch. No unbounded child process or hidden network I/O.
- [ ] Session summary counts consumed candles, warmup ticks, evaluated ticks,
  passed/failed rules, first/last source times and completion relative to the
  promised end. Prefix exhaustion is `partial`, not successful full completion.
- [ ] Test early EOF, malformed/oversized/duplicate-key frames, duplicate open,
  wrong session ID, out-of-order input and byte-deterministic fresh-process output.
- [ ] Verify production service container lint and targeted PHPStan. Run all
  existing Backtesting indicator and canonical rule tests plus new worker tests.
- [ ] Single consolidated review, corrections and CI before merge. Do not ask for
  a second review cycle or change risk/contract constants to satisfy a test.

## Benchmark and continuation

- [ ] Consume a verified training month plus separate warmup for BTC and ETH.
  Record wall time, peak memory, candles/second and decisions/second. Confirm
  counts and source end independently. No benchmark uses 2026 profit results.
- [ ] Extrapolate using the actual evaluated count and measured throughput;
  address bottlenecks before the full ten-pair period rather than promising an
  unmeasured finish time. Parallel workers remain limited to two.
- [ ] Next boundary is the explicitly versioned research price/fill/cost model,
  shared portfolio, variant ledger and holdout controller. Passing this worker
  does not establish trades, profitability, or completion of the campaign.
