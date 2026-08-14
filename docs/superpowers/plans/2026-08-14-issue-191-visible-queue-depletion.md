# #191 Visible queue depletion implementation plan

> Execute inline with the `executing-plans` workflow. Do not delegate: the user
> requested quota-efficient single-agent work after the previous merge.

**Goal:** Add a deterministic, authenticated public-tape maker partial-fill
model without claiming certified fills.

**Architecture:** A pure Python backtesting module consumes the existing strict
canonical plan, dataset descriptor and three verified public tapes. It validates
their lineage, selects one fresh pre-live L1 record, then applies an immutable
`visible-queue-depletion.v1` state machine over contra-side public trades using
converted base quantities and exact `Decimal` arithmetic.

**Tech stack:** Python 3, Pydantic v2, frozen dataclasses/models, pytest, Ruff,
MyPy.

---

### Task 1: Freeze strict result and policy contracts

**Files:**
- Create: `python-orchestrator/app/backtesting/visible_queue_depletion.py`
- Create: `python-orchestrator/tests/test_backtesting_visible_queue_depletion.py`

1. Write failing tests for policy identity, immutable canonical output, explicit
   non-certification flags, deterministic result hashing and unsupported taker
   rejection.
2. Run the focused test and confirm RED.
3. Implement the minimum strict models/helpers and make the tests GREEN.

### Task 2: Bind dataset and authenticated tapes

**Files:**
- Modify: `python-orchestrator/app/backtesting/public_quantity_conversion_tape.py`
- Modify: `python-orchestrator/app/backtesting/visible_queue_depletion.py`
- Modify: `python-orchestrator/tests/test_backtesting_public_quantity_conversion_tape.py`
- Modify: `python-orchestrator/tests/test_backtesting_visible_queue_depletion.py`

1. Write failing tests that require the verified conversion tape to expose its
   validated dataset/source/tape identities and reject mismatched plan, dataset,
   raw tapes or conversion lineage.
2. Run focused tests and confirm RED.
3. Add immutable identity fields populated only after existing strict artifact
   verification; validate all model inputs fail closed.
4. Run focused tests and confirm GREEN.

### Task 3: Model initial queue and partial fills

**Files:**
- Modify: `python-orchestrator/app/backtesting/visible_queue_depletion.py`
- Modify: `python-orchestrator/tests/test_backtesting_visible_queue_depletion.py`

1. Write failing long and short tests for fresh pre-live book selection,
   converted visible queue, same-price depletion, partial fills, level-through
   completion, same-side/wrong-price ignores and stable tape ordering.
2. Add fail-closed tests for stale/missing books, missing conversions,
   non-top-of-book entry and invalid deadlines.
3. Run focused tests and confirm RED.
4. Implement the exact-decimal deterministic state machine and canonical trace.
5. Run focused tests and confirm GREEN.

### Task 4: Harden temporal and tamper boundaries

**Files:**
- Modify: `python-orchestrator/tests/test_backtesting_visible_queue_depletion.py`
- Modify: `docs/handbook/backtesting.md` (or the closest existing #191 handbook)

1. Add adversarial tests proving no pre-live or post-deadline lookahead, no
   candle inference, immutable results and stable result hashes.
2. Document the non-certification boundary and deferred taker fallback.
3. Run focused tests, Ruff and MyPy.

### Task 5: Verify and deliver

1. Run the full Python suite and repository-scoped static/config checks.
2. Inspect the diff for placeholders, accidental scope expansion and mainnet
   write paths.
3. Commit intentionally, push, open a ready PR linked to #191, request Codex
   review once and wait for CI/review.
4. Treat a Codex 👍 as approval. Address only actionable feedback; merge after
   green CI and no blocking thread, then update #191 and continue to the next
   global Paper #132 lot.
