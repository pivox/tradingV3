# Indicator snapshot market-data venue migration design

## Context

`position_trade_analysis_v2` joins `indicator_snapshots` on the complete Paper
market identity: execution exchange, market type, public market-data venue,
symbol and timeframe. The view contract and its PostgreSQL fixtures therefore
use `indicator_snapshots.market_data_venue`, but the real migration chain and
the Doctrine entity never created that column. A fresh PostgreSQL database
fails at `Version20260811120000` before Paper runtime readiness can be checked.

## Decision

Add a corrective migration between the original Paper venue migration and the
first analysis view that consumes the field. The column is nullable for legacy
BitMart snapshots, constrained to `okx` or `hyperliquid` when present, and
indexed with the other lookup dimensions used by the analysis view.

Map the same nullable field on `IndicatorSnapshot` with the established strict
normalization contract. Existing producers continue to write `NULL`; no venue
is inferred from the execution exchange. This PR does not activate a new Paper
projection path.

## Rejected alternatives

- Removing the venue predicate would allow cross-venue evidence to be selected.
- Matching the venue against `indicator_snapshots.exchange` would conflate the
  Fake execution venue with the public market-data source.
- Editing only the already-published migration would not repair databases that
  already recorded it as executed.

## Verification

- PostgreSQL migration up/down contract, constraint and index test.
- Doctrine entity mapping and value normalization test.
- Full migration chain on an empty dedicated PostgreSQL database.
- Existing position analysis view suite.
