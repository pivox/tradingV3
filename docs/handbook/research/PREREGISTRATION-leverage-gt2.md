# Pre-registration leverage-gt2 — theoretical leverage above 2x in tick scalping, BTC excluded (P2-B4)

**Status: read from the FROZEN line below §13**, so the body does not change at the freeze. The owner set the four
decisions D1–D4 on 2026-10-15 (D1 = `distinct_pair_day`, D2 = `drop`, D3 = 1, D4 = `not_charged`; §12), in this text
and in `research_lev/protocol.py`, accepted the implementer choices C1–C9 as written (§12) and gave his questions A–D,
mapped to the frozen metrics in §11 as a reading guide only. Freezing authorises the E0a census only after the
owner's explicit go; E1 runs only if the E0a → E1 rule of §7 triggers it. Nothing in this document has been
computed: no census, no replay, no outcome column has been read for this study.

Inputs (private audit of 2026-10-15, read-only, `tradingV3-private/research/20261015T070000Z-scalp-leverage-audit/`):
`00-SYNTHESE.md` (with its addendum), `01-AUDIT-FAISABILITE.md`, `02-INVENTAIRE-DONNEES.md`, `03-DEFINITION-LEVIER.md`,
`04-PROTOCOLE-DRAFT_NOT_FROZEN.md`, `05-PLAN-IMPLEMENTATION.md`, `06-COUT-TECHNIQUE.md`. The owner accepted the
audit's recommended options on 2026-10-15 and gave a go up to E1; his decisions are integrated below. Code:
`python-orchestrator/scripts/research_lev/` (written and tested on synthetic data before this freeze; §12).

## 0. Nature and lineage

- **Exploratory, post hoc.** A sub-group analysis of configurations that already failed (r10: 0 of 96 at the
  training stage; r10x exploratory; r11 closed at training; r12 closed at validation). Every window used here is
  already consumed. This is never a validation, never a replication, and nothing is chosen from outcomes.
- **No criterion is relaxed after a read.** Corrections of code defects are disclosed and never used as
  optimisation. Verdicts are published even when negative.
- **Single use.** Each stage (E0a, E1, analysis) runs once, behind a claim written before the read (§12).
- **Standing rules.** BTC is excluded always. A trade whose theoretical leverage is ≤ 2x is never levered up: it
  is taken only at its own size, and only as a member of the reference group.

## 1. Question and estimand

**Owner's question.** Do the scalping opportunities whose fixed-risk sizing requires a theoretical leverage above
2x have a net expectancy different from those that require between 1x and 2x?

**Invariance (stated before any data).** With fixed-risk sizing N·(d + c) = r·E, if the stop is reached before
liquidation and no cap binds, the applied leverage cannot change the result in R or in % of equity. What separates
the bands is the provisioned stop distance d + c, hence the volatility, the cost-to-stop ratio c/(d + c), the tick
size and the liquidity. The study measures a **descriptive association** between "tight-stop opportunity" and net
expectancy, never a causal effect of leverage.

**Estimand.** Δ_b = E[R | L_eng ∈ b] − E[R | L_eng ∈ ]1,2]], where R = net PnL / (r·E) = net_bp / (d_bp + c_bp)
per trade at its own theoretical size. **Multiplicity (D1).** The configurations are nested (§2), so one trade
(series, side, decision second) can be produced by several configurations of an arm. Under D1 = `distinct_pair_day`
(owner's decision, 2026-10-15) the expectation is over distinct trades (each once); the alternative
`copies_config_pair_day`, over configuration copies (each distinct trade weighted by the number of configurations
that contain it), is published as a diagnostic only (§9).

- **Primary (single contrast):** b = ]2,3], L_eng, population P2-B4, per filled trade, pooled over the frozen
  configurations of §2.
- **Secondary bands:** ]3,5] and ]5,10], analysed only if both arms reach N_min (§9).
- **Reference group G_ref = ]1,2]:** natural trades of the same engine, configurations and ex ante admission rule,
  at their own size. Never levered up, never moved into a studied band.
- **]0,1] and > 10:** counted in the census, not studied, not compared. Under L_eng, > 7.14 is impossible by
  construction for P2 (§3), so ]5,10] can only hold its lower part.
- **Cumulative groups** G3 = ]2,3], G5 = ]2,5], G10 = ]2,10]: published from the disjoint bands, nested,
  descriptive only.
- **Indeterminable leverage:** counted by reason against all census signals and excluded from every arm.
- **Two versions:** per fill (primary; implementer choice C3, consistent with the owner's "N_min = 400 filled
  trades per arm") and intention to trade (an admitted order that is cancelled or not filled counts 0 R; secondary
  S3), because the fill depends on volatility (through rule).

## 2. Population P2-B4 and the frozen configurations

**Engine.** The tick engine of `research_exec` (`scalp_engine.py`), as frozen for r10–r12: entry E1 at the touch
(250 ms latency, through rule, maker 2 bp), the contract's 45 s order TTL (`SETUP_TTL_US`), cancelled if it would
cross at activation, staleness 10 s; decisions at the last second of each 5-minute candle (stacks of
`platform_setups.py`).

