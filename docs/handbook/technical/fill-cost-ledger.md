# Fill and Cost Ledger v1

This document describes the first persistent ledger for exchange-neutral fills and costs. It is part of #190 and depends on the certified net PnL contract delivered before it.

## Scope

The ledger persists normalized fill and cost facts in `fill_cost_ledger`. It is append-only for normal ingestion: a repeated event with the same idempotency key is treated as a replay, while the same key with a different payload is rejected as a conflict.

The first version uses Fake/Paper as the deterministic reference source. Bitmart can now project audited REST trade fills into the ledger; the Bitmart-specific coverage and remaining certification limits are documented in `docs/handbook/technical/bitmart-ledger-ingestion.md`. This does not certify Bitmart, OKX, or Hyperliquid net PnL.

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

Replays with the same canonical fill/cost payload hash are ignored. Mutable projection source and late lineage enrichment do not change that canonical hash. If both the existing row and the replay provide non-null lineage identifiers and they differ, ingestion raises a conflict instead of silently moving the fill to another trade. A different payload for the same idempotency key raises a conflict and does not update the existing row.

## Lineage Resolution

`FillCostLedgerIngestionService` resolves lineage in this order:

1. `internal_trade_id` from normalized fill metadata or event payload;
2. exact `client_order_id` in the same `exchange + market_type`;
3. exact `exchange_order_id` in the same `exchange + market_type`;
4. exact `position_id` in the same `exchange + market_type` when present.

When a higher-priority identifier is present but unmatched, the resolver continues to the next exact identifier. There is no fallback by symbol alone and no timestamp-window matching. If no lineage is found, the row is still persisted with `quality_flags=["missing_lineage"]`.

## Quantity Aggregation

`FillQuantityAggregationService` builds the deterministic quantity state for one logical trade and one venue. The input scope is exactly:

```text
internal_trade_id + exchange + market_type
```

It does not match by symbol, timestamp, profile, or "next close" ordering. The repository query orders by `occurred_at, id`, and the service recomputes quantities from persisted ledger facts.

The aggregation exposes:

- `entry_first_fill_at`
- `entry_last_fill_at`
- `entry_qty`
- `entry_vwap`
- `exit_first_fill_at`
- `exit_last_fill_at`
- `exit_qty`
- `exit_vwap`
- `remaining_qty`
- `position_fully_closed`
- `quantity_status`
- `quantity_quality_flags`

`entry_vwap` and `exit_vwap` are quantity-weighted from fill price and quantity. Funding and cost-only adjustment rows contribute to cost aggregates but not to entry or exit quantity.

The default close tolerance is `0.00000001`. A position is fully closed only when `remaining_qty` is within that tolerance and no blocking quantity flag is present.

Current statuses:

- `complete`: entry and exit quantities are present and the residual quantity is zero within tolerance.
- `open_position`: entry quantity exists but exit quantity is absent or smaller than entry quantity.
- `missing_entry_fill`: no usable entry fill exists.
- `quantity_mismatch`: exit quantity exceeds entry quantity.
- `fill_conflict`: the same venue fill identifier appears with a different quantitative payload.

Quality flags keep non-blocking audit details visible. Exact duplicate fills are ignored and flagged with `duplicate_fill_ignored`; cancelled/corrected/voided rows are ignored and flagged with `cancelled_fill_ignored`. A conflicting duplicate is never resolved arbitrarily and blocks net PnL certification.

PostgreSQL fixture coverage for manual or CI-backed checks lives in `tests/fixtures/fill_quantity_aggregation_postgres.sql`. It seeds a complete partial-entry/TP1/trailing case, an open partial-exit case, and a conflicting duplicate venue fill case against the real `fill_cost_ledger` schema.

## Fee Conversion

`fee_amount` preserves the provider-reported amount for audit, including provider sign conventions. `fee_usdt` stores the normalized USDT cost used by certification and is non-negative; provider-signed charged fees such as `-0.02 USDT` are stored as `fee_amount=-0.020000000000` and `fee_usdt=0.020000000000`.

A non-USDT fee is converted only when the event metadata or payload includes an explicit conversion:

```json
{"fee_conversion": {"currency": "BNB", "usdt_rate": 600.0}}
```

The conversion rate must be finite and strictly positive. If the conversion is missing, `fee_usdt` remains `NULL` and `fee_conversion_missing` is added to `quality_flags`. If the conversion rate is invalid, `fee_usdt` remains `NULL` and `fee_conversion_invalid` is added.

## Raw Reference Redaction

`raw_reference` is a compact reference, not a raw provider payload. It may include IDs such as `exchange_fill_id`, `exchange_order_id`, `client_order_id`, or a fixture event ID. Sensitive key variants containing markers such as `apikey`, `secret`, `token`, `password`, `memo`, or `credential` are removed recursively.
