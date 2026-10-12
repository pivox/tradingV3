# Research v2 screen — candidate selection from 1-minute history

Exploratory tooling in `python-orchestrator/scripts/research_v2/` (numpy, outside the
coverage-gated `app` package). It screens pre-registered setups on the ten-pair Binance dataset
(training 2023–2024, validation 2025) and never reads 2026: the loader cuts every candle and
funding event at `2026-01-01T00:00Z` (`dataset.CUT_MS`) and the cache loader refuses later rows.

Pre-registration, grid and outputs live under
`tradingV3-private/research/20261012T003000Z-explore-v2/` (`PREREGISTRATION.md`, frozen with a
content hash before any PnL computation). The canonical PHP path stays the authority for any
survivor: the screen reports canonical feasibility (plan net R ≥ 1.3, supported exit kinds) but
does not replace a campaign.

## Runtime

```bash
R=~/workspace/perso/tradingV3-private/research
PY=$R/20261012T003000Z-explore-v2-runtime/bin/python   # python3 -m venv + numpy==2.3.3
cd python-orchestrator
```

## Commands

```bash
# 1. Parse the archives into the cache (sha256 of every archive checked against the manifest).
$PY -m scripts.research_v2.cli prepare --dataset-root $R/20261009T060000Z-10pairs-20221101 \
    --cache-root $R/20261012T003000Z-explore-v2/cache

# 2. Write the configuration grid (counts per family and cadence).
$PY -m scripts.research_v2.cli grid --out $R/20261012T003000Z-explore-v2/grid.json

# 3. Training screen: every configuration, both cost profiles, 10 control seeds, budgets.
$PY -m scripts.research_v2.cli screen --cache-root $R/20261012T003000Z-explore-v2/cache \
    --cost-path $R/20261009T060000Z-campaign-assumptions-r1/cost-assumptions.json \
    --instrument-path $R/20261009T060000Z-source-complements-r1/instrument-assumptions.json \
    --phase training --workers 3 --seeds 10 --out $R/20261012T003000Z-explore-v2/screen-training.json

# 4. Validation (2025), once, on the selected configurations only.
$PY -m scripts.research_v2.cli screen … --phase validation --configs-file survivors.txt \
    --out $R/20261012T003000Z-explore-v2/screen-validation.json

# 5. Markdown summary of a screen document.
$PY -m scripts.research_v2.cli report --screen screen-training.json --out screen-training.md
```

A configuration id reads `setup|cadence|side|exit`, for example `A1.N20|4h|long|S3.T5`
(Donchian 20 breakout, 4h bars, long, stop 3 × ATR14, target 5 R). Exit ids: `T3`/`T5`/`T2`
fixed R targets, `TR3` Chandelier trailing stop of 3 ATR, `X` indicator exit at the mean.

## Outputs

- `screen-<phase>.json`: one row per configuration with the counters (signals, admitted,
  unfilled, unsized, filled), the baseline and adverse summaries (trades, win rate, net, mean net
  R, bootstrap 90 % lower bound, profit factor, realized drawdown, exposure, by year / pair /
  month / side / exit reason), the control distribution, the budget study (500 to 100,000
  USDT) and, in training, the gate reasons. The document records the sha256 of every package
  file, of the cost and instrument assumptions and of the cache receipt.
- `screen-<phase>-outcomes/*.npz`: the trade ledger of each configuration (`OUTCOME_DTYPE`).

## Conventions worth knowing

- Decision at a complete bar close; limit entry at the close resting one bar; stop checked from
  the fill candle, target from the next candle; stop wins ties and fills at the worse of the stop
  and the candle open; horizon from the decision close; simulations clipped at the phase end.
- Reference sizing 250 USDT notional (quantity rounded down to the step). Net PnL is linear in
  the quantity, which is what the budget study relies on.
- With ten pairs and one plan per pair, the ten-plan cap never binds, so pairs are replayed
  independently; a worker pool over configurations shares the loaded pairs through `fork`.
- Tests: `$PY -m pytest --noconftest tests/test_research_v2_*.py` (the orchestrator conftest
  needs the full dev requirements; CI installs them, so the tests also run there).
