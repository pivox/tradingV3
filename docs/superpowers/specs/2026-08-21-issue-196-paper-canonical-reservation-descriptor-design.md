# Issue #196 Paper canonical reservation descriptor

## Problem

The modern Paper Fake runtime now persists the exact public instrument contract,
but its active private orders and positions do not retain the canonical
portfolio reservation that authorized the exposure. A later portfolio source
can identify a decision and plan hash, but it cannot recover the exact reserved
risk, reserved notional or admission identity without joining a second store or
recomputing canonical risk from incomplete exchange records.

## Decision

Add a strict immutable `PaperCanonicalFakeReservationDescriptor` derived from
the validated `PaperCanonicalPreparedEffect`. The canonical Fake dispatcher
encodes it into scalar order metadata before submission, and the matching
engine carries it through entry orders, protection orders and positions using
the existing lineage propagation boundary.

The descriptor contains only the authenticated opening reservation facts that
a private portfolio projection needs:

- schema and exact Paper cell ID;
- network, public venue and account namespace;
- mode/setup/version/side identity;
- decision key, config hash and plan hash;
- quote currency;
- reserved risk and reserved notional as canonical decimal strings;
- portfolio input and snapshot identity hashes;
- admission hash and canonical opening reservation state hash;
- reservation version and observation timestamp;
- a descriptor hash over the complete canonical payload.

Construction verifies the prepared effect first, then checks every field
against the modern cell, plan, lineage, admission proof and canonical opening
reservation. Decoding accepts the exact v1 wire shape only, recomputes the hash,
validates canonical decimals and timestamps, and can assert an exact cell.

## Alternatives considered

Reading Doctrine `OrderIntent` and the durable effect journal while projecting
the Fake account would couple two persistence boundaries and introduce a race
between their checkpoints.

Recomputing reservation values from Fake order price, quantity and stop fields
would lose the complete cost-aware stop risk and the authenticated admission
identity. It could turn an approximation into certification evidence.

The embedded immutable descriptor is the smallest restart-safe authority. It
keeps the Fake state self-describing and lets the following portfolio-source
slice aggregate only validated private facts.

## Invariants and failure semantics

- legacy Fake and legacy Paper behavior remains unchanged;
- only modern Paper cells and valid canonical prepared effects can produce a
  descriptor;
- the encoded payload has an exact versioned shape and canonical JSON order;
- every decimal is finite, non-negative and encoded canonically; reserved risk
  and notional are strictly positive;
- all identity and hash fields match the cell, plan, proof and reservation;
- forged, truncated, non-canonical or foreign descriptors fail with
  `paper_canonical_fake_reservation_descriptor_invalid`;
- the dispatcher validates and attaches the descriptor before any Fake order
  mutation;
- idempotent replay requires the already persisted descriptor to match the
  current prepared effect exactly;
- no fallback to inferred order economics or a legacy reservation is allowed.

## Tests

Focused tests cover deterministic encoding, exact field propagation, decode
round-trip, sensitivity to reservation identity, malformed and forged payloads,
cell mismatch, dispatcher persistence, idempotent restart and propagation into
filled positions and attached protections. Adjacent modern Fake execution tests,
targeted PHPStan, container/YAML lint and diff checks complete verification.

## Scope and safety

This slice does not aggregate balances, PnL, concurrency or exposure into a
`CanonicalPortfolioSnapshot`, does not wire the production evidence provider,
and does not enable the modern coordinator. Those remain the next #196 slices.
It adds no network client, credential access, private venue endpoint, strategy
tuning or mainnet execution path.
