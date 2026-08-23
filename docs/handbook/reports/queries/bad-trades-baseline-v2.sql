-- #132 bad/loss trades baseline export.
--
-- Usage:
--   psql "$DATABASE_URL" \
--     -v from_ts='2026-01-01 00:00:00+00' \
--     -v to_ts='2026-12-31 23:59:59+00' \
--     -f docs/handbook/reports/queries/bad-trades-baseline-v2.sql \
--     > /tmp/bad-trades-baseline-v2.csv
--
-- position_trade_analysis_v2 is the sole certification authority. This export
-- never scans or re-aggregates fill_cost_ledger and never substitutes recorded
-- or estimated PnL for a missing canonical certified value.
--
-- The downstream report keeps cells below 50 certified trades out of every KPI.
-- A certification cell is the exact tuple:
-- paper_network x market_data_venue x mode_id x setup_id x canonical_side.

\set ON_ERROR_STOP on
\if :{?from_ts}
\else
  \set from_ts '1970-01-01 00:00:00+00'
\endif
\if :{?to_ts}
\else
  \set to_ts '9999-12-31 23:59:59+00'
\endif
COPY (
WITH scoped AS (
  SELECT pta.*
  FROM position_trade_analysis_v2 pta
  WHERE pta.mode_id IN ('day_trading', 'scalping', 'micro_scalping')
    AND pta.entry_time >= :'from_ts'::timestamptz
    AND pta.entry_time < :'to_ts'::timestamptz
), enriched AS (
  SELECT
    pta.*,
    order_scope.order_intent_match_status,
    order_scope.order_intent_id,
    order_scope.client_order_id,
    order_scope.exchange_order_id,
    zone_scope.zone_match_status,
    zone_scope.zone_dev_pct,
    zone_scope.zone_max_dev_pct,
    zone_scope.entry_zone_width_pct,
    zone_scope.zone_reason,
    zone_scope.zone_category
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
      CASE WHEN count(*) = 1 THEN min(oi.exchange_order_id) END AS exchange_order_id
    FROM order_intent oi
    WHERE pta.internal_trade_id IS NOT NULL
      AND oi.internal_trade_id = pta.internal_trade_id
      AND oi.exchange IS NOT DISTINCT FROM pta.exchange
      AND oi.market_type IS NOT DISTINCT FROM pta.market_type
      AND oi.symbol = pta.symbol
      AND oi.mode_id IS NOT DISTINCT FROM pta.mode_id
      AND oi.mode_version IS NOT DISTINCT FROM pta.mode_version
      AND oi.setup_id IS NOT DISTINCT FROM pta.setup_id
      AND oi.setup_version IS NOT DISTINCT FROM pta.setup_version
      AND oi.config_hash IS NOT DISTINCT FROM pta.canonical_config_hash
      AND oi.condition_catalog_hash IS NOT DISTINCT FROM pta.condition_catalog_hash
      AND oi.canonical_side IS NOT DISTINCT FROM pta.canonical_side
      AND oi.decision_id IS NOT DISTINCT FROM pta.decision_id
      AND oi.intent_id IS NOT DISTINCT FROM pta.intent_id
  ) order_scope ON true
  LEFT JOIN LATERAL (
    SELECT
      CASE
        WHEN pta.decision_key IS NULL THEN 'missing_decision_key'
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
    WHERE pta.decision_key IS NOT NULL
      AND tze.decision_key = pta.decision_key
      AND tze.exchange IS NOT DISTINCT FROM pta.exchange
      AND tze.market_type IS NOT DISTINCT FROM pta.market_type
      AND tze.symbol = pta.symbol
      AND tze.timeframe IS NOT DISTINCT FROM pta.timeframe
  ) zone_scope ON true
), finalized AS (
  SELECT
    enriched.*,
    (
      lineage_classification = 'canonical'
      AND paper_eligibility = 'baseline_eligible'
      AND analysis_status = 'matched_closed'
      AND close_match_status = 'matched'
      AND canonical_cost_completeness = 'complete'
      AND canonical_pnl_quality_flags = '[]'::jsonb
      AND position_fully_closed IS TRUE
      AND canonical_net_pnl_usdt IS NOT NULL
      AND canonical_realized_net_pnl_r IS NOT NULL
      AND NULLIF(btrim(paper_network), '') IS NOT NULL
      AND NULLIF(btrim(market_data_venue), '') IS NOT NULL
      AND NULLIF(btrim(mode_id), '') IS NOT NULL
      AND NULLIF(btrim(setup_id), '') IS NOT NULL
      AND NULLIF(btrim(canonical_side), '') IS NOT NULL
    ) AS is_certified
  FROM enriched
)
SELECT
  entry_event_id,
  close_event_id,
  entry_time,
  close_time,
  mtf_profile,
  lineage_classification,
  paper_eligibility,
  paper_network,
  market_data_venue,
  mode_id,
  mode_version,
  setup_id,
  setup_version,
  canonical_side,
  canonical_config_hash AS config_hash,
  condition_catalog_hash,
  decision_id,
  decision_key,
  intent_id,
  canonical_order_id,
  canonical_position_id,
  canonical_trade_id,
  symbol,
  timeframe,
  lower(canonical_side) AS direction,
  exchange,
  market_type,
  run_id,
  canonical_correlation_run_id AS correlation_run_id,
  canonical_orchestration_run_id AS orchestration_run_id,
  canonical_orchestration_dashboard_id AS dashboard_id,
  canonical_orchestration_set_id AS set_id,
  internal_trade_id,
  trade_id,
  position_id,
  order_intent_match_status,
  order_intent_id,
  client_order_id,
  exchange_order_id,
  zone_match_status,
  zone_dev_pct,
  zone_max_dev_pct,
  entry_zone_width_pct,
  zone_reason,
  zone_category,
  CASE WHEN is_certified THEN 'v2'::text END AS certification_source,
  is_certified,
  analysis_status,
  close_match_status,
  close_matched_by,
  canonical_cost_completeness AS cost_completeness,
  canonical_pnl_quality_flags AS pnl_quality_flags,
  position_fully_closed,
  canonical_net_pnl_usdt AS net_pnl_usdt,
  gross_realized_pnl_usdt,
  recorded_pnl_usdt,
  estimated_net_pnl_usdt,
  canonical_realized_net_pnl_r AS realized_net_pnl_r,
  realized_gross_pnl_r,
  pnl_r,
  risk_usdt_at_entry,
  risk_usdt,
  notional_usdt,
  entry_fee_usdt + exit_fee_usdt + other_trading_fees_usdt AS fees_usdt,
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
  entry_first_fill_at,
  entry_last_fill_at,
  entry_qty,
  entry_vwap,
  exit_first_fill_at,
  exit_last_fill_at,
  exit_qty,
  exit_vwap,
  remaining_qty,
  quantity_status,
  mfe_r,
  mae_r,
  mfe_pct,
  mae_pct,
  mfe_price,
  mae_price,
  mfe_at,
  mae_at,
  canonical_mfe_mae_data_quality AS mfe_mae_data_quality,
  canonical_holding_time_sec AS holding_time_sec,
  entry_rsi,
  entry_atr,
  atr_pct_entry,
  entry_volume_ratio,
  entry_macd,
  entry_ma9,
  entry_ma21,
  snapshot_kline_time,
  initial_stop_price,
  stop_distance_pct,
  planned_r_multiple,
  expected_r_multiple
FROM finalized
ORDER BY entry_time ASC, entry_event_id ASC
) TO STDOUT WITH (FORMAT csv, HEADER true);
