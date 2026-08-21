# Issue #196 Paper canonical private portfolio

## Problem

The modern Paper runtime now persists exact instrument and reservation
descriptors, but no production source can turn the private Fake state into the
`CanonicalPortfolioSnapshot` required by portfolio admission. The stored Fake
balance is not authoritative for this purpose: normal fills and funding are
recorded in the monetary event ledger while only liquidation PnL is currently
booked into the legacy balance DTO. Reading that balance as equity would omit
costs and normal realized PnL; adding the complete ledger to it would count
liquidations twice.

The state is also read through several independent getters. A concurrent fill,
cancel or market mark between those reads could produce a portfolio snapshot
that never existed.

## Decision

Build a versioned, fail-closed private portfolio source for a single modern
Paper execution cell.

The Fake state persists two new account facts:

- an immutable USDT opening-balance descriptor, including its schema, ledger
  model and identity hash;
- a certified monotonic state revision incremented for every persisted Fake
  mutation.

The state store exposes one immutable private-state snapshot acquired under its
existing file lock. It contains the exact revision, balances, active orders,
open positions, mark prices and event ledger from the same state boundary.

A reusable `FakeMonetaryLedgerProjector` owns the existing exact monetary-event
semantics: fill fees, spread, slippage, liquidation fees, realized gross PnL
and funding. `FakeDailyLossCapGuard` consumes this projector so portfolio
certification and runtime daily-loss enforcement cannot drift. The projector
deduplicates exact event replays, rejects conflicting sequences or funding
identities, rejects future monetary events and exposes a hash of the accepted
event set.

`PaperCanonicalFakePortfolioSource` then:

1. binds the exact modern cell and canonical policy scope;
2. validates the opening balance and certified state revision;
3. verifies that every monetary event and every active exposure carries a
   valid reservation descriptor for that cell;
4. projects lifetime net realized cash and policy-day net realized PnL;
5. recomputes open notional and unrealized PnL from the current mark, quantity
   and validated canonical instrument contract size;
6. computes pending-entry notional from the remaining entry quantity and exact
   order price;
7. counts each active decision once and conservatively keeps its full opening
   reserved risk until all associated active orders and positions disappear;
8. returns an immutable `CanonicalPortfolioSnapshot` with a hash covering the
   account origin, both ledgers, active records, marks, policy window and state
   revision.

Equity is `opening balance + lifetime monetary net + current unrealized PnL`.
The policy-day `realizedNetPnlQuote` is the exact monetary net for that policy
window. Entry costs are therefore recognized when incurred, while hypothetical
future exit costs are never invented.

## Alternatives considered

Using `ExchangeBalanceDto::total` was rejected because it omits normal fills and
funding. Adding the ledger to the current DTO balance was rejected because
liquidation PnL is already booked there.

Updating the legacy balance on every fill in this slice was rejected because it
would change margin admission and many legacy Fake behaviours at once. The new
canonical source instead makes the exact event ledger authoritative while
leaving that runtime-parity migration for a dedicated follow-up.

Reading orders, positions and events independently was rejected because the
result could cross a mutation boundary. The existing Fake file lock is the
smallest correct consistency boundary.

Inferring reservations from quantities, prices or stop fields was rejected.
Only the persisted canonical reservation descriptor is authority for reserved
risk and active-decision identity.

## Invariants and failure semantics

- legacy Fake behaviour and mainnet safety remain unchanged;
- only a modern Paper cell and its exact canonical policy are accepted;
- old states without an authenticated opening balance or certified revision
  are not silently upgraded into certification evidence;
- every monetary event in a modern cell must carry the exact cell reservation
  descriptor; funding propagates it from the position;
- every active order and position must carry matching reservation and
  instrument descriptors;
- conflicting descriptors for one decision, duplicate entry reservations,
  missing marks, non-canonical decimals, invalid event sequences, future
  monetary events and non-positive equity fail closed;
- protection orders never count as pending entries or pending notional;
- one decision contributes reserved risk once even when a partial fill leaves
  both a position and a resting entry order;
- no fallback reads Doctrine intents, legacy profiles or inferred stop risk;
- all source failures normalize to
  `paper_canonical_fake_portfolio_snapshot_invalid`.

## Tests

Tests first prove the monetary projector against the existing daily-loss
semantics, then prove a fresh, pending, filled, funded, partially filled and
restarted modern Fake cell. Negative cases forge or remove descriptors, cross
cells, inject a future/conflicting monetary event, remove the account origin
and corrupt active instrument facts. State-revision tests prove mark-only and
order mutations change the snapshot identity.

The adjacent Fake/Paper suite, PHPStan, Symfony container/YAML lint and diff
checks complete verification.

## Scope and safety

This slice creates the private portfolio source but does not yet wire it into
the production evidence provider or certification coordinator. It adds no
network client, private exchange endpoint, credential access, strategy tuning
or real/mainnet execution path.
