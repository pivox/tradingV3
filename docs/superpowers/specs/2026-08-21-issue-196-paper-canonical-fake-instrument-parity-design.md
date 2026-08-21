# Issue #196 — Canonical Paper/Fake instrument parity

## Problem

The modern Paper plan is sized from authenticated public venue metadata, but
the Fake execution engine still validates and accounts orders with its static
unit-contract fixtures. For OKX, a canonical contract size such as `0.01`
would therefore be executed as `1`. Quantity precision, minimum notional,
margin, costs, funding and PnL would no longer describe the plan that was
admitted by the canonical runtime.

The production evidence provider and portfolio snapshot must not be wired
while this unit mismatch exists.

## Decision

Add a Paper-only instrument registry implementing
`FakeInstrumentProviderInterface`. One registry instance belongs to one
`PaperFakeRuntime` and is shared by its adapter and matching engine.

Before a canonical effect can set leverage or submit an order, the dispatcher
binds the effect's authenticated `CanonicalOrderPlan` into that registry. The
registry constructs the Fake instrument used for execution from two explicit
authorities:

- canonical plan facts own symbol, perpetual market, quote currency, price
  tick, quantity step, minimum quantity, minimum notional, contract size and
  exchange leverage cap;
- the versioned Fake simulation fixture owns base/settlement identity,
  maintenance-margin rate and supported Fake order types.

There is no fallback to the fixture for a missing or invalid canonical
economic field. The plan must already have a valid canonical hash, its public
venue/environment identity must match the Paper cell, and its quote/market
identity must agree with the explicit simulation fixture.

## Durable descriptor and recovery

The registry emits a versioned canonical descriptor containing every selected
instrument field, the Fake fixture/model versions and a SHA-256 identity hash.
The dispatcher includes that descriptor in trusted canonical request metadata.
The Fake matching engine preserves it on the entry order and propagates it to
derived protection orders and positions with the existing lineage metadata.

When a Paper runtime is recreated, the registry scans active entry orders and
open positions in the restored private Fake state. It accepts only complete,
hash-valid descriptors that agree byte-for-byte. A missing descriptor on
active modern canonical state, a malformed descriptor or conflicting active
descriptors fails closed. Historical legacy/Fake state remains untouched and
is not reinterpreted as canonical Paper state.

Binding is idempotent for the same descriptor. A changed descriptor is allowed
only when the private state has no active canonical entry order or position;
otherwise it fails with a stable instrument-drift reason. This prevents an
instrument update from changing the units of an already-open lifecycle.

## Integration boundaries

`PaperFakeRuntimeFactory` creates the registry after restoring the private
state, then injects the same instance into `FakeExchangeMatchingEngine` and
`FakeExchangeAdapter`. `PaperFakeRuntime` exposes only the narrow bind operation
needed by `PaperCanonicalFakeEffectDispatcher`.

The generic `FakeInstrumentCatalog`, non-Paper Fake adapter construction and
legacy Paper dispatcher remain unchanged. This slice does not build the
portfolio snapshot or full strategy evidence provider and does not remove the
modern readiness blocker.

## Failure and safety contract

- unbound canonical Paper instruments are unavailable, never substituted;
- invalid plan hashes, non-integral positive leverage caps after conservative
  flooring, unsupported symbols, identity drift and descriptor corruption fail
  closed with stable Paper-specific reasons;
- canonical contract size is persisted as the matching engine's
  `margin_contract_size`, so margin, fill costs, funding and liquidation use
  the same unit after restart;
- descriptors contain public/model facts only and pass the existing Paper
  redaction guard;
- no private exchange client, credential, tuning, live order route or mainnet
  mutation is introduced. Paper continues to mutate only its private Fake
  state.

## Verification

Focused tests prove:

- an OKX-like `0.01` contract is used by validation, persisted margin metadata,
  fill fees/slippage and position margin instead of the fixture value `1`;
- plan binding is deterministic and idempotent;
- incomplete, forged, cross-cell and conflicting descriptors fail closed;
- restart restores the exact active descriptor and reproduces the same fill;
- instrument drift is rejected with active state and accepted after the state
  is terminal;
- generic Fake fixtures and legacy Paper behavior remain byte-compatible.

The adjacent Paper canonical dispatcher/runtime, Fake adapter/matching,
funding, liquidation and restart suites, targeted PHPStan, container/YAML lint
and repository CI must remain green.

