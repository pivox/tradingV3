# Paper Canonical Private Portfolio Implementation Plan

**Goal:** Produce a restart-safe, exact `CanonicalPortfolioSnapshot` from one modern Paper Fake cell without trusting the stale legacy balance.

**Architecture:** Persist an authenticated account origin and monotonic state revision, extract the existing exact fill/funding arithmetic into a shared monetary projector, expose one lock-consistent private state snapshot, then aggregate only validated canonical reservation and instrument descriptors.

**Tech Stack:** PHP 8.4, PHPUnit 11, Symfony, Brick Math, canonical JSON, existing Fake/Paper runtime.

## Task 1: Shared exact monetary ledger

- [ ] Add failing projector tests for fills, funding, exact duplicates,
  conflicts, future events and deterministic input hashes.
- [ ] Implement immutable projection/result and stable exception contracts.
- [ ] Refactor `FakeDailyLossCapGuard` to consume the projector without
  changing any status, reason or audit metadata.
- [ ] Run the projector and complete daily-loss guard suites.

## Task 2: Certified private state boundary

- [ ] Add failing state-store tests for the immutable opening-balance
  descriptor, monotonic persisted revision and one lock-consistent snapshot.
- [ ] Implement the origin descriptor and private-state snapshot DTO.
- [ ] Persist and restore the revision without certifying legacy payloads.
- [ ] Propagate the canonical reservation identity into funding events.
- [ ] Run state persistence, funding, liquidation and restart tests.

## Task 3: Canonical portfolio source

- [ ] Add failing tests for fresh, pending, filled, funded and restarted cells.
- [ ] Implement exact policy scope/day binding and lifetime/day ledger
  projection.
- [ ] Validate active reservation and instrument descriptors, deduplicate
  decisions and aggregate reserved risk, positions, pending entries, notional
  and unrealized PnL.
- [ ] Hash the complete private input and return the strict canonical snapshot.
- [ ] Add negative tests for legacy/missing/forged/cross-cell evidence,
  conflicts, future monetary facts and insolvent equity.

## Task 4: Review and delivery

- [ ] Review the complete diff for duplicate accounting, race windows, legacy
  fallback, private network access, credentials, tuning and mainnet writes.
- [ ] Run focused and adjacent Fake/Paper PHPUnit suites.
- [ ] Run targeted PHPStan, container/YAML lint, PHP syntax and diff checks.
- [ ] Commit only intended files, open a ready PR for #196, handle real review
  feedback, merge when checks are green and record the next #196 slice.
