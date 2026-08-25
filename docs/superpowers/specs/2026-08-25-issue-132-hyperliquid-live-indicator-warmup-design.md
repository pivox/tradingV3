# #132 Hyperliquid Live Indicator Warmup Design

Date: 2026-08-25

Parent: #132

## Goal

Make every new Hyperliquid public live Paper capture contain the contiguous
confirmed native candles required by canonical strategy indicators: 250 rows
for 1m, 5m and 15m, and an aligned 1,000-row 1h base from which 250 4h candles
are derived. Live books, trades, metadata and funding remain in the same
certifiable dataset.

## Scope and Choice

The existing Hyperliquid live source starts with websocket subscriptions,
metadata, funding and snapshot boundaries, but has no historical candle warmup.
The existing credential-free REST client already exposes `candleSnapshot` with
a validated maximum of 500 rows.

The selected design injects that client into the live source and fetches bounded
ascending time ranges immediately after all websocket subscriptions are ready.
For each BTC and ETH stream it obtains 250 closed 1m, 5m and 15m candles and a
UTC-four-hour-aligned 1,000-row 1h base. Only then does it emit metadata,
funding, warmup candles and the initial snapshot boundaries through the existing
durable pending-event protocol.

A separate historical dataset was rejected because it would split the live
book/instrument/funding proof from the indicator proof. A native 4h feed was
rejected because canonical projection intentionally derives 4h from 1h and
must retain a fresh native 1h window.

## Range and Integrity Contract

The source freezes one immutable observation upper bound per symbol when the fresh
session becomes ready. Each interval derives its latest fully closed candle from
that bound. The 1h end is moved backward by zero through three hours so
that its 1,000-row base begins on the UTC four-hour grid. Lower intervals use
their latest fully closed boundary.

Each REST request covers at most 500 expected starts. Responses must be non-empty,
ascending, structurally valid, entirely inside the requested range and exactly
contiguous at the native interval. The 1h stream therefore uses exactly two
bounded pages; each lower stream uses one. Duplicate, missing, out-of-range or
conflicting rows fail closed with a stable live-integrity reason. No partial
snapshot boundary is emitted.

The normalized candle events are globally ordered by close time, normalized
symbol and interval duration before durable emission. The 1h base is followed
by any newer confirmed contiguous 1h rows obtained during a resumed catch-up,
up to the current closed boundary. This preserves both the aligned 4h base and
the freshest native 1h context.

## Resume and Checkpoint

The checkpoint schema records exact immutable warmup ends for BTC and ETH. A
restart before streaming reconstructs the same REST ranges, revalidates the
complete result and resumes through the existing pending-event acknowledgement
and acknowledged-identity frontier. Once the source reaches `streaming`, the
warmup is never repeated. The configuration hash and checkpoint schema bind the
new policy, so legacy checkpoints cannot silently adopt different ranges.

Only public Hyperliquid REST and websocket endpoints are used. No credential,
account, private websocket, order endpoint or execution adapter is introduced.
Mainnet remains public and read-only; subsequent execution remains Fake/Paper.

## Verification

Tests prove exact windows for both symbols, 500-row pagination, 4h-grid
alignment, global deterministic order, restart during emission, current 1h
catch-up and fail-closed behavior for empty, gapped, duplicate, conflicting and
out-of-range pages. Factory/service-wiring tests prove the REST client is
injected. Existing reconnect, capture/replay equality, checkpoint and canonical
projection suites remain green. Required gates are focused PHPUnit, adjacent
Paper suites, targeted PHPStan, Symfony container/YAML lint, MkDocs strict,
`git diff --check`, CI and substantive review before merge.
