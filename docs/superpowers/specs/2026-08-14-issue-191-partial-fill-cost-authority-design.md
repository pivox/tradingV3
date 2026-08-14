# #191 Partial-fill cost authority design

## Scope

Create a bounded PHP authority that settles the planned stop or one canonical
target for an actual filled base-asset quantity. It consumes the complete plan
wire through `CanonicalOrderPlan::fromArray()` and never trusts cost fields
selected or calculated by Python.

## Request and result

`canonical-partial-fill-cost-request.v1` binds the dataset, complete plan,
visible-queue result/trace hashes, exact filled base quantity, terminal kind
and target id. The authority rejects zero, overfill, unknown targets, malformed
shape and every plan that cannot be rehydrated and revalidated.

PHP recalculates entry/exit notionals, gross PnL, fees, spread, slippage,
adverse planned funding, stop risk and net PnL directly from the validated plan
prices/rates and the filled base quantity using `BigDecimal`. Because every
component is linear in that quantity, the validated target net R remains
invariant and is preserved from the plan. No full-plan cost is prorated by
Python.

The immutable result exposes exact decimal strings, request/result hashes,
`costs_are_certified=false`, `cost_evidence=canonical_plan_partial_quantity`,
and `result_is_live_proof=false`. Historical funding replacement remains in
the existing timestamped authority and will be combined by the later Python
bridge/runtime lot.

The command is local stdin/stdout only, bounded by the strict JSON decoder. It
adds no venue call, credential, fallback or mainnet write path.
