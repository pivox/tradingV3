# #191 Authenticated public instrument metadata capture design

## Goal

Capture the venue facts required to interpret public book and trade quantities
inside the immutable Paper dataset. This is the evidence prerequisite for a
later contract-to-base conversion tape; it does not perform conversion or
simulate execution.

## Chosen authority

The metadata is a first-class `instrument_metadata` Paper v2 event. A live
source observes it through the venue's unauthenticated public API, timestamps
the observation at receipt, normalizes only the supported BTC and ETH perpetual
contracts, and persists it through the same acknowledged checkpoint path as
market events.

This boundary is preferred to either a sidecar or a static symbol table:

- a sidecar could be replaced independently of the dataset event chain;
- a static table would not prove which venue contract was effective when the
  market observation became available;
- reading current provider metadata during a later backtest would introduce
  look-ahead and could silently reinterpret historical quantities.

Metadata is observed once for each symbol at the start of every live source
epoch, before that epoch's snapshot boundary. A reconnect therefore refreshes
the observation. Historical datasets that lack a dated metadata event remain
non-convertible and receive no fallback.

## Versioned normalized contract

Every event has `metadata_schema_version = paper-instrument-metadata.v1`, the
native and normalized instrument identities, explicit base/quote/settlement
assets, `perpetual` instrument type, `live` status, a positive source epoch and
an admitted public origin. Numeric values are canonical positive decimals.

Common quantity fields are:

- `quantity_unit`: `contracts` on OKX and `base_asset` on Hyperliquid;
- `quantity_step` and `minimum_quantity` in that explicit unit;
- `contract_value`, `contract_multiplier` and `contract_value_unit` preserved
  separately so a later lot cannot omit the multiplier;
- nullable venue maxima only when the public source exposes them.

OKX accepts only live linear USDT-settled swaps whose `ctValCcy` equals the
base asset. It preserves `tickSz`, `lotSz`, `minSz`, `maxMktSz`, `maxLmtSz`,
`ctVal`, `ctMult`, `ctType`, `ctValCcy` and `settleCcy` from
`GET /api/v5/public/instruments?instType=SWAP&instId=...`. Quantity remains in
contracts.

Hyperliquid accepts only BTC and ETH entries from the public `meta` info
response. It preserves the universe index as `asset_id`, `szDecimals` and
`maxLeverage`. Size is in base-asset units, `contract_value` and
`contract_multiplier` are exactly one, and the minimum quantity equals
`10^-szDecimals`. Because perpetual prices use five significant figures plus
a `6 - szDecimals` decimal-place cap, the contract stores both constraints and
does not invent a fixed price tick.

## Identity and continuity

The natural identity binds network, venue, native symbol, metadata schema,
source epoch and the canonical digest of the admitted source fields. A repeated
identity with different bytes fails closed through the existing ordinal and
checkpoint machinery.

For OKX, instrument metadata is a regular stream frontier and participates in
warmup/reconnect transitions. For Hyperliquid, it is emitted through the
existing immutable pending-event continuation before the two snapshot
boundaries. Dataset verification recomputes the venue-specific identity,
validates exact keys and units, and requires metadata to precede the boundary
for the same symbol and epoch.

## Failure model

The capture fails closed on a missing symbol, duplicate instrument, unknown
field shape, unsupported contract type or currency, non-live state, invalid
decimal, impossible precision, response cap violation, public HTTP error,
natural-identity conflict, or checkpoint mismatch. There is no provider DTO,
environment, legacy profile or current-time fallback.

Only public read endpoints are used. No authenticated account endpoint and no
real-order/mainnet execution path is introduced.

## Testing

Focused tests cover strict REST response parsing, both venue normalizers,
initial and reconnect epoch emission, crash/resume acknowledgement, exact
dataset verification, tampering, invalid units and source ordering. Service
wiring proves both metadata clients resolve to the bounded Paper public clients.
Broader Paper tests, PHPStan and strict documentation verification guard the
existing acquisition and replay contracts.

## Explicit non-goals

This lot does not convert contracts to base quantity, attach metadata to a
book, select metadata by availability time, compute notional or fees, infer
depth/queue rank, or produce full or partial fills. Those behaviors belong to
the next immutable conversion-tape and separately versioned execution-model
lots.
