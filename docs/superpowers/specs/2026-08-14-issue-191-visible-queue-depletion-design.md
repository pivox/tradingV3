# #191 Visible queue depletion maker-fill design

## Goal

Replace OHLCV touch-based maker entry assumptions with a deterministic,
dataset-bound partial-fill model driven only by authenticated public L1 and
trade tapes. This lot does not claim exchange queue priority, infer hidden
liquidity, or authorize private/mainnet execution.

## Scope and boundary

The model accepts one `CanonicalBacktestOrderPlan`, its exact
`DatasetDescriptor`, a verified public book tape, a verified public execution
tape, and their verified quantity-conversion tape. It supports only limit plans
whose `entryLiquidityRole` is `maker`. Taker plans and market fallback fail
closed because the canonical plan currently carries no versioned fallback
authorization.

All inputs must bind the same dataset ID/checksum, source checksum, source
network, venue and market type. The plan symbol must be covered by the dataset.
The quantity-conversion tape must bind the exact book and execution tape
checksums. No raw venue quantity is compared with a plan quantity.

## Initial visible queue

The order becomes live at `plan.createdAt`. Select the latest book record for
the plan symbol whose `available_at` is at or before that instant, ordered by
the immutable tape order. The record must not be older than
`maximumInputAgeSeconds` at order-live time.

A long maker entry must equal the selected best bid; a short maker entry must
equal the selected best ask. The corresponding converted L1 base quantity is
the initial visible queue ahead. The order base quantity is the exact decimal
product of the canonical plan's `quantity` and `contractSize` values. A stale
or missing book, a non-top-of-book price, or missing conversion fails closed.

## Deterministic depletion policy

Policy identity is `visible-queue-depletion.v1`.

Only public trades for the same symbol whose exchange event time is at or after
order-live time, and which become available after order-live time and no later
than the effective entry deadline, are eligible. Both event and availability
time must be no later than the deadline, preventing delayed pre-live evidence
and late delivery from being applied retroactively. The effective deadline is
the earliest of `expiresAt` and `cancelAfterAt` when the latter is present.
Eligible trades retain verified tape order.

For a long/buy maker entry, only sell-aggressor trades are contra-side evidence:

- a trade at the entry price first depletes visible queue ahead; any residual
  quantity fills the modeled order;
- a trade below the entry price proves traversal through the level and fills
  the whole remaining order;
- a trade above the entry price is ignored.

For a short/sell maker entry the rule is symmetric: buy aggressors at the entry
deplete the queue, while a trade above the entry proves traversal and fills the
remaining order. Same-side trades never consume queue or fill the order.

The model never reads candles and never upgrades a touched price to a full fill.
No book update after placement reduces queue ahead because L1 cannot distinguish
cancellation from execution without stronger evidence.

## Result contract

The immutable result binds the dataset, plan/config hashes, all three tape
checksums, policy identity, initial book source record, initial visible queue,
order base quantity, effective deadline and deterministic trace hash. Each fill
trace item records its public trade source record, source event position,
happened/available times, price, converted base quantity, queue before/after,
fill quantity, cumulative fill and remaining order quantity.

The terminal status is `unfilled`, `partially_filled`, or `filled`. All decimal
quantities and prices serialize as canonical positive or zero decimal strings.
Every result carries:

- `fills_are_certified: false`;
- `queue_evidence: visible_l1_plus_public_trades`;
- `latency_assumption: available_at_ordering_no_private_ack`;
- `result_is_live_proof: false`.

These flags are invariant. This model is deterministic evidence for research,
not proof of actual maker priority or fill.

## Rejections and exclusions

Stable fail-closed rejections cover wrong input types, cross-dataset or
cross-tape lineage, unsupported liquidity role, missing/stale initial book,
entry not at the visible same-side top, missing conversions, invalid deadlines,
and inconsistent symbol/venue/network/market identity.

This lot excludes taker fallback, private acknowledgements, queue-position
calibration, hidden liquidity, fees/PnL settlement, stop/target execution,
Backtrader event replacement, persistence and certification aggregation. Those
remain subsequent #191/#190 work.
