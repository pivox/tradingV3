# Issue #304 Lot C — Portfolio Enforcement Implementation Plan

> Scope: Paper/Fake/backtest and modern runtime preparation only. No private
> mainnet execution port is selected or enabled.

## Contract decisions

- Existing mode contracts remain blocked: their daily-loss values do not own a
  timezone/day boundary or unrealized-loss semantics, and their mode exposure
  cap is unresolved.
- The canonical portfolio compiler accepts only a future resolved effective
  snapshot whose daily cap explicitly owns percentage, absolute quote,
  currency, timezone, local day boundary, and unrealized-loss semantics.
- Concurrency explicitly states whether pending entries count. No default is
  inferred.
- Portfolio freshness uses the accepted OrderPlan maximum input age. Network,
  venue, environment, account, mode, and quote currency form one exact scope.
- Daily loss consumption is conservative: realized loss, optional unrealized
  loss, and already-reserved stop risk consume the most restrictive of the
  percentage and absolute caps.
- Mode exposure includes open notional, pending/reserved notional, and the
  candidate plan. Counts and notionals are non-overlapping snapshot facts.
- A reservation is bound to decision key, config hash, plan hash, portfolio
  input hash, and atomic state version.

## C1 — Pure policy and admission decision

### Tests first

- Add compiler tests for explicit unit conversion, missing day semantics,
  unresolved exposure, malformed currency/timezone/day boundary, and exact
  authenticated config identity.
- Add admission tests for the percentage/absolute daily minimum, optional
  unrealized loss, reserved risk, concurrency with pending entries, projected
  exposure, stale/future/wrong-scope snapshots, duplicate decision keys, and
  byte-stable decisions.

### Implementation

- Add immutable canonical portfolio policy, scope, snapshot, request,
  reservation decision, and stable exception contracts.
- Add a pure admission engine using arbitrary-precision decimal arithmetic.
- Keep persistence and all runtime adapters out of C1.

## C2 — Atomic reservation state and partial fills

### Tests first

- Prove compare-and-swap reservation, duplicate decision-key idempotence,
  conflicting reservation rejection, terminal release idempotence, and state
  hash/version advancement.
- Prove filled and residual quantities/risk are separate, protected quantity is
  never overstated, over-budget residuals are reduced/cancelled, and uncovered
  filled exposure blocks further entry with the compensation reason.

### Implementation

- Add a deterministic reservation aggregate and transition engine.
- Add an atomic store port plus an in-memory reference implementation used by
  parity tests. Durable Paper/Fake adapters must persist plan and reservation in
  one transaction before they can claim executable behavior.

## C3 — Runtime/Fake/Paper/backtest parity

### Tests first

- Feed identical policy, plan, portfolio, reservation, fill, cancel, and close
  snapshots through four thin adapters and assert identical serialized
  decisions and rejection codes.
- Assert that no adapter imports a legacy TradeEntry/ExecutionBox fallback or a
  private mainnet execution port.

### Implementation

- Add thin source adapters only; the policy, admission, reservation, and fill
  engines remain shared.
- Wire Fake/Paper persistence only where plan plus reservation can commit
  atomically. Runtime and backtest use the same input serializer.
- Remove a portfolio blocker only when its strict consumer and resolved
  contract exist. Current `1.0.0` mode contracts remain non-executable.

## Verification per sub-lot

- Focused PHPUnit tests and the expanded TradingCore Risk/OrderPlan suites.
- PHPStan on changed production and test namespaces.
- PHP lint, `git diff --check`, config resolver suite, and repository CI.
- Scope audit proving no legacy fallback and no mainnet mutation activation.
