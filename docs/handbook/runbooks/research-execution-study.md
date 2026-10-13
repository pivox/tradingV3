# Runbook — execution study and tick-data scalping screen (`scripts/research_exec`)

Read-only research on the paper public captures (OKX `run14`, Hyperliquid `run15`) and on the
Binance USD-M aggregated-trade tape. Two parts, one pre-registration
(`tradingV3-private/research/20261012T060000Z-execution-r1/PREREGISTRATION.md`, frozen
2026-10-12T06:18:05Z, sha256 `a8feedc4…`):

- **Part A, execution primitives** (`cli.py`): passive orders every 5 s at the touch, fill rules
  `through` (pessimistic) and `touch` (front of the queue), mark-outs at 1 / 10 / 60 / 300 s,
  round trips with a taker exit, the unconditional mid drift as the baseline.
- **Part B, tick-data scalping screen** (`screen.py`): 1-second bars and features
  (`tick.py`), six signal families S1–S6, maker entries E1/E2 with the through rule, exits
  X1–X4 (`scalp_engine.py`), regime-matched random controls, gates G1–G8, selection, one
  validation pass, budgets.

The package lives outside the coverage-gated `app` package; its tests are
`python-orchestrator/tests/test_research_exec*.py`. It needs numpy; `orjson` speeds up the
extraction when installed and is optional.

## Inputs

| Input | Where | Notes |
|---|---|---|
| Captures | `tradingV3-private/paper-market-data/<dataset>/{manifest.json,events.ndjson}` | never modified; `extract` re-hashes the events file against the manifest |
| Binance tape | `tradingV3-private/research/20261012T060000Z-tape-binance-r1/npz/<SYMBOL>/<SYMBOL>-<date>.npz` | daily `aggTrades` archives, sha256-checked at download, converted to `ts_us/price/size/taker_buy` |
| Binance instruments | `tradingV3-private/research/20261009T060000Z-source-complements-r1/instrument-assumptions.json` | tick, step, minimums; mid-caps in `…/20261012T060000Z-execution-r1/instrument-assumptions-midcap.json`, the union in `instrument-assumptions-r8.json` |
| Binance depth (r8) | `…/20261013T061500Z-tape-binance-r8/depth/<SYMBOL>/<SYMBOL>-<date>.npz` | daily `bookDepth` archives (one snapshot every 30 s on average: cumulative notional within ±1 … 5 % of the mid), sha256-checked, `ts_s / bid / ask` (n × 5) |
| Binance top of book (r9) | `…/20261013T063500Z-tape-binance-r9/book/<SYMBOL>/<SYMBOL>-<date>.npz` | daily `bookTicker` archives (every best bid/ask update with quantities, 2023-05-16 → 2024-03-30), reduced to one record per second: `ts_s / bid / ask / bid_qty / ask_qty / updates / qi_mean` |

Acquisition of any of the three archives (`scripts/research_exec/acquire.py`): every zip is
downloaded, checked against its published sha256 (fail-closed), converted and deleted; a
`manifest-<kind>.json` records hashes, row counts and diagnostics; files already in the manifest
are skipped. The bookTicker day of a major is over a gigabyte of text and is streamed.

```bash
python -m scripts.research_exec.acquire aggtrades  --out <dir> --symbols BTCUSDT,ETHUSDT --dates 2025-01-13..2025-01-17 --workers 3
python -m scripts.research_exec.acquire bookdepth  --out <dir> --symbols BTCUSDT --dates 2025-02-10..2025-02-14,2025-03-10..2025-03-14
python -m scripts.research_exec.acquire bookticker --out <dir> --symbols ATOMUSDT --dates 2023-07-10..2023-07-14 --workers 2
```
| Fees | maker 2 bp, taker 5 bp (`config/trading/runtime/exchange/*.yaml`) | the screen also reports fees × 1.5 |

## Commands

