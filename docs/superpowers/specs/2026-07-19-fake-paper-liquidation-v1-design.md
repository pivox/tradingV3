# Fake/Paper Liquidation v1 Design

## Scope

Prompt 8 of issue #196 adds a deterministic liquidation model and a preventive
guard to the adapter-level Fake/Paper exchange. Version 1 supports isolated
margin for linear USDT-settled perpetual positions only. Cross margin is an
explicit fail-closed capability gap until a portfolio-level equity and
maintenance model exists.

This lot does not alter strategy, MTF, EntryZone, sizing, trade frequency, or
any Bitmart, OKX, Hyperliquid, demo, testnet, or mainnet transport. It adds no
exchange call and grants no write permission.

## Audited Preconditions and Gaps

- The branch and `origin/main` start at merge PR #288,
  `46c07ea972b3983b589d734e228396184c27ad03`, which contains Prompt 7.
- Issue #196 is open and no concurrent Prompt 8 implementation PR exists. The
  only open PR observed is unrelated PR #134.
- The canonical Prompt 8 and the common foundation in section 2 are present
  and were read in full. The referenced
  `PROMPT_MAITRE_ORCHESTRATION_CODEX_TRADINGV3_V2.md` is absent from the
  worktree, every Git object/ref, and repository-wide GitHub search; no content
  was invented for it.
- `FakeInstrument` already requires exact positive `contract_size` and
  `maintenance_margin_rate`, and the catalog is versioned.
- `FakeOrderValidator` already validates instrument, precision, notional,
  leverage, margin mode, and available margin, but currently accepts both
  `isolated` and `cross`.
- `FakeExchangeStateStore` persists orders, positions, balances, leverage,
  order books, events, faults, and private-WS state in one checksum-protected
  envelope. `runAtomically()` serializes mutations and rolls back the complete
  runtime snapshot on an exception.
- Position margin is the isolated initial margin accumulated from entry fills.
  Positions already retain entry/exit fees, spread, slippage, contract size,
  and lineage metadata.
- Market movement currently changes bid/ask and uses their midpoint for stop
  matching. There is no separately named or persisted mark-price source, so a
  liquidation implementation must not infer mark from a last trade or silently
  fall back to the book.
- Full-close payloads currently publish `liquidation_fee_usdt=0.0` for every
  close. `FillCostLedgerIngestionService` always stores a null liquidation fee
  for fills even if metadata were to provide one.
- Funding and the UTC Daily loss cap already use exact decimal facts and an
  append-only event journal. The Daily loss cap recognizes ordinary fill
  events only, so a dedicated liquidation fill must be added to its monetary
  event set without adding a second PnL fact.
- The existing TradingCore `LiquidationGuard` only checks a caller-provided
  authoritative liquidation price against a stop. It is float-based and is not
  wired into the Fake matching engine; it remains unchanged by this lot.

## Criterion Matrix

| Criterion | Component | Proof boundary | Fail-closed condition | Rollback |
|---|---|---|---|---|
| Isolated linear calculation | Pure calculator and versioned policy | Exact long/short unit tests | Missing or malformed quantity, entry, margin, contract size, MMR, or leverage lineage | Remove calculator/policy; preserve historical event facts |
| Cross unsupported | Matching preflight and `setLeverage` | Cross setting and entry tests | Stable `liquidation_cross_margin_unsupported` rejection before exposure | Restore former cross acceptance only after reverting model/runtime claims |
| Mark price only | Persistent explicit mark map | Preflight, guard, gap, restart tests | Missing/non-positive mark; no book/trade fallback | Archive/quarantine new state before older code if required |
| Distinct guard buffer | Calculator result and position metadata | Safe/guard/liquidate state tests | Buffer malformed, non-positive, or not between entry and threshold | Remove alert/evaluation path; retain audit events |
| Dedicated liquidation fill | Matching engine under state transaction | Long, short, exact threshold, and gap tests | Invalid persisted liquidation state or unknown fee | Revert event generation; never rewrite a prior liquidation as a normal fill |
| Separate known fee | Policy, event payload, ledger ingestion | Fee amount/model/currency and PnL tests | Fee rate/model/currency missing or invalid | Preserve fee-bearing rows/events as historical facts |
| Protection cleanup | Matching engine | Multiple stale protection tests | Any active protection for the liquidated position remains | Revert liquidation path as one atomic lot |
| Atomic lifecycle/ledger/balance/position | Existing state transaction plus projection batch | Rollback and balance-delta tests | Any sub-mutation throws | Runtime snapshot and state file remain unchanged |
| Exact-once restart/replay | Deterministic liquidation identity and persisted terminal state | Filesystem restart then repeated mark event | Conflicting persisted identity/payload | Keep state quarantined on incompatible rollback |
| Runtime readiness | Adapter model metadata and Fake runtime-check | Valid, missing mark, cross state, invalid model tests | Model/policy/mark/metadata/recovery not ready | Remove readiness claim with implementation |

