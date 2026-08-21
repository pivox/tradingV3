# Issue #196 canonical Paper indicator-window source

## Scope

This slice creates the first production-shaped input boundary for the canonical
Paper evidence provider. It extracts indicator candle windows only from the
events already authenticated and projected by `PaperMarketStateProjector`, and
normalizes them through the same exact OKX/Hyperliquid adapter used by verified
backtest datasets.

It does not invent a derived dataset identity, costs, order book, instrument
metadata or portfolio state. It therefore does not implement
`PaperCanonicalStrategyEvidenceProviderInterface` and does not activate modern
Paper replay.

## Contract

`PaperCanonicalIndicatorWindowSource`:

1. accepts only a modern cell whose network and venue match the trigger;
2. requires requested timeframes in canonical duration order;
3. requires the trigger to be the current event in the projected prefix;
4. excludes events and normalized candles unavailable at the shared replay
   clock, including candles received before their close;
5. reuses `PaperBacktestDatasetAdapter::adaptCandleEvents()` so venue payload,
   native-symbol and source-event provenance remain exact;
6. returns exactly 250 contiguous native candles per requested timeframe;
7. returns 1,000 aligned hourly source candles when a derived `4h` projection
   is requested; and
8. returns no evidence when history is insufficient, while malformed or
   discontinuous history fails closed with stable reasons.

The shared `PaperReplayClock` is mandatory. There is no constructor fallback to
wall time or the Unix epoch.

## Activation boundary

The current replay reader advances the shared clock to exchange time. The
window source additionally honors normalized `available_at`, so it will not
expose a candle merely because its envelope has already been received. Any
future change to replay-clock semantics must be explicit and replay-tested; this
slice introduces no look-ahead exception.

The next evidence-provider slice must build a deterministic, documented
checksum graph tying these source records to the verified replay dataset before
calling `CanonicalIndicatorProjector`. Modern Paper remains fail-closed and no
mainnet execution client is enabled.
