# Paper canonical execution-cost source design

## Problem

The modern Paper strategy path still cannot build a canonical order plan from
real public market evidence. The canonical engines require a complete
`CanonicalExecutionCostSnapshot`, but no production source currently composes
the effective fee policy, authenticated order book, venue funding schedule and
the exact Fake/Paper slippage model. Supplying literals or treating missing
costs as zero would make risk, net R and Paper certification
non-representative.

## Decision

Add an unwired `PaperCanonicalExecutionCostSource` dedicated to composing
already-canonical evidence for one modern Paper cell and its exact current
trigger.

The source receives the cell, trigger and compiled `CanonicalExecutionPolicy`.
It asks `PaperCanonicalOrderBookSource` and `PaperCanonicalFundingSource` for
the current authenticated public facts, then constructs the existing
`CanonicalExecutionCostSnapshot` without introducing another cost arithmetic
implementation.

The source derives:

- fee authority from the policy's config-bound maker/taker rates; the cost
  snapshot preserves the policy liquidity roles and config hash while the
  existing canonical risk/net-R engines remain the only fee calculators;
- entry, stop and target spread rates from the same current canonical top of
  book;
- entry, stop and target slippage rates from the exact deterministic
  `FakeFillCostModel`: maker is zero and taker is five basis points;
- funding rate and interval from the canonical venue-schedule snapshot added
  by the preceding #196 slice;
- target identities and liquidity roles from the compiled execution policy.

The composite `observedAt` is the order-book observation time. This represents
when the executable cost view was calculated and keeps the order-plan
freshness guard tied to the fast-changing market input. Funding retains its
separate one-interval freshness and exact-interval checks in
`PaperCanonicalFundingSource`; using its older observation time for the whole
composite would incorrectly make every normally scheduled rate fail the
short-lived order-book freshness guard.

The composite input hash is a canonical SHA-256 graph over the cell/config
identity, trigger identity, exact order-book and funding input hashes, funding
interval, Fake slippage and spread model versions, rates, sources, roles and
ordered targets. This makes any policy, evidence or model change visible to the
existing order-plan lineage.

## Alternatives considered

Embedding the composition directly in the future full strategy evidence
provider would reduce one class but couple configuration, public market,
execution costs and private portfolio work into one difficult-to-test unit.

Introducing a generic pluggable execution-model registry would be extensible,
but there is only one certified deterministic Paper execution model today. It
would add an abstraction and configuration surface before a second model
exists.

The dedicated source is therefore the smallest boundary that can be tested and
reviewed independently while remaining reusable by the future evidence
provider.

## Invariants and failure semantics

- only modern cells are accepted;
- cell, trigger, policy, book, funding and config identities must agree;
- the policy must require `order_book`, `execution_model` and
  `venue_schedule` for their respective components;
- the entry role must match the limit order policy, and every role must be
  `maker` or `taker`;
- the book must be canonical, current, non-future, non-stale and scoped to the
  exact public network, venue, symbol and perpetual market;
- the funding snapshot must use the policy's exact positive interval and must
  already satisfy its independent no-lookahead and freshness contract;
- spread and slippage rates are finite, non-negative and strictly below one;
- targets are non-empty, ordered exactly as the policy and carry unique IDs;
- missing book or funding evidence returns no cost snapshot, never implicit
  zero;
- malformed, forged or mismatched evidence throws a stable fail-closed domain
  error;
- no private exchange endpoint, credential, order write or mainnet execution
  path is introduced.

## Tests

Focused tests cover a complete long and short snapshot, maker/taker slippage,
target ordering, exact spread/funding propagation, canonical hash determinism
and sensitivity, current-trigger binding, missing evidence, cell/config/source
identity mismatches, stale/future book evidence, funding interval mismatch and
model/policy drift.

Adjacent tests cover the existing order-book and funding sources, canonical
net-R and order-plan validation, the Fake fill-cost model and runtime metadata.
Targeted PHPStan, Symfony container lint, YAML lint and `git diff --check`
complete local verification before the normal PR/CI cycle.

## Scope

This slice only adds the deterministic, fail-closed execution-cost source and
its specification. It remains unwired. Constructing the private Fake/Paper
portfolio snapshot, assembling the full strategy evidence provider and
enabling the coordinator are subsequent #196 slices.
