# H3-A — status and next decision (2026-10-16)

**Status: `SCORING_UNTESTABLE`** — for now, on the candidate sources examined, at a reasonable horizon.

This is a statement about testability, not about scoring in general. It does not say that the score has no
information, that every form of scoring is impossible, or that the H3 architecture is wrong. No model was trained, no
historical return was read, nothing was validated and no profitability is claimed. Every DRAFT value stays DRAFT.
**Nothing is launched by this page. The owner decides.**

Inputs: agents A-D (integration commit `cbff09de`), the two independent reviews and the responses
([H3-A-INDEPENDENT-REVIEWS.md](H3-A-INDEPENDENT-REVIEWS.md)), the DRAFT protocol
([H3-A-STATISTICAL-VALIDATION-DRAFT.md](H3-A-STATISTICAL-VALIDATION-DRAFT.md)).

## 1. Why not SCORING_TESTABLE

SCORING_TESTABLE needs all seven conditions. Most fail:

| Condition | State |
|---|---|
| A defensible economic source is qualified | **No.** CS-EARN is only conditional (M1 account eligibility and M2 liquidity unverified; M4 mechanism after T contested, QR-3). CS-FUND is not a scoring source; CS-UNLOCK, CS-XMOM, CS-MACRO and CS-REOPEN are rejected. |
| Frequency allows a sufficiently powerful test | **No.** On a CS-EARN-like synthetic stream (176 events a year, upper bound), the DRAFT primary has power 0.155 at 2 years and 0.23 at 4 years at IC 0.15 (reproduced). A1 alone reaches 0.8 at about 4.7 years, A2 at about 20 years. |
| Labels defined and representative of the target population | **Partly.** The reference static-exit label on `TECHNICALLY_ELIGIBLE` fixes the outcome-selected replay labels by definition, now enforced in the V2 contracts. Open: execution-timing rule (R1-F6), data-coverage gaps, non-capturable first bar. |
| Matched random control works | **In synthetic nulls, yes** (BR stratified by day removes the R1-F1 confound). Open: matching on score status (QR-7), the least-favourable null (QR-6), capacity-binding cells. |
| A valid statistical procedure exists | **No.** None validated through the real mechanics; A1 is not pinned to one statistic (QR-4); rank IC drifts above nominal at 104 weeks in dependent nulls. |
| An independent validation path is identified | **Not secured.** The CS-EARN holdout can only be prospective; the trial ledger cannot yet detect a truncated or forked ledger without an external anchor; registry overlap rule (CR-V2-4) not applied. |
| Data and authorisations available or future collection approved | **No.** No prospective X-Perp capture is approved and none runs. A start on 2026-11-02 is only a possibility, not a decision. Underlying-stock history is not admitted. |

## 2. Why SCORING_UNTESTABLE rather than SCORING_CONDITIONAL

The owner's definition of UNTESTABLE is "no defensible generator allows credible learning and validation at a
reasonable horizon". That is the situation today:

- **Only one generator is left, and it is weak where it matters.** CS-EARN's distinctive argument (the ±10 % band,
  off-hours price discovery) acts before the decision instant T; after T the X-Perp tracks a large-cap stock, where
  the drift is reported absent since 2006, and its side rule mirrors the closed H2 family (QR-3).
- **The horizon is structural, not a missing measurement.** With 176-208 events a year at best, the full primary
  stays below 0.25 power after 4 years even at a strong IC of 0.15; a non-rejection would be uninformative, which
  the DRAFT itself (§7) classifies as "untestable on this source". The CTO reviewer's own fall-through rule (accepted
  horizon under about 3 years → UNTESTABLE) is met by this evidence: even A1 alone needs about 4.7 years.
- **Learning is not possible before validation.** The engine needs a CALIBRATED model; nothing may be fitted on
  prospective data; X-Perp history is consumed or quarantined and at most four months old; underlying history is not
  admitted. Every horizon above excludes a training period (QR-2).

The CTO reviewer recommended CONDITIONAL because the prerequisites are cheap to measure; that is true of M1, M2 and
the code items, but none of them can shorten the horizon or create training data. The disagreement is recorded.

What is kept: the contracts, the BR control, the census, the trial ledger and the DRAFT protocol are reusable. They
make a future source testable faster; they are not evidence.

## 3. What would reopen H3-A (to SCORING_CONDITIONAL)

Each is an owner decision or a new source, not a measurement:

