# #304 Lot B — Canonical Entry and Order Plan Design

## Scope

Lot B adds the strict consumer for resolved setup execution policies. It does
not make any current setup executable and does not copy values from legacy
`trade_entry.*.yaml`. Current unresolved `entry_zone`, `stop`, `targets`,
`minimum_net_r`, `time_stop`, or `cost_contract` decisions continue to reject.

The implementation is pure TradingCore code. It has no Symfony container,
Doctrine, Messenger, provider, HTTP, legacy `TradeEntryConfig`, legacy
`OrderPlanBuilder`, or `ExecutionBox` dependency.

## Compiled execution policy

One compiler consumes an executable `EffectiveTradingConfigSnapshot`, verifies
its canonical hash again, and requires every setup execution decision below to
be `defined` with its exact unit. Policy construction is private.

`entry_zone.value` owns:

- an explicit anchor kind and timeframe;
- an explicit ATR timeframe;
- ATR multiplier, minimum and maximum half-width rates;
- a bounded signed asymmetry rate;
- TTL and maximum input age in seconds;
- mandatory outward tick quantization.

`stop.value` owns exactly one source kind (`atr` or `pivot`), its timeframe,
the required multiplier or pivot identifier, and a non-negative buffer rate.
There is no source fallback.

`targets.value` is a non-empty ordered list. Each target owns one positive
risk multiple and one execution liquidity role. `minimum_net_r` is a positive
net-R multiple. `time_stop` supplies the holding window used for funding costs.
`cost_contract` requires explicit entry, stop, and target spread/slippage
sources; unknown costs reject at request construction.

## EntryZone

The request contains timestamped anchor, ATR, candidate-market, and tick
snapshots plus an injected clock. Every identity, timeframe, timestamp, and
finite numeric value is validated. Inputs older than the policy maximum age or
observed in the future reject.

The half-width is `clamp(ATR * multiplier, anchor * min_rate,
anchor * max_rate)`. Side asymmetry moves width between the lower and upper
halves without moving the anchor. Bounds are quantized outward to the tick.
The selected candidate price is quantized conservatively and must remain in the
zone. The immutable result records `observedAt`, `computedAt`, `expiresAt`,
source identities, policy identity, and config hash.

## Protection and net R

Protection is calculated before sizing. ATR stops use only the requested ATR
snapshot; pivot stops use only the exact requested pivot snapshot. Long stops
and targets remain below/above entry respectively; short polarity is reversed.
Stop quantization is conservative away from entry. Target quantization is
conservative toward entry. Polarity is revalidated afterward.

Each target price is derived from the exact quantized entry-to-stop risk
distance and its configured R multiple. Net R is then calculated with
arbitrary-precision decimal arithmetic using the same authenticated fee
schedule and explicit entry/stop/target spread, slippage, funding rate, and
holding-window interval count used by risk sizing. Every target must meet the
compiled minimum; one failure rejects the complete plan.

## Canonical OrderPlan

The pipeline accepts only a compiled policy, accepted EntryZone, accepted
protection/net-R decision, and accepted `CanonicalRiskDecision`. Identity,
side, config hash, quantity, entry, and stop must agree byte-for-byte at the
contract boundary.

The resulting immutable canonical plan records all lineage identifiers,
timestamps, input hashes, cost components, net-R values, caps, and protection
prices. A final pure validator recomputes freshness, side, zone containment,
risk-budget, leverage-cap, and minimum-net-R invariants from the plan. It never
maps through a legacy DTO and never selects a MARKET fallback.

## Runtime safety boundary

Lot B exposes the canonical pipeline and serializer but does not remove the
portfolio blockers owned by Lot C or make unresolved setup contracts
executable. Fake/Paper/demo-testnet adapters may consume the pipeline in later
mode issues. Mainnet remains public/read-only.

