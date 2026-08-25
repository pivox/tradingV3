# Paper Market Replay

## Safety boundary

Paper replay always executes through the Fake exchange. `market_data_venue` records
the public-data origin and may be `okx` or `hyperliquid`; it never changes the
execution venue.

Public OKX and Hyperliquid capture/replay sources and the Paper execution
coordinator are wired. Keep `PAPER_EXECUTION_ENABLED=0` as the safe default;
enable it only in the dedicated Paper process connected to the allowlisted Paper
database. The execution graph contains no private client or real exchange adapter.
It has no credentials, exchange writes, strategy tuning or Bitmart scope.

## Dataset contract

Local datasets live under:

```text
trading-app/var/paper-market-data/<dataset_id>/
  manifest.json
  events.ndjson
  checkpoints/
```

`events.ndjson` is append-only. Each normalized event has a deterministic identity
derived from its schema version, public-data venue, normalized symbol, channel,
exchange timestamp, and sequence (or payload hash when no sequence exists).
Replaying the exact identity and payload is a no-op; the same identity with a
different payload is a conflict and fails closed.

A completed manifest records the event-file SHA-256, event count, exchange-time
range, channels, sequence gaps, and final event identity. Verify all of those facts
before replay. A changed, truncated, malformed, or incomplete dataset must not be
replayed.

Quality is explicit:

- `recorded_public_book_and_trades` is recorded public book/trade data.
- `public_historical_candles_and_trades` requires both a model name and model
  version in the manifest.
- `incomplete` captures are frozen for audit and are not replayable.

Checkpoints persist the last consumed event identity atomically. Resume starts after
that checkpoint in the deterministic replay order. The controlled replay clock moves
monotonically with the delivered event timestamp; wall-clock time must not alter a
replay result.

## Public mainnet capture

Prepare one private root outside Git, then run one independent process per
public-data venue. These commands have no execution dependency and do not need
`PAPER_EXECUTION_ENABLED`:

```bash
install -d -m 0700 /absolute/private/paper-market-data

PAPER_MARKET_ACQUISITION_ENABLED=1 \
PAPER_MARKET_DATA_ROOT=/absolute/private/paper-market-data \
php bin/console app:paper-market:public-capture \
  --venue=okx \
  --dataset-id=first-baseline-okx-20260823-mainnet \
  --duration-sec=86400

HYPERLIQUID_PAPER_PUBLIC_ACQUISITION_ENABLED=1 \
PAPER_MARKET_DATA_ROOT=/absolute/private/paper-market-data \
php bin/console app:paper-market:public-capture \
  --venue=hyperliquid \
  --dataset-id=first-baseline-hyperliquid-20260823-mainnet \
  --duration-sec=86400
```

The duration is explicit and bounded from 300 to 604800 seconds. BTC/ETH,
mainnet provenance, native instruments and required channels are fixed by the
venue contracts. OKX and Hyperliquid may run concurrently, but always as
separate processes so their event loops, reconnect budgets and failures remain
isolated.

A timer, `SIGINT` or `SIGTERM` requests a healthy stop. The source completes
only after its queues and pending acknowledgements are drained and continuity
is proven. A protocol, continuity, durability or abnormal-stop failure freezes
the dataset as `incomplete`; a terminal dataset is immutable. An abrupt process
loss may leave `recording`, in which case the exact same command resumes from
the durable recorder/source checkpoints.

New OKX captures warm up each BTC/ETH stream with exactly 1,000 confirmed,
contiguous one-hour candles before publishing its initial snapshot boundary.
This supplies the source window used to derive the canonical 250-candle 4h
context while retaining live books, trades, instrument metadata and funding in
the same dataset. The lower timeframes retain their 300-row public warmup. An
empty, non-progressing, conflicting or gapped hourly history fails closed.
Existing terminal datasets are immutable and are not retroactively upgraded;
create a new dataset ID to obtain this contract.

Successful output is redacted schema `paper-public-capture-result-v1` and ends
with `certification_status=not_evaluated`. A complete capture proves neither a
trade nor representative coverage. After both captures complete, pass their
absolute directories to the exact certification campaign below, then apply the
independent 50-certified-trades gate to every cell.

## Readiness check

Run the read-only check with the exact inputs that will be replayed:

```bash
PAPER_EXECUTION_ENABLED=1 php bin/console app:paper-market:runtime-check \
  --dataset=/absolute/private/dataset \
  --configuration=/absolute/private/configuration.json \
  --strategy-profile=regular \
  --run-id=run-20260820-001
```

