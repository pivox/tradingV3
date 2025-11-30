<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vue de synthèse entrée/sortie + indicateurs pour les trades (position_trade_analysis).
 */
final class Version20251129000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create view position_trade_analysis joining ORDER_SUBMITTED, POSITION_CLOSED and indicator_snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE VIEW position_trade_analysis AS
WITH entry_events AS (
  SELECT
    e.id,
    e.symbol,
    COALESCE(NULLIF(e.timeframe, ''), NULLIF(e.extra->> 'timeframe', '')) AS timeframe,
    e.run_id,
    e.happened_at,
    e.extra,
    -- Snapshot indicateurs au moment de l'entrée (vue simplifiée)
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
close_events AS (
  SELECT
    e.id,
    e.symbol,
    e.run_id,
    e.position_id,
    e.happened_at,
    e.extra
  FROM trade_lifecycle_event e
  WHERE e.event_type = 'position_closed'
)
SELECT
  ee.id                         AS entry_event_id,
  ce.id                         AS close_event_id,
  ee.symbol,
  ee.timeframe,
  ee.run_id,
  COALESCE(ee.extra->> 'trade_id', ce.extra->> 'trade_id') AS trade_id,
  ee.happened_at                AS entry_time,
  ce.happened_at                AS close_time,
  -- métriques de plan / sizing à l'entrée (si présentes dans extra)
  (ee.extra->> 'r_multiple_final')::numeric       AS expected_r_multiple,
  (ee.extra->> 'risk_usdt')::numeric              AS risk_usdt,
  (ee.extra->> 'notional_usdt')::numeric          AS notional_usdt,
  (ee.extra->> 'atr_pct_entry')::numeric          AS atr_pct_entry,
  (ee.extra->> 'volume_ratio')::numeric           AS entry_volume_ratio,
  -- indicateurs au moment de l'entrée (snapshot)
  sv.snapshot_kline_time,
  sv.entry_rsi::numeric                           AS entry_rsi,
  sv.entry_atr::numeric                           AS entry_atr,
  sv.entry_macd::numeric                          AS entry_macd,
  sv.entry_ma9::numeric                           AS entry_ma9,
  sv.entry_ma21::numeric                          AS entry_ma21,
  sv.entry_vwap::numeric                          AS entry_vwap,
  -- métriques de sortie (POSITION_CLOSED.extra)
  (ce.extra->> 'pnl_R')::numeric                  AS pnl_R,
  (ce.extra->> 'pnl')::numeric                    AS pnl_usdt,
  (ce.extra->> 'pnl_pct')::numeric                AS pnl_pct,
  (ce.extra->> 'mfe_pct')::numeric                AS mfe_pct,
  (ce.extra->> 'mae_pct')::numeric                AS mae_pct,
  (ce.extra->> 'holding_time_sec')::numeric       AS holding_time_sec
FROM entry_events ee
LEFT JOIN snapshot_values sv ON sv.event_id = ee.id
LEFT JOIN LATERAL (
  SELECT c.*
  FROM close_events c
  WHERE c.symbol = ee.symbol
    AND (c.run_id IS NULL OR c.run_id = ee.run_id)
    AND c.happened_at >= ee.happened_at
  ORDER BY c.happened_at
  LIMIT 1
) ce ON TRUE;
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis');
    }
}
