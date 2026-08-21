# Issue #196 canonical Paper indicator dataset binding implementation plan

1. Add failing PHP tests for the Python-compatible checksum graph, strict
   source/candle validation, duplicate rejection and deterministic ordering.
2. Add a checked-in cross-runtime golden vector produced by the canonical
   Python dataset builder and serializer.
3. Implement the pure Paper indicator dataset-binding builder without runtime
   Python or filesystem dependencies.
4. Add failing projection-source tests for windows-to-binding-to-projector
   composition, replay-clock evaluation time, missing history and exact source
   provenance.
5. Implement the unwired canonical projection source using the existing window
   source and `CanonicalIndicatorProjectorInterface`.
6. Run focused and adjacent Paper/backtesting tests, targeted PHPStan,
   container lint and diff checks.
7. Publish a ready PR, request Codex review, address actionable feedback, and
   merge only the exact reviewed green SHA.
