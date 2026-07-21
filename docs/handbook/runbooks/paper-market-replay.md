# Paper Market Replay

## Safety boundary

Paper replay always executes through the Fake exchange. `market_data_venue` records
the public-data origin and may be `okx` or `hyperliquid`; it never changes the
execution venue.

PR 1 provides the dataset contract only. It does not provide an operator capture
or replay command. Commands from the approved design arrive with their owning
source or execution PR.

Keep `PAPER_EXECUTION_ENABLED=0` as the expected default until that wiring exists.
This foundation has no private APIs, credentials, exchange writes, strategy tuning,
or Bitmart scope.

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

## Database and rollback rules

The only allowed Paper database is `trading_paper`. Tests may use names matching
`*_paper_test`; no other database name is valid for Paper operations.

To roll back a future local replay, stop its local consumers and preserve the
dataset manifests and event files for audit. Recreate only the dedicated Paper
database, and only after explicit approval. Do not remove or alter a dataset as part
of rollback.
