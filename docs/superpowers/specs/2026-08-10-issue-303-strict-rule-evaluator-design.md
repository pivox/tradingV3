# #303 typed fail-closed rule evaluator design

## Status and scope

- Status: approved design
- Date: 2026-08-10
- Primary issue: #303 / TV3-RULES-001
- Dependencies delivered: #301 setup contracts, #133 effective configuration
- In scope: modern setup contracts, their condition catalogue, compilation,
  evaluation, trace and runtime integration
- Out of scope: strategy tuning, legacy-rule parity, BitMart removal, swing
  trading, mainnet writes

## Problem

Published rule intent currently has more than one runtime truth:

- `YamlRuleEngine` accepts unknown blocks and operators and accepts malformed
  field comparisons;
- `ConditionLoader` stores rules in process-global mutable state;
- `TimeframeValidationService` catches failures from `ConditionRegistry` and
  falls back to the permissive YAML engine;
- the #301 condition list validates identifiers but does not define the metric,
  unit, side, timeframe, parameters or missing-data policy of a condition;
- exceptions and missing inputs can therefore change the engine used for a
  decision instead of deterministically rejecting that decision.

The modern Paper baseline needs one versioned, immutable and fail-closed path.

## Invariants

1. A modern setup is evaluated only from an exact setup version, exact catalogue
   version and exact effective-config snapshot.
2. Every referenced condition exists in the catalogue and has one canonical
   definition.
3. Unknown keys, nodes, conditions, operators, timeframes, sides and parameters
   are compilation errors.
4. `all_of` and `any_of` must be non-empty and have one truth table everywhere.
5. Missing, stale, non-numeric or non-finite critical inputs produce a failed
   result, never an exception-driven fallback.
6. Runtime inputs and compiled objects are immutable for the duration of one
   evaluation.
7. Series have a declared chronological order. No condition may guess or reverse
   it privately.
8. A modern decision records a structured trace including failed and skipped
   nodes; it cannot silently switch to a legacy evaluator.
9. `micro_scalping` remains non-publishable while real timestamped spread and
   OFI inputs cannot satisfy the catalogue contract.
10. No change enables exchange writes or mainnet execution.

## Architecture

### Versioned condition catalogue

Add a canonical catalogue artifact under `config/trading/condition_catalog/`.
The v1 document contains an exact semantic version and definitions keyed by the
54 condition identifiers referenced by the #301 contracts. Each definition
declares:

- stable condition ID and implementation service ID;
- metric(s), unit and value type;
- compatible timeframes and sides;
- required context source and freshness contract;
- scalar or series shape and chronological order;
- typed parameter schema, defaults and allowed overrides;
- comparison/operator semantics and epsilon policy;
- missing-data policy, fixed to `reject` for critical inputs;
- implementation/provenance reference.

The loader rejects duplicate IDs, unknown fields, invalid units/types, empty
compatibility sets, unsafe missing-data policies and ambiguous parameter
schemas. It canonicalizes the validated document and exposes its SHA-256 hash.
Setup contracts must carry that exact hash before they can become executable.

### Compiler and AST

`StrictSetupRuleCompiler` consumes a validated setup contract and catalogue. It
produces immutable AST value objects:

- `AllOfNode(children)`;
- `AnyOfNode(children)`;
- `ConditionNode(conditionId, timeframe, side, typedParameters, provenance)`.

Compilation validates catalogue identity, setup side, condition side/timeframe,
parameter names/types/ranges, non-empty groups and provenance. It never resolves
aliases. The four setup sections (`regime`, `context`, `trigger`,
`confirmations`) plus filters and no-trade rules retain their section identity
in the compiled plan.

The compiler returns either a complete plan or a list of deterministic
diagnostics. A partially compiled plan is never executable.

### Strict evaluator

`StrictRuleEvaluator` is stateless. It receives a compiled node and an immutable
context snapshot indexed by timeframe. It resolves the exact condition service,
validates required input values and freshness, then evaluates the condition.

Truth tables are fixed:

- `all_of`: true only when every child passes;
- `any_of`: true when at least one child passes;
- empty groups: impossible after compilation and rejected defensively;
- condition error or invalid critical input: failed node with a reason code.

Evaluation has no `catch => legacy engine` path. Unexpected exceptions become a
failed trace with `condition_error`; infrastructure callers may fail the whole
decision, but may not retry it through another evaluator.

### Trace contract

Every evaluation returns `StrictEvaluationResult` with:

- catalogue/setup/config identity and hashes;
- setup side and requested timeframe;
- final boolean and stable reason code;
- ordered tree of node results;
- condition ID, inputs used, typed parameters, observed value, threshold,
  epsilon, provenance and duration;
- explicit input-quality/freshness metadata;
- evaluator and trace schema versions.

Secrets and full unbounded market series are excluded. Values needed to replay
the decision remain bounded and canonicalizable.

### Runtime cutover

Modern requests identified by canonical `mode_id`, `setup_id` and their exact
versions compile and evaluate exclusively through the strict path. Startup or
preflight rejects an uncompileable setup. A runtime data failure rejects the
decision.

Legacy requests may continue using the old engine temporarily, but legacy and
modern paths are selected explicitly before evaluation. The existing
ConditionRegistry-to-YAML exception fallback is forbidden for modern requests.
No automatic parity or differential fallback is introduced.

## Context ownership

The catalogue makes context ownership explicit:

- indicator series come from the indicator snapshot/provider contract and are
  chronological oldest-to-newest;
- ADX and volatility consume the timeframe declared by the condition node;
- spread and OFI require venue observations with source and event timestamp;
- expected net R and EntryZone consume their canonical domain snapshots rather
  than ad-hoc indicator-map keys;
- selector metrics identify their producer and unit.

Adapters may translate existing contexts during migration, but must validate
the resulting shape and cannot synthesize absent values as zero.

## Test strategy

Implementation follows red-green-refactor in three slices:

1. Catalogue/schema tests: exact fields, duplicate/unknown IDs, invalid types,
   hash determinism and all 54 referenced IDs.
2. Compiler/evaluator tests: unknown constructs, empty groups, side/timeframe
   mismatch, override validation, truth tables, exception handling and complete
   traces.
3. Contract/runtime tests: compile every real #301 setup, exercise each
   publishable condition at threshold-minus-epsilon, equality,
   threshold-plus-epsilon and missing/non-finite input, verify chronological
   MACD behavior, and prove modern failures never enter the YAML fallback.

Existing tests that are already red on `main` because test constructors lag
production constructors are recorded separately; they are not accepted as new
regressions and are repaired when touched by the cutover.

## Delivery sequence

1. Catalogue DTOs, strict loader and deterministic hash.
2. Immutable AST and compiler integrated with #301 contracts.
3. Stateless evaluator and trace objects.
4. Context adapters and direct condition contract tests.
5. Modern runtime cutover and explicit legacy isolation.
6. Full static analysis, configuration lint, targeted/unit suites and relevant
   PostgreSQL integration tests.

The PR is complete only when all modern setup files compile deterministically,
all executable conditions are covered by their boundary matrix, blocked inputs
remain blocked, and no modern evaluation can reach a permissive fallback.
