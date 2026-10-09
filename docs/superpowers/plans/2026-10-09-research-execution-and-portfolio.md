# Research Execution and Shared Portfolio Implementation Plan

> **For agentic workers:** use subagent-driven-development or executing-plans, TDD, and one consolidated review/correction cycle per PR. This is the continuation of the user-approved candle research, not authority for exchange orders.

**Goal:** turn B1's canonical closed-candle signals into explicitly hypothetical research plans, simulated executions and auditable shared-portfolio statistics, without weakening published strategy or risk contracts.

**Architecture:** share existing PHP decimal geometry and cost arithmetic; keep research provenance and plans separate from canonical execution authorization. A Python streaming consumer advances all ten symbols on one UTC clock, with one portfolio per variant. Variant selection never reads final-holdout results before being frozen.

**Dependencies:** acquisition manifest and verified candles/funding from phase A; persistent canonical signal worker B1; genuine baseline effective snapshot and its hashes. These instructions do not claim those dependencies are complete.

## Invariants established from repository code

- Baseline is long `day_trading.trend_continuation.long@1.1.0`, execution context `fake/local`, research source Binance USD-M mainnet perpetual. Production allowlists remain unchanged; the ten-symbol research universe is separately explicit.
- Initial balance is 100,000 USDT. Keep risk budget 5%, daily loss limit `min(6% equity, 30 USDT)`, four concurrent positions including pending, 100% aggregate notional exposure, and leverage cap 2. The effective per-position notional cap is **250 USDT**, because `runtime/env/local.yaml` is stricter than Fake's 1,000 USDT. Minimum is at least Fake's 5 USDT and any stricter declared instrument assumption.
- `CanonicalRiskEngine` calculates final leverage; do not mistake its cap of 2 for leverage actually used. Liquidation hypotheses use the actual decision leverage.
- `CanonicalPortfolioAdmissionEngine` consumes `max(-realized_net_pnl,0) + max(-unrealized_net_pnl,0) + reserved_risk`, then compares candidate risk with the remaining daily allowance. Mirror this exact calculation with golden fixtures, including whether reservations persist after fills; inspect the existing reservation store/adapter lifecycle before implementing that lifecycle.
- Limit entries only, no market fallback. Entry TTL and cancellation bound must be read from the authentic policy (currently 90s and 120s); holding is at most 8h and cannot cross the UTC midnight boundary.
- Fee assumptions use the genuine Fake maker/taker values (0.0002/0.0005), labeled as research assumptions, never as historical Binance tariffs. Do not alter net-R threshold 1.3, risk limits, confirmations or published contracts to create trades.

## B2a — Reuse pure price and cost arithmetic

Files to inspect first:

- `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalEntryZoneEngine.php`
- `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalProtectionEngine.php`
- `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalNetREngine.php`
- `trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskEngine.php`
- their existing tests, policy types and decimal conversion helper.

- [ ] TDD an independently callable value-only entry-zone helper (`EntryZonePriceMath`) on the existing fixtures; preserve BigDecimal conversion/order/rounding and reason codes.
- [ ] Extract only the block from anchor/ATR/width to quantized bounds and candidate entry. Keep all identity, scope, freshness, hash and policy checks in the canonical engine. New inputs have no venue or execution authority; validate finite positive prices, tick, side and bounded width/asymmetry values.
- [ ] Similarly extract `ProtectionPriceMath`, covering both existing ATR and pivot paths, both sides, stop buffer and all target rounding. Do not simplify the old pivot/short behavior just because the first campaign is ATR/long.
- [ ] Extract common per-leg decimal cost arithmetic only if needed by the research builder. Preserve fee roles, adverse-funding provision, exact risk-component parity and target net-R rounding. Avoid an unnecessary broad risk-engine refactor.
- [ ] Existing wrappers return the same typed canonical objects and hashes; research helpers never construct a canonical plan. Prove exact baseline fixture parity and rejection parity, run all affected canonical tests, lint and PHPStan.

## B2b — Explicit research assumptions, variants and plans

New services live under `TradingCore/Backtesting/Research/`; command/protocol name and wire versions must be separate from canonical order planning. Reuse the persistent process approach, not one Symfony boot per signal.

- [ ] Freeze `research-instrument-assumptions.v1` for the ten symbols: tick, quantity step, min/max quantity, min notional, contract size, leverage cap and maintenance-margin/liquidation proxy. Record source URL, retrieval time, raw SHA-256, validity statement and assumption status.
- [ ] Public current Binance exchangeInfo may supply precision/limits, but does not attest 2023 specifications. Preserve raw response with bounded download and private atomic storage, fixed public endpoint only. Do not call authenticated leverage-bracket APIs. Missing precision or MMR assumptions means non-planifiable, never default Fake's two/three decimals onto every coin.
- [ ] Define explicit baseline and adverse spread/slippage, funding-provision and MMR/liquidation-fee assumptions before training. Each has units and a hash. This research model does not satisfy the mode's live order-book data contract; every output must say so.
- [ ] Define `ResearchVariant`: baseline plus at most 12 immutable diffs. Initial candidates: EMA20/5m anchor; VWAP/15m anchor; EMA20/15m anchor; width ATR multiplier 0.50; width multiplier 0.75; minimum half-width rate 0.001; maximum half-width rate 0.02; stop ATR multiplier 1.25; stop multiplier 2.0; target 1.5R; target 2.5R; EMA20/5m plus width 0.50. Verify actual baseline values first (expected VWAP/5m, width0.30ATR, stop1.5ATR, target2R) and reject no-op/duplicate candidates. All unlisted fields remain baseline-identical.
- [ ] Hash the exact diff with baseline setup/config/catalog hashes. Reject unknown keys, altered rule ASTs, risk caps, TTL, fees, horizon or confirmations. No changed array is passed as an authenticated effective snapshot.
- [ ] `ResearchPlanBuilder` accepts a genuine passed B1 signal, exact variant, instrument/cost assumptions and a current portfolio view. Validate all source/hash/closed-time bindings. Use last closed 15m close as the declared candidate-price proxy; never choose a more convenient future entry price.
- [ ] Invoke shared geometry helpers, genuine canonical risk policy/engine when its typed provenance can be honestly satisfied, and shared cost math. If a canonical instrument input cannot honestly represent a research assumption, use a clearly named value-only arithmetic boundary instead of forged evidence.
- [ ] Return typed rejections or `research-plan.v1` (never `CanonicalOrderPlan`) with signal/data/code/config/variant/assumption hashes; entry, zone, stop, target, quantity, final leverage, risk components, TTL/holding deadline and net-R. Rejection counters retain their denominator.
- [ ] Add strict wire/size/error handling and fixture parity for geometry, sizing, cost admission, invalid diffs, missing observations and stale/future contexts. No exchange adapter or DB write.

