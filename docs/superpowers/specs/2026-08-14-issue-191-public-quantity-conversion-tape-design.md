# #191 Authenticated public quantity conversion tape design

## Goal

Convert the exact venue quantity units carried by authenticated Paper public
trade and L1 book events into base-asset quantities without changing the raw
trade or book tapes. The result is an immutable derived evidence artifact that
a later execution model may consume, but this lot does not infer liquidity,
queue priority or fills.

## Chosen boundary

The raw `backtest-public-trade.v1` and `backtest-public-book.v1` records remain
unchanged. A separate `backtest-public-quantity-conversion-tape.v1` artifact
binds each converted observation to:

- the exact Paper dataset and authenticated source checksum;
- the source trade or book record ID;
- the source event's zero-based position in the immutable dataset event list;
- the exact `instrument_metadata` event ID and its earlier event position;
- the original venue quantity, unit and conversion inputs;
- the exact base-asset result.

This preserves the distinction between observed venue facts and derived facts.
Changing a conversion formula, metadata selection rule or record schema creates
a new versioned artifact instead of reinterpreting the raw tapes.

Alternatives were rejected as follows:

- extending the raw tape v1 records would mutate an already published evidence
  boundary and make old fixtures ambiguous;
- converting inside the fill simulator would couple evidence interpretation to
  execution policy and invite current-metadata or look-ahead fallbacks.

## Effective metadata selection

The verified Paper snapshot event list is the only ordering authority. While
the PHP adapter walks that list, an admitted metadata event becomes effective
for its symbol only after its own position. A public trade or top-of-book event
is convertible only when all of the following are true:

1. a strict metadata v1 event for the same network, venue and symbol was seen
   at a lower event position;
2. the metadata `received_timestamp` is not later than the source event
   `received_timestamp`;
3. the metadata unit matches the venue source unit exactly;
4. the metadata remains the latest admitted observation for that symbol at the
   source event position.

Equal receipt timestamps are resolved only by dataset position. The existing
adapter may still expose raw v1 trades/books from a legacy dataset, but it emits
no derived row for an observation before metadata. Artifact construction then
fails complete-coverage validation. Changed metadata with invalid ordering and
unknown units likewise produce no certification. No current API, static symbol
table, environment value or legacy profile may supply missing inputs.

## Conversion contract

Every conversion row uses canonical positive decimal strings and contains a
discriminator `source_channel` with one of two exact shapes:

- `public_trade`: one `trade` quantity;
- `top_of_book`: exact `bid` and `ask` quantities.

For OKX linear USDT swaps, the captured metadata guarantees that
`contract_value_unit` equals the base asset. The exact formula is:

```text
base_quantity = contracts * contract_value * contract_multiplier
```

This follows the OKX public instrument contract, which defines one derivative
contract value as `ctVal * ctMult` in `ctValCcy`. For Hyperliquid perpetuals,
the public `sz` field is already denominated in coin/base currency, so the
conversion factor is exactly one and the canonical base quantity equals the
source quantity.

All arithmetic uses arbitrary-precision decimal multiplication. The converter
does not round, quantize or apply a price. Source quantity-step validation
remains a venue metadata invariant; conversion does not turn a rejected venue
quantity into an admitted one.

## PHP projection

`PaperBacktestDatasetAdapter` keeps the raw candle, trade and book projections
and additionally emits strict normalized metadata plus conversion rows. The
adapter owns source event positions because it already receives the verified,
checksum-bound Paper event list. Dedicated immutable value objects validate
metadata, cross-references, channel-specific quantity roles and exact results.

The encoder emits canonical NDJSON for metadata and conversions. Checked-in
fixtures are generated from this encoder and remain forbidden from carrying
mode, setup, profile or strategy identity.

## Python verification and artifacts

Python validates the PHP metadata and conversion rows with frozen, strict
Pydantic contracts. The serializer receives the verified candle dataset, raw
public execution tape, raw public book tape, metadata rows and conversion rows.
It then:

- rebinds every row to the same dataset/source/network/venue;
- requires every referenced source record to exist in exactly one raw tape;
- requires every referenced metadata record to exist and precede the source
  event position;
- rechecks availability, symbol, units and the venue conversion formula using
  `Decimal`;
- rejects duplicate source references, missing conversions, extra conversions,
  ordering drift, tampering and bounded-artifact overflow;
- writes canonical manifest, metadata NDJSON and conversion NDJSON bytes with
  checksums covering both content sections and the raw tape checksums.

When a supplied raw tape contains public observations, the conversion artifact
must cover every one of them. A legacy raw tape remains readable but cannot be
promoted to conversion evidence without dated metadata. A tape with no public
trade or book records is not silently represented as conversion evidence.

## Failure and security model

The lot fails closed on missing or late metadata, mismatched event positions,
wrong source IDs, non-canonical decimals, zero or negative quantities,
unsupported metadata versions, formula mismatch, source-tape substitution,
duplicate references, unknown fields or artifact bounds.

It performs no HTTP call and adds no credential, account, private endpoint,
order, exchange mutation or real-mainnet execution path. Mainnet inputs remain
public and read-only.

## Testing

PHP tests prove metadata projection, exact OKX and Hyperliquid arithmetic,
event-order selection, equal-time ordering, metadata supersession, missing
metadata rejection, channel shapes, fixture bytes and tamper rejection.

Python tests prove strict cross-runtime parsing, complete trade/book coverage,
exact independent formula verification, source/metadata reference integrity,
look-ahead rejection, duplicate and ordering rejection, source-tape binding,
artifact bounds and deterministic bytes. Broader Paper, Python, PHPStan and
strict MkDocs checks protect the surrounding contracts.

## Explicit non-goals

This lot does not compute notional, fees or slippage; aggregate book depth;
infer executable size; rank queue position; model maker/taker selection;
produce full or partial fills; or make a modern mode executable. Those require
separately versioned execution-policy evidence and remain later #191 work.
