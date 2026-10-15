# H3 — independent reviews and responses (2026-10-16)

**Status: DRAFT research prototype, synthetic data only.** Two independent reviewers examined the integration branch
`claude/h3-opportunity-architecture` at commit `c10a2a2b`. This page records their verdicts, every finding, and what
was done about it. The owner's rule applies: every criticism is either fixed or documented as a blocker. No finding
was dropped.

Neither the reviews nor the responses read any market data, opened any price, return or trade file, called an
exchange or placed an order. Every number below comes from synthetic data and is not evidence about any market.

## 1. Verdicts

| Reviewer | Scope | Verdict |
|---|---|---|
| R1 | Quantitative methodology, adversarial, read-only | **requires architecture fixes** |
| R2 | CTO, risk and security, read-only | **architecture sound, with fixes** |

**R1 (summary).** The contract-level anti-lookahead rules hold: observed ≤ available ≤ decision, closed bars only,
fills at the next open, stop first inside a bar. Input identity and fingerprints are enforced. The intersection-union
primary and the serial-gatekeeping logic are correct as statistics, and agent C was right to reject the percentile
circular block bootstrap. The team is honest that nothing can run today: there is no generator, no calibrated model,
no unread data and no validated procedure.

R1's main refutation is that the B0–B3 design would still not answer the owner's questions separately once those
blockers are lifted, for five reasons:

- S1 is confounded by abstention.
- Serial gatekeeping prevents separate answers.
- Labels are capacity-selected.
- The power study assumes paired trades.
- The synthetic acceptance rule has no Monte Carlo precision.

R1 also found that fills one hour late select the traded sample, that independence is checked by dataset id only,
and that no candidate source fits the factorial.

**R2 (summary).** The core risk architecture holds:

- a score never sets a size;
- an action changes a position only after an ADMIT, with one pending action per position;
- stops never widen;
- partial closes are strictly partial and on the lot grid;
- duplicate candidates are refused;
- costs are charged once.

R2 listed five items to fix before any frozen protocol or non-synthetic run:

- F1: a venue-filled entry was dropped by the fill re-check.
- F2: retried closes could not be deduplicated.
- F3: `run_factorial` did not check the risk policy, the readiness or the provenance.
- F4: R measured at the fill price.
- F5: actions applied one bar late.

Seven minor items were listed as well (F6 to F12).

## 2. How each finding was verified

Every finding was checked by the responder before any response, as follows:

- **R1's probes** (synthetic scratch scripts, not committed) were re-run unchanged.
  - Zero-skill random abstention under a negative mean: S1 rejects in 74 % of panels and "H3-A supported" appears in
    44 % (μ0 = -0.2 R, adaptive +0.4 R). With μ0 = -0.3 R and adaptive +0.6 R, the figures are 94 % and 81 %.
  - Partial overlap: P1 power at δ = 0.3 falls from 1.0 to 0.60 (iid) and 0.38 (regime) when B3 trades a 43 %
    subset.
  - Binomial check of the acceptance rule: an exact-size test fails it with probability 0.13, 0.24 and 0.34 over 12,
    24 and 36 cells. The 0.080 figure has a 95 % interval of [0.053, 0.107].
  - The e2e example: 222 of 273 candidates had no B0 label, the rank-IC test had n = 1, and B0 had 38
    `fill_beyond_collar` refusals.
- **R2's probes** (synthetic scratch scripts, not committed) were re-run unchanged. They reproduced every symptom: no trade
  and no ORDER record, a kill-switch exit 1,000 ms before its decision, realised R of 13.10 against 2.34, an off-tick
  stop admitted, an unscored B1 entry admitted, a doubled partial close, and the `research_wallet_unreconciled`
  crash.
- **Code and PHP reading.** The responder read `compare.py`, `factorial.py`, `registry.py`, the simulator and the
  gate. On the PHP side, read-only: `OkxDemoWriteGate.php` (lines 139–154) and
  `DemoTradingSafetyPolicyEvaluator.php` (lines 82–84).

One nuance on R2-F1's first probe. The kill switch was decided at T0+2h+1s, after the fill at the T0+2h open. The
defect was therefore that the simulator applied a later kill switch to an earlier fill, a look-ahead, rather than a
kill switch tripping between admission and fill. The defect was real either way, and the fix covers both readings.

## 3. Findings and responses

Severity is the reviewer's. "Fixed" means fixed in code with a test, unless only documentation was at fault.

### R1 — quantitative methodology

