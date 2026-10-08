# Paper Plan Rejection Diagnostics Implementation Plan

> **For agentic workers:** This lot was implemented inline. The coordinator requested no subagents and no commit, push, or PR from this worker; that instruction does not restrict the coordinator's later integration work.

**Goal:** Add opt-in, redacted Paper plan refusal diagnostics to canonical Paper replay.

**Architecture:** A shared Paper-only NDJSON writer has explicit start/record/close lifecycle. The replay command starts it from an option; the existing expected-exception catch records safelisted fields passed from the evidence source.

**Tech Stack:** PHP 8, Symfony Console/DI, PHPUnit.

---

### Task 1: Writer and lifecycle

**Files:** Create `trading-app/src/Trading/Paper/Execution/Strategy/PaperPlanRejectionDiagnostics.php`; test `trading-app/tests/Trading/Paper/Execution/Strategy/PaperPlanRejectionDiagnosticsTest.php`.

- [x] Write tests for inactive no-op, exclusive owner-only creation, NDJSON output, close/reset, and IO failure. Source tests cover the safelist.
- [x] Run the focused PHPUnit test and observe feature-specific failure.
- [x] Implement a writer using exclusive create and explicit write checks. Run the focused test to green.

### Task 2: Catch and context

**Files:** Modify `PaperCanonicalOrderPlanEvidenceSource.php`, `PaperCanonicalStrategyEvidenceSource.php`, and `PaperCanonicalOrderPlanEvidenceSourceTest.php` in `trading-app`.

- [x] Add a rejection test asserting reason, event and cell correlation, numeric zone inputs, absent bounds, and unchanged null return. Add an unexpected-exception test.
- [x] Run focused tests red. Pass optional context into `build()` and record only within its expected catch. Run focused tests green.

### Task 3: CLI and replay integration

**Files:** Modify `trading-app/src/Command/PaperExecutionReplayCommand.php`, `trading-app/tests/Command/PaperExecutionReplayCommandTest.php`, and `trading-app/tests/Trading/Paper/Execution/PaperCanonicalModernReplayEndToEndTest.php`.

- [x] Test CLI option failure, exclusive file handling and reset after failure. Compare one accepted modern trigger consumed through the in-memory coordinator with diagnostic on/off; this is not a full CLI replay or a refused-trigger replay.
- [x] Run focused tests red. Wire the shared writer and explicit close/reset, then rerun green.
- [x] Document usage and private report handling in `docs/handbook/reports/paper-zero-trade-diagnostic-2026-10-08.md`.
- [x] Run targeted unit and modern E2E tests, lint changed PHP, inspect git diff, report exact results. The in-memory store's constant journal checksum is not a Doctrine checksum proof.

### Delivery verification

- One consolidated independent review found no blocking defect. Its test-scope clarifications were applied; no second review was requested.
- Targeted checks: 23 tests, 173 assertions; Symfony container and changed PHP syntax checked.
- Full `tests/Trading/Paper/Execution` with `--bootstrap vendor/autoload.php --fail-on-skipped`: 303 tests, 5,557 assertions, no skips, against the dedicated disposable `paper_plan_diag_paper_test` database. An initial run skipped 24 guarded PostgreSQL tests because its database name lacked the required `_paper_test` suffix; a correctly named fresh database resolved this without changing tests.
- `mkdocs build --strict` and whitespace checks pass. Real frozen-dataset diagnostic collection remains a subsequent execution step, not a result of the in-memory fixture tests.
