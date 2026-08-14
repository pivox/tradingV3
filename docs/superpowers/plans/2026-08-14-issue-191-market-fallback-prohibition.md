# #191 Canonical market-fallback prohibition implementation plan

> Execute inline with the `executing-plans` workflow. Do not delegate; the user
> requested quota-efficient single-agent work.

**Goal:** Propagate the already-approved `market_fallback=false` policy through
the hash-bound canonical backtest plan and require it for public-tape fills.

**Architecture:** Add a required false fallback flag to PHP CanonicalOrderPlan,
emit a v2 backtest envelope, retain strict v1 read compatibility in Python, and
gate the v2 visible-queue model on the explicit prohibition.

**Tech stack:** PHP 8.4/Symfony/PHPUnit/PHPStan, Python 3/Pydantic/pytest.

---

### Task 1: Freeze PHP fallback propagation

1. Write failing canonical builder/projection tests for `marketFallback=false`,
   v2 schema emission, hash binding and authenticated `true` rejection.
2. Run focused PHPUnit and confirm RED.
3. Add the immutable plan field, builder propagation and validator guard.
4. Run focused PHPUnit and confirm GREEN.

### Task 2: Freeze Python v1/v2 compatibility

1. Write failing tests proving v1-without-field remains readable, v2 requires
   exact false, and mixed version/field payloads fail closed after rehash.
2. Add the strict optional nested field plus envelope version invariants.
3. Keep runtime v1 serialization from introducing a null hash input.
4. Run focused pytest and confirm GREEN.

### Task 3: Gate visible queue fills

1. Write a failing test proving a valid v1 plan is rejected as missing fallback
   policy while v2 explicit false remains executable.
2. Add the fail-closed version/policy guard and update test plan factories.
3. Run focused queue, contract and runtime tests.

### Task 4: Verify and deliver

1. Update the backtesting handbook and runtime design.
2. Run full relevant PHP suites, full Python coverage gate, PHPStan/config lint,
   strict MkDocs and diff checks.
3. Open a ready PR linked to #191, request one Codex review, address actionable
   feedback, and merge on green CI with no blocking thread.
