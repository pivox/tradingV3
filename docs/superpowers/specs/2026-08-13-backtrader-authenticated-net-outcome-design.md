# Backtrader Authenticated Net Outcome Design

## Scope

This #191 lot turns a closed canonical Backtrader execution into a deterministic
net outcome without moving cost authority from PHP to Python. It covers a stop
or one declared canonical target. It does not claim historical funding,
estimate a holding-expiry exit, simulate partial fills, or persist a portfolio
ledger.

## Decision

`CanonicalOrderPlan` already authenticates the complete adverse stop cost and
each target's complete net reward. The backtest settlement adapter selects the
matching authenticated branch after execution and emits its components. It
does not multiply rates, notionals, prices, or quantities in Python.

For a target exit, the authoritative components are the target's
`grossReward`, entry/target fees, entry/target spread, entry/target slippage and
`fundingCost`; `netReward` and `netR` are copied as the PHP-certified totals.
For a stop exit, the authoritative components are `grossStopLoss`, entry/stop
fees, entry/stop spread, entry/stop slippage and plan `fundingCost`;
`totalStopLoss` is copied as the PHP-certified loss. Stop net PnL and R are the
negative of those authenticated magnitudes.

The output calls funding `planned_adverse_funding_cost`, with
`funding_evidence=canonical_plan_provision`. It must never be described as
historical or realized funding. Historical timestamped funding needs a new PHP
authority and verified dataset stream and remains a separate atomic lot.

## Contract and lineage

The immutable result uses `canonical-backtest-net-outcome.v1` and includes the
dataset id/checksum, plan/config/cost-input hashes, modern identity, symbol,
side, terminal event identity, exact decimal components, cost-basis version and
an outcome hash. Decimal values are encoded as exact JSON numbers. The adapter
revalidates the plan and requires the execution event to match the same plan,
config, dataset, quantity and authenticated stop/target price.

Non-executed plans produce no trade outcome. A holding-expiry result, unknown
target, missing event, forged event lineage, non-terminal trace, or mismatched
cost arithmetic fails closed with a stable error code.

## Testing

Golden and mutation tests cover target and stop settlement, long and short net
signs, exact decimal serialization, component reconciliation, lineage
tampering, unsupported holding expiry and deterministic result hashing. A
dependency-boundary test prevents fee, spread, slippage or funding formulas
from being implemented in the Python settlement adapter.

## Deferred work

Historical funding application, arbitrary-price exits, maker/taker fallback,
partial fills, gap execution, durable ledger aggregation, metrics and
PostgreSQL replay remain later #191 lots. No live/private exchange path is
added; the input plan remains fake local/test only.