## B2c — Streaming shared-portfolio execution

Implement `python-orchestrator/app/backtesting/research/portfolio_simulator.py` and focused tests; reuse existing backtesting primitives only where their provenance/type requirements genuinely fit.

- [ ] Merge verified one-minute symbol iterators into timestamp batches. Require explicit coverage policy; missing candles cause a declared gap/invalid segment, never forward fills or synthetic zero returns. Hold only rolling state, pending orders and positions, not full datasets.
- [ ] Freeze deterministic same-timestamp symbol priority before training (approved-universe order); record it and test sensitivity separately rather than optimizing it on holdout.
- [ ] At each clock boundary, settle existing positions/events and mark portfolio, expire reservations, then admit eligible new signals. Read decisions only at their declared availability; no same-signal-bar fills or admission using another symbol's future close.
- [ ] Pending orders consume risk, concurrency, margin and exposure. Quantity and risk are recomputed from the actual portfolio state at admission, not ten independent 100,000-USDT accounts.
- [ ] Conservative OHLCV limit model: no guaranteed queue priority or partial-fill realism. A resting limit must be touched on a later candle; favorable price improvement is not assumed. Freeze explicit handling when TTL or cancellation lands inside a one-minute bar: an ambiguous touch after the last wholly eligible interval must not become an optimistic fill. Count rejected ambiguity.
- [ ] Resolve stop/target overlap conservatively, including a stop inside the entry-fill candle. Gap-through-stop executes at the worse admissible opening/stop price plus declared adverse costs; target uses no favorable improvement. Price-path ambiguity is counted in ledger fields.
- [ ] Forced exit at holding/midnight is a versioned research settlement at a known available boundary price with adverse costs, not a certified market order. Preserve close-before-midnight semantics and reject an entry that cannot fit a valid lifetime. Define event ordering at exactly midnight and funding boundaries explicitly.
- [ ] Apply observed funding at its actual timestamp without rounding away archive jitter; use only past-known rates for pre-trade provision. Funding settlement uses a declared mark-price proxy if no historical mark is available. Retain actual versus provisioned amounts separately. Missing funding coverage is invalid/inconclusive, not free financing.
- [ ] Liquidation is an explicitly labeled proxy using declared MMR, actual leverage and adverse costs, not a historical exchange proof. If a candle can touch both stop and liquidation, choose the more adverse outcome; record the ambiguity and stress assumptions.
- [ ] Test wallet/equity/margin reconciliation, cap250, four-slot limit, daily30 allowance, duplicate signals, reservation release, out-of-order inputs, all expiry boundaries, all exit reasons, all ambiguity paths and uninterrupted/chunked determinism.
- [ ] Benchmark one training month plus warmup on BTC/ETH and extrapolate actual throughput before full scheduling. At most two workers; research evidence private and bounded.

## C — Ledger, bounded search, final report

- [ ] Persist an immutable experiment identity, every attempted candidate and each rejection/trade/cashflow, plus validated resumable progress. Atomic completion requires expected source end/count and reconciled totals, never merely a PID or lock file.
- [ ] Train only2023–2024, validate2025, and record a frozen selection artifact before reading any2026 profit/selection output. Quality-only inventory can cover all years. Retain zero/negative/failed candidates, never retest holdout during search.
- [ ] Rank eligible candidates by stable net expectancy with drawdown/trade-count/cost-completeness constraints defined before inspecting outcomes; win rate alone is not the objective. If no candidate meets them, report no validated candidate, not a forced winner. Any subsequent training-only batch needs a recorded reason and new ledger entries.
- [ ] Produce JSON/CSV statistics and a French summary: counts, win rate, net/gross PnL, fees/funding, expectancy/netR, profit factor with undefined denominators, marked-to-market drawdown, exposure and per-year/pair breakdown. Independently recompute totals from trades/cashflows and distinguish assumed from observed costs.
- [ ] Report dataset coverage/gaps, universe-selection bias, current-metadata bias, OHLCV fill uncertainty, missing costs, limits and completed versus pending phases. A negative/inconclusive result is valid; no global optimum or profitability promise.
- [ ] Continue with D, distinct Paper transfer validation on supported venues/captures. Do not auto-publish a new operational setup or enable demo/mainnet writes.
