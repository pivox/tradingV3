# Prompts - TradingV3 Expert

## Codex Prompt - Diagnostic

```text
Use the TradingV3 Expert skill.

Analyze this TradingV3 problem:

<paste logs, SQL output, YAML diff, or issue>

Return:
1. Category: strategy, risk, execution, exchange, architecture, or statistics.
2. Evidence from the provided artifacts.
3. Main risk before any signal optimization.
4. Testable hypothesis.
5. Smallest atomic change.
6. Validation plan with commands.
7. GitHub issue draft.
8. PR scope.

Constraints:
- Prioritize loss reduction.
- Do not optimize winrate alone.
- Require SL and risk validation.
- Keep strategy logic separate from exchange constraints.
```

## Codex Prompt - Implementation

```text
Use the TradingV3 Expert skill.

Implement the following atomic issue in a separate branch/worktree:

<paste issue>

Before editing:
- Inspect existing Symfony/PHP services and YAML patterns.
- Identify tests to add or update.
- Keep the diff limited to the issue scope.

During implementation:
- Preserve strategy/exchange boundaries.
- Add audit/log fields if needed to diagnose risk decisions.
- Add tests for risk, precision, fallback, or rejection behavior.

Before PR:
- Run relevant tests.
- Validate config/YAML syntax if modified.
- Summarize trading risk impact.
- Create a PR with acceptance criteria and verification.
```

## Claude Prompt - Research

```text
You are acting as TradingV3 Expert.

Research the following strategy/risk/execution question:

<question>

Do not propose code first. Produce:
- Assumptions.
- Required data.
- Failure modes.
- Statistical validation method.
- Minimal experiment.
- Rollback criteria.

Trading constraints:
- No trade without stop-loss.
- No arbitrary leverage.
- Include fees, spread, slippage, and funding.
- Require out-of-sample validation.
```

## Claude Prompt - YAML Review

```text
Use TradingV3 Expert to review this YAML change:

<paste YAML diff>

Check:
- ConditionRegistry compatibility.
- Defaults and fallback behavior.
- Entry zone width and deviation impact.
- Stop-loss/risk impact.
- Rollback criteria.
- Required logs and reports.

Return blocking issues first, then recommended changes.
```

## Prompt - Post-run Analysis

```text
Use TradingV3 Expert.

Analyze this post-run report:

<paste mtf_condition_report output, logs, or SQL>

Return:
- Dominant rejection reasons.
- Symbols or timeframes with abnormal behavior.
- Whether failures are strategy, risk, execution, exchange, or data quality.
- One atomic tuning hypothesis.
- Required validation before merge.
```
