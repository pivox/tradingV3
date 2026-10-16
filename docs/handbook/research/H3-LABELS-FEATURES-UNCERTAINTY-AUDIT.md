# H3-A: labels, features and uncertainty audit

**Status: DRAFT, H3-A testability mission, 2026-10-16.** Synthetic data only. No model is trained, no historical
return is read, nothing here is evidence about any market. Real scores stay `UNESTIMATED`. Every number below comes
from the synthetic end-to-end example (`python -m scripts.research_h3.e2e`, fixed `SEED` 20261015) and exercises code
only.

Code: `scoring/labels.py`, `scoring/uncertainty.py`, `scoring/catalogue_v2.py` (new); additive audit fields in
`integration/label_replay.py`. Tests: `tests/test_research_h3_labels.py`. The V1 engine, the V1 catalogue
`h3-scoring-features` 0.1.0 and the golden vectors are unchanged (they still verify).

## 1. Summary

| Question | Answer |
|---|---|
| Are the 149 capacity-free labels representative of the evaluated candidates? | **No.** All 124 unlabelled candidates are refused at the entry collar, and that refusal depends on the price path after the decision. Removing the portfolio removed the capacity selection, not the outcome selection. |
| Target population of the scoring | `TECHNICALLY_ELIGIBLE`: every candidate that passes the checks decidable at T, in a fresh 500-USDT portfolio. Integration: `Population` is the V2 contracts' enum, and `ExperimentManifestV2` refuses `EXECUTABLE` as target; the census is available as `e2e.label_check(result, census=True)`. |
| Proposed label | `h3.reference_static_exit_label/1`: a gross static-exit outcome at the reference price, independent of executability. In the example it labels 261 of the 261 eligible candidates. |
| `xs_rel_ret_lb` | Not banned automatically, but not freezable. It is the cross-sectional momentum formation signal at a 24 h lookback; used with a negative weight, it is a residual reversal (H2). The decision stays `PENDING` until a source is bound. The default is to exclude it unless it is justified for that source and passes label-free independence checks. |
| `uncertainty` (V1) | A heuristic, `1/(1+|t|)`. It is not calibrated and it is most confident on the most extreme predictions. It is kept as is in V1, and an explicit multi-component report is added next to it. |

## 2. C1: label populations and exclusions

### 2.1 Why 124 candidates have no replay label

Timeline at bar resolution, used by the portfolio runs and the replay alike:

- Bar `k` closes at `b`. The reference price is that close.
- The decision is `T = b + 2 s`, when the bar is received.
- The bar opening at `b` has already started at `T`, so the entry is sent at the next open, `b + 1 h`.
- The entry is an IOC limit at `reference × (1 ± 0.001)`: the 10 bp DRAFT collar of `ExecutionAssumptions.entry_collar_rate`.
- A long is not filled when that open is above `reference × 1.001`. A short is not filled when it is below `reference × 0.999`.

In the example, every one of the 124 refusals has the single reason `fill_beyond_collar`. The pre-registered audit
A2 (private `PREREG-C-SYNTHETIC-AUDIT.md`, sha256 `eaad91cc…`, written before the run) confirms the mechanism:

| Set | n | Side-signed move, reference → fill-bar open | Reference gross R (mean) |
|---|---|---|---|
| E: filled by the replay (the 149) | 149 | mean −0.59 %, max +0.099 % | −0.22 (n = 147) |
| X: refused at the collar (the 124) | 124 | mean +0.66 %, min +0.134 % | +0.47 (n = 120) |

The difference X − E is +0.69 R, one-sided permutation p = 0.0005 (2,000 permutations, seed 1). Verdict:
`OUTCOME_DEPENDENT_SELECTION_PRESENT`.

- The refused candidates are exactly those whose first hour moved in their favour by more than the collar.
- That first hour is part of their outcome path.
- A scorer trained or checked on the 149 learns on candidates with an adverse first hour, and is then applied to all of them.

This holds by construction for any price process, not only for this synthetic one.

The 52 B0 labels add capacity selection on top of this, which is what R1-F3 had fixed.

### 2.2 Populations

| Population | Definition | Example |
|---|---|---|
| (before generation) | snapshots refused for data (`snapshot_data_partial` 8, `snapshot_data_stale` 1) | 9 |
| `ALL_CANDIDATES` | every candidate the common generator emitted | 273 |
| `TECHNICALLY_ELIGIBLE` (**target**) | checks decidable at T only: a usable bar at T; a protective stop on the tick grid; the portfolio's settlement currency; a non-zero size above the minimum size and notional under the H3 limits at 500 USDT (the gate's `admit_entry` on an empty portfolio); and, retrospectively, a horizon inside the data window | 261 |
| `LABELABLE` | eligible, with a reference label (contiguous bars up to the exit) | 261 |
| `EXECUTABLE` | eligible, and filled by the capacity-free replay with an untruncated exit | 142 |

