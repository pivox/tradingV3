# Issue #303 — typed fail-closed rule evaluator implementation plan

## Goal

Replace the modern setup rule ambiguity with one versioned condition catalogue,
one immutable AST compiler and one stateless fail-closed evaluator. Compile all
#301 setup contracts and prevent canonical modern requests from reaching the
legacy YAML fallback.

Design: `docs/superpowers/specs/2026-08-10-issue-303-strict-rule-evaluator-design.md`.

## Task 1 — Versioned condition catalogue

Files:

- `trading-app/config/trading/condition_catalog/1.0.0.yaml`
- `trading-app/src/TradingCore/Rules/Catalog/*`
- `trading-app/tests/TradingCore/Rules/Catalog/*`

Steps:

1. Add red tests for schema/version validation, exact keys, unique IDs, parameter
   types, compatibility, missing-data policy and deterministic canonical hash.
2. Define all condition IDs referenced by the nine versioned setup artifacts,
   including metric/unit/source, timeframes, sides, series order and typed
   overrides.
3. Implement immutable catalogue DTOs and a strict loader. Reject unknown or
   ambiguous content; do not infer defaults outside the artifact.
4. Prove that the catalogue equals the set of conditions referenced by the real
   setup files and that its hash is stable across mapping order.

## Task 2 — Immutable AST compiler

Files:

- `trading-app/src/TradingCore/Rules/Ast/*`
- `trading-app/src/TradingCore/Rules/Compiler/*`
- `trading-app/src/TradingCore/Setup/SetupCompiler.php`
- `trading-app/tests/TradingCore/Rules/Compiler/*`
- `trading-app/tests/TradingCore/Setup/*`

Steps:

1. Add red tests for unknown conditions/operators/keys, empty groups,
   timeframe/side mismatch, invalid overrides and catalogue hash mismatch.
2. Implement readonly `AllOfNode`, `AnyOfNode`, `ConditionNode` and compiled-plan
   identities retaining setup sections and provenance.
3. Compile a validated setup document only as a whole; never expose partial ASTs.
4. Compile every real #301 setup artifact and preserve blocked/draft status.
5. Replace the validator's duplicated condition/parameter constants with the
   catalogue authority where dependency direction permits.

## Task 3 — Strict evaluation and trace

Files:

- `trading-app/src/TradingCore/Rules/Evaluation/*`
- `trading-app/src/TradingCore/Rules/Context/*`
- `trading-app/tests/TradingCore/Rules/Evaluation/*`

Steps:

1. Add truth-table and defensive empty-group tests.
2. Add boundary tests for threshold-minus-epsilon, equality,
   threshold-plus-epsilon, missing, stale, non-numeric and non-finite inputs.
3. Implement an immutable context snapshot and stateless recursive evaluator.
4. Resolve condition services from an immutable map and normalize failures to
   stable reason codes without retrying another engine.
5. Return a versioned ordered trace with exact catalogue/setup/config identity,
   observed values, parameters, provenance and input quality.

## Task 4 — Condition and context contract fixes

Files:

- `trading-app/src/Indicator/Condition/*` as selected by the catalogue
- `trading-app/src/Indicator/Context/IndicatorContextBuilder.php`
- focused condition/context tests

Steps:

1. Make chronological order explicit and test MACD series oldest-to-newest.
2. Validate ADX, volatility, EntryZone, expected-net-R and selector inputs from
   their declared source/timeframe rather than accidental flat keys.
3. Require source and event timestamp for spread/OFI; never substitute zero.
4. Prove all publishable catalogue entries satisfy their boundary matrix.
5. Keep micro-scalping spread/OFI entries blocked until their real timestamped
   source contract is satisfied.

## Task 5 — Modern runtime cutover

Files:

- `trading-app/src/MtfValidator/Service/TimeframeValidationService.php`
- modern request/preflight services discovered from the #133/#302 path
- service wiring and focused runtime tests

Steps:

1. Add a failing test showing a canonical modern evaluator exception currently
   reaches `YamlRuleEngine`.
2. Select strict versus legacy execution explicitly before evaluation from the
   canonical identity; never from a legacy profile alias.
3. Compile/cache immutable plans by exact catalogue/setup/config hashes.
4. Reject compile, input and condition failures with structured trace and no
   fallback. Retain the legacy path only for explicitly legacy requests.
5. Verify canonical modern mode/setup/side/timeframe invariants end-to-end.

## Task 6 — Certification and delivery

1. Run catalogue, compiler, evaluator, setup and runtime suites; repair relevant
   pre-existing constructor-drift tests instead of hiding them.
2. Run PHPStan, Symfony container/YAML lint, full feasible PHPUnit and relevant
   PostgreSQL integration tests.
3. Prove all real setup artifacts compile or remain blocked for declared external
   dependencies, and prove no modern fallback call is possible.
4. Perform independent spec and quality review, address findings, open the #303
   PR, request GitHub review and merge only after green blocking checks.
