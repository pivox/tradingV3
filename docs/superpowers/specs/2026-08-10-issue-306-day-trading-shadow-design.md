# #306 — `day_trading` Shadow Baseline Design

## Scope

Issue #306 introduces the first modern, executable `day_trading` baseline. It
publishes an immutable `day_trading` mode contract at version `1.1.0` and an
immutable `day_trading.trend_continuation.long` setup contract at version
`1.1.0`. Both are restricted to shadow execution through Fake, Paper, and
backtest consumers. Private mainnet execution remains forbidden.

The existing `1.0.0` contracts remain unchanged. The new contracts never load,
alias, merge, or fall back to `regular` configuration or its generic provider.
The existing `day_trading.trend_continuation.short` setup remains blocked at
`1.0.0`; correcting and activating a short setup is outside this baseline.

This version is an explicit shadow hypothesis. It can produce decisions,
canonical order plans, simulated execution, and certification evidence, but it
cannot be promoted on the strength of this issue alone. Promotion depends on
the Paper #132 proof and sample requirements.

## Mode contract `day_trading@1.1.0`

The mode is published with a `shadow` lifecycle and is executable only when
the runtime capability is Fake, Paper, or backtest. Its compatible setup
catalog may retain both trend-continuation setup identifiers, but only
`day_trading.trend_continuation.long@1.1.0` is executable. Compatibility never
overrides the setup's own lifecycle.

The mode decisions are:

- continuous crypto session availability, 24 hours per day;
- an eight-hour maximum holding horizon (`PT8H`);
- mandatory closure before the next `00:00 UTC` daily boundary;
- evaluation cadence and decision validity of `PT15M`;
- `4h` regime, `1h` context, `15m` trigger and execution, with mandatory `5m`
  and `1m` confirmations;
- no implicit timeframe substitution, especially no implicit `1m` fallback;
- risk budget of 5 percent of equity per trade;
- daily-loss cap equal to the lower of 6 percent of equity and 30 USDT,
  including realized loss, unrealized loss, and pending reservations;
- at most four concurrent positions or pending reservations;
- mode notional exposure capped at 100 percent of equity;
- isolated margin and leverage capped at 2x.

The risk percentages, absolute daily cap, concurrency limit, session, horizon,
timeframe roles, daily boundary, exposure cap, and leverage cap are stored as
versioned decisions rather than PHP defaults. Every amount records its unit and
every time decision records its timezone or duration semantics.

## Long setup `day_trading.trend_continuation.long@1.1.0`

The setup is a long-only `shadow` setup. Its MTF rule tree is derived from the
existing long hypothesis, with exact provenance, but is copied into the new
contract and validated independently. Rules are side-specific. No global or
short rule participates in the long decision.

All rule operands are evaluated through the typed, fail-closed evaluator from
#303. Missing conditions, unknown operators, invalid operand types,
contradictory side context, absent samples, and stale samples yield a
structured `no_trade`; they never become truthy and never select a fallback.

Selection is deterministic: the setup evaluates the fixed timeframe chain and
builds a plan on `15m` only after the required `5m` and `1m` confirmations pass.
There is no dynamic descent, overlapping stay/drop threshold, or alternative
execution timeframe. Consequently, ADX, estimated R, zone width, and live
spread are consumed only by explicit typed rules or guards, not by a hidden
selector.

## Canonical entry and protection policy

The setup compiles directly to the #304 canonical execution policy. It defines
the following entry zone:

- VWAP anchor on `5m`;
- ATR source on `5m` with multiplier `0.30`;
- minimum half-width rate `0.0005` and maximum half-width rate `0.0100`;
- symmetric bounds;
- outward exchange-tick quantization;
- 240-second TTL;
- maximum source age of 60 seconds.

The stop is exclusively ATR-based on `5m` with multiplier `1.5`. It has no
pivot or generic stop fallback. The target policy has one target at 2.0 gross
R. The complete plan must retain at least 1.3 net R after fees, live spread,
modelled slippage, and applicable funding. Failure at any target or an unknown
cost rejects the complete plan.

The order policy is limit-only with maker intent, a 90-second TTL, mandatory
cancellation at 120 seconds, and no market fallback. The shadow guards require
live spread no greater than 6 basis points and estimated slippage no greater
than 8 basis points. These guard values and the 1.3 net-R threshold are
versioned shadow hypotheses, not unversioned runtime defaults.

The time stop is `PT8H`, additionally bounded by the next UTC daily boundary.
The earlier limit always wins. The runtime must not create a plan whose
remaining admissible lifetime is non-positive.

