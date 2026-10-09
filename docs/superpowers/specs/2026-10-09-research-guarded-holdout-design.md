# C2b — guarded final holdout: proposed implementation design

Status: implementation and single-review corrections committed; final coverage
and required CI pending.
Authority and durable-claim infrastructure is committed in `5968c181`, shared-engine
integration in `1f8161a0`, and controller/reporting in `bf72a7cf`.
The consolidated review corrections are committed in `662994f1`.
Full acceptance remains pending. This document is not authority
to read the 2026 strategy/profit
holdout. The overall campaign scope was approved in
`2026-10-09-historical-research-campaign-design.md`. The current training and
validation supervisor must continue unchanged. Its output is still pending.

## Purpose and scope

Finish the approved historical campaign through the fixed exclusive cutoff
`2026-10-09T06:00:00Z`, after a genuine selected-candidate freeze. Evaluate at
most the selected hypothesis and the prespecified baseline comparator, each
under the original baseline/adverse cost profiles. If the selected hypothesis
is baseline, there are two unique runs rather than four. A valid no-winner
freeze authorizes neither the selected hypothesis nor the comparator.

Source warmup starts `2025-11-01T00:00:00Z`; scoring starts
`2026-01-01T00:00:00Z`. Preserve the ten-symbol priority, signal AST, original
instrument/cost assumptions and all risk limits. This is hypothetical research,
not Paper certification or demo/testnet execution. No new parameter search,
threshold changes, source purchases, exchange writes or operational publication.

## Verified compatibility constraints

Inspected repository snapshot: `8ef8addd93a240ead5c01abbc67d053df44e8942`.

- `experiments.make_protocol()` hashes the currently imported `experiments.py`
  and `statistics.py`. `verify_freeze()` uses `_validate_protocol()`, which
  reconstructs that protocol. Changing either module then using it to verify
  the original campaign would reject the authentic old protocol.
- `signals._code_hashes()` records absolute file paths. C1's
  `_b1_code_matches()` normalizes four Python module paths but requires identical
  content; PHP paths remain exact. Moving or editing the frozen application is
  not a supported migration.
- `orchestration.Stages.snapshot()` and `Stages.protocol()` verify frozen
  inventories and the original report paths. Never edit the running runtime or
  execution checkout, dependency environment, reports or code hashes.
- `signal_sources.select_sources()`, `campaign._verify_reports()` /
  `run_campaign()`, `portfolio_simulator.RunAssumptions` and
  `statistics.analyze_ledgers()` have independent pre-holdout guards. Adding
  only a new CLI phase is neither sufficient nor an acceptable bypass.

## Alternatives

1. **Recommended: retained frozen verifier plus a new guarded execution lane.**
   Verify the old freeze with its authentic code; bind a separate holdout
   contract to that result and the new runner. Share the existing simulator and
   financial arithmetic. Requires retaining and verifying the old code closure.
2. Package a versioned copy of the old verifier and its dependency closure.
   This removes reliance on the existing worktree but adds import-isolation and
   migration maintenance without a current campaign need.
3. Teach the new selection verifier to accept historical self-hashes.
   Reject this shortcut: it changes existing verification semantics and risks
   treating untrusted recorded hashes as authority.

## Guarded execution boundary

A new `app/backtesting/research/holdout.py` controller owns authorization,
provenance transition, claims, bounded orchestration and terminal evidence.
Production invocation uses a fixed, trusted frozen verifier worktree and
interpreter from the retained campaign configuration, not an arbitrary command
supplied by a caller. Verify its complete imported dependency closure against
the trusted recorded inventory before invoking the fixed verification entry.
The child uses a sanitized import environment, finite deadline and bounded
output. Its returned canonical freeze must match the original artifact bytes.
Synthetic test adapters must not become a production verifier override.

Only a fully verified `selection_state=selected` can create a typed internal
authorization. Bind it to the exact selected catalogue entry/diff, baseline
comparator, cost profiles, original registry/protocol/freeze identities,
baseline setup/config/catalog/snapshot, instrument and cost manifests, risk
policy, symbol priority, warmup/score/end window, unchanged PHP inventory and
new runner inventory. Check those bindings before any held-out source or
profit read, not merely before report publication.

This is a local workflow guard, not a sandbox against an operator who can edit
the verifier or authority configuration. Hash integrity alone is not authority.

## Once-only evidence

The campaign has one trusted original-registry authority and one private
sidecar claim location. The location is independent of output directories and
must not be replaceable by an unrestricted CLI argument. Copying a freeze or
choosing another output directory does not establish a new campaign authority.
The implementation must document this trust boundary explicitly.

Before the first held-out market/signal/profit read, atomically claim the whole
deduplicated batch and publish its immutable contract. Use exclusive creation,
file fsync and parent-directory fsync; test concurrent claimants and interruption
between every publication step. Existing or ambiguous claims fail closed.
Preflight failures before claiming do not consume holdout; an existing claim
does, even if no terminal artifact was written.

Any post-claim crash, failed run, incomplete funding or partial evidence remains
recorded and consumes the evaluation. There is no automatic re-evaluation under
another output root. Arithmetic verification of retained ledgers is allowed;
regenerating signals or simulations is not verification. No untested resume
mechanism is implied by this design.

## Reuse without weakening existing entrypoints

- `signal_sources.py`: extract common coverage/provenance validation behind the
  existing closed entrypoint; add a separate exact-window authorized selector.
- `signals.py`: reuse streaming worker machinery for guarded holdout signals.
  Generate the unchanged baseline signals once and reuse them across the fixed
  hypotheses/cost profiles. New reports retain their actual code paths/hashes.
