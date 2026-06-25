# Bitmart Fill Ledger Ingestion v1

This document records the first Bitmart-specific ingestion path into the exchange-neutral `fill_cost_ledger`.

## Scope

Bitmart REST trade rows from `GET /contract/private/trades` are normalized into `ExchangeFillDto` through `BitmartExchangeAdapter::getFillsSnapshot()`. `ExchangeReconciliationService` can then publish `ExchangeFillReceived`, and the existing ledger ingestion persists those fills idempotently.

This PR does not certify Bitmart net PnL. It imports fill facts and preserves available fee fields, while incomplete or ambiguous costs remain visible through ledger quality flags.

## Source Matrix

| Source field | Endpoint/topic | Ledger mapping | Currency | Sign | Quality | Certification |
|---|---|---|---|---|---|---|
| `trade_id` / `id` | `/contract/private/trades` | `exchange_fill_id` | n/a | n/a | exact when present | usable for idempotency |
| `order_id` | `/contract/private/trades` | `exchange_order_id` | n/a | n/a | required | usable for lineage fallback |
| `client_order_id` | `/contract/private/trades` when present | `client_order_id` | n/a | n/a | optional | usable for lineage fallback |
| `position_id` / `exchange_position_id` | `/contract/private/trades` when present | metadata `position_id` | n/a | n/a | optional | usable for lineage fallback |
| `side` | `/contract/private/trades` | side + position side | n/a | n/a | exact for numeric Bitmart side codes `1..4`; string side has unknown position side | partial |
| `price` | `/contract/private/trades` | fill price | USDT quote implied by symbol | positive | required | fill fact only |
| `vol` / `size` / `deal_size` / `filled_size` | `/contract/private/trades` | fill quantity | contract/base quantity | positive | required | fill fact only |
| `fee` / `fees` / `commission` | `/contract/private/trades` | `fee_amount` | provider field | provider sign preserved | partial | `fee_usdt` only when currency is USDT or explicit conversion exists |
| `fee_currency` / variants | `/contract/private/trades` | `fee_currency` | source value | n/a | optional | missing currency leaves `fee_usdt=NULL` |
| `exec_type` / liquidity variants | `/contract/private/trades` | `liquidity_role` | n/a | n/a | maker/taker if explicit, otherwise unknown | informational |
| `create_time` / `trade_time` / timestamp variants | `/contract/private/trades` | `occurred_at` | n/a | n/a | source timestamp, seconds or milliseconds | ordering only |
| `flow_type=3` funding | `/contract/private/transaction-history` | not ingested in v1 | provider | not proven here | audited but inactive | not certified |
| `flow_type=2` realized PnL | `/contract/private/transaction-history` | not ingested in v1 | provider | not proven gross/net | audited but inactive | not certified |

## Runtime Behavior

- `BitmartExchangeAdapter` now implements `ExchangeRestSnapshotProviderInterface`.
- `getFillsSnapshot()` reads up to 200 recent trades from `BitmartAccountProvider::getTrades()` and maps only rows with symbol, order ID, positive quantity and positive price.
- `getOrdersSnapshot()` keeps the existing open-order snapshot behavior.
- `hasAuthoritativePositionSnapshot()` returns `false`: current Bitmart position reads can return an empty array on provider errors, so reconciliation must not close local positions from absence in this PR.
- Duplicate REST/WS projection is delegated to the ledger idempotency key: `bitmart:perpetual:exchange_fill:{trade_id}` when Bitmart provides `trade_id`, otherwise the ledger deterministic fallback.

## Limits

- Funding is intentionally not persisted from Bitmart transactions until the sign and exact linkage to an internal trade are demonstrated by fixtures or runtime audit.
- Non-USDT fees require an explicit conversion in event metadata; otherwise `fee_usdt` remains `NULL`.
- Missing lineage does not block persistence, but the row is not considered certified net PnL.
- No Bitmart live behavior, strategy, SL/TP, risk or guard logic changes are included.
