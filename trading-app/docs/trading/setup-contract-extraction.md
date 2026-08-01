# Canonical setup contract extraction

This catalog freezes eight source hypotheses from the four legacy validation files. It does not activate trading, tune thresholds, claim profitability, add mainnet writes, or change the runtime resolver. The intended future precedence remains `base < mode < setup < exchange < mode_exchange < env`; #133 owns that resolver work.

## Frozen catalog

| Setup ID | Legacy source | Initial state | Modern mode |
|---|---|---|---|
| `day_trading.trend_continuation.long` | regular long | draft | day_trading 1.0.0 |
| `day_trading.trend_continuation.short` | regular short | blocked | day_trading 1.0.0 |
| `scalping.trend_continuation.long` | scalper 15m scenario A | draft | scalping 1.0.0 |
| `scalping.pullback.long` | scalper 15m scenario B | draft | scalping 1.0.0 |
| `scalping.trend_momentum.short` | scalper short | draft | scalping 1.0.0 |
| `micro_scalping.momentum_ofi.long` | micro long | blocked | micro_scalping 1.0.0 |
| `micro_scalping.momentum_ofi.short` | micro short | blocked | micro_scalping 1.0.0 |
| `crash_short` | crash short | draft | unresolved pending #310 |

There is no swing setup. Regular `any_of` branches are retained as evidence variants inside one continuation hypothesis. Scalper 5m/1m rules are common confirmations. The two scalper 15m long scenarios remain separate identities. The crash 1m pullback branch adds a condition to the normal branch and is therefore a redundant subset, not a ninth setup. Crash remains a setup and never becomes a fourth modern mode.

## Immutable sources

Each setup pins the exact legacy file, every cited definition/invocation range, the content SHA-256, and the last commit that changed the source file. Every extracted rule and boolean group also carries its own line provenance. Tests recompute source content hashes, load exactly eight version directories, audit all eight origin ranges, and validate the documents both with PHP and the Draft 2020-12 schema.

The pinned hashes are:

- regular: `e15ec9ea51330c83b2d0f14791a7985fc06793ee925c32eb4d5d962b3b2e1a13`
- scalper: `5bf86ce415079ee896a98d2c91e987d11db975c986500862b0cff82440c590a2`
- micro: `47969bd5055b28ba5871b0b22e503482730a368c56fad3d0963aaad3808808e2`
- crash: `5dd5cbf03cdbcb804cd664e47c0dce4007438bbce973af027a05e7155b2c10e2`

## Visible defects and unresolved decisions

- Regular short is blocked because its below-VWAP rules conflict with mandatory bullish MA9/VWAP pullback filters.
- Regular selector bands overlap: widths `(1.2, 1.3]` and ATR `(120, 130]` can satisfy stay and drop evidence simultaneously.
- Scalper 15m can satisfy neither `stay_on_if` nor a conditional drop rule.
- Micro references `spread_bps_lte` and side-specific OFI conditions without registered implementations/trustworthy data. Its symmetric positive epsilon MACD comparisons can admit both sides.
- Crash has no resolved modern-mode membership or cost model. Its extra pullback OR branch is a strict subset of the normal branch.
- None of the four validation sources defines a canonical EntryZone, stop, targets, minimum net R, invalidation, time-stop, full validity duration, or complete costs. These fields are explicitly `unresolved`; no values are inferred from trade-entry configuration.
- A sourceable condition-catalog artifact/hash does not exist yet. Snapshots store `condition_catalog_hash: null`, remain non-publishable, and never fabricate a hash.

## Static safety and ownership

The PHP loader rejects unknown IDs/versions and identity mismatches without aliases or fallback. The PHP validator and JSON schema reject missing, extra, mistyped and unknown fields/conditions, unsupported timeframes, unknown condition parameters, and wrong parameter types. Parameter keys are condition-specific in PHP and constrained by equivalent conditional rules in the JSON schema. Critical absent or stale data rejects. Side equality is enforced as `setup.side = context.side = execution.side`. Setup documents cannot own risk budget, leverage caps, or exchange fees; those remain mode/exchange concerns.

Compilation preserves the source `all_of`/`any_of` expression tree in a canonical AST, including the three scalper scenario-A MACD alternatives and the redundant crash pullback subset. Its readonly snapshot contains setup version, compatible mode versions, stable configuration hash, condition-catalog state, and provenance indexed by key. A source artifact outside `config/trading/setup_contract` cannot be loaded or compiled through the canonical loader. Publication also requires a complete condition catalog, executable lifecycle, full trace, and certified net baseline. All eight extracted versions intentionally fail those gates.