The horizon rule is a calendar rule: `reference + max_holding + one bar ≤ end of the window`, decided whatever the path.
It is outcome-independent only because it is calendar-based. Censoring at the label step would keep the late
candidates whose stop or target fired early and drop the others. In the example, 6 of the 12 late candidates would
have been kept, all of them with a stop or target exit (test `test_c1_horizon_censoring_must_be_calendar_not_path`).

How the 149 replay labels and the 124 refusals split:

- Of the 149 replay labels, 7 belong to the 12 calendar-ineligible candidates. 2 of those 7 are truncated by the
  end of the data (`end_of_data` exits, now flagged `horizon_covered = False`).
- Of the 124 refusals, 119 are eligible and 5 are calendar-ineligible.

### 2.3 Exclusion reasons (the owner's table)

Each reason the code really emits is mapped in `labels.REASON_CATEGORY`. A code that is not listed counts as
outcome-dependent.

| Category | Reason codes (code) | When it is known | Can depend on the outcome? | Example count |
|---|---|---|---|---|
| Missing data | `snapshot_data_*`, `feature_*`, `no_market_data_at_decision` | at T | no | 9 (before generation) |
| Invalid stop | `stop_missing`, `stop_not_protective`, `stop_not_on_tick`, `target_not_on_tick` | at T | no | 0 |
| Absent instrument | `no_market_data`, `settlement_currency_mismatch` | at T | no | 0 |
| Minimum size | `quantity_below_min_size`, `notional_below_min_notional`, `post_quantization_risk_breach` | at T (sized at the collar price) | no | 0 |
| Market constraints | `fill_beyond_collar`, `ioc_not_filled_beyond_collar`, `fill_beyond_stop`, `fill_*` re-check at the fill price | after T | **yes** | 124 (replay) |
| Holding not covered | `horizon_not_covered` (calendar), `end_of_data_exit`, `after_end_of_data`, `end_of_data_pending_entry` | calendar | no, if calendar-based | 12 eligibility; 2 truncated replay labels |
| Price unavailable | `no_market_data_after_decision`, `price_path_gap` | after T (feed) | only if outages correlate with prices; check on real data | 0 |
| Portfolio state | risk budget, leverage, margin, `instrument_already_open`, kill switch, `cap_*` | portfolio path | **yes** (capacity) | 0 alone; it is the B0 selection |
| Protocol | `candidate_from_future`, `candidate_stale`, `score_*`, `candidate_id_conflict` | at T | no | 0 |
| Operational failures | `entry_order_rejected`, `entry_network_error`, `reconciled_not_filled` | injected | no (injected) | 0 |

### 2.4 Proposed label: `h3.reference_static_exit_label/1`

- **Entry.** A virtual entry at `entry_reference_price` at the reference instant t0, which is the close of the last
  bar usable at T.
- **Exits** use the candidate's initial stop, target and `max_holding_ms`, with the simulator's static rules in its
  order:
  1. at each open: an open through the stop exits at the open (a gap, so R < −1); then an open through the target
     exits at the target; then the time exit at the first open at or after `t0 + max_holding_ms`;
  2. intrabar: the stop is assumed before the target when both are touched (flagged `ambiguous_bar`), and the target
     fills on a trade-through.
- **Unit.** The label is gross, in R = |reference − stop|. That is the unit of the scorer's `gross_r` head.
  - Costs and funding are the engine's deterministic `cost_r` and `funding_cost_r`, subtracted once.
  - `labels.training_target` refuses a net unit for the gross head, so costs are never deducted twice.
  - `net_reference_r` gives the label in the unit of `expected_net_r`, for calibration checks only.
- **Exclusions.** Missing bars inside the path (`price_path_gap`) and a path that ends before the exit leave the
  candidate unlabelled, never truncated.
- **Leakage audit.**
  - The label reads only bars opening at or after t0.
  - No snapshot feature can read a bar that closes after T (`Bar.usable_at`). The test changes the label-path bars
    and checks that the snapshot is identical; it changes earlier bars and checks that the label is identical.
  - The label reads no collar, fill, portfolio, other candidate or score.
  - The sub-bar interval between t0 and T (the latency) cannot be separated at bar resolution. The label is
    therefore the idealised outcome of a fill at the reference, not a claim that such a fill is achievable.
