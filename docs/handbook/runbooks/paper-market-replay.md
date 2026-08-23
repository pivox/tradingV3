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

## Readiness check

Run the read-only check with the exact inputs that will be replayed:

```bash
PAPER_EXECUTION_ENABLED=1 php bin/console app:paper-market:runtime-check \
  --dataset=/absolute/private/dataset \
  --configuration=/absolute/private/configuration.json \
  --profile=regular \
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
  --profile=regular \
  --run-id=run-20260820-001
```

The replay command uses the same preparation contract before any state write.

### Modern operator identity

To inspect a modern cell, omit `--profile` and provide the complete canonical
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
the capability to `paper`. Mixing `--profile` with modern options, omitting one
modern field or using a legacy alias fails closed. The check resolves the exact
Effective Config and emits only its canonical hashes and identity. A successful
modern check returns `ready=true` and `baseline_eligible=true`; the replay still
executes exclusively against Fake state. Identity/configuration failures use
stable redacted blocker codes and never expose an Effective Config filesystem
path.

## Database and rollback rules

The only allowed Paper database is `trading_paper`. Tests may use names matching
`*_paper_test`; no other database name is valid for Paper operations.

To roll back a local replay, stop its local consumers and preserve the
dataset manifests and event files for audit. Recreate only the dedicated Paper
database, and only after explicit approval. Do not remove or alter a dataset as part
of rollback.
