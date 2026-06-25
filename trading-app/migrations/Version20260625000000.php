<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\NullLogger;

/**
 * DATA-002 — Enrichit `position_trade_analysis_v2` avec le contrat PnL net certifiable.
 *
 * La vue reste read-only, versionnée et entry-based. Elle conserve le matching FIFO exact
 * livré par `Version20260623010000`, puis ajoute des colonnes financières explicites. Le net
 * certifié n'est calculé que lorsque le brut, tous les coûts obligatoires, la fermeture
 * complète, la cohérence de quantité et le lineage sont démontrés. Sinon `net_pnl_usdt` reste
 * NULL et les raisons sont exposées dans `pnl_quality_flags`.
 */
final class Version20260625000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DATA-002: expose certified net PnL contract fields in position_trade_analysis_v2';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_v2_fifo_internal ON trade_lifecycle_event (event_type, internal_trade_id, symbol, exchange, market_type, run_id, happened_at, id) WHERE internal_trade_id IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_v2_fifo_position ON trade_lifecycle_event (event_type, position_id, symbol, exchange, market_type, run_id, happened_at, id) WHERE position_id IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_v2_fifo_extra_trade ON trade_lifecycle_event (event_type, (extra->>\'trade_id\'), symbol, exchange, market_type, run_id, happened_at, id) WHERE extra ? \'trade_id\'');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_tle_v2_fifo_extra_position ON trade_lifecycle_event (event_type, (extra->>\'position_id\'), symbol, exchange, market_type, run_id, happened_at, id) WHERE extra ? \'position_id\'');
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis_v2');
        $this->addSql(<<<'SQL'
CREATE VIEW position_trade_analysis_v2 AS
WITH RECURSIVE
entry_events AS (
  SELECT
    e.id,
    e.symbol,
    COALESCE(NULLIF(e.timeframe, ''), NULLIF(e.extra->> 'timeframe', '')) AS timeframe,
    e.run_id,
    e.exchange,
    e.market_type,
    COALESCE(NULLIF(e.config_profile, ''), NULLIF(e.extra->> 'mtf_profile', '')) AS mtf_profile,
    NULLIF(e.extra->> 'orchestration_run_id', '')       AS orchestration_run_id,
    NULLIF(e.extra->> 'orchestration_dashboard_id', '') AS dashboard_id,
    NULLIF(e.extra->> 'orchestration_set_id', '')       AS set_id,
    COALESCE(NULLIF(e.internal_trade_id, ''), NULLIF(e.extra->> 'internal_trade_id', '')) AS match_internal_trade_id,
    NULLIF(e.extra->> 'trade_id', '')                   AS match_trade_id,
    COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) AS match_position_id,
    e.happened_at,
    e.extra,
    (
      SELECT s.id
      FROM indicator_snapshots s
      WHERE s.symbol = e.symbol
        AND s.timeframe = COALESCE(NULLIF(e.timeframe, ''), NULLIF(e.extra->> 'timeframe', ''))
        AND s.kline_time <= e.happened_at
      ORDER BY s.kline_time DESC
      LIMIT 1
    ) AS snapshot_id
  FROM trade_lifecycle_event e
  WHERE e.event_type = 'order_submitted'
),
close_events AS (
  SELECT
    e.id,
    e.symbol,
    e.exchange,
    e.market_type,
    e.run_id,
    e.happened_at,
    e.extra,
    COALESCE(NULLIF(e.internal_trade_id, ''), NULLIF(e.extra->> 'internal_trade_id', '')) AS match_internal_trade_id,
    NULLIF(e.extra->> 'trade_id', '') AS match_trade_id,
    COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) AS match_position_id,
    COALESCE(
      CASE
        WHEN (e.extra->> 'close_time') ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}'
        THEN (e.extra->> 'close_time')::timestamp AT TIME ZONE 'UTC'
        ELSE NULL
      END,
      e.happened_at
    ) AS effective_close_time
  FROM trade_lifecycle_event e
  WHERE e.event_type = 'position_closed'
),
opened_bridge AS (
  SELECT
    NULLIF(e.extra->> 'trade_id', '') AS trade_id,
    e.symbol,
    e.exchange,
    e.market_type,
    COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) AS position_id,
    ROW_NUMBER() OVER (
      PARTITION BY NULLIF(e.extra->> 'trade_id', ''), e.symbol, e.exchange, e.market_type
      ORDER BY e.happened_at, e.id
    ) AS rn
  FROM trade_lifecycle_event e
  WHERE e.event_type = 'position_opened'
    AND NULLIF(e.extra->> 'trade_id', '') IS NOT NULL
    AND COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) IS NOT NULL
),
entry_resolved AS (
  SELECT
    ee.*,
    COALESCE(ee.match_position_id, ob.position_id) AS eff_position_id
  FROM entry_events ee
  LEFT JOIN opened_bridge ob
    ON ob.trade_id = ee.match_trade_id
   AND ob.symbol = ee.symbol
   AND ob.exchange = ee.exchange
   AND ob.market_type = ee.market_type
   AND ob.rn = 1
),
snapshot_values AS (
  SELECT
    es.id               AS event_id,
    s.kline_time        AS snapshot_kline_time,
    s.values->> 'rsi'   AS entry_rsi,
    s.values->> 'atr'   AS entry_atr,
    s.values->> 'macd'  AS entry_macd,
    s.values->> 'ma9'   AS entry_ma9,
    s.values->> 'ma21'  AS entry_ma21,
    s.values->> 'vwap'  AS entry_vwap
  FROM entry_events es
  JOIN indicator_snapshots s ON s.id = es.snapshot_id
),
internal_entry_base AS (
  SELECT id, match_internal_trade_id AS match_key, symbol, exchange, market_type, run_id, happened_at
  FROM entry_resolved
  WHERE match_internal_trade_id IS NOT NULL
),
internal_close_base AS (
  SELECT c.id, c.match_internal_trade_id AS match_key, c.symbol, c.exchange, c.market_type,
         resolved.match_run_id AS run_id, c.effective_close_time
  FROM close_events c
  JOIN LATERAL (
    SELECT CASE
      WHEN c.run_id IS NOT NULL THEN c.run_id
      WHEN COUNT(DISTINCT e.run_id) = 1 THEN MIN(e.run_id)
      ELSE NULL
    END AS match_run_id
    FROM internal_entry_base e
    WHERE e.match_key = c.match_internal_trade_id
      AND e.symbol = c.symbol
      AND e.exchange = c.exchange
      AND e.market_type = c.market_type
      AND e.happened_at <= c.effective_close_time
  ) resolved ON resolved.match_run_id IS NOT NULL
  WHERE c.match_internal_trade_id IS NOT NULL
),
internal_events AS (
  SELECT 'entry' AS event_kind, id AS event_id, match_key, symbol, exchange, market_type, run_id,
         happened_at AS event_time, 0 AS kind_order
  FROM internal_entry_base
  UNION ALL
  SELECT 'close' AS event_kind, id AS event_id, match_key, symbol, exchange, market_type, run_id,
         effective_close_time AS event_time, 1 AS kind_order
  FROM internal_close_base
),
internal_groups AS (
  SELECT DISTINCT match_key, symbol, exchange, market_type, run_id FROM internal_events
),
internal_ordered AS (
  SELECT ie.*,
         ROW_NUMBER() OVER (
           PARTITION BY match_key, symbol, exchange, market_type, run_id
           ORDER BY event_time, kind_order, event_id
         ) AS seq
  FROM internal_events ie
),
internal_walk(match_key, symbol, exchange, market_type, run_id, seq, open_entry_ids, pair_entry_id, pair_close_id) AS (
  SELECT match_key, symbol, exchange, market_type, run_id, 0::bigint,
         ARRAY[]::bigint[], NULL::bigint, NULL::bigint
  FROM internal_groups
  UNION ALL
  SELECT w.match_key, w.symbol, w.exchange, w.market_type, w.run_id, o.seq,
         CASE
           WHEN o.event_kind = 'entry' THEN w.open_entry_ids || o.event_id
           WHEN cardinality(w.open_entry_ids) > 0 THEN COALESCE(w.open_entry_ids[2:cardinality(w.open_entry_ids)], ARRAY[]::bigint[])
           ELSE w.open_entry_ids
         END,
         CASE WHEN o.event_kind = 'close' AND cardinality(w.open_entry_ids) > 0 THEN w.open_entry_ids[1] ELSE NULL END,
         CASE WHEN o.event_kind = 'close' AND cardinality(w.open_entry_ids) > 0 THEN o.event_id ELSE NULL END
  FROM internal_walk w
  JOIN internal_ordered o
    ON o.match_key = w.match_key
   AND o.symbol = w.symbol
   AND o.exchange IS NOT DISTINCT FROM w.exchange
   AND o.market_type IS NOT DISTINCT FROM w.market_type
   AND o.run_id IS NOT DISTINCT FROM w.run_id
   AND o.seq = w.seq + 1
),
internal_pairs AS (
  SELECT pair_entry_id AS entry_event_id, pair_close_id AS close_event_id, 'matched_internal_trade_id' AS matched_by
  FROM internal_walk
  WHERE pair_entry_id IS NOT NULL AND pair_close_id IS NOT NULL
),
trade_entry_base AS (
  SELECT id, match_trade_id AS match_key, symbol, exchange, market_type, run_id, happened_at
  FROM entry_resolved e
  WHERE match_trade_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM internal_pairs p WHERE p.entry_event_id = e.id)
),
trade_close_base AS (
  SELECT c.id, c.match_trade_id AS match_key, c.symbol, c.exchange, c.market_type,
         resolved.match_run_id AS run_id, c.effective_close_time
  FROM close_events c
  JOIN LATERAL (
    SELECT CASE
      WHEN c.run_id IS NOT NULL THEN c.run_id
      WHEN COUNT(DISTINCT e.run_id) = 1 THEN MIN(e.run_id)
      ELSE NULL
    END AS match_run_id
    FROM trade_entry_base e
    WHERE e.match_key = c.match_trade_id
      AND e.symbol = c.symbol
      AND e.exchange = c.exchange
      AND e.market_type = c.market_type
      AND e.happened_at <= c.effective_close_time
  ) resolved ON resolved.match_run_id IS NOT NULL
  WHERE c.match_trade_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM internal_pairs p WHERE p.close_event_id = c.id)
),
trade_events AS (
  SELECT 'entry' AS event_kind, id AS event_id, match_key, symbol, exchange, market_type, run_id,
         happened_at AS event_time, 0 AS kind_order
  FROM trade_entry_base
  UNION ALL
  SELECT 'close' AS event_kind, id AS event_id, match_key, symbol, exchange, market_type, run_id,
         effective_close_time AS event_time, 1 AS kind_order
  FROM trade_close_base
),
trade_groups AS (
  SELECT DISTINCT match_key, symbol, exchange, market_type, run_id FROM trade_events
),
trade_ordered AS (
  SELECT te.*,
         ROW_NUMBER() OVER (
           PARTITION BY match_key, symbol, exchange, market_type, run_id
           ORDER BY event_time, kind_order, event_id
         ) AS seq
  FROM trade_events te
),
trade_walk(match_key, symbol, exchange, market_type, run_id, seq, open_entry_ids, pair_entry_id, pair_close_id) AS (
  SELECT match_key, symbol, exchange, market_type, run_id, 0::bigint,
         ARRAY[]::bigint[], NULL::bigint, NULL::bigint
  FROM trade_groups
  UNION ALL
  SELECT w.match_key, w.symbol, w.exchange, w.market_type, w.run_id, o.seq,
         CASE
           WHEN o.event_kind = 'entry' THEN w.open_entry_ids || o.event_id
           WHEN cardinality(w.open_entry_ids) > 0 THEN COALESCE(w.open_entry_ids[2:cardinality(w.open_entry_ids)], ARRAY[]::bigint[])
           ELSE w.open_entry_ids
         END,
         CASE WHEN o.event_kind = 'close' AND cardinality(w.open_entry_ids) > 0 THEN w.open_entry_ids[1] ELSE NULL END,
         CASE WHEN o.event_kind = 'close' AND cardinality(w.open_entry_ids) > 0 THEN o.event_id ELSE NULL END
  FROM trade_walk w
  JOIN trade_ordered o
    ON o.match_key = w.match_key
   AND o.symbol = w.symbol
   AND o.exchange IS NOT DISTINCT FROM w.exchange
   AND o.market_type IS NOT DISTINCT FROM w.market_type
   AND o.run_id IS NOT DISTINCT FROM w.run_id
   AND o.seq = w.seq + 1
),
trade_pairs AS (
  SELECT pair_entry_id AS entry_event_id, pair_close_id AS close_event_id, 'matched_trade_id' AS matched_by
  FROM trade_walk
  WHERE pair_entry_id IS NOT NULL AND pair_close_id IS NOT NULL
),
position_entry_base AS (
  SELECT id, eff_position_id AS match_key, symbol, exchange, market_type, run_id, happened_at
  FROM entry_resolved e
  WHERE eff_position_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM internal_pairs p WHERE p.entry_event_id = e.id)
    AND NOT EXISTS (SELECT 1 FROM trade_pairs p WHERE p.entry_event_id = e.id)
),
position_close_base AS (
  SELECT c.id, c.match_position_id AS match_key, c.symbol, c.exchange, c.market_type,
         resolved.match_run_id AS run_id, c.effective_close_time
  FROM close_events c
  JOIN LATERAL (
    SELECT CASE
      WHEN c.run_id IS NOT NULL THEN c.run_id
      WHEN COUNT(DISTINCT e.run_id) = 1 THEN MIN(e.run_id)
      ELSE NULL
    END AS match_run_id
    FROM position_entry_base e
    WHERE e.match_key = c.match_position_id
      AND e.symbol = c.symbol
      AND e.exchange = c.exchange
      AND e.market_type = c.market_type
      AND e.happened_at <= c.effective_close_time
  ) resolved ON resolved.match_run_id IS NOT NULL
  WHERE c.match_position_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM internal_pairs p WHERE p.close_event_id = c.id)
    AND NOT EXISTS (SELECT 1 FROM trade_pairs p WHERE p.close_event_id = c.id)
),
position_events AS (
  SELECT 'entry' AS event_kind, id AS event_id, match_key, symbol, exchange, market_type, run_id,
         happened_at AS event_time, 0 AS kind_order
  FROM position_entry_base
  UNION ALL
  SELECT 'close' AS event_kind, id AS event_id, match_key, symbol, exchange, market_type, run_id,
         effective_close_time AS event_time, 1 AS kind_order
  FROM position_close_base
),
position_groups AS (
  SELECT DISTINCT match_key, symbol, exchange, market_type, run_id FROM position_events
),
position_ordered AS (
  SELECT pe.*,
         ROW_NUMBER() OVER (
           PARTITION BY match_key, symbol, exchange, market_type, run_id
           ORDER BY event_time, kind_order, event_id
         ) AS seq
  FROM position_events pe
),
position_walk(match_key, symbol, exchange, market_type, run_id, seq, open_entry_ids, pair_entry_id, pair_close_id) AS (
  SELECT match_key, symbol, exchange, market_type, run_id, 0::bigint,
         ARRAY[]::bigint[], NULL::bigint, NULL::bigint
  FROM position_groups
  UNION ALL
  SELECT w.match_key, w.symbol, w.exchange, w.market_type, w.run_id, o.seq,
         CASE
           WHEN o.event_kind = 'entry' THEN w.open_entry_ids || o.event_id
           WHEN cardinality(w.open_entry_ids) > 0 THEN COALESCE(w.open_entry_ids[2:cardinality(w.open_entry_ids)], ARRAY[]::bigint[])
           ELSE w.open_entry_ids
         END,
         CASE WHEN o.event_kind = 'close' AND cardinality(w.open_entry_ids) > 0 THEN w.open_entry_ids[1] ELSE NULL END,
         CASE WHEN o.event_kind = 'close' AND cardinality(w.open_entry_ids) > 0 THEN o.event_id ELSE NULL END
  FROM position_walk w
  JOIN position_ordered o
    ON o.match_key = w.match_key
   AND o.symbol = w.symbol
   AND o.exchange IS NOT DISTINCT FROM w.exchange
   AND o.market_type IS NOT DISTINCT FROM w.market_type
   AND o.run_id IS NOT DISTINCT FROM w.run_id
   AND o.seq = w.seq + 1
),
position_pairs AS (
  SELECT pair_entry_id AS entry_event_id, pair_close_id AS close_event_id, 'matched_position_id' AS matched_by
  FROM position_walk
  WHERE pair_entry_id IS NOT NULL AND pair_close_id IS NOT NULL
),
matched AS (
  SELECT entry_event_id, close_event_id, matched_by FROM internal_pairs
  UNION ALL
  SELECT entry_event_id, close_event_id, matched_by FROM trade_pairs
  UNION ALL
  SELECT entry_event_id, close_event_id, matched_by FROM position_pairs
)
SELECT
  ee.id                         AS entry_event_id,
  m.close_event_id              AS close_event_id,
  ee.symbol,
  ee.timeframe,
  ee.run_id                     AS run_id,
  ee.run_id                     AS correlation_run_id,
  ee.orchestration_run_id,
  ee.dashboard_id,
  ee.set_id,
  ee.exchange,
  ee.market_type,
  ee.mtf_profile,
  COALESCE(ee.match_internal_trade_id, ce.match_internal_trade_id) AS internal_trade_id,
  COALESCE(ee.match_trade_id, ce.match_trade_id)        AS trade_id,
  COALESCE(ce.match_position_id, ee.eff_position_id)     AS position_id,
  ee.happened_at                AS entry_time,
  ce.effective_close_time       AS close_time,
  CASE WHEN m.close_event_id IS NOT NULL THEN 'matched' ELSE 'unmatched' END AS close_match_status,
  COALESCE(m.matched_by, 'unmatched')                   AS close_matched_by,
  CASE WHEN m.close_event_id IS NOT NULL THEN 'matched_closed' ELSE 'unmatched' END AS analysis_status,
  (ee.extra->> 'r_multiple_final')::numeric       AS expected_r_multiple,
  (ee.extra->> 'risk_usdt')::numeric              AS risk_usdt,
  (ee.extra->> 'notional_usdt')::numeric          AS notional_usdt,
  (ee.extra->> 'atr_pct_entry')::numeric          AS atr_pct_entry,
  (ee.extra->> 'volume_ratio')::numeric           AS entry_volume_ratio,
  sv.snapshot_kline_time,
  sv.entry_rsi::numeric                           AS entry_rsi,
  sv.entry_atr::numeric                           AS entry_atr,
  sv.entry_macd::numeric                          AS entry_macd,
  sv.entry_ma9::numeric                           AS entry_ma9,
  sv.entry_ma21::numeric                          AS entry_ma21,
  sv.entry_vwap::numeric                          AS entry_vwap,
  (ce.extra->> 'pnl_R')::numeric                  AS pnl_r,
  COALESCE(money.recorded_pnl_usdt, (ce.extra->> 'pnl')::numeric) AS recorded_pnl_usdt,
  money.gross_realized_pnl_usdt,
  money.entry_fee_usdt,
  money.exit_fee_usdt,
  money.other_trading_fees_usdt,
  (ce.extra->> 'pnl_pct')::numeric                AS pnl_pct,
  (ce.extra->> 'mfe_pct')::numeric                AS mfe_pct,
  (ce.extra->> 'mae_pct')::numeric                AS mae_pct,
  (ce.extra->> 'holding_time_sec')::numeric       AS holding_time_sec,
  (ce.extra->> 'fees')::numeric                   AS fees_usdt,
  money.funding_usdt                              AS funding_usdt,
  (ce.extra->> 'slippage')::numeric               AS slippage_usdt,
  money.spread_cost_usdt,
  money.slippage_cost_usdt,
  money.borrow_cost_usdt,
  money.liquidation_fee_usdt,
  CASE WHEN quality.flags = ARRAY[]::text[] AND m.close_event_id IS NOT NULL THEN
    money.entry_fee_usdt
      + money.exit_fee_usdt
      + money.other_trading_fees_usdt
      - money.funding_usdt
      + money.spread_cost_usdt
      + money.slippage_cost_usdt
      + money.borrow_cost_usdt
      + money.liquidation_fee_usdt
  ELSE NULL END                                   AS total_known_cost_usdt,
  CASE WHEN quality.flags = ARRAY[]::text[] AND m.close_event_id IS NOT NULL THEN
    money.gross_realized_pnl_usdt
      - money.entry_fee_usdt
      - money.exit_fee_usdt
      - money.other_trading_fees_usdt
      + money.funding_usdt
      - money.spread_cost_usdt
      - money.slippage_cost_usdt
      - money.borrow_cost_usdt
      - money.liquidation_fee_usdt
  ELSE NULL END                                   AS net_pnl_usdt,
  COALESCE((ee.extra->> 'risk_usdt_at_entry')::numeric, (ee.extra->> 'risk_usdt')::numeric) AS risk_usdt_at_entry,
  CASE WHEN quality.flags = ARRAY[]::text[]
    AND m.close_event_id IS NOT NULL
    AND COALESCE((ee.extra->> 'risk_usdt_at_entry')::numeric, (ee.extra->> 'risk_usdt')::numeric) > 0
  THEN
    (
      money.gross_realized_pnl_usdt
        - money.entry_fee_usdt
        - money.exit_fee_usdt
        - money.other_trading_fees_usdt
        + money.funding_usdt
        - money.spread_cost_usdt
        - money.slippage_cost_usdt
        - money.borrow_cost_usdt
        - money.liquidation_fee_usdt
    ) / COALESCE((ee.extra->> 'risk_usdt_at_entry')::numeric, (ee.extra->> 'risk_usdt')::numeric)
  ELSE NULL END                                   AS realized_net_pnl_r,
  CASE
    WHEN ce.extra IS NULL THEN NULL
    WHEN ce.extra ? 'position_fully_closed' THEN (ce.extra->> 'position_fully_closed') = 'true'
    ELSE NULL
  END                                             AS position_fully_closed,
  money.pnl_source,
  to_jsonb(quality.flags)                         AS pnl_quality_flags,
  CASE WHEN
    (ce.extra->> 'pnl')      IS NOT NULL AND
    (ce.extra->> 'fees')     IS NOT NULL AND
    (ce.extra->> 'funding')  IS NOT NULL AND
    (ce.extra->> 'slippage') IS NOT NULL
  THEN
    (ce.extra->> 'pnl')::numeric
      - (ce.extra->> 'fees')::numeric
      - (ce.extra->> 'funding')::numeric
      - (ce.extra->> 'slippage')::numeric
  ELSE NULL END                                   AS estimated_net_pnl_usdt,
  CASE
    WHEN m.close_event_id IS NULL THEN 'not_applicable'
    WHEN quality.flags = ARRAY[]::text[] THEN 'complete'
    WHEN (ce.extra->> 'fees') IS NULL
     AND (ce.extra->> 'funding') IS NULL
     AND (ce.extra->> 'slippage') IS NULL
     AND money.gross_realized_pnl_usdt IS NULL
     AND money.entry_fee_usdt IS NULL
     AND money.exit_fee_usdt IS NULL THEN 'unknown'
    ELSE 'partial'
  END                                             AS cost_completeness
