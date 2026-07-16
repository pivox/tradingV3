# Fake Slippage Cost Model v1 Design

## Scope

This lot closes the `slippage_model_zero` gap of issue #196 for the deterministic
Fake/Paper exchange. It introduces a versioned, non-zero additional slippage
cost for taker fills and propagates that cost through Fake events, REST/WS fill
snapshots, the persistent fill-cost ledger, position close evidence, and the
golden scenario catalog.

It does not implement funding, maker fallback, depth simulation, trailing stops,
stop-attachment compensation, liquidation, or exchange writes.

## Cost model

`FakeFillCostModel` is the single deterministic source for Fake fill cost
classification and calculation:

- model version: `fixed_adverse_slippage_bps_v1`;
- taker additional slippage: `5` basis points of fill notional;
- maker additional slippage: explicit `0`;
- maker classification: `postOnly=true`;
- taker classification: market, immediately crossing limit, trigger/protection,
  and any other non-post-only fill;
- notional: `quantity * fill_price * contract_size`;
- monetary outputs: rounded to 12 decimal places.

The existing fill price remains the deterministic top-of-book or submitted
maker price. The model does not worsen the execution price. This prevents the
same slippage from being represented both in the fill price and as a separate
cost.

The bid/ask spread is already reflected in top-of-book execution prices. This
lot does not subtract a second spread cost. `spread_cost_usdt` remains explicit
`0` for Fake fills under the versioned `top_of_book_embedded_spread_v1` rule.

## Event and fill propagation

Every Fake fill event includes:

- `liquidity_role`;
- `fill_fee`;
- `spread_cost_usdt`;
- `slippage_cost_usdt`;
- `cost_model_version`;
- `spread_model_version`;
- the existing `pnl_source` and completeness metadata.

Both `FakeExchangeEventNormalizer` and `FakeExchangeAdapter::getFillsSnapshot()`
copy these fields into `ExchangeFillDto::metadata`. REST reconciliation and
private-WS projection therefore produce the same cost payload and the same
idempotency fingerprint.

Missing or invalid generic-provider cost metadata remains `NULL` in
`FillCostLedgerIngestionService`. Only finite, non-negative explicit values are
accepted for spread and slippage. Invalid values add stable quality flags and
cannot certify net PnL.

## Position and PnL aggregation

The matching engine accumulates entry and exit slippage separately in position
metadata alongside fee and notional ledgers. On full close, the payload exposes
the total `slippage_cost_usdt` and the explicit `spread_cost_usdt`.

The existing certified PnL formula then subtracts the additional slippage once:

```text
recorded_pnl_usdt =
  gross_realized_pnl_usdt
  - entry_fee_usdt
  - exit_fee_usdt
  - slippage_cost_usdt
```

Other existing explicit Fake components are unchanged in this atomic lot.
Partial fills aggregate cost per fill, and replaying the same fill does not
create or double-count another ledger entry.

## Runtime and golden evidence

`runtimeModelMetadata()` publishes the new slippage and spread model versions
plus the configured slippage basis points. `FakeRuntimeCheck` accepts only the
versioned non-zero model and no longer emits `fake_paper_slippage_model_zero`.

Golden scenario 5, `market_with_slippage`, becomes executable. Its deterministic
runner proves:

- a market order fills at the existing top of book;
- the fill is classified as taker;
- the additional slippage cost is positive and exactly 5 bps of notional;
- the same scenario run from fresh state produces identical normalized output.

Maker coverage proves explicit zero slippage without classifying an unknown cost
as zero.

## Tests and safety

Tests cover model arithmetic, maker/taker classification, contract-size scaling,
partial-fill aggregation, REST/WS parity, ledger persistence, close-event PnL,
runtime metadata, golden execution, replay idempotence, redaction, and restart
stability.

The implementation remains local and deterministic. It adds no credential,
network call, demo/testnet permission, strategy change, or exchange mutation.

## Rollback

Rollback restores the prior `explicit_zero_additional_slippage_v1` runtime
metadata and returns golden scenario 5 to `partial/slippage_model_zero`. Existing
state remains readable because costs are stored in additive metadata and the
persistent state schema is unchanged. Ledger rows already emitted by the new
model remain immutable audit evidence and must not be rewritten.
