# Issues Ready To Post - TradingV3 Expert

## Issue 1 - Enforce Stop-loss Protection Before Order Dispatch

```markdown
## Context
TradingV3 must never dispatch a live futures order without automatic stop-loss protection.

## Problem
If an order intent can be emitted before a valid stop-loss plan exists, the system can create unbounded downside during provider, worker, or reconciliation failures.

## Hypothesis
Blocking dispatch unless a valid stop-loss plan is attached will reduce catastrophic execution risk without changing signal logic.

## Scope
- In: order planning, validation, audit logs, tests.
- Out: strategy thresholds, TP optimization, exchange-specific refactors.

## Acceptance Criteria
- [ ] Live order dispatch fails closed when stop-loss plan is missing.
- [ ] Stop-loss price is validated after tick quantization.
- [ ] Rejection reason is persisted/audited.
- [ ] Tests cover missing SL, invalid SL, and valid SL paths.

## Validation
- [ ] Unit tests for order plan validation.
- [ ] Integration test for messenger dispatch guard.
- [ ] Manual dry-run log confirms rejection reason.

## Rollback
Revert guard if it blocks valid orders because of a schema mismatch; keep audit logs for diagnosis.
```

## Issue 2 - Add Post-quantization Risk Cap Check

```markdown
## Context
Position sizing can change after exchange precision and lot size quantization.

## Problem
Risk may be computed before quantization, while the final order size can exceed the configured risk budget.

## Hypothesis
Recomputing risk after quantization and rejecting excess risk will prevent hidden risk drift.

## Scope
- In: sizing service, exchange constraints, tests, audit fields.
- Out: strategy thresholds and entry signal logic.

## Acceptance Criteria
- [ ] Final quantity after quantization is used for risk check.
- [ ] Orders exceeding risk cap are rejected before provider call.
- [ ] Audit includes pre/post quantization quantity and risk.
- [ ] Tests cover low-price alt and high-price symbol precision.

## Validation
- [ ] Unit tests for quantized risk.
- [ ] Fixture tests for at least 3 symbols.
```

## Issue 3 - Add OOS Report Gate For YAML Strategy Changes

```markdown
## Context
YAML threshold changes can look profitable in-sample while degrading live behavior.

## Problem
Strategy PRs can be merged without a consistent out-of-sample report.

## Hypothesis
Requiring a standard OOS report template for YAML strategy changes will reduce overfitting and improve review quality.

## Scope
- In: docs, PR template/checklist, report command references.
- Out: backtest engine implementation.

## Acceptance Criteria
- [ ] Strategy YAML PR template requires dataset, OOS window, trade count, Wilson CI, costs, and rollback criteria.
- [ ] Example report is documented.
- [ ] Review checklist flags missing OOS evidence.
```

## Issue 4 - Isolate Exchange Precision In Adapter Contract

```markdown
## Context
Strategy code should not know provider-specific precision rules.

## Problem
Precision handling spread across services can leak exchange concerns into strategy or order planning code.

## Hypothesis
Centralizing precision in `ExchangeAdapterInterface::getExchangeConstraints()` will make execution safer and simplify multi-exchange support.

## Scope
- In: adapter contract, constraints DTO, tests, call sites.
- Out: adding a new exchange provider.

## Acceptance Criteria
- [ ] Strategy services consume normalized `ExchangeConstraints`.
- [ ] Provider payload precision remains inside adapter/provider layer.
- [ ] Tests cover price tick, quantity step, min size, and min notional.
- [ ] Existing BitMart behavior remains unchanged.
```

## Issue 5 - Add MTF Rejection Reason Summary Command

```markdown
## Context
MTF tuning requires fast visibility into rejection reasons by symbol, timeframe, and rule.

## Problem
Manual log queries are useful but not standardized enough for repeatable post-run analysis.

## Hypothesis
A console command that summarizes rejection reasons from logs or audit tables will make threshold tuning safer and faster.

## Scope
- In: Symfony console command, report output, tests with fixture logs.
- Out: changing MTF decision logic.

## Acceptance Criteria
- [ ] Command accepts date/since/profile filters.
- [ ] Output groups by reason, timeframe, symbol, and rule.
- [ ] CSV export is supported.
- [ ] Tests cover log fixture parsing.
```
