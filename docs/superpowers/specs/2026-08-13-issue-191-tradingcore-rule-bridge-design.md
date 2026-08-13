# #191 Canonical TradingCore Rule Bridge Design

## Objective

Provide a deterministic, fail-closed bridge from the Python backtest runtime to the canonical PHP setup-rule evaluator. The bridge must execute the exact versioned setup AST and condition catalogue already used by modern runtime paths. It must not translate legacy validation YAML or reimplement trading conditions in Python.

## Scope

This lot evaluates one modern setup against already-computed indicator snapshots. It validates and re-resolves the complete #133 effective-config snapshot, constructs canonical lineage, invokes `CanonicalSetupRuleRuntime`, and returns a versioned result.

It does not calculate indicators from Paper candles, build an EntryZone or OrderPlan, reserve portfolio risk, simulate fills, invoke Backtrader, or execute any exchange mutation. The immediately following #191 lot will project verified Paper candle windows into PHP-owned indicator snapshots; that dependency remains explicit because Paper v2 currently supplies candles, not the 250-bar indicator windows required by the rule runtime.

## Architecture

The process boundary is a one-shot Symfony console command:

```text
Python BacktestTradingCoreBridge
  -> canonical-backtest-rule-request.v1 JSON on stdin
  -> php bin/console app:backtest:rules:evaluate
  -> CanonicalBacktestRuleEvaluator
  -> EffectiveTradingConfigResolver + snapshot equality
  -> CanonicalSetupRuleRuntime
  -> canonical-backtest-rule-result.v1 JSON on stdout
```

CLI is preferred over HTTP because it invokes the real Symfony container without requiring a long-lived server, network authentication, or shared mutable state. Each Python call gets a fresh PHP process, so the in-memory rule-plan cache cannot affect the observable result.

## Request contract

The request is a strict JSON object with these exact fields:

- `schema_version = canonical-backtest-rule-request.v1`;
- `request_id`, a safe deterministic caller identifier;
- `effective_config_snapshot`, the complete canonical #133/#303 snapshot whose request has `execution_capability=backtest`, `exchange=fake`, and `environment=local|test`;
- `symbol`, `market_type`, and `evaluated_at` in UTC RFC3339;
- `indicators_by_timeframe`, a non-empty mapping keyed by canonical timeframe.

Each indicator mapping must contain `kline_time` and a `snapshot_identity` matching the requested timeframe, symbol, `fake`, environment, and market type. Condition-specific fields remain governed by the PHP catalogue/evaluator and missing critical data rejects rather than defaulting optimistically.

The bridge bounds stdin to 8 MiB, JSON nesting to 128 levels, and indicator timeframes to the canonical catalogue. Unknown fields, legacy `profile`, non-finite values, unsafe identifiers, non-UTC timestamps, wrong snapshot hashes, and snapshots that differ from a fresh resolver output are protocol errors.

## PHP evaluation

`CanonicalBacktestRuleEvaluator` reconstructs an `EffectiveTradingConfigRequest` with capability `backtest`, calls `EffectiveTradingConfigResolver`, and compares the submitted and resolved snapshots as canonical arrays. The three hashes (`config_hash`, `condition_catalog_hash`, `snapshot_hash`) and the complete snapshot must match; stale or forged evidence cannot be evaluated.

It then constructs a modern replay `LineageContext` and calls only `CanonicalSetupRuleRuntime::evaluate()`. A failed trading hypothesis is a successful protocol response with `passed=false`; invalid protocol/config/catalogue/identity is a command failure.

## Result contract

The single stdout JSON object has exact fields:

- `schema_version = canonical-backtest-rule-result.v1`;
- request identity, seven modern trading identity fields, `symbol`, `market_type`, and `evaluated_at`;
- `config_hash`, `condition_catalog_hash`, and `snapshot_hash`;
- `passed`, `reason_code`, and the canonical runtime trace;
- `input_hash` and `result_hash` using lowercase SHA-256 over canonical JSON.

The observable trace omits `plan_cache_hit`, whose value depends on process history. `plan_cache_key` is retained. Arrays are recursively key-sorted for hashing while lists preserve order. Stdout contains only the result JSON; diagnostics go to stderr.

## Python client

`BacktestTradingCoreBridge` accepts a frozen Pydantic request, serializes canonical compact JSON, and calls a fixed argv without a shell. It applies a configurable timeout (default 15 seconds), caps stdout/stderr at 8 MiB, requires exit code zero, requires exactly one strict result object, validates the full result schema, checks identity and the three hashes against the request, and recomputes `input_hash` and `result_hash`.

Timeout, missing executable, non-zero exit, malformed/multiple/oversized JSON, unknown fields, identity drift, or hash drift raises a typed bridge error. There is no retry or fallback in this lot.

## Determinism and safety

- Same request bytes and code/config versions produce the same result bytes and hashes.
- Trading rejection and no-trade use exit code zero; technical/protocol rejection is non-zero.
- No access to exchange APIs, DB state, OrderPlan, or portfolio reservations is allowed.
- Only `fake/local|test` backtest capability is accepted.
- Private mainnet remains forbidden.

## Verification

PHP unit/command tests cover pass/no-trade results, repeated byte equality, snapshot/hash/catalogue/indicator identity/timeframe/staleness failures, input bounds, extra/legacy fields, and stdout discipline. Python tests cover argv/stdin determinism, timeout, exit errors, size caps, malformed/multiple JSON, strict schema, identity/hash drift, and a real cross-runtime golden invocation.