- `campaign.py`: factor shared simulation internals, preserving existing public
  training/validation behavior; a distinct holdout entrypoint requires exact
  authorization and claimed scope before report/source reads.
- `portfolio_simulator.py`: reuse the same simulator. An optional typed internal
  authorization is mandatory for the exact holdout phase/window; default calls
  still reject held-out dates. Do not introduce an unrestricted end-date flag.
- `statistics.py`: share private arithmetic while keeping the current public
  pre-holdout verifier closed. A separate holdout verifier checks the new
  contract before opening summary/ledger profit data and independently
  recomputes trades, cashflows and marked equity.
- Keep the original selection artifacts and verifier bytes intact. Holdout has
  its own contract, attempts, terminal states and reports; do not run an old
  protocol through the new selection-code validator.

Record old and new Python inventories, explicit changed modules and purpose,
original B1 identity and new B1 identity. Do not claim identical Python bytes or
rewrite old paths/hashes to make them appear compatible. Preserve the exact PHP
baseline semantics and verify old/new pre-holdout fixture parity before launch.

## Required verification before implementation can be accepted

All development tests are synthetic; no actual 2026 market/strategy/PnL reads.

1. Missing, malformed, tampered, noncanonical, incomplete and no-winner freezes
   deny before data/profit reads, including baseline-only requests.
2. Wrong verifier bytes, dependency closure, interpreter, cwd, import path or
   caller-supplied executable deny. Authentic old evidence remains verifiable.
3. Changed variant/diff, cost scenario, risk cap, baseline hash, dates, cutoff,
   symbol order or source identity deny at every public entrypoint.
4. Concurrent claims allow exactly one batch; different output directories do
   not reset it; baseline selection deduplicates to two slots. Publication
   failures and crash states retain correct, conservative consumption evidence.
5. Synthetic end-to-end worker/plan/candle/ledger fixtures exercise the genuine
   shared simulator, funding, fees, marked drawdown and independent statistics.
   Warmup is unscored and the cutoff remains exclusive. Missing costs/coverage
   are invalid/inconclusive, not successful zero-return observations.
6. Existing training/validation guards and regression fixtures remain unchanged.
   No arbitrary Boolean authorization, environment-variable bypass, alternate
   simulator, forged effective snapshot or Paper label is introduced.
7. One consolidated independent review/correction, relevant tests and required
   CI precede merge. Real launch is a separate verified action only after the
   running campaign yields an eligible immutable selection.

## Current execution status and next action

This is not an acceptance or operational readiness report. At the
latest engineering checkpoint, training groups and their supervisor are active
and no selection freeze exists. The concrete TDD plan is
`../plans/2026-10-09-research-guarded-holdout.md`; its first slice passed 116
authority tests with 95.33% branch-enabled coverage and a 12-file historical
Git inventory check. The engine slice subsequently passed 927 parent-run tests
and a positive synthetic 2023 parity case against `8ef8addd` (seven exact
ledgers, nonvolatile summary and independent arithmetic). Its expanded suite
passed 1,133 tests with 14 optional PHP tests skipped. These are development
checks, not operational performance evidence.

The controller commit `bf72a7cf` includes bounded orchestration, JSON/CSV/French
reports and read-only retained verification. The parent-run expanded focused
suite produced 974 passes and one failure; the implementer's broader suite
produced 1,180 passes, 14 optional skips and the same one failure. Both failures
were the old synthetic inventory fixture missing the two newly inventoried
dependencies (`orchestration.py`, `experiments.py`). Only that test was corrected
to assert the exact sixteen-file inventory. The parent then independently passed
the entire 116-test authority module; application source hashes were unchanged.
These are separate runs, not a claim that a fresh full suite passed locally.

Coverage collected from the full run and the corrected authority-module rerun
in a separate copy of its database measured 96.675532% combined line/branch
coverage over seven modules, with no exclusions; pure branch coverage was
93.243243%. Controller-only combined coverage was 95.945946%, with 88.135593%
pure branch coverage. The synthetic controller scenarios score three minutes
after 60,000 warmup minutes per pair; they are not an actual full-calendar run.
Those coverage measurements precede the consolidated review corrections and
must not be presented as coverage of the corrected revision.

The single consolidated review found two Important defects and one Minor
provenance issue. Commit `662994f1` reconciles the authority/campaign inventories
and makes controller fixtures use the genuine authority inventory. It also
reconstructs the exact bound source subset from its manifest and verifies the
referenced archive/REST bytes, sizes and safe paths before retained summary/PnL
reads, without invoking an evaluation selector, candle iterator or simulator.
Reads are chunked, deadline- and size-bounded; no persistent hash cache is used.
The manifest has no independent hash of CHECKSUM-file bytes: those files retain
the existing strict digest/filename validation, not a claim of byte attestation.

The execution contract now records the actual runner's invoked/resolved
interpreter path, version and on-disk binary hash. Reports identify the frozen
verifier runtime separately and retain the original runner provenance during
read-only replay. Runner dependencies, process memory and the host OS are not
attested by this record.

The parent independently passed 22 concrete correction regressions, the direct
inventory-equality test, and all six binding-scope cases after correcting one
old test that mutated a detached fixture instead of a real input. The broader
487-case run had 486 passes and that one test failure; application source bytes
did not change when fixing the test. A fresh fifteen-file research coverage
run is in progress over eight modules, including the changed checksum helper.
Complete repository CI remains mandatory before merge; there is no second
independent review cycle. A successful C2b implementation still does not
authorize evaluation without the original campaign's eligible selection.
No authority has been provisioned. Do not modify the two operationally frozen
worktrees; recheck current process/evidence state before any operational action.
