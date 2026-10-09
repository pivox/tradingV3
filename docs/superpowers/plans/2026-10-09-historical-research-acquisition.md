# Historical Research Acquisition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Acquire, retain and verify ten-pair public Binance USD-M candle/funding history for the approved research campaign, without trading or fabricating missing coverage.

**Architecture:** Independent research data adapter with pure planning/validation, bounded public transport, private atomic storage and a resumable CLI. It does not pretend to be the existing Paper dataset or modify production strategy contracts. The full campaign is specified in `docs/superpowers/specs/2026-10-09-historical-research-campaign-design.md`; this PR implements phase A only, with B/C/D still required afterward.

**Tech Stack:** Python 3.11+, standard library (`csv`, `zipfile`, `hashlib`, `decimal`, `datetime`, `pathlib`, `json`), existing `httpx`, pytest.

## Files and interfaces

- Create `python-orchestrator/app/backtesting/research/__init__.py`.
- Create `python-orchestrator/app/backtesting/research/binance_history.py`: fixed universe, UTC range/archive planning, bounded parsing and coverage validation.
- Create `python-orchestrator/app/backtesting/research/acquire.py`: HTTP acquisition, checksums, private resume/manifest and `python -m` entry point.
- Create `python-orchestrator/tests/test_research_binance_history.py` and `test_research_acquire.py`.
- Add a short usage/limitations section to the campaign design after implementation.

No other application module, runtime config, `.env`, lockfile or existing data
is in scope. If two modules become unclear or exceed approximately 500 lines
each, propose a focused decomposition before expanding the scope.

## Task 1 — Plans and archive evidence (TDD)

- [ ] Write a failing test for a frozen half-open interval. A complete January
  plus two days in February yields one monthly and two daily kline sources.

```python
from datetime import datetime, timezone
from app.backtesting.research.binance_history import plan_archives

def test_monthly_then_daily():
    start = datetime(2023, 1, 1, tzinfo=timezone.utc)
    end = datetime(2023, 2, 3, tzinfo=timezone.utc)
    sources = plan_archives("BTCUSDT", start, end, kind="klines")
    assert [s.filename for s in sources] == [
        "BTCUSDT-1m-2023-01.zip",
        "BTCUSDT-1m-2023-02-01.zip",
        "BTCUSDT-1m-2023-02-02.zip",
    ]
    assert all(s.url.startswith("https://data.binance.vision/data/futures/um/") for s in sources)
```

- [ ] Run the test and observe missing implementation. Implement exact UTC
  partitioning; reject naive/reversed/non-minute-aligned ranges and unknown symbols.
  `ArchiveSource` is an immutable dataclass with `symbol`, `kind`, `start`, `end`,
  `filename`, `url`, `checksum_url`; timestamps are aware UTC. `kind="funding"`
  plans monthly funding archives intersecting the range, not nonexistent daily
  funding files. Clip selected records to the requested half-open interval.
- [ ] Write fixtures in memory using `zipfile.ZipFile(BytesIO(), "w")` and
  actual official column layouts. Add tests for valid header/headerless klines,
  missing minute, duplicate/out-of-order row, incomplete final bar, invalid OHLC,
  non-finite decimal, wrong millisecond timestamp and ZIP traversal/multiple files.
- [ ] Implement streaming CSV validation with `Decimal`, UTC millisecond times,
  60,000-ms alignment, close=`open+59,999`, nonnegative volumes/trade counts,
  positive prices and `low <= open,close <= high`. Do not fill gaps. Return a
  serializable evidence object with `row_count`, `first_open_ms`, `last_close_ms`,
  requested/observed coverage and explicit gap intervals/status.
- [ ] Funding validation retains `calc_time`, positive finite interval hours,
  finite signed rate; rejects duplicate/backwards times. Do not round timestamp
  jitter or infer complete coverage merely from a row count.
- [ ] Run `python -m pytest tests/test_research_binance_history.py`; green before
  transport/storage implementation.

## Task 2 — Bounded acquisition and safe resume (TDD)

- [ ] Write checksum tests first, including the exact expected filename.

```python
import hashlib
import pytest
from app.backtesting.research.acquire import verify_checksum

def test_checksum_binds_filename_and_bytes():
    raw = b"archive bytes"
    digest = hashlib.sha256(raw).hexdigest()
    assert verify_checksum(raw, f"{digest}  sample.zip\n", "sample.zip") == digest
    with pytest.raises(ValueError):
        verify_checksum(raw, f"{digest}  different.zip\n", "sample.zip")
    with pytest.raises(ValueError):
        verify_checksum(b"changed", f"{digest}  sample.zip\n", "sample.zip")
```

