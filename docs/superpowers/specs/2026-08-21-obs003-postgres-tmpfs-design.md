# OBS-003 PostgreSQL tmpfs design

## Context

The required OBS-003 workflow runs several write-heavy PostgreSQL integration suites before `PositionTradeAnalysisViewTest`. On PR #383, two hosted-runner attempts stalled in that final suite. PostgreSQL reported checkpoints lasting 167 and 270 seconds, while the unchanged 55-test suite passed locally in 59.5 seconds with 693 assertions.

## Decision

Mount only the PostgreSQL service data directory, `/var/lib/postgresql/data`, as a Docker `tmpfs` in the OBS-003 job. The database is created from scratch for every job and is used exclusively for tests, so it has no durability requirement. All schemas, migrations, test order, assertions, and fail-on-skipped gates remain unchanged.

## Rejected alternatives

- Moving the view suite earlier would hide the accumulated storage pressure without fixing it.
- Splitting the suite into another job would duplicate checkout and dependency installation and increase CI cost.
- Rerunning the unchanged workflow again would not make the gate more deterministic.

## Safety and verification

The mount is scoped to the PostgreSQL 16 service container in `.github/workflows/obs003-trading.yml`; it cannot affect runtime, production, or persistent developer databases. Verification consists of workflow syntax inspection, the unchanged local PostgreSQL integration suite, and the exact GitHub Actions check produced by the change.