- **Executability** is the separate `EXECUTABLE` flag, a fill probability under a timing rule. It is reported next to
  the label and is never a training filter. If the owner's timing rule (R1-F6: sub-bar fills, or re-anchoring the
  reference at the fill) changes the achievable entry, the label definition must move with it, as a new version.

## 3. C2: audit of `integration.label_replay`

| Check | Replay (V1, unchanged rule) | Reference label |
|---|---|---|
| Price convention | Entry at the fill: the next open, one bar after the reference. The R denominator is taken at the reference. **Mixed.** | Entry and R both at the reference |
| Cost model | `net_r`: realised simulator costs (taker entry and exit, maker target, flat half-spread, slippage, realised funding). `gross_r`: no costs, but it includes the move from the reference to the fill | Gross. Costs come from the engine's ex-ante formula, subtracted once |
| R reference | `net_r` ÷ the cost-inclusive risk at the collar (C's R). `gross_r` ÷ the gross distance at the reference (A's R). **Unit mismatch:** `expected_net_r` is in A's unit. The mean of `net_r` / `net_gross_r` in the example is 0.81. **Added:** `net_gross_r`, accepted by `label_mapping(unit='net_gross_r')`, for calibration checks | A's R |
| Stop | The candidate's initial stop. The fill-to-stop distance differs from the reference-to-stop distance (`fill_risk`) | The candidate's initial stop |
| Horizon | `max_holding` is counted from the fill. A replay window shorter than the horizon gives an `end_of_data` exit, a truncated label (2 in the example). **Added:** `horizon_covered`, and `label_mapping(full_horizon_only=True)` | Counted from t0. A short path is excluded |
| Chronology | Decision, then the entry at the next open, then the exits. The same as the portfolio runs | t0, then the path |
| Leakage through exit data | None found: post-T bars are used only as the outcome | Tested |
| Selection on the outcome | **Yes: the collar** (section 2.1) | None. The remaining exclusions are calendar-based or ex ante |
| Gaps (missing bars) | Silently treated as a gap: the next open acts through the stop. **Added:** `price_path_gaps` | Excluded (`price_path_gap`) |
| Ambiguous bars | Stop first, with the trade flag `stop_and_target_same_bar_stop_assumed` | Stop first, `ambiguous_bar` |
| Funding | Realised funding inside `net_r` | Excluded. The engine's provision is subtracted |

These are additive fields with defaults. The `traded` rule is unchanged, so the e2e numbers (149, 124, the report
fingerprint) are unchanged. A retrospective label may serve for training. No feature at T may depend on it, and the
leakage test checks exactly that.

## 4. C3: the 13 features of `h3-scoring-features` 0.1.0

| Feature | Role | Concern |
|---|---|---|
| `mkt_ret_lb` | predictive | 24 h market return: a regime variable. It touches the closed market-momentum regime filter (regime-r2 D2). Decision `PENDING` |
| `xs_rel_ret_lb` | predictive | **See below.** Decision `PENDING` |
| `xs_dispersion_lb` | predictive | Not directional. No closed family identified. Rises with market volatility |
| `rvol_lb` | predictive | Not directional. It is a conditioning variable for the stop distance in noise units |
| `efficiency_lb` | predictive | Partly a function of the absolute return and of `rvol_lb`. The label-free pruning rule applies |
| `liq_turnover_pct` | predictive | A rank, so survivorship matters. Correlated with the spread (a cost) |
| `stop_vol_units` | predictive (candidate) | It depends on the generator's stop rule, so it is source-specific by nature |
| `spread_bp`, `funding_rate_hourly`, `cost_r`, `funding_cost_r` | cost | Deterministic, never learned. A funding-carry source (CS-FUND) must revisit the credit rule |
| `data_age_ms` | quality | Abstention only |
| `portfolio_corr_lb` | risk gate | Portfolio-dependent, so never a score input (correct) |

All 13 respect the availability rule (`Bar.usable_at`, `available_ms ≤ decision`). The engine refuses any feature
observed after the decision.

### 4.1 `xs_rel_ret_lb`

- **Does it reintroduce the closed mechanism?** Partly, by construction. Its formula, the own return minus the
  cross-sectional median, is the CS-XMOM formation signal. The lookback is 24 h instead of 14 days.
  - The feature is sign-oriented (`directional`). A positive gross-head weight therefore tilts the accepted set
    towards "long relative winners, short relative losers": cross-sectional momentum.
  - A negative weight tilts it towards residual reversal, the H2 mechanism, closed on 2026-10-15.
  - Both signs touch closed families.
