# Paper Strategy Profile CLI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the Symfony global-option collision that prevents both Paper operator commands from starting.

**Architecture:** Give the Paper-specific legacy selector the unambiguous `--strategy-profile` name and leave canonical modern selection unchanged. Test through FrameworkBundle's real console application so application-definition merging cannot regress silently.

**Tech Stack:** PHP 8.2+, Symfony Console/FrameworkBundle, PHPUnit 11.

---

### Task 1: Reproduce command-definition merging

**Files:**
- Create: `trading-app/tests/Command/PaperOperatorCommandContainerTest.php`

- [ ] **Step 1: Write a kernel-backed failing test**

Boot `App\Kernel`, create `Symfony\Bundle\FrameworkBundle\Console\Application`,
find each of `app:paper-market:runtime-check` and
`app:paper-market:replay`, and call `mergeApplicationDefinition()`. Assert the
merged definition contains the global `profile` option and the Paper-specific
`strategy-profile` option.

- [ ] **Step 2: Verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/Command/PaperOperatorCommandContainerTest.php
```

Expected: error `An option named "profile" already exists.`

### Task 2: Rename the Paper legacy selector

**Files:**
- Modify: `trading-app/src/Command/PaperReplayRuntimeCheckCommand.php`
- Modify: `trading-app/src/Command/PaperExecutionReplayCommand.php`
- Modify: `trading-app/tests/Command/PaperReplayRuntimeCheckCommandTest.php`
- Modify: `trading-app/tests/Command/PaperExecutionReplayCommandTest.php`

- [ ] **Step 1: Implement the minimal rename**

Declare `strategy-profile` instead of `profile`, and pass
`optionalOption($input, 'strategy-profile')` to
`PaperReplayStrategySelection::fromOptions()`. Replace command-test input keys
`--profile` with `--strategy-profile`; do not change the strategy selection
domain object or any modern option.

- [ ] **Step 2: Verify GREEN and command behavior**

Run:

```bash
cd trading-app
php bin/phpunit tests/Command/PaperOperatorCommandContainerTest.php tests/Command/PaperReplayRuntimeCheckCommandTest.php tests/Command/PaperExecutionReplayCommandTest.php
```

Expected: all tests pass.

### Task 3: Update operator documentation and real readiness evidence

**Files:**
- Modify: `docs/handbook/runbooks/paper-market-replay.md`
- Modify: `docs/handbook/technical/paper-market-data-datasets.md`
- Modify: `docs/superpowers/specs/2026-08-20-issue-196-modern-paper-operator-design.md`

- [ ] **Step 1: Replace Paper command examples and prose**

Use `--strategy-profile` only for the two Paper operator commands. Do not alter
unrelated Docker, MTF, or exchange profile options.

- [ ] **Step 2: Run a real modern runtime-check**

Invoke `bin/console app:paper-market:runtime-check` with r11, the private Paper
configuration, and a complete modern identity. Expected: canonical redacted
JSON readiness output, never a Symfony option-collision exception.

- [ ] **Step 3: Run static/lint checks and deliver**

Run targeted PHPStan, `php bin/console lint:container --no-debug`,
`git diff --check`, commit, push, open a ready PR, request Codex review, and
merge only after required CI is green with no blocking thread.
