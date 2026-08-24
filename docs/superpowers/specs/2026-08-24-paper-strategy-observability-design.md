# Paper Strategy Observability Design

## Context

The first modern Fake/Paper baseline replay completed all twelve configured cells but produced no order intent. The append-only journal proves that every market effect was requested and acknowledged, but it does not explain why the canonical strategy produced no trade. Missing upstream evidence and an explicit canonical runtime rejection are both currently represented by `null`.

## Scope

This change records a deterministic strategy observation for every newly accepted modern Paper source event. It does not change setup conditions, risk, sizing, market-data capture, eligibility, certification, or aggregation.

## Contract

`PaperCanonicalStrategyPreparation` returns a typed result with exactly one of these states:

- `planned`, with a verified `PaperCanonicalStrategyDecision`;
- `no_trade`, with the canonical runtime reason code;
- `missing_evidence`, with a bounded Paper evidence reason code.

The concrete evidence source identifies these fail-closed stages:

- `paper_indicator_projection_unavailable`;
- `paper_order_book_unavailable`;
- `paper_instrument_unavailable`;
- `paper_execution_cost_unavailable`;
- `paper_order_plan_unavailable`.

A nullable test double or alternate assembler remains fail-closed and maps to `paper_strategy_input_unavailable`.

## Durable journal

For a modern cell, the coordinator appends one `strategy_observed` event in the same transaction as `source_claimed`. Its payload contains only:

- schema `paper-strategy-observation.v1`;
- state and reason code;
- source event id;
- the cell's exact mode/setup versions, side, config hash, and condition-catalog hash.

The observation has the source position and source event id columns populated. It has no effect key and never creates an order intent. Replays below the checkpoint cannot append a duplicate; transaction rollback cannot leave an observation without its source claim.

Legacy Paper cells keep their existing behavior. Existing completed campaigns are not backfilled or reinterpreted; validation requires a new campaign identity.

## Safety and verification

Reason codes and payload keys are strict, canonical JSON remains the journal checksum input, and no raw exchange payload or private account data is stored. Unit tests cover the result shape, evidence-stage mapping, coordinator atomic persistence, replay idempotence, and journal integrity. The complete Paper test suite and targeted static analysis must pass before review.