| Reopening route | Missing proof | How to obtain | Cost | Delay | Decision criterion |
|---|---|---|---|---|---|
| R-1 Admit underlying US-equity history for development (training, M4 check) and possibly a disjoint historical holdout window | Owner authorisation; registry rows `us-equity-underlying-history` (and EDGAR filing metadata); literature consumption declared | Written owner decision, then registry rows and a DRAFT amendment before any read | Owner time, low | Days | Pre-registered power ≥ 0.8 at the owner's minimum IC within the accepted horizon, with training outside the confirmation data |
| R-2 Restate H3-A as A1 only on a pre-specified, non-fitted score | Owner acceptance; a minimum useful IC ≥ 0.15; a horizon of about 5 years accepted | Protocol amendment (DRAFT) | Owner time | Days to decide; about 5 years of collection | Power ≥ 0.8 for A1 at the declared IC within the accepted horizon |
| R-3 Qualify a higher-frequency source with a mechanism | A source with roughly 3× CS-EARN's frequency that passes M1-M5 | Agent A style qualification from public metadata | Low | Days to weeks | M1-M5 pass and the power criterion above |

If none is chosen, H3-A stays closed as untestable on the current sources.

## 4. The owner's six questions

1. **Is there an economically defensible source, frequent enough and compatible with 500 USDT?** No. CS-EARN is the
   only candidate and is CONDITIONAL on M1/M2, contested on M4 after T, and at most 176-208 events a year
   (from the issuer count; announcement dates not counted). Evidence: H3-CANDIDATE-SOURCE-QUALIFICATION.md §3-§5,
   QR-3.
2. **Can we show that scoring adds value beyond trading less?** In principle yes: BR stratified by decision day kept
   size at or below nominal in every synthetic null where the naive B1 − B0 rejects 38-43 % of the time. In practice
   not at reachable sizes (primary power 0.28 at 52 weeks on B3's stream, 0.155-0.23 at 2-4 years on a CS-EARN-like
   stream), and matching on score status is still open. Evidence: H3-SCORING-ABSTENTION-CONTROLS.md §6, QR-1, QR-7.
3. **Are labels representative of the evaluated candidates?** The replay labels are not (outcome-selected by the
   fill collar; permutation p = 0.0005 on synthetic data). The reference static-exit label on the technically
   eligible population fixes this by definition and is now enforced by the V2 contracts; the timing rule and
   data-coverage gaps remain. Evidence: H3-LABELS-FEATURES-UNCERTAINTY-AUDIT.md §2, CTO-02, QR-8.
4. **Are uncertainty and features correctly defined?** No. The V1 uncertainty is a heuristic (the most extreme
   predictions get the highest confidence; `n_train` declared, not effective). The feature catalogue is not bound to
   a generator; `xs_rel_ret_lb` is PENDING in `catalogue_v2` (EXCLUDED recommended at binding, still active in V1);
   no CS-EARN catalogue exists and its plausible features meet closed families. Evidence: labels audit §4-§5, QR-9,
   QR-14, CTO-06.
5. **Can an independent protocol falsify H3-A?** A DRAFT protocol exists (A1 ∧ A2 ∧ A3, pre-declared falsification
   readings, an explicit "underpowered" status), but it is not frozen, its A1 statistic is not pinned, and on the
   only source a non-rejection would be uninformative for years. Evidence: DRAFT §3-§7, QR-4, QR-10.
6. **Continue H3-A or declare it untestable for now?** Declare it untestable for now (this page), keep the
   infrastructure, and let the owner choose whether to reopen through R-1, R-2 or R-3.

## 5. One prioritised next action (not executed)

**Owner decision, in writing: is underlying US-equity history (the issuers listed as OKX Europe X-Perps) admitted as
DEVELOPMENT data for H3-A — for training and for the M4 check of CS-EARN — and may a disjoint historical window serve
as the H3-A holdout?**

- Why first: it is the only route that addresses both structural obstacles at once (no training data, QR-2; no
  mechanism evidence after T, QR-3) and can shorten the confirmation horizon; R-2 and R-3 do not remove the training
  obstacle.
- What it needs to be decided: no data read, no training, no backtest. The decision is about eligibility only; any
  later read would require registry rows, a DRAFT amendment with the literature consumption declared, and a frozen
  protocol first.
- Decision criterion: **No** → H3-A stays `SCORING_UNTESTABLE` on the current sources; further work goes to R-3
  (source search) or stops. **Yes** → the coordinator drafts the registry rows and the protocol amendment (still
  DRAFT, nothing read), and the status can be re-assessed as `SCORING_CONDITIONAL` against the prerequisites listed
  in the integration blocker table and the reviews (M1/M2, M4, A1 statistic, BR on score status, timing rule,
  catalogue, ledger anchor, harness migration).

Nothing has been launched: no capture, no collection, no training, no backtest, no order, no change to `fwd-okx-r1`,
Temporal, systemd, docker or production configuration. The owner decides.

## 6. Owner decision 2026-10-16 and re-assessment (appended 2026-10-16; sections 1-5 above are unchanged history)

**Decision recorded.** The owner gave a **limited GO** for the documentary preparation of US-equity history of the
X-Perp underlyings: registry rows, a DRAFT amendment and a research plan. The GO excludes every return read,
collection, paid acquisition, calibration, optimisation, historical simulation and trading operation. The verbatim
text is in [H3-A-CS-EARN-DATA-AMENDMENT-DRAFT.md](H3-A-CS-EARN-DATA-AMENDMENT-DRAFT.md) §0.

