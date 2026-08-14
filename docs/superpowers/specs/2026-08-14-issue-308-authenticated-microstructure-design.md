# Issue #308 Authenticated Microstructure Design

## Goal and scope

Build the strict data authority required before `micro_scalping` can become
executable. The authority derives a live spread and a versioned order-flow
imbalance from the authenticated public market-data records delivered by
#191. It does not publish an executable mode/setup contract, tune thresholds,
or activate any execution port.

## Chosen metric

The existing legacy field called `order_flow_imbalance` is an unversioned
static depth ratio and cannot be certified. The canonical v1 metric instead is
an aggressor-volume ratio over an explicit trailing window:

```text
OFI = (sum(buy aggressor quantity) - sum(sell aggressor quantity))
      / sum(all aggressor quantity)
```

The result is dimensionless and bounded to `[-1, 1]`. Every contributing
trade belongs to one venue, symbol, market type, source snapshot and quantity
unit. Mixing OKX contracts with Hyperliquid base-asset quantities is rejected.
No conversion is needed inside one homogeneous ratio.

The spread is derived from the latest public L1 record available at evaluation
time:

```text
spread_bps = 10000 * (ask - bid) / ((ask + bid) / 2)
```

The output names the OFI definition `aggressor_volume_ratio.v1`; it never
claims depth reconstruction, queue position or fillability.

## Inputs and policy

The engine consumes the immutable public-book and public-trade facts already
validated by the Paper v2 / backtest boundary. A strict policy declares:

- trailing window length;
- maximum book age;
- maximum latest-trade age;
- maximum gap at the window boundaries and between consecutive trades;
- minimum trade count.

Policy values are explicit positive integers and form part of the output hash.
No default is inferred by the engine. The later `micro_scalping` contract owns
the production values.

Evaluation accepts only facts whose `available_at` is not later than the
evaluation instant, preventing look-ahead. Trade membership uses event time,
while freshness and gap checks use event chronology. Inputs must already be in
canonical `available_at`, `happened_at`, source-ID order and source IDs must be
unique.

## Output and lineage

`canonical-microstructure-snapshot.v1` records:

- network, venue, market type and symbol;
- evaluation instant and exact window bounds;
- selected L1 identity, timestamps, bid, ask and derived spread;
- OFI definition, quantity unit, count and exact buy/sell/total quantities;
- first/last trade times and every consumed source record ID;
- source snapshot checksum and policy;
- a SHA-256 hash over the complete canonical payload.

Decimals remain canonical strings. PHP uses Brick Math and Python uses
`Decimal`; both round only derived ratios to 12 decimal places with HALF_EVEN
and remove insignificant trailing zeroes. Golden examples pin the exact hash
in both runtimes.

## Failure model

All failures are fail-closed under stable `canonical_microstructure_*` codes:

- invalid policy or input shape;
- mixed identity, source checksum or quantity unit;
- duplicate or non-canonical chronology;
- missing/non-positive/crossed book;
- unavailable, future or stale evidence;
- insufficient trades or an uncovered window gap;
- invalid/non-finite arithmetic;
- snapshot hash mismatch.

No scalar supplied by legacy MTF enrichment can bypass this authority.

## Delivery split

This PR delivers the pure PHP and Python authority plus cross-runtime golden
proof. A subsequent #308 PR will:

1. adapt live Shadow/Paper inputs and verified backtest tapes to this boundary;
2. implement the typed spread/OFI conditions;
3. publish new mode/setup/catalog versions with explicit policy values;
4. build the canonical order plan and reservation path;
5. prove Shadow/Fake/Paper/backtest parity before executable status.

Mainnet remains public and read-only.

## Verification

Tests cover long/short OFI signs, exact spread, look-ahead exclusion, stale and
future data, sparse/gapped windows, duplicate and reordered records, crossed
identities/units/checksums, decimal determinism, tamper detection and identical
PHP/Python golden hashes. Static analysis and the existing Paper/backtest suites
must remain green.
