# Issue #308 Runtime Microstructure Input Implementation Plan

> Execute test-first. Keep the published 1.0 mode/setup contracts byte-for-byte
> immutable and do not activate private mainnet execution.

**Goal:** Add a typed, fail-closed runtime ingress for authenticated spread and
OFI proof while leaving micro-scalping contracts blocked until the next lot.

**Architecture:** A verified dataset adapter builds the sealed canonical
snapshot. A provider port supplies it to a runtime input resolver, which derives
the expected market identity from canonical lineage and emits a typed rule
snapshot plus auditable status. `CanonicalSetupRuleRuntime` appends only that
resolved input.

### Task 1: Dataset-to-proof adapter

- [x] Add failing tests for exact-symbol conversion and empty/crossed datasets.
- [x] Implement `PaperBacktestMicrostructureAdapter` as a thin delegation to
  `CanonicalMicrostructureEngine`.
- [x] Run the focused microstructure tests.

### Task 2: Runtime input port and resolver

- [x] Add failing tests for provider absence, provider failure, strict
  network/venue/market/symbol/unit mapping and a valid proof.
- [x] Add `CanonicalMicrostructureSnapshotProviderInterface`.
- [x] Add a resolver returning snapshots plus a non-secret audit trace.
- [x] Keep unsupported environments and Fake fail-closed for #196.

### Task 3: Canonical rule runtime integration

- [x] Add a failing runtime test proving the provider snapshot is attached with
  runtime-owned identity while micro setup 1.0 remains compiled-blocked.
- [x] Inject the resolver optionally into `CanonicalSetupRuleRuntime` and append
  its typed snapshot/identity without legacy fallbacks.
- [x] Include microstructure input status/hash/identity in the canonical trace.

### Task 4: Verification and delivery

- [x] Run focused PHPUnit, relevant Paper/backtest and rules suites.
- [x] Run targeted PHPStan, container lint, MkDocs strict and `git diff --check`.
- [ ] Commit, push, open a ready PR, request Codex review, address actual review
  threads, merge when checks and review are clean, then continue #308.
