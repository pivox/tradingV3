# #196 modern Paper baseline eligibility design

## Goal

Allow exact executable modern Paper cells to produce certification candidates,
while making it impossible for legacy or `reference_only` rows to enter the
#132 certified population.

## Decision

An effective modern configuration that has passed the existing strict resolver,
canonical identity construction and coordinator readiness is persisted as
`baseline_eligible`. Legacy profiles remain `reference_only` without aliases or
promotion. The cell identity, dataset checksum/build binding, canonical strategy
bridge and Fake-only execution boundary remain unchanged.

The database accepts exactly `reference_only` or `baseline_eligible` for Paper
cells and propagated trade facts. No existing row is updated or backfilled.
Existing reference evidence therefore stays historical and cannot become a
certification candidate retroactively.

The #132 SQL and Python gates both require `paper_eligibility` to equal
`baseline_eligible`. Missing, legacy, reference-only or unknown values are
excluded explicitly before the 50-trade cell threshold is evaluated.

## Safety

- Mainnet remains public-data-only and all effects execute against Fake state.
- Eligibility does not assert profitability or certify a trade; all lineage,
  closure, cost, PnL and minimum-sample gates still apply independently.
- No strategy threshold, risk value or contract version changes.
- Rollback never rewrites provenance; it can only restore the stricter database
  constraint after baseline-eligible rows have been handled explicitly.

## Verification

Tests cover modern readiness/replay eligibility, legacy rejection, persistence,
provenance round-trip, additive constraint migration, SQL filtering and Python
exclusion of reference-only rows.
