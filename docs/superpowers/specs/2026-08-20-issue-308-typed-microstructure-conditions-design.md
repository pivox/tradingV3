# Issue #308 Typed Microstructure Conditions Design

## Goal

Expose the authenticated `canonical-microstructure-snapshot.v1` produced by
#364 to the strict rule evaluator and publish executable implementations for
`spread_bps_lte`, `order_flow_imbalance_gte`, and
`order_flow_imbalance_lte`. This lot removes only the data/condition blocker;
it does not make either micro-scalping setup executable.

## Boundary choice

The only accepted data flow is:

```text
verified public Paper/backtest facts
  -> CanonicalMicrostructureEngine
  -> CanonicalMicrostructureSnapshot
  -> CanonicalMicrostructureRuleInputAdapter
  -> RuleInputSnapshot(timestamped_order_book/1m)
  -> StrictRuleEvaluator
```

Conditions never read raw exchange payloads, legacy MTF enrichment, generic
indicator scalars, or an unverified array. Shadow and Paper may use a snapshot
built from a verified live Paper capture; backtest uses the PHP/Python golden
authority already bound to the same public dataset. Fake may replay an
authenticated fixture but may not synthesize spread or OFI and claim parity.

## Rule input adapter

`CanonicalMicrostructureRuleInputAdapter` calls `verify()` on the snapshot and
creates one `RuleInputSnapshot` with timeframe `1m` and source
`timestamped_order_book`.

Its values include:

- `spread_bps` and `order_flow_imbalance` as finite floats converted from the
  canonical decimal strings;
- `order_flow_imbalance_definition=aggressor_volume_ratio.v1`;
- microstructure input hash, source checksum, network, venue, market type,
  symbol, quantity unit, exact evaluation/window timestamps, and trade count.

`observedAt` equals the snapshot evaluation instant. `validUntil` is the most
restrictive of book expiry, latest-trade expiry, and latest-trade gap expiry.
If that instant precedes `observedAt`, conversion rejects. The adapter never
extends the policy freshness and never substitutes the catalog's five-second
upper bound.

## Typed conditions

The three final condition services share one proof validator. A condition
returns `missing_data=true` unless all of these are exact:

- `_input_source=timestamped_order_book` and `timeframe=1m`;
- the expected OFI definition;
- canonical SHA-256 input/source hashes;
- admitted network, venue, market type, symbol and quantity unit;
- finite observed value and finite in-range threshold.

`spread_bps_lte` accepts non-negative spread and threshold and passes on
`value <= max_spread_bps`. OFI conditions accept value/threshold in `[-1,1]`
and pass on `>= min_ofi` or `<= max_ofi`. Invalid proof never becomes a normal
threshold failure: it remains missing critical data for the strict evaluator.

## Catalog version

Published catalog versions remain immutable. A new exact `1.2.0` file copies
`1.1.0`, changes only the three implementation/status/provenance rows, and is
added to the loader allow-list. Their freshness contract remains five seconds,
but the adapter can emit an earlier `validUntil` from the source policy.

No existing setup is repinned in this lot. The later contract/runtime lot will
pin the exact `1.2.0` catalog hash together with resolved risk, EntryZone,
protection, costs, order policy and holding horizon.

## Failure and verification

Tests cover pass/fail boundaries, missing and forged proof, non-finite values,
wrong source/timeframe/definition/identity, snapshot tampering, exact validity
calculation, catalog immutability, exact-version loading, DI registration, and
an end-to-end strict-rule evaluation from the canonical snapshot.

No private mainnet execution port is introduced or selected.
