-- #132 bad/loss trades baseline export.
--
-- Usage:
--   psql "$DATABASE_URL" \
--     -v from_ts='2026-01-01 00:00:00+00' \
--     -v to_ts='2026-12-31 23:59:59+00' \
--     -v output_file='/tmp/bad-trades-baseline-v2.csv' \
--     -f docs/handbook/reports/queries/bad-trades-baseline-v2.sql
--
-- The export is intentionally conservative:
-- - base population comes from position_trade_analysis_v2;
-- - certified metrics require complete costs, full close, no quality flags, and net PnL/R;
-- - order_intent is joined only through internal_trade_id and exact venue/symbol scope;
-- - EntryZone is joined only through a unique order_intent decision_key;
-- - fill ledger aggregates are joined only through internal_trade_id and exact venue/symbol scope;
-- - no symbol-only or time-window reconciliation is performed.

\set ON_ERROR_STOP on
\if :{?from_ts}
\else
  \set from_ts '1970-01-01 00:00:00+00'
\endif
\if :{?to_ts}
\else
  \set to_ts '9999-12-31 23:59:59+00'
\endif
\if :{?output_file}
\else
  \set output_file 'bad-trades-baseline-v2.csv'
\endif

\copy (
WITH scoped AS (
  SELECT pta.*
  FROM position_trade_analysis_v2 pta
  WHERE pta.mtf_profile IN ('regular', 'scalper', 'scalper_micro')
    AND pta.entry_time >= :'from_ts'::timestamptz
    AND pta.entry_time < :'to_ts'::timestamptz
),
enriched AS (
  SELECT
    pta.*,
    COALESCE(jsonb_array_length(COALESCE(pta.pnl_quality_flags, '[]'::jsonb)), 0) AS pnl_quality_flag_count,
    order_scope.order_intent_match_status,
    order_scope.order_intent_id,
    order_scope.client_order_id,
    order_scope.exchange_order_id,
    order_scope.decision_key,
    order_scope.direction,
    order_scope.order_intent_side,
    zone_scope.zone_match_status,
    zone_scope.zone_dev_pct,
    zone_scope.zone_max_dev_pct,
    zone_scope.entry_zone_width_pct,
    zone_scope.zone_reason,
    zone_scope.zone_category,
    ledger_scope.ledger_fill_count,
    ledger_scope.entry_fill_count,
    ledger_scope.exit_fill_count,
    ledger_scope.maker_fill_count,
    ledger_scope.taker_fill_count,
    ledger_scope.unknown_liquidity_fill_count,
    ledger_scope.ledger_quality_flag_count
  FROM scoped pta
  LEFT JOIN LATERAL (
    SELECT
      CASE
        WHEN count(*) = 0 THEN 'missing_order_intent'
        WHEN count(*) = 1 THEN 'unique'
        ELSE 'identifier_conflict'
      END AS order_intent_match_status,
      CASE WHEN count(*) = 1 THEN min(oi.id) END AS order_intent_id,
      CASE WHEN count(*) = 1 THEN min(oi.client_order_id) END AS client_order_id,
      CASE WHEN count(*) = 1 THEN min(oi.exchange_order_id) END AS exchange_order_id,
      CASE WHEN count(*) = 1 THEN min(oi.decision_key) END AS decision_key,
      CASE
        WHEN count(*) = 1 AND min(oi.side) = 1 THEN 'long'
        WHEN count(*) = 1 AND min(oi.side) = 4 THEN 'short'
        WHEN count(*) = 1 THEN 'non_entry_side'
        ELSE 'unknown'
      END AS direction,
      CASE WHEN count(*) = 1 THEN min(oi.side) END AS order_intent_side
    FROM order_intent oi
    WHERE pta.internal_trade_id IS NOT NULL
      AND oi.internal_trade_id = pta.internal_trade_id
      AND oi.exchange IS NOT DISTINCT FROM pta.exchange
      AND oi.market_type IS NOT DISTINCT FROM pta.market_type
      AND oi.symbol = pta.symbol
  ) order_scope ON true
  LEFT JOIN LATERAL (
    SELECT
      CASE
        WHEN order_scope.decision_key IS NULL THEN 'missing_decision_key'
        WHEN count(*) = 0 THEN 'missing_entry_zone'
        WHEN count(*) = 1 THEN 'unique'
        ELSE 'identifier_conflict'
      END AS zone_match_status,
      CASE WHEN count(*) = 1 THEN min(tze.zone_dev_pct) END AS zone_dev_pct,
      CASE WHEN count(*) = 1 THEN min(tze.zone_max_dev_pct) END AS zone_max_dev_pct,
      CASE WHEN count(*) = 1 THEN min(tze.entry_zone_width_pct) END AS entry_zone_width_pct,
      CASE WHEN count(*) = 1 THEN min(tze.reason) END AS zone_reason,
      CASE WHEN count(*) = 1 THEN min(tze.category) END AS zone_category
    FROM trade_zone_events tze
    WHERE order_scope.decision_key IS NOT NULL
      AND tze.decision_key = order_scope.decision_key
      AND tze.exchange IS NOT DISTINCT FROM pta.exchange
      AND tze.market_type IS NOT DISTINCT FROM pta.market_type
      AND tze.symbol = pta.symbol
      AND tze.timeframe IS NOT DISTINCT FROM pta.timeframe
  ) zone_scope ON true
  LEFT JOIN LATERAL (
    SELECT
      count(*)::int AS ledger_fill_count,
      count(*) FILTER (WHERE f.fill_role = 'entry')::int AS entry_fill_count,
      count(*) FILTER (WHERE f.fill_role = 'exit')::int AS exit_fill_count,
      count(*) FILTER (WHERE f.liquidity_role = 'maker')::int AS maker_fill_count,
      count(*) FILTER (WHERE f.liquidity_role = 'taker')::int AS taker_fill_count,
      count(*) FILTER (WHERE f.liquidity_role = 'unknown')::int AS unknown_liquidity_fill_count,
      COALESCE(sum(jsonb_array_length(COALESCE(f.quality_flags, '[]'::jsonb))), 0)::int AS ledger_quality_flag_count
    FROM fill_cost_ledger f
    WHERE pta.internal_trade_id IS NOT NULL
      AND f.internal_trade_id = pta.internal_trade_id
      AND f.exchange IS NOT DISTINCT FROM pta.exchange
      AND f.market_type IS NOT DISTINCT FROM pta.market_type
      AND f.symbol = pta.symbol
  ) ledger_scope ON true
)
SELECT
  entry_event_id,
  close_event_id,
  entry_time,
  close_time,
  mtf_profile,
  symbol,
  timeframe,
  direction,
  exchange,
  market_type,
  run_id,
  correlation_run_id,
  orchestration_run_id,
  dashboard_id,
  set_id,
  internal_trade_id,
  trade_id,
  position_id,
  order_intent_match_status,
  order_intent_id,
  client_order_id,
  exchange_order_id,
  decision_key,
  zone_match_status,
  zone_dev_pct,
  zone_max_dev_pct,
  entry_zone_width_pct,
  zone_reason,
  zone_category,
  ledger_fill_count,
  entry_fill_count,
  exit_fill_count,
  maker_fill_count,
  taker_fill_count,
  unknown_liquidity_fill_count,
  ledger_quality_flag_count,
  (
    analysis_status = 'matched_closed'
    AND close_match_status = 'matched'
    AND cost_completeness = 'complete'
    AND pnl_quality_flag_count = 0
    AND position_fully_closed IS TRUE
    AND net_pnl_usdt IS NOT NULL
    AND realized_net_pnl_r IS NOT NULL
  ) AS is_certified,
  analysis_status,
  close_match_status,
  close_matched_by,
  cost_completeness,
  pnl_quality_flags,
  position_fully_closed,
  net_pnl_usdt,
  gross_realized_pnl_usdt,
  recorded_pnl_usdt,
  estimated_net_pnl_usdt,
  realized_net_pnl_r,
  realized_gross_pnl_r,
  pnl_r,
  risk_usdt_at_entry,
  risk_usdt,
  notional_usdt,
  fees_usdt,
  entry_fee_usdt,
  exit_fee_usdt,
  other_trading_fees_usdt,
  spread_cost_usdt,
  slippage_cost_usdt,
  slippage_usdt,
  funding_usdt,
  borrow_cost_usdt,
  liquidation_fee_usdt,
  total_known_cost_usdt,
  mfe_r,
  mae_r,
  mfe_pct,
  mae_pct,
  mfe_price,
  mae_price,
  mfe_at,
  mae_at,
  mfe_mae_data_quality,
  holding_time_sec,
  entry_rsi,
  entry_atr,
  atr_pct_entry,
  entry_volume_ratio,
  entry_macd,
  entry_ma9,
  entry_ma21,
  entry_vwap,
  snapshot_kline_time,
  initial_stop_price,
  stop_distance_pct,
  planned_r_multiple,
  expected_r_multiple
FROM enriched
ORDER BY entry_time ASC, entry_event_id ASC
) TO :'output_file' CSV HEADER
