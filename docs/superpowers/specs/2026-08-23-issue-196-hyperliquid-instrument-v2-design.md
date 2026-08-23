# #196 Hyperliquid instrument v2 design

## Goal

Make public Hyperliquid instrument records eligible for canonical modern
strategy evidence without inventing a static tick, a quantity cap, or an
implicit USDC/USDT conversion.

This lot removes only the instrument-provider blocker. A full Hyperliquid
strategy replay remains fail-closed until a separate public funding capture and
canonical funding source exist; the current funding source intentionally
accepts OKX only.

## Source contract

New captures emit `paper-instrument-metadata.v2` for BTC and ETH perpetuals.
The record preserves the public contract semantics:

- the base quantity represents one unit of the underlying asset;
- the contract is USDT-denominated and USDC-settled/margined;
- `szDecimals`, maximum leverage, five significant price figures, and the
  per-asset maximum price decimals come from the public metadata contract;
- maximum market notional follows the frozen public leverage tiers: 15,000,000
  for leverage at least 25, 5,000,000 from 20, 2,000,000 from 10, and 500,000
  below 10;
- maximum limit notional is ten times the market maximum.

The notional fields carry a named versioned model. They are deterministic
contract constraints derived from the public maximum leverage, not values
returned by a private endpoint.

Old v1 datasets remain readable for historical quantity conversion, but they
remain ineligible for canonical execution evidence. Normalized instrument
records move to `backtest-instrument-metadata.v3` to preserve the two optional
notional caps and their model without changing v2 semantics in place.

## Canonical evidence

Hyperliquid has no single static price tick. The instrument source therefore
requires the current authenticated replay book and derives:

1. a conservative reference price equal to the best ask;
2. the common valid price step imposed by both the per-asset decimal cap and
   the five-significant-figure rule;
3. market and limit maximum quantities by dividing the corresponding notional
   caps by the reference price and flooring to the public quantity step.

The instrument and tick share a composite SHA-256 input hash over the exact
metadata record, exact book snapshot, and derived constraints. Missing v2
metadata, book mismatch, non-positive result, or a derived quantity below the
minimum fails closed. OKX v2 behavior remains unchanged.

Because the five-significant-figure step changes at decimal magnitude
boundaries, every generated entry, stop, and target is validated again against
its own price magnitude. A price that crosses a boundary without satisfying the
new step produces no plan. The canonical book snapshot also carries the public
`source_epoch`; Hyperliquid evidence requires exact equality with the metadata
epoch so the reconnect interval before the first new book stays fail-closed.

## Settlement semantics

Canonical risk and the private Fake ledger remain numerically denominated in
the USDT contract quote currency. Hyperliquid settlement remains explicitly
USDC in source and normalized evidence, and the Fake instrument descriptor
uses USDC settlement for a Hyperliquid cell. That descriptor advances to v2;
persisted v1 descriptors remain restart-readable with their original USDT
settlement semantics. No exchange rate, stablecoin parity conversion, or
private balance call is introduced.

## Safety and verification

- Capture remains public metadata plus public order book only.
- No private endpoint, credential, exchange write, or real execution is added.
- Exact payload keys, source ordinals, dataset verification, PHP normalization,
  and the Python mirror all reject malformed or mixed-version records.
- Tests cover leverage-tier boundaries, dynamic tick boundaries, quantity
  flooring, book identity/hash binding, v1 rejection for execution, old-record
  readability, and Hyperliquid USDC settlement in Fake descriptors.
