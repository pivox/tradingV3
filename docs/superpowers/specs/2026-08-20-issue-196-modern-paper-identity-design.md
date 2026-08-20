# Issue #196 modern Paper identity design

## Scope

This slice adds the durable identity substrate required before a modern setup can
enter the existing Paper coordinator. It does not promote a setup, dispatch a
canonical order plan or generate certification trades.

## Decision

Legacy Paper cells keep their exact v1 identity and remain `reference_only`.
Modern Paper cells use a distinct v2 identity containing:

- network and public market-data venue;
- private Paper configuration snapshot ID;
- exact mode ID/version, setup ID/version and side;
- canonical effective config hash and condition catalog hash;
- explicit run ID and isolated account namespace.

All modern fields are mandatory together. No legacy profile is inferred from a
modern mode, no version alias is accepted and no historical row is backfilled.
The v2 cell digest is calculated from the complete tuple, so two sides, setups,
versions, configs, venues or networks cannot share state.

The effective-config request must use the public venue represented by the Paper
dataset, the matching network environment and the `paper` capability. Its
resolved snapshot must be executable and its request/config/catalog hashes must
match the cell identity exactly.

## Safety boundary

This PR deliberately keeps modern Paper cells non-runnable. The coordinator
rejects them with `paper_modern_strategy_bridge_unavailable` before provenance,
registration or event mutation. Baseline eligibility remains unavailable until
the later bridge consumes the canonical rule, order-plan and portfolio runtime
without converting through the legacy prudent plan.

No private exchange client, demo/testnet mutation or mainnet execution is added.

## Persistence

An additive migration adds nullable modern identity columns to
`paper_execution_cell` with an all-or-none constraint, exact lowercase side and
SHA-256 checks. Existing rows remain valid and retain their v1 cell IDs. Store
registration and inspection compare every modern field and fail closed on a
single divergence.

## Follow-up

The next slice will expose an operator input for the exact modern identity,
resolve the canonical effective config, prepare a canonical Paper decision and
extend the durable effect/dispatch boundary. Only that end-to-end path may make
a modern identity `baseline_eligible`.
