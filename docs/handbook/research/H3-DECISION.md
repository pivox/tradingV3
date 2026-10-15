# H3 — decision record (2026-10-16)

## Status

**`H3_BLOCKED_DATA_OR_HYPOTHESIS`**

This status is not a verdict on the market, and it makes no profitability claim. The H3 infrastructure exists and
runs end to end on synthetic data. Two independent reviews found defects in it; most are fixed and the rest are
documented ([H3-INDEPENDENT-REVIEWS.md](H3-INDEPENDENT-REVIEWS.md)). What cannot be done today is to feed it a
defensible stream of opportunities and test it on data that has not been read.

**Nothing is launched.** No capture, re-study, training or experiment starts without the owner's explicit go.

## 1. Why this status

The binding constraints are the economic hypothesis and the data, in this order:

1. **No candidate source is both powerful and compatible with the generator.** This comes from the candidate-source
   dossiers (PR #513) and review R1-F8.
   - **CS-UNLOCK** is the only good fit for the common generator, but:
     - it gives at most about 40 trades a year with holds of about 9 days;
     - it fails gate M3, because no licensed point-in-time unlock calendar exists in the registry
       (`token-unlock-calendar`: missing; at least about 828 USD a year);
     - it therefore yields no labelled history to train or calibrate a scorer.
   - **CS-FUND** is a yield measurement, not a factorial generator. It has two candidates in total, about 26 USD a
     year gross, and a verdict about 12.5 months after a freeze.
   - **CS-XMOM** is incompatible with the generator (six legs without a stop means 3 % risk) and was judged unsound.
     It belongs to the closed cross-sectional momentum family, which A's feature catalogue partly brings back
     through `xs_rel_ret_lb`.
   - The DRAFT guard of at least 100 B3 trades would need 2.5 years or more of CS-UNLOCK before any abstention.
2. **No independent historical data** (`INDEPENDENT_DATA_NOT_AVAILABLE`, [H3-DATA-ELIGIBILITY.md](H3-DATA-ELIGIBILITY.md)).
   - On the only execution target, OKX Europe X-Perps, history up to 2026-10-12 is consumed-equivalent, and
     2026-10-14..31 is quarantined around fwd-okx-r1.
   - Since review R1-F7, off-target windows over the consumed 2023–2026 paths are consumed-equivalent too, and the
     quarantine covers every venue.
   - Only a prospective X-Perp capture after a freeze can confirm anything, from 2026-11-02 at the earliest.
3. **No validated test procedure** (agent C).
   - The percentile block bootstrap was rejected (study 1).
   - No replacement passed in every synthetic scenario (study 2, `procedure_validated = False`), so the code refuses a
     FROZEN plan.
   - Review R1-F5 adds that the acceptance rule itself is too imprecise to say how long a window must be.
   - Review R1-F4 adds that the power figures assume paired trades, which the scored variants do not have.
4. **The design cannot yet answer its questions separately** (reviews R1-F1 and R1-F2).
   - S1 (scoring) is confounded by abstention, and S2–S4 by exposure.
   - Serial gatekeeping on B3 gives H3-A and H3-B no alpha of their own.
   - The code now refuses to report any hypothesis as `supported`.
5. **Execution and market access are not demonstrated.**
   - Live stop replacement is not demonstrated. In TradingV3, `TpSlTwoTargetsService` cancels and then places, with
     no rollback, and no adapter supports modify. Agent B and agent D (GAP-STOP-REPLACE, B4) therefore keep live
     adaptive stop modification forbidden.
   - The X-Perp instrument resolver is missing in TradingV3 (D, GAP-XPERP). Gate M1 is conditional (API access to
     X-Perps unverified), and the X-Perp audit is CONDITIONAL (#506).
   - Hourly-bar timing selects trades (reviews R1-F6 and R2-F5): about 43–45 % of synthetic entry attempts are refused
     at the collar, and every B2/B3 gap exit is action-induced.

### Why not the other statuses

- **`H3_REQUIRES_ARCHITECTURE_FIXES` is not the primary status.** The code defects the reviews found are fixed and
  tested (368 synthetic tests pass). The remaining architecture items do not block on their own today:
  - matched controls;
  - a multiplicity procedure;
  - the execution-timing rule;
  - schema v2 for provenance windows;
  - a persistent trial ledger.

  Each is a pre-freeze condition that needs an owner choice, and none of them would let H3 run without a source and
  data.
- **`H3_BLOCKED_MARKET_ACCESS` is not the primary status either.** A prospective paper replay needs public X-Perp data,
  not execution. Market access (M1, M2, GAP-XPERP) blocks trading, not the research question.

## 2. Proposed next experiment protocol (PROPOSED, for the owner's decision; not launched)

Working name **H3-N1: source qualification, then a synthetic re-study, then (only if both pass) a prospective
capture.** It reads no market data until the owner approves step 3.

### 2.1 Economic source of opportunities

- **Step 1, qualification (desk only, no price data).** A source is admissible for the full factorial only if it has
  all of the following:
  1. a written economic mechanism, different from the closed families under gate M4 (including CS-XMOM);
  2. at least about 5 candidates a week on the tradable universe, estimated from event counts and not from returns;
  3. holds of one day or less, so that adaptive exits have room and blocks are not overlapping;
  4. a licensed, point-in-time history of its candidates for scorer training, with listing and delisting dates;
  5. a candidate definition with a mandatory stop, compatible with 0.5 % risk at 500 USDT and the venue minimum
     sizes.
- **Default if no source qualifies:** declare H3-A (scoring) untestable for now. Either restrict H3 to H3-B (B0 vs B2)
  on the best generator-compatible source (CS-UNLOCK, only if its licence is bought), or close H3.
- CS-XMOM is abandoned. CS-FUND, if kept, is measured on its own as a yield, outside the factorial.

### 2.2 Tradable universe

- OKX Europe X-Perps only, the only execution target eligible from France.
  - 160 crypto X-Perps are listed; 29 trade at least 1 M USD a day.
  - The universe is built point-in-time from the listing and delisting record (`walkforward.universe_at`).
- No Binance, Hyperliquid or Kraken data may confirm an X-Perp claim. At most they may serve as development
  statistics on windows that are still `possibly_independent`.

### 2.3 Development data

- **Synthetic only by default.**
- Consumed historical data may be used only with the owner's consent per dataset (`registry.check_use`). It is never
  used for confirmation.
- The quarantine of 2026-10-14..31 is never read, on any venue.
- Labels come only from the capacity-free replay (`integration.label_replay`).
- Every development trial goes to a persistent, hash-chained trial ledger, to be built first (R1-F12).

### 2.4 Independent validation

- **Prospective only:** X-Perp 1m candles, funding and top-of-book quotes, captured after the freeze, from 2026-11-02
  at the earliest. It never starts before the fwd-okx-r1 verdict of 31 October.
- **Paper replay of the variants. No order, demo included.**
- The window length is fixed before the start, from the re-study of step 2. It is never extended or cut, and there is
  no interim performance look.

### 2.5 Steps, compute budget and tests

| Step | Content | Data | Compute budget (DRAFT) |
|---|---|---|---|
| 1 | Source qualification dossier(s); feature catalogue versioned to the chosen source, `xs_rel_ret_lb` dropped or justified | none (documents, event counts) | desk only |
| 2a | Pre-freeze code: matched controls (B0m over K seeds, exposure-matched static), separate-alpha multiplicity procedure, execution-timing rule, persistent trial ledger, schema v2 items #19–22 | synthetic | negligible; full H3 suite must stay green |
| 2b | Synthetic size and power re-study through the real mechanics. A mechanics-faithful tier calibrates overlap, abstention and timing at reduced scale; a fast tier with at least 4,000 panels per cell runs on the calibrated DGP. Covers negative μ0, partial overlap, ≤ 1 candidate a week, multi-week holds; reports P1, P2, the IUT and S1–S4 with a Monte Carlo interval | synthetic | at most 48 CPU-hours, at most 2 of the 4 CPUs, `nice`. Not while it could disturb the fwd-okx-r1 capture (or only after 31 October). No shared database. A pre-registered acceptance rule with stated precision is written **before** the run |
| 3 | Freeze (`PREREGISTRATION-H3.md`, hashed), then prospective capture | X-Perp public data from ≥ 2026-11-02 | a capture service like fwd-okx-r1's (light). Its own licence check first |

Tests required at every step:

- the full H3 synthetic suite (368 tests today) and A's golden vectors;
- the Python 3.11 grammar check and `mkdocs build --strict`;
- new tests for each step 2a item: the matched control's acceptance rate equals B1's; separate-alpha error control on
  synthetic nulls; timing-rule invariance under future perturbation; ledger chain verification;
- no test may open real data.

### 2.6 Stop criteria (declared in advance)

- **After step 1:** stop if no source meets the five criteria. Default: H3-A untestable, then H3-B only or close H3,
  as the owner chooses.
- **After step 2b:** stop if no procedure keeps its level, with stated Monte Carlo precision, at a window the owner
  accepts. Also stop if the power at the owner's minimum effect is below the owner's floor.
- **Before step 3:** stop if gate M1 or M2 fails for the account, or if the capture pipeline or the OKX market-data
  licence cannot be established.
- **During step 3:**
  - no interim performance look and no early stop;
  - if more than 10 % of the planned blocks fail the quality checks, the result is `INSUFFICIENT_EVIDENCE` (DRAFT);
  - any later trading decision is the owner's, separately (M5).

## 3. Owner decisions

1. **Confirm C's quarantine of all OKX price data from 2026-10-14 to 2026-10-31.** Confirm, narrow or drop the
   review's extension of it to every venue (`any-venue-2026-10-14-to-2026-10-31`). Confirm the cross-venue
   consumed-equivalent reclassification (R1-F7).
2. **Choose or abandon each candidate source.**
   - CS-XMOM: abandon (recommended).
   - CS-FUND: a stand-alone yield measurement outside H3, or abandon.
   - CS-UNLOCK: buy a point-in-time calendar licence for an H3-B-only study, or abandon.
   - Alternatively, commission a new source meeting the §2.1 criteria, or close H3.
3. **Choose a test procedure and a window, or accept a longer prospective window.** Pick `sn_gaussian`, `batch_t` or
   another procedure. Then either accept a longer prospective window, or rule `strong_cluster_t3` implausible on
   stated grounds. In either case, approve the step 2b re-study with Monte Carlo precision before any window is
   fixed.
4. **Multiplicity.** Choose a procedure that gives H3-A and H3-B their own alpha (R1-F2), and keep the IUT with P2 as
   the economic gate.
5. **Matched controls.** Approve B0m (random abstention over K seeds) and an exposure-matched static control. This
   includes the `Variant` wire change (R1-F1, schema v2 #22).
6. **Execution-timing rule** for entries and actions: sub-bar fills at the decision, or re-anchoring the reference at
   the fill (R1-F6, R2-F5).
7. **B4: live stop modification stays forbidden** (demo included). Confirm. Nothing in H3 changes that, and a stable
   intent key (R2-F2, #19) would be a prerequisite of any future live port.
8. **Kill-switch semantics in the simulation.** Production refuses every write under the kill switch. Choose whether
   H3 keeps closes allowed or models them as refused (R2-F6).
9. **Cash comparator.** Choose a pre-declared cash yield, or a descriptive comparison with a stated risk-free rate
   (R1-F10).
10. **Partial closes.** Pre-declare a liquidity status rule, or accept that B2/B3 measure trail-and-target only
    (R2-F9).
11. **Stop-replacement sensitivity rate** for `REPLACE_CANCELLED_PLACE_FAILED` (R2-F12).
12. **Schema v2** (backlog #1–#22 in [H3-ARCHITECTURE.md](H3-ARCHITECTURE.md) §7). A wire change means new golden
    vectors.
13. **Consent per consumed dataset** for development use, if any.
14. **Out-of-scope fixes.**
    - `portfolio_simulator.finish()` after a liquidation-proxy exit (R2-F10).
    - Excluding `scripts/research_*` from the orchestrator image through `.dockerignore` (R2-F11).
15. **Market access before any execution, not before a paper capture:** gates M1 and M2 for the account, and the
    X-Perp resolver (GAP-XPERP).
16. **This branch.** `claude/h3-opportunity-architecture` is local and was not pushed. Pushing it, opening a pull
    request and merging are the owner's decisions.

**Nothing is launched without the owner's explicit go.** That covers source purchases, captures, re-studies, any
training and any experiment.