**Exit B4.** Stop at max(1.5 × ATR14(5m) of the decision second, 2 ticks); time exit 2 h after the fill; taker exit
no later than 23:59:00 UTC of the fill's day; no target. The trigger detection is the frozen one (a print at or
beyond the stop, stop before the deadline); the execution of the stop is made realistic (§5).

**Configurations.** Derived only from the texts of the four pre-registrations below, keeping the rows with exit B4,
entry E1 and no cost gate (K0; R1 has no cost-gate token, which is K0-equivalent). Identifiers are those of
`screen.build_grid` for each protocol (a test checks that each is in its source grid and that the list equals the
filtered union of the four grids). 31 distinct configurations:

| # | Configuration | Source | Citation |
|---|---|---|---|
| 1–6 | `P1-A3-K0`, `P1-A4-K0`, `P2-A3-K0`, `P2-A4-K0` (long); `P3-A3-K0`, `P3-A4-K0` (short); all `|E1|B4` | r10 | `PREREGISTRATION-r10.md` §3 (rules P{n}-A{3,4}-K{0,2,4,6}, k = 0 "the 1.1.0 behaviour"; exits B1–B4, "B4 no target: time-only exit at 2 h (stop and boundary kept)"); frozen 2026-10-13T12:15:50Z, body sha256 `fa24489d…` |
| 7–15 | `P{1,2}-A4-K0-V{1.1,1.2,1.3}|long|E1|B4`, `P3-A4-K0-V{1.1,1.2,1.3}|short|E1|B4` | r10x | `EXPLORATORY-r10x.md` §2 analysis 1 (P{n}-A4-K{0,2,4}-V{t}, k = 0 kept), frozen 2026-10-13T14:55:07Z, body sha256 `a402cd10…` |
| 16–27 | `P{1,2}-A{3,4}-K0-Z{1,0}|long|E1|B4`, `P3-A{3,4}-K0-Z{1,0}|short|E1|B4` | r10x | `EXPLORATORY-r10x.md` §2 analysis 2; the three `P{n}-A3-K0-Z1` rows are also r11's (`PREREGISTRATION-r11.md` §2), kept once |
| 28 | `P0-A3-K0-Z1|both|E1|B4` (pool: long stream P1 ∪ P2, short stream P3) | r11 | `PREREGISTRATION-r11.md` §2 (exits B4 / B2 / B3; B4 kept), frozen 2026-10-13T15:23:04Z, body sha256 `6c9dc905…` |
| 29–31 | `R1|long|E1|B4`, `R1|short|E1|B4`, `R1|both|E1|B4` | r12 | `PREREGISTRATION-r12.md` §2 ("no cost gate", "Exit B4 only", three side rules), frozen 2026-10-13T16:10:04Z, body sha256 `a04f0358…` |

**Nesting.** The rows overlap by construction: A4 ⊂ A4-V1.3 ⊂ V1.2 ⊂ V1.1 ⊂ A3 (the A4 volume threshold is
1.4); Z1 and Z0 partition their parent; the long stream of P0 is P1-A3-Z1 ∪ P2-A3-Z1 and its short stream is
P3-A3-Z1; R1 covers every in-zone cadence second; `R1|both` repeats `R1|long` and `R1|short`. One in-zone P1-A4
signal can belong to about nine configurations. How copies count is decision D1 (§9, §12).

**"Both" rows: a departure from the source semantics (decision D2).** r11 §2 ran P0 as two independent streams and
r12 §2 ran `R1|both` as "two streams run independently … one position at a time per stream, no netting". Here the
owner-accepted rule of the audit (draft §4.5: one position per pair, the long and short streams merged) applies, so
`P0-A3-K0-Z1|both` and `R1|both` are **re-defined**, not reproduced. The implementer's conflict rule (§5) makes
`R1|both` place an order only when the band filter admits one side and refuses the other, an arbitrary selection;
under the source semantics `R1|both` is a pure duplicate of `R1|long` ∪ `R1|short`. D2 = `drop` (owner's
decision, 2026-10-15; the alternative was `keep_conflict_rule`): `R1|both` is removed from E0a and E1, which run 30
configurations.

The r10x reference rows (`P{n}-A{3,4}-K0`) are the r10 rows 1–6 (same identifiers). The four source documents are
under `tradingV3-private/research/20261013T121000Z-scalping-120-r10/` (r10, r10x),
`20261013T153000Z-zone-time-r11/` and `20261013T160000Z-zone-only-r12/`; their body hashes are on their `.sha256`
files. The code list is `research_lev/protocol.py` `CONFIGURATIONS`.

**Not in P2-B4 (excluded a priori):** B1–B3 (targets in multiples of R: arithmetic confirmations, audit §4.9); the
cost-gated rows K2/K4/K6 (empty under L_eng by construction: K2 ≤ 1.79, K4 ≤ 1.02, K6 ≤ 0.71); σ exits; candle
engines (`research_v2`, NOT_INFORMATIVE for d < 8 bp by bar resolution); Q3 V2 (constant L 0.22); the PHP platform
(L ≤ 1.28); every exit without a stop (indeterminable).

