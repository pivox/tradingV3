-- PostgreSQL fixture for #190 partial-fill quantity aggregation.
-- Requires the fill_cost_ledger table from Doctrine migration Version20260625010000.
-- The fixture intentionally uses exact internal_trade_id + exchange + market_type scopes.

DELETE FROM fill_cost_ledger
WHERE source = 'fill_quantity_aggregation_fixture_v1';

INSERT INTO fill_cost_ledger (
    idempotency_key,
    payload_hash,
    internal_trade_id,
    internal_position_id,
    position_id,
    exchange,
    market_type,
    symbol,
    side,
    fill_id,
    exchange_fill_id,
    exchange_order_id,
    client_order_id,
    order_intent_id,
    fill_role,
    liquidity_role,
    price,
    quantity,
    notional,
    fee_amount,
    fee_currency,
    fee_usdt,
    funding_usdt,
    spread_cost_usdt,
    slippage_cost_usdt,
    borrow_cost_usdt,
    liquidation_fee_usdt,
    occurred_at,
    source,
    source_version,
    quality_flags,
    raw_reference,
    created_at
) VALUES
-- Complete long: partial entry fills + TP1 + trailing remainder.
('fixture:bitmart:futures:exchange_fill:pf-entry-1', repeat('a', 64), 'fixture-trade-complete', 'fixture-pos-complete', 'BM-POS-1', 'bitmart', 'futures', 'BTCUSDT', 'BUY', 'pf-entry-1', 'pf-entry-1', 'ord-entry-1', 'client-entry-1', NULL, 'entry', 'maker', 100.000000000000, 0.400000000000, 40.000000000000, -0.020000000000, 'USDT', 0.020000000000, NULL, NULL, NULL, NULL, NULL, '2026-06-25 10:00:00+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"partial_entry"}'::jsonb, now()),
('fixture:bitmart:futures:exchange_fill:pf-entry-2', repeat('b', 64), 'fixture-trade-complete', 'fixture-pos-complete', 'BM-POS-1', 'bitmart', 'futures', 'BTCUSDT', 'BUY', 'pf-entry-2', 'pf-entry-2', 'ord-entry-1', 'client-entry-1', NULL, 'entry', 'taker', 101.000000000000, 0.600000000000, 60.600000000000, -0.030000000000, 'USDT', 0.030000000000, NULL, NULL, NULL, NULL, NULL, '2026-06-25 10:00:20+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"partial_entry"}'::jsonb, now()),
('fixture:bitmart:futures:exchange_fill:pf-tp1', repeat('c', 64), 'fixture-trade-complete', 'fixture-pos-complete', 'BM-POS-1', 'bitmart', 'futures', 'BTCUSDT', 'SELL', 'pf-tp1', 'pf-tp1', 'ord-tp1', 'client-tp1', NULL, 'exit', 'maker', 110.000000000000, 0.500000000000, 55.000000000000, -0.040000000000, 'USDT', 0.040000000000, NULL, NULL, NULL, NULL, NULL, '2026-06-25 10:10:00+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"tp1"}'::jsonb, now()),
('fixture:bitmart:futures:exchange_fill:pf-trailing', repeat('d', 64), 'fixture-trade-complete', 'fixture-pos-complete', 'BM-POS-1', 'bitmart', 'futures', 'BTCUSDT', 'SELL', 'pf-trailing', 'pf-trailing', 'ord-trailing', 'client-trailing', NULL, 'exit', 'taker', 108.000000000000, 0.500000000000, 54.000000000000, -0.050000000000, 'USDT', 0.050000000000, NULL, NULL, NULL, NULL, NULL, '2026-06-25 10:20:00+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"trailing_remainder"}'::jsonb, now()),
('fixture:bitmart:futures:internal:pf-funding', repeat('e', 64), 'fixture-trade-complete', 'fixture-pos-complete', 'BM-POS-1', 'bitmart', 'futures', 'BTCUSDT', NULL, 'pf-funding', NULL, NULL, NULL, NULL, 'funding', 'unknown', NULL, NULL, NULL, NULL, NULL, NULL, 0.120000000000, NULL, NULL, NULL, NULL, '2026-06-25 10:15:00+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"funding_credit"}'::jsonb, now()),

-- Open after TP1: residual quantity must remain positive.
('fixture:fake:paper:exchange_fill:open-entry', repeat('f', 64), 'fixture-trade-open', 'fixture-pos-open', 'FAKE-POS-1', 'fake', 'paper', 'ETHUSDT', 'BUY', 'open-entry', 'open-entry', 'ord-open-entry', 'client-open-entry', NULL, 'entry', 'maker', 200.000000000000, 2.000000000000, 400.000000000000, 0.000000000000, 'USDT', 0.000000000000, NULL, NULL, NULL, NULL, NULL, '2026-06-25 11:00:00+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"open_partial_exit"}'::jsonb, now()),
('fixture:fake:paper:exchange_fill:open-tp1', repeat('1', 64), 'fixture-trade-open', 'fixture-pos-open', 'FAKE-POS-1', 'fake', 'paper', 'ETHUSDT', 'SELL', 'open-tp1', 'open-tp1', 'ord-open-tp1', 'client-open-tp1', NULL, 'exit', 'maker', 210.000000000000, 0.800000000000, 168.000000000000, 0.000000000000, 'USDT', 0.000000000000, NULL, NULL, NULL, NULL, NULL, '2026-06-25 11:05:00+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"open_partial_exit"}'::jsonb, now()),

-- Conflicting replay: same exchange_fill_id with different quantity/price must be flagged by the service.
('fixture:fake:paper:exchange_fill:conflict-a', repeat('2', 64), 'fixture-trade-conflict', 'fixture-pos-conflict', 'FAKE-POS-2', 'fake', 'paper', 'SOLUSDT', 'BUY', 'conflict-a', 'same-exchange-fill-id', 'ord-conflict', 'client-conflict', NULL, 'entry', 'maker', 50.000000000000, 1.000000000000, 50.000000000000, 0.000000000000, 'USDT', 0.000000000000, NULL, NULL, NULL, NULL, NULL, '2026-06-25 12:00:00+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"conflicting_duplicate"}'::jsonb, now()),
('fixture:fake:paper:exchange_fill:conflict-b', repeat('3', 64), 'fixture-trade-conflict', 'fixture-pos-conflict', 'FAKE-POS-2', 'fake', 'paper', 'SOLUSDT', 'BUY', 'conflict-b', 'same-exchange-fill-id', 'ord-conflict', 'client-conflict', NULL, 'entry', 'maker', 51.000000000000, 1.000000000000, 51.000000000000, 0.000000000000, 'USDT', 0.000000000000, NULL, NULL, NULL, NULL, NULL, '2026-06-25 12:00:00+00', 'fill_quantity_aggregation_fixture_v1', 'postgres_fixture_v1', '[]'::jsonb, '{"case":"conflicting_duplicate"}'::jsonb, now());
