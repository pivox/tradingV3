# Backtesting OKX / Hyperliquid

Repeatable loop: edit a profile config, replay one cell on a frozen 24h capture, read the
certified trade export. Same engine as the #132 certification campaign, one cell at a time,
on a throw-away database.

## The command

```bash
scripts/backtest-profile.sh \
  --venue=hyperliquid \
  --mode-id=day_trading --mode-version=1.1.0 \
  --setup-id=day_trading.trend_continuation.long --setup-version=1.1.0 --side=long
```

`--venue=okx` replays the OKX capture. Both datasets live outside Git under
`tradingV3-private/paper-market-data/` and are read-only; the script refuses anything whose
manifest is not `complete`.

Options: `--configuration=/abs/paper-configuration.json` (Paper configuration snapshot, default
`{"strategy":{}}`), `--dataset=/abs/dir` (another complete capture), `--out=/abs/dir`,
`--port=5460` (first free port from there), `--keep-db` (keep the Postgres container for SQL
digging), `--refresh-receipt` (force the full dataset verification again).

Run two cells at once by starting the script twice: each run gets its own container, port,
run ID and result directory. Three in parallel is the campaign's proven ceiling on this host.

## Which engine, and why

| Path | What it is | Use it for |
|---|---|---|
| `app:paper-market:replay` (this script) | Full Paper pipeline on recorded book + trades: MTF validation, canonical setup rules, Fake exchange fills, costs, PnL, `position_trade_analysis_v2` | **Iterating on a profile** — the only path that ends in a trade list and the certification KPIs |
| `app:paper-market:certification-campaign` | Same replay, all 6 cells of a scope, state file, receipts, resume | The official gate once a profile is settled (no overrides, no tuning) |
| `app:backtest:rules:evaluate`, `indicators:project`, `funding:settle`, `partial-fill-cost:settle` | JSON-on-stdin authorities called by the Python Backtrader runtime (`python-orchestrator/app/backtesting`, #191) | Not a backtest by themselves: they evaluate one rule / project one indicator window / settle one cost. The Python ledger, metrics and reports are still out of scope there |

The campaign cannot run a single cell (`PaperCertificationMatrixBuilder` demands the complete
mode and setup matrix), hence the per-cell wrapper.

## What to edit

Strategy behaviour is resolved from the config layers of the tree you run from, in this order:
`base -> mode -> setup -> exchange -> mode_exchange -> environment`, all under
`trading-app/config/trading/`:

| Layer | Files |
|---|---|
| Mode contract | `mode_contract/<mode_id>/<version>.yaml` |
| Setup contract (conditions, side) | `setup_contract/<setup_id>/<version>.yaml` |
| Runtime per mode and venue | `runtime/mode_exchange/<mode_id>.<version>.<okx\|hyperliquid\|fake>.yaml`, `runtime/exchange/*.yaml`, `runtime/base.yaml` |
| Condition catalog | `condition_catalog/<version>.yaml` |

The legacy profiles (`validations.<mode>.yaml`, `trade_entry.<mode>.yaml`, `scalper*`,
`regular`) are `reference_only` for the modern cells and do not feed this replay.

Every edit changes `config_hash` (printed in `summary.txt`); two runs with the same hash
replay byte-identically, so a diff of two `export.csv` is a diff of the config change only.
Bumping a contract version (new `<version>.yaml`) is the clean way to keep the baseline
reproducible; editing in place is fine while exploring.

## Reading the results

`--out` directory (default `tradingV3-private/backtests/<utc>-<venue>-<setup>-<side>/`):

| File | Content |
|---|---|
| `summary.txt` | run ID, cell, `config_hash`, `condition_catalog_hash`, `trades`, wall clock |
| `export.csv` | the certification export (`bad-trades-baseline-v2.sql` over `position_trade_analysis_v2`): one row per closed trade with lineage, costs, PnL, R, MFE/MAE. Zero rows = the setup never traded |
| `export.md` / `export.json` | `bad_trades_baseline.py` report (per-cell KPIs; cells under 50 trades are excluded from KPIs by design) |
| `replay.out` | completion proof: `event_count`, `journal_checksum`, `fake_state_sha256`, timings |
| `runtime-check.out` | readiness JSON (`ready`, `baseline_eligible`, hashes) |
| `table-rows.txt` | row counts per table after the replay — not an authority, just to see how far the pipeline got (decisions, orders, positions) when `trades=0` |

Only `export.csv` counts as a trade count. Nothing in the replay logs does.

## Time budget

Measured on this host (one cell, nothing else running):

| Step | Hyperliquid (1.49M events, 1.2 GB) | OKX (4.41M events) |
|---|---|---|
| Container + migrations | seconds | seconds |
| Dataset receipt (once per dataset, cached in `tradingV3-private/backtest-receipts/`) | see smoke timing in the PR / session notes | idem |
| Replay | ~1h in the campaign (3 cells in parallel) | ~2h10 in the campaign (3 cells in parallel) |

The receipt is bound to the dataset bytes and to the replay code under
`src/Trading/Paper/{Dataset,MarketData,Hyperliquid,Okx}`; config edits keep it valid, code edits
there do not (the script reissues it automatically when the readiness check rejects it).

There is no time-window option on the replay: a cell always consumes the whole dataset. To
iterate faster, the next tooling step would be a bounded replay (`--until-event` or an
exchange-time upper bound), or shorter captures dedicated to exploration.

## Tuning here vs. certification

- **Here**: change any config layer, re-run, compare. That is the purpose of this loop.
- **Certification campaign** (`app:paper-market:certification-campaign`,
  `config/trading/paper_certification/first-baseline-v1.json`): the matrix, the 50 trades per
  cell bar and the absence of overrides are fixed. Loosening a spec there to pass is tuning the
  exam, not the strategy. Settle the profile with this loop first, then run the campaign on the
  committed configs.

Safety: the replay executes only against the Fake exchange on the recorded data.
`PAPER_EXECUTION_ENABLED=1` is set by the script for the two Paper commands only (exactly what the
campaign executor does); `OKX_LIVE_ENABLED`, `DEMO_TRADING_ENABLED`, `OKX_DEMO_TRADING_ENABLED`
are untouched and stay `0`. No exchange connection is opened.
