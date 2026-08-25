# #132 OKX Live Indicator Warmup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Emit 1,000 proven one-hour candles per symbol during the existing OKX public live warmup so canonical 4h indicators become available in the same live dataset.

**Architecture:** A private bounded pagination helper extends only the `1H` initial REST warmup. It validates progress, deduplicates exact rows, pins the observed confirmed frontier, and returns an aligned 1,000-row base plus its zero-to-three-row confirmed suffix to the existing normalization/acknowledgement pipeline.

**Tech Stack:** PHP 8.4, existing OKX public REST client, PHPUnit, PHPStan.

---

### Task 1: Successful bounded hourly pagination

**Files:**
- Modify: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`
- Modify: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php`

- [ ] Add a failing source test whose current page and three older pages contain exactly 1,000 confirmed contiguous 1H candles; assert chronological emission and exact exclusive cursors.
- [ ] Run the focused PHPUnit test and confirm it fails with only the current 300 rows emitted.
- [ ] Implement a private `initialCandleRows()` helper with a 1,000-row 1H target and four-page history budget; retain existing single-page behavior for other bars.
- [ ] Re-run the focused test and confirm it passes.

### Task 2: Integrity and restart boundaries

**Files:**
- Modify: `trading-app/tests/Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceTest.php`
- Modify: `trading-app/src/Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php`

- [ ] Add failing tests for exact duplicate acceptance, conflicting duplicate rejection, empty/non-progressing page rejection, hourly grid gap rejection and insufficient rows after four pages.
- [ ] Add a failing restart test that acknowledges part of the 1H warmup, recreates the source from disk, and proves byte-identical completion without duplicate recorded events.
- [ ] Implement minimal row identity, progress, deduplication, grid and budget validation using stable existing integrity reasons.
- [ ] Re-run the focused tests and the complete OKX live source suite.

### Task 3: Verification and delivery

**Files:**
- Modify: `docs/handbook/runbooks/paper-market-replay.md`

- [ ] Document that new OKX live captures include the 1,000-hour strategy warmup and that existing terminal datasets are unchanged.
- [ ] Run OKX live source, capture/replay equality, recorder/capture and indicator-window PHPUnit suites.
- [ ] Run PHPStan on the changed source, documentation lint and `git diff --check`.
- [ ] Commit, push, open a draft PR, move it ready after local review, request one Codex review, resolve actionable feedback, and merge when final CI is green.