FROM entry_resolved ee
LEFT JOIN matched m        ON m.entry_event_id = ee.id
LEFT JOIN close_events ce  ON ce.id = m.close_event_id
LEFT JOIN snapshot_values sv ON sv.event_id = ee.id
LEFT JOIN LATERAL (
  SELECT
    (ce.extra->> 'gross_realized_pnl_usdt')::numeric AS gross_realized_pnl_usdt,
    (ce.extra->> 'recorded_pnl_usdt')::numeric AS recorded_pnl_usdt,
    (ce.extra->> 'entry_fee_usdt')::numeric AS entry_fee_usdt,
    (ce.extra->> 'exit_fee_usdt')::numeric AS exit_fee_usdt,
    (ce.extra->> 'other_trading_fees_usdt')::numeric AS other_trading_fees_usdt,
    COALESCE((ce.extra->> 'funding_usdt')::numeric, (ce.extra->> 'funding')::numeric) AS funding_usdt,
    (ce.extra->> 'spread_cost_usdt')::numeric AS spread_cost_usdt,
    COALESCE((ce.extra->> 'slippage_cost_usdt')::numeric, (ce.extra->> 'slippage')::numeric) AS slippage_cost_usdt,
    (ce.extra->> 'borrow_cost_usdt')::numeric AS borrow_cost_usdt,
    (ce.extra->> 'liquidation_fee_usdt')::numeric AS liquidation_fee_usdt,
    COALESCE(NULLIF(ce.extra->> 'pnl_source', ''), CASE WHEN ce.extra IS NOT NULL THEN 'provider_recorded' ELSE NULL END) AS pnl_source,
    (ce.extra->> 'entry_qty')::numeric AS entry_qty,
    (ce.extra->> 'exit_qty')::numeric AS exit_qty,
    (ce.extra->> 'remaining_qty')::numeric AS remaining_qty
) money ON true
LEFT JOIN LATERAL (
  SELECT ARRAY_REMOVE(ARRAY[
    CASE WHEN m.close_event_id IS NULL THEN 'unmatched' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.gross_realized_pnl_usdt IS NULL THEN 'missing_gross_pnl' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.entry_fee_usdt IS NULL THEN 'missing_entry_fee' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.exit_fee_usdt IS NULL THEN 'missing_exit_fee' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.other_trading_fees_usdt IS NULL THEN 'missing_other_trading_fees' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.funding_usdt IS NULL THEN 'missing_funding' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.spread_cost_usdt IS NULL THEN 'missing_spread_cost' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.slippage_cost_usdt IS NULL THEN 'missing_slippage_cost' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.borrow_cost_usdt IS NULL THEN 'missing_borrow_cost' END,
    CASE WHEN m.close_event_id IS NOT NULL AND money.liquidation_fee_usdt IS NULL THEN 'missing_liquidation_fee' END,
    CASE WHEN m.close_event_id IS NOT NULL AND COALESCE((ce.extra->> 'fills_complete') = 'true', false) IS NOT TRUE THEN 'fills_incomplete' END,
    CASE WHEN m.close_event_id IS NOT NULL AND COALESCE((ce.extra->> 'position_fully_closed') = 'true', false) IS NOT TRUE THEN 'position_not_fully_closed' END,
    CASE WHEN m.close_event_id IS NOT NULL AND COALESCE((ce.extra->> 'lineage_sufficient') = 'true', false) IS NOT TRUE THEN 'lineage_insufficient' END,
    CASE WHEN m.close_event_id IS NOT NULL AND COALESCE((ce.extra->> 'identifier_conflict') = 'true', false) IS TRUE THEN 'identifier_conflict' END,
    CASE WHEN m.close_event_id IS NOT NULL AND NOT (
      COALESCE((ce.extra->> 'quantity_coherent') = 'true', false)
      OR (
        money.entry_qty IS NOT NULL
        AND money.exit_qty IS NOT NULL
        AND abs(money.entry_qty - money.exit_qty) <= 0.00000001
        AND COALESCE(money.remaining_qty, 0) <= 0.00000001
      )
    ) THEN 'quantity_mismatch' END
  ], NULL)::text[] AS flags
) quality ON true;
SQL);
    }

    public function down(Schema $schema): void
    {
        $previousMigrationClass = __NAMESPACE__ . '\\Version20260623010000';
        if (!class_exists($previousMigrationClass, false)) {
            require_once __DIR__ . '/Version20260623010000.php';
        }
        if (!is_a($previousMigrationClass, AbstractMigration::class, true)) {
            $this->throwIrreversibleMigrationException('Previous position_trade_analysis_v2 migration is not loadable.');
        }

        /** @var class-string<AbstractMigration> $previousMigrationClass */
        $previous = new $previousMigrationClass($this->connection, new NullLogger());
        $previous->up($schema);
        foreach ($previous->getSql() as $query) {
            $this->addSql($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
