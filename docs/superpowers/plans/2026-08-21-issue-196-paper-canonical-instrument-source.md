# #196 — Canonical Paper instrument source implementation

1. Add failing tests for OKX public leverage capture and exact v2 payload
   validation.
2. Extend normalized backtest instrument metadata with exact sizing fields and
   add a standalone authenticated-prefix adapter.
3. Add failing Paper source tests for current-trigger binding, no-lookahead,
   canonical ordering, lineage, v1 incompleteness, malformed data and
   Hyperliquid incompleteness.
4. Implement `PaperCanonicalInstrumentSource` without wiring it into the
   coordinator.
5. Run focused and adjacent PHPUnit suites, targeted PHPStan, syntax/config
   lint and diff checks.
6. Publish a PR, request Codex review, resolve actionable feedback, require all
   checks green, then merge and record the #196 milestone.

