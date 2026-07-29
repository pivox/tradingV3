# Hyperliquid Paper Public History Design

Date: 2026-07-28

Parent: #132

Branch: `issue/132-hyperliquid-public-history`

## Scope

This lot adds credential-free historical Hyperliquid market data to the Paper
pipeline. It supports BTC and ETH perpetual candles for `1m`, `5m`, `15m`, and
`1h` on Hyperliquid public mainnet and public testnet.

The lot does not:

- retrieve or synthesize historical trades;
- reuse an account, wallet, signing, or execution client;
- retrieve a current L2 snapshot and label it as historical;
- connect to a websocket;
- place an order or call an exchange/action endpoint;
- combine mainnet and testnet in one dataset.

## Architecture Choice

Implement a dedicated Hyperliquid Paper acquisition stack that reuses only the
venue-neutral Paper event, dataset, and replay contracts.

Rejected alternatives:

1. Generalizing the existing OKX acquisition stack first would enlarge the
   change and couple two APIs with materially different pagination and
   historical-data semantics.
2. Reusing an account or execution Hyperliquid client would make the
   credential-free and read-only boundary depend on runtime configuration
   rather than construction-time types.

The dedicated stack keeps protocol validation, endpoint allowlisting,
pagination, checkpoints, normalization, and the modelled book independently
testable.

## Network and Endpoint Boundary

`HyperliquidPaperPublicConfig` is constructed with exactly one
`PaperMarketDataNetwork`:

- `mainnet` maps only to `https://api.hyperliquid.xyz/info`;
- `testnet` maps only to `https://api.hyperliquid-testnet.xyz/info`.

`legacy_unknown` is rejected. URI overrides, redirects, alternate paths,
userinfo, fragments, query strings, and non-HTTPS schemes are rejected.

The HTTP client exposes one public method, `candleSnapshot()`. It always sends:

```json
{
  "type": "candleSnapshot",
  "req": {
    "coin": "BTC",
    "interval": "1m",
    "startTime": 1760000000000,
    "endTime": 1760009999999
  }
}
```

Only `BTC`, `ETH`, `1m`, `5m`, `15m`, and `1h` pass validation. The request has
only `Content-Type: application/json` and `Accept: application/json`. It never
accepts arbitrary request types or headers.

The client uses bounded response reads, a fixed timeout, no redirects, a
bounded retry schedule for HTTP 429/5xx and transport failures, and a
Paper-specific rate limiter. Exceptions and logs contain stable reason codes
without response bodies, request bodies, paths, credentials, wallet addresses,
or arbitrary upstream text.

## Historical Request

`HyperliquidHistoricalRequest` contains:

- schema version;
- dataset ID;
- network;
- sorted unique symbols;
- the fixed four intervals;
- inclusive UTC start and exclusive UTC end;
- maximum event, page, response-byte, and retry bounds.

Its deterministic request hash includes every field above, including the
network. The Paper manifest and recorder derive the dataset identity from that
network even when the caller-provided dataset ID contains no network suffix. A
request cannot change network during resume.

The API documents a maximum of 500 elements for time-range responses and only
the most recent 5,000 candles. The source therefore paginates each
`coin × interval` stream forward with windows capped at 500 intervals.
`startTime` is inclusive; the next page starts at `last.t + interval_ms`.

The source rejects:

- a response larger than the page or byte limit;
- a candle outside the requested coin, interval, or time range;
- duplicate or decreasing candle starts;
- overlapping pages;
- a cursor that does not progress;
- missing candles inside the requested grid;
- an initial range older than the API retention actually returned;
- exhaustion of page/event/retry limits;
- malformed numeric values or inconsistent candle boundaries.

Pages are staged and checksummed before their cursor is acknowledged. The
checkpoint stores schema version, network, request hash, each stream cursor,
page counters, staged-page checksums, and completion flags. Publication uses
the same private, atomic, durable filesystem rules as the existing Paper
checkpoints. Restart revalidates all staged pages before continuing.

## Event Normalization

Each API candle becomes one `PaperMarketEvent`:

