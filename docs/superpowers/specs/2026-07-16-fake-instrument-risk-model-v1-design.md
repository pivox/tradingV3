# Fake Instrument Risk Model v1 Design

## Scope

This lot completes the metadata, precision, balance, margin, and leverage slice of issue #196 for the deterministic Fake/Paper exchange. It does not implement daily loss caps, liquidation, funding, non-zero slippage, or live exchange writes.

## Instrument catalog

`FakeInstrumentCatalog` owns a versioned, deterministic list of perpetual instruments. Each `FakeInstrument` exposes the canonical symbol, market and asset currencies, price tick, quantity step, minimum quantity, minimum notional, contract size, maximum leverage, maintenance margin rate, and allowed order types. Unknown symbols and unsupported market types are never inferred.

The first fixture set contains `BTCUSDT` and `ETHUSDT`. Decimal rules are represented as strings and checked with Brick Math so validation never depends on binary-float modulo or implicit rounding.

## Order validation

`FakeOrderValidator` validates an incoming `PlaceOrderRequest` before the matching engine allocates an order ID. It returns one stable rejection reason from this ordered set:

1. `instrument_unknown`
2. `market_type_not_supported`
3. `order_type_not_supported`
4. `price_not_quantized`
5. `stop_price_not_quantized`
6. `quantity_not_quantized`
7. `quantity_below_minimum`
8. `notional_below_minimum`
9. `leverage_above_maximum`
10. `margin_mode_not_supported`
11. `insufficient_balance`

Rejected requests are persisted as rejected orders and emit `order.rejected`; input values are never rounded. Market order notional uses the deterministic top of book. Reduce-only and protection orders do not reserve new initial margin but still obey instrument precision.

## Balance and margin

The persisted USDT balance remains the account total. Used margin is derived from active non-reduce-only orders plus open positions; available balance is `max(total - used, 0)`. Derivation avoids a second mutable reservation counter that could diverge across fill, cancellation, or restart.

Initial margin is `notional / leverage`. Maintenance margin is exposed from the instrument rate. A new entry is rejected when its initial margin exceeds current available balance. Existing position margin is already persisted and survives recovery.

## Leverage

`setLeverage()` validates symbol, margin mode, and exchange cap, then stores a per-symbol setting in the versioned Fake state. Existing version-1 state files without this additive field restore with catalog defaults. A request-provided leverage remains supported because the adapter declares that a separate leverage call is not required; it must still respect the cap.

## Provider and readiness behavior

The legacy Fake contract and account providers become read-only views over the same catalog and state instead of returning empty success. Unsupported mutating legacy operations continue to fail explicitly unless they delegate to the modern adapter. Runtime metadata reports concrete catalog and precision model versions, allowing the readiness check to stop reporting missing metadata while preserving all market-source, clock, persistence, and live-write guards.

## Tests and safety

Tests cover catalog shape, every validation rejection, no risk-changing rounding, insufficient balance, margin release after cancel, leverage persistence across restart, provider reads, runtime readiness metadata, and unchanged no-network/no-live guarantees. Golden scenarios 6-8 move from unsupported to executable only after their behavior is proven end to end.