**Done under it:**

- Three registry rows were added. Nothing is acquired, and the code refuses every use.
  - `us-equity-underlying-history` (`potential_not_acquired`);
  - `us-equity-underlying-evaluation-window` (`reserved`, mechanism evaluation, explicitly not X-Perp validation);
  - `edgar-filing-metadata` (`potential_not_acquired`).
- The DRAFT amendment covers four things:
  - the licence review;
  - EDGAR timestamps;
  - a rule-based point-in-time universe;
  - the windows. Development runs 2007-01-01 → 2019-03-31, the embargo one quarter, and the evaluation window
    2019-07-01 → 2025-12-31, reserved.
- It also sets anti-lookahead rules, the equity label, the transferability limits, an M4 assessment and a power note.

**Re-assessment, on prerequisites actually satisfied, not on the authorisation (owner item 6).**

| Prerequisite | State after this step |
|---|---|
| A defensible economic source (M4 after T, QR-3) | **Not satisfied: M4 FAIL as specified** (amendment §10). No venue mechanism acts after T. The large-cap evidence is negative or contested. The side rule mirrors the closed H2. |
| M1 TradFi X-Perp access and API; M2 liquidity, InstrumentSpec, fees and funding | Not satisfied (unchanged) |
| Licence of a point-in-time equity source | **Not satisfied.** No acceptable free source. The survivorship-free sources are paid or institutional. No paid acquisition is authorised. |
| Training data (QR-2) | Not satisfied. The data is registered as potential, not acquired; the amendment is not frozen. |
| Power at a reasonable horizon (QR-1) | **Not satisfied, and not changed by equity history** (amendment §11): primary 0.155 / 0.23 at 2 / 4 years; A1 about 4.7 years and A2 about 20 years to reach 0.8 at IC 0.15 |
| Execution-timing rule, A1 statistic, BR on score status, CS-EARN feature catalogue, ledger anchor, harness migration | Not satisfied (unchanged) |
| Collection approval (EDGAR metadata; prospective X-Perp capture) | Not given |

**Status: `SCORING_UNTESTABLE`, unchanged.** No prerequisite in the table moved to satisfied. The new M4 assessment
weakens the only remaining source further.

**Remaining owner decisions** (detail in the amendment §12):

- **D-1.** Given M4 FAIL as specified:
  - (a) stop CS-EARN;
  - (b) keep the frozen, development-only E-M4 equity check as the only route to CONDITIONAL, with a negative prior;
  - (c) a new, separately declared hypothesis.
- **D-2.** A paid data acquisition: vendor and budget.
- **D-3.** The window and universe rules.
- **D-4.** The crypto-coupled stratum.
- **D-5.** EDGAR metadata collection under fair access.
- **D-6.** The timing rule: acceptance time vs release time.
- **D-7.** Freeze the amendment and E-M4 before any read.
- **D-8.** M1/M2 and the minimum useful IC with an accepted horizon (unchanged).

Nothing was launched.

## 7. Owner decision 2026-10-16: `CS_EARN_M4_FAIL_CLOSED`

The owner closes CS-EARN as currently formulated. His reasons:

- the economic mechanism is not defensible enough after T;
- statistical power is insufficient at a reasonable horizon;
- buying historical data is not justified.

Decisions, as recorded:

1. **Stop CS-EARN.** No purchase, download, training, backtest, EDGAR collection or protocol freeze.
2. **Kept as scientific history:**
   - the registry rows (all `potential_not_acquired` or `reserved`, and refused by `check_use`);
   - the DRAFT amendment ([H3-A-CS-EARN-DATA-AMENDMENT-DRAFT.md](H3-A-CS-EARN-DATA-AMENDMENT-DRAFT.md));
   - the licence analysis;
   - the review results.
3. **H3-A stays `SCORING_UNTESTABLE`** for every source examined so far: CS-FUND, CS-UNLOCK, CS-XMOM, CS-EARN,
   CS-MACRO and CS-REOPEN.
4. **Unchanged:** the H1 and H2 verdicts, and the reserved fwd-okx-r1 windows.
5. **No new hypothesis is launched automatically.** The owner wants to examine later a new economic source of
   opportunities with these properties:
   - frequent enough to be validated in a reasonable time;
   - on a product really accessible from France, with 500 USDT;
   - independent of the closed H1, H2, CS-XMOM and CS-EARN.

   That needs his separate GO.

**Closure conditions, as they apply to any future use of CS-EARN-like ideas:**

- A post-earnings source on equity X-Perps can only come back as a **new, separately declared hypothesis**. It must
  carry:
  - its own mechanism after T;
  - its own power study;
  - a declaration that CS-EARN, H2 and this record were seen.
- The `us-equity-*` and `edgar-filing-metadata` registry rows stay non-readable until a new owner GO changes them.
