# #196 Immediate partial-fill protection implementation plan

**Goal:** remove the unprotected window after every ordinary Fake/Paper partial
fill and update the canonical audit evidence.

## Task 1 — Lock the runtime contract with failing tests

- Extend `FakeExchangeAdapterTest` for immediate SL creation, stable resize,
  exact rejection compensation, restart/replay and the protection/entry race.
- Change golden scenario 3 so it never submits a manual protection.
- Run the focused tests and retain the expected failures.

## Task 2 — Implement atomic protection synchronization

- Add parent/protection lookup and exact resize helpers to
  `FakeExchangeMatchingEngine`.
- Synchronize the SL after every exposure-increasing fill.
- Reuse it during terminal protection finalization.
- Generalize compensation to an exact increment and deterministic fill-boundary
  identity.
- Cancel an active entry residual when its protection fills.

## Task 3 — Prove persistence and update certification

- Add file-backed restart and idempotent replay coverage.
- Remove the P0 partial-fill gap from the #196 audit and Fake/Paper handbook.
- Keep scenarios 15 and 20 partial until their own end-to-end proofs exist.

## Task 4 — Verify and deliver

- Run focused Fake/Paper tests, the broader Exchange partition, scoped PHPStan,
  container/YAML lint, MkDocs strict and `git diff --check`.
- Open a PR linked to #196, request one Codex review, address real actionable
  threads, and merge only with green required checks and no blocking review.
