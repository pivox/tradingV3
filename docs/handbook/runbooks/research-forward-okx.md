# Runbook — forward test on OKX public data (`scripts/research_exec/forward_okx.py`, fwd-okx-r1)

A forward test replays a frozen rule on data that did not exist when the rule was chosen. This
runbook covers the OKX forward capture and its replay with the research screen. Public data
only: no credential, no private endpoint, no order; the OKX demo flags are not involved.

Pre-registration: `tradingV3-private/research/20261013T100000Z-forward-okx-r1/PREREGISTRATION-forward-okx-r1.md`
(frozen 2026-10-13, hash in the sibling `.sha256`). Report:
[forward test OKX 2026-10](../reports/research-forward-okx-2026-10.md).

## What is captured

| Stream | Source | Reduction | File per symbol-day |
|---|---|---|---|
| Prints | public WebSocket `trades` (one message per taker order, aggregated fills, taker side) | sizes to base units (`ctVal`), sorted by (time, id), unique ids | `tape/npz/<SYMBOL>/<SYMBOL>-<date>.npz` (`ts_us`, `price`, `size`, `taker_buy`, `agg_trade_id`) |
| Top of book | public WebSocket `bbo-tbt` (every best bid/ask change with sizes) | one record per second: last update, count, mean queue imbalance | `tape/book/<SYMBOL>/<SYMBOL>-<date>.npz` (the r9 bookTicker layout) |
| Depth | REST `/api/v5/market/books-full` (5,000 levels per side) every 30 s | cumulative notional within ±1 … 5 % of the mid; coverage recorded | `tape/depth/<SYMBOL>/<SYMBOL>-<date>.npz` (the r8 bookDepth layout plus `coverage_*_pct`) |
| Instruments | REST `/api/v5/public/instruments?instType=SWAP` once | r8 instrument layout, sizes in base units | `tape/instrument-assumptions-okx.json` |

Symbols are the Binance-style names of the research tapes (`BTCUSDT` ↔ `BTC-USDT-SWAP`), so the
screen's tape loader reads the forward tape unchanged. Exchange timestamps throughout. The day
in progress is checkpointed every 10 minutes (`partial: true` in `tape/manifest-forward.json`) and
finalized 90 s after midnight UTC with the sha256 of each file. Disconnects and REST failures are
recorded in the manifest; after a reconnect the last 500 prints of each symbol are merged over
REST.

Known limit: the REST book is truncated at 5,000 levels per side. On 2026-10-13 that reaches
±0.8 % on BTC and ±2.1 % on ETH (full coverage on the other eighteen pairs). The ±1 % band of
BTC is therefore the share within about 0.8 %. The per-file minimum coverage is in the manifest.

## Commands

Run from `python-orchestrator/` with a runtime that has numpy, websockets and orjson
(`tradingV3-private/research/20261013T100000Z-forward-okx-runtime/bin/python`).

```bash
# Capture (runs until killed; --duration-s for a bounded run; --symbols to restrict)
python -m scripts.research_exec.forward_okx capture --out <tape dir> --depth-every 30 --checkpoint-every 600

# Replay one window of the fwd-okx-r1 protocol (phase: training = W0 shakedown, validation = W1, holdout = W2)
python -m scripts.research_exec.screen screen --source binance --protocol fwd-okx-r1 --phase validation \
    --tape-dir <tape dir> --instruments <tape dir>/instrument-assumptions-okx.json --out <out dir> --workers 2 --prereg <PREREGISTRATION>
python -m scripts.research_exec.screen verdict --protocol fwd-okx-r1 --training <out>/screen-training.json \
    --validation <out>/screen-validation.json --out <out>/verdict-validation.json
python -m scripts.research_exec.screen budgets --screen <out>/screen-validation.json --out <out>/budgets-validation.json
```

The research directory holds two detached loops: `run-capture.sh` (restarts the capture on exit;
`touch STOP` ends it) and `evaluate.sh` (every hour, replays a window once all its symbol-days
are final; stops after W2; `touch STOP-EVAL` ends it). Logs under `logs/`.

## Health checks

```bash
X=tradingV3-private/research/20261013T100000Z-forward-okx-r1
tail -3 $X/logs/run-capture.log; tail -2 $X/logs/capture-*.log | tail -3        # process alive, checkpoints advancing
python3 -c 'import json; m=json.load(open("'$X'/tape/manifest-forward.json")); print(len(m["files"]), "files;", len(m["disconnects"]), "disconnects;", len(m["rest_failures"]), "REST failures")'
du -sh $X/tape                                                                      # about 50 MB per day
```

A day whose manifest entry stays `partial: true` after 00:02 UTC means the capture was down at
the rollover; the loader then reads the checkpointed part and reports the missing seconds.

## Protocol fwd-okx-r1 (`screen.py`)

One configuration, `Q3-W900-K1.5-T0.1|long|E1|Z3`, on the twenty r8 pairs, with real quotes
(`book`) and depth (`depth`):

| Window | Dates (UTC) | Phase | Role |
|---|---|---|---|
| W0 | 2026-10-14..16 | training | pipeline shakedown; PnL reported, counts for nothing |
| W1 | 2026-10-19..23 | validation | judged once with the r8 validation checks |
| W2 | 2026-10-26..30 | holdout | judged once with the same checks |

Checks: trades ≥ 100, mean net > 0, PF ≥ 1.05, mean net above the regime-matched control median,
≥ 50 % of the twenty pairs positive. A candidate passes W1 and W2. Fees 2 / 5 bp; the screen
also reports fees × 1.5.