- **Does a new generator justify it?** No source is qualified today, so no source can justify it. For an event source
  (unlocks, funding extremes), the relative return before the event can be a legitimate control for crowding or
  pre-event drift. That argument is source-specific and must be written when the catalogue is bound.
- **Is removing it required for conceptual independence?** Not automatically. But H3-A claims that scoring adds value
  beyond the generator and beyond trading less. A gain that comes from this feature would be a closed family
  re-entering through the scorer.
- **Can it create a disguised XMOM strategy?** Yes. B1 − B0 could be produced by a relative-return tilt alone. This
  can be detected without labels: measure the share of B1-accepted candidates in the top or bottom tercile of
  `xs_rel_ret_lb`, by side.
- **Proposal.** In V2 the feature carries closed families (CS-XMOM, H2) and three checks:
  1. a label-free rank correlation with the closed signals;
  2. a label-free accepted-set tilt;
  3. a pre-registered nested ablation, run once.

  Its decision is `PENDING`, and the catalogue cannot be frozen while it is pending. When the catalogue is bound to a
  source, the feature is either `EXCLUDED` (the recommended default) or `JUSTIFIED` with the label-free checks passed.
  The removal must be recorded as a versioned change. The V1 catalogue is not modified.

### 4.2 Catalogue V2 structure (`scoring/catalogue_v2.py`)

Schema `h3.feature_catalogue/2`. It is a structure, not a frozen list.

Each feature carries the following fields:

- roles, including a new `source_trigger` role, which is never a score input;
- formula, inputs, availability (`uses_post_decision_data` must be false), provenance (point-in-time, licence status);
- `source_binding`, `portfolio_dependent`;
- an M4 justification: the mechanism, the closed families, a decision (`NO_OVERLAP`, `JUSTIFIED`, `EXCLUDED` or
  `PENDING`), and independence checks.

The catalogue carries its parent (spec id, version and fingerprint of V1) and a change log.

`validate` refuses the following:

- a predictive feature that is portfolio-dependent or derived from outcomes;
- a feature that uses post-decision data;
- a missing M4 justification, or `NO_OVERLAP` with a listed closed family;
- an `EXCLUDED` feature still in the predictive role;
- a source-binding mismatch;
- any removal, addition or modification relative to the parent without a change entry, and an unbumped version.

`freeze_reasons` also requires the following:

- a bound source;
- no `PENDING` decision;
- passed label-free checks;
- an established licence.

`draft_from_v1` maps the 13 V1 features unchanged, unbound and `DRAFT`. It validates as a draft and cannot be frozen.

## 5. C4: uncertainty

### 5.1 What V1 computes

V1 computes `se² = sd²(1+q)/n_train + Σ_imputed w²` and `uncertainty = se/(se+|expected_net_r|) = 1/(1+|t|)`. This
matches its documentation and is not a code defect. It is, however, a heuristic, for these reasons:

- **Linear model.** It assumes homoscedastic, independent residuals. R is bounded below near −1 (except on gaps) and
  is skewed by targets. A constant `residual_sd_r` cannot describe that.
- **Ridge.** The OLS formula ignores the shrinkage bias, and the bias grows with |z|.
- **Dependence.** `n_train` is declared, and nothing checks that it is an effective count. Overlapping holdings and
  co-moving coins inflate it, which deflates the SE.
- **Multicollinearity.** Without `unc.cinv`, `q = Σ z²`, so correlated features are counted as independent.
- **Extrapolation.** Only a marginal `|z| ≤ z_support_max` is checked. There is no joint distance and no check on the
  predicted label range. The heuristic falls towards 0 as |expected_net_r| grows, so the most extreme predictions get
  the most confidence. Test: a gross intercept of 50 gives `uncertainty < 0.01` and `TRADE_CANDIDATE`.
- **Heads.** `p_stop` and `tail_loss_r` carry no uncertainty. Gap and squeeze losses are not modelled.
- **Costs.** Future costs are taken at base. The pessimistic multiplier exists only in the evaluation.
- **Effective size.** It is not computed anywhere.

### 5.2 Explicit representation (`scoring/uncertainty.py`, schema `h3.uncertainty_report/1`)

`decompose(score, trace, model, dependence=, domain=, cost_multiplier=, regime_flags=)` returns one component per
question. Each component has a status: `ESTIMATED`, `FLAGGED`, `SCENARIO`, `NOT_MODELLED`, `NOT_ASSESSED` or
`UNESTIMATED`.

