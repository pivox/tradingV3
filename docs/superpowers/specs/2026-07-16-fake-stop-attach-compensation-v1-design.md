# Fake Stop-Attach Compensation v1 Design

## Scope

This lot closes the `stop_attach_failure_compensation_not_integrated` golden gap
of issue #196 for the local Fake/Paper adapter. It does not add exchange
connectivity, demo/testnet writes, a general liquidation engine, trailing stops,
or strategy changes.

## Current failure

The Fake matching engine can deterministically reject the attached protection
after an entry fill. It records `protection_status=rejected`, but leaves the
simulated position open and unprotected. The older TradingCore Fake fixture only
returns `cancel_or_reduce_only_close_required`; it does not execute or prove the
compensation.

## Compensation contract

When an entry is fully filled and its attached protection is rejected:

1. Keep the entry fill and the `protection_order.rejected` event visible.
2. Immediately create one deterministic market, reduce-only compensation order
   on the opposite side for the failed entry's exact filled quantity.
3. Execute the compensation through the same Fake matching engine, so normal
   order, fill, fee, slippage, position lifecycle, lineage, and persistence behavior
   is reused.
4. Verify that the failed entry exposure is removed. A standalone entry must be
   flat; an aggregate position may retain only its prior size and must still
   have active reduce-only stop coverage for that residual exposure.
5. Persist compensation evidence on the original entry order:
   - `fail_safe_action=reduce_only_market_close`;
   - `compensation_status=completed`;
   - `compensation_outcome=position_closed` or `entry_exposure_closed`;
   - compensation client/exchange order identifiers;
   - compensation quantity and position sizes before/after;
   - `failed_entry_exposure_closed=true`;
   - explicit residual-protection and flat-position flags.
6. Preserve `protection_status=rejected` and the rejection reason. Compensation
   does not rewrite the failed protection as successful.

The original entry result remains an accepted, filled entry because that fill
occurred. Safety is represented by explicit rejection plus a completed
compensation, never by pretending the SL was attached or closing unrelated
protected exposure.

## Determinism and idempotence

The compensation client order identifier is derived from the entry exchange
order identifier and a fixed hash prefix. Replaying the original client order
returns the persisted entry and cannot create a second compensation fill.

All mutations occur inside the existing Fake state transaction. File-backed
state therefore persists the entry, rejection, compensation order, close fill,
resulting position, residual protection proof, and event sequence atomically.

## Golden evidence

Golden scenario 12, `stop_loss_attach_failure`, becomes executable only when it
proves:

- entry filled;
- protection rejected;
- exactly one reduce-only market compensation filled;
- no open position or open order remains;
- one position-close event exists;
- replay does not create another compensation;
- the result is deterministic from fresh state.

Separate adapter coverage proves that an existing protected position is restored
to its prior size rather than being closed by the failed position increase.

## Failure semantics

The default Fake compensation path is deterministic and local. If its internal
order is rejected, is not filled, removes the wrong quantity, or leaves residual
exposure without stop coverage, the matching engine raises a stable invariant
error. The surrounding Fake state transaction rolls back the local scenario
instead of committing new unprotected exposure.

## Rollback

Rollback restores scenario 12 to
`partial/stop_attach_failure_compensation_not_integrated` and removes the
automatic local compensation branch. No exchange rollback is required because
this lot has no network or external side effect.
