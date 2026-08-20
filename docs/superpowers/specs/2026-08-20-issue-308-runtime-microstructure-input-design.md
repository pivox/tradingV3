# Issue #308 Runtime Microstructure Input Design

## Goal

Carry the authenticated `canonical-microstructure-snapshot.v1` boundary into
`CanonicalSetupRuleRuntime` without reading legacy spread/OFI scalars. This lot
publishes the typed runtime ingress needed by the later `micro_scalping@1.1.0`
contracts; the existing blocked setup contracts remain immutable and blocked.

## Chosen boundary

The runtime receives microstructure only through a dedicated provider port:

```text
verified Paper/backtest/live records
  -> CanonicalMicrostructureEngine
  -> CanonicalMicrostructureSnapshotProviderInterface
  -> CanonicalMicrostructureRuleInputAdapter
  -> RuleInputSnapshot(timestamped_order_book/1m)
  -> CanonicalSetupRuleRuntime
```

The provider returns the sealed snapshot object, never an array or precomputed
metric. A dataset adapter may build that object from a verified
`PaperBacktestDataset`; live Shadow providers can implement the same port later
without changing rule evaluation.

## Market identity

The runtime owns the expected identity. It derives it from canonical lineage,
not from the provider result:

- `environment=mainnet|testnet` maps exactly to the source network;
- `exchange=okx|hyperliquid` maps exactly to the market-data venue;
- market type must be `perpetual`;
- symbol must be present;
- OKX quantities are `contracts`; Hyperliquid quantities are `base_asset`.

`fake`, `demo`, `test`, `local`, spot, missing symbols and every unknown value
have no implicit mapping. They produce no expected identity and therefore no
authenticated microstructure input. Fake/Paper venue routing remains owned by
#196.

## Failure contract

Provider absence, a null result, provider failure, canonical adapter rejection,
or unavailable market identity is represented explicitly in the runtime trace.
No exception or best-effort scalar fallback can turn those states into a valid
input. Existing catalog 1.0 micro setups still report their frozen compiled
blockers; future catalog 1.2 setups will fail closed on the missing
`timestamped_order_book` snapshot.

The trace exposes only status, canonical hash and public identity metadata. It
does not serialize the sealed proof object.

## Verified dataset adapter

`PaperBacktestMicrostructureAdapter` accepts an already verified
`PaperBacktestDataset`, an exact symbol, evaluation instant and versioned
policy. It filters the dataset to that symbol and delegates all chronology,
freshness, identity and arithmetic checks to `CanonicalMicrostructureEngine`.
It never hydrates a snapshot or consumes raw event payloads.

## Non-goals

- No `micro_scalping@1.1.0` mode/setup publication in this lot.
- No legacy MTF order-book enrichment.
- No synthetic Fake spread or OFI.
- No private mainnet execution or mutation port.

## Verification

Tests cover verified dataset conversion, wrong/missing identity, provider
absence/failure, cross-market proofs, exact runtime trace binding, unchanged
blocked 1.0 behavior, container lint, PHPStan and the relevant rule/Paper suites.
