# Issue #304 Canonical Risk Runtime Design

## Scope and delivery shape

Issue #304 is delivered in three sequential lots. The split is for reviewability only; all three lots remain required before #304 is complete.

1. **Lot A — canonical risk authority:** explicit units, cost-aware sizing, conservative quantity/leverage quantization, and post-quantization invariants.
2. **Lot B — canonical EntryZone and OrderPlan:** one strict zone policy, fresh inputs, side-correct protection, net-R enforcement, and no legacy fallback.
3. **Lot C — portfolio enforcement and parity:** daily loss, concurrency, mode exposure, partial-fill reservations, and one deterministic engine shared by runtime, Fake, Paper, and backtest.

The explicit legacy request path remains unchanged. Modern execution remains fail-closed until every required mode/setup/exchange decision and runtime input is defined. No lot enables real mainnet writes.

## Current-state defects being replaced

The effective modern contracts express `trade_budget` as `percent_equity_per_trade`, while `CanonicalTradeEntryConfigFactory` currently requires `quote_notional`. The preparatory risk module can still select legacy percentage fields and can calculate a fallback capital base that applies the risk percentage twice. Leverage can still apply timeframe and liquidity multipliers before later execution layers apply additional multipliers. EntryZone currently owns defaults, falls back between anchors, creates its own wall-clock timestamp, accepts ambiguous percentage representations, and can produce a zone without ATR. The modern runtime policy validator therefore blocks every request instead of enforcing the published policies.

These blockers are removed only when their concrete consumer exists. A blocker is never deleted merely because a configuration value is present.

## Canonical units and contract compilation

External contracts retain human-readable units. A new strict policy compiler converts them exactly once into internal names whose unit is unambiguous:

- `percent_equity_per_trade` becomes `riskRate`, with `0.4` compiled to `0.004`;
- `percent_equity_notional` becomes `exposureRate`;
- percentage daily limits become rates and absolute quote limits remain quote amounts with an explicit currency;
- basis points become rates by division by 10,000;
- exchange fee, funding, spread, and slippage rates remain fractions in `[0, 1)`;
- prices, quantities, quote amounts, tick sizes, and quantity steps remain named absolute values.

Modern DTOs do not expose `riskPct`, `risk_pct_percent`, or a generic percentage normalizer. Values outside their declared bounds, unknown units, non-finite values, missing currencies, or conflicting duplicate sources reject compilation. Existing semantic versions are not mutated to manufacture missing decisions; a newly resolved contract is published as a new version.

The canonical policy is immutable and carries the effective-config hash plus mode, setup, side, network, venue, environment, and contract versions. Every decision and outcome carries the same identity.

## Lot A: cost-aware risk and leverage kernel

### Inputs

The pure kernel receives:

- equity and available quote balance observed at a timestamp;
- side, entry price, stop price, target prices, contract size, tick size, quantity step, minimum and maximum quantity;
- one mode-owned per-trade `riskRate`;
- exchange, mode, symbol, and environment leverage caps;
- explicit maker/taker role for each execution and complete conservative costs for entry, stop, and target paths;
- the immutable policy identity and input snapshot time.

Unknown critical inputs reject. Entry and stop fee rates are derived from their explicit liquidity roles and the authenticated exchange schedule; callers cannot supply an independent fee rate. Entry and stop spread/slippage are distinct mandatory inputs. Perpetual funding is required when the maximum holding window can cross a funding boundary: positive rates are adverse to longs and negative rates are adverse to shorts. A cost model cannot silently assume zero.

### Sizing

For notional `N`, the stop-path loss is:

```text
gross_stop_loss = N * abs(entry_price - stop_price) / entry_price
stop_path_costs = entry_cost(N) + stop_exit_cost(N) + adverse_funding_cost(N)
total_stop_loss = gross_stop_loss + stop_path_costs
risk_budget_quote = equity * riskRate
maximum_notional = risk_budget_quote / (stop_distance_rate + stop_path_cost_rate)
```

Quantity is derived from `maximum_notional`, then rounded down to the exchange quantity step. Steps below `1e-12` or not representable on the supported 12-decimal grid reject, and every cap is revalidated after quantization with hard fail-closed bounds rather than an absolute epsilon. Minimum quantity or minimum notional is never reached by rounding up; such an order rejects. Maximum quantity, market maximum quantity, available-margin capacity, and every leverage cap are applied before a final quantity is selected.

The engine recalculates stop-path loss from the final price, quantity, contract size, and costs. It emits an executable decision only when:

```text
final_stop_path_loss <= risk_budget_quote
final_leverage <= min(all applicable caps)
```

No timeframe, confidence, liquidity, or execution multiplier can increase quantity or leverage. If an adjustment is later required, it creates a new full calculation from the original canonical inputs and re-runs all invariants.

### Leverage

Required leverage is derived from final notional and the canonical collateral base. The final exchange leverage is conservatively quantized so it cannot exceed any cap. If leverage quantization reduces notional capacity, quantity is reduced and risk is recalculated. A floor cannot increase leverage beyond a cap and is not part of the modern contract unless explicitly owned by a versioned policy.

### Output

The immutable result records requested budget, final quantity/notional, final leverage, every cap, gross stop loss, separate entry/stop cost components, total stop loss, quantization deltas, input timestamps, and policy identity. Policy construction is private and accepts only an effective snapshot whose canonical payload hash has been recomputed successfully. Failed invariants return stable rejection codes and never a partially executable plan.

## Lot B: EntryZone, protection, OrderPlan, and net R

### EntryZone policy

