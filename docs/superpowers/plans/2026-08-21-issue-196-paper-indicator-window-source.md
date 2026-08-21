# Issue #196 canonical Paper indicator-window source implementation plan

1. Add failing adapter tests proving that an authenticated replay prefix uses
   the exact verified-dataset candle normalization and rejects duplicate or
   forged source provenance.
2. Add `PaperBacktestDatasetAdapter::adaptCandleEvents()` by extracting no new
   venue-normalization rules.
3. Add failing window-source tests for exact 250-candle history, temporal
   availability, discontinuities, current-trigger identity and the 1,000-hour
   source required by derived `4h` projection.
4. Implement `PaperCanonicalIndicatorWindowSource` with strict cell scope,
   mandatory replay clock, canonical timeframe order and fail-closed history
   validation.
5. Keep the source unwired until deterministic dataset checksums and the rest of
   the canonical evidence graph are available.
6. Run focused tests, the relevant Paper strategy/backtesting suites, targeted
   PHPStan, container lint and diff checks.
7. Commit, publish a ready PR, request Codex review, address actionable
   feedback and merge only the exact green reviewed SHA.