## Considered Approaches

### Extend the TradingCore stop/liquidation guard

That service is a plan-safety checker around a supplied liquidation price. It
does not own Fake balances, positions, mark events, fills, protections, or
restart state, and its arithmetic is float-based. Extending it would mix a
generic pre-trade SL contract with an adapter-specific execution simulator.
This approach is rejected.

### Subscribe asynchronously to market events

An event subscriber could observe price movements and close positions later,
but the alert, liquidation fill, protection cancellation, balance delta, and
position removal would span multiple commits. A crash between them would break
the Prompt 8 atomicity and replay requirements. This approach is rejected.

### Pure decimal calculator plus transactional Fake orchestration

The selected approach adds a small calculator/policy boundary and keeps state
orchestration in the existing Fake matching transaction. A controlled market
move first persists an explicit mark, then evaluates open positions before
ordinary stop/limit matching. The same transaction either records the complete
guard/liquidation result or restores the prior state.

## Versioned Isolated Model

The policy is immutable and exposes:

```text
model_version              = fake-isolated-linear-liquidation-v1
guard_buffer_rate          = 0.010000000000  (1% of entry price)
liquidation_fee_rate       = 0.005000000000  (50 bps of mark notional)
liquidation_fee_currency   = USDT
liquidation_fee_model      = fake-liquidation-notional-fee-v1
mark_price_source          = fake-controlled-mark-v1
supported_margin_mode      = isolated
cross_margin               = unsupported
```

These are synthetic Fake/Paper assumptions, not claimed exchange parity. The
non-zero liquidation fee is explicit and known; it is never defaulted from an
absent value. Policy values are plain positive decimal strings and are exposed
through runtime model metadata.

For position quantity `q`, contract size `c`, entry price `E`, isolated margin
`M`, and maintenance-margin rate `r`, define `Q = q * c`:

```text
long equity(P)  = M + Q * (P - E)
short equity(P) = M + Q * (E - P)
maintenance(P)  = Q * P * r

long liquidation price  = (Q * E - M) / (Q * (1 - r))
short liquidation price = (Q * E + M) / (Q * (1 + r))
```

The long threshold may be exactly zero at 1x isolated margin; that is a valid
non-negative boundary, not an unknown price. All other thresholds must be
finite, non-negative, and on the adverse side of entry. The short threshold
must be strictly positive and above entry. Maintenance rate must satisfy
`0 < r < 1`.

The distinct guard amount is `E * guard_buffer_rate`:

```text
long guard price  = liquidation_price + guard_amount
short guard price = liquidation_price - guard_amount
```

The guard price must lie strictly between the liquidation threshold and entry.
At a positive explicit mark `P`:

```text
long:  liquidate when P <= liquidation; guard when liquidation < P <= guard
short: liquidate when P >= liquidation; guard when guard <= P < liquidation
```

Everything in the calculator, candidate-position aggregation, fee calculation,
and balance delta uses `Brick\Math\BigDecimal` with scale 12 and `HALF_EVEN`.
Floats remain DTO projections only.

## Preflight and Fill-Boundary Enforcement

For every non-reduce-only exposure increase, the matching engine evaluates the
candidate position after exact client-order replay and the Daily loss/One-Way
guards, but before order creation or fill side effects. It requires:

- `isolated` margin;
- catalog instrument and exact positive contract size/MMR;
- explicit positive leverage or the persisted/default 1x setting;
- explicit controlled mark price;
- valid resulting isolated margin, weighted entry price, liquidation threshold,
  and guard price;
- current mark outside the guard and liquidation zones.

If a same-side position already exists, preflight aggregates its exact persisted
quantity, entry and isolated margin with the prospective fill. A legacy or
malformed position without these facts blocks the increase rather than
reconstructing them from floats or symbols.

Resting entry orders are re-evaluated at the fill boundary with the actual fill
quantity and execution price. A zero-fill order becomes `REJECTED`; a partially
filled order has only its remainder cancelled and its existing exposure remains
protected through the established fail-safe path. Reduce-only and protection
orders bypass liquidation preflight so risk reduction is never blocked.

