Trade Entry Execution Notes — Bitmart Futures V2

Scope: Execution payload, sizing, leverage, and logging decisions that must persist across sessions.

- Bitmart submit-order payload mapping
  - side must be numeric: 1=open_long (buy), 4=open_short (sell). Closes (2/3) reserved for position exits.
  - use size (string) for contract quantity; do not use quantity.
  - do not include leverage in submit-order payload; leverage is set via submit-leverage beforehand.

- Provider and execution integration
  - Provider maps generic inputs to Bitmart schema and posts to /contract/private/submit-order.
  - Execution passes the numeric side along to the provider (keeps entry semantics explicit).
  - Successful order responses are handled as OrderDto; non-null means submitted; null is treated as error.

- Risk sizing and leverage
  - position size = floor(risk_usdt / (distance * contract_size)).
  - notional_usdt = entry * contract_size * size.
  - leverage = ceil(notional_usdt / initial_margin_budget), clamped to exchange min/max.
  - initial_margin_usdt = notional_usdt / leverage.

- Logging improvements
  - Added positions_flow DEBUG: order_plan.budget_check with risk_usdt, entry, size, contract_size, notional_usdt, initial_margin_budget, initial_margin_usdt, available_usdt, leverage.
  - Added positions INFO: order_plan.model_ready now includes contract_size, notional_usdt, initial_margin_usdt.
  - Bitmart ERROR logs for submit-order include the JSON payload to aid diagnostics.

- Previous issue and resolution
  - Was sending side=buy/sell and quantity → Bitmart returned 40011 Invalid parameter.
  - Fixed by sending side=1/4 and size, and removing leverage from submit-order payload.

- Optional future enhancement
  - Add a DEBUG echo of submit-order payload on success (sanitized) to speed verification.

