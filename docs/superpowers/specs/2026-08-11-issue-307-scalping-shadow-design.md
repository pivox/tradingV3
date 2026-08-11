# #307 — `scalping` Shadow/Paper Baseline Design

## Scope

Issue #307 publishes one complete, immutable, executable Shadow/Paper baseline
for the modern `scalping` mode. It introduces `scalping@1.1.0` and three
independent setup contracts at `1.1.0`:

- `scalping.trend_continuation.long` for legacy scenario A;
- `scalping.pullback.long` for legacy scenario B;
- `scalping.trend_momentum.short` for the sourced short branch.

The existing `1.0.0` contracts remain unchanged and non-executable. The new
contracts never load, alias, merge, or fall back to `scalper` configuration.
They may cite the legacy files as provenance, but all effective values are
copied into immutable modern contracts and runtime layers.

The baseline is executable only through Fake, Paper, and backtest consumers.
Public mainnet market data may feed Paper evaluation, but private mainnet
execution remains forbidden. Promotion and the campaign of 50 certified trades
per executable cell remain part of the later #132 certification work.

## Architecture decision

The implementation extracts the mode-neutral orchestration already proven by
#306 into a shared Shadow runtime core. Small `DayTradingShadowRuntime` and
`ScalpingShadowRuntime` facades supply the accepted contract identities and
mode-specific outcome namespace. The shared core owns the common flow:

`ModeContract -> SetupContract -> EffectiveConfig -> typed rules ->`
`canonical OrderPlan -> #304 reservation -> simulated execution -> outcome`

This keeps one authority for capability selection, lineage matching, live-cost
guards, plan construction, and portfolio admission without turning the entire
application into a generic execution engine. The existing day-trading public
API and reason codes remain stable.

Two alternatives were rejected:

- copying the complete day-trading runtime into a scalping namespace, because
  duplicated safety and lineage gates would diverge;
- replacing all modern execution with a universal configurable runtime in this
  issue, because that refactor is broader and riskier than #307.

Each runtime unit has one responsibility: the shared core performs canonical
orchestration, while a mode policy validates exact identities and maps stable
reason codes. Neither layer reads a legacy profile.

## Mode contract `scalping@1.1.0`

The mode is published with a `shadow` lifecycle and is executable only when the
requested capability is Fake, Paper, or backtest. It declares exactly the three
setup identifiers in scope.

The versioned mode decisions are:

- continuous crypto session availability with UTC accounting;
- maximum holding duration `PT2H` and mandatory closure before `00:00 UTC`;
- `1h` regime, `15m` context, `5m` trigger and execution, and mandatory `1m`
  confirmation;
- evaluation cadence and decision validity of `PT5M`;
- one risk budget of 2 percent of equity per trade;
- daily-loss cap equal to the lower of 6 percent of equity and 40 USDT,
  including realized loss, unrealized loss, and pending reservations;
- at most three concurrent positions or pending reservations;
- aggregate scalping notional capped at 75 percent of equity;
- isolated margin and leverage capped at 3x;
- BTCUSDT and ETHUSDT perpetuals only;
- maximum order notional of 25 USDT in the mode/exchange runtime layers;
- limit-only maker intent with no market fallback.

The lower legacy `risk.fixed_risk_pct` value is selected over the conflicting
7-percent request value. Successive timeframe size or leverage multipliers are
not copied. The 3x leverage and 25-USDT notional boundaries come from the
existing scalper Demo/Testnet envelopes and become explicit modern constraints.
The PT2H horizon, three-entry concurrency cap, and 75-percent exposure cap are
new conservative #307 Shadow hypotheses, not inferred facts.

## Three separate setup theses

### `scalping.trend_continuation.long@1.1.0`

This setup contains only the sourced bullish 1h regime and 15m scenario-A trend
and momentum branch. Scenario B cannot satisfy or rescue it. Its 5m trigger and
mandatory 1m confirmation remain long-only.

### `scalping.pullback.long@1.1.0`

