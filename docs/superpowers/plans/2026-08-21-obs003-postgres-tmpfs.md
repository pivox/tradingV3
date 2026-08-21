# OBS-003 PostgreSQL tmpfs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove hosted-runner Docker storage latency from the required OBS-003 PostgreSQL integration gate without weakening any test.

**Architecture:** Keep the existing PostgreSQL 16 service and all test commands intact. Add one Docker service option that stores only the disposable PostgreSQL data directory in memory for the lifetime of the CI job.

**Tech Stack:** GitHub Actions, Docker service containers, PostgreSQL 16, PHPUnit 11.

---

### Task 1: Put the disposable PostgreSQL service data on tmpfs

**Files:**
- Modify: `.github/workflows/obs003-trading.yml`

- [ ] **Step 1: Preserve the failing-run evidence**

Record that OBS-003 run `32447834693`, attempts 1 and 2, stalled in `PostgreSQL view test — must run, never skipped`; attempt 2 PostgreSQL logs contain checkpoints of 167 and 270 seconds. Confirm the unchanged local suite with:

```bash
DATABASE_URL='postgresql://trading@127.0.0.1:55439/trading_app_test?serverVersion=14&charset=utf8' \
  vendor/bin/phpunit --bootstrap vendor/autoload.php --fail-on-skipped \
  tests/Trading/View/PositionTradeAnalysisViewTest.php
```

Expected baseline: `OK (55 tests, 693 assertions)`.

- [ ] **Step 2: Add the minimal service option**

Extend the existing PostgreSQL service options with:

```yaml
--tmpfs /var/lib/postgresql/data:rw
```

Add a concise comment explaining that the database is disposable and the mount avoids hosted-runner overlay-storage checkpoint stalls.

- [ ] **Step 3: Verify the workflow diff**

Run:

```bash
git diff --check
git diff -- .github/workflows/obs003-trading.yml
```

Expected: no whitespace errors and only the documented tmpfs option is added to the workflow.

- [ ] **Step 4: Verify in GitHub Actions**

Push the branch, open a focused PR, request Codex review, and wait for `OBS-003 Trading (PHP + PostgreSQL view)` on the exact head SHA.

Expected: PostgreSQL initializes successfully, all 55 view tests finish, and every required check is green before merge.