- network: the request network;
- venue: `hyperliquid`;
- channel: the matching common candle channel;
- instrument: `BTCUSDT` or `ETHUSDT`;
- exchange and received timestamp: candle close timestamp `T`;
- natural identity: `coin | interval | start t | close T`;
- payload: canonical decimal strings for OHLC and volume, trade count, start,
  close, interval, origin `rest_candle_snapshot`, and confirmation status.

Events are emitted in deterministic order by exchange timestamp, symbol,
interval duration, channel, and event ID. There is no `public_trade` event and
no placeholder trade event.

## Deterministic Prudent Book Model

The model is named `hl_candle_atr_top_v1`, version `1.0.0`.

It consumes only completed candles in the same `network × symbol × interval`
stream:

1. `true_range` is the maximum of `high-low`, `abs(high-previous_close)`, and
   `abs(low-previous_close)`. The first candle uses `high-low`.
2. `atr14` is the arithmetic mean of the latest 14 available true ranges,
   including the current candle.
3. `volatility_bps` is
   `10000 × max(high-low, atr14) / close`.
4. Total spread is
   `clamp(0.15 × volatility_bps, 2 bps, 50 bps)`.
5. Mid is the candle close. Best bid and ask are
   `mid × (1 ∓ spread_bps / 20000)`.
6. Prices are rendered as canonical non-exponent decimal strings without
   claiming exchange tick precision.
7. When volume and trade count are positive, each side exposes exactly one
   synthetic top level with size `volume / trade_count`. When either is zero,
   no book event is emitted for that candle.

The resulting book event:

- uses the common book-snapshot channel;
- has the candle close timestamp;
- identifies the originating candle and model name/version;
- marks every level and the complete event as synthetic/modelled;
- never calls the current `l2Book` endpoint.

One top level per side intentionally avoids inventing historical depth. The
model is deterministic across restart and replay because its rolling ATR state
is reconstructed from the checksummed candle pages.

## Dataset Contract

Add the quality:

`public_historical_candles_modelled_book`

A complete Hyperliquid historical manifest must have:

- venue `hyperliquid`;
- network `mainnet` or `testnet`;
- the new quality;
- model name `hl_candle_atr_top_v1`;
- model version `1.0.0`;
- only the requested candle channels and modelled book snapshots;
- no trade channel.

The manifest and dataset identity bind the network. The recorder rejects an
event from another network or venue. Baseline verification accepts this quality
only when the network provenance is certifiable, all requested candle grids are
complete, and the declared model matches every modelled book event.

Mainnet and testnet requests always produce separate directories, manifests,
checkpoints, and dataset identities.

## Service Wiring

Register only Paper-specific Hyperliquid services:

- public config;
- rate limiter;
- HTTP client interface and implementation;
- historical request;
- checkpoint store;
- normalizer;
- deterministic book model;
- acknowledged historical event stream.

Acquisition remains disabled by default. The container wiring must not inject a
private key, wallet, signer, account client, exchange client, or existing
Hyperliquid execution service into any of these services.

## Verification

All protocol tests use local Symfony HTTP fixtures or mock transports; CI makes
no real Hyperliquid request.

Tests cover:

- exact mainnet/testnet endpoint allowlists and dataset separation;
- exact request body and header allowlist;
- rejection of credentials, wallets, signatures, arbitrary request types,
  `/exchange`, `/action`, redirects, and alternate hosts;
- BTC/ETH and four-interval validation;
- 500-element pagination, inclusive cursor handling, retry and rate limits;
- restart from every checkpoint boundary;
- duplicate, out-of-order, overlap, gap, stale range, malformed response,
  oversized response, checksum corruption, and non-progressing cursor;
- deterministic candle and modelled-book normalization;
- ATR warm-up, spread clamps, zero-volume behavior, and model version binding;
- capture/replay byte and event equality;
- manifest quality, network provenance, and baseline certification;
- absence of historical trade events and current L2 calls;
- redaction of upstream and local sensitive values.

Required gates:

- relevant PHPUnit suites;
- PHPStan on changed sources;
- Symfony container and YAML lint;
- MkDocs strict;
- `git diff --check`;
- one Codex review request, resolution of all actionable threads, CI on the
  final HEAD, then merge without requesting a second review.
