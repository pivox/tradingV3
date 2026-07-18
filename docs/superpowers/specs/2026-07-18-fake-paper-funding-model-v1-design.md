# Fake/Paper Funding Model v1 Design

## Scope

This lot implements Prompt 4 of issue #196: deterministic, exchange-neutral,
persistent funding for Fake/Paper perpetual positions and executable golden
scenario 18. It does not change strategies, MTF rules, entry zones, trading
frequency, One-Way policy, liquidation, or any live/demo/testnet write gate.

The existing `fill_cost_ledger` remains the monetary system of record. Funding
is a cost-only ledger fact: it never creates an entry or exit fill and never
calls an exchange trading endpoint.

## Audited Preconditions

- The branch starts at merge PR #284 and Prompt 3 is present on `origin/main`.
- `FillCostLedgerIngestionService` already supports signed funding rows, but its
  legacy adjustment method identifies events by an arbitrary event ID and
  requires `internal_trade_id`.
- `HyperliquidNormalizedFundingDto` proves that provider funding currently has
  a signed native amount, currency, rate, and occurrence time, but there is no
  shared exchange-neutral DTO/event.
- `NetPnlCertificationService` and the certified SQL contract already interpret
  positive funding as a credit and negative funding as a debit.
- Fake order and position mutations already use `Psr\Clock\ClockInterface`, and
  `FakeExchangeStateStore` persists typed Fake events and event sequences.

## Considered Approaches

### Recalculate funding when a position closes

This would need little state, but it cannot represent explicit funding
deadlines, partial exposure at a deadline, late events, or an open position
surviving a restart. It also makes absence look like a derived zero. Rejected.

### Persist directly from the Fake engine into Doctrine

This would make ledger writes immediate, but it couples the deterministic Fake
domain to a database implementation and bypasses the normalizer/event pipeline
used by all exchanges. It would make restart tests depend on PostgreSQL and
would not prove exchange-neutral ingestion. Rejected.

### Persist Fake facts, then normalize and project them

The selected flow is:

```text
controlled clock + explicit schedule + open position snapshot
  -> FakeFundingModel
  -> funding.accrued Fake event persisted exactly once
  -> FakeExchangeEventNormalizer
  -> ExchangeFundingReceived(ExchangeFundingDto)
  -> DoctrineExchangeLocalProjectionStore
  -> FillCostLedgerIngestionService
  -> fill_cost_ledger cost-only row
```

This follows the existing Fake private-event pipeline, keeps the calculation
unit-testable without a database, and lets the ledger enforce a second exact
idempotency boundary during replay.

## Model and Schedule Contract

`FakeFundingModelConfig` is immutable and versioned. Version 1 fixes:

- model version `fake-funding-notional-rate-interval-v1`;
- decimal output scale 12 with HALF_EVEN rounding;
- known USDT-normalized settlement currency `USDT`;
- positive amount means account credit and negative amount means account debit.

`FakeFundingSchedule` carries an exact `due_at`, nullable signed funding rate,
the interval represented by that rate, the applied interval, and settlement
currency. There is no fuzzy time-window lookup. A schedule is eligible only
when `due_at <= clock.now()`; a future schedule fails explicitly.

For each matching open position:

```text
notional = abs(position_size) * mark_price * contract_size
unsigned = notional * funding_rate * applied_interval / rate_interval
long amount  = -unsigned
short amount = +unsigned
```

Consequently, a positive rate makes longs pay and shorts receive; a negative
rate reverses those roles. The position size and mark price are captured at the
explicit deadline application. Contract size comes from
`margin_contract_size`, with the Fake catalog value `1` as the deterministic
default.

A missing rate yields an `unknown` result and no monetary event. No position
yields `no_position`. Missing/non-positive mark price or contract size fails
closed. A non-USDT currency keeps the signed native amount but sets normalized
`amount_usdt` to `null`; ledger projection persists `funding_usdt=NULL` with
`funding_currency_not_normalized` rather than inventing a conversion or zero.

## Identity, Persistence, and Replay

The stable position identity is chosen in this order:

1. exact `position_id` metadata;
2. exact `internal_position_id` metadata;
3. deterministic Fake identity from exchange, market, symbol, side, and
   immutable `opened_at`.

Both Fake-state and ledger idempotency use the canonical tuple:

```text
position_identity + due_at UTC + model_version
```

The tuple is hashed only to fit storage limits; all components remain in the
redacted audit payload. Replaying an identical event returns the existing fact.
Reusing the same tuple with a different monetary payload is a conflict. Older
deadlines may arrive after newer deadlines because identity is deadline-based,
not sequence-window-based.

The Fake state store performs check-and-append under its existing file lock and
persists the normalized event sequence. A restart reloads the event and rejects
the same settlement tuple without allocating a new sequence. The ledger unique
key independently protects database replay and concurrent ingestion.

`internal_trade_id` is copied when present and otherwise resolved from exact
position lineage by the ledger service. Missing lineage is visible through the
existing `missing_lineage` flag; there is no symbol/time fallback.

## Exchange-Neutral Event and Ledger Projection

`ExchangeFundingDto` contains venue/market/symbol, position side and identity,
optional lineage, notional, rate, intervals, signed native amount/currency,
nullable normalized USDT amount, deadline, source, and model version.

`ExchangeFundingReceived` is the only normalized event produced for a
`funding.accrued` Fake event. It is not an `ExchangeFillReceived`, and the
Doctrine projection only calls funding ledger ingestion. It does not call
`syncTradeFromApi`, create a futures trade, or modify entry/exit quantities.

The ledger row uses `fill_role=funding`, null price/quantity/order/fill venue
identifiers, the exact position identity, signed `funding_usdt` when known, and
the model/deadline details in the redacted raw reference. The schema-required
`fill_id` is a deterministic cost-entry identifier and is not exposed as an
exchange fill ID.

## Fixtures and Golden Scenario 18

`funding-model-v1.json` is the versioned deterministic fixture with positive,
negative, and absent rates. Golden scenario 18 opens controlled long and short
positions, applies exact deadlines, replays a duplicate, persists/reloads Fake
state, applies a late deadline, and verifies:

- long and short pay/receive signs;
- partial-position notional;
- no funding for no position or absent rate;
- one normalized funding event and zero normalized fills per settlement;
- duplicate/restart replay does not add an event;
- model/config version and lineage are present;
- known and unknown currency behavior stays explicit.

## PnL Convention

Funding is a signed PnL adjustment, not an always-positive cost:

```text
net_pnl = gross_pnl - fees - spread - slippage - borrow - liquidation + funding
```

The aggregation sums each unique funding ledger row once. A positive credit
raises net PnL; a negative debit lowers it. Missing funding remains `NULL` and
prevents complete certification. Tests cover duplicate/restart replay so the
same deadline cannot affect net PnL twice.

## Safety and Rollback

The change is local Fake/Paper logic only. It introduces no credential access,
network request, exchange order, demo/testnet mutation, or mainnet path.
`mainnet_write_enabled` remains false.

Rollback is code/config removal: revert the model, DTO/event/normalizer and
projection changes plus fixtures/docs. No destructive migration is required;
funding rows already fit the additive `fill_cost_ledger` contract and remain
auditable if the feature is rolled back.

