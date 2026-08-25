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
`1H` rows. It validates and identifies the oldest returned timestamp, then
requests older pages of at most 300 rows with that timestamp as the exclusive
cursor. Pagination stops only when at least 1,000 unique confirmed rows are
available.

Every page must:

- contain 1 through 300 structurally valid rows;
- be strictly reverse chronological before sorting;
- precede its request cursor and make the oldest timestamp progress;
- agree byte-for-byte on duplicate natural identities;
- contain a contiguous confirmed one-hour grid after deduplication.

The source retains exactly the newest 1,000 confirmed rows for emission. It
fails closed with a stable public-response integrity reason on an empty page,
non-progress, conflicting duplicate, malformed row, gap or exhaustion of a
fixed four-history-page budget. No partial snapshot boundary is emitted.

## Resume and Safety

Events still use the existing pending-event acknowledgement and durable live
checkpoint path. If power is lost during emission, restart refetches the bounded
warmup, skips identities already committed through the stream frontier, and
continues exactly. A completed warmup transition is not repeated.

Only the already allowlisted public OKX REST client is used. No credential,
account, private websocket, order endpoint or execution adapter is introduced.
Mainnet remains public and read-only; all later replay execution remains Fake.

## Verification

Tests prove 1,000 confirmed contiguous hourly emissions for both symbols,
exclusive cursor progression, deterministic deduplication, restart during
emission, and fail-closed behavior for gap, empty/non-progressing pages,
conflicting duplicates and page-budget exhaustion. Existing lower-timeframe,
reconnect, capture/replay equality and checkpoint tests must remain unchanged.
Required gates are related PHPUnit suites, PHPStan on the changed source,
`git diff --check`, CI and one substantive Codex review before merge.
