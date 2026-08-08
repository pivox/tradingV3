# Canonical runner preflight design

## Scope

Close the three P1 findings from review cycle 2 of PR #332 without implementing
the #303 condition evaluator or the #304 risk formulas. Modern requests must be
rejected before any exchange-capable or worker-capable runner activity, while
legacy requests retain their historical enabled-profile default.

## Decision

`MtfRunnerService::run()` owns the first runtime preflight because it is the
shared boundary used after HTTP request construction and before symbol,
exchange, persistence, worker, and TP/SL work. When the request lineage is
modern, the runner evaluates the typed effective snapshot with
`CanonicalTradeEntryConfigFactory` and `CanonicalTradeRuntimePolicyValidator`.

The preflight returns a stable rejected run immediately:

- a non-executable or invalid canonical snapshot uses the exact typed lineage
  error as its reason;
- an executable snapshot with pending #304 policies uses the first ordered
  runtime-policy blocker and retains the complete blocker list;
- if #304 becomes ready before #303, the fallback reason is
  `canonical_mtf_evaluator_pending_303`.

The rejection occurs before `createContext()`, symbol resolution,
`ExchangeStateSynchronizer`, open-state filtering, locks, switches, sequential
validation, parallel worker spawning, projections, and TP/SL recalculation.
No exchange provider or durable asynchronous work is reachable.

`MtfValidatorCoreService` retains its equivalent preflight as defense in depth
for direct callers that bypass `MtfRunnerService`. Both boundaries consume one
shared canonical preflight result object/service so blocker ordering and reason
selection cannot diverge.

## Parallel execution

Blocked modern requests never enter `runParallel()`, so no canonical identity
is serialized to `mtf:run-worker` during Lot 2A. Full worker-lineage transport
is deferred until a modern request can pass #303 and #304; silently downgrading
to legacy lineage remains forbidden.

## Legacy compatibility

`RunnerController` restores the previous enabled-mode selection only when
`trading_identity` is absent and neither `profile` nor `mtf_profile` is
provided. The selected profile is passed consistently to symbol resolution and
MTF validation. Canonical requests continue to use their exact `mode_id` and
never receive a legacy alias or default.

## Observability and response contract

The runner rejection contains the canonical status, first reason, complete
ordered blocker list, and redacted lineage context. It is logged and audited as
a canonical policy rejection. The response is a completed fail-closed run, not
an exception, partial failure, or worker error.

## Tests

Tests prove:

1. a blocked modern request causes zero calls to context creation, symbol
   resolution, sync, filter, workers, projections, and TP/SL;
2. `workers > 1` still returns the same rejection without spawning a process;
3. the ordered #304 blockers and non-executable snapshot reason are preserved;
4. the direct MTF-core guard remains equivalent;
5. a profile-less legacy HTTP request uses the first enabled mode, while an
   explicit legacy profile and a canonical mode remain unchanged.

No mainnet execution is enabled and no strategy tuning is introduced.