Run from `python-orchestrator/` with the research runtime
(`tradingV3-private/research/20261012T003000Z-explore-v2-runtime/bin/python`). `X` is the
study's artifact directory.

```bash
# Part A
python -m scripts.research_exec.cli extract --dataset <capture dir> --out $X/okx.npz --receipt $X/okx.receipt.json
python -m scripts.research_exec.cli simulate --extract $X/okx.npz --out $X/part-a/okx.primary.orders.npz \
    --summary $X/part-a/okx.primary.summary.json            # --rule touch | --latency-ms 0 | --ttl-s 300 …
python -m scripts.research_exec.cli report --summaries $X/part-a/*.summary.json --receipts $X/*.receipt.json --out $X/part-a/report.md

# Part B — captures (first 12 h training, last 12 h validation)
python -m scripts.research_exec.screen screen --source captures --phase training --extract-dir $X --out $X/part-b/captures --workers 3
python -m scripts.research_exec.screen select --screen $X/part-b/captures/screen-training.json --out $X/part-b/captures/validation-list.json
python -m scripts.research_exec.screen screen --source captures --phase validation --extract-dir $X --out $X/part-b/captures \
    --configs-file $X/part-b/captures/validation-list.txt
python -m scripts.research_exec.screen verdict --training …/screen-training.json --validation …/screen-validation.json --out …/verdict.json
python -m scripts.research_exec.screen budgets --screen …/screen-validation.json --configs …/validation-list.txt --out …/budgets.json

# Part B — Binance tape (March + July 2025 training, November 2025 validation)
python -m scripts.research_exec.screen screen --source binance --phase training --tape-dir <tape dir> \
    --instruments <instrument-assumptions.json> --out $X/part-b/binance --workers 3

# r2 protocol (PREREGISTRATION-r2.md: entry E1, exits Y1-Y4 = 15-60 min holds, its own gates),
# then the December 2025 holdout, read once for the validated configurations only
python -m scripts.research_exec.screen screen --source binance --phase training --protocol r2 --tape-dir <tape dir> \
    --instruments <instrument-assumptions.json> --out $X/part-b/binance-r2 --workers 3
python -m scripts.research_exec.screen screen --source binance --phase holdout --protocol r2 --tape-dir <holdout tape dir> \
    --instruments <instrument-assumptions.json> --out $X/part-b/binance-r2 --configs-file $X/part-b/binance-r2/holdout-list.txt
python -m scripts.research_exec.screen verdict --protocol r2 --training …/screen-training.json --validation …/screen-holdout.json --out …/verdict-holdout.json
```

Chains used on 2026-10-12 (artifact directory): `run-part-a.sh`, `run-part-b-training.sh`
(captures), `run-part-b-validate.sh <source>`, `run-binance-chain2.sh` (r2, then r1 training
and validation), `run-r2.sh`, `run-r3.sh`, `run-r4.sh`, `run-r5.sh`, `run-r6.sh`, `run-r7.sh` (training → validation → holdout).
On 2026-10-13: `acquire-r8.sh` → `launch-r8.sh` (waits for the acquisition, checks the file counts, runs `run-r8.sh`:
training → select → validation → holdout), and `acquire-r9.sh` → `launch-r9.sh` (waits for the acquisition **and** the
end of r8 on the four shared CPUs, then `run-r9.sh`). r8 and r9 run from two worktrees so that a running screen's
code tree is never edited (`code_identity()` hashes the package at the end of each run).

Every document records the sha256 of each package file (`code`) and, when `--prereg` is given,
of the pre-registration. Outcomes per configuration are saved as `screen-<phase>-outcomes/*.npz`
(`TRADE_DTYPE` rows: series, side, decision second, fill and exit times, prices, exit reason,
gross / fees / net in bp, hold).

## Conventions worth knowing

- Clock: exchange timestamps, microseconds; decisions at the end of a second; orders live
  250 ms later; market exits carry the same latency.
- Through rule: a resting buy fills on the first print strictly below its price (a sell above);
  the touch rule (part A only) fills on a print at the price with the opposite taker side.
