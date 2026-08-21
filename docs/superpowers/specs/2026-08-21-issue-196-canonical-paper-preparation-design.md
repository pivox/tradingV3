# Issue #196 canonical Paper preparation bridge

## Scope

This slice implements the strict preparation bridge between the authenticated
`PaperCanonicalStrategyInputAssembler` boundary and `CanonicalShadowRuntime`.
It does not implement the production evidence provider and does not enable
modern Paper replay in the service container.

## Contract

`PaperCanonicalStrategyPreparation` implements the existing Paper preparation
interface. For each triggering event it:

1. asks the assembler for a complete, dataset-bound canonical input;
2. returns no decision when evidence is not yet complete;
3. runs `CanonicalShadowRuntime` under a policy containing only the exact
   mode/setup/version/side identity persisted by the Paper cell;
4. requires a canonical order book for `scalping` and `micro_scalping`, and
   authenticated microstructure for `micro_scalping`;
5. returns no decision for a canonical `no_trade` outcome;
6. converts a `planned` outcome only when its plan, reservation, lineage and
   versioned admission proof are complete and mutually consistent.

The admission proof is rehydrated from the runtime evidence and verified
against the plan, reservation and effective portfolio policy before the
decision crosses the Paper boundary. Missing or malformed planned evidence
fails closed with a stable Paper reason.

## Safety and activation

The bridge has no legacy `PreparedTradeEntry` or `OrderPlanModel` conversion.
It does not call any exchange client. The production coordinator remains
unwired because `PaperCanonicalStrategyEvidenceProviderInterface` still has no
production implementation. Modern replay therefore continues to stop at
`paper_modern_strategy_bridge_unavailable` after this slice.

The following slice must implement the real evidence provider from verified
indicator history, public book/instrument metadata, canonical costs and the
private Fake/Paper portfolio state. Only then may the bridge be wired and the
modern readiness blocker reconsidered.
