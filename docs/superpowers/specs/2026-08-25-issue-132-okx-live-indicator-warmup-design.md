# #132 OKX Live Indicator Warmup Design

Date: 2026-08-25

Parent: #132

## Goal

Make every new OKX public live Paper capture contain the 1,000 contiguous
confirmed one-hour candles required to derive the canonical 250-candle 4h
indicator window, while retaining live books, trades, metadata and funding in
the same certifiable dataset.

## Scope and Choice

The existing live source retrieves one page of at most 300 current candles for
each native interval. This is sufficient for 1m, 5m and 15m indicator windows,
but leaves day trading permanently unavailable because 4h is derived from
1,000 one-hour candles.

The selected change paginates only the initial `1H` warmup through the existing
credential-free `historyCandles` endpoint until 1,000 confirmed candles are
proven. The 1m, 5m and 15m warmups remain one page. Historical and live events
continue into the same recorder and manifest.

A separate historical dataset was rejected because it cannot supply live book,
instrument and funding evidence to the same campaign cell, and collecting an
entire 1,000-hour public-trade history would exceed prudent bounds. Adding a
native 4h channel was rejected because it would change the canonical replay
contract and still leave interval-retention composition unresolved elsewhere.

## Pagination Contract

For each OKX instrument, the source first requests the existing 300 current
`1H` rows. It durably pins the newest confirmed timestamp observed on that
first page, then requests older pages of at most 300 rows with the oldest
returned timestamp as the exclusive cursor. The source selects the newest
1,000-row base whose first opening timestamp is aligned to the UTC four-hour
grid and paginates until that base is complete.

Every page must:

- contain 1 through 300 structurally valid rows;
- be strictly reverse chronological before sorting;
- precede its request cursor and make the oldest timestamp progress;
- agree byte-for-byte on duplicate natural identities;
- contain a contiguous confirmed one-hour grid after deduplication.

The source emits the aligned 1,000-row base followed by the zero to three newer
confirmed rows already observed on the first page. Preserving that suffix keeps
the recorded 1H stream contiguous when the websocket takes over. It fails
closed with a stable public-response integrity reason on an empty page,
non-progress, conflicting duplicate, malformed row, gap or exhaustion of a
fixed four-history-page budget. No partial snapshot boundary is emitted.

When a strategy requests derived 4h context, the canonical indicator-window
consumer selects the newest aligned complete 1,000-row block and leaves any
zero-to-three-row suffix outside that projection until the next 4h block closes.
Native 1h-only requests continue to use their freshest 250-row suffix.

## Resume and Safety

Events still use the existing pending-event acknowledgement and durable live
checkpoint path. The pinned newest-confirmed timestamp freezes both the aligned
base and its observed suffix. If power is lost during emission, restart fetches
history through that timestamp without consulting the shifted current page,
skips identities already committed through the stream frontier, and continues
exactly. A completed warmup transition is not repeated.

Only the already allowlisted public OKX REST client is used. No credential,
account, private websocket, order endpoint or execution adapter is introduced.
Mainnet remains public and read-only; all later replay execution remains Fake.

## Verification

Tests prove an aligned 1,000-hour base plus its confirmed contiguous suffix for
both symbols, exclusive cursor progression, deterministic deduplication,
restart during emission, and fail-closed behavior for gap,
empty/non-progressing pages, conflicting duplicates and page-budget exhaustion.
Existing lower-timeframe, reconnect, capture/replay equality and checkpoint
tests must remain unchanged.
Required gates are related PHPUnit suites, PHPStan on the changed source,
`git diff --check`, CI and one substantive Codex review before merge.
