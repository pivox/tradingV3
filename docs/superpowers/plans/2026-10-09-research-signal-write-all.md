# Research signal complete-frame transport fix

> **For agentic workers:** use subagent-driven-development and test-driven-development. One consolidated review/correction cycle, then required CI and merge; no routine new go. Preserve the untracked guarded-holdout design proposal and exclude it from this change.

**Goal:** prevent a raw pipe short write from silently truncating B1 worker JSON frames.

**Architecture:** keep the existing producer thread, bounded reader and worker lifecycle. Send each encoded frame completely before flushing or counting it. Raw writes returning no progress fail explicitly. Retry an interrupted syscall only within the existing worker deadline. No strategy, data, protocol schema or holdout guard changes.

**Tech Stack:** Python standard-library binary pipes; pytest synthetic subprocess fixtures.

## Demonstrated cause and scope

An earlier private B1 attempt failed with worker `json_invalid`. A controlled
synthetic reproduction showed a 149816-byte frame returning a 65536-byte raw
write after STOP/CONT in three trials; the ordinary trial sent all bytes. The
historical syscall count was not recorded, so attribution to that exact event
remains an inference. Source JSON encoding was independently valid.

`signals.py` uses `Popen(bufsize=0)` then unchecked `proc.stdin.write(line)`.
`flush()` cannot send the suffix never passed successfully to the raw stream.
The fix is independently warranted by the raw write contract.

Implement only in this author checkout, never in the running runtime/execution
snapshots. Do not send signals to historical workers. A merged fix has a new B1
code identity and is for future runs; do not deploy it into the current campaign
or rewrite its existing provenance hashes. This fix is not checkpoint/resume.

## Task 1 — failing producer regression

**Files:** `python-orchestrator/tests/test_research_signals.py`.

- [ ] Wrap the actual stdin of the existing synthetic subprocess fixture with
  a test adapter that writes only a small positive prefix per call. Delegate
  flush/close and every other required stream operation to the real pipe.
- [ ] Call the existing `run_signals` with the fixture dataset and fake worker.
  Assert completion, exact input/candle/result counts and no malformed frame.
- [ ] Run this regression before production edits; record its expected failure
  due to lost JSON suffix, not a fixture/import error. No real PHP, data or OS
  STOP/CONT is needed. Keep existing subprocess timeout/cleanup bounded.

The core adapter behavior is:

```python
def write(self, raw):
    return self.inner.write(raw[:self.maximum_write])
```

## Task 2 — complete writes, bounded failures

**Files:** `python-orchestrator/app/backtesting/research/signals.py` and its test.

- [ ] Add a small internal helper used by the actual producer, equivalent to:

```python
def _write_all(stream, raw: bytes, deadline: float) -> None:
    remaining = memoryview(raw)
    while remaining:
        if time.monotonic() >= deadline:
            raise SignalError("worker stdin producer timeout")
        try:
            written = stream.write(remaining)
        except InterruptedError:
            continue
        if type(written) is not int or not 0 < written <= len(remaining):
            raise SignalError("worker stdin write made invalid progress")
        remaining = remaining[written:]
```

- [ ] Replace the unchecked write with `_write_all(proc.stdin, line, deadline)`;
  flush after the complete frame, and increment `sent` counters only afterward.
  Preserve existing producer error propagation and final worker termination.
- [ ] Add tests for short writes followed by success, repeated short writes,
  interrupted calls without duplicate bytes, zero/None/negative/oversized return
  values, broken pipe, deadline expiry and unchanged normal writes. Assert the
  actual reconstructed bytes, not just mocked call counts. Tests for helper
  behavior must fail before implementing each corresponding branch.
- [ ] The main reader still owns timeout/termination for a blocking raw syscall.
  Do not claim the helper can interrupt such a syscall by itself. Test failure
  paths leave no completed report or surviving synthetic worker.

## Task 3 — verification and delivery

- [ ] Run the full focused B1 suite and its orchestration compatibility tests:

```bash
python -m pytest --noconftest -p no:cacheprovider \
  tests/test_research_signals.py tests/test_research_orchestration.py
```

- [ ] Measure changed-module branch-enabled coverage without exclusions; retain
  the existing >=95% gate. Use an author-only environment; never install into
  the dependency environment used by live jobs. No application DB or exchange.
- [ ] Run `git diff --check`, inspect the exact diff, and commit only this plan,
  the transport change and its tests. Do not include the unrelated untracked
  holdout proposal.
- [ ] One independent reviewer covers both requirements and code quality.
  Apply concrete corrections, run relevant verification, create a targeted PR,
  wait for required CI, then merge without extra review rounds. Keep the running
  code snapshots unchanged throughout.

## Author verification — 2026-10-09

Implemented in isolated author checkout `codex/research-signal-write-all` only.
Author environment: `/tmp/research-write-all-venv.IvnN51`, with pinned
`pydantic==2.13.4`, `httpx==0.28.1`, `pytest==8.4.2`, `pytest-cov==7.1.0`.
The running runtime/execution checkouts and their dependency environment were
not touched. The unrelated untracked guarded-holdout proposal is excluded.

RED, before any production change, from `python-orchestrator`:

```bash
/tmp/research-write-all-venv.IvnN51/bin/python -m pytest --noconftest \
  -p no:cacheprovider tests/test_research_signals.py::test_producer_delivers_complete_frames_after_short_pipe_writes \
  -q --tb=short
```

Result: 1 failed, `worker exited nonzero: 1`. The real synthetic worker received
only 17-byte prefixes instead of complete JSON frames; the test's cleanup
assertions confirmed the child exited and stdin closed. Helper contract tests
were also run before implementation: 19 failed (missing helper and missing
invalid-progress errors), 1 passed (existing broken-pipe handling).

GREEN, using the same author interpreter:

```bash
python -m pytest --noconftest -p no:cacheprovider tests/test_research_signals.py \
  -k 'write_all or producer_write_failure or producer_delivers_complete' -q --tb=short
```

Result: 21 passed. Integration verifies the exact reconstructed frame bytes,
valid JSON, 3 input frames, 2 consumed candles, 0 evaluations, the result digest,
empty stderr and worker exit 0. Failure integrations verify failed reports,
absent finalized outputs, closed stdin and reaped workers. Unit cases cover
multiple short writes, normal writes, interrupted retries without duplication,
invalid progress (including bool/noninteger returns), broken pipe and deadline
checks before every attempt.

Full focused verification, from `python-orchestrator`:

```bash
COVERAGE_FILE=/tmp/research-write-all-venv.IvnN51/coverage \
  /tmp/research-write-all-venv.IvnN51/bin/python -m pytest --noconftest \
  -p no:cacheprovider tests/test_research_signals.py tests/test_research_orchestration.py \
  --cov=app.backtesting.research.signals --cov-branch --cov-config=/dev/null \
  --cov-report=term-missing --cov-fail-under=95
```

Result: 249 passed in 10.19s; changed-module coverage 96.15% (442 statements,
182 branches), no project coverage exclusions. `git diff --check` passed.
Author self-review confirms counters advance only after complete write and
flush, with existing reader/termination behavior retained. Independent parent
review and subsequent PR/CI/merge remain outside this author task.
