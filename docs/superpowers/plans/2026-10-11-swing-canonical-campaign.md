# Swing canonical campaign — implementation plan (2026-10-11)

## Decision

On 2026-10-11 the owner chose option 1 of the [two-sided swing report](../../handbook/reports/research-swing-two-sided-exploration-2026-10-11.md): fix the swing portfolio rule, keep one candidate, then build the canonical path and test the 2026 holdout once.

- **Portfolio rule.** One active plan per pair, pending entries included, so at most 10 plans. Simultaneous signals on different pairs never compete. Notional is the frozen 250 quote at leverage 1.
- **Candidate** (exploration id `B30_2s` × `S3.T5.H14d`):
  - long when the 4h close is above the 4h EMA200 and above the highest high of the previous 30 closed 4h bars;
  - short when it is below the EMA200 and below the lowest low of the previous 30 bars;
  - entry is a limit at the 4h close, rounded toward the market side, resting 60 minutes;
  - stop at 3 × ATR14(4h), one target at 5 R, 14-day holding horizon with no daily boundary.
- **Costs.** The frozen baseline and adverse profiles. The funding provision covers 42 intervals of 8 h. A short reserves the same provision as a long.
- **Exploratory evidence, one plan per pair:**

  | Phase | Trades | Mean net R | Profit factor |
  |---|---:|---:|---:|
  | 2023–24 | 682 | +0.14 | 1.21 |
  | 2025 | 319 | +0.16 | 1.24 |

  Under the adverse profile the profit factor is 1.16 (2023–24) and 1.20 (2025). 2025 informed the choice, so only the 2026 holdout is out of sample.
- **No tuning from here on.** If the canonical pipeline disagrees with the exploration, the implementation gets fixed, never the parameters. Any parameter change means a new candidate and a new decision.

## Default technical decisions

Each decision is recorded here and can be overturned before the frozen commit.

1. **Identity.** Mode `swing@1.0.0`, `shadow`, executable for backtest only. Setups `swing.breakout.long@1.0.0` and `swing.breakout.short@1.0.0`. No demo, testnet or live flag changes. A new ADR supersedes ADR-300's "exactly three identities".
2. **Daily loss.** The candidate was evaluated without a daily-loss rule. The swing mode contract declares an explicit, non-binding cap (100 % of equity, 100,000 quote). Ten plans of 250 notional cannot reach it, so planner and kernel keep one code path. A campaign assertion requires zero daily-loss rejections.
3. **Lifecycle constants:**
   - entry TTL 3600 s, cancel after 3660 s (first bar after expiry, as 90 → 120);
   - holding window 1,209,600 s;
   - minimum net R 1.3, risk budget 5 %, exposure cap 100 %.
4. **Signals.** One research signal run per (symbol, side), at a 4h cadence, selected by a worker `--profile` option, so the v1 frame schemas stay. The kernel identity uses composite base hashes, which are the identity function for single-side day_trading.
5. **Breakout condition.** New conditions in catalog `1.3.0` (catalogs 1.0.0–1.2.0 stay byte-identical). The metric is scalar, computed from the 60-value `high_series` / `low_series` that excludes the current bar. New conditions accept integral closes (the canonical JSON turns them into integers).
6. **Variant catalogue for swing:** `baseline` only. Training, validation and holdout each run 2 units (baseline and adverse costs).
7. **Holdout.** A new campaign spec with its own frozen commit, protocol, registry, freeze and authority. The pin lands after the frozen commit. The owner installs the authority and runs the holdout once.

## Pull requests

Every PR keeps day_trading bit-identical and its tests green. Every PR gets one review cycle and green CI before merging.

| # | Scope | Proof |
|---|---|---|
| 1 | **Kernel.** `PortfolioSimulator` profile (TTL, cancel, holding window, daily boundary, concurrency, one plan per pair, daily-loss parameters, sides) and short arithmetic (fills, stops, targets, liquidation proxy, gross, funding) | Old tests unchanged; reflection test (short = long on the reflected series); real-data replay equal to `swing.run_swing` |
| 2 | **Evidence and statistics.** Side- and profile-aware replay (`statistics.py`, `campaign_evidence.py`) | Swing ledger fixtures; independent replay equal to the kernel |
| 3 | **Signal plumbing (Python).** Cadence, per-side execution identity, `--profile`, funding intervals from the profile | Default call shapes unchanged |
| 4 | **Stream identity.** Bindings keyed by (symbol, side), composite base hashes, plan worker per-side source runs, statistics sources | Day_trading identities unchanged |
| P1 | **PHP contracts.** Swing mode, two setups, catalog 1.3.0, the two conditions, validators, schemas, runtime layer, ADR, tests | PHPUnit suites listed in the maps, catalog stableHash pinned |
| P2 | **PHP research workers.** Profiles in `ResearchSignalSession`, `ResearchPlanSession`, `ResearchPlanBuilder`, `ResearchVariant`: side, cadence, candidate timeframe, negated short provision, one plan per pair, policy limits | PHP plans equal `swing.build_swing_plan`; signals equal the exploration frames |
| 5 | **Protocol and orchestration.** Swing protocol, variant catalogue, streams, resource bounds, code inventories | End-to-end rehearsal below |
| 6 | **Rehearsal.** Real PHP end to end on a non-holdout slice, including the holdout machinery under a temporary home | Equal to the exploration, except documented phase-end and rejection-label differences |
| — | **Frozen commit X.** Run training and validation signals (per side), the campaign, the freeze | Selection freeze |
| 7 | **Holdout pin** (after X), then the owner installs the authority and runs the holdout once | Holdout report |

## Stop rules

- If the canonical training/validation result fails the protocol gates, the campaign ends with `no_eligible_candidate` and the holdout is not opened.
- Any defect found after the holdout claim is reported, not fixed and rerun: the claim is single-use.
