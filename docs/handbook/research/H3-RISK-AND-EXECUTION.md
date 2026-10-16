# H3 — Risk admission, simulated execution and TradingCore integration (DRAFT, 2026-10-15)

Agent D of the H3 mission ("Opportunity Scoring & Adaptive Position Management"). This page covers how the scoring
engine (H3-A) and the adaptive manager (H3-B) plug into TradingV3 without degrading it.

- **Status.** Research prototype on synthetic data only. No order, no exchange call, no market data file, no
  production PHP class or configuration changed, no Temporal workflow touched.
- **No claim.** Nothing here is evidence of an edge. Every number below comes either from the code read on
  2026-10-15 (commit `31bda7e8`) or from synthetic tests.
- **Code.** `python-orchestrator/scripts/research_h3/integration/`.
- **Tests.** `python-orchestrator/tests/test_research_h3_integration.py`.
- **Migration plan.** `H3-IMPLEMENTATION-PLAN.md`.

## 1. Target flow and responsibilities (D2)

```
Candidate Provider ──► Opportunity Scoring ──► Risk Admission ──► Order Plan ──► Fake/Paper Execution
  (common generator,      (H3-A: score, rank,     (H3RiskGate         (admitted     (run_variant today;
   no score read)          NO_TRADE allowed)        .admit_entry)       RiskDecision)  Fake/Paper later)
                                                                                         │
        Audit ◄── Risk Validation ◄── Adaptive Management ◄── PositionState (data available at D only)
   (AuditJournal,  (H3RiskGate            (H3-B: ManagementPolicy
    hash chain)     .validate_action)      returns an AdaptiveAction value)
```

| Stage | Component (owner) | Produces | Must not |
|---|---|---|---|
| Candidate provider | common generator from a candidate-source dossier (CS-FUND / CS-UNLOCK / CS-XMOM or other); today only `SyntheticCandidateProvider` (D) | `OpportunityCandidate` with a mandatory protective stop | read scores; claim an economic edge (the synthetic one is `synthetic-no-economic-claim`) |
| Opportunity scoring | H3-A | `OpportunityScore` (UNESTIMATED without a calibrated model) | size a position or emit an order |
| Risk admission | `H3RiskGate.admit_entry` (D) | `RiskDecision` (ENTRY): quantity, IOC limit price, stop | admit anything that breaks `H3_LIMITS` (the contract makes it unconstructible) |
| Order plan | today the admitted `RiskDecision` (quantity + limit price + attached stop); a `CanonicalOrderPlan` only after the gaps of section 9 | an order description | exist without an ADMIT decision |
| Execution | `run_variant` (D); later `FakeExecutionPort` / Paper replay | fills, exits, cash flows | fill beyond the collar, or fill without `admit_fill` |
| Adaptive management | H3-B `ManagementPolicy.decide(PositionState)` | an `AdaptiveAction` value | change a position or the portfolio itself |
| Risk validation | `H3RiskGate.validate_action` (D) | `RiskDecision` (ACTION) | let an action widen a stop or raise open risk or notional |
| Audit | `AuditJournal` (D) | append-only hash chain per experiment and variant | be edited in place |
| Trigger | Temporal (unchanged) | a call at a schedule | compute scores or decisions (section 8) |

**How the separations are enforced in code (not only by convention):**

- **Positions come only from the gate.** `run_variant` sends an order only for an ADMIT `RiskDecision` issued by
  the gate, and only when the kill switch is off and the equity positive at the open. Since review R2-F1 a filled
  order is venue truth: when `admit_fill` refuses it at the actual fill price (through the stop, or limits at the
  current equity), the position is closed at once by a reduce-only fail-safe (`fill_recheck_failed`) instead of being
  dropped, and the ORDER is journalled before the re-check. The R unit of the trade is the admission's
  `risk_amount` (at the collar, before the fill: review R2-F4); the fill-time risk is kept as `fill_risk`.
- **Scores are inputs to the gate, not orders.** The gate refuses a candidate with a NO_TRADE score or a score for
  another candidate. A score never changes the size (tested).
- **The policy only returns a value.** `PositionState` is a frozen copy built from bars with
  `available_ms <= decision_ms`. The simulator applies the returned action at the next open only after
  `validate_action` admits it. A policy exception becomes a `policy_error` refusal and a HOLD.
