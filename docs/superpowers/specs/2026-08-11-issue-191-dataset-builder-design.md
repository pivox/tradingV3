# Issue #191 — Deterministic Dataset Builder design

## Scope

This atomic lot builds and publishes a versioned candle dataset plus its quality
report. It does not read a PHP Paper manifest directly, fetch market data,
calculate indicators, adapt TradingCore, invoke Backtrader, simulate execution,
or make a modern mode runnable.

The input boundary is a `DatasetSourceIdentity` (`source`, source schema/build
version, `sha256:` checksum, network, venue, and market type) plus a materialized
sequence of normalized `CandleRecord` objects from a source that has already
been authenticated and verified by its owner. In particular, the Python builder does not duplicate
`PaperDatasetVerifier` or claim that raw `manifest.json` / `events.ndjson` files
are verified. A later source adapter may cross that boundary explicitly.

## Record contract

`CandleRecord` is frozen and rejects unknown fields. It carries:

```text
schema_version = backtest-candle.v1
source_record_id
source_network
market_data_venue
market_type
symbol
timeframe
open_at
close_at
available_at
open / high / low / close / volume
complete = true
```

The supported v1 timeframes are `1m`, `5m`, `15m`, `1h`, and `4h`. Timestamps
must be UTC-aware. A candle covers `[open_at, close_at)`, its duration must equal
its timeframe, and its `open_at` must lie on the UTC Unix-epoch grid for that
timeframe (`epoch_seconds mod timeframe_seconds = 0`). `available_at >= close_at`.
This last field is the data
availability boundary: a consumer evaluating instant `t` may see only records
whose `available_at <= t`. A mutable or incomplete candle is invalid.

Prices and volume are canonical decimal strings, never binary floats. The v1
grammar has no sign, exponent, leading zero, or insignificant trailing zero:
`0|[1-9][0-9]*(\.[0-9]*[1-9])?`. Prices must be positive, volume may be zero,
and `low <= open, close <= high`. Sources normalize into this exact form before
constructing the record; the builder never guesses a representation.

One build contains exactly one source network, market-data venue, and market
type. Symbols and timeframes may vary. The record identity is:

```text
market_data_venue + market_type + symbol + timeframe + open_at
```

`source_record_id` remains provenance and a final deterministic tie-breaker; it
does not permit two candles for one identity.

## Quality policy

`DatasetQualityReport` and its nested stream/missing-range records are frozen,
typed, deterministically ordered, and machine-readable. The report contains
input and accepted counts, coverage per `(venue, market_type, symbol,
timeframe)`, expected and observed counts, exact duplicates, conflicting
duplicates, missing ranges, and stable quality flags.

Expected points are derived only between the first observed `open_at` and last
observed `close_at` of each stream. The builder does not invent coverage before
or after those bounds. A missing range records its inclusive first missing open,
exclusive end, timeframe, and missing-bar count.

Both byte-identical and conflicting duplicate identities are defects. Any
duplicate, gap, overlap, invalid chronology, mixed source identity, or empty
input makes the report ineligible and rejects the build. The typed rejection
exposes the report for diagnosis, but produces no manifest and cannot be
published. There is no silent row deletion, winner selection, fill-forward, or
optimistic default.

Duplicate counts preserve both defect classes within one identity group. The
exact count is the sum of repeated records beyond the first occurrence of each
canonical record variant. The conflicting count is the number of distinct
canonical variants beyond the first. Thus `A,A,B` reports one exact and one
conflicting duplicate, independently of input order.

The analyzer keeps defensive `stream_overlap` and
`invalid_stream_chronology` flags for future normalized-record implementations
and source adapters. They are unreachable through the current strict
`CandleRecord` boundary because UTC grid alignment and exact duration reject
such records earlier.

## Canonical artifacts and checksum graph

For an eligible build, records are sorted by:

```text
market_data_venue
market_type
symbol
timeframe duration in seconds
open_at
source_record_id
```

The builder emits three exact UTF-8 artifacts with one trailing newline:

```text
candles.ndjson
quality-report.json
manifest.json
```