## 3. Leverage definitions, costs, bands, indeterminables, floor strata

All quantities are computed **at the decision second, before any cap, rounding or minimum**, in basis points of the
order price (`research_lev/leverage.py`, `engine.decision_inputs`).

- **Order price.** E1: the bid (long) or the ask (short) of the decision second, rounded to the tick — exactly the
  price `run_policy` sends.
- **Stop distance d.** distance = max(1.5 × ATR5[s], 2 ticks) (`scalp_engine._setup_exit`); the effective trigger is
  the first grid price at or beyond `price − side × distance` (rounded away from the entry; a print on the grid
  reaches the unrounded stop iff it reaches this price, so the frozen detection is unchanged); d_bp = side ×
  (price − trigger) / price × 1e4. This is a deviation from audit 05 §5.1 (d = max(1.5·ATR5, 2 ticks) unrounded):
  it moves band membership only at the exact edges (implementer choice C4).
- **Cost provision c (owner decision).** c_bp = 2 (maker entry) + 5 (taker stop) + s + p, with s = `spread_bp` of
  the decision second (proxy from the prints, `tick.py:227`, at least one tick) and p = 1 tick of slippage
  provision (tick / price × 1e4). Funding is not in c (see §11 and decision D4).
- **Primary: L_eng = r / (d + c) = 50 / (d_bp + c_bp)**, r = 0.005 (passed as a fraction).
- **Secondary, separate analysis: L_stop = r / d = 50 / d_bp** (legacy `DynamicLeverageService.php:105` form). It is
  not a sensitivity: it moves the threshold (L_stop > 2 ⇔ d < 25 bp; L_eng > 2 ⇔ d + c < 25 bp), with its own arms,
  counts and E0a trigger; its contrasts are in the Holm family (§9).
- **Bounds by construction (P2).** L_eng ≤ 50 / (7 + s + tick_bp) < 7.14; ]3,5] needs d + c < 16.7 bp and ]5,10]
  needs d + c < 10 bp, reachable only at the two-tick floor on small-tick pairs. No band of P2 is declared empty a
  priori; an arm under N_min is published NOT_INFORMATIVE.
- **Bands** (left-open, right-closed): ]0,1], ]1,2], ]2,3], ]3,5], ]5,10], > 10. 2.0 belongs to ]1,2], 3.0 to ]2,3],
  5.0 to ]3,5], 10.0 to ]5,10] (tested).
- **Indeterminable** (counted by reason, excluded): `atr_missing` (ATR5 not yet available), `quote_stale` (no
  mid, or quote older than 10 s), `spread_missing`, `nonpositive_distance` (guard); in E1 also `no_quote` (no quote
  at activation, the engine's own skip).
- **Floor strata.** A stop at the two-tick floor (2 ticks ≥ 1.5 × ATR5) has a known d: it is determinable, flagged
  in every band, and published with and without it everywhere: per arm (`floor_split`: n and mean R with and
  without the floor), for the primary and every contrast S1–S6 (`without_floor_descriptive`: n and Δ, outside
  Holm), for the cumulative groups, and as the tested secondary S9 (]2,3] under L_eng).

## 4. Universe, data, windows, consumed data

- **Pairs (19):** the twenty r8/r10 pairs minus BTCUSDT, removed by name before any computation (`P2_SYMBOLS`):
  majors ETH, SOL, XRP, DOGE, BNB, ADA, LTC, LINK, AVAX; mid-caps ARB, OP, APT, SUI, NEAR, TIA, SEI, INJ, ATOM, FIL
  (all `USDT`).
- **Windows:** the four r10 training weeks `w2404` 2024-04-15..19, `w2405` 05-13..17, `w2406` 06-10..14, `w2408`
  08-12..16 (76 pair-week series, 380 pair-days).
- **Data:** `tradingV3-private/research/20261013T121000Z-tape-binance-r10/` (aggTrades npz; monthly 1-minute klines
  2024-03 → 08 for the stacks, ATR5, VWAP and zone); instruments `instrument-assumptions-r10.json` (sha256
  `cd940cffdd1212ff…`). Quotes are proxied from the prints (no Binance top-of-book exists on disk).
- **Input checks before any claim is consumed** (`cli.preflight`, reading no tape content): the sha256 of the
  instrument file (`cd940cffdd1212ff76c399917536e871ed0bfeed8733fa2bb74a7e5dc0b6fb92`), of `manifest-aggtrades.json`
  (`e0f1a68f40d701ec1a035c0a31e8c0f5cd93e9597a78fccebe432bb0515a4986`) and of `manifest-klines.json`
  (`a2876b084566e4fb5e34b5c4cdfaae9a9a4aab1111b3e720f853014da569a205`) must equal these frozen values; the planned
  series must be exactly the 76 pair-weeks with no missing day; every klines month (with one warm-up month) must be
  present. Otherwise the run is refused and the claim stays unconsumed. A stage whose series count differs from 76
  is never marked complete.