This setup contains only the sourced bullish 1h regime and 15m scenario-B
dynamic-support pullback. It retains the sourced three-bar pullback validity
input as a condition parameter, while full decision validity remains PT5M.
Scenario A cannot satisfy or rescue it.

### `scalping.trend_momentum.short@1.1.0`

This setup contains only the sourced bearish regime, structure, and momentum
branch. It uses the sourced 5m trigger and mandatory 1m bearish confirmation.
No short pullback is invented for symmetry.

The selected setup identity is fixed before lower-timeframe evaluation. The 1m
confirmation can only accept or reject the selected thesis; it cannot change
the setup, side, execution timeframe, or decision key. This replaces the
partial legacy selector with a total deterministic rule: execute on 5m after a
mandatory 1m confirmation, otherwise return `no_trade`.

## Typed rule and MACD semantics

All rule nodes execute through the typed, fail-closed #303 evaluator. The three
ASTs retain exact source provenance while using canonical condition identifiers.
Unknown conditions, malformed operands, wrong-side data, absent observations,
and stale observations reject the setup.

Sequence-based MACD conditions receive samples in explicit oldest-to-newest
order. Their runtime contract validates that timestamps are strictly ordered
and refuses ambiguous or reversed series. Parameters attached to a setup node
are the explicit invocation values; catalogue defaults fill only an absent
parameter and never overwrite a supplied value. The evaluation trace records
the effective parameter value and its origin so a hidden override cannot alter
the result.

Failures use stable setup-qualified evidence. At minimum, outcomes distinguish
identity, regime, context, trigger, mandatory confirmation, missing/stale data,
invalid MACD order, cost, plan, and portfolio admission failures. Every outcome
retains mode, setup, version, side, decision key, catalogue hash, and effective
config hash.

## Canonical entry and protection policy

All three setups declare the same initial conservative execution envelope in
their own immutable contract. Sharing equal values does not move strategy
thresholds into the mode layer and does not merge the theses.

The entry policy is:

- VWAP anchor on `5m`;
- ATR source on `5m` with multiplier `0.22`;
- minimum half-width rate `0.0004` and maximum half-width rate `0.0065`;
- symmetric bounds and outward exchange-tick quantization;
- EntryZone TTL of 150 seconds;
- maximum critical input age of 30 seconds.

Protection and return policy is:

- ATR-only stop on `5m` with multiplier `1.5` and no pivot fallback;
- one target at 1.8 gross R;
- minimum 1.3 net R after fees, live spread, modelled slippage, and applicable
  funding;
- close-beyond-stop invalidation;
- time stop `PT2H`, additionally bounded by the next UTC daily boundary.

The order policy is a maker-intent limit with 45-second TTL, mandatory
cancellation after 75 seconds, maximum live spread of 6 basis points, maximum
modelled slippage of 8 basis points, and no market fallback. A non-filled maker
order expires and is cancelled. A partial fill reserves and accounts only
through the canonical #304 state machine; the residual order is cancelled at
the deadline. No branch converts the residual or complete entry to taker.

## Runtime configuration and market data

Modern runtime layers are added for `scalping@1.1.0` on Fake, OKX, and
Hyperliquid. They are independent of the existing `scalper.*` layers. Each
layer preserves BTC/ETH perpetual scope, a 25-USDT maximum order notional,
3x maximum leverage, stop requirement, kill switch, and disabled writes.

The resolver requires the full explicit identity: mode ID/version, setup
ID/version, exchange, environment, side, and Shadow execution capability. It
has no filename substitution and no fallback profile. Paper may resolve public
mainnet data through the read-only mainnet environment; PrivateMainnet is
rejected before resolution.

Critical data includes current OHLCV and derived indicator observations for
1h, 15m, 5m, and 1m, a current order book for the 5m execution decision, exact
instrument precision, venue fee schedule, funding schedule, and execution-model
slippage. Missing, stale, non-finite, or identity-mismatched inputs produce
`no_trade`.

