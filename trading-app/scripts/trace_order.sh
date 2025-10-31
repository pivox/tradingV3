#!/usr/bin/env bash
set -euo pipefail

SYMBOL="${1:-}"
DECISION_KEY="${2:-}"

if [[ -z "$SYMBOL" ]]; then
  echo "Usage: $0 <SYMBOL> [DECISION_KEY]"
  exit 1
fi

LOGDIR="$(cd "$(dirname "$0")/.." && pwd)/var/log"

echo "== Trace for symbol: $SYMBOL ${DECISION_KEY:+(decision_key=$DECISION_KEY)} =="

pattern="$SYMBOL"
if [[ -n "$DECISION_KEY" ]]; then
  pattern="$SYMBOL|$DECISION_KEY"
fi

echo
echo "-- Positions Flow (entry, sizing, budget, exec) --"
rg -n -S -e "$pattern" \
  -e "order_plan.entry_price_selected|order_plan.sizing|order_plan.budget_check|order_plan.entry_clamped_to_zone|build_order_plan.ready|execute_order_plan.start" \
  "$LOGDIR"/positions-flow-*.log || true

echo
echo "-- Positions (model_ready, submitted, errors) --"
rg -n -S -e "$pattern" \
  -e "order_plan.model_ready|trade_entry.order_submitted|execute_order_plan.failed|execution.order_error" \
  "$LOGDIR"/positions-*.log || true

echo
echo "-- Bitmart (submit-leverage, submit-order) --"
rg -n -S -e "$pattern" \
  -e "/contract/private/submit-leverage|/contract/private/submit-order|Request failed|Response|Request" \
  "$LOGDIR"/bitmart-*.log || true

echo
echo "-- Recent budget checks (last 20 lines) --"
rg -n -S "order_plan.budget_check" "$LOGDIR"/positions-flow-*.log | tail -n 20 || true

echo
echo "Hint: If Bitmart shows 40011 Invalid parameter, verify side (1 or 4) and size fields in the JSON payload above."

