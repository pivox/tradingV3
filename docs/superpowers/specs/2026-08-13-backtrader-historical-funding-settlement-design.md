# Backtrader Historical Funding Settlement Design

## Scope

This atomic #191 lot replaces the static plan funding provision only when a
complete, integrity-bound historical funding schedule is supplied. It adds a
strict schedule contract, a PHP settlement authority, and a bounded Python
bridge. It does not add exchange acquisition, infer mark prices from OHLC, or
claim that an integrity checksum authenticates venue data.

## Decision

The schedule is a separate canonical artifact bound to the same verified Paper
source checksum, network, venue, symbol, and market type as the candle feed.
Every settlement record carries its source record identity, funding instant,
availability instant, signed rate, exact mark price, and interval. Schedule
coverage bounds and a regular interval grid prove whether every funding instant
crossed by a trade is represented. Missing, duplicated, late, off-grid, or
identity-mismatched records fail closed.

The PHP authority receives the canonical order-plan identity, actual entry and
exit instants, exact quantity and contract size, plus the verified schedule. It
applies only records satisfying `entry_at < funding_at <= exit_at`. For a
positive venue rate, a long pays and a short receives:

```text
mark_notional = quantity * contract_size * mark_price
signed_cashflow(long)  = -mark_notional * funding_rate
signed_cashflow(short) =  mark_notional * funding_rate
```

Positive output funding is therefore a credit and negative output funding is a
debit, matching the existing certified PnL convention. Exact decimal strings
and Brick Math prevent binary-float settlement arithmetic in PHP.

## Contracts and trust

The Python schedule serializer owns canonical JSON bytes and a SHA-256 schedule
checksum. `VerifiedHistoricalFundingSchedule` re-verifies those bytes before
use and exposes the source identity needed to match the verified candle feed.
The checksum proves integrity and deterministic replay, not exchange
authenticity.

The PHP result binds dataset, schedule, plan, config, cost-input, side, quantity,
entry/exit times, applied source record ids, signed total, request hash, and
result hash. The bounded Python bridge revalidates every field and hash and
rejects child-process errors without reflecting raw input.

The net outcome may replace `planned_adverse_funding_cost_quote` with
`historical_funding_cashflow_quote` only after the schedule and PHP result pass
all checks. It continues to declare `costs_are_certified=false` and uses
`funding_evidence=integrity_bound_historical_schedule`; the remaining fee,
spread, and slippage components are still plan projections.

## Error handling

All malformed numbers, non-UTC timestamps, incomplete coverage, missing grid
records, records available after their funding instant, source mismatches,
schedule checksum mismatches, bridge timeouts, oversized I/O, and result-binding
failures use stable fail-closed reason codes. A perpetual outcome never silently
falls back from a supplied invalid historical schedule to the plan provision.

## Testing

Tests cover long debit, short credit, negative rates, multiple crossed funding
instants, boundary inclusion, no crossed instant, incomplete schedules, source
and checksum tampering, exact decimal serialization, bounded subprocess errors,
PHP/Python golden parity, and replacement of only the funding component in the
net outcome. Dependency tests forbid funding arithmetic in the Python bridge.

## Deferred work

Public OKX/Hyperliquid funding-rate acquisition, timestamped mark/index price
capture, verified Paper event projection, dynamic venue schedule rules, partial
fills, and certification remain subsequent lots. No private/user funding API,
exchange write, or real-mainnet execution path is introduced.
