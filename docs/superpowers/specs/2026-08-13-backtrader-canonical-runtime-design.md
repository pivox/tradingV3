# Backtrader Canonical Runtime Design

## Scope

This #191 lot adds the first executable Backtrader boundary without moving any
trading authority to Python. It consumes verified dataset candles and an exact,
authenticated mirror of the PHP `CanonicalOrderPlan`. It emits a deterministic
execution trace for one plan. It does not aggregate portfolio metrics, persist
to PostgreSQL, calibrate market costs, or enable any private/live exchange.

## Decision

The runtime is split into three units:

1. `CanonicalBacktestOrderPlan` is a strict frozen Python wire contract. It
   carries the modern mode/setup/version/side identity, `fake` + local/test
   execution scope, entry zone, quantity, immediate stop, targets, TTL/holding
   boundaries, exact known cost inputs, config/input hashes and the PHP
   `plan_hash`. Unknown or incomplete fields fail closed.
2. `VerifiedBacktraderFeedAdapter` accepts only already verified
   `CandleRecord` values for one exact dataset/symbol/timeframe stream. It
   exposes the bar only at `available_at`, preserves UTC and decimal OHLCV, and
   rejects gaps, mixed identity, incomplete/future records and any bar outside
   the run boundary.
3. `CanonicalBacktraderRuntime` schedules one authenticated plan against that
   feed. Backtrader provides deterministic bar iteration and order lifecycle;
   the adapter owns the translation and returns canonical immutable events.
   TradingCore remains the authority for signal, EntryZone, risk, leverage,
   stop, targets, costs and plan validation.

Backtrader is pinned to `1.9.78.123`. No strategy rule, sizing formula or cost
formula is copied into a Backtrader `Strategy`.

## Execution semantics v1

- Only a limit entry is accepted because `CanonicalOrderPlan` currently emits
  `orderType=limit`.
- The order becomes eligible no earlier than both `created_at` and the bar's
  `available_at`; this prevents look-ahead through candle close data.
- Entry is possible only when the bar range crosses `entry_price`, the price is
  inside the authenticated EntryZone, and the plan has not reached
  `expires_at`/`cancel_after_at`.
- V1 fills the full authenticated quantity at the authenticated limit price.
  Partial fills and maker-to-taker fallback remain an explicit later lot; the
  runtime never invents book depth.
- The stop is attached atomically in the same canonical fill event. An entry
  without a valid protective stop is rejected before the engine starts.
- After entry, bars are evaluated only when available. A long stop triggers
  when `low <= stop`; a short stop when `high >= stop`. Targets use the inverse
  side test.
- If stop and any target are reachable in the same bar,
  `conservative_stop_first` closes at the stop. Other intra-bar policies are
  rejected in this first executable lot.
- If several targets are reachable without a stop, v1 closes the entire
  position at the first target in declared canonical order. Partial target
  reduction/trailing remains a later lot.
- `holding_expires_at` closes on the first subsequently available bar at its
  open and records `holding_expired`. An open position at dataset end is
  rejected as incomplete evidence rather than force-closed optimistically.
- A plan that never fills emits `entry_expired` or `entry_not_filled`; it never
  creates a trade.

## Evidence and determinism

Every event binds `dataset_id`, dataset checksum, plan hash, config hash,
symbol/timeframe, bar source record id and event time. The result stores a
canonical input hash and result hash. Replaying identical bytes with the pinned
engine version must produce byte-identical JSON.

The adapter rejects non-finite/coerced numbers, reordered/duplicate fields,
mixed identities, future availability, hash mismatches, unknown events and
unsupported order or intra-bar policies. Runtime errors expose stable codes and
never include child data or secrets.

## Testing

Tests are layered:

- strict contract and hash-tamper tests for the plan and result;
- feed tests for UTC, availability, stream identity, gaps and no look-ahead;
- deterministic goldens for maker fill + target, non-fill/expiry, stop, same-bar
  stop/target ambiguity and holding expiry;
- two identical runs under different `PYTHONHASHSEED` values with byte-identical
  output;
- a dependency-boundary test proving no PHP trading rule or risk formula is
  reimplemented in the Backtrader adapter.

## Deferred work

Partial fills, taker fallback, calibrated spread/slippage/funding application,
multi-plan portfolio reservations, ledger aggregation, metrics, PostgreSQL
replay and certification reports are later #191 lots. `micro_scalping` remains
blocked until real spread and OFI evidence exists. Mainnet stays public and
read-only; this runtime accepts only `exchange=fake` and local/test.
