# H3-B — Adaptive position management (DRAFT)

**Status: DRAFT design and research prototype.** This is H3-B of the H3 mission ("Opportunity Scoring & Adaptive
Position Management"). Everything below was tested on synthetic values only. No market data was read, no
order was placed and no parameter was fitted. No number on this page is evidence of any edge. It does not claim
that adaptive management beats a static exit: that is the question H3-B asks, and the B0–B3 protocol has to answer
it later.

Code: `python-orchestrator/scripts/research_h3/adaptive/` (`guards`, `params`, `states`, `policy`).
Tests: `python-orchestrator/tests/test_research_h3_adaptive.py`.
Shared contracts: `python-orchestrator/scripts/research_h3/contracts.py` (schema version 1, not modified here).

## 1. Scope

The engine re-evaluates one open position per snapshot and returns one `AdaptiveAction`. It has three properties:

- **Deterministic.** The same `PositionState` and the same parameters give the same action, with the same id.
- **Exchange-independent.** The tick, lot, minimum size and minimum notional come from the snapshot's
  `InstrumentRef`.
- **Subordinate to the risk path.** It never mutates a position or a portfolio, and never sends an order. Every
  action still has to pass the integration risk gate (`RiskGate.validate_action`) before the simulator applies it.

Two policies implement `contracts.ManagementPolicy`:

| Policy | Identity | Behaviour |
|---|---|---|
| `StaticPolicy` | `h3-static@0.1.0-draft` | Always HOLD. The simulator exits B0/B1 positions only by stop, target or maximum holding time. |
| `AdaptivePolicyV1` | `h3-adaptive-v1@0.1.0-draft` | The DRAFT state → action table of §3, used by B2/B3. |

Each policy's `PolicyRef.policy_fingerprint` is the sha256 of a canonical JSON document. For V1 that document holds
every parameter, so changing any value changes the policy identity.

## 2. Inputs (owner item B1)

The engine reads `contracts.PositionState` and nothing else. Every field of that snapshot was observed at or before
`observed_ms`, which is at or before `decision_ms`; the contracts enforce that order. No future observation can
therefore enter a decision.

| B1 input | `PositionState` field | V1 use |
|---|---|---|
| observed price | `last_price` | breach test, trail cap, partial notional |
| entry price, direction | `entry_price`, `header.side` | R unit, signs |
| current stop / target | `current_stop`, `current_target` | tightening must beat `current_stop`; V1 does not use the target |
| holding time | `holding_ms`, `max_holding_ms` | INITIAL and EXPIRY states |
| unrealised PnL | derived: `side × (last − entry) × open_qty × contract_value` | journal; ADVERSE state (in R) |
| MFE / MAE up to T | `mfe_price`, `mae_price` | FAVOURABLE state, trail anchor; MAE is journal only |
| volatility | `volatility` | **not used** (unit not fixed by the contract, see §10) |
| liquidity | `liquidity` | an optional partial close waits unless NORMAL |
| funding | `funding_paid` | journal only (`funding_r`) |
| equity, risk budget | `equity`, `open_risk_total`, `risk_budget_remaining` | journal flag `risk_budget_exceeded`; adaptation can only lower risk |
| action history | `actions_applied`, plus the observable effects (`current_stop`, `open_quantity`) | idempotency; "partial already taken" = `open_quantity < initial_quantity` |
| policy version | `policy` | a policy only manages positions that carry its own `PolicyRef` |
| data quality | `data_status`, `header.clock` | DEGRADED state |
| kill switch | `kill_switch` | KILL_SWITCH state |

R is the initial risk per unit, `|entry_price − initial_stop|`. It is anchored on the initial stop, never on the
current one, so the initial plan is never rewritten (B3.10).

## 3. State machine and action table (owner item B5; all thresholds DRAFT)

The classifier is memoryless. Each snapshot is classified on its own, and history enters only through fields of the
snapshot (current stop, open quantity, applied actions). The first matching row wins.

| # | State | Condition | V1 action | Reason codes |
|---|---|---|---|---|
| 1 | CLOSED | `status = closed` (open quantity 0) | HOLD | `position_closed` |
| 2 | EXPIRY | `holding_ms ≥ max_holding_ms` | FULL_CLOSE | `max_holding_reached` |
| 3 | BREACHED | last price at or beyond the current stop while the position is still open | FULL_CLOSE (reduce-only) | `stop_breached_exit` |
| 4 | KILL_SWITCH | `kill_switch` set | HOLD; FULL_CLOSE only if `kill_switch_flatten` | `kill_switch_hold` / `kill_switch_flatten` |
| 5 | DEGRADED | `data_status ≠ complete`, or `decision_ms − observed_ms > max_snapshot_age_ms` | HOLD | `data_partial`, `data_missing`, `data_stale`, `snapshot_stale` |
| 6 | INITIAL | `holding_ms < initial_phase_ms` | HOLD | `state_initial` |
| 7 | FAVOURABLE | `side × (mfe − entry) ≥ favourable_mfe_r × R` | see below | `state_favourable` + … |
| 8 | ADVERSE | `side × (last − entry) ≤ −adverse_r × R` | HOLD | `state_adverse` |
| 9 | NEUTRAL | otherwise | HOLD | `state_neutral` |

Rows 2–4 decide without fresh data:

- EXPIRY is a time rule.
- BREACHED and KILL_SWITCH can only produce HOLD or an exit that takes exposure to zero.

In the FAVOURABLE state there is one action per snapshot, checked in this order (protection comes before profit
taking):

1. **TIGHTEN_STOP**, to the trail level:
   - start from `best price − trail_r × R` for a long (mirrored for a short);
   - cap it one protective margin inside the last price;
   - round it to the tick grid away from the market;
   - emit it only if the result is strictly tighter than the current stop.

   With the DRAFT values, the first tightening is exactly break-even, and after that the stop trails the best price
   at 1 R.
2. Otherwise, **PARTIAL_CLOSE**, once:
   - size: `partial_close_fraction × initial_quantity`, floored to the lot;
   - only if liquidity is NORMAL and the order minima allow it.

   If the minima do not allow it, V1 holds with `partial_close_not_executable` plus the violation codes. It turns the
   partial close into a FULL_CLOSE only when `partial_to_full_below_minimum` is true, an explicit rule the owner must
   validate (B3); it is off by default.
3. Otherwise HOLD (`stop_at_trail_level`, `partial_already_taken`, or `partial_close_deferred_liquidity`).

**V1 never emits ADJUST_TARGET.** No a-priori rule for moving a target was found that is not a performance choice.
The guards still validate ADJUST_TARGET for any other policy (§5).

**V1 takes no action in the ADVERSE state.** The initial stop is the loss limit. An earlier loss-cut would be a
separate hypothesis that nothing here supports.

Every candidate action passes `guards.check_action` before it is returned. A refused candidate becomes HOLD, with
`guard_refused` and the violation codes. `AdaptivePolicyV1.evaluate` returns the full trace, `AdaptiveDecision`:
state, metrics, candidate, guard verdict and final action. The trace has a canonical JSON form and a sha256
fingerprint, ready for the journal.

Known consequences of the table, stated rather than tuned:

- **The partial close can be postponed indefinitely.** It waits for a snapshot where the stop is already at its trail
  level, so in a steady trend V1 keeps trailing and never takes it.
- **The partial close fills at the decision price, not at +1 R.** V1 places no resting limit order.
- **The trail cap can act as a market exit.** When the price has already fallen back more than 1 R from its best
  level, the cap puts the stop one tick inside the market, which in practice exits on the next snapshot.

## 4. Parameters (all DRAFT, none chosen from performance)

No data has ever been run through this policy, so no value can come from a performance measurement. Each value has
an a-priori reason, recorded in `params.PARAMETER_RATIONALE`; the tests require every parameter to have one.

| Parameter | DRAFT value | A-priori reason |
|---|---|---|
| `favourable_mfe_r` | 1 | 1 R, the risk unit fixed at entry, is the first point where the initial risk can be removed without asking more than the trade has shown. |
| `trail_r` | 1 | The position keeps the loss tolerance it was admitted with, re-anchored on the best price; no new width is invented. |
| `adverse_r` | 0.5 | Half-way to the initial stop. It only labels the ADVERSE state; V1 takes no action on it. |
| `partial_close_fraction` | 0.5 | An equal split; no information says which half of the position is better. |
| `partial_to_full_below_minimum` | false | B3: the transformation needs an explicit rule validated by the owner. |
| `defer_partial_unless_liquidity_normal` | true | An optional market reduction pays an unknown cost in a thin or unknown book. Stop tightening and protective exits are never deferred. |
| `kill_switch_flatten` | false | Mirrors TradingV3: a tripped kill switch freezes writes (§9). |
| `initial_phase_ms` | 3 600 000 | One bar at the DRAFT 1 h cadence. Inside the entry bar, high and low cannot be ordered against the fill. |
| `max_snapshot_age_ms` | 3 600 000 | A snapshot older than one bar interval has missed at least one bar. |
| `protective_margin_ticks` | 1 | The smallest distance that keeps a stop strictly protective on the price grid. |

The 1 h cadence is the synthetic fixtures' (`synthetic.HOUR_MS`). Once the protocol freezes a decision cadence, both
time parameters must equal one bar of it. A frozen set of values gets a new `policy_version`.

## 5. Invariants and where they are enforced

`guards.check_action(action, state, params=DEFAULT_GUARD_PARAMS, *, require_same_snapshot=True)` returns the
violated invariants as stable codes; an empty tuple means admissible. The policy runs it on its own output, and the
integration risk gate is expected to run it again. Tests: `tests/test_research_h3_adaptive.py`.

| # | Invariant (owner B3 / B4) | Enforcement | Codes |
|---|---|---|---|
| 1 | Deterministic for an identical snapshot | Pure functions of `PositionState` plus frozen parameters; ids from `AdaptiveAction.create` | — |
| 2 | Canonical risk respected | No kind adds quantity or leverage. The guard checks that risk and notional do not rise. A contract-level ADMIT `RiskDecision` cannot increase open risk or notional. | `risk_increased`, `exposure_increased` |
| 3 | Protection preserved | The stop only tightens and stays strictly protective by at least one margin. A widened stop cannot even be built into a `PositionState`. | `stop_widened`, `stop_unchanged`, `stop_wider_than_initial`, `stop_not_protective` |
| 4 | Price steps and minimum quantities | Stops and targets on the tick grid. A partial close is a lot multiple, at least the minimum size, and leaves at least the minimum size and minimum notional. | `stop_not_on_tick`, `target_not_on_tick`, `close_not_lot_multiple`, `close_below_min_size`, `remaining_below_min_size`, `close_below_min_notional`, `remaining_below_min_notional` |
| 5 | Idempotent | The id is derived from (position, snapshot fingerprint, kind, values, policy). An applied id is never re-applied, and an action is valid only on the snapshot it was decided on. | `action_already_applied`, `action_snapshot_mismatch` |
| 6 | Refusable without compromising the existing stop | The engine only proposes. A refused action leaves the state, and its stop, unchanged. The next snapshot re-derives the same level under a new id. | — |
| 7 | Journaled | Reason codes on every action, and an `AdaptiveDecision` trace with a fingerprint | — |
| 8 | Maximum holding time | EXPIRY → FULL_CLOSE regardless of data, kill switch or liquidity; the simulator's own expiry stays the authority | `max_holding_reached` |
| 9 | Never increases exposure | Same as 2. A partial close larger than the open quantity (a reversal) is refused. | `close_exceeds_open_quantity`, `position_side_reversal`, `partial_close_is_full_close` |
| 10 | No retroactive change to the initial plan | Actions carry no field for the initial stop, target, quantity or maximum holding; R is anchored on the initial stop | — |
| — | Identity | The action targets this position, instrument, side, portfolio and policy | `action_*_mismatch` |
| — | Closed position | Only HOLD | `position_closed` |
| — | Kill switch | Only HOLD or FULL_CLOSE | `kill_switch_active` |
| — | Stale or partial data | No price-dependent action (TIGHTEN_STOP, ADJUST_TARGET, PARTIAL_CLOSE). A FULL_CLOSE stays admissible because it takes exposure to zero. | `data_partial`, `data_missing`, `data_stale`, `snapshot_stale` |
| — | Target sanity (other policies) | A target stays beyond the last price by one margin | `target_not_beyond_price`, `target_unchanged` |

## 6. How a decision passes through the canonical risk path

The engine has no side channel: anything it proposes reaches a position only through the risk gate and the
simulator.

1. The integration simulator builds a `PositionState` at `decision_ms`.
2. The policy proposes: `policy.evaluate(state)`, which calls `classify` and then `check_action` on its own
   candidate (a refused candidate becomes HOLD). The result is an `AdaptiveAction` plus its `AdaptiveDecision` trace.
3. The integration risk gate decides: `RiskGate.validate_action(action, state, portfolio)` (integration package) is
   expected to call `guards.check_action` and the portfolio-level checks. It returns a `RiskDecision`:
   - REJECT: nothing changes;
   - ADMIT: the contract refuses an ADMIT that raises open risk or notional.
4. The simulator applies an ADMIT, reduce-only, and records the action id in `actions_applied`.
5. The audit sink records the action and its risk decision as `DecisionAudit` records (event `action`), chained by
   hash.

What the integration side must guarantee for these invariants to hold end to end. These are requests, not
assumptions made silently:

- **Closes are reduce-only.** FULL_CLOSE and PARTIAL_CLOSE are capped at the open quantity at execution. A
  `stop_breached_exit` can race a stop fill; with reduce-only semantics the second close finds nothing to close.
- **`actions_applied` lists only successfully applied action ids.** A refused or failed action is not listed, so the
  next snapshot retries it under a new id.
- **TIGHTEN_STOP is modelled as atomic in simulation.** The old stop stays in force until the new one is live (§9).
  It is a modelling assumption and must be labelled as one.
- **Expiry is executed identically for all four variants by the simulator.** The policy's FULL_CLOSE at expiry is a
  redundant, idempotent safety.
- **MFE/MAE exclude prices observed before the fill.** The entry bar's high and low cannot be ordered against the
  fill. INITIAL only delays acting on them; it does not remove them from `mfe_price`.

## 7. Failure scenarios tested (owner item B6)

All tests are synthetic and run for both sides where relevant. Fills belong to the integration simulator. Here, the
policy side of each scenario is tested: the action proposed for a snapshot that already shows the scenario.

| B6 scenario | Expected and tested behaviour | Test(s) |
|---|---|---|
| Stop never widened | Wider, equal, off-grid or non-protective stops are refused. A widened stop cannot be built into a `PositionState`. Seeded sweep: 2 × 600 snapshots, no V1 tightening ever widens. | `test_guard_refuses_a_wider_equal_off_grid_or_non_protective_stop`, `test_a_widened_stop_cannot_even_be_represented_in_a_position_state`, `test_property_every_v1_decision_is_safe_deterministic_and_admissible` |
| Risk budget never increased | Every V1 action builds an ADMIT ACTION `RiskDecision` under the contract rule. No kind adds exposure; a reversal is refused. | `test_every_v1_action_is_admissible_under_the_contract_action_rule`, `test_no_action_kind_can_add_exposure`, property sweep |
| Partial orders | Lot flooring. Below the minimum: HOLD by default, FULL_CLOSE only under the explicit flag. Remaining size and notional are checked. Only one partial close. Deferred in thin or unknown liquidity. | `test_partial_*`, `test_only_one_partial_close`, `test_thin_or_unknown_liquidity_defers_the_partial_but_not_the_stop` |
| Gap through the stop | Price beyond the stop on an open position gives a reduce-only FULL_CLOSE, never a tightening above the price. An earlier excursion beyond a stop tightened later is not a breach. | `test_gap_through_the_stop_closes_and_never_tightens_beyond_the_price`, `test_an_excursion_beyond_a_stop_tightened_later_is_not_a_breach` |
| Stop and TP in the same bar | Once the simulator has settled the bar, the closed position only holds and the guard refuses everything else. A target crossed but unfilled does not change the stop logic. | `test_after_an_ambiguous_bar_a_closed_position_only_holds`, `test_a_target_crossed_but_unfilled_does_not_change_the_stop_logic` |
| Missing data | Partial, missing or stale data gives HOLD, and the guard refuses price-dependent actions. Expiry and breach exits still go through. An unknown volatility changes nothing. | `test_incomplete_data_blocks_price_dependent_actions`, `test_unknown_volatility_does_not_change_the_decision` |
| Stale snapshot | An age above one bar gives HOLD. A decision taken on an earlier snapshot cannot be replayed on a new one unless the caller explicitly re-validates it. | `test_a_stale_snapshot_holds_and_an_old_decision_cannot_be_replayed_on_a_new_snapshot` |
| Repeated decisions | Identical action and id on the same snapshot, including after a JSON round trip. An applied action is refused when replayed. A 7-step path never widens the stop, never adds quantity and applies each id once. | `test_repeated_decisions_on_one_snapshot_are_identical_and_applied_once`, `test_a_path_never_widens_the_stop_never_adds_quantity_and_applies_each_action_once` |
| Expiry | FULL_CLOSE exactly at `max_holding_ms` (not 1 ms before), even with a kill switch and missing data | `test_expiry_closes_at_the_maximum_holding_time_only` |
| Failed stop modification | The old stop stays. The next snapshot proposes the same level under a new id, which the guard admits. The old action cannot be replayed. | `test_a_failed_stop_modification_keeps_the_old_stop_and_is_retried_consistently` |
| Position already closed | HOLD under both policies; every other kind is refused with `position_closed` | `test_already_closed_position_holds_with_both_policies` |
| Kill switch | HOLD by default, FULL_CLOSE only with `kill_switch_flatten`. The guard refuses TIGHTEN_STOP, PARTIAL_CLOSE and ADJUST_TARGET. | `test_kill_switch_holds_by_default_and_flattens_only_when_configured` |

Run (from `python-orchestrator/`):
`python -m pytest --noconftest -q tests/test_research_h3_adaptive.py tests/test_research_h3_contracts.py`. At the
time of writing, 153 tests pass in about 6 s (Python 3.12.3; CI uses Python 3.11, and the sources are checked
against the 3.11 grammar by a test).

## 8. Impossible or dangerous actions

**Not representable.** No action can express these:

| Forbidden by the brief | Why it cannot happen |
|---|---|
| Adding to a position, martingale, averaging down | No action kind increases quantity: `close_quantity` is positive and only reduces. |
| Automatic long/short reversal | A close larger than the open quantity is refused (`close_exceeds_open_quantity`, `position_side_reversal`); FULL_CLOSE goes to zero, not beyond. |
| Leverage increase | No action carries leverage, and notional can only fall. |
| Widening the stop | Refused by the guard, and a `PositionState` with a widened stop cannot be constructed. |
| Removing the stop without equivalent protection | No action removes a stop; `current_stop` is mandatory in the contract. |

**Representable but refused by the guards:**

- a non-protective or off-grid stop;
- a target inside the market;
- a partial close equal to the position, below the minima, or leaving dust;
- any price-dependent action on stale, partial or missing data;
- any non-HOLD action on a closed position;
- tighten, partial or target actions under a kill switch;
- an action replayed or aimed at another position, side, portfolio, instrument or policy.

**Admissible but still dangerous** (residual risks, kept visible):

- **Write frequency.** Trailing can move the stop by one tick on every snapshot. In live trading, each move is a
  protective replacement (§9). The table has no minimum step, because one would be an invented threshold.
- **Breach exit at market.** It can fill well beyond the stop in a gap. The simulator measures the loss beyond 1 R;
  it is never hidden.
- **A one-tick trail cap is a near-market exit** whose slippage at the stop is unknown.
- **FULL_CLOSE at expiry in thin liquidity** pays an unknown cost. It is still mandatory because the maximum holding
  time is a hard rule.
- **Duplicate close on a breach.** The breach exit racing a stop fill is safe only with reduce-only execution capped
  at the open quantity.
- **Retry loop.** A modification that keeps failing is re-proposed on every snapshot. The contract has no field for a
  retry budget (§10).

## 9. Stop-replacement sequencing in TradingV3 (owner item B4)

This section comes from a read-only reading of the PHP code at the base of this branch; nothing was run. All paths
are under `trading-app/`, and line numbers are those at the time of writing.

**What exists:**

1. **No adapter can amend a stop in place.**
   - `ExchangeAdapterInterface` offers only `placeOrder` and `cancelOrder` as order writes
     (`src/Exchange/Contract/ExchangeAdapterInterface.php:43-45`).
   - Every adapter declares `supportsModifyOrder: false`: `src/Exchange/Adapter/OkxExchangeAdapter.php:70`,
     `HyperliquidExchangeAdapter.php:82`, `FakeExchangeAdapter.php:89`.
   - No file under `src/` mentions an amend endpoint.
2. **The only code path that replaces the stop of an open position cancels first and places afterwards.** It is
   `TpSlTwoTargetsService::__invoke` (`src/TradeEntry/Service/TpSlTwoTargetsService.php`):
   - **Cancel (:550-572).** It cancels the existing stop when it differs from the new one by more than one tick.
     A cancel exception is caught and only logged (`tp2sl.cancel_sl_failed`).
   - **Between the two steps (:587-648).** It cancels the existing take-profits and computes the take-profit split,
     which can call the indicator provider for ATR.
   - **Place (:685-713).** It then places the new stop.
   - **No gating and no confirmation.** The cancel result does not gate the placement, and the new stop is not
     confirmed through `getOpenOrders`.
   - **Failure of the second step.** A refused placement only drops out of the result. An exception propagates to
     the caller, which logs it (`MtfRunnerService.php:1071-1078`, reported by the code survey). Nothing rolls back,
     re-protects, closes or trips anything. Inference from the code: if the cancel succeeded and the placement
     failed, the position is left without a stop.
   - **Failure of the first step.** If the cancel fails or is skipped, a second full-size reduce-only stop is placed
     next to the first.
   - **Callers:**
     - the MTF runner's TP/SL recalculation, run when the minute is a multiple of 3
       (`src/MtfRunner/Service/MtfRunnerService.php:1096-1101`) with `cancelExistingStopLossIfDifferent: true`
       (`:1055`);
     - an HTTP endpoint and a console command (code survey).
3. **A stop beyond entry is not recognised as a stop.** The service classifies orders by price against entry; for a
   long, a stop is any reduce-only close order priced below entry (`TpSlTwoTargetsService.php:540-544`). A stop
   moved to break-even or beyond, which is exactly what V1 does, would be treated as a take-profit by that path.
4. **The entry-time path is stricter.** `ProtectionEnforcer::enforceAfterEntryFill`
   (`src/TradeEntry/Execution/ProtectionEnforcer.php:34`) confirms the stop through `findConfirmedStopLoss` (:383)
   and calls `failAndEmergencyClose` (:319) on any failure. The replacement path has neither.
5. **OKX write gate** (`src/Exchange/Okx/Demo/`):
   - **No "modify stop" kind.** There are three write kinds, `entry`, `take_profit` and `protective`
     (`OkxDemoWriteKind.php`), so a replacement is two separately gated `protective` writes.
   - **The durable trip refuses every kind, protective included** (`OkxDemoWriteGate.php:125-127`).
   - **A kill-switch refusal also refuses a protective write**, unless its only reasons are private-stream
     observability or the notional cap (`:139-156`).
   - **A failed audit write trips the gate** (`OkxDemoGuardedExchangeAdapter.php`, `audit_after_failed`). If that
     happens right after the cancel of a replacement, the re-placement is refused (inference from the code).
6. **Hyperliquid:** there is no modify action, and dependency injection wires `$mutationProof: null`
   (`config/services.yaml:286-288`), so the adapter cannot write at all.
7. **The fake exchange is the only atomic case.** It ratchets its trailing stop in place, and runs the TP1 → trailing
   replacement inside `runAtomically`. `tests/Exchange/Fake/FakeTp1TrailingTest.php` (rollback test at :826) shows
   that when the replacement fails, the original stop is still open.

   That proves atomicity of the simulator, not of any exchange.
8. **Reconciliation only reports.** `ExchangeReconciliationService::detectUnprotectedPositions` (:235) reports
   unprotected positions and takes no action.
9. **No test covers a live replacement failure.** No test exercises the real cancel-then-place sequence with a
   failure of either step (code survey of `tests/`).

**Conclusion.** It is **not demonstrated** that a protective stop can be replaced without a window during which the
position has no stop. The only replacement path today cancels before it places, does not confirm the new stop, and
does not recover when it fails.

Therefore **no adaptive stop modification is allowed live** (demo included). TIGHTEN_STOP exists only in simulation,
where it is modelled as atomic and labelled as an assumption.

A pessimistic simulation variant in which every TIGHTEN_STOP fails is a useful bound: V1 then reduces to the static
stop plus its partial and full closes.

**After the independent review (2026-10-16).** The non-atomic case is now injectable in the simulator:
`FailureKind.REPLACE_CANCELLED_PLACE_FAILED` cancels the old stop, fails the new one, leaves the position unprotected
for the bar (counted at full notional) and closes it by the fail-safe at the next open (R2-F12). Two further review
findings concern this policy as measured: the simulator feeds `LiquidityStatus.UNKNOWN`, so with
`defer_partial_unless_liquidity_normal` the partial-close branch never fires and B2/B3 measure a **trail-and-target
policy only** (R2-F9); and every adaptive action applies one bar after its decision at hourly bars, so a trail
validated against a one-hour-old close can become a gap exit at the next open (R2-F5, counted per trade as
`stop_through_open_at_action_application`). Both are recorded in
[H3-INDEPENDENT-REVIEWS.md](H3-INDEPENDENT-REVIEWS.md).

**Prerequisites before any live use** (owner decisions, none started here):

- either an amend-in-place endpoint confirmed for the target product (OKX Europe X-Perps), with a test showing that
  the old trigger remains live when the amend fails;
- or a place-new-then-cancel-old sequence. This option needs:
  - the new stop confirmed live before the old one is cancelled;
  - a proof that two reduce-only stops cannot over-reduce or reverse the position on that venue (unverified today);
  - an emergency close when the new stop cannot be confirmed.

Either option also needs:

- a stop classification that does not depend on the entry price;
- a write kind for the replacement in the write gate;
- tests that inject a failure at each step.

## 10. What remains DRAFT, and open questions

**DRAFT values.** Every parameter in §4, the state thresholds and the action table are DRAFT. The time parameters
must be re-derived from the frozen decision cadence.

**Contract change requests** (integrator decision; private workarounds noted):

1. **Pin the unit of `PositionState.volatility`**, for example price units per bar of the frozen cadence. V1 ignores
   volatility until the unit is pinned.
2. **Add a protection status to `PositionState`**, for example `live`, `replacing` or `absent`. Today the snapshot
   cannot represent "the stop is being replaced" or "the stop is missing". The gap shows up only as BREACHED once the
   price crosses the stop.
3. **Track the outcome of failed attempts.** Add a field such as `actions_failed`, or a last-outcome code, so that a
   retry budget can be defined. Today a failing modification is re-proposed on every snapshot.
4. **Specify how MFE/MAE are built.** They should come from observations after the fill, excluding the entry bar's
   pre-fill extremes, and the docstring should say so.

**Open questions for the owner:**

- Kill switch: hold, as V1 does by default, or flatten?
- Should the partial-to-full promotion rule ever be enabled?
- Should a minimum trailing step be adopted to limit write frequency, and on what a-priori basis?
- Is a partial close wanted at all, given that it may never happen in a steady trend?

**Not covered here:** fills, fees, slippage and funding accrual (integration simulator), the B0–B3 comparison
protocol (validation package), and any real data.