Fees and instrument constraints are exchange-owned. Entry liquidity role is
maker; stop and target liquidation assumptions are taker unless a future
contract version explicitly changes them. Spread, slippage, and funding always
come from their declared runtime sources and are included in the plan hash.

## Risk, fills, and outcome parity

The #304 canonical authority is the sole source of sizing and portfolio
admission. It applies trade risk, daily loss, concurrency, mode exposure,
notional, leverage, reservation, partial-fill, cancellation, and holding
deadlines uniformly.

Fake, Paper, and backtest select isolated adapters and stores but consume the
same config snapshot, rule result, OrderPlan, and transition semantics. Adapter
fill modelling may differ only in observations; it may not reinterpret risk or
cost policy. For identical canonical observations, all three capabilities must
produce identical decisions, hashes, required actions, and net accounting.

Private mainnet execution has no adapter path. Environment `mainnet` remains a
public-data, read-only Paper source with `dry_run: true` and
`write_enabled: false`.

## Deterministic net report

The PR includes a deterministic report fixture grouped by exact setup and side.
It proves that each accepted fixture carries its full lineage and computes net
R/PnL from canonical costs. It never combines the two long setups, never
combines long and short, and never labels a fixture set as certified production
evidence.

This report is an implementation proof, not the later #132 statistical
baseline. It contains no threshold tuning. The future certification campaign
must still collect at least 50 certified trades for every executable
`network × venue × mode × setup × side` cell and must not aggregate
under-sampled cells.

## Fail-closed outcomes

Stable rejection categories cover at least:

- unsupported mode, setup, version, side, or capability;
- legacy `scalper` alias or missing modern runtime layer;
- lineage or config/catalogue hash mismatch;
- failed regime, context, trigger, or 1m confirmation;
- absent, stale, contradictory, reversed, or invalid rule data;
- unavailable or excessive spread/slippage;
- unresolved fee, funding, precision, or instrument constraint;
- invalid or expired EntryZone;
- stop polarity, target, or minimum-net-R failure;
- order TTL or cancellation deadline;
- daily-loss, concurrency, exposure, notional, leverage, or reservation failure;
- holding deadline and required close action.

No rejection category authorizes a fallback config, setup, side, timeframe,
price source, stop source, order type, or cost.

## Verification

Contract and schema tests cover immutable `1.1.0` identities, exact setup
compatibility, lifecycle and capability restrictions, units, provenance,
runtime layers, and rejection of `scalper` aliases.

Deterministic fixtures cover:

- one passing and representative failing path for each of the three setups;
- strict separation of scenario A, scenario B, and short momentum;
- fixed 5m execution and mandatory non-mutating 1m confirmation;
- chronological and reversed MACD sample series;
- explicit parameter precedence and trace provenance;
- missing/stale data at every required timeframe;
- EntryZone, ATR stop, 1.8R target, 1.3 net-R floor, tick quantization, TTL,
  cancellation, PT2H, and UTC boundary behavior;
- risk, daily loss, concurrency, mode exposure, 25-USDT notional, 3x leverage,
  reservations, and partial fills;
- maker fill, maker non-fill, partial fill, residual cancellation, and rejected
  market fallback;
- lineage and hash propagation through accepted and rejected outcomes;
- Fake/Paper/backtest parity and blocked PrivateMainnet paths;
- deterministic net report separation by setup and side.

Implementation is test-first at every boundary. Targeted PHPUnit suites, the
relevant broad regression gate, PHPStan, YAML linting, container linting, and
diff hygiene must pass before review. The ready PR receives three Codex review
cycles; blocking findings are corrected before merge.

## Out of scope

- promotion beyond Shadow or any private mainnet execution;
- tuning thresholds from deterministic fixtures or Paper outcomes;
- the 50-certified-trades-per-cell campaign and final #132 exports;
- `micro_scalping`, `crash_short`, `swing_trading`, or BitMart removal;
- invention of a pullback short setup;
- migration of unrelated legacy `scalper` consumers.
