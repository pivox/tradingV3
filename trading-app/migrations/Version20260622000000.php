<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OBS-003 v2 — Rapprochement fiable entrée/clôture + lineage d'orchestration.
 *
 * Remplace la vue `position_trade_analysis` (créée par Version20251129000000) qui
 * rapprochait une entrée de la PREMIÈRE clôture ultérieure du même symbole
 * (`LEFT JOIN LATERAL ... ORDER BY happened_at LIMIT 1`) — un rapprochement ambigu
 * dès que plusieurs trades portent sur le même symbole/run (une clôture pouvait être
 * réutilisée par plusieurs entrées, et deux trades du même symbole se mélangeaient).
 *
 * Nouvelle règle de rapprochement, par identifiants EXACTS et en 1-pour-1 :
 *   1. `trade_id` exact (entrée.extra->>'trade_id' = clôture.extra->>'trade_id') ;
 *   2. sinon `position_id` EFFECTIF exact, borné au même symbole et à une clôture
 *      postérieure à l'entrée ;
 *   3. sinon `unmatched` (aucune clôture rattachée, le trade reste visible « ouvert »).
 * Jamais de repli par symbole seul. L'appariement utilise `ROW_NUMBER()` par clé pour
 * garantir qu'une clôture n'est jamais réutilisée par deux entrées et que la jointure
 * ne multiplie pas les lignes (exactement une ligne par entrée).
 *
 * Pont trade_id ↔ position_id : dans le cycle MTF normal, `order_submitted` ne porte que
 * le `trade_id` et la clôture synchronisée que le `position_id` — les deux côtés n'ont
 * donc pas de clé commune. L'événement `position_opened` (issu du flux MTF) porte les
 * DEUX ; on l'utilise comme pont pour résoudre le `position_id` effectif d'une entrée
 * (sinon le rapprochement exact laisserait tout `unmatched`). Cf. revue OBS-003 v2 (P1).
 *
 * Garde anti-clôture périmée (P2) : le rapprochement par `position_id` exige la même
 * `symbol` et `close.happened_at >= entry.happened_at`, pour qu'un `position_id` réutilisé
 * ou une clôture importée ancienne n'attribue jamais un vieux PnL au run courant.
 *
 * Colonnes ajoutées :
 *  - lineage : `correlation_run_id`, `orchestration_run_id`, `dashboard_id`, `set_id`,
 *    `exchange`, `market_type`, `mtf_profile`, `position_id` ;
 *  - rapprochement : `close_match_status`, `close_matched_by` ;
 *  - PnL : `pnl_usdt` est renommé `recorded_pnl_usdt` (la valeur enregistrée, dont on
 *    ne garantit PAS qu'elle est nette de tous les coûts), et l'on expose `fees_usdt`,
 *    `funding_usdt`, `slippage_usdt`, `net_pnl_usdt` (calculé UNIQUEMENT si tous les
 *    coûts sont présents) et `net_pnl_complete`.
 *
 * Lecture seule, aucune table modifiée : `CREATE OR REPLACE VIEW`. La colonne `run_id`
 * (héritée) est conservée pour compat et vaut l'identifiant de corrélation stocké sur
 * `trade_lifecycle_event.run_id`.
 */
final class Version20260622000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OBS-003 v2: rebuild position_trade_analysis with exact entry/close matching, orchestration lineage and recorded/net PnL semantics';
    }

    public function up(Schema $schema): void
    {
        // La vue existante (Version20251129000000) a un autre jeu/ordre de colonnes :
        // `CREATE OR REPLACE` interdit le renommage de colonnes, on DROP puis CREATE.
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis');
        $this->addSql(<<<'SQL'
CREATE VIEW position_trade_analysis AS
WITH entry_events AS (
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
    e.run_id,
    e.happened_at,
    e.extra,
    NULLIF(e.extra->> 'trade_id', '') AS match_trade_id,
    COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) AS match_position_id
  FROM trade_lifecycle_event e
  WHERE e.event_type = 'position_closed'
),
-- Pont trade_id -> position_id : l'événement `position_opened` issu du flux MTF porte
-- À LA FOIS le `trade_id` (contexte lifecycle) ET le `position_id` (métadonnée d'ordre).
-- `order_submitted` n'a que le trade_id et la clôture synchronisée n'a que le
-- position_id ; sans ce pont, le rapprochement exact ne matcherait jamais le cycle
-- normal. Les `position_opened` de synchro pure (sans trade_id) sont naturellement exclus.
opened_bridge AS (
  SELECT
    NULLIF(e.extra->> 'trade_id', '') AS trade_id,
    COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) AS position_id,
    ROW_NUMBER() OVER (
      PARTITION BY NULLIF(e.extra->> 'trade_id', '')
      ORDER BY e.happened_at, e.id
    ) AS rn
  FROM trade_lifecycle_event e
  WHERE e.event_type = 'position_opened'
    AND NULLIF(e.extra->> 'trade_id', '') IS NOT NULL
    AND COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) IS NOT NULL
),
-- position_id EFFECTIF d'une entrée : le sien s'il existe, sinon celui résolu via le
-- pont (premier `position_opened` partageant le même trade_id).
entry_resolved AS (
  SELECT
    ee.*,
    COALESCE(ee.match_position_id, ob.position_id) AS eff_position_id
  FROM entry_events ee
  LEFT JOIN opened_bridge ob ON ob.trade_id = ee.match_trade_id AND ob.rn = 1
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
-- (1) Appariement 1-pour-1 par trade_id : i-ème entrée <-> i-ème clôture du même
-- trade_id (ROW_NUMBER déterministe). trade_id étant unique par trade, c'est en
-- pratique un appariement direct ; le rang garantit l'absence de réutilisation.
entry_tid AS (
  SELECT id, match_trade_id,
         ROW_NUMBER() OVER (PARTITION BY match_trade_id ORDER BY happened_at, id) AS rn
  FROM entry_events WHERE match_trade_id IS NOT NULL
),
close_tid AS (
  SELECT id, match_trade_id,
         ROW_NUMBER() OVER (PARTITION BY match_trade_id ORDER BY happened_at, id) AS rn
  FROM close_events WHERE match_trade_id IS NOT NULL
),
tid_pairs AS (
  SELECT e.id AS entry_event_id, c.id AS close_event_id
  FROM entry_tid e
  JOIN close_tid c ON c.match_trade_id = e.match_trade_id AND c.rn = e.rn
),
-- (2) Appariement par position_id, UNIQUEMENT pour les entrées et clôtures non
-- déjà appariées par trade_id (aucune clôture réutilisée entre les deux passes).
entry_pid_base AS (
  SELECT id, eff_position_id, symbol, happened_at
  FROM entry_resolved
  WHERE eff_position_id IS NOT NULL
    AND id NOT IN (SELECT entry_event_id FROM tid_pairs)
),
entry_pid AS (
  -- Rang par (position_id effectif, symbole) : un position_id réutilisé sur deux
  -- symboles ne doit pas mélanger les rangs (le garde `c.symbol = e.symbol` au join
  -- rejetterait alors des clôtures pourtant valides). Cf. revue OBS-003 v2 (P2).
  SELECT id, eff_position_id, symbol, happened_at,
         ROW_NUMBER() OVER (PARTITION BY eff_position_id, symbol ORDER BY happened_at, id) AS rn
  FROM entry_pid_base
),
-- P2 : ne RANGE que les clôtures ÉLIGIBLES — celles précédées d'au moins une entrée
-- (même position_id effectif + même symbole) à cet instant. Une clôture orpheline /
-- périmée ANTÉRIEURE à l'entrée est ainsi écartée du jeu de candidats AVANT le
-- ROW_NUMBER (et non rejetée après), de sorte qu'elle ne « vole » pas le rang 1 à la
-- vraie clôture postérieure : un trade réellement clôturé ne reste donc pas `unmatched`.
close_pid AS (
  SELECT c.id, c.match_position_id, c.symbol, c.happened_at,
         ROW_NUMBER() OVER (PARTITION BY c.match_position_id, c.symbol ORDER BY c.happened_at, c.id) AS rn
  FROM close_events c
  WHERE c.match_position_id IS NOT NULL
    AND c.id NOT IN (SELECT close_event_id FROM tid_pairs)
    AND EXISTS (
      SELECT 1 FROM entry_pid_base e
      WHERE e.eff_position_id = c.match_position_id
        AND e.symbol = c.symbol
        AND e.happened_at <= c.happened_at
    )
),
pid_pairs AS (
  -- Appariement 1-pour-1 par position_id EFFECTIF, borné au même symbole ET à une
  -- clôture postérieure à l'entrée. Combiné à l'éligibilité ci-dessus : ni clôture
  -- périmée attribuée (vieux PnL), ni trade clôturé laissé `unmatched`.
  SELECT e.id AS entry_event_id, c.id AS close_event_id
  FROM entry_pid e
  JOIN close_pid c
    ON c.match_position_id = e.eff_position_id
   AND c.rn = e.rn
   AND c.symbol = e.symbol
   AND c.happened_at >= e.happened_at
),
matched AS (
  SELECT entry_event_id, close_event_id, 'matched_trade_id'    AS matched_by FROM tid_pairs
  UNION ALL
  SELECT entry_event_id, close_event_id, 'matched_position_id' AS matched_by FROM pid_pairs
)
SELECT
  ee.id                         AS entry_event_id,
  m.close_event_id              AS close_event_id,
  ee.symbol,
  ee.timeframe,
  -- run_id (compat) == identifiant de corrélation stocké sur l'événement.
  ee.run_id                     AS run_id,
  ee.run_id                     AS correlation_run_id,
  ee.orchestration_run_id,
  ee.dashboard_id,
  ee.set_id,
  ee.exchange,
  ee.market_type,
  ee.mtf_profile,
  COALESCE(ee.match_trade_id, ce.match_trade_id)        AS trade_id,
  COALESCE(ce.match_position_id, ee.eff_position_id)    AS position_id,
  ee.happened_at                AS entry_time,
  ce.happened_at                AS close_time,
  CASE WHEN m.close_event_id IS NOT NULL THEN 'matched' ELSE 'unmatched' END AS close_match_status,
  COALESCE(m.matched_by, 'unmatched')                  AS close_matched_by,
  -- métriques de plan / sizing à l'entrée
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
  (ce.extra->> 'pnl_R')::numeric                  AS pnl_r,
  -- PnL ENREGISTRÉ tel quel (PAS garanti net de tous les coûts) — cf. net_pnl_*.
  (ce.extra->> 'pnl')::numeric                    AS recorded_pnl_usdt,
  (ce.extra->> 'pnl_pct')::numeric                AS pnl_pct,
  (ce.extra->> 'mfe_pct')::numeric                AS mfe_pct,
  (ce.extra->> 'mae_pct')::numeric                AS mae_pct,
  (ce.extra->> 'holding_time_sec')::numeric       AS holding_time_sec,
  -- composantes de coût (null tant que la source ne les fournit pas — jamais inventées)
  (ce.extra->> 'fees')::numeric                   AS fees_usdt,
  (ce.extra->> 'funding')::numeric                AS funding_usdt,
  (ce.extra->> 'slippage')::numeric               AS slippage_usdt,
  -- net complet UNIQUEMENT si recorded + tous les coûts sont présents.
  (
    (ce.extra->> 'pnl')      IS NOT NULL AND
    (ce.extra->> 'fees')     IS NOT NULL AND
    (ce.extra->> 'funding')  IS NOT NULL AND
    (ce.extra->> 'slippage') IS NOT NULL
  )                                               AS net_pnl_complete,
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
  ELSE NULL END                                   AS net_pnl_usdt
FROM entry_resolved ee
LEFT JOIN matched m        ON m.entry_event_id = ee.id
LEFT JOIN close_events ce  ON ce.id = m.close_event_id
LEFT JOIN snapshot_values sv ON sv.event_id = ee.id;
SQL);
    }

    public function down(Schema $schema): void
    {
        // Restaure la définition précédente (Version20251129000000).
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis');
        $this->addSql(<<<'SQL'
CREATE VIEW position_trade_analysis AS
WITH entry_events AS (
  SELECT
    e.id,
    e.symbol,
    COALESCE(NULLIF(e.timeframe, ''), NULLIF(e.extra->> 'timeframe', '')) AS timeframe,
    e.run_id,
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
}
