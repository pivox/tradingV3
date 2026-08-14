# #191 Canonical plan rehydration design

## Scope

Add one strict PHP intake boundary for a canonical OrderPlan wire payload. The
partial-fill cost authority will use this boundary later instead of trusting
cost fields selected by Python.

## Contract

`CanonicalOrderPlan::fromArray()` accepts only the exact ordered shape emitted
by `toArray()`. Nullable wire fields may be absent only where `toArray()` omits
them; they are restored at their canonical hash positions. Targets, lists,
scalars and UTC microsecond timestamps are type checked without coercion.

The boundary then reconstructs the immutable domain objects, verifies the
original plan hash, and reruns `CanonicalOrderPlanValidator` at the plan's
creation instant. A self-hashed payload with invalid arithmetic, lineage,
identity, deadlines, costs, stop/target geometry or fallback policy therefore
still fails closed.

This lot adds no settlement formula, exchange call, private channel or
mainnet execution.

