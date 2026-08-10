# #304 Lot B — Canonical Entry and OrderPlan Implementation Plan

**Goal:** Build the strict EntryZone, protection, net-R, and canonical
OrderPlan pipeline without legacy fallback or runtime activation.

**Architecture:** Add private-construction compiled policy contracts and pure
calculators under canonical TradingCore namespaces. Use injected time,
arbitrary-precision decimal math, immutable DTOs, and stable rejection codes.
Keep all unresolved setup contracts blocked.

## Task 1 — Compile the setup execution policy

- Add `CanonicalExecutionPolicy` and compiler/exception classes.
- Validate snapshot executability, identity, canonical hash, exact decision
  units, exact shapes, finite rates, durations, target ordering, and explicit
  cost ownership.
- Prove unresolved decisions, unknown keys, legacy aliases, hash tampering, and
  direct policy construction reject.
- Run focused PHPUnit, PHPStan, lint, then commit.

## Task 2 — Calculate a strict timestamped EntryZone

- Add timestamped anchor/ATR/market snapshots, request, decision, and engine.
- Inject `Psr\Clock\ClockInterface`.
- Reject missing, stale, future, non-finite, wrong-timeframe, wrong-source, and
  invalid-tick inputs.
- Calculate width/asymmetry, quantize outward, require candidate containment,
  and record immutable timestamps/lineage.
- Add deterministic long/short, freshness, boundary, and no-fallback tests.

## Task 3 — Calculate side-correct protection

- Add strict stop input snapshots and target DTOs.
- Implement ATR and pivot policies as separate exhaustive branches.
- Quantize stop away from entry and targets toward entry, then revalidate side.
- Reject absent required inputs and any attempted source substitution.
- Add long/short and tick-boundary tests.

## Task 4 — Enforce cost-inclusive net R

- Add explicit target execution cost snapshots.
- Reuse compiled maker/taker fee authority and side-aware funding semantics.
- Calculate gross reward, net reward, net risk, and net R with BigDecimal.
- Require every target to meet `minimum_net_r`; unknown costs or holding window
  reject.
- Add tests where gross R passes but net R fails.

## Task 5 — Build and revalidate the canonical OrderPlan

- Add an immutable canonical plan, build request, builder, and final validator.
- Require exact identity/config-hash agreement across policy, zone, protection,
  and risk decision.
- Record lineage, input hashes, timestamps, caps, costs, and per-target net R.
- Recompute zone freshness/containment, side, risk, leverage, and net-R
  invariants from the plan.
- Prove no legacy dependency or MARKET fallback is reachable.

## Task 6 — Verification and delivery

- Run all canonical risk/entry/protection/order-plan tests plus existing
  TradingCore Entry/SlTp/OrderPlan regressions.
- Run focused PHPStan, PHP lint, dependency audit, and `git diff --check`.
- Update the risk/order-plan handbook with Lot B boundaries.
- Push a draft PR referencing #304, request iterative Codex review, resolve all
  findings, pass CI, then merge before starting Lot C.
