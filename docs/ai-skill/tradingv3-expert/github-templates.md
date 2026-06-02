# GitHub Templates - TradingV3 Expert

## Atomic Issue

```markdown
## Context

## Problem

## Hypothesis

## Scope
- In:
- Out:

## Acceptance Criteria
- [ ] ...
- [ ] ...

## Validation
- [ ] Unit tests:
- [ ] Integration tests:
- [ ] Backtest/OOS:
- [ ] Logs/audit:

## Risk

## Rollback
```

## Atomic PR

```markdown
## Summary
- ...
- ...

## Why

## Changes
- ...

## Validation
- [ ] Command:
- [ ] Result:

## Trading Risk
- SL guaranteed:
- Sizing impact:
- Exchange impact:
- Statistical impact:

## Rollback
```

## Review Request

```markdown
Please review this PR with focus on:

- Risk management regressions
- Strategy/exchange boundary leaks
- Execution idempotence
- YAML fallback behavior
- Missing statistical validation
- Missing tests
```

## Labels

Recommended labels:

- `area:strategy`
- `area:risk`
- `area:execution`
- `area:exchange`
- `area:backtest`
- `area:docs`
- `type:bug`
- `type:experiment`
- `type:refactor`
- `risk:high`
- `risk:low`

## PR Size Rules

One PR should change one hypothesis.

Split if it changes more than one of:

- Signal condition.
- Risk sizing.
- Stop placement.
- Exchange adapter.
- Backtest engine.
- YAML schema.
- Persistence/audit schema.

## Acceptance Criteria Examples

Risk:

- No `OrderIntent` can be created without a stop-loss plan.
- Quantized position risk remains under configured risk cap.
- Rejection reason is persisted for every risk failure.

Exchange:

- Adapter rejects unsupported order types before provider call.
- Symbol precision fixtures cover BTC, ETH and one low-price alt.
- Reconciliation links local intent to exchange order id.

Statistics:

- Report includes Wilson CI and trade count.
- OOS window is fixed and documented.
- Multiple-tested variants are listed.