- **Consumed data.** These 380 pair-days were read by r10 (96 rows), r10x (180 rows), r11 and r12 at their
  training stages and by r13 (metrics). This study reads nothing else: no September, October or November 2024 file,
  no 2025 tape, no forward data. After this study, these windows remain consumed and no period becomes available
  again. E1 is not a replication and 2025 is never used as a validation.
- **Prior knowledge on record.** The aggregate, band-agnostic B4 result of each of these configurations on these
  380 pair-days is already known from the r10, r10x, r11 and r12 reports (r11 §5 even quotes the in-zone B4 means,
  +30.4, +22.8 and +9.4 bp); only the split by leverage band is new. The majors' 2024 1-minute klines were also read
  by the earlier candle studies (r10 §1). Nothing in this protocol was chosen from those results: the band
  definitions, thresholds and tests come from the owner's decisions and the audit, fixed before this text.

## 5. E1 replay

One replay per configuration × pair × **arm**, an arm being (definition, band) among ]1,2] (reference), ]2,3],
]3,5], ]5,10] under L_eng and under L_stop, run only if triggered by E0a (§7). Implementation
`research_lev/engine.py`; `scalp_engine.py` is not modified.

- **Ex ante admission.** At each decision second, after the order price is computed and before the order is sent
  (the place of `scalp_engine.py:149`–`:153`), the signal's leverage under the arm's definition is computed from its
  decision-time inputs; an indeterminable or out-of-band signal does `continue` **without touching `busy_until`**
  (counters `excluded_band` and the reasons). Filtering saved ledgers is not used (a ≤ 2x trade blocked its pair there).
- **One position per pair** (owner-accepted, audit §4.5). The streams of a "both" configuration are merged: the
  pair is busy while either side has an order or a position. Two sides admitted in the same second while flat are a
  `conflict`: no order, nothing blocked (implementer rule, C5; consequence: `R1|both`, whose two streams fire on the
  same seconds, places almost no order in E1 — see D2).
- **Identity.** With one stream, no admission and the frozen exit, the loop reproduces `run_policy` byte for byte
  (tested on synthetic data for B4, X1, X3, Z3, both sides).
- **Realistic stop execution.** The trigger detection of B4 is unchanged; the stop executes at market 250 ms after
  the trigger print at the worst of the trigger print, the prints within the latency and the live touch (the logic of
  `q3r2_engine.stop_exit`, mirrored for shorts). The time exit is unchanged (taker at the touch). The frozen touch
  price is kept as a diagnostic. Fees: 2 bp maker entry + 5 bp taker exit.
- **Slippage grid (in R).** 0, 1, 2 and 5 extra adverse ticks on the taker exit. Following the audit (§4.8,
  accepted by the owner), a band whose mean net R changes sign across the grid is **NON_INFORMATIVE**, and so is
  every contrast or test that involves it: the verdict is overridden (§9), whatever its p-value. The sign of Δ
  across the grid is published as a descriptive label (SIGN_STABLE / SIGN_FLIP). No proportional (size-dependent)
  term is applied: the draft never fixed its coefficient (C6).
- **Export per admitted order:** status (crossed, unfilled, filled, no exit), decision second, fill, entry, exit,
  frozen exit, reason, gross / fees / net bp, hold, d, c, spread, tick_bp, L_eng, L_stop, floor flag, ATR5 bp,
  `stale_900`, trigger, stop slippage, same-second stop flag, worst price between the fill and the exit, settlements
  crossed (00/08/16 UTC assumed for every pair: approximate, a pair on a 4 h funding interval crosses more), and
  the C1 fields.

## 6. Comparators

- **C1 — paired counterfactual per signal.** For every filled trade, the same fill is re-evaluated with the stop
  widened to d′ such that L_eng = 1.5 (d′ = 33.33 bp − c; C1 indeterminable when c ≥ 33.33 bp), without any pair
  blocking or portfolio coupling (so both members contain exactly the same trades). B4 has no target: only the stop
  width changes. The C1 hold time is published (C1 also changes the duration). Test S7: mean (R − R_C1) in ]2,3].
- **C2 — stratification.** Strata = terciles of volatility (ATR5 / price, bp) × tick size (bp) × liquidity
  (`stale_900`, share of the last 900 s without a print), 27 cells (terciles and 27 cells: implementer choice C7).
  The tercile cut points are computed by E0a on the distinct determinable census signals (outcome-free, C2) and used
  unchanged by the analysis. A trade whose stratum input is not finite (e.g. `stale_900` in the first 900 s of a
  window) goes to an explicit indeterminable cell, excluded from S8 and counted (never to the top tercile). Δ within cells over the
  common support (cells with trades in both arms), weighted by the studied arm's counts; the support share is
  published; the effect is local to the support. Test S8.
- **C3 (random control), B1–B3, K2 under L_stop on its own population, σ exits on q3x, the 500-USDT portfolio replay
  and the reframing "tight stop at equal volatility": NOT authorised now.** They may become future protocols; this
  package does not run them.
- The ratio c/(d + c) is inseparable from L: reported as a mechanism, never as a controlled factor.

## 7. E0a census and the E0a → E1 trigger