JSON uses sorted keys, compact separators, explicit UTC timestamps, canonical
decimal strings, and no wall-clock-generated value. `candles.ndjson` is hashed
first. The report is then serialized and hashed. The manifest binds the record
schema/build versions, source identity and source checksum, derived coverage,
record count, candle checksum, and report checksum. Coverage includes one
canonical entry per exact `(venue, market_type, symbol, timeframe)` stream with
its first open, last close and observed record count. Aggregate symbols,
timeframes, bounds and count are derived from those entries. A run may request
only combinations present in this catalog, and its half-open execution period
`[period_start, period_end)` must fit every requested stream's
`[first_open_at, last_close_at)` coverage. Each stream starts on its UTC
timeframe grid and, because eligible inputs are gapless, must satisfy exactly
`last_close_at - first_open_at = record_count * timeframe_duration`. The dataset checksum is the SHA-256
of the canonical manifest core plus the two already computed artifact checksums,
avoiding a self-referential hash.

Input order, process hash seed, locale, working directory, and current time must
not change any artifact byte or checksum. `DatasetDescriptor` is derived from
the manifest facts; callers do not provide its counts, bounds, quality fields,
or checksum.

## Publication

Publication writes a private sibling staging directory, flushes files and the
directory, and atomically renames it to `<root>/<dataset_id>`. It never mutates
a published dataset. If the target already contains the three exact verified
bytes, publication is an idempotent no-op. Any missing, changed, extra, or
symlinked artifact at that identity is a stable conflict and remains untouched.
A root path is traversed from `/` through retained no-follow directory fds. A
missing component is created as a random private `.dataset-root-*` directory,
opened and flushed, then atomically renamed without replacement and followed by
a parent-directory flush. Every retained path identity is checked before
success. Exact-target verification retains all three artifact fds until a final
collective name/inode/type/mode/link-count/size/mtime/ctime and target-directory
stability pass around its second content read. This detects in-place mutations
during validation. The target directory itself is snapshotted before that pass
and rechecked afterward for identity, type, mode, links, size, mtime and ctime,
detecting late entry swaps even when the original entry is restored. It is
deliberately a finite snapshot: portable POSIX calls
cannot prevent a hostile same-UID writer from mutating bytes after the final
observation, so storage ownership or external coordination must exclude such
writes for post-return immutability.
Every `ALREADY_PUBLISHED` path flushes the dataset root after that exact
observation and before its final anchored-path check and return. A root path
component won by a concurrent creator is likewise adopted only after flushing
its parent directory.
A failed build or failed staging write leaves no published target.
Failure cleanup removes no filesystem entry, not even fd-relative contents: it
only closes retained descriptors. It preserves an empty, partial or complete
private staging directory for a janitor operating outside concurrent
publication. Private `.dataset-root-*` directories left by a losing concurrent
creator or an interrupted root build use the same janitor policy and are never
removed by the publisher. A `PUBLISHED` result leaves no dataset staging
directory because the
atomic rename turns that directory into the immutable target. A concurrent
loser returning `ALREADY_PUBLISHED` preserves its complete staging directory
for that janitor.

The implementation uses the existing Pydantic dependency for contracts and
only the Python standard library for Decimal validation, ordering, canonical
JSON, hashing, and filesystem publication. It adds no dataframe, Parquet,
Backtrader, database, or network dependency.

## Modern contract boundary

Dataset APIs contain no profile, mode, setup, or alias field. They never map
`regular`, `scalper`, or `scalper_micro` to modern identities. The legacy
`Profile` enum currently present in the first #191 contracts is not expanded or
adapted in this lot because dataset construction is strategy-independent.

PR3 must replace that runtime boundary with exact `mode_id`, `mode_version`,
`setup_id`, `setup_version`, `side`, and the immutable #133/#303 snapshot. Until
then, no result from this builder makes `day_trading`, `scalping`, or
`micro_scalping` runnable or certifiable. No mainnet execution path is added.

## Verification

Unit and golden-byte tests prove strict record validation, gap and duplicate
rejection, availability filtering at the exact time boundary, canonical output
under input permutations, checksum cross-verification, atomic/idempotent
publication, conflict preservation, and absence of a mode/profile field from
the dataset API. The full Python orchestrator suite remains the regression gate.