- A cancelled (crossing) order blocks new decisions for 5 s, an unfilled one until its TTL, a
  position until its exit; one position per series.
- Controls: random decision seconds matched to the trades' count per hour of the day, same
  policy; the gate compares the mean net bp with the control's 95th percentile and median.
- Binance quotes are proxies (last sell print = bid, last buy print = ask, forced one tick
  apart); book-imbalance rules (S3) do not run there.
- Memory: the captures fit in a few hundred MB; the Binance training set (20 series of five
  days) needs about 5 GB in the parent, shared with the forked workers; workers save their
  own outcomes and return only summary rows (the first pooled run held every configuration's
  trades in the parent and would have exhausted the host).
- Protocols: `--protocol r1` (default), `r2`, `r3`, `r4`, `r5` or `r6` selects the entries, exits, rules, sides, gates,
  validation thresholds and training windows of the corresponding pre-registration
  (`PREREGISTRATION.md`, `-r2.md`, `-r3.md`); `--phase holdout` reads the December window from
  the holdout tape directory given as `--tape-dir`. r3 adds the S7 band-reversion family
  (EMA 300 / 900 s), the exits Z1–Z4 (60–120 min holds, passive exits with a 5-minute TTL) and
  the May and September 2025 training windows; r4 adds two-sided configurations
  (`…|both|…`: the long and the short streams run independently on each series and their
  trades are pooled); r5 runs the 2 σ two-sided configuration on nine training windows in a
  memory-light, series-by-series mode (each worker loads one series, replays every
  configuration on it and frees it; the pooled and by-series drivers give identical rows);
  r6 carries its own symbol list (ten mid-cap perpetuals, instrument file from the exchange
  metadata) and the full catalogue plus the two-sided band rules; r7 reuses `--protocol r6`
  with an explicit `--configs-file` for an out-of-sample validation and holdout; **r8** loads the
  bookDepth snapshots next to the prints (`depth: True`), adds the depth features `di_1`, `di_2`,
  `ddi_1`, `ddi_2` (120 s staleness, 300 s reference) and the families Q1–Q4, and carries its own
  phases (`january` validation, `december-2024` holdout); **r9** replaces the print proxies by the
  per-second bookTicker records (`book: True`, quotes with displayed quantities), adds the
  features `qi`, `mpo`, `dmp_5`, `dmp_30` and the families M1–M3, the entry **E3** (queue rule: the
  order fills when the prints at its price on the opposite taker side since activation exhaust
  the displayed quantity of the decision second plus its own 250 USDT, or when the price trades
  through) and its 2023-24 windows (`w2307` … `w2403`). A rule id is parsed token by token
  (`parse_rule`: `W` window, `B` depth band, `T` threshold, `K` sigma multiple, `D` delta, `Q`
  queue threshold, `N` run length, flags `F`, `C`, bare `B`); a rule whose features are absent
  (S3 without book sizes, Q without depth) is skipped on that series.

## Outputs

| File | Content |
|---|---|
| `okx.receipt.json`, `hl.receipt.json` | counts per channel and origin, latency percentiles, spreads, quote gaps, instruments |
| `part-a/<venue>.<run>.summary.json` | fill rates, mark-outs, lower bounds, sessions, verdict |
| `part-b/<source>/screen-<phase>.json` | one row per configuration: trades, mean/median net bp, PF, LB90, control distribution, gates |
| `part-b/<source>/validation-list.json` | selected configurations (≤ 3 per family) |
| `part-b/<source>/verdict.json` | validation checks per configuration and the outcome |
| `part-b/<source>/budgets.json` | notional, minimums, fees and projected net per day by budget |

Reports: `docs/handbook/reports/research-execution-tick-2026-10-12.md` (A, r1–r5),
`research-midcap-tick-2026-10-13.md` (r6, r7), `research-book-families-2026-10-13.md` (r8, r9).