- **E0a** (`research_lev/census.py`): every signal second of every active configuration (30 under D2 = drop)
  and stream, on every series, classified from its decision-time inputs only — no order, no fill, no exit, no print
  after the decision second, no pair blocking. It is an upper envelope of what E1 can admit. It counts each
  configuration's signals (nominal, `pooled`) and the **distinct** signals (series × side × decision second once,
  `pooled_distinct`).
- **Outputs:** counts per configuration × pair × window × stream × definition × band, the indeterminable reasons,
  the floor counts per band, pooled totals per definition and band, by configuration, by pair and by window, and the
  C2 tercile cut points.
- **Trigger rule (owner decision):** for each definition, a band is triggered iff census × f_min ≥ N_min, with
  f_min = 0.3 and N_min = 400; the census count is the distinct one (D1 = `distinct_pair_day`, owner's decision); the
  nominal count is published, not used by the trigger. The E1 command recomputes the trigger from `e0a-census.json` and refuses to
  run if it differs from `e0a-trigger.json`. E1 runs, for a definition, the reference arm ]1,2] plus every triggered studied band,
  and nothing at all for that definition if ]1,2] is not triggered or no studied band is.

## 8. Measurement diagnostics (published per arm, before any expectancy)

Orders, fills and fill rate; stop share; share of stops triggered in the fill's second; stop slippage in R (p50,
p90, max); mean difference in R between the realistic and the frozen stop execution; floor share; σ-fallback share
(0 by construction: ATR stop); stop/target tie share (not applicable: no target); share of trades crossing a
funding settlement (8 h grid, approximate); median hold; worst trade in R and share below −2 R (to be read against
the mechanical floor: the slippage in R grows as 1/(d + c)); the floor split; duplicated trades across nested
configurations and the nominal count; per-configuration and per-source counts, shares and mean R (nominal, each
configuration's own trades) with the largest configuration share as a concentration diagnostic; the indeterminable
and exclusion counters; the analytic liquidation counters (§10); the primary under the other multiplicity rule and
with every copy in pair × day clusters (diagnostics, §9).

## 9. Inference and frozen decision rules

- **R** per trade = net_bp / (d_bp + c_bp) at slippage-grid point 0, for every arm and both definitions (every
  trade at its canonical size, §10).
- **Decision population and clusters (D1 = `distinct_pair_day`, owner's decision 2026-10-15).** Each arm keeps
  every distinct trade (series, side, decision second) once; N_min = 400 counts distinct filled trades; clusters =
  pair × UTC day of the decision. The alternative `copies_config_pair_day` (the audit's original wording, accepted by
  the owner before the nesting was identified; diagnostic only): every configuration copy counts, in N_min too;
  clusters = configuration × pair × UTC day. With
  copies, configuration × pair × day clusters treat the copies of one trade as independent and narrow the interval
  by about √(number of copies) (tested), so this option is anti-conservative. The other combination is published as
  a diagnostic.
- **Interval:** cluster bootstrap, drawn jointly for the two arms of a contrast (a cluster carries its trades of
  both arms), 10,000 resamples, seed 20261015; two-sided percentile interval at 1 − α;
  p = min(1, 2 · min(P*(Δ ≤ 0), P*(Δ ≥ 0))).
- **Primary:** Δ ]2,3] − ]1,2] under L_eng, P2-B4, per fill, pooled; α = 0.05 two-sided; no adjustment.
- **Verdict rule:** NOT_INFORMATIVE if either arm has fewer than N_min = 400 filled trades (counted under D1), or
  if either band of the test is slippage-sensitive (§5); otherwise DIFFERENT_LOWER or DIFFERENT_HIGHER if p < α
  (equivalently the 95 % interval excludes 0), with the sign of Δ; otherwise NO_DIFFERENCE_DETECTED. The grid labels
  of both arms and the descriptive SIGN_STABLE / SIGN_FLIP label of Δ are published beside the verdict.
- **Secondary list (Holm, declared now, family of nine):** S1 Δ ]3,5] (L_eng); S2 Δ ]5,10] (L_eng); S3 Δ ]2,3]
  intention to trade (L_eng); S4 Δ ]2,3] (L_stop); S5 Δ ]3,5] (L_stop); S6 Δ ]5,10] (L_stop); S7 C1 mean (R − R_C1)
  in ]2,3] (L_eng); S8 C2 stratified Δ ]2,3] (L_eng); S9 Δ ]2,3] without the floor strata (L_eng). A test whose arm
  is under N_min, not triggered, or slippage-sensitive keeps p = 1 in the family and is NOT_INFORMATIVE. Each verdict
  uses the Holm-adjusted p with the rule above. The audit asked for L_stop to have "its own multiplicity budget"
  (03 §3.2, 04 §4.2) while its §4.13 and the owner's decision put Holm "over the full secondary list": S4–S6 share
  the single family of nine, the more conservative reading (C8).
- **Descriptive robustness (not a replication, no gate):** Δ of the same sign on ≥ 3 of the 4 weeks; no pair above
  30 % of the band's trades; per-pair and per-week splits.