The command verifies the completed public dataset and checksum, certifiable
network provenance, the private/redacted configuration snapshot, controlled
clock, the replay reader event bound, exact execution cell, existing dataset
binding and kill state, Paper database and Fake-only/write-disabled runtime.
For an existing cell, the persisted resume event must also exist at the exact
position and timestamp in the prepared dataset; validation does not advance the
controlled clock.
It does not register state, bind a dataset, write a checkpoint or consume an
event.

Success returns schema `paper-replay-readiness-v1` with `ready=true`. Read
`baseline_eligible` separately: the legacy profiles `regular`, `scalper` and
`scalper_micro` remain `reference_only`, so technical readiness does not make
their trades eligible for a modern baseline. Failure returns `ready=false` and
one stable `blocker`; paths and configuration contents are never output.

Modern cells use identity schema v2. Their exact identity contains network,
public-data venue, Paper configuration snapshot, canonical mode/setup IDs and
versions, side, canonical configuration hash, condition-catalog hash and run ID.
All modern fields are mandatory together; legacy v1 cells are never backfilled.
The canonical strategy/effect bridge is now wired. A modern identity that passes
the strict Effective Config resolver and coordinator readiness is marked
`baseline_eligible`; this only makes its closed trades candidates for the
independent lineage/cost/PnL and minimum-50 gates. Historical v2 cells persisted
as `reference_only` are never backfilled or counted.

After a successful check, execute the same tuple:

```bash
PAPER_EXECUTION_ENABLED=1 php bin/console app:paper-market:replay \
  --dataset=/absolute/private/dataset \
  --configuration=/absolute/private/configuration.json \
  --strategy-profile=regular \
  --run-id=run-20260820-001
```

The replay command uses the same preparation contract before any state write.

### Modern operator identity

To inspect a modern cell, omit `--strategy-profile` and provide the complete canonical
identity:

```bash
PAPER_EXECUTION_ENABLED=1 php bin/console app:paper-market:runtime-check \
  --dataset=/absolute/private/dataset \
  --configuration=/absolute/private/configuration.json \
  --mode-id=day_trading \
  --mode-version=1.1.0 \
  --setup-id=day_trading.trend_continuation.long \
  --setup-version=1.1.0 \
  --side=long \
  --run-id=run-20260820-modern-001
```

The dataset fixes the public venue and network environment; the command fixes
the capability to `paper`. Mixing `--strategy-profile` with modern options, omitting one
modern field or using a legacy alias fails closed. The check resolves the exact
Effective Config and emits only its canonical hashes and identity. A successful
modern check returns `ready=true` and `baseline_eligible=true`; the replay still
executes exclusively against Fake state. Identity/configuration failures use
stable redacted blocker codes and never expose an Effective Config filesystem
path.

## Exact certification campaign

Run the complete first-baseline matrix with one explicit dataset per public
scope. The configuration and state files must be private (`0600`); dataset,
configuration and state paths must be absolute and contain no symlink component.

```bash
PAPER_EXECUTION_ENABLED=1 php bin/console app:paper-market:certification-campaign \
  --spec="$PWD/config/trading/paper_certification/first-baseline-v1.json" \
  --configuration=/absolute/private/paper-configuration.json \
  --dataset=mainnet/okx=/absolute/private/okx-dataset \
  --dataset=mainnet/hyperliquid=/absolute/private/hyperliquid-dataset \
  --campaign-id=first-baseline-20260823 \
  --state=/absolute/private/first-baseline-20260823.state.json \
  --cell-timeout-sec=3600
```

The command derives all 12 cells from the canonical matrix and starts a fresh
PHP process for each readiness check and each replay. It stops at the first
failure. Re-run the byte-identical command to resume: deterministic run IDs
address the same database checkpoints, while every cell is checked and replayed
idempotently again. The state file is atomic audit evidence and cannot skip the
database authority; changing the matrix, configuration, manifest or events file
under the same campaign ID is rejected.

`status=completed` means only that every verified dataset reached its end in
Fake/Paper; `certification_status` remains `not_evaluated`. It is not a
certification claim and does not imply one trade, a
closed trade, complete costs/PnL or the minimum 50 trades per exact cell. Run the
#132 export gates separately after the campaign.

## Database and rollback rules

The only allowed Paper database is `trading_paper`. Tests may use names matching
`*_paper_test`; no other database name is valid for Paper operations.

To roll back a local replay, stop its local consumers and preserve the
dataset manifests and event files for audit. Recreate only the dedicated Paper
database, and only after explicit approval. Do not remove or alter a dataset as part
of rollback.