Each executable setup owns exactly one EntryZone policy with:

- one explicit anchor source and timeframe;
- one explicit ATR source and timeframe;
- ATR multiplier, minimum and maximum half-width rates;
- optional side asymmetry with explicit bounds;
- TTL and maximum input age;
- mandatory tick quantization policy.

The calculator receives an injected clock and timestamped anchor, ATR, and market snapshots. Missing, stale, non-finite, or incompatible inputs reject. There is no VWAP-to-reference, pivot-to-ATR, or configuration-default fallback. The zone records `observedAt`, `computedAt`, and `expiresAt`; it must be finite, ordered, fresh, and contain the selected candidate price after tick quantization.

Unresolved current setup contracts remain non-executable. Lot B supplies the strict runtime consumer and schema; it does not invent EntryZone values for those setups.

### Protection and side coherence

The stop policy is evaluated before sizing. Long stops must be below entry and long targets above entry; short stops must be above entry and short targets below entry. ATR or pivot inputs required by the selected policy cannot fall back to another policy. Every stop and target is tick-quantized in the conservative side-specific direction and revalidated afterward.

### Net R

Net R uses the same cost snapshot as sizing:

```text
net_reward = gross_target_profit - entry_cost - target_exit_cost - adverse_funding_cost
net_risk = gross_stop_loss + entry_cost + stop_exit_cost + adverse_funding_cost
net_R = net_reward / net_risk
```

Every configured target must meet the setup's `minimum_net_r`, or the order rejects. Missing fee, spread, slippage, funding, holding-window, or target data rejects. Gross nominal R never substitutes for net R.

### Runtime boundary

The modern preparation path calls the canonical pipeline directly and builds a TradingCore OrderPlan only from accepted canonical decisions. It does not map back through legacy `TradeEntryConfig`, `OrderPlanBuilder`, `ExecutionBox`, MARKET fallback, or legacy YAML defaults. A final pre-submit validator recomputes the risk, cap, side, freshness, and net-R invariants from the serialized plan. A changed market/cost/portfolio snapshot requires a new calculation.

## Lot C: portfolio policy and partial fills

### Portfolio state port

A read-only port supplies an atomic, timestamped portfolio snapshot scoped by network, venue, account, mode, and quote currency. It contains equity, realized net PnL for the policy day, unrealized loss when the contract says it is included, open positions, pending orders, reserved risk, and notional exposure.

The daily-loss contract must explicitly define day boundary/timezone and whether unrealized losses count. Existing contracts that lack those semantics remain blocked and require a new version rather than an inferred default.

### Guards

Before reserving an intent, one atomic policy decision enforces:

- remaining daily loss under both percentage and absolute quote caps;
- maximum concurrent positions including pending entries according to the contract;
- projected mode exposure including the candidate and pending reservations;
- per-trade risk after portfolio-level reductions;
- unique reservation identity tied to the lineage decision key.

Unavailable or stale portfolio state rejects. Contradictory caps resolve to the most restrictive value. The reservation and accepted plan are persisted atomically for Paper/Fake paths; there is no mainnet execution activation.

### Partial fills

Filled risk and residual order risk are accounted separately under the same reservation. Each fill recomputes actual protected quantity, remaining quantity, worst-case stop loss, fees, exposure, and available budget. An over-budget residual is reduced or cancelled; an unprotected filled quantity rejects further entry and triggers the existing safe compensation path. Reservation release is idempotent on cancellation, terminal rejection, or full close.

## Determinism and parity

The canonical engine is pure except for injected clock and state ports. Runtime, Fake, Paper, and backtest adapters construct the same versioned input snapshot and call the same compiler, calculators, validators, and serializer. They may differ only in their market/account data source, which is recorded in lineage. Golden fixtures assert byte-stable decisions and equal rejection codes for identical inputs.

Mainnet remains public/read-only. No adapter may select a private mainnet execution port as part of #304.

## Failure model

Stable failures are grouped by boundary:

- `canonical_policy_*` for missing, unresolved, conflicting, or unit-invalid policy;
- `canonical_market_*` for missing, stale, or invalid market/cost inputs;
- `canonical_entry_zone_*` for invalid or expired zones;
- `canonical_risk_*` for sizing and post-quantization budget breaches;
- `canonical_leverage_*` for cap or capacity breaches;
- `canonical_net_r_*` for incomplete costs or insufficient net R;
- `canonical_portfolio_*` for daily, concurrency, exposure, state, and reservation failures.

Exceptions carry structured evidence but no executable fallback. Logs and lineage store the stable code, policy hash, input hash, and failed invariant without secrets.

## Mandatory verification

The completed three-lot implementation proves:

- property tests for risk, leverage, caps, and quantization across long and short cases;
- `0.4 percent` compiles to `0.004 rate` and never to `0.4 rate`;
- exact mode/setup/side/profile identity survives LIMIT and MARKET preparation;
- close and distant stops, and missing ATR/pivot, reject or size conservatively as specified;
- unknown fees, spread, slippage, funding, or holding window reject;
- contradictory caps use the most restrictive cap;
- no cumulative multiplier can increase final risk or leverage;
- post-quantization stop loss stays within budget;
- partial fills preserve filled plus residual risk accounting;
- daily loss and concurrency use atomic portfolio state;
- runtime, Fake, Paper, and backtest produce equal decisions from equal snapshots;
- modern execution never calls a legacy fallback or a private mainnet port.

Focused tests, the relevant integration suite, static analysis, schema/YAML validation, Symfony container lint, and repository CI must all be green before each lot is merged.