1. **The conditional mean.**
   - It reports the SE with the declared n.
   - When an `EffectiveSample` is given, it also reports the SE inflated by `sqrt(n/n_eff)`. The SE is never deflated.
   - The `EffectiveSample` comes from `effective_n_bartlett` (Newey–West lag rule, factor floored at 1) or from
     `effective_n_clusters` (the Kish/Moulton design effect). `conservative` keeps the smaller of the two.
   - Without an effective-n estimate, the component is `FLAGGED effective_n_not_verified`.
2. **The variability of a single trade.** `sqrt(sd² + se²)`, which is far larger than the SE of the mean. It is
   flagged: constant residual, bounded and skewed outcome, no interval derived.
3. **Regime change.** A non-numeric status: `NOT_ASSESSED`, or `FLAGGED` with reason codes.
4. **A misspecified model.** Always `FLAGGED`, with these reasons: linear heads not validated, homoscedasticity,
   ridge approximation, identity correlation, imputation.

Next to these four sit three more components:

- **Domain:** `IN_DOMAIN` only when the marginal, joint (Mahalanobis, with `cinv` and a declared bound) and
  label-range checks all ran and passed. Otherwise the domain is `OUT_OF_DOMAIN` or `UNKNOWN`. A check that did not run
  is not a pass.
- **Stop and tail:** `NOT_MODELLED`, with point values only.
- **Costs:** a deterministic `SCENARIO` (base, and pessimistic × multiplier), not a distribution.

The report always carries `is_calibrated_probability: false` and `overall: DESCRIPTIVE_NOT_CALIBRATED` (or
`UNESTIMATED`). It does not change the V1 score. `abstention_reasons` lists conservative reasons for a future engine
version: out of domain, unknown domain, unverified effective n.

**No V1 engine change.** No real defect was found in the V1 arithmetic. The weaknesses above are about
interpretation and missing components. Changing V1 would invalidate the golden vectors and gain nothing, since no
model exists yet.

## 6. C5: synthetic tests (`tests/test_research_h3_labels.py`)

| Owner item | Tests |
|---|---|
| No labels → UNESTIMATED | `test_c5_absent_labels_leave_the_effective_n_unestimated`, `test_c5_no_model_no_calibration_or_invalid_model_gives_no_number` |
| Missing calibration parameters → UNESTIMATED | the same test. An UNCALIBRATED manifest gives UNESTIMATED. A manifest without its calibration table is MODEL_INVALID. Neither produces a number, and the report is UNESTIMATED |
| Invalid model → rejection | the same test (`unc.residual_sd_r = 0` gives MODEL_INVALID) |
| Out of domain → explicit status | `test_c5_out_of_domain_is_an_explicit_status` |
| Strong temporal dependence → no artificial reduction | `test_c5_strong_temporal_dependence_is_not_hidden_prereg_a3` (pre-registered A3, seeds 1–20), `test_c5_clustered_outcomes_reduce_the_effective_n` |
| Missing features → documented behaviour | `test_c5_missing_features_documented_behaviour`. A missing cost input gives INVALID_INPUT. A missing predictive input gives INVALID_INPUT, unless the manifest allows imputation; then the result is ESTIMATED, with imputation variance and a flag |
| Extreme results → no excess confidence | `test_c5_extreme_predictions_get_no_excess_confidence` |
| No double cost deduction | `test_c2_no_double_cost_deduction` |
| Parity vectors preserved | `test_c5_parity_vectors_and_v1_catalogue_are_preserved` |
| Labels and leakage | `test_c1_*`, `test_c2_*` (census 273/149/124/52, collar selection audit, calendar horizon, the reference-label rules, independence from executability, the leakage test, the replay audit fields) |
| Catalogue V2 | `test_c3_*` |

## 7. Limits

- These are synthetic numbers. In particular, the size of the collar selection depends on the synthetic volatility
  relative to the 10 bp collar. Only the mechanism is general.
- The Bartlett effective n under-corrects strong persistence with the short lag rule: for AR(1) with φ = 0.8, the
  factor is about 4 where the true factor is 9. Use the cluster estimate and `conservative` alongside it.
- The reference label needs the owner's execution-timing rule (R1-F6) before it can be frozen. The protocol must also
  state that it is the training and evaluation label of the scorer, while executability is reported separately.
- Whether feed outages (`price_path_gap`) are independent of prices must be checked on real data.