## Cost and market-data contract

The fee schedule is resolved for the selected exchange and instrument. Entry,
stop, and target liquidity roles are explicit. Spread comes from a current
order-book snapshot, slippage from the selected execution model, and funding
from the venue schedule over the admissible holding duration. The contract
does not contain exchange-generic fee values.

Every critical source carries identity, observation time, and provenance.
Missing, stale, non-finite, or identity-mismatched market data or costs yield
`no_trade`. Environment configuration owns the allowed symbol universe; the
setup does not silently substitute a symbol or venue.

## Resolution and runtime flow

The runtime has one authoritative path:

`ModeContract -> SetupContract -> EffectiveConfig -> typed evaluation ->`
`canonical OrderPlan -> #304 reservation -> simulated execution -> outcome`

The resolver requires explicit `mode_id`, `mode_version`, `setup_id`,
`setup_version`, exchange, and environment. It produces the strict immutable
snapshot defined by #133. The snapshot's canonical hash and all four contract
identity fields propagate through evaluation, rejection, plan, reservation,
order, partial fills, lifecycle events, and outcome as required by #302.

The #304 risk authority is the sole source of sizing and portfolio admission.
It applies the per-trade budget, daily cap, concurrency cap, mode exposure,
isolated leverage cap, reservations, and partial-fill accounting uniformly.
The same canonical plan and guards are consumed by Fake, Paper, and backtest;
adapters may model fills but may not reinterpret the policy.

Shadow execution stops before every private mainnet boundary. The prohibition
is capability-based and tested, not inferred from an environment name.

## Provenance

Every copied rule and numeric decision records its exact source path and source
version. Values originating in `trade_entry.regular.yaml` are treated only as
historical inputs to the new hypothesis; the runtime does not read that file.
Newly decided values are labelled as #306 shadow hypotheses with the decision
date and issue reference.

At minimum, provenance distinguishes:

- historically sourced risk, daily cap, concurrency, ATR, zone, target, and
  order timing values;
- #306 decisions for fixed timeframe roles, UTC boundary semantics, leverage,
  exposure, freshness, net-R acceptance, and live cost guards;
- exchange-resolved fees, tick rules, funding, and instrument constraints;
- runtime observations for prices, spread, ATR, VWAP, and rule operands.

The effective-config hash commits to the fully resolved values and provenance,
so two materially different inputs cannot share a certification cell.

## Fail-closed outcomes

A rejection is an intentional, serializable outcome with the full contract
identity and config hash. Stable reason categories cover at least:

- incompatible or non-shadow-capable runtime;
- unknown contract version or legacy alias;
- missing, stale, contradictory, or invalid rule data;
- failed MTF condition or mandatory confirmation;
- unavailable or excessive spread/slippage;
- unresolved fee, funding, tick, or instrument constraint;
- invalid or expired EntryZone;
- stop polarity or minimum-net-R failure;
- daily-loss, concurrency, exposure, leverage, or reservation rejection;
- insufficient time before the UTC boundary.

No category authorizes a fallback config, timeframe, side, price source, stop,
order type, or cost.

## Verification

Contract and schema tests cover immutability, exact `1.1.0` identities,
compatibility, lifecycle/capability restrictions, declared units, provenance,
and rejection of `regular`, `scalper`, or other aliases.

Deterministic fixtures cover:

- a valid long decision and canonical plan;
- failed long conditions and explicit `no_trade`;
- missing and stale data at each mandatory timeframe;
- excessive spread and slippage, unresolved costs, and insufficient net R;
- EntryZone, ATR stop, 2R target, tick quantization, TTL, cancellation, and UTC
  horizon behavior;
- daily loss, concurrency, exposure, leverage, reservation, and partial-fill
  enforcement;
- config identity and hash propagation through all outcomes;
- identical policy decisions across Fake, Paper, and backtest;
- blocked short and inaccessible private-mainnet paths.

Implementation proceeds test-first at each boundary. Targeted suites, the full
PHP test suite, configuration validation, static analysis, and linting must pass
before a draft PR is opened. Shadow/Paper evidence is collected later under the
#132 certification work; this issue does not tune thresholds from its own
results.

## Out of scope

- activation or redesign of the short setup;
- `scalping`, `micro_scalping`, `crash_short`, or `swing_trading`;
- BitMart removal;
- threshold tuning from Paper outcomes;
- production promotion or any private mainnet execution;
- the 50-certified-trades-per-cell campaign and final #132 exports.
