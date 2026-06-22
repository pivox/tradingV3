<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OBS-003 v2 — Vue VERSIONNÉE `position_trade_analysis_v2` (rapprochement fiable +
 * lineage d'orchestration + contrat PnL explicite).
 *
 * Bascule PROGRESSIVE (issue #190, étape 8) : on NE touche PAS la vue historique
 * `position_trade_analysis` (v1, Version20251129000000). On crée une vue parallèle `_v2`
 * que le nouvel endpoint outcome consomme explicitement. Cela préserve la comparaison
 * v1/v2, le rollback et les consommateurs SQL/Twig existants ; le remplacement de la v1
 * sera un jalon ultérieur documenté.
 *
 * Règle de rapprochement entrée(`order_submitted`)/clôture(`position_closed`), par
 * identifiants EXACTS et en 1-pour-1 — jamais par symbole/timestamp/run_id seuls :
 *   1. `trade_id` exact ;
 *   2. sinon `position_id` EFFECTIF exact, borné à la même VENUE (symbole + exchange +
 *      market_type) et à une clôture postérieure à l'entrée ;
 *   3. sinon `unmatched` (état réel inconnu : ni ouvert confirmé ni clôturé certifié).
 * `ROW_NUMBER()` garantit qu'une clôture ne sert qu'une entrée et que la jointure ne
 * multiplie pas les lignes. Pont `trade_id -> position_id` via `position_opened` (qui
 * porte les deux) pour le cycle MTF normal où `order_submitted` n'a que le trade_id et la
 * clôture synchronisée que le position_id.
 *
 * Contrat PnL EXPLICITE (issue #190) — aucune valeur estimée présentée comme certifiée :
 *   - `recorded_pnl_usdt` : valeur enregistrée telle quelle (ni brute ni nette garanties) ;
 *   - `fees_usdt` / `funding_usdt` / `slippage_usdt` : composantes brutes si présentes
 *     (jamais 0 par défaut : absentes => NULL) ;
 *   - `estimated_net_pnl_usdt` : ESTIMATION best-effort (`recorded - fees - funding -
 *     slippage`) UNIQUEMENT si les trois composantes sont présentes. La convention de
 *     signe du funding et l'absence de spread/brut/frais entrée-sortie séparés sont des
 *     limites connues (contrat #190 complet à venir) : c'est une estimation, pas une
 *     mesure nette certifiée ;
 *   - `cost_completeness` : `not_applicable` (pas de clôture) | `unknown` (clôturé sans
 *     aucune composante) | `partial` (au moins une composante). `complete` est RÉSERVÉ au
 *     contrat #190 complet et n'est jamais émis ici. Il n'existe donc pas de `net_pnl_usdt`
 *     certifié dans cette vue.
 *   - `analysis_status` : `matched_closed` | `unmatched` (statut de qualité de la ligne).
 *
 * Lecture seule, aucune table modifiée.
 */
final class Version20260622000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OBS-003 v2: create versioned view position_trade_analysis_v2 (exact entry/close matching, orchestration lineage, explicit recorded/estimated PnL) alongside v1';
    }

    public function up(Schema $schema): void
    {
        // Bascule progressive : on garde la v1 intacte et on crée une vue parallèle _v2.
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis_v2');
        $this->addSql(<<<'SQL'
CREATE VIEW position_trade_analysis_v2 AS
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
    e.exchange,
    e.market_type,
    e.run_id,
    e.happened_at,
    e.extra,
    NULLIF(e.extra->> 'trade_id', '') AS match_trade_id,
    COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) AS match_position_id
  FROM trade_lifecycle_event e
  WHERE e.event_type = 'position_closed'
),
-- Pont trade_id -> position_id : `position_opened` (flux MTF) porte les DEUX.
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
-- (1) Appariement 1-pour-1 par trade_id (unique par trade ; rang = anti-réutilisation).
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
-- (2) Appariement par position_id effectif, non déjà appariés par trade_id.
entry_pid_base AS (
  SELECT id, eff_position_id, symbol, exchange, market_type, happened_at
  FROM entry_resolved
  WHERE eff_position_id IS NOT NULL
    AND id NOT IN (SELECT entry_event_id FROM tid_pairs)
),
entry_pid AS (
  -- Rang par (position_id effectif + venue complète) : un position_id réutilisé sur
  -- un autre symbole/exchange/marché ne mélange pas les rangs.
  SELECT id, eff_position_id, symbol, exchange, market_type, happened_at,
         ROW_NUMBER() OVER (
           PARTITION BY eff_position_id, symbol, exchange, market_type
           ORDER BY happened_at, id
         ) AS rn
  FROM entry_pid_base
),
-- Ne RANGE que les clôtures ÉLIGIBLES (précédées d'au moins une entrée même venue) :
-- une clôture orpheline/périmée antérieure est écartée AVANT le rang (et non rejetée
-- après), donc elle ne vole pas le rang 1 à la vraie clôture postérieure.
close_pid AS (
  SELECT c.id, c.match_position_id, c.symbol, c.exchange, c.market_type, c.happened_at,
         ROW_NUMBER() OVER (
           PARTITION BY c.match_position_id, c.symbol, c.exchange, c.market_type
           ORDER BY c.happened_at, c.id
         ) AS rn
  FROM close_events c
  WHERE c.match_position_id IS NOT NULL
    AND c.id NOT IN (SELECT close_event_id FROM tid_pairs)
    AND EXISTS (
      SELECT 1 FROM entry_pid_base e
      WHERE e.eff_position_id = c.match_position_id
        AND e.symbol = c.symbol
        AND e.exchange = c.exchange
        AND e.market_type = c.market_type
        AND e.happened_at <= c.happened_at
    )
),
pid_pairs AS (
  SELECT e.id AS entry_event_id, c.id AS close_event_id
  FROM entry_pid e
  JOIN close_pid c
    ON c.match_position_id = e.eff_position_id
   AND c.rn = e.rn
   AND c.symbol = e.symbol
   AND c.exchange = e.exchange
   AND c.market_type = e.market_type
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
  ee.run_id                     AS run_id,
  ee.run_id                     AS correlation_run_id,
  ee.orchestration_run_id,
  ee.dashboard_id,
  ee.set_id,
  ee.exchange,
  ee.market_type,
  ee.mtf_profile,
  COALESCE(ee.match_trade_id, ce.match_trade_id)        AS trade_id,
  COALESCE(ce.match_position_id, ee.eff_position_id)     AS position_id,
  ee.happened_at                AS entry_time,
  ce.happened_at                AS close_time,
  CASE WHEN m.close_event_id IS NOT NULL THEN 'matched' ELSE 'unmatched' END AS close_match_status,
  COALESCE(m.matched_by, 'unmatched')                   AS close_matched_by,
  -- Statut de qualité de la ligne (issue #190, étape 7). On ne prétend pas « ouvert » :
  -- une ligne non rapprochée est d'état réel INCONNU (peut être clôturée non-rapprochable).
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
  -- PnL ENREGISTRÉ tel quel (ni brut ni net garantis).
  (ce.extra->> 'pnl')::numeric                    AS recorded_pnl_usdt,
  (ce.extra->> 'pnl_pct')::numeric                AS pnl_pct,
  (ce.extra->> 'mfe_pct')::numeric                AS mfe_pct,
  (ce.extra->> 'mae_pct')::numeric                AS mae_pct,
  (ce.extra->> 'holding_time_sec')::numeric       AS holding_time_sec,
  -- Composantes de coût (NULL si absentes, jamais 0 par défaut).
  (ce.extra->> 'fees')::numeric                   AS fees_usdt,
  (ce.extra->> 'funding')::numeric                AS funding_usdt,
  (ce.extra->> 'slippage')::numeric               AS slippage_usdt,
  -- ESTIMATION best-effort, jamais certifiée : uniquement si les 3 composantes existent.
  -- (Convention de signe funding + absence spread/brut = limites connues, contrat #190.)
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
  -- Complétude des coûts : jamais 'complete' (contrat #190 complet non atteignable ici).
  CASE
    WHEN m.close_event_id IS NULL THEN 'not_applicable'
    WHEN (ce.extra->> 'fees') IS NULL
     AND (ce.extra->> 'funding') IS NULL
     AND (ce.extra->> 'slippage') IS NULL THEN 'unknown'
    ELSE 'partial'
  END                                             AS cost_completeness
FROM entry_resolved ee
LEFT JOIN matched m        ON m.entry_event_id = ee.id
LEFT JOIN close_events ce  ON ce.id = m.close_event_id
LEFT JOIN snapshot_values sv ON sv.event_id = ee.id;
SQL);
    }

    public function down(Schema $schema): void
    {
        // Rollback sûr : on ne supprime que la vue versionnée, la v1 n'a jamais été touchée.
        $this->addSql('DROP VIEW IF EXISTS position_trade_analysis_v2');
    }
}