| Id | Severity | Title | Location | Response |
|---|---|---|---|---|
| R1-F1 | blocker | S1 (B1 - B0) cannot separate scoring skill from abstention and exposure; no matched random-abstention control | `validation/plan.py`, `compare.py`, `factorial.py`; EXPERIMENTAL-DESIGN §2, §4 | **Partly fixed, rest a documented blocker.** `compare.evaluate` can no longer report a hypothesis as `supported`. When every test rejects it reports `rejects_null_attribution_not_identified`, with the reasons in `ATTRIBUTION_BLOCKERS` (tests: `test_strong_combined_effect…`, `test_r1_f1_…`, `test_review_r1_f1_…`). The matched controls (B0m random abstention over K seeds, an exposure-matched static control) need a `Variant` wire change and a protocol decision: schema v2 #22, pre-registration §12. |
| R1-F2 | major | Serial gatekeeping on B3 makes H3-A and H3-B unconfirmable on their own | `compare.py`; PREREGISTRATION §6 | **Documented blocker.** The multiplicity procedure that gives H3-A and H3-B their own alpha (graphical or fallback procedure, or Holm over {S1, S2, B3 vs cash}) is an owner choice before any freeze (pre-registration §12, [H3-DECISION.md](H3-DECISION.md)). Meanwhile the code reports no hypothesis as supported (R1-F1). |
| R1-F3 | major | Capacity-limited B0 labels make the C4 checks inoperable; no capacity-free replay | `scoring_checks.py`, `validation_adapter.py` | **Fixed.** New `integration/label_replay.py` (`capacity_free_labels`): every candidate is replayed alone with a fresh gate and the B0 (or B2) policy, giving labels in net R and in A's gross-R unit. New `scoring_checks.join_scores_labels`. In the example 149 of 273 candidates are labelled (B0 had 52), and the rank-IC test has 8 daily blocks instead of 1. Pre-registration §10 item 9 is now a requirement. Tests: `test_r1_f3_…` (×2), `test_review_r1_f3_…`. **Residual:** 124 candidates are refused even alone by the collar, which is the timing blocker (R1-F6). |
| R1-F4 | major | The power-study DGP assumes B0 and B3 trade the same candidates | `validation/power.py`; PREREGISTRATION §8 | **Documented blocker.** The re-study must run through the real mechanics (simulator, gate, capacity, abstention), with a negative μ0, partial overlap, at most one candidate per week and multi-week holds, and report the size and power of P1, P2, the IUT and S1–S4 (pre-registration §12). It runs on synthetic data only, after the owner chooses the candidate source. |
| R1-F5 | major | The acceptance rule has no Monte Carlo precision for a maximum over many cells; "about 104 weeks" is over-interpreted | C's private power artefacts; PREREGISTRATION §8.2; DATA-ELIGIBILITY §3 | **Docs fixed, re-run a documented blocker.** The "no procedure below about 104 blocks" wording is corrected in PREREGISTRATION §4, §7, §8.2 and §10 and in DATA-ELIGIBILITY §3. Each now gives the interval and the multiple-cell failure probability. C's private study artefacts were left untouched as records. A re-run needs about 4,000 panels per cell, or a multiplicity-adjusted upper bound, before any window is chosen. |
| R1-F6 | major | One-hour-delayed fills behind a one-sided collar select the traded and labelled sample | `simulator.py`, `risk_gate.py` | **Documented blocker, measurement added.** Refusal reasons are now published per variant in the e2e report: about 43 % of B0 entry attempts and 45 % of candidates replayed alone are refused at the collar (synthetic). The execution-timing rule is an owner decision before any label exists: sub-bar fills at the decision, or re-anchoring the reference at the fill (pre-registration §12). |
| R1-F7 | major | Data independence is checked by dataset id only, not by underlying and time window across venues | `registry.py`; `contracts.Provenance`; DATA-ELIGIBILITY | **Partly fixed, rest a documented blocker.** Registry and document were reclassified. A new `any-venue-2026-10-14-to-2026-10-31` row is RESERVED. Three rows became `consumed_equivalent` (`binance-other-intraday-2023-to-2026-10-13`, `hyperliquid-public-to-2026-10-13`, `kraken-futures-public-to-2026-10-13`); only Binance intraday before 2023 stays possibly independent. `okx-xperp-metadata` (prices inside the quarantine) is RESERVED/never. The (underlying × time) overlap rule needs schema v2 (#20). Tests: `test_registry_rules_and_conclusion`, `test_registry_matches_the_eligibility_document`. |
| R1-F8 | blocker | No candidate source supports the factorial; the scorer catalogue fits none and brings back cross-sectional momentum | candidate-source dossiers (PR #513); OPPORTUNITY-SCORING §2; PREREGISTRATION §2, §7 | **Documented blocker. This sets the decision status** ([H3-DECISION.md](H3-DECISION.md)). Confirmed: CS-UNLOCK fails M3 and gives at most about 40 trades a year; CS-FUND gives 2 candidates; CS-XMOM is unsound. A's catalogue holds `xs_rel_ret_lb` (the e2e refuses on `feature_outside_training_support:xs_rel_ret_lb`). Before any freeze, the catalogue must be versioned to the chosen generator, and its relative-return features dropped or justified against M4. |
| R1-F9 | minor | The minimum-information guard covers P1 only; ESS is lenient near n = 26 | `compare.py`, `stats.py` | **Fixed (guard); estimator choice documented.** The effective-block floor now also applies to P2 (B3 against cash). A secondary whose series has too few effective blocks is `secondary_insufficient_effective_blocks`, never confirmatory. Test: `test_r1_f9_…`. A bias-corrected ESS, or a fixed pre-registered floor, is part of the procedure choice (owner). |
| R1-F10 | minor | The pessimistic guard is weaker than pre-registered; the cash comparator is called "conservative" | `integration/costs.py`; PREREGISTRATION §7; EXPERIMENTAL-DESIGN §4 | **Fixed.** Under PESSIMISTIC, `cost_rates` scales fees, spread, slippage and the funding provision, and the simulator scales adverse funding cash flows (credits are not scaled), as pre-registered. The "conservative" wording is corrected: a 0 % comparator is lenient. Pre-declaring a cash yield is an owner decision. Tests: `test_pessimistic_scenario_scales_every_cost_component…`, `test_r1_f10_…` (×2). |
| R1-F11 | minor | Replay-integrity detectors are unusable as wired (fill-realism false positives, a weak oracle check) | `adaptive_checks.py` | **Mostly fixed; the oracle extension is a documented residual.** An exit stamped on a bar boundary is now checked against the bar ending there and the bar starting there. The false `fill_exit_outside_bar_range` findings (B0 10, B2 12) are gone. A harness-level future-perturbation test was added: bars after a cut are moved +3 %, every risk decision before the cut stays identical, and decisions after it change. Tests: `test_r1_f11_…` (×2), `test_review_r1_f11_…`. Extending the oracle check to stop exits after tightening and to the "next open after the window extreme" pattern is not done (residual risk 4). |
| R1-F12 | minor | The development trial ledger is in-memory and voluntary; B0 uses one lottery draw | `walkforward.py`, `factorial.py` | **Documented blocker for any freeze.** The ledger must be an append-only, hash-chained file whose head is fingerprinted in the manifest, and B0/B2 must be evaluated over K pre-declared lottery seeds (pre-registration §12). No development trial has been run on real data, so nothing is under-disclosed today. |

### R2 — CTO, risk and security

| Id | Severity | Title | Location | Response |
|---|---|---|---|---|
| R2-F1 | major | A venue-filled entry is dropped when the fill re-check refuses it; the sent order is never journalled | `simulator.py` (`_fill_entry`), `risk_gate.py` | **Fixed.** Five changes. (1) Pre-send checks use only what is known before sending (kill switch active *at that instant*, equity), and a refused order is never sent. (2) The ORDER is journalled before the re-check. (3) A filled order is venue truth: if `admit_fill` refuses it (through the stop, or limits at the current equity), the position is closed at once at the same open (`fill_recheck_failed` → new `ExitReason.FAIL_SAFE`), never dropped. (4) An IOC limit never fills beyond its price, whatever the injected truth says. (5) A fill processed after a later kill-switch decision is flattened too. Tests: `test_r2_f1_…` (×5). |
| R2-F2 | major | Adaptive action ids derive from the state snapshot, so a retried close is never deduplicated; action-level venue truth is ignored | `contracts.AdaptiveAction`, `simulator.py` | **Fixed in the simulator; the live part is a documented blocker.** `filled_on_venue` on an action's NETWORK_ERROR is now honoured: the action is applied once, the next state reflects it, and no second close is decided. `filled_on_venue` with any other failure kind is refused. A stable intent key as client order id, plus an unresolved-order status, is a contract change (schema v2 #19). It is needed before any live port, and live stop modification stays forbidden (B4). Test: `test_r2_f2_…`. |
| R2-F3 | major | `run_factorial` does not check the risk policy, the readiness or the provenance of what ran | `factorial.py`, `e2e.py` | **Fixed.** Every factory-built gate must expose a `policy` equal to `manifest.risk_policy` (BASE) or to an explicitly declared `risk_policy` (PESSIMISTIC; required). The B1/B3 readiness verdict is required whenever a dataset is not synthetic, and must be empty. Every candidate and snapshot provenance must be in `manifest.datasets`. The e2e passes both. Test: `test_r2_f3_…`. **Residual:** the pessimistic risk policy is not anchored in the manifest, and `VariantRun` carries no risk-policy fingerprint (schema v2 #21). |
| R2-F4 | major | R is the gate's risk at the actual fill price, so R-multiples swing up to ~3x | `simulator.py`, `validation_adapter.py` | **Fixed.** A trade's R is the admission's `risk_amount`: the admitted quantity at the collar, known before the fill, never above 0.5 % of the equity it was sized on. The fill-time risk is kept as `Trade.fill_risk`. In R2's probe the trade now shows +2.3 R instead of +13.1 R. Tests: `test_r2_f4_…`, `test_risk_caps_are_never_exceeded`. A's gross-R label is a second, documented unit (backlog #17); the label replay provides both. |
| R2-F5 | major | Actions apply one full bar after the decision; trailing stops become delayed market exits in B2/B3 only | `simulator.py`, `adaptive/policy.py`, `adaptive/guards.py` | **Measured; the rule is a documented blocker.** A stop tightened through the open at application is flagged per trade (`stop_through_open_at_action_application`) and counted per variant (`action_application_gap_exits`). In the example every B2/B3 gap exit is action-induced (B2 12, B3 5; B0/B1 0, tested). The timing rule for entries *and* actions is an owner decision (pre-registration §12). Test: `test_r2_f5_…`, `test_review_r2_f5_…`. |
| R2-F6 | minor | Kill-switch closes execute before the kill-switch decision; H3 lets closes through a kill switch that `OkxDemoWriteGate` refuses | `simulator.py`; `adaptive/guards.py`; ARCHITECTURE; PHP | **Fixed (timing and wording); the close semantics are an owner decision.** A pending close carries its earliest execution instant, the kill switch is active only from its decision instant, and a flatten never executes before it. "Mirroring `OkxDemoWriteGate`" is corrected in the gate docstring, ARCHITECTURE §5 and RISK-AND-EXECUTION. Production refuses every write under the kill switch, reduce-only closes included. Whether H3 should model closes as refused is listed for the owner. Test: `test_r2_f1_and_f6_…`. |
| R2-F7 | minor | The gate sizes and admits off-tick stops and targets | `risk_gate.py` | **Fixed.** `stop_not_on_tick` and `target_not_on_tick` refusals. Test: `test_r2_f7_…`. |
| R2-F8 | minor | The gate does not require a score for scored portfolios | `risk_gate.py` | **Fixed.** From `PortfolioView.variant`: B1/B3 without a score are refused with `score_missing`, and B0/B2 with a score with `score_not_expected`. Gate `policy_version` is now `0.2.0`. Tests: `test_score_gating_and_structural_refusals`, `test_r2_gate_policy_version…`. |
| R2-F9 | minor | The partial-close branch of `AdaptivePolicyV1` can never fire in the H3 simulator | `simulator.py` (liquidity UNKNOWN), `adaptive/params.py` | **Fixed (disclosure and adapter test); the liquidity feed is an owner decision.** The pre-registration (§12) and ADAPTIVE-MANAGEMENT now state that B2/B3 measure a trail-and-target policy only. A scripted partial close now goes through the adapter and checks the multi-exit translation (`test_r2_f9_…`). Feeding a liquidity status needs a pre-declared rule. |
| R2-F10 | minor | `portfolio_simulator.finish()` raises after a liquidation-proxy exit (outside H3) | `app/backtesting/research/portfolio_simulator.py` | **Reproduced; documented as an owner-side item, not fixed here.** The file is outside the H3 scope (the orchestrator `app/`) and is a certified kernel imported by 14 test modules. It fails closed: no wrong number, but the whole run is lost. The fix (tick-quantised liquidation price, or reconciliation from the cash-flow ledger, plus a regression test) is listed in [H3-DECISION.md](H3-DECISION.md). |
| R2-F11 | minor | Packaging and CI coupling: research code ships in the runtime image; a Python test regex-checks PHP sources | `python-orchestrator/Dockerfile`, `.dockerignore`; `test_research_h3_integration.py` | **Partly fixed, rest an owner item.** The PHP-coupled assertion now says that the research map is out of date and which file to update, not production. Excluding `scripts/research_*` from the image is a docker change, forbidden in this mission. It also concerns the pre-existing `research_lev` and `research_h2` packages, so it is listed for the owner. |
| R2-F12 | minor | The dangerous stop-replacement failure (cancel succeeds, place fails) cannot be injected | `simulator.py`; RISK-AND-EXECUTION | **Fixed.** New `FailureKind.REPLACE_CANCELLED_PLACE_FAILED` (TIGHTEN_STOP only): the position is unprotected for the bar, its stop is not honoured, it is counted at full notional, and the fail-safe closes it at the next open (`ExitReason.FAIL_SAFE`). A sensitivity scenario with a per-modification failure rate is added to the DRAFT pre-registration (§12, rate to be set by the owner). Test: `test_r2_f12_…`. |

**Rejected findings: none.** Every finding was reproduced or confirmed by reading.

## 4. What changed in the code

All changes are under `python-orchestrator/scripts/research_h3/` and its tests. No contract wire form changed: A's
golden vectors still print `golden vectors: OK`.

- `integration/simulator.py`:
  - entry path with pre-send checks, ORDER first, venue truth and the fail-safe close at once;
  - R fixed at admission, plus `fill_risk`;
  - a time-aware kill switch and a time-stamped pending close;
  - venue truth for actions;
  - `REPLACE_CANCELLED_PLACE_FAILED`;
  - pessimistic adverse funding;
  - action-induced gap-exit and fail-safe counts.
- `integration/risk_gate.py`: tick-grid and score/variant refusals, `policy_version` 0.2.0, corrected kill-switch
  wording.
- `integration/costs.py`: the pessimistic scenario scales every cost component.
- `integration/label_replay.py` (new): capacity-free labels.
- `integration/validation_adapter.py`: exit reasons mapped to `FAIL_SAFE`, and the R convention.
- `validation/records.py`: `ExitReason.FAIL_SAFE`. A fail-safe closed at its fill instant may have an entry beyond
  its stop; this is the only exception.
- `validation/factorial.py`: risk-policy, readiness and provenance checks.
- `validation/compare.py`: attribution blockers and the information guard on P2 and on the secondaries.
- `validation/adaptive_checks.py`: the bar-boundary exit rule.
- `validation/scoring_checks.py`: `join_scores_labels`.
- `validation/registry.py`: the cross-venue quarantine and the consumed-equivalent rows.
- `e2e.py`: wiring for the new checks, capacity-free labels in the report, refusal reasons and action-induced gap
  exits per variant.
- Tests:
  - `tests/test_research_h3_reviews.py` is new (18 test functions);
  - `test_research_h3_e2e.py` gets 4 new tests;
  - `test_research_h3_integration.py` and `test_research_h3_validation.py` get updates. These were tightened, never
    loosened: for example, `supported` is now impossible, and fees must be scaled.

The synthetic example still runs end to end. Its figures moved:

- B0 makes 52 trades instead of 51, one of them a `fail_safe` close of an entry filled through its stop.
- The fixture-path verdict is still `INSUFFICIENT_EVIDENCE` (8 daily blocks), evidence `synthetic_not_evidence`.
- Every hypothesis is still `not_confirmatory`.

## 5. Residual risks

1. **Nothing can run on real data.** No candidate source is both powerful and generator-compatible, and there is no
   calibrated model, no independent historical data and no validated test procedure. The status in
   [H3-DECISION.md](H3-DECISION.md) follows from this.
2. **Attribution.** Until the matched controls and a multiplicity procedure with separate alpha exist, the design
   can say whether B3 beats B0 and cash, but not *why*. The code refuses to say "supported".
3. **Execution timing.** Every synthetic trade, and every label, is conditioned on the next-bar-open convention. This
   affects the collar refusals (about 43–45 %), the action-induced gap exits and the adverse selection. It is now
   measured, not fixed.
4. **Replay-integrity coverage.** The future-perturbation test covers one synthetic path. The oracle detector still
   examines only full adaptive closes.
5. **Data independence by id.** The registry is conservative by hand. A new dataset id over consumed or quarantined
   paths would pass the code until schema v2 adds windows and underlyings.
6. **Live safety is out of scope and stays forbidden.** Live stop modification (B4), a stable intent key for retried
   closes, the X-Perp instrument resolver (GAP-XPERP) and the kill-switch close semantics are all unresolved for
   TradingV3. No H3 code can place an order.
7. **Power and size are unknown for the real design.** The synthetic studies modelled paired trades, and their
   acceptance rule is imprecise. No window can be chosen from them as they stand.
8. **Out-of-scope defect.** `portfolio_simulator.finish()` loses a whole run after a liquidation-proxy exit (R2-F10,
   owner-side).
