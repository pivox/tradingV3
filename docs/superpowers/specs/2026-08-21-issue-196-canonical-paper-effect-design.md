# Issue #196 canonical Paper effect contract

## Scope

This slice defines the durable contract required to journal a modern canonical
Paper decision after an exact strategy outcome has been produced. It does not
build market/risk inputs, run a modern strategy, dispatch an order, or promote a
cell to baseline eligibility.

## Decision

Keep the proven legacy Paper prepared-effect path byte-compatible and add a
parallel modern contract. A canonical plan is never projected into
`OrderPlanModel` and a canonical reservation is never serialized with PHP
object serialization.

The modern effect contains exactly:

- the complete `CanonicalOrderPlan` wire document;
- the canonical portfolio admission proof used to recreate the opening
  reservation;
- the complete modern `LineageContext` and decision key;
- the execution timeframe;
- the already-reserved durable OrderIntent identity;
- the v2 Paper cell provenance;
- a checksum over the canonical payload.

The codec uses the existing plan contract and an admission-proof v2 wire
contract that authenticates the actual admission timestamp. Decode reconstructs
the opening reservation at that timestamp from the proof, plan, and portfolio
policy compiled from the lineage effective-config snapshot. Legacy v1 proofs
remain readable and verifiable when an existing reservation supplies the
timestamp, but cannot independently open a new reservation. This preserves the
explicit serialization ban on `CanonicalPortfolioReservation` while proving
that the recreated state hash is the authenticated opening state.

## Modern Paper provenance

Legacy provenance retains its exact eight-field shape. Modern provenance adds
the exact mode/setup versions, side, config hash, and condition-catalog hash.
All modern fields are mandatory together.

Rehydrating a modern cell from durable provenance requires:

- valid network, venue, snapshot, run, hashes, versions, and side;
- the v2 cell digest to match exactly;
- `exchange=fake` while `market_data_venue` remains the canonical config and
  market-data venue;
- the plan and lineage exchange to equal that public venue;
- the plan environment to equal the Paper network;
- the portfolio scope network and account namespace to equal the reconstructed
  v2 Paper cell;
- plan, lineage, admission proof, and cell identities to match exactly.

The execution adapter and public/config venue are intentionally distinct. The
effect records Fake as the mutation target without rewriting the canonical
plan's venue identity.

## Fail-closed behavior

Unknown, missing, reordered, mixed legacy/modern, or extra fields fail with a
stable `paper_canonical_prepared_effect_payload_invalid` code. Checksum drift,
unsafe/redacted data, stale or non-executable lineage snapshots, plan hash
drift, admission-proof policy drift, decision drift, cell drift, and venue or
network drift fail identically at the codec boundary.

The legacy codec and legacy replay remain unchanged. Modern readiness and
replay continue to return `paper_modern_strategy_bridge_unavailable`, so this
slice cannot create a database fact, Fake order, or certified trade by itself.

## Safety and follow-up

No private exchange client, credential, demo/testnet write, or mainnet write is
introduced. The next slice will assemble canonical Paper inputs from verified
market state and private Paper risk metadata, reserve the modern OrderIntent,
and route this exact effect through a Fake-only canonical dispatcher. Only the
complete recovery/idempotency path may remove the readiness blocker.