- [ ] Implement digest/filename verification before ZIP parsing; only fixed
  archive and REST origins are allowed. No supplied credentials, configurable
  arbitrary URLs, private API calls, shell calls or redirects to unknown hosts.
- [ ] Add `httpx.MockTransport` tests for successful ZIP+checksum, archive 404,
  429/5xx bounded retry, fatal HTTP failure, invalid checksum and size-limit abort.
  Transport retries at most three attempts, timeouts 30 seconds/request. Respect
  bounded Retry-After; 403/451 must not be bypassed or routed to another region.
- [ ] Store verified raw files and checksum sidecars under an explicit root
  outside Git. Refuse symlinks/path escapes; private dirs/files (0700/0600).
  Use a same-directory temporary file plus atomic publication. Never overwrite
  an existing verified artifact with different bytes.
- [ ] Add resume tests: second identical run makes no archive download after
  rechecking local bytes; changed local artifact fails; incompatible manifest
  range/universe fails; a missing archive remains explicit and retryable.
- [ ] Manifest binds schema, venue=`binance`, market=`usds_m_perpetual`, interval,
  full universe, UTC start/exclusive end, fetched URL/hash/time/size, evidence
  type, quality and per-symbol coverage/errors. Write progress after each source;
  partial failures retain successful evidence and return a nonzero partial status.
- [ ] Enforce a single writer, download/file expansion caps, total 20-GiB root
  cap and 20-GiB free-disk reserve (limits injectable lower in tests). Never delete
  user files to create space. A lock without a live owner is not run completion.
- [ ] Run targeted tests with coverage; exercise error/retry/resume branches.

## Task 3 — Current REST tail and operational entry point (TDD)

- [ ] Add tests for paginated current-tail klines, exact cutoff exclusion,
  unexpected symbol/shape, no-progress cursor, short/empty pages and transient
  errors. REST data remains raw with local SHA, explicitly distinct from an
  official archive checksum. A missing historical archive is not automatically
  repaired with current-market data.
- [ ] Implement a CLI with required `--root`, `--start`, `--end`, optional
  `--symbols` (default the fixed ten) and `--include-funding`. Current incomplete
  month uses daily archives, then REST only for genuinely uncovered recent tail.
  Each REST response is retained separately and the cursor moves by last open
  time plus one minute, never by wall clock. Available funding history may be
  separately paginated; report missing coverage honestly if absent.
- [ ] CLI test: fake public transport, short fixed range, valid manifest and
  exact counts; repeat without duplication; incomplete acquisition exits nonzero
  and never reports `complete`. Emit concise progress suitable for monitoring.
- [ ] Run baseline backtesting tests plus new tests:

```bash
python -m pytest tests/test_backtesting_contracts.py tests/test_backtesting_dataset.py tests/test_backtesting_backtrader_runtime.py tests/test_research_binance_history.py tests/test_research_acquire.py
```

## Task 4 — Verification, delivery and actual acquisition

- [ ] Run the full Python suite with the repository's 95% coverage gate (do not
  lower it); required PostgreSQL smoke is run by CI on its isolated service.
- [ ] Run strict MkDocs and whitespace checks. One consolidated Sol review;
  apply verified findings and rerun affected tests, with no second review request.
- [ ] Commit, open PR, merge only at the tested head after required CI succeeds.
- [ ] Run a one-month single-pair acquisition into a new private root; independently
  reconcile CSV row count, checksum and the known UTC coverage. Do not look at
  trading outcomes or optimize on 2026.
- [ ] Freeze the full campaign's exact UTC cutoff and run all ten pairs from 2023
  with funding. Keep the live process handle and progress/manifest paths in the
  campaign checkpoint. Never restart merely because a polling request times out.
- [ ] Report coverage, size, missing periods and next implementation boundary;
  proceed to phase B rather than declaring the full research request complete.

## Initial checkpoint

Worktree: `.worktrees/paper-zero-trade-diagnostic`; branch
`codex/historical-research-data`; base `8bff9361353931b2d993ddc5db6ddfe0fafa8cbc`.
Python environment: `/tmp/tradingv3-research.PIbeBK`. Root checkout and retained
Paper diagnostic databases/captures are outside this change.
