# #191 Canonical plan rehydration plan

1. Add failing round-trip and mutation tests for exact wire order, scalar and
   timestamp types, target shape, hash tampering and self-hashed bad arithmetic.
2. Implement strict `CanonicalOrderPlan::fromArray()` reconstruction and run
   the existing full validator at `createdAt`.
3. Verify focused/full TradingCore suites, PHPStan, container/docs lint, then
   deliver the atomic prerequisite PR before partial-fill settlement.

