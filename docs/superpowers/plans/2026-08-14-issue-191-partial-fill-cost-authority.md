# #191 Partial-fill cost authority plan

1. Add failing PHP tests for target/stop settlement, exact hashes, partial/full
   quantities, overfill, target mismatch, shape and plan tampering.
2. Implement the `BigDecimal` settlement over a strictly rehydrated canonical
   plan and expose a bounded Symfony stdin/stdout command.
3. Test command failures, deterministic bytes, full TradingCore, PHPStan,
   container/docs lint, then deliver the authority as an atomic PR.

