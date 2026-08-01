# Lot2A Early Rejection and Intent Identity Design

## Scope

Close two Lot2A boundaries without implementing #304 formulas: reject known canonical runtime-policy blockers before MTF providers and compare retry identity with the persisted structured intent identifier rather than a Doctrine primary key.

## MTF rejection flow

Immediately after resolving the typed canonical trade-entry config, `TradingDecisionHandler` runs `CanonicalTradeRuntimePolicyValidator`. A known policy rejection becomes a stable synchronous `SymbolResultDto` decision with `status=rejected`, the first ordered blocker as `reason`, and the complete ordered blocker list. It is logged and audited as a canonical policy rejection. No ATR, indicator, request builder, preflight, planner, or order service is called.

`MtfTradingDecisionMessageHandler` recognizes this returned decision, logs a structured non-retryable acknowledgement, and returns normally so Messenger acknowledges the message. Unexpected exceptions still propagate under existing behavior.

## Retry intent identity

Modern retry validation compares `LineageContext::intentId` with `OrderIntent::getIntentId()`. Both structured identifiers are required on a persisted modern retry; either missing value raises `canonical_identity_incomplete:intent_id`, an exact mismatch raises `canonical_identity_mismatch:intent_id`, and an exact match passes. Doctrine `OrderIntent::getId()` remains storage identity only.

All canonical identity failures use `LineageContextException`. `ExecuteOrderPlan` rethrows this type from pre-submit lineage sync, post-execution lineage sync, and intent-status synchronization. Infrastructure and legacy lineage errors retain their existing best-effort logging behavior. A pre-submit canonical conflict occurs before exchange execution.

## Tests

Tests first prove the current failures, then cover stable synchronous rejection payloads, Messenger acknowledgement, zero provider/downstream calls, missing/exact/mismatched structured intent IDs, and propagation from every ExecuteOrderPlan sync boundary.
