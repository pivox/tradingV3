# H3-A — Opportunity scoring engine (DRAFT specification)

Status: DRAFT, 2026-10-15. Research prototype. It has been run on synthetic data only. **No statistical edge is
demonstrated.** No model has been calibrated, so every real score is `UNESTIMATED` today. Every numeric threshold below
is marked DRAFT with the a-priori reason for it. The owner freezes these values in an authorised protocol. None was
chosen from known performance.

Code is in `python-orchestrator/scripts/research_h3/scoring/` and tests are in
`python-orchestrator/tests/test_research_h3_scoring.py`. The scorer uses the shared contracts v1 in
`scripts/research_h3/contracts.py`.

## 1. Role in H3

H3-A asks one question: do features observable at decision time T separate candidates with a favourable net expectancy
from the rest? The scorer receives a candidate (`OpportunityCandidate`) from the **common candidate generator** and the
candidate's `FeatureSnapshot`. It returns an `OpportunityScore`.

The scorer does not:

- generate or filter candidates (that is the generator's job, and the generator is independent of any score);
- size a position;
- apply risk limits (that is the risk gate's job);
- turn a score into an order.

`TRADE_CANDIDATE` means "eligible for the risk gate", not "buy". A high score is not a trading signal.

Scores never depend on a variant's portfolio. The same candidate therefore gets the same score in B1 and in B3, which
keeps the B0–B3 factorial design clean. For the same reason, portfolio correlation is a risk-gate feature (§2). It is
never a score input.

## 2. Feature catalogue `h3-scoring-features` 0.1.0

Catalogue fingerprint: `sha256:806891dc2ac8cafed3ce6670a92e8f563569bf99e8a425f54a5d948d124d44b1`. A test pins this
value. Any change to the catalogue needs a new version.

The catalogue has four DRAFT parameters, each chosen a priori:

- bar interval: 1 h. The candidate sources under study (the CS-* dossiers) act over hours to days, and 1 h bars avoid
  microstructure noise.
- lookback: 24 bars. One day, one funding and attention cycle. Not tuned.
- minimum cross-section: 5 instruments. Below that, a median is not meaningful.
- maximum ages: listed per feature in the table.

A feature has one of four roles:

- **predictive** features may be model inputs;
- **cost** features are deterministic accounting, subtracted by the engine and never learned;
- **quality** features drive abstention only;
- **risk_gate** features are for the risk gate only.

A **directional** feature is multiplied by +1 for a long and −1 for a short before use. After that, a positive value
favours the candidate's side.

| Feature | Role | Source | Dir. | Formula (arithmetic §7) | Max age (DRAFT) | Economic reason | Main leakage risk |
|---|---|---|---|---|---|---|---|
| `mkt_ret_lb` | predictive | universe bars | yes | median over the aligned universe of `close[t]/close[t-24] - 1` | 2 h | market component of the recent return: the regime a directional trade is exposed to | survivorship (today's listing instead of the point-in-time universe) |
| `xs_rel_ret_lb` | predictive | universe bars | yes | own 24-bar return minus `mkt_ret_lb` | 2 h | relative (cross-sectional) momentum: the coin-specific part of the move | survivorship; delisted coins must stay until delisting |
| `xs_dispersion_lb` | predictive | universe bars | no | median of `abs(ret_j - mkt_ret_lb)` (cross-sectional MAD) | 2 h | dispersion between instruments: room for idiosyncratic opportunities | dropping dead coins lowers the dispersion |
| `rvol_lb` | predictive | instrument bars | no | `sqrt(mean(r_i^2))` over 24 one-bar simple returns | 2 h | recent volatility: stop distance in noise terms; volatility clusters | forming bar (excluded by `Bar.usable_at`) |
| `efficiency_lb` | predictive | instrument bars | no | `abs(close[t]-close[t-24]) / sum(abs(close[i]-close[i-1]))`, in [0, 1] | 2 h | market structure: trending versus choppy path | none beyond the bar rule |
| `liq_turnover_pct` | predictive | universe bars | no | percentile rank of the median per-bar quote turnover among the other instruments | 2 h | liquidity level, scale-free (bcmath has no log) | survivorship (illiquid coins disappear) |
| `stop_vol_units` | predictive | candidate | no | `(stop distance / entry) / rvol_lb` | inherits | distance to the stop in volatility units: the a-priori driver of stop-out risk | a stop placed using prices after T |
| `spread_bp` | cost | quotes | no | `(ask-bid)/((ask+bid)/2) x 10000` on the latest quote received at or before T | 60 s | crossing the spread on entry and exit | quote observed after T; period-average spreads |
| `funding_rate_hourly` | cost | funding | yes | last settled rate x 3600000 / (last − previous funding_ms); sign: paid by longs | 24 h | carrying cost of a perpetual | the current-period rate is only known at settlement; predicted rates are revised |
| `cost_r` | cost | candidate | no | `(2 x taker_fee + spread_bp/10000 + 2 x slippage) / stop_fraction` | inherits | round-trip predictable cost in R (large with tight stops) | costs read from the realised fills being evaluated |
| `funding_cost_r` | cost | candidate | no | `max(0, side_sign x funding_rate_hourly) x max_holding_h / stop_fraction`; 0 for spot | inherits | funding paid over the maximum holding period, in R | realised holding time used instead of the ex-ante maximum |
| `data_age_ms` | quality | instrument bars | no | `T − close_ms` of the latest usable bar | — | pipeline freshness; never predictive (it measures our pipeline, not the market) | none |
| `portfolio_corr_lb` | risk_gate | portfolio bars | no | max over held j of `side_c x side_j x pearson(r_c, r_j)`; 0 for an empty portfolio | 2 h | concentration | as a score input it would differ between B1 and B3 |

The maximum ages are DRAFT, set a priori:

- 2 h is two intervals: one late bar is tolerated, two mean the feed stalled.
- 60 s: a top of book older than a minute is not the current book.
- 24 h is three 8-hourly settlement periods.

**Funding credits are ignored.** This DRAFT rule is conservative a priori, because holding time and future rates are
uncertain. A CS-FUND candidate source must revisit it in its protocol.

The costs come from the experiment's `CostModel`, which is shared by B0–B3. Taker fees are assumed on both legs (a
priori: stop exits are taker). The scorer uses the base costs. The CostModel's `pessimistic_multiplier` belongs to the
robustness evaluation.

Each feature's spec in `features.py` records the full availability rule, provenance, rationale, leakage risks and
correlation notes.

### Redundancy and correlation

- **Return decomposition.** The return is split into `mkt_ret_lb` (market) and `xs_rel_ret_lb` (relative). An
  own-return feature next to both would be exactly collinear up to the median, so the catalogue has none.
- **Expected correlations.** These pairs are expected to be partly redundant:
  - `efficiency_lb` with `abs(ret)` and `rvol_lb`;
  - `stop_vol_units` with `rvol_lb` (it has `rvol_lb` in its denominator);
  - `xs_dispersion_lb` with market volatility;
  - `liq_turnover_pct` with `spread_bp`.
- **Excluded duplicate.** A range-based (Parkinson) volatility would duplicate `rvol_lb`, so it is excluded.
- **Pruning rule.** `features.prune_correlated(columns, bound)` is label-free. It visits features in catalogue order and
  drops a feature whose |Spearman ρ| with an already kept feature exceeds `bound`. It takes no outcome column, so it
  cannot select on performance. `bound` has no default. The DRAFT value is 0.8, an a-priori rule of thumb for
  collinearity. Use it only on development data, inside an authorised protocol.

### Time rules (anti-lookahead)

- A bar is used only if `Bar.usable_at(T)` holds: it is closed and `available_ms <= T`. The forming bar and any bar
  received after T are ignored. Tests check that adding or changing them never changes a snapshot. A bar received
  exactly at T is usable.
- Quotes and funding are used only when `available_ms <= T`. Funding uses settled rates only.
- Every `FeatureValue` carries:
  - `observed_ms`: the end of the last observation used;
  - `available_ms`: the latest receipt time of the data used.
- The snapshot clock is set as follows:
  - observed = the latest feature `observed_ms`;
  - available = the latest feature `available_ms`;
  - decision = T.
  - The contract enforces `observed <= available <= decision`.
- A feature can fail in three ways:
  - **MISSING**: the window is incomplete, has a gap, or has conflicting duplicates (identical duplicates are merged);
  - **STALE**: the window is older than the feature's maximum age;
  - **NON_FINITE**: a denominator is zero.
- Input order never matters, for bars, quotes, funding or the universe mapping.
- The engine re-applies the maximum-age rule at the candidate's decision time. Data carried forward goes STALE.
- Cross-sectional features use only instruments whose window ends on the same bar as the candidate's. The **caller must
  pass the point-in-time universe**: the instruments listed at T, including those delisted later. Nothing in the code
  can verify this. It is a protocol obligation (§9).

## 3. Score interface

`ScoringEngine(cost_model, spec=CATALOGUE)` implements `contracts.OpportunityScorer`:

- `score(candidate, snapshot, model) -> OpportunityScore`;
- `rank(scores) -> tuple[OpportunityScore, ...]`;
- `evaluate(...) -> ScoreTrace`, which returns the score plus every intermediate number, for audit and parity.

| Output | Meaning | Present when |
|---|---|---|
| `expected_net_r` | expected net R: `gross_r − cost_r − funding_cost_r` | ESTIMATED |
| `p_stop` | probability of a stop-out, from the calibrated stop head | ESTIMATED |
| `tail_loss_r` | expected tail loss in R (positive): `tail_gross + cost_r + funding_cost_r`; the protocol defines the tail (e.g. CVaR level) | ESTIMATED |
| `uncertainty` | `se / (se + abs(expected_net_r)) = 1/(1+abs(t))`, in (0, 1]; 1 when not estimated | always |
| `data_quality` | share of the required snapshot inputs that are OK and fresh; 0 on a structural input error | always |
| `utility` | `expected_net_r − λ·tail_loss_r − γ·uncertainty` | ESTIMATED |
| `rank` | 1 = best, set by `rank()` only | ranked TRADE_CANDIDATE |
| `verdict` | `TRADE_CANDIDATE` or `NO_TRADE` | always |
| `reasons` | stable, sorted reason codes | always (non-empty on NO_TRADE) |

The model predicts **gross** R under the protocol's static exit. Predictable costs are subtracted deterministically and
are never learned. Training labels must therefore be gross R, built with the same cost conventions. Otherwise costs are
counted twice.

### Uncertainty

- `q = z' A z`, where A is the inverse correlation matrix of the standardised training features (`unc.cinv.*`). It is
  the identity when that matrix is absent.
- `se² = sd² (1 + q) / n_train + Σ_imputed w_i²`. This is the standard error of the conditional mean, by the OLS
  formula (an approximation for ridge). The second term counts each imputed feature as an unknown unit-variance z.
- `uncertainty = se / (se + abs(expected_net_r))`.
- Uncertainty therefore grows with extrapolation (q), with outcome noise (`sd`), with imputation, and with fewer
  training outcomes. Tests check each of these.
- `n_train` must be the **effective** number of independent outcomes. Overlapping trades and correlated coins make the
  nominal count overstate it, which understates uncertainty.
- The uncertainty of `p_stop` and `tail_loss_r` is not modelled in v1.

## 4. Abstention and NO_TRADE rules

Every non-ESTIMATED score has the verdict `NO_TRADE` and carries no number. The first matching row wins.

| # | Condition | Status | Reason codes |
|---|---|---|---|
| 1 | snapshot ≠ candidate's snapshot ref, instrument, side, decision time, provenance or protocol; other catalogue; unknown feature; wrong unit; feature after T | INVALID_INPUT (data_quality 0) | `snapshot_*_mismatch`, `feature_spec_mismatch`, `feature_unknown:<f>`, `feature_unit_mismatch:<f>`, `feature_after_decision:<f>` |
| 2 | no model | UNESTIMATED | `model_absent` |
| 3 | invalid manifest: catalogue mismatch, feature unknown, not predictive or out of order, parameter missing, unexpected or invalid, bad calibration table, unsupported kind | MODEL_INVALID | `model_*` |
| 4 | reference-null or UNCALIBRATED model | UNESTIMATED | `model_reference_null`, `model_uncalibrated` |
| 5 | required data MISSING, STALE or NON_FINITE (a predictive feature may be imputed only if the manifest allows it, up to `max_imputed`; cost inputs never are); arithmetic out of domain | INVALID_INPUT | `feature_missing:<f>`, `feature_stale:<f>`, `feature_non_finite:<f>`, `too_many_imputed`, `arithmetic_out_of_domain:*` |
| 6 | estimation gives a negative tail or a non-positive quadratic form | MODEL_INVALID | `model_tail_prediction_negative`, `model_uncertainty_matrix_not_positive` |
| 7 | ESTIMATED, but abs(z) > `z_support_max`, or utility ≤ `utility_min`, or uncertainty > `uncertainty_max` | ESTIMATED, NO_TRADE | `feature_outside_training_support:<f>`, `utility_not_above_min`, `uncertainty_above_max` |

UNESTIMATED and MODEL_INVALID scores also list the data reasons, for audit. Some reasons are informational and do not
force abstention: `feature_imputed:<f>` and `p_stop_index_clamped`.

The numbers of an ESTIMATED NO_TRADE score are kept for audit. They must never be used for selection, and `rank()`
ignores them.

## 5. Before any calibration: UNESTIMATED, and B1/B3 are not runnable

- No model has been trained, and none may be trained in this mission. On real data, every score is therefore
  `UNESTIMATED` with the verdict `NO_TRADE` and no number. The scorer never invents a probability or an expectancy.
- A B1 that ran on such scores would degenerate silently into one of two things: "no trade" (abstain everywhere) or
  B0 (if NO_TRADE were ignored). Neither is acceptable. The rule is:
  - `model.scored_variant_readiness(model, experiment_datasets, experiment_protocol)` must return `()` before B1 or B3
    may run.
  - It requires a decodable LINEAR or RIDGE manifest that is CALIBRATED.
  - On any non-synthetic dataset, it also requires:
    - real training data (a synthetic test fixture is refused: `model_trained_on_synthetic_only`);
    - a FROZEN training protocol;
    - training datasets disjoint from the evaluated ones, by dataset id and sha256;
    - a FROZEN experiment protocol.
- Otherwise the experiment runs B0 and B2 only and reports B1 and B3 as **NOT_RUNNABLE**. It never substitutes
  reference entries for scored entries.
- Synthetic CALIBRATED manifests exist only as labelled test fixtures. They are `h3-score-synthetic-fixture`: training
  provenance `synthetic`, hand-written coefficients, DRAFT protocol. They exercise code paths and are never evidence.
- The coefficients λ (`policy.lambda_tail`) and γ (`policy.gamma_uncertainty`) and every threshold (`utility_min`,
  `uncertainty_max`, `z_support_max`, `max_imputed`) have **no default in code**. They are set only by an authorised
  development protocol, then frozen inside the manifest, whose fingerprint pins them.
- Recommendation for the validation design: score **every** candidate of the common generator and log it, in B0 as
  well. The score does not depend on the portfolio, so the association between score and outcome can be measured on
  the common set, independently of selection.

## 6. Models allowed

| Kind | v1 status | Notes |
|---|---|---|
| `reference_null` | implemented | no parameters; always UNESTIMATED |
| `linear` | implemented | transparent linear score: three heads on standardised, side-oriented features |
| `ridge` | implemented | same inference as linear; records `train.ridge_alpha > 0` |
| `simple_nonlinear` | refused (`model_kind_not_supported`) | only if its extra complexity is justified first |

No neural network. No reinforcement learning. No training or optimisation in this mission.

The three heads are:

- `gross_r`: a linear head;
- `stop`: a linear index, mapped to `p_stop` by a monotone piecewise-linear calibration table of 2–32 points, clamped
  at the end points;
- `tail_r`: a linear head.

A model built for one side only is possible: the caller passes that side's manifest. Making it first-class needs a
contract field (§10).

Parameter layout (`ModelManifest.parameters`, sorted by name; `<f>` stands for each model feature in catalogue order):

- `std.<f>.mean`, `std.<f>.scale` (> 0);
- `head.{gross_r,stop,tail_r}.intercept`, `head.{gross_r,stop,tail_r}.w.<f>`;
- `head.stop.calib.<kk>.{x,p}`;
- `unc.residual_sd_r` (> 0), `unc.n_train` (integer ≥ 2), optional `unc.cinv.<fi>.<fj>` (i ≤ j, all or none);
- `policy.{lambda_tail, gamma_uncertainty, utility_min, uncertainty_max, z_support_max, impute_missing, max_imputed}`;
- `train.ridge_alpha` (ridge only).

Further rules:

- A parameter with more than 18 decimals is refused, because it is not exactly representable in the scoring arithmetic.
- The standardisation means and scales must be computed on the training data only, never on the full sample.

## 7. Serialization and Python/PHP parity

- **Manifest.** The model is a versioned `ModelManifest` in canonical JSON: Decimals as strings, sha256 fingerprint.
  Python and PHP compute the same score from the same manifest. The Symfony runtime would never call Python
  synchronously per decision. This mission does not integrate the scorer into the runtime.
- **Arithmetic** (`scoring/arith.py`, "h3-scoring-arithmetic/1"):
  - every input is truncated toward zero to 18 decimals, with |x| < 10^30;
  - `add`/`sub` are exact;
  - `mul`/`div` keep the exact result truncated toward zero to 18 decimals (PHP `bcmul`/`bcdiv`, scale 18);
  - `sqrt(x) = isqrt(x·10^36)/10^18` with an exact integer square root (PHP `gmp_sqrt`, not `bcsqrt`);
  - there is no `ln`/`exp` anywhere;
  - the operation order is exactly as written in `features.py` and `engine.py`;
  - the wire form is `contracts.wire_decimal`.
- **Golden vectors.** `scoring/parity/golden_v1.json` (≈170 kB, one case per line) holds:
  - one feature case: bars, plus a forming bar, a late bar, a late quote and a future settlement → the expected
    snapshot;
  - 15 score cases: manifest + snapshot + candidate → the expected score wire, its fingerprint and the full estimation
    trace;
  - one rank case, with an exact utility tie.
- **Conformance.** A port conforms when its canonical JSON equals the expected one byte for byte. The test recomputes
  every case from the stored inputs, and checks that the file equals a fresh build.
- **Regeneration.** Regenerate the vectors only together with a catalogue or engine version change:
  `python -m scripts.research_h3.scoring.parity --write`.

## 8. Ranking

- `rank()` accepts one decision batch only: the same decision time, the same model and distinct candidate ids.
  Otherwise it raises `ScoringError`: `scoring_rank_mixed_decision_times`, `scoring_rank_mixed_models` or
  `scoring_rank_duplicate_candidate`.
- Only ESTIMATED `TRADE_CANDIDATE` scores are ranked. The sort keys, in order:
  1. utility, descending;
  2. uncertainty, ascending;
  3. data_quality, descending;
  4. candidate_id, ascending: a deterministic last resort with no economic meaning.
- Unranked scores follow by candidate_id, with rank `None`.
- The result does not depend on the input order, and ranking twice changes nothing.
- How many ranked candidates are admitted is decided by the risk gate and the protocol, not by the scorer.

## 9. Bias risks

1. **Lookahead.** The scorer excludes forming bars, data received after T, and unsettled or predicted funding rates. It
   records observed and available times and re-checks freshness. Lookahead can still enter through:
   - a candidate stop or entry computed with later prices;
   - standardisation statistics computed on the full sample;
   - an archive whose `available_ms` is reconstructed as `close_ms` without the real publication latency;
   - a universe chosen with hindsight.
2. **Survivorship in cross-sectional features.** Four features rank or median across the universe. Dropping delisted
   or illiquid coins biases dispersion down and liquidity ranks up. The universe must be point-in-time, with delistings
   dated. This is a protocol obligation, because the code cannot check it.
3. **Label construction.** Labels come from future outcomes and must be defined by the protocol: gross R from the
   candidate's entry reference to the static exit (stop, target or `max_holding_ms`), with the same cost conventions.
   No best-exit, MFE or hindsight-optimal labels. Overlapping outcomes need purging and an embargo of at least
   `max_holding_ms` between training and evaluation windows. `n_train` must be the effective count.
4. **Selection by known performance.** Several things must never be chosen after seeing outcomes:
   - features, the catalogue version, the bar interval and lookback, λ, γ and the thresholds;
   - the correlation-pruning bound;
   - the decision to impute.

   Data already read (H1, H2 and the earlier r-series) may only ever be development data, never validation.
   Coefficients are fitted once, in a FROZEN development protocol, then frozen with the manifest's fingerprint.
5. **Correlated features.** Redundant inputs make linear weights unstable and uninterpretable. The catalogue avoids
   exact collinearity, documents the expected correlations, and provides the label-free pruning rule. Ridge is the
   allowed remedy for the rest.
6. **Multiple testing.** Every feature set, model kind and threshold tried in development counts as a trial. The
   protocol must count them (agent C's framework).
7. **Cost-model mismatch.** The scorer's cost formula (taker on both legs, observed spread, CostModel slippage, funding
   credits ignored) must equal the simulator's conventions. Otherwise `expected_net_r` is biased relative to the
   evaluated outcome.
8. **Single model for both sides.** Orienting the directional features assumes symmetric long and short effects.
   Crypto drift and funding asymmetry may break that, so side-specific manifests may be needed.

## 10. Tests

`tests/test_research_h3_scoring.py` has 71 tests (parametrised cases counted). Synthetic only, a few seconds. They cover:

- the arithmetic: truncation like bcmath, the exact floor square root, refusal of non-finite and out-of-range values;
- the catalogue: fingerprint pinned, every spec documented, the doc lists every feature;
- the features:
  - values computed by hand;
  - insensitivity to input order;
  - no lookahead: forming, late and future bars, late quotes, future funding, a bar received exactly at T;
  - gaps, conflicts, staleness, zero denominators;
  - the minimum cross-section and alignment;
  - side-adjusted portfolio correlation, with the risk_gate role enforced;
  - derived costs, the funding-credit rule and spot;
- label-free pruning;
- manifests: round trip, 15 named invalid-parameter cases, catalogue mismatch, unsupported kind, no default
  coefficients;
- the engine:
  - identical inputs give identical scores;
  - the ESTIMATED shape and formulas;
  - side orientation;
  - no number without a usable calibrated model;
  - missing, stale, partial and non-finite data;
  - arithmetic out of domain;
  - freshness re-checked at decision time;
  - every snapshot/candidate mismatch;
  - training-support abstention;
  - uncertainty monotonicity;
  - the inverse correlation matrix;
  - bounded imputation;
  - calibration clamping;
  - a negative tail;
  - the engine exposes only score, evaluate and rank;
- ranking: order independence, idempotence, coherence, ties, refusal of mixed batches;
- the B1/B3 readiness rule;
- golden vectors: recomputed from the stored inputs, byte-identical to a fresh build, divergence detected, no float or
  exponent;
- Python 3.11 syntax.

The test command (from `python-orchestrator/`):
`python -m pytest --noconftest -q tests/test_research_h3_scoring.py tests/test_research_h3_contracts.py`.

## 11. What remains DRAFT or open

- Every DRAFT number above: the interval, lookback, minimum universe, maximum ages, pruning bound, funding-credit rule
  and taker-on-both-legs. λ, γ and every policy threshold have no value at all yet.
- The label definition, the tail definition (CVaR level), purge and embargo, and the effective-n rule. These belong to
  the validation protocol (agent C).
- The economic source of candidates (CS-FUND, CS-UNLOCK or CS-XMOM). The scorer is agnostic to it, but a funding-carry
  source must revisit the funding-credit rule.
- The PHP port itself. Only the golden vectors exist.
- Contract change requests:
  - `ModelManifest`: add `feature_spec_fingerprint`, and an optional `side` for side-specific models.
  - `FeatureSnapshot`: add `feature_spec_fingerprint`.
  - `Quote`: document the unit of `bid_size`/`ask_size`. It is unused by v1, but needed for any depth feature.