- **Diagnostic only:** the primary under the other D1 option, and with every copy in pair × day clusters.

## 10. Applied leverage, liquidation and sizing guard

- **Sizing:** every trade, in an L_eng arm or an L_stop arm, at its canonical size N = r·E/(d + c), so its
  notional / E is L_eng. L_stop only classifies trades into bands; no trade is ever sized at r·E/d (that would size a
  trade of L_eng ≤ 2, e.g. d = 20 bp and c = 9 bp, at 2.5 E: a lever-up, forbidden). R does not depend on E. The
  liquidation control checks, for every trade, that the implied notional / E does not exceed its L_eng
  (`assert_not_levered_up`, wired in `stats.liquidation_control`, tested) (C1).
- **Applied leverage (margin setting, not size):** A_ceil = min(ceil(L_eng), A_max) (canonical
  `finalLeverage = max(1, ceil(notional/balance))`) and A_full = A_max, scenarios A_max ∈ {2, 10}, for the L_eng and
  the L_stop arms alike. Isolated margin N/A must fit in the equity: refused iff L_eng > A (with A_max = 2 every trade
  above 2x is refused; counted). A_full on the ]1,2] group sets the margin at A_max with the size unchanged: it only
  moves the analytic liquidation price (C9).
- **Liquidation (analytic control, no change to the fills):** isolated proxy of `portfolio_simulator.py` — long
  entry·(1 − 1/A)/(1 − MMR), short entry·(1 + 1/A)/(1 + MMR) — against the worst print between the fill and the
  exit; MMR 0.01 for the majors ("not attested"), {0.005, 0.01, 0.02} for the mid-caps (unknown). Counters per arm,
  rule, A_max and MMR. For A ≤ 10 the liquidation lies ≥ ≈ 9 % from the entry, far beyond stops < 25 bp: expected
  zero except on gaps of that size.

## 11. What can and cannot be concluded

- **Can:** a descriptive, post hoc association between the theoretical-leverage band (that is, the provisioned stop
  distance) and the net expectancy in R on consumed 2024 Binance windows, decomposed by C1 and C2, with measurement
  diagnostics.
- **Cannot:** a strategy; a causal effect of leverage; a validation or replication; a statement about a band under
  N_min; any use of 2025 as validation; a transfer to OKX Europe X-Perps without a new measurement on unread data.
- **Venue.** Binance USD-M data, not executable for a French retail account and not usable for live execution under
  the Binance data licence; OKX Europe X-Perps (owner's account check 2026-10-15: isolated margin, leverage selector
  up to 50x on OKB, maker 0.02 % / taker 0.05 %, USDC margin) is feasibility context only. Ticks, contract values,
  fees, funding intervals and maximum leverage per instrument differ and are not modelled here.
- **Funding (D4 = `not_charged`, owner's decision 2026-10-15).** The audit (§4.8) asks for the real rate or a
  declared provision. This package charges
  neither; it reports the share of trades crossing a settlement on an approximate 00/08/16 UTC grid (wrong for a pair
  on a 4 h interval in 2024). D4 = `not_charged` records the owner's explicit acceptance of that (B4 holds ≤ 2 h);
  the share of trades crossing a settlement is published (§8). A provision would need an amendment and new code.

### Owner's questions A–D: reading guide (2026-10-15)

The owner's questions (his wording, 2026-10-15) and where the report answers them. This is a **reading guide only**:
it adds no test, no criterion, no threshold and no output; every verdict comes from §9 alone. A part of a question
that no frozen metric covers is said to be unanswered, and the report says so rather than computing something new.
Keys are those of `e0a-census.json` (E0a) and `e1-analysis.json` (E1 analysis, per arm `arms[<definition>:<band>]`).

**A. Sélection** — « combien d'opportunités sont éliminées parce que leur levier théorique est ≤ 2× ? combien
subsistent dans chaque tranche ? existent-elles sur suffisamment de paires et de périodes pour une étude
statistique ? »

- Eliminated by a > 2x filter: the E0a distinct counts of ]0,1] and ]1,2] (`pooled_distinct`, per definition)
  against all determinable distinct signals; indeterminables by reason (`distinct_indeterminate`), excluded from
  every arm. In this study the ]1,2] trades are not discarded: they are the reference group, at their own size,
  never levered up (§1, §10). In E1, the admission counters (`excluded_band` and the reasons, §5).
- Remaining per band: E0a distinct counts per band and definition (`pooled_distinct`), nominal counts by
  configuration, pair and window (`by_config`, `by_pair`, `by_window`), floor counts; E1 per arm `orders`, `filled`,
  `fill_rate`, `filled_nominal`, `duplicate_trades_across_configurations` (§8).
- Enough for a statistical study: the only frozen criteria are the E0a → E1 trigger (census × 0.3 ≥ 400, §7) and
  N_min = 400 distinct filled trades per arm (§9, otherwise NOT_INFORMATIVE). Spread across pairs and periods: per
  arm `by_pair`, `by_week`, `max_pair_share` (descriptive, §9); no extra threshold.

