# Issue #196 Paper replay readiness implementation plan

**Goal:** expose a read-only Paper replay readiness contract and make the actual
replay use the same fail-closed preparation.

**Architecture:** extract private configuration loading, add a typed immutable
preparation/result service, add an operator runtime-check command, and refactor
the replay command to consume the shared preparation.

**Tech stack:** PHP 8.4, Symfony Console/DI, PHPUnit 11, PHPStan.

## Task 1: Characterize the readiness contract

- Add failing tests for the successful redacted JSON report and the
  `reference_only`/`baseline_eligible=false` distinction.
- Add failing tests for stable failure output and zero persistence writes.
- Run the focused tests and confirm the expected red state.

## Task 2: Implement shared preparation

- Extract the bounded private configuration reader from the replay command.
- Add immutable `PaperReplayPreparation` and `PaperReplayReadinessService`.
- Validate the baseline dataset, controlled clock, cell provenance and
  coordinator safety before returning.
- Run focused unit tests.

## Task 3: Add the operator command and refactor replay

- Add `app:paper-market:runtime-check` with JSON as the stable default output.
- Refactor `app:paper-market:replay` to use the same service before state writes.
- Update direct command tests and container wiring tests.
- Run focused command and Paper execution tests.

## Task 4: Update evidence and verify

- Update the Paper replay runbook and #196 audit to reflect the implemented
  public replay/readiness boundary without promoting legacy profiles.
- Run PHPUnit for all affected Paper components, Symfony container validation,
  focused PHPStan and documentation checks.
- Review the diff, commit, open a PR, request review, address real blocking
  feedback, and merge when green.