Stable rejection reasons distinguish cross unsupported, mark unknown,
metadata/calculation invalid, and entry already inside the guard. Audit metadata
contains only model/version, side, margin mode, safe decimal thresholds/rates,
and stable reason codes. It excludes raw payloads, paths, credentials, headers,
URLs, and environment values.

## Explicit Mark State and Guard Alerts

The Fake state adds an additive `markPrices` map. Fresh BTCUSDT and ETHUSDT
fixtures initialize explicit known marks. A legacy persisted envelope without
the field hydrates with no mark and therefore fails closed for new exposure and
runtime readiness; it never derives a mark from bid/ask or last trade.

`FakeExchangeOrderBook::movePrice()` explicitly updates both the synthetic book
and the controlled mark from the provided deterministic market move. A direct
mark setter remains a domain operation, not an exchange transport. The scenario
service evaluates liquidation under the existing file lock before ordinary
matching.

Every open position stores the calculated threshold, guard, exact inputs,
policy/fee versions, margin mode, and last mark. Entering the guard zone appends
one `liquidation.guard_entered` audit event per unchanged position calculation
and updates mark/unrealized PnL without closing the position. Repeated marks and
restart do not duplicate the alert. A later exposure increase creates a new
calculation and can arm a new alert.

## Liquidation Lifecycle and Costs

Threshold crossing, including a gap beyond it, closes at the observed mark,
never at the theoretical threshold and never from a last trade. The engine
creates a deterministic reduce-only MARKET liquidation order and records:

1. `liquidation.triggered` with threshold, guard, mark, side, and model lineage;
2. a terminal dedicated `liquidation.filled` event with the exact full position
   quantity and mark execution price;
3. `position.closed` carrying `close_reason=liquidation` and the certified close
   payload.

The liquidation fill has ordinary taker fee/spread/slippage facts plus a
separate liquidation fee:

```text
liquidation_fee_usdt
  = abs(position_quantity) * mark_price * contract_size
    * liquidation_fee_rate
```

`FillCostLedgerIngestionService` reads that component only when it is present,
valid, and non-negative; invalid input produces a quality flag and null, never
zero. Normal non-liquidation fills retain null at the row level unless a known
component is explicitly supplied. The position close payload uses the explicit
known fee and subtracts it exactly once from recorded net PnL.

The Daily loss cap treats `liquidation.filled` as the single monetary fill fact:
realized gross minus ordinary fee/spread/slippage minus liquidation fee. The
subsequent `position.closed` lifecycle fact is not folded again. The ledger fill
ID includes the persistent event sequence/type/order identity, so REST/private
WS replay projects the same row exactly once.

## Atomic Position, Protection, Balance, and Replay Contract

Before removing the position, every active STOP_LOSS, TAKE_PROFIT, TRIGGER, or
trailing protection for the exact symbol/position side is cancelled with
`reason=position_liquidated`. Entry orders of unrelated symbols/sides are not
touched.

The same Fake transaction then saves the filled liquidation order, appends the
dedicated fill and lifecycle facts, removes the position, and applies the
certified close net delta to the USDT balance. The stored total/equity balance
changes by gross realized PnL minus entry/exit trading fees, spread, slippage,
and the liquidation fee. Releasing isolated margin is reflected by the existing
derived available-margin calculation, so margin is not credited a second time.

The liquidation client ID is derived from immutable position identity, side,
opened time, and model version. A completed transaction has no open position to
liquidate again. A crash or exception before commit restores order, position,
balance, protections, events, and sequences together. Restart/repeated mark
evaluation therefore cannot add a second fill, balance delta, PnL, or fee.

## Runtime Readiness, Documentation, and Rollback

The Fake adapter publishes policy/model/mark/fee metadata. Runtime readiness is
blocking when:

- policy values or versions differ from the supported v1 contract;
- instrument contract size or maintenance rate is missing/invalid;
- a required explicit mark is absent/invalid;
- any persisted leverage setting or open position uses cross margin;
- an open position lacks valid persisted liquidation metadata;
- the existing clock, Daily loss cap, cost model, persistence, or protection
  checks fail.

Readiness remains local dry-run only with trade permissions false, kill switch
active, and no credentials.

Rollback reverts the calculator/policy, mark map, matching branches, ledger
field ingestion, runtime/config, tests, and documentation as one lot. Do not
delete or rewrite liquidation events or ledger rows. Archive or quarantine a
Fake state file containing liquidation v1 facts before running code that cannot
interpret them. Re-enable cross only together with an explicit replacement
portfolio model; never as a fallback. No exchange cleanup is required because
the feature is strictly local and sends no order.