**B. Performance** — « la tranche > 2× a-t-elle une meilleure espérance brute et nette que la tranche ≤ 2× ?
l'avantage persiste-t-il après frais, spread, slippage et funding ? résiste-t-il aux changements de période, de
paire et de volatilité ? »

- Net expectancy and the decision: the primary Δ ]2,3] − ]1,2] (L_eng, per fill) and its verdict (§9); the other
  bands through S1, S2 (L_eng) and S4–S6 (L_stop), Holm-adjusted; per arm `mean_r`, `median_r`, `win_rate`. Gross
  expectancy: per arm `mean_gross_r` and `mean_cost_r` (descriptive; no test on gross R).
- After costs: R is net of the 2 bp maker entry and 5 bp taker exit and uses the realistic stop execution (§5); the
  spread enters through c and the touch prices; slippage through the grid 0/1/2/5 ticks (`slippage_grid_mean_r`,
  `slippage_grid_label`, SIGN_STABLE / SIGN_FLIP of Δ, and the NOT_INFORMATIVE override of §5 / §9). Funding is not
  charged (D4): only the share of trades crossing a settlement is published
  (`settlement_crossing_share_8h_approx`), so the funding part is answered only as an exposure, not as a cost.
- Period and pair: the descriptive robustness of the primary (`weeks_same_sign`, `max_pair_share_band`, §9) and the
  per-pair / per-week splits. Volatility: S8 (C2, Δ within volatility × tick × liquidity strata, with
  `support_share`) and S7 (C1). These are descriptive or Holm-tested as declared; nothing here is a replication.

**C. Risque** — « impacts du levier sur la marge, les liquidations possibles, les pertes extrêmes, le drawdown et le
capital disponible. »

- Margin: the applied-leverage scenarios A_ceil / A_full × A_max ∈ {2, 10} and the count of trades refused under
  isolated margin (`margin_refused`, §10); per arm the distribution of `leverage` (L_eng, L_stop) and `d_bp_p50`,
  `c_bp_p50`, `cost_share_c_over_d_plus_c_p50`.
- Possible liquidations: the analytic liquidation counters per arm, rule, A_max and MMR (`liquidated_before_exit`,
  §10), which never change the fills.
- Extreme losses: per arm `worst_r`, `best_r`, `share_below_minus_2r`, `stop_slippage_r` (p50, p90, max),
  `same_second_stop_share`, `realistic_minus_frozen_r_mean` (§8), to be read against the mechanical floor (the
  slippage in R grows as 1/(d + c)).
- Drawdown and available capital: **not measured**. The 500-USDT portfolio replay is NOT authorised (§6), so there is
  no equity curve, no drawdown and no capital-in-use series; the report gives per-trade and analytic measures only
  (R at the canonical size, notional / E = L_eng, margin = notional / A) and says so.

**D. Robustesse** — « concentration sur quelques paires, journées ou les 5 % meilleurs trades ? la différence
est-elle crédible ou due à la sélection post hoc ? comparateur qui sépare levier, distance de stop et volatilité. »

- Pairs: `by_pair`, `max_pair_share` and the 30 % descriptive rule (§9). Days: the decision clusters are pair × UTC
  day, so the bootstrap interval already carries the day-to-day dependence; per-week splits (`by_week`) are
  published. No per-day breakdown and no share of the 5 % best trades is a frozen output: those parts are
  **unanswered** by this protocol (only `best_r` / `worst_r` per arm), and the report says so.
- Credibility versus post hoc selection: the nature of the study (§0: exploratory, post hoc, consumed windows), the
  prior knowledge on record (§4), Holm over the nine secondaries, the N_min and slippage overrides, the
  multiplicity diagnostics (primary under the copies rule and with every copy in pair × day clusters, §8 / §9), the
  configuration concentration (`max_config_share_nominal`), and §11 (what cannot be concluded). No verdict here is a
  validation.
- Separating leverage, stop distance and volatility: C1 (S7, same signals with the stop widened to L_eng = 1.5) and
  C2 (S8, strata of volatility × tick × liquidity), the L_stop definition (S4–S6), the floor strata (S9 and the
  floor splits). The ratio c/(d + c) is inseparable from L and is reported as a mechanism only (§6).

## 12. Execution, single-use claims, code, stop rules, owner decisions