- **The portfolio is private to the simulator.** Neither the policy nor the scorer receives a mutable object.

## 2. What exists in TradingV3 (D1: implemented, tested, documented)

Static reading only: no PHP was run. "CI" names the GitHub workflow that runs the test. Paths are under
`trading-app/` unless stated otherwise.

| Component | Implemented | Tested by | In CI | Documented | H3 decision |
|---|---|---|---|---|---|
| `CanonicalRiskEngine`, `CanonicalRiskPolicy(+Compiler)`, request, decision, cost and instrument snapshots | yes. Cost-inclusive sizing, leverage and notional caps, quantisation | `tests/TradingCore/Risk/Canonical/*`, `OrderPlan/Canonical/CanonicalNetREngineTest` | **no** | `technical/risk-and-leverage-module.md` (#304 Lot A) | reuse its **risk definition** (same components); port H3 rules to it later via a mode contract |
| `CanonicalPortfolioAdmissionEngine`, policy, snapshot, reservation and store | yes. Daily loss cap, concurrency, mode exposure, duplicate decision key, reservation lifecycle | `tests/TradingCore/Risk/Canonical/Portfolio/*`; the Paper store `tests/Trading/Paper/Execution/Strategy/*` | Paper store only (`obs003-trading.yml`) | same page (#304 Lot C) | target for H3 admission. Gaps: total open risk, occupancy, adaptive transitions |
| Portfolio adapters (Fake / Paper / Backtest / Runtime) + selector | yes. Marker subclasses; only the injected store differs | `CanonicalPortfolioAdapterParityTest` | no | Lot C | Runtime adapter is not wired anywhere outside its test |
| `CanonicalOrderPlanBuilder` / `Validator` / `Authority`, `CanonicalProtectionEngine`, `CanonicalNetREngine`, `NetRCostMath` | yes. Limit orders only, entry inside a zone, stop from ATR or pivot, net-R gate | `tests/TradingCore/OrderPlan/Canonical/*`; Paper `PaperExecutionServiceWiringTest` etc. | Paper tests only | Lot B | reuse later. Gaps: market/IOC entry, explicit stop kind |
| `FakeExecutionPort` + scenarios (accepted, rejected, stop attach failure, partial fill + stop rejected, cancel) | yes, on the **legacy** `OrderPlan` DTO | `tests/TradingCore/Execution/Fake/*` | no | `technical/fake-paper-gateway.md` | its scenario vocabulary is mirrored by `FailurePlan`; it does not consume `CanonicalOrderPlan` |
| `OkxDryRunExecutionPort` | yes, pure preview | `OkxDryRunExecutionPortTest` | no | `technical/okx-dry-run.md` | not used |
| Demo kill switch and safety evaluator (`DemoTradingKillSwitchService`, `OkxDemoWriteGate`) | yes. Every OKX write is classed entry / take_profit / protective | `tests/TradingCore/Execution/Safety/*`, `tests/Exchange/Okx/Demo/*` | no | `runbooks/demo-testnet-kill-switch.md` | H3 kill switch semantics copied: entries and target changes refused, protective actions allowed |
| Effective config resolver, runtime layers | yes. Modes day_trading, scalping, micro_scalping 1.1.0 and swing 1.0.0; all writes disabled | `tests/TradingCore/Config/*` | yes (`trading-core-config.yml`) | `technical/effective-trading-config-resolver.md` | no H3 mode exists; mode budgets must be USDT |
| Live MTF → TradeEntry path | yes. Uses its own `src/TradeEntry/{RiskSizer,OrderPlan,EntryZone}` stack, **not** the canonical engines | `tests/TradeEntry/*` | no | `functional/trade-entry.md` | not touched; not a target for H3 |
| Stop management after entry (`TpSlTwoTargetsService`, `processTpSlRecalculation`) | yes. Replaces a stop by **cancel then place**, the cancel failure is only logged; no amend exists (`supportsModifyOrder` false, never read) | `tests/TradeEntry/Service/TpSlTwoTargetsAdapterMigrationTest` (3 tests, none on the sequence) | no | `functional/trade-entry.md` describes `AttachTpSl`, which is an empty class (drift) | **blocking** for H3-B outside simulation (GAP-STOP-REPLACE) |
| TradingCore legacy services (`Risk/Service/*`, `OrderPlan/Service/OrderPlanBuilder`, `SlTp/Service/*`) | yes | their own tests | no | PR08–PR09 module pages | no runtime caller except the dry-run/Fake ports' validator; not reused |
| `portfolio_simulator.py` (Python) | yes | `tests/test_research_portfolio_simulator.py` (59 tests) | yes (`python-orchestrator.yml`) | module docstring | **not reused as the engine** (section 6); its conventions are reused and cross-tested |
| Temporal `cron_symfony_mtf_workers` | yes. Two workflows, HTTP POST activities only | its own tests | yes (`temporal-cron.yml`) | `technical/temporal.md` | unchanged (section 8) |

**Facts that matter for H3:**

- **CI does not run the PHP canonical risk, order-plan and execution tests.** No workflow runs
  `tests/TradingCore/{Risk,OrderPlan,Execution}`, `tests/Exchange` or `tests/TradeEntry`. The canonical engines
  reach CI only through the Paper execution tests.
- **Nothing in TradingCore validates an adaptive action.** The reservation keeps the admission stop and the full
  quantity until `close()`.

## 3. Integration contracts (what other components call)

| Name | Signature | Notes |
|---|---|---|
| `H3RiskConfig` | `(cost_model: CostModel, assumptions=ExecutionAssumptions(), limits=H3_LIMITS, max_candidate_age_ms=None, policy_id='h3-risk-gate', policy_version='0.1.0')` | its fingerprint is the risk `PolicyRef` fingerprint |
| `H3RiskGate.admit_entry` | `(candidate, score: Optional[OpportunityScore], portfolio: PortfolioView, *, open_instruments=None) -> RiskDecision` | implements `contracts.RiskGate` |
| `H3RiskGate.admit_fill` | `(admission: RiskDecision, candidate, fill_price: Decimal, portfolio: PortfolioView) -> RiskDecision` | `portfolio` excludes this entry's own reservation |
| `H3RiskGate.validate_action` | `(action: AdaptiveAction, state: PositionState, portfolio: PortfolioView) -> RiskDecision` | implements `contracts.RiskGate` |
| `H3RiskGate.risk_of` | `(side, entry, stop, quantity, contract_value, max_holding_ms) -> Decimal` | the R unit, cost-inclusive |
| `run_variant` | `(*, experiment_id, variant, portfolio_id, bars, candidates, policy, gate, scores=None, funding=(), initial_equity=500, quote_ccy='USDC', failures=FailurePlan(), kill_switch_at_ms=None, flatten_on_kill_switch=False, audit=None) -> VariantRunResult` | one variant portfolio |
| `VariantRunResult` | `trades`, `equity_curve`, `refusals`, `risk_decisions`, `audit`, `net_pnl`, `gap_loss_total`, `loss_beyond_nominal_total`, `max_open_risk_fraction`, `max_leverage`, `summary()` | `summary()` is JSON-ready; Decimals are strings |
| `AuditJournal` | `(experiment_id, variant)`; `append`, `record`, `record_contract`, `record_private`, `verify`, `dump_jsonl`, `load_jsonl`, `head_fingerprint` | implements `contracts.AuditSink` |
| `SyntheticCandidateProvider` | `(seed=0, side=None, max_holding_ms=1 day, target_r_multiple=None).candidates(snapshots, decision_ms)` | implements `contracts.CandidateProvider`; fixture only |
| `tradingcore_map` | `FIELD_MAP`, `BLOCKING_GAPS`, `PHP_CLASSES`, `rows_for`, `gap_ids` | data; a test checks it against both the contracts and the PHP sources |

**Conventions the harness must keep:**

- **One gate per variant, or distinct portfolio ids.** The gate remembers the decisions it gave per
  `portfolio_id`, for idempotency.
- **The gate is the cost source.** `run_variant` uses the gate's cost model and assumptions, so the costs the
  gate sized for are the costs the simulator charges.
- **Bars.** One bar interval for every instrument. Bars must be closed. Latency (`available_ms - close_ms`) must be
  under one interval.
- **Candidate timing.** A candidate is processed at the first decision instant `D >= candidate.decision_ms`, and
  it fills at the first bar open `>= D`.

## 4. H3 risk rules as implemented (experimental policy, not production)

Entry sizing in `admit_entry` follows `CanonicalRiskEngine` step by step:

1. **Budget.** `min(0.5 % x equity, 1 % x equity - open risk)`. Open risk includes the reservations of pending
   entries.
   Independent review CTO-10 (2026-10-16): the 1 % is an admission and fill-time cap against current equity, not a
   running invariant. After admission a mark-to-market drawdown can leave open risk slightly above 1 % of marked
   equity (up to about 1.003 % in the synthetic e2e), with no action. Whether a running cap (no new entry above 1 % of
   marked equity) is required is an owner decision, to include in the PHP parity test.
2. **Loss of one contract at the stop, cost-inclusive.** Gross distance + entry fee + stop exit fee + entry and
   stop spread + entry and stop slippage + adverse funding provision. These are the components of PHP's
   `totalStopLoss`.
3. **Size and caps.** The quantity is the budget divided by that loss, floored to `lot_size`. It is then capped by:
   - leverage 1x, as portfolio notional <= equity;
   - margin, as cash - margin in use >= notional + entry costs.

   Unrealised gains do not unlock margin, the same convention as `portfolio_simulator.py`.
4. **Post-quantisation check.** If the risk still exceeds the budget, the quantity drops by one lot, as in PHP.
   The gate then refuses below `min_size` or `min_notional`.
5. **Entry collar.** Every amount is computed at the **entry collar price**: reference x (1 ± 10 bp DRAFT) on the
   tick grid, against the trader. The admitted `price_reference` is that IOC limit price.
   - Any fill inside the collar keeps the cost-inclusive risk within 0.5 % of the equity at decision.
   - A fill beyond it is refused (`fill_beyond_collar`), as an IOC limit would not fill.
   - `admit_fill` re-checks the limits at the actual price and current equity.

**Decision: costs are inside the nominal risk.** This matches `CanonicalRiskEngine`, `CanonicalOrderPlanValidator`
and the research portfolio simulator. Consequences:

- H3's "0.5 % per trade" caps the cost-inclusive loss at the stop, not the gross distance.
- A trade stopped exactly at its stop loses at most R; the funding provision is reserved but never charged
  (tested).

**Action validation.** `validate_action` requires all of the following:

- the action belongs to this exact state (fingerprint);
- the position is open and the action was not already applied;
- a stop moves strictly tighter, on tick, and stays on the protective side of the last price;
- a target is on tick and beyond the last price;
- a partial close is on the lot grid, at least `min_size`, strictly partial, and leaves at least `min_size`;
- open risk and notional never increase. This is checked again even though the contract already forbids it.

Under the kill switch only HOLD, TIGHTEN_STOP, PARTIAL_CLOSE and FULL_CLOSE pass. **Correction (independent review
R2-F6, 2026-10-16):** this is a research rule and does *not* mirror the OKX demo gate. In TradingV3 a kill switch adds
`kill_switch_enabled` (`DemoTradingSafetyPolicyEvaluator`), and `OkxDemoWriteGate` exempts protective writes only
from the observability and max-notional reasons, so every write is refused under the kill switch, reduce-only closes
included: only the venue-side stop protects an open position. Whether H3 keeps closes allowed under the kill switch,
or models them as refused, is an owner decision ([H3-DECISION.md](H3-DECISION.md)). Since the review, a kill-switch
flatten executes only at an open at or after the kill-switch decision.

**Fail closed.** The gate refuses with `portfolio_occupancy_unknown` when it cannot know which instruments are open
(no `open_instruments` given while positions exist).

**Repeated calls.** The same candidate returns the same decision object and id. A different candidate reusing a
candidate id is refused. The simulator also refuses to apply a decision id twice.

## 5. Safety tests (D4) and where they are

All tests are in `tests/test_research_h3_integration.py`. Last run on 2026-10-15 (Python 3.12.3, pytest 9.1.1):
52 passed; with the 39 contract tests, 91 passed in about 6 s.

| D4 requirement | Test(s) |
|---|---|
| 0.5 % per position | `test_per_trade_budget_is_half_a_percent_of_equity_cost_inclusive`. Hand-computed: one contract loses 0.01229 → 203 contracts without collar, 188 with the 10 bp collar |
| 1 % total | `test_total_open_risk_is_capped_at_one_percent`, `test_risk_reduction_stays_admissible_after_equity_fell` |
| leverage 1x | `test_leverage_one_x_caps_portfolio_notional_at_equity`; every admission in `test_synthetic_portfolio_invariants_without_gaps` |
| minimum size, quantisation | `test_quantity_is_floored_to_the_lot_grid_and_minimum_size_refused`, `test_minimum_notional_is_refused` |
| margin, exposure | `test_margin_uses_cash_not_unrealized_gains`, `test_leverage_one_x_caps_portfolio_notional_at_equity` |
| fees and funding, no double counting | `test_fees_and_funding_are_booked_once_and_the_wallet_reconciles`, `test_funding_at_the_entry_instant_is_adverse_possible_credit_dropped`, `test_target_needs_a_trade_through_unless_touch_is_declared` (maker fee) |
| mandatory stop | `test_stop_is_mandatory_and_protective`, the action parametrisation (`stop_not_tightened`) |
| liquidation | `test_liquidation_is_unreachable_for_a_long_at_one_x_and_needs_a_doubling_for_a_short` |
| repeated calls | `test_repeated_admission_is_idempotent_and_conflicting_ids_refused`, `test_action_identity_and_lifecycle_checks`, `test_fill_recheck_refuses_a_fill_beyond_the_limits` |
| position already open | `test_instrument_already_open_and_unknown_occupancy_fail_closed` |
| simulated network errors | `test_injected_entry_failures` (venue filled or not, reconciled once) |
| simulated order failure | `test_injected_entry_failures`, `test_failed_stop_attachment_is_closed_by_the_fail_safe_and_its_loss_measured`, `test_stop_modification_failure_keeps_the_previous_stop`, `test_action_arriving_after_the_stop_filled_is_refused` |
| kill switch | `test_kill_switch_allows_only_risk_reducing_actions`, `test_kill_switch_blocks_new_entries_and_can_flatten` |
| gap loss measured separately | `test_gap_through_stop_loss_is_measured_separately_and_never_clipped` |
| no lookahead in management | `test_policy_sees_only_data_available_at_the_decision` |
| actions never raise risk | `test_actions_are_checked_and_never_increase_risk_or_notional`, `test_partial_close_reduces_open_risk_and_every_admitted_action_reduces_or_keeps_it` |
| determinism and audit integrity | `test_runs_are_deterministic_and_the_audit_chain_detects_tampering`, `test_audit_journal_is_append_only` |

## 6. Why `portfolio_simulator.py` is not the H3 engine, and what is reused

The owner asked not to duplicate an available module. `app/backtesting/research/portfolio_simulator.py` was read
in full. It cannot express H3:

- **Plans are immutable.** Each plan has one fixed stop, one target, one quantity and a TTL. There is no stop
  update, partial close or target change, so B2/B3 cannot run on it.
- **Its rules are hard-wired and differ from H3.**

  | Rule | `portfolio_simulator.py` | H3 |
  |---|---|---|
  | Universe | 10 Binance USDT symbols, `source_venue` `binance_usdm` | any instrument |
  | Data | 1-minute candles | any bar interval |
  | Phase windows | 2023–2025, holdout authority | none |
  | Wallet | 100 000 | 500 |
  | Risk budget | 5 % | 0.5 % per trade, 1 % total |
  | Plan filters | net-R >= 1.3; notional <= 250 | none |
  | Daily loss cap | 6 % / 30 | none |
  | Entry | maker limit | IOC at the next open |

- **Its input is a PHP research plan.** It consumes `research-plan.v1` hashes and identities, not the H3 contracts.

Changing it would touch a certified research kernel used by the swing and holdout machinery (14 test modules
import it).

**Reused (as conventions, kept consistent by cross-tests, not by code import):**

- Decimal arithmetic;
- cost lines `units x price x rate` booked separately;
- a stop gapped at the open fills at the open;
- stop before target in the same bar;
- funding `-units x mark x rate` for a long, at the last known close;
- uncertain funding exposure charged when adverse and dropped when a credit;
- the isolated liquidation proxy formula, with the worse of stop and liquidation winning;
- wallet reconciliation at the end;
- unrealised gains not unlocking margin.

**Cross-tests (Python vs Python only).** Four tests drive `PortfolioSimulator` through its public API on the same
synthetic bars as `run_variant`:

- gap fill price;
- stop vs target in the same bar;
- funding sign and mark;
- liquidation formula.

They pass. **No PHP was run in this mission. Parity between H3 and the PHP engines is not claimed.**

## 7. Divergences (Python simulator, PHP canonical engines, H3)

| Topic | PHP canonical | `portfolio_simulator.py` | H3 integration |
|---|---|---|---|
| Nominal risk | `totalStopLoss`: gross + fees + spreads + slippage + adverse funding provision | same components | same components, computed at the collar price |
| Leverage | `finalLeverage = ceil(notional / available balance)`, an integer per position, <= floor(min caps) | notional <= equity and notional / leverage <= available | portfolio notional <= equity at admission, margin = notional at 1x; **between admissions leverage moves with marks** (reported, not capped) |
| Total risk | daily loss cap = realised + unrealised loss + reserved risk per policy day | `daily_capacity`, same as PHP | open risk <= 1 % at any admission |
| Per-instrument occupancy | `perSymbolLimit` makes admission throw (`..._symbol_occupancy_unavailable`) | `one_active_per_symbol` per profile | always one position or pending entry per instrument, fail closed |
| Entry | limit inside an entry zone, TTL, market fallback forbidden | limit fill on touch, maker fee, target ignored on the fill bar | IOC limit at the collar, filled at the next open (taker), fill bar evaluated (stop first) |
| Target | mandatory, net-R >= `minimumNetR` | fill on touch | optional, fill on trade-through by default (DRAFT) |
| Gap through stop | not simulated | fill at `worst(open, stop)` | same, cross-tested; plus `gap_loss` and `loss_beyond_nominal` |
| Funding | `CanonicalHistoricalFundingSettlement` (not compared) | per event, adverse-possible / credit-certain | same, cross-tested |
| Liquidation | `LiquidationGuard` on the Hyperliquid testnet port only | unrounded isolated proxy | same formula (cross-tested), price rounded on the tick grid against the trader |
| Adaptive actions | none: reservation keeps the admission stop | none: immutable plans | tighten, target, partial and full close, all through the gate |
| Numbers | floats converted to BigDecimal through `json_encode` | Decimal inside, floats at the wire | Decimal throughout, canonical JSON strings |

**Finding on the existing simulator, not fixed (outside this scope).** After a liquidation-proxy exit,
`PortfolioSimulator.finish()` raises `research_wallet_unreconciled`. The cause:

- the proxy price is non-terminating (100 x 0.5 / 0.995);
- the 28-digit Decimal context rounds `wallet` and `100000 + total_cashflow` differently, by 1e-23.

The probe was run on synthetic bars. The existing tests check the liquidation price but never call `finish()`
after a liquidation. H3 avoids the problem by rounding the proxy to the tick grid.

## 8. Temporal keeps its role (D5)

`cron_symfony_mtf_workers` registers two workflows whose activities only POST to:

- `/api/mtf/run`
- `/api/mtf/sync-contracts`
- `/api/maintenance/cleanup`
- the Python orchestrator

They format the response and do nothing else. This mission changed no workflow, schedule or worker. If H3 ever
runs on a schedule, Temporal may only trigger a research job: candidate generation, scoring, risk and management
stay in the research code (later in Symfony services), and every decision is recorded in the audit journal, not in
Temporal history.

## 9. Blocking gaps

They are listed with evidence in `tradingcore_map.BLOCKING_GAPS`, and a test checks the evidence paths exist.

| Id | Gap | Blocks |
|---|---|---|
| GAP-XPERP | OKX code is USDT-SWAP only. The only French execution target is OKX Europe X-Perps (USD prices, USDC margin). The resolver throws on X-Perp ids, `instType=SWAP` is hardcoded, the normalisers drop non-SWAP fills and stops, and USDT is assumed. See [H1-XPERP-TECHNICAL-GAP.md](H1-XPERP-TECHNICAL-GAP.md) and [FRANCE-EXCHANGE-ELIGIBILITY.md](FRANCE-EXCHANGE-ELIGIBILITY.md) | any paper, demo or live H3 on the target venue |
| GAP-STOP-REPLACE | Protective-order replacement is cancel then place, not atomic. A failed place after the cancel leaves no stop; a failed cancel leaves a duplicate. No test covers the sequence | H3-B outside simulation |
| GAP-ADAPTIVE-TRANSITIONS | No canonical reservation transition for stop update, partial reduction or target change | porting `validate_action` to PHP; Paper replay of B2/B3 |
| GAP-TOTAL-RISK | There is no open-risk cap in PHP; the daily loss cap is a different quantity | H3 admission in PHP |
| GAP-OCCUPANCY | Occupied instruments are absent from `CanonicalPortfolioSnapshot` | H3 admission in PHP |
| GAP-MARKET-ENTRY | Canonical plans are resting limits in a zone; there is no IOC | canonical order plans for H3 |
| GAP-STOP-KIND | The protection engine derives stops from ATR or pivot only | canonical order plans for H3 |
| GAP-MODE-CONTRACT | There is no H3 mode or setup contract, and mode budgets must be USDT (`ModeContractValidator`) | any use of the canonical policies |
| GAP-CI-COVERAGE | The TradingCore Risk, OrderPlan and Execution PHPUnit tests are not in CI | trusting any PHP-side change |

## 10. Gap risk and other ways to lose more than R

These are never hidden; each is measured per trade and summed in `VariantRunResult`:

- **Gap through the stop.** The fill is at the open. `gap_loss = units x |stop - fill|`, and
  `loss_beyond_nominal = max(0, -net PnL - R)`. `realized_r` is not clipped.
- **Failed stop attachment.** The position is unprotected until a fail-safe market close at the next open. While
  open, it counts its whole notional as open risk, so nothing new is admitted.
- **Liquidation proxy.** It cannot occur for a long at 1x. A short needs a move of about +99 %.
- **Funding above the provision.** The actual charge is booked and the excess shows in `loss_beyond_nominal`.
- **Equity falling between decision and fill.** `admit_fill` re-checks against the current equity.

On a synthetic no-gap random walk with stops, trailing to entry and expiries, no trade lost more than its R
(`test_synthetic_portfolio_invariants_without_gaps`). This is a property of the arithmetic, not evidence about
markets.

## 11. DRAFT parameters (to be frozen by the owner; none was chosen from performance)

| Parameter | Value | A-priori reason |
|---|---|---|
| `entry_collar_rate` | 10 bp | order of one market leg's fee + slippage (0.05 % + 0.05 %) |
| `half_spread_rate` | 0 | synthetic; must come from captured quotes per instrument |
| `funding_provision_rate` / interval | 0.0001 / 8 h | conventional 0.01 %/8 h interest component; 8 h cadence of OKX swaps and X-Perps |
| `mmr_proxy_rate`, `liquidation_fee_rate` | 0.005, 0.001 | synthetic, same order as the research simulator's fixtures |
| `target_fill` | trade-through | conservative, does not favour target-moving policies |
| `max_candidate_age_ms` | none | delay is at most one bar inside `run_variant`; the harness should set one |
| fee roles | entry, stop and closes taker; target maker | order types modelled |
| synthetic provider | 1 day holding, no target | fixture choices |

## 12. Contract change requests (to the integrator)

- **CR-1.** `CostModel` should carry numeric half-spread and funding-provision parameters. Today they live in the
  private `ExecutionAssumptions`.
- **CR-2.** `PortfolioView` should carry `open_instruments` (sorted), so the gate does not need a side parameter
  to check occupancy.
- **CR-3.** `RiskDecision` should carry the order type and limit price explicitly (today `price_reference` = the
  IOC collar price for entries).
- **CR-4.** `DecisionAudit.header` requires an instrument. Portfolio-level events (the kill switch) borrow a
  candidate's header; a portfolio-level header would be cleaner.
