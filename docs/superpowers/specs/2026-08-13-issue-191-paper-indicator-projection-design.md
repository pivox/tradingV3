# Paper Candle Indicator Projection Design (#191)

## Goal

Project verified Paper v2 candle windows into the exact PHP-owned indicator context consumed by `CanonicalSetupRuleRuntime`, without database, exchange, order, portfolio, or Python indicator logic.

## Scope

This lot owns the deterministic boundary between a verified Paper dataset and canonical PHP indicators. It includes strict candle-window validation, native `1m`/`5m`/`15m`/`1h` projection, UTC `4h` aggregation, stable evidence hashes, a Symfony stdin/stdout command, and a frozen Python subprocess bridge.

It does not run TradingCore rules, create an `OrderPlan`, model fills or costs, persist snapshots, fetch market data, or execute on any exchange. Mainnet data may be public and read-only; execution identity is always `fake`.

## Chosen Architecture

Introduce a pure PHP projector instead of routing through `IndicatorProviderService`. The existing service mixes provider reads, cache, clock, Doctrine persistence, and fail-soft behavior. The projector validates canonical Paper candles, constructs exactly one 250-bar window per requested timeframe, invokes the existing PHP Core calculators, and emits the canonical runtime snapshot shape.

The projector owns `4h` aggregation because it is part of canonical market-data interpretation. Python only verifies the complete dataset artifacts, selects the bounded source suffix, serializes the request, validates the response, and enforces the subprocess limits.

The indicator backend is explicitly `php_fallback_v1`. The canonical projector must not branch on the presence of `trader_*`; otherwise identical Paper evidence could produce different results across hosts. Existing Core algorithms remain the formula authority, but the new projection path invokes a deterministic PHP-only facade whose behavior is covered by parity fixtures. Runtime convergence onto the same facade may be performed only where it preserves current behavior and is covered by parity tests; no unrelated runtime rewrite belongs in this lot.

## Request Contract

The request schema is `canonical-indicator-projection-request.v1`. One request represents one symbol at one evaluation instant and contains exactly:

- `request_id`;
- `evaluated_at`, as an exact UTC timestamp;
- `environment`, restricted to `local` or `test`;
- `indicator_engine_version = php_fallback_v1`;
- `dataset_binding`, containing the verified dataset identifier, dataset/candles/quality/source checksums, source network, market-data venue, and `market_type = perpetual`;
- `symbol`, restricted to the certifiable Paper universe;
- `requested_timeframes`, a unique ordered subset of `1m`, `5m`, `15m`, `1h`, `4h`;
- `candles_by_timeframe`, containing native `backtest-candle.v1` records for `1m`, `5m`, `15m`, and `1h` only.

Each requested native timeframe supplies exactly its freshest 250 admissible records. A requested `4h` supplies exactly 1,000 `1h` records, including the 250-record suffix needed if `1h` is requested too. This keeps the protocol bounded and makes accidental strategy-dependent history impossible.

Before slicing, Python must verify the complete Paper dataset and all published checksums. Because v1 does not carry Merkle inclusion proofs, PHP verifies the submitted slice shape and binds it with `window_hash`; it does not claim autonomous proof that the slice belongs to the complete artifact.

## Candle and Time Validation

Candles are strictly oldest-to-newest. Every record must:

- have the exact `backtest-candle.v1` shape and the same dataset provenance, symbol, and native timeframe as its enclosing window;
- be complete and have finite canonical decimal OHLCV values with valid geometry;
- use an exact UTC grid and `[open_at, close_at)` duration;
- satisfy `close_at <= evaluated_at` and `available_at <= evaluated_at`;
- be unique, contiguous, and non-overlapping.

There is no backfill, timeframe substitution, implicit truncation, stale-record fallback, or tolerance for duplicate/reversed input. A native window other than the exact required length fails closed.

For `4h`, PHP groups four consecutive `1h` records aligned at UTC epoch hours divisible by four. The aggregate uses first open, maximum high, minimum low, fourth close, exact decimal volume sum, group close, and maximum component availability. Missing, duplicated, shifted, future, or unavailable components reject the entire request. The projected `kline_time` is the final aggregate's open time, matching the current runtime convention.

## Indicator Projection

For each requested timeframe, the projector feeds exactly 250 validated bars to the deterministic PHP calculator facade. Output keys match the current canonical runtime context, including scalar values, EMA/MACD series and timestamps, ADX variants, volume ratio, pullback age, and `ma_21_plus_k_atr`.

The facade rejects non-finite inputs or outputs. It must compute the actual MACD line/signal evidence rather than copying the histogram into `macd_line_signal_series`. Any unavailable required indicator is an error, not an empty timeframe.

Each snapshot includes:

- `snapshot_identity = {timeframe, symbol, exchange: fake, environment, market_type}`;
- `kline_time` from the latest projected bar;
- the canonical indicator values;
- `window_hash` over the exact canonical source window;
- `indicator_engine_version`.

The market-data venue remains in dataset evidence and never replaces `exchange: fake` in the runtime identity.

## Result Contract and Determinism

The result schema is `canonical-indicator-projection-result.v1`. It echoes the request identity and dataset binding, contains `snapshots_by_timeframe`, and binds the exact normalized request with `input_hash`. `result_hash` covers the complete result except itself.

Canonical JSON ordering, timestamp rendering, float serialization, and hash rules follow the established TradingCore rule bridge. Repeating an input must yield byte-identical stdout and identical hashes.

The Symfony command reads one JSON object from stdin and writes one JSON object to stdout. It rejects duplicate JSON keys, trailing content, non-object roots, invalid UTF-8, depth over 128, more than 20,000 structural tokens, and input over 8 MiB. Errors use stable reason codes on stderr and a non-zero exit status; stdout remains empty on failure.

The Python bridge is frozen after construction and uses a real bounded subprocess: 15-second timeout, bounded stdout/stderr, deterministic environment, cleanup on every path, and exact identity/hash revalidation. It never evaluates indicators or aggregates candles.

## Failure Model

All contract, provenance, chronology, availability, arithmetic, process, identity, and hash failures are fail-closed. No exception is converted to an empty snapshot and no partial result is returned. No component may read Doctrine, an exchange API, runtime indicator snapshots, the order system, or portfolio state.

## Verification

Tests cover:

- 249 bars rejected and 250 accepted for every native timeframe;
- exactly 1,000 hourly records producing 250 correct `4h` aggregates;
- OHLCV, maximum availability, UTC alignment, gaps, duplicates, reversals, future close, future availability, and incomplete records;
- forged symbol, venue, network, market type, checksums, request identity, window hash, input hash, and result hash;
- finite PHP-only indicator output, canonical runtime shape, and the corrected MACD line/signal series;
- golden OKX and Hyperliquid requests through the real Symfony subprocess;
- byte-identical replay and equality across environments with or without `php-trader` loaded;
- input size, depth, structure, output, stderr, and timeout bounds;
- proof that no database, external provider, order, portfolio, or live execution port is invoked.

The implementation is acceptable only after targeted PHP/Python tests, static analysis, Symfony container lint, the full Python suite, and an independent final review pass.
