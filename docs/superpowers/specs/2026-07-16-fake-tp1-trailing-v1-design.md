# Fake/Paper TP1 Then Trailing v1 Design

## Scope

This change implements issue #196 golden scenario 13, `tp1_then_trailing`, only
inside the local Fake/Paper exchange. A fixture opts an entry into a partial
TP1 followed by a deterministic trailing stop. No strategy, MTF rule, EntryZone,
live profile, provider, network client, credential, demo/testnet path, or
mainnet permission changes.

The existing behavior remains unchanged when no trailing policy is requested.
If any trailing-policy field is present, the complete capability contract must
be valid; malformed, incomplete, disabled, or incoherent requests fail
explicitly instead of silently falling back to a full-size take profit.

## Chosen Architecture

The versioned trailing state is carried by the reduce-only `TRIGGER` order. The
existing `fake-paper-state-v1` envelope already persists orders, order metadata,
positions, order books, events, and event sequences under one file transaction.
Consequently, the active watermark and derived stop survive restart without a
new database migration or a second source of truth.

A focused `FakeTp1TrailingPolicy` value object owns the trusted fixture contract:

- policy version `fake-tp1-trailing-v1`;
- explicit enabled flag;
- exact TP1 quantity;
- fixed absolute trailing offset in quote-price units.

Only these typed scalar policy fields cross the ordinary request-metadata
boundary. Parent IDs, activation order IDs, watermark, stop, state status,
remaining quantity, and lifecycle lineage are derived by the matching engine.
Raw payloads, headers, credentials, and arbitrary metadata are never copied.

## Entry and Protection Creation

An opted-in entry must provide both an attached stop-loss price and an attached
take-profit price. Its TP1 quantity must be positive and strictly below the
entry quantity; its offset must be finite and positive. The normal order
validator remains responsible for exchange context, quantity, price, leverage,
margin, and precision.

After the entry fills, attached protection is created as follows:

- the initial `STOP_LOSS` is reduce-only for the complete filled exposure;
- the `TAKE_PROFIT` is reduce-only for the fixture's exact TP1 quantity;
- both protection orders retain the approved scalar lineage and trusted policy;
- legacy entries without the policy keep their existing full-size SL/TP behavior.

## Atomic TP1 Transition

`FakeExchangeScenarioService::fillOrder()` and `movePrice()` already execute
inside `FakeExchangeStateStore::runAtomically()`. On a complete TP1 fill that
leaves exposure, the matching engine performs one atomic transition:

1. book the normal reduce-only fill, fee, costs, and partial position update;
2. cancel the active initial SL with reason `tp1_replaced_by_trailing`;
3. create one reduce-only `TRIGGER` for the exact remaining position quantity;
4. initialize the favorable watermark to the TP1 execution price;
5. derive the first stop from the fixed offset;
6. append `trailing_stop.armed` with versioned, redacted lineage.

For a long, `stop = watermark - offset`. For a short,
`stop = watermark + offset`. The fixture values must keep the initial trailing
stop coherent with the scenario's initial SL; the engine never invents policy
from a live profile.

If the SL fills first, ordinary sibling cleanup cancels TP1 and a later attempt
to fill that cancelled TP1 is a no-op. If TP1 fills first, the SL is cancelled
before the trailing order becomes visible. The state transaction rolls the
position, orders, ledger, and events back together if any step throws.

## Watermark and Trigger Algorithm

Before matching open orders after a price move, the engine updates each active
versioned trailing order from the current deterministic mid price:

- long: `new_watermark = max(old_watermark, mid)` and the stop may only rise;
- short: `new_watermark = min(old_watermark, mid)` and the stop may only fall;
- an adverse or duplicate price produces no state write and no lifecycle event;
- a favorable change persists the new watermark and stop, then appends exactly
  one `trailing_stop.updated` event.

Matching then uses the ordinary `TRIGGER` crossing rule. A gap through the stop
fills at the next available simulated top-of-book price. Before the normal fill
event is emitted, the trailing order receives terminal state `triggered`; one
`trailing_stop.triggered` transition identifies the execution. The normal
reduce-only fill closes only the remaining position, and full closure cancels
any residual protection sharing the parent lineage.

## Persistence, Replay, and Idempotence

The trigger order persists these state fields in its metadata:

- state version;
- state status (`active` or `triggered`);
- activation TP1 order ID;
- favorable watermark;
- fixed offset;
- parent entry lineage.

The order quantity and stop price remain the canonical remaining quantity and
derived stop. Restart reconstructs them through the existing typed order
serialization. Replaying the entry returns the original order. Replaying a TP1
fill, a duplicate price, an unchanged adverse price, or a filled trailing order
does not create another order, fill, fee, PnL booking, or lifecycle event.

The deterministic trailing client-order ID is derived from the parent entry and
TP1 identities. An existing derived child is accepted only when its immutable
intent and versioned state lineage match; a conflict fails explicitly.

## Costs and PnL

TP1 and trailing execution use the existing `fillOrder()` path and
`FakeFillCostModel`. The position ledger therefore records one entry quantity,
the configured TP1 exit quantity, and the exact trailing remainder. Final close
evidence must report coherent entry/exit quantities, complete fill lineage, and
the existing non-null cost model versions. Missing or invalid cost inputs remain
exceptions; the trailing feature does not introduce a zero-cost fallback.

## Fixtures and Golden Evidence

`tests/fixtures/fake-paper/tp1-trailing-v1.json` contains explicit long and short
cases. Each case fixes entry direction, total quantity, TP1 quantity, initial SL,
TP1 price, absolute offset, favorable moves, an adverse move, and a gap price.
The fixtures are test-only Fake/Paper inputs.

Golden scenario 13 becomes `executable`, removes
`trailing_stop_not_implemented`, and runs deterministic long and short paths.
Focused tests cover TP1 arming, exact remainder, favorable monotonic movement,
no loosening, gap execution, duplicate prices, restart, TP1/SL race ordering,
cleanup, event uniqueness, lineage redaction, and single-count costs/PnL.

## Rollback

Rollback removes the policy and matching branches, restores scenario 13 to
`partial` with `trailing_stop_not_implemented`, and removes the focused fixture
and documentation. Before running a revision that does not understand the
additive trailing metadata, archive or quarantine any Fake state file containing
`fake-tp1-trailing-v1`; never silently reuse it. Rollback does not alter or enable
any network, demo, testnet, or mainnet write path.
