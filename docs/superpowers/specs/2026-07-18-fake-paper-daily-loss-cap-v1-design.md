# Fake/Paper Daily Loss Cap v1 Design

## Scope

Prompt 7 of issue #196 adds a daily loss cap to the ordinary Fake/Paper order
path only. It must fail closed for every new entry or exposure increase when
the current UTC day has consumed the configured limit or when the monetary
facts needed to calculate that consumption are ambiguous. Reductions,
reduce-only orders, stop-losses, take-profits, triggers, and emergency closes
remain available.

This change does not alter strategies, MTF rules, EntryZone, sizing, order
frequency, live guards, or any Bitmart, OKX, Hyperliquid, demo, testnet, or
mainnet transport. It sends no exchange request.

## Audited Preconditions

- The branch and `origin/main` both start at merge
  `b1a829a6680b294ef2cf7638574266df4953780b`, which contains Prompt 6 PR
  #287.
- Issue #196 remains open and there is no concurrent open Daily loss cap PR.
- PRs #204, #207, #209, #280, and #285 define the certified net-PnL,
  fill-cost-ledger, fill-quantity, slippage, and funding contracts used here.
- Fake state already persists an append-only, sequenced event journal with an
  integrity envelope, atomic replacement, restart hydration, and idempotent
  client-order replay.
- Fake fills already persist fee, spread, slippage, currency, model lineage,
  and cost completeness. Funding events persist a signed nullable USDT amount.
- Full closes expose certified PnL, but partial reductions do not currently
  expose their realized gross PnL. The engine must therefore add a signed
  realized gross amount to every Fake fill event so partial exits can be
  accounted for without waiting for a full close.
- The baseline supplied for this worktree is green: 71 tests, 618 assertions,
  and one pre-existing deprecation.

## Criterion Matrix

| Criterion | Initial state | Component | Contract test | Main risk | Rollback |
|---|---|---|---|---|---|
| Controlled UTC day | Fake runtime already injects a clock | Daily-loss evaluator | 23:59:59 to 00:00:00 UTC | Host timezone leakage | Remove policy wiring; event history is unchanged |
| Realized net loss only | Close payload exists; partial realized PnL is absent | Matching engine fill facts and evaluator | Partial/full long and short reductions | Counting unrealized PnL or double-counting closes | Ignore additive fill field and remove evaluator |
| Known costs only | Fill costs and funding may be nullable | Evaluator completeness checks | Unknown fee/currency/funding | Treating unknown as zero | Disable entry path by reverting wiring, never rewrite ledger |
| Block exposure increase | One-Way and margin checks already run in the engine | Matching engine preflight guard | New entry rejected before margin/order creation | Late rejection after side effects | Remove the preflight call |
| Allow risk reduction | Reduce/protection intent is already classified | Policy bypass | reduce-only, SL, TP, trigger/emergency | Locking users into positions | Revert guard call; protections remain unchanged |
| Persistent reconstruction | Fake state event journal is durable | Evaluator over state events | filesystem restart and replay | Mutable or divergent counters | Remove evaluator; retained events remain readable |
| Idempotence | Event sequence and client ID exist | Monetary-event deduplication and rejection replay | exact duplicate and conflicting duplicate | Double charge or conflict hidden | Revert additive evaluator only |
| Stable rejection/audit | Fake rejected orders/events already persist | Structured rejection metadata | repeated client ID returns same rejection | Sensitive raw payload in audit | Remove new safe metadata keys |
| Versioned readiness/config | Fake model metadata and runtime checks exist | policy/config/adapter/runtime check | valid, invalid, reached, not-computable | Runtime claims ready while unsafe | Revert policy service/config version |
| Metrics | Persistent events are the source of truth | Status counters derived per evaluation | evaluated/duplicate/rejection counts | Non-auditable mutable metric state | Remove derived metadata only |

## Considered Approaches

### Mutable daily balance

Updating a counter on fills is inexpensive, but it creates a second monetary
truth that can drift after a crash, retry, replay, or repair. Resetting it at
midnight would also destroy history. This approach is rejected.

### Query Doctrine/PostgreSQL before each Fake order

The analysis ledger is authoritative for reporting, but a database query in
the synchronous Fake order path would make immediate safety depend on an
asynchronous projection and its schema availability. It would also introduce
a new runtime dependency where the Fake state already contains the canonical
facts. This approach is rejected for Prompt 7.

### Reconstruct from the persisted Fake monetary event journal

The selected design folds the current UTC-day facts from the durable Fake
state every time an exposure-increasing request is evaluated. It has no mutable
counter, is deterministic under a controlled clock, retains prior days, and
rebuilds identically after restart. Event sequences and canonical monetary
fingerprints provide replay safety.

## Monetary Convention

The day is the half-open UTC interval `[00:00:00, next 00:00:00)` obtained only
from the injected `ClockInterface`. Event timestamps are converted to UTC; the
host timezone is irrelevant.

For facts incurred within that interval:

