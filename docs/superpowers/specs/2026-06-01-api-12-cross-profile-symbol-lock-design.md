# API-12 Cross-Profile Symbol Lock Design

## Context

API-11 makes it possible to run multiple Temporal schedules for the same exchange and market type with different MTF profiles. The current `decision_key` idempotency includes `strategy_profile`, so it blocks duplicate submissions for the same profile but allows two different profiles to reserve the same symbol in the same minute.

The first unsafe case is:

```text
bitmart:perpetual:BTCUSDT:...:long:scalper:v1
bitmart:perpetual:BTCUSDT:...:long:scalper_micro:v1
```

Both decisions are distinct, but they target the same tradable instrument. The global business rule is therefore:

```text
exchange + market_type + symbol can have only one active opening owner.
```

## Decision

Use option A from the design discussion: create a dedicated persistent `symbol_execution_lock` table and a small manager service that reserves/releases locks atomically around `OrderIntent` reservation.

This is intentionally separate from `order_intent` idempotency because the lock has a different lifetime, diagnostic surface, and release policy.

## Data Model

`SymbolExecutionLock` stores:

- `exchange`
- `market_type`
- `symbol`
- `status`
- `owner_profile`
- `owner_decision_key`
- `owner_order_intent_id`
- `locked_at`
- `expires_at`
- `released_at`
- `release_reason`
- `payload`
- timestamps

PostgreSQL gets a partial unique index on:

```sql
(exchange, market_type, symbol) WHERE released_at IS NULL
```

Doctrine tests also use repository lookups so the behavior remains verifiable in the existing test harness.

## Reservation Flow

`ExecuteOrderPlan` already calls `OrderIntentManager::reserveIntent()` before validating and sending an order. The new lock is acquired inside `OrderIntentManager::reserveIntent()` in the same database transaction, after checking the existing profile-scoped decision key and before flushing a new `OrderIntent`. The legacy reservation path without a `decision_key` also goes through the same global symbol lock.

If another active lock exists, reservation returns a blocked `OrderIntentReservation` with:

```text
reason = cross_profile_symbol_locked
```

The returned lock metadata includes the blocking profile, blocking decision key, and blocking order intent id where available.

## Release Flow

The first version releases locks when the local `OrderIntent` reaches a non-active terminal state:

- `FAILED`
- `CANCELLED`
- `PositionClosedEvent` for the same exchange/market/symbol, when no local open position remains

The lock remains active when an intent is `SENT`, because that may represent an open order or active position. The existing position-close event flow releases the lock after exposure is closed; no broader reconciliation refactor is included in this PR.

Automatic release and TTL reclaim refuse to release if an open local `positions` row or open local `futures_order` row exists for the same exchange/market/symbol. Manual release uses the same guard unless `--force` is explicitly passed.

## Diagnostics

Add commands:

```bash
php bin/console app:symbol-lock:list
php bin/console app:symbol-lock:release BTCUSDT --exchange=bitmart --market-type=perpetual --reason=manual_investigation
php bin/console app:symbol-lock:release BTCUSDT --exchange=bitmart --market-type=perpetual --reason=manual_investigation --force
```

Forced releases are logged and persisted with `release_reason`.

## Testing

Regression coverage focuses on the risk boundary:

- same exchange/market/symbol with a different profile is blocked;
- same symbol on a different exchange or market type is allowed;
- different symbol is allowed;
- failed/cancelled intents release the lock;
- position-close events release the lock when no local open position or open order remains;
- expired locks are reclaimed only when no local open position or open order remains;
- manual release refuses while a position is open unless `--force`;
- list/release command output is covered at command level.

## Scope Limits

This PR does not add profile preemption, does not change MTF validation rules, and does not enable multiple live profiles by itself.
