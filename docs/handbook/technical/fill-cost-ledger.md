# Fill and Cost Ledger v1

This document describes the first persistent ledger for exchange-neutral fills and costs. It is part of #190 and depends on the certified net PnL contract delivered before it.

## Scope

The ledger persists normalized fill and cost facts in `fill_cost_ledger`. It is append-only for normal ingestion: a repeated event with the same idempotency key is treated as a replay, while the same key with a different payload is rejected as a conflict.

This first version uses Fake/Paper as the deterministic reference source. Real exchanges may produce normalized `ExchangeFillReceived` events, but this PR does not certify Bitmart, OKX, or Hyperliquid net PnL.

## Stored Fields

Each row stores the exact identifiers available at ingestion time:

- `internal_trade_id`
- `internal_position_id`
- `position_id`
- `exchange`
- `market_type`
- `symbol`
- `side`
- `fill_id`
- `exchange_fill_id`
- `exchange_order_id`
- `client_order_id`
- `order_intent_id`
- `fill_role`
- `liquidity_role`
- `price`
- `quantity`
- `notional`
- `fee_amount`
- `fee_currency`
- `fee_usdt`
- `funding_usdt`
- `spread_cost_usdt`
- `slippage_cost_usdt`
- `borrow_cost_usdt`
- `liquidation_fee_usdt`
- `occurred_at`
- `source`
- `source_version`
- `quality_flags`
- `raw_reference`

Unknown costs remain `NULL`. They are not converted to zero unless the source explicitly reports zero or a deterministic fixture states that the cost is not applicable.

## Idempotency

The exact idempotency key is:

```text
{exchange}:{market_type}:exchange_fill:{exchange_fill_id}
```

when `exchange_fill_id` is present.

If no exchange fill ID exists, the fallback is:

```text
{exchange}:{market_type}:internal:{deterministic_fill_id}
```

The deterministic fill ID is derived from venue, market type, symbol, order ID, client order ID, fill timestamp, quantity, price, order side, and position side. It never uses symbol and timestamp alone.

Replays with the same payload hash are ignored. A different payload for the same idempotency key raises a conflict and does not update the existing row.

## Lineage Resolution

`FillCostLedgerIngestionService` resolves lineage in this order:

1. `internal_trade_id` from normalized fill metadata or event payload;
2. exact `client_order_id` in the same `exchange + market_type`;
3. exact `exchange_order_id` in the same `exchange + market_type`;
4. exact `position_id` in the same `exchange + market_type` when present.

There is no fallback by symbol alone and no timestamp-window matching. If no lineage is found, the row is still persisted with `quality_flags=["missing_lineage"]`.

## Fee Conversion

USDT fees are copied to `fee_usdt`.

A non-USDT fee is converted only when the event metadata or payload includes an explicit conversion:

```json
{"fee_conversion": {"currency": "BNB", "usdt_rate": 600.0}}
```

If the conversion is missing, `fee_usdt` remains `NULL` and `fee_conversion_missing` is added to `quality_flags`.

## Raw Reference Redaction

`raw_reference` is a compact reference, not a raw provider payload. It may include IDs such as `exchange_fill_id`, `exchange_order_id`, `client_order_id`, or a fixture event ID. Sensitive keys such as `api_key`, `secret`, `token`, `password`, `memo`, and `credentials` are removed.
