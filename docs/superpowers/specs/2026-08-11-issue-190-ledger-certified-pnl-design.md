# Issue #190 — Ledger-backed certified PnL design

## Scope

This atomic lot makes `position_trade_analysis_v2` consume the persisted
`fill_cost_ledger`. It does not remove the legacy v1 view, backfill ambiguous
history, tune strategies, or certify real-provider costs that are incomplete.

## Decision

Keep `position_trade_analysis_v2` as the single certified analytics authority.
Add a ledger aggregate read model in SQL and compose it into the existing v2
surface. Do not calculate a second certified result in controllers, reports, or
exports, and do not introduce a mutable certification table in this lot.

The aggregate identity is exact and fail-closed:

```text
internal_trade_id
+ exchange
+ market_type
+ symbol
+ market_data_venue
+ paper_execution_cell_id
+ configuration_snapshot_id
```

Nullable legacy provenance uses `IS NOT DISTINCT FROM`; it may remain visible
but can never become canonical certified evidence.

## Ledger aggregation

Rows marked cancelled, corrected, reversed, or voided are excluded. Any other
ledger quality flag blocks certification. Entry and exit fills are aggregated
separately to expose first/last fill times, quantity, VWAP, and notional.
Funding rows are included in the same exact trade identity.

Certification requires:

- at least one valid entry fill and one valid exit fill;
- positive finite prices and quantities;
- one coherent position side, with opposite entry and exit order sides;
- entry quantity equal to exit quantity within `1e-8` and zero remainder;
- a matched close with canonical lineage;
- every fill fee normalized to USDT;
- explicit spread and slippage costs on every fill;
- explicit other fees, funding fallback, borrow cost, and liquidation fee from
  the close contract when no corresponding ledger settlement exists;
- no ledger or identifier conflict.

Gross PnL is derived from fill notionals. Provider PnL remains separate as
`recorded_pnl_usdt`. Funding is a signed credit: positive funding increases net
PnL. Missing applicability evidence remains `NULL`; absence is never converted
to zero.

## View composition

Preserve the existing exact FIFO entry/close matching view as a named pre-ledger
source. Recreate its public v2 successor by replacing only the financial and
quality fields with ledger-backed values and by adding fill aggregate fields.
Recreate the #302 canonical-lineage wrapper unchanged in meaning. The public
`canonical_net_pnl_usdt` remains non-null only when both ledger certification
and structured entry/close lineage are canonical.

The indicator snapshot lookup is also tightened to exchange and market type.
If the venue identity cannot be demonstrated, indicator analysis fields remain
unresolved rather than crossing venues.

## Failure behavior

Incomplete quantity, costs, side, lineage, or venue provenance produces stable
quality flags and leaves `net_pnl_usdt`, `total_known_cost_usdt`, and
`realized_net_pnl_r` null. Open and unmatched trades remain visible. No fallback
to symbol-only matching, close extras for fill quantities, or provider-recorded
PnL is allowed for certification.

## Verification

PostgreSQL integration tests cover long and short trades, partial entry and exit
fills, funding credits/debits, missing costs, quantity mismatch, open trades,
same-symbol cross-venue isolation, canonical-lineage mismatch, and replay-safe
ledger identity. Repository, reporting, and outcome tests prove that all
consumers use the single v2 definition.

## Follow-up lots

Quality filters (`all`, `complete`, `certified`, `warnings`, `open`), production
divergence reports, historical backfill decisions, and removal of v1 remain
separate reviewable changes.
