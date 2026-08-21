# Paper replay observation clock design

## Problem

`PaperReplayReader` orders and yields authenticated events by their exchange business key, but advances `PaperReplayClock` only to `exchange_timestamp`. A confirmed candle is normally received after its exchange/open timestamp. The canonical indicator window therefore sees its current trigger as not yet available and returns no evidence in the real replay path.

## Decision

Keep the existing deterministic event order. For each yielded event, advance the replay clock to a monotone observation watermark:

`max(current replay clock, exchange_timestamp, received_timestamp)`

The first event of a replay from position zero remains strict: its observation timestamp must not precede the clock supplied by the caller. This preserves the stale replay guard. Before a resumed replay yields its suffix, it reconstructs the maximum observation timestamp of the authenticated acknowledged prefix and advances a fresh clock to that watermark. A caller that already carries a later monotone clock keeps it. Later receipt timestamps may also be non-monotone because the canonical replay order deliberately ignores them; in both cases the watermark stays unchanged.

Only already-yielded events can influence projections. Moving the clock watermark cannot expose a later event because `PaperMarketStateProjector` contains only the applied prefix.

## Contract

- event ordering and checkpoint positions remain unchanged;
- an event is never observed before either its exchange or receipt timestamp;
- the clock never regresses across a replay prefix;
- an initially stale replay from position zero still fails closed with `paper_replay_clock_regression`;
- resume reconstructs and preserves the watermark established by the authenticated acknowledged prefix, including with a fresh process clock;
- `assertCanResume()` remains read-only and does not advance the clock;
- a normally delayed confirmed candle becomes eligible for canonical indicator projection when it is yielded.

## Scope

This slice changes replay time semantics and tests only. It does not wire the modern strategy bridge, change trading conditions, tune thresholds, or enable real/mainnet execution.