```text
daily_net_usdt
  = sum(realized_gross_pnl_usdt on reduction fills)
  - sum(fill_fee_usdt)
  - sum(spread_cost_usdt)
  - sum(slippage_cost_usdt)
  + sum(signed funding amount_usdt)

consumption_usdt = max(0, -daily_net_usdt)
```

Entry costs are consumed when the fill occurs even if the position remains
open. Realized gross PnL is consumed only for the reduced quantity. Funding
keeps the certified convention: a positive amount is a credit and a negative
amount is a debit. Unrealized PnL and position mark-to-market values are never
used. The cap is reached when `consumption_usdt >= limit_usdt`; equality is
blocking.

All arithmetic uses `Brick\Math\BigDecimal`, scale 12, and `HALF_EVEN`. The
configured limit must be a plain positive decimal with at most 18 integer and
12 fractional digits, compatible with the project monetary primitives.
Invalid, zero, or negative limits make the policy not ready and the guard
fail-closed.

## Required Facts and Fail-Closed Rules

Every in-window fill must contain a valid event timestamp, unique event
sequence, fill quantity, fee amount/currency, spread and slippage costs,
relevant model versions, and complete cost status. Monetary values must be
finite decimal strings; costs and quantity must be non-negative; currency must
be USDT. A reduction fill additionally requires signed
`realized_gross_pnl_usdt`. Funding requires a finite signed `amount_usdt` in
USDT and its model/idempotency lineage.

Missing legacy fields, unknown currency conversions, incomplete costs,
malformed values, future monetary events relative to the controlled clock,
and conflicting duplicates produce `not_computable`. They are never converted
to zero. Facts outside the current UTC window do not affect consumption, but
their event identity remains in history.

Two monetary events with the same event sequence and the same canonical
fingerprint are counted once and increment a derived duplicate count. The same
sequence with a different fingerprint is ambiguous and therefore
`not_computable`. Funding idempotency keys remain an additional exact-once
guarantee.

## Enforcement and Idempotence

The matching engine performs these steps for an exposure-increasing request:

1. validate the Fake context and intent;
2. return an existing exact client-order replay, if any;
3. evaluate the Daily loss cap before One-Way, book, margin, order creation, or
   fill side effects;
4. persist a stable rejected Fake order and `order.rejected` event if blocked;
5. continue through the existing path only when the cap is computable and
   strictly below the limit.

An accepted resting exposure order is re-evaluated at the single fill boundary,
immediately before fill arithmetic, order mutation, position mutation, fill
events, or attached-protection creation. If the cap has become reached or not
computable since submission, the order transitions once to `REJECTED` with the
same redacted Daily loss metadata and no fill or position side effect. Repeated
manual fills or price matches return that persisted terminal order and do not
append another rejection. Reduce-only and protection fills retain the same
bypass as their submission path.

The existing replay lookup precedes the cap so a repeated client order returns
the original accepted or rejected result rather than being re-evaluated under
a later day or balance. Reduction intent follows the established contract:
`reduce_only` or standalone `STOP_LOSS`, `TAKE_PROFIT`, or `TRIGGER` orders.
These requests bypass the cap regardless of cap status so risk can always be
reduced. They still pass all existing validation and position guards.

Rejections use one of two stable reasons:

- `daily_loss_cap_reached` when a computable consumption is greater than or
  equal to the limit;
- `daily_loss_cap_not_computable` for invalid policy or ambiguous monetary
  state.

Audit metadata is redacted and contains only the policy/model version, UTC
date, status, safe block reason, limit, computable daily net/consumption when
available, and derived event/duplicate/rejection counts. It never contains a
raw order payload, filesystem path, credential, token, or environment value.

## Persistence, Day Rollover, and Metrics

No migration is needed. The fill fact is an additive event payload field and
the cap status is reconstructed from the existing persistent Fake journal.
Changing UTC day only changes the selected window; no event or counter is
deleted or reset. Restart hydration uses the same journal and therefore yields
the same status.

Operational counters are derived rather than mutated: evaluated monetary
facts, identical duplicates, invalid/conflicting facts, and daily cap
rejections. Persistent `order.rejected` events are the audit source for the
rejection count.

## Runtime Readiness and Configuration

The policy version is `fake-daily-loss-cap-v1`. Fake configuration exposes a
strict `daily_loss_cap_usdt` with a conservative default of
`100.000000000000` USDT and records the policy version in Fake runtime model
metadata. There is no silent disable switch.

The Fake runtime check is blocking when the policy is invalid, the current day
is not computable, or the cap is reached. A reached cap is an operationally
healthy calculation but the runtime is not ready for new exposure. Readiness
metadata includes only the redacted status fields described above.

## Safety and Rollback

Rollback is code/config only: revert the policy service wiring, matching-engine
preflight call, runtime check extension, and additive fill payload field. Do
not delete or rewrite Fake state events. Existing state remains backward
readable; older events lacking the new required facts intentionally stay
fail-closed while this policy is active.

Before delivery, run the focused PHPUnit suite, the proportional Fake suite,
all touched PHP through PHPStan, container lint without debug, YAML lint,
MkDocs strict, diff/secret/redaction checks, and a complete transport audit.
PostgreSQL validation is not required because this design adds no schema or
query dependency.