- **Order of operations:** owner's go → freeze (§13) → `claim --stage E0a` → `census` → `e0a-trigger.json` →
  `claim --stage E1` (records the E0a outputs' sha256) → `replay` → `claim --stage analysis` → `analyse`. Outputs
  only under `tradingV3-private/research/20261015T090000Z-leverage-gt2/`.
- **Frozen locations.** The protocol file, its `.sha256`, the output directory
  (`tradingV3-private/research/20261015T090000Z-leverage-gt2/`), the tape and the instrument file are constants of
  `research_lev/protocol.py`; the CLI refuses any other path or expected hash, so a fresh output directory or a
  modified copy of this protocol is not a second chance.
- **Claims** (`research_lev/claim.py`, model `q3r2_c2.py`). A claim is refused unless D1–D4 are set; the protocol
  body sha256 equals the `.sha256` file and the FROZEN line; the FROZEN line carries `code sha256 <digest>` and the
  current code identity (sha256 of every `research_lev` and `research_exec` module, canonical JSON) has that digest —
  the code is pinned to the freeze for every stage; for E1 and the analysis the code also equals the E0a claim's;
  every prerequisite stage is complete and each of its outputs still has the sha256 recorded when it finished (the
  analysis depends on E0a and E1, since it reads the C2 cut points of `e0a-census.json`). The run consumes the claim
  before any data is read, re-checking the protocol body against the FROZEN line, the code and the prerequisites,
  and refuses a second run.
- **Resumption after a memory-guard abort (D3 = 1, owner's decision 2026-10-15).** This is an exception to strict
  single use: a stage (E0a or E1) stopped by the guard or by a dead worker before writing any output
  (`aborted_by_guard`) may be resumed once, with identical code, and the resumption is recorded; enforced by
  `consume`. The analysis is never re-run: the analysis stage has no resumption. (The alternative D3 = 0 would have
  closed the study NOT_INFORMATIVE for a stage lost to the guard.)
- **Resources:** `nice -n 15`, at most 2 workers, one series per worker at a time, one heavy step at a time; the
  memory guard is checked before each submission and every 0.5 s while series are in flight, and stops the run
  cleanly below 4,000 MB available; a worker that dies without returning its series (e.g. the kernel OOM killer)
  ends the run the same way instead of hanging (tested); long runs in the background with a log. No network, no
  exchange call, no order.
- **Code:** written after the owner's decisions and before this freeze, tested on synthetic data
  (`tests/test_research_lev.py`: no look-ahead, formulas and band edges, BTC exclusion, no lever-up, indeterminables,
  floor strata, identity with the frozen engine when the hook is off, admission without blocking, merged streams,
  realistic stop, C1, determinism, single-use claims, code pinning, prerequisite hashes, frozen locations, input
  preflight, guard in flight and dead workers, liquidation at the canonical size, Holm, the slippage override, the
  multiplicity rules, the C2 indeterminable cell, trigger rule, configuration list, no look-ahead in the kline-derived
  inputs, the CLI end to end). The package cannot change after the freeze without every claim being refused; a
  defect found later is disclosed and needs an amendment, and the affected stage cannot be re-read.
- **Stop rules:** E0a triggers nothing → E1 is not run and the study closes NOT_INFORMATIVE. Any arm under N_min →
  NOT_INFORMATIVE for that contrast.
- **Owner decisions, set by the owner on 2026-10-15** (in `research_lev/protocol.py` and in this text; the code
  refuses every claim while any of them is unset):
  - **D1 — multiplicity of nested configurations** (§1, §7, §9): **`distinct_pair_day`** — distinct trades, N_min =
    400 distinct filled trades per arm, the E0a trigger on the distinct census counts, the estimand over distinct
    trades, pair × day clusters. The copies rule (`copies_config_pair_day`) stays a diagnostic only.
  - **D2 — `R1|both`** (§2, §5): **`drop`** — `R1|both` removed, 30 configurations.
  - **D3 — resumption after a memory-guard abort** (above): **1** — one resumption, only if the guard stops a stage
    before any output is written; recorded; the analysis is never re-run.
  - **D4 — funding** (§11): **`not_charged`** — B4 holds ≤ 2 h; the share of trades crossing a funding settlement is
    published.
- **Implementer choices, accepted by the owner as written (2026-10-15)** (each outcome-free; the code implements the
  stated version):
  - C1 every trade at its canonical size r·E/(d + c); R = net/(d + c) in the L_stop arms too; L_stop only classifies
    (§10).
  - C2 the E0a terciles over distinct determinable census signals (§6).
  - C3 per fill as the primary version, intention to trade as S3 (§1).
  - C4 d measured to the grid-rounded effective trigger, a deviation from audit 05 §5.1 (§3).
  - C5 the conflict rule of merged streams (§5).
  - C6 no proportional slippage term (its coefficient was never fixed) (§5).
  - C7 terciles and 27 cells for C2 (§6).
  - C8 L_stop contrasts in the single Holm family of nine (§9).
  - C9 A_full on the ]1,2] group moves only the analytic liquidation price (§10).
  - The slippage override of §5 / §9 is the audit's rule (§4.8) as accepted by the owner; it is listed here so the
    owner can see that a ]1,2] reference whose mean R crosses zero on the grid makes every contrast NOT_INFORMATIVE.
- **Questions A–D** of the owner (draft §4.17 point 1): given by the owner on 2026-10-15 and mapped to the frozen
  metrics of §7–§10 in the reading guide at the end of §11 (no new test, no new criterion).

## 13. Freeze

The body above this line is hashed (sha256 over the file content up to and including the line "## 13. Freeze" and
the following blank line); the hash, the code digest (`claim.code_digest()`), the time and the git commit go to
`PREREGISTRATION-leverage-gt2.sha256` and to a `FROZEN <time> body sha256 <hash> code sha256 <digest>` line below,
before any claim. Status until then: **NOT_FROZEN — BLOCKED_ON_OWNER_DECISIONS D1–D4**.
