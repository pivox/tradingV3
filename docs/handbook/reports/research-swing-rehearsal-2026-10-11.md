# Swing canonical campaign — rehearsal (2026-10-11)

## Result

The full canonical pipeline of campaign `swing-2026-10-11` ran end to end on real data, outside the 2026 holdout:

- PHP signal and plan workers for both sides;
- the swing kernel;
- the independent statistics;
- the registry and selection freeze;
- the once-only holdout machinery, under a temporary `HOME`.

Every stage completed. Every unit matches the exploration trade for trade. The only differences are on positions still open at a window's end, which the two engines settle with different documented conventions.

The rehearsal found one defect, and it was in the pin procedure, not in the research code: the swing frozen-verifier inventory must be written as canonical JSON (one trailing newline). The authority refused the first rehearsal pin with `json_not_canonical` before installing anything. `scripts/research_swing_rehearsal.py pin` now writes it canonically, and the campaign runbook says so.

This rehearsal makes no claim about the candidate's performance. Its windows are three-month and one-month slices, and its screen keeps only the evidence gates.

## Setup

| Item | Value |
|---|---|
| Code | `claude/swing-holdout` (PRs 1–5b), research modules identical to main 6dfb43b3 |
| Frozen checkout | the code + `research_swing_rehearsal.py constants` |
| Runner checkout | frozen + `research_swing_rehearsal.py pin` (what PR 7 will do) |
| Training slice | 2024-07-01 → 2024-10-01 (warm-up from 2024-05-01) |
| Validation slice | 2025-07-01 → 2025-10-01 (warm-up from 2025-05-01) |
| "Holdout" slice | 2025-11-01 → 2025-12-01 (warm-up from 2025-09-01), protocol cutoff 2025-12-01 |
| Screen | evidence gates only (status, reconciliation, coverage, cost evidence) |
| Inputs | the campaign's dataset, funding supplement, instrument and cost assumptions and Python runtime |

Artifacts: `tradingV3-private/research/20261009T060000Z-swing-rehearsal-r1/`.

## Stages

1. **Training B1.** Four roots: both sides × two symbol groups, 552 4h decisions per pair, about 11 minutes per root with two at a time.
2. **Orchestration** (`--family swing`, 35 minutes):
   - It observed the four training roots and launched the four validation roots with `--profile`.
   - It froze a swing protocol: one candidate; one cell per side with each side's own setup, config and snapshot hashes; composite identity; ten-plan, one-per-pair risk assumptions; ten source runs per side and phase.
   - It ran the four units and froze the selection: `selected`, `guarded_selected_identity_only`.
3. **Authority.** `install_campaign_authority(..., campaign_id='swing-2026-10-11')` ran under the temporary `HOME` with the real preflight: B1 reports and baselines per side, baseline file hashes, PHP inventory, the research venv's runtime inventory and the pinned frozen files.
4. **Holdout** (62 minutes):
   - The real once-only claim was made, with the real frozen-verifier subprocess replaying the selection freeze.
   - One B1 root per profile ran with the real PHP worker.
   - The input bindings of the two units were published.
   - Both units ran with the real PHP planners and the swing kernel, and each was independently verified.
   - The report was published (`holdout_status: complete`).
5. **Read-only replay.** `--verify-retained` rebuilt the same report without generating or simulating anything.

| B1 root | Decisions | Passed |
|---|---:|---:|
| training long (2 groups) | 5,520 | 221 |
| training short (2 groups) | 5,520 | 171 |
| validation long (2 groups) | 5,520 | 308 |
| validation short (2 groups) | 5,520 | 58 |
| holdout slice long | 1,800 | 4 |
| holdout slice short | 1,800 | 91 |

| Unit | Costs | Trades | Net (quote) | Mean net R | Net PF |
|---|---|---:|---:|---:|---:|
| training slice | baseline | 88 | −59.13 | +0.028 | 0.94 |
| training slice | adverse | 88 | −85.81 | −0.002 | 0.91 |
| validation slice | baseline | 82 | +302.42 | +0.343 | 1.40 |
| validation slice | adverse | 79 | +257.45 | +0.190 | 1.34 |
| holdout slice | baseline | 23 | +168.28 | +0.423 | 2.14 |
| holdout slice | adverse | 23 | +161.33 | +0.285 | 2.08 |

## Canonical pipeline against the exploration

`scripts/research_swing_compare.py` matches every canonical trade with `swing.run_swing` by pair and 4h decision. It then compares fill and exit boundaries, exit reason and price, net PnL, funding, ambiguity and side.

| Unit | Costs | Canonical trades | Exploration trades | Open at the window end | Other differences |
|---|---|---:|---:|---:|---:|
| training slice | baseline | 88 | 88 | 8 | 0 |
| training slice | adverse | 88 | 88 | 8 | 0 |
| validation slice | baseline | 82 | 82 | 8 | 0 |
| validation slice | adverse | 79 | 79 | 7 | 0 |
| holdout slice | baseline | 23 | 23 | 8 | 0 |
| holdout slice | adverse | 23 | 23 | 8 | 0 |

A position still open at the window end is settled differently by the two engines:

- The exploration clips its deadline to the window end and exits at the close one minute before it (`holding_deadline`).
- The kernel settles it at the research end at the final close (`research_end`).

These trades differ only in exit reason, exit price and net PnL. All other trades are equal field by field. Rejection labels are not compared: the exploration counts a pair conflict before the plan arithmetic, the kernel after it.

The new ledger check of #470 (one active plan per pair) also accepts the four real registry units unchanged.

## Notes for the campaign

- **Pin.** The pin must write `frozen_verifier_inventory.swing.json` canonically. `research_swing_rehearsal.py pin` does.
- **Durations.**
  - Holdout: one month of holdout took about an hour, mostly the repeated re-verification of the raw source archives (publication, binding, unit start). The 2026 holdout (9.3 months) should take a few hours, well under its seven-day limit.
  - Training: B1 takes about an hour per pair of roots.
- **Storage.** About 110 MB per three-month unit, so under 1 GB for a two-year training unit.
- **French report.** The French selection report states the campaign's real phases (2023–2024, 2025) even when the rehearsal constants shorten them. This is cosmetic and only affects the rehearsal.
