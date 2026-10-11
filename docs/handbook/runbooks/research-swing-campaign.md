# Swing canonical campaign — runbook

Campaign `swing-2026-10-11` tests one frozen candidate, `B30_2s × S3.T5.H14d`:

- contracts: `swing@1.0.0`, `swing.breakout.long` and `swing.breakout.short`;
- one active plan per pair, at most ten plans;
- training 2023–2024, validation 2025, both cost profiles;
- the 2026 holdout runs once, only if the selection is frozen as `selected`.

Decision and plan: `docs/superpowers/plans/2026-10-11-swing-canonical-campaign.md`. Every output lives under `../tradingV3-private/research/`. No network, application database, secret or order is involved.

## Inputs

The inputs are the same as for the day_trading campaign:

| Input | Path under `tradingV3-private/research/` |
|---|---|
| Candles and funding (Binance USD-M 1m, 2022-11-01 → 2026-10-09T06:00Z) | `20261009T060000Z-10pairs-20221101` |
| Funding supplement, instrument metadata | `20261009T060000Z-source-complements-r1` (`instrument-assumptions.json`) |
| Cost profiles (baseline, adverse) | `20261009T060000Z-campaign-assumptions-r1/cost-assumptions.json` |
| Python runtime (pydantic) | `20261009T060000Z-recovery-runtime-r1/bin/python` |

The swing exploration (`20261009T060000Z-swing-r2`) uses the same cost and instrument assumptions. Their canonical hashes are equal; only the formatting differs.

## 1. Frozen commit X

1. Check out X as a dedicated worktree outside the repository, for example `tradingV3-private/research/<stamp>-swing-frozen/`.
2. Copy `trading-app/vendor` into it.
3. Never change it afterwards. The orchestration snapshot, the B1 reports and the authority all bind its bytes.

## 2. Training B1

There are four roots: both sides × two symbol groups. Run them from `<frozen>/python-orchestrator`, at most two at a time (the host has four shared CPUs):

```bash
$PY -m app.backtesting.research.signals --dataset-root $DATASET --app-dir <frozen>/trading-app \
  --output-root $R/<stamp>-swing-training-$PROFILE-g$N --symbols $GROUP \
  --start 2022-11-01T00:00:00Z --score-start 2023-01-01T00:00:00Z --end 2025-01-01T00:00:00Z \
  --profile $PROFILE
```

- `PROFILE`: `swing.long`, `swing.short`.
- Group 1: `BTCUSDT,ETHUSDT,BNBUSDT,XRPUSDT,ADAUSDT`.
- Group 2: `DOGEUSDT,SOLUSDT,LTCUSDT,LINKUSDT,AVAXUSDT`.

## 3. Orchestration

The orchestration launches validation B1, freezes the protocol, runs the units and freezes the selection:

```bash
$PY -m app.backtesting.research.orchestration --family swing \
  --dataset-root $DATASET --app-dir <frozen>/trading-app --b1-cwd <frozen>/python-orchestrator --python $PY \
  --instrument-path $SOURCES/instrument-assumptions.json --cost-path $ASSUMPTIONS/cost-assumptions.json \
  --funding-supplement-root $SOURCES \
  --registry-root $R/<stamp>-swing-registry --report-root $R/<stamp>-swing-reports \
  --evidence-root $R/<stamp>-swing-orchestration \
  --training-roots <long g1> <long g2> <short g1> <short g2> \
  --validation-roots <four fresh paths, same order> --timeout 604800
```

Run it detached (`setsid nohup … &`) so that it never depends on a terminal session.

- `<evidence>/terminal.json` ends either `selected` or `no_eligible_candidate`.
- With `no_eligible_candidate`, the campaign stops and the holdout stays closed.
- Compare every unit with the exploration:

```bash
$PY scripts/research_swing_compare.py --registry <registry> \
  --exploration $R/20261009T060000Z-swing-r2 --output <report>.json
```

## 4. Holdout pin (PR 7, only if `selected`)

The pin is applied to a checkout of X:

```bash
python scripts/research_swing_rehearsal.py pin <checkout>/python-orchestrator <X> <frozen>/python-orchestrator
```

The pin does three things:

- writes `frozen_verifier_inventory.swing.json` as canonical JSON (sorted, compact, one trailing newline; the authority refuses anything else);
- sets `SWING_FROZEN_COMMIT = X`;
- adds the inventory to `holdout_authority._new_code_inventory` and `campaign._runner_code`.

The runner checkout for the holdout is X + pin.

## 5. Owner only: install the authority, run the holdout once

From the runner checkout's `python-orchestrator`:

```bash
$PY -c "from pathlib import Path; from app.backtesting.research import holdout_authority as h; \
  h.install_campaign_authority(Path('<evidence>/attempt.json'), Path('<evidence>/input-code-identity.json'), \
  campaign_id=h.SWING_CAMPAIGN_ID)"
$PY -m app.backtesting.research.holdout --campaign swing-2026-10-11 --output-root $R/<stamp>-swing-holdout
$PY -m app.backtesting.research.holdout --campaign swing-2026-10-11 --verify-retained
```

The claim is single-use. A defect found after the claim is reported, not fixed and rerun.

## Rehearsal

`research_swing_rehearsal.py constants` builds a rehearsal checkout. The rehearsal then runs steps 1–5 on non-holdout slices under a temporary `HOME`:

- training 2024-07 → 2024-10;
- validation 2025-07 → 2025-10;
- "holdout" 2025-11.

See the [rehearsal report](../reports/research-swing-rehearsal-2026-10-11.md).
