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
 * LIMITE DE PRODUCTION CONNUE (clé de rapprochement partagée — suivi #190/#189) : la vue
 * est CORRECTE mais ne peut relier que ce que les producteurs émettent. Dans le chemin
 * runtime ACTUEL, `order_submitted` (TradeEntryService) porte le `trade_id` (via
 * LifecycleContextBuilder) mais pas le `position_id`, tandis que les `position_opened` /
 * `position_closed` synchronisés (`TradingStateSyncRunner`) portent le `position_id` de
 * l'exchange mais PAS le `trade_id`. Aucune clé n'est donc partagée des deux côtés :
 *   - le passage `trade_id` ne s'arme pas (la clôture n'a pas de `trade_id`) ;
 *   - le pont `trade_id -> position_id` ne s'arme pas (`position_opened` n'a pas de
 *     `trade_id`), donc `eff_position_id` reste NULL et le passage `position_id` non plus.
 * Conséquence : un trade MTF réel ressort `unmatched` (état HONNÊTE « inconnu », jamais un
 * faux rapprochement ni un PnL inventé), tant que les producteurs ne propagent pas une clé
 * commune (trade_id sur open/close, ou un order_id partagé). Propager cette clé touche le
 * chemin live et fait l'objet d'un SUIVI dédié (hors périmètre de cette PR, qui livre la
 * surface d'analyse fiable + le contrat PnL explicite). Le pont reste en place pour le
 * jour où `position_opened` portera le `trade_id`, et est couvert par les tests de la vue.
 *
 * LIMITE D'ORDONNANCEMENT CONNUE (rapprochement FIFO par rang — suivi #200) : le
 * rapprochement 1-pour-1 repose sur `ROW_NUMBER()` (rang d'entrée vs rang de clôture par
 * clé+venue) plus un garde temporel `effective_close_time >= happened_at`. Si une clôture
 * SUPPLÉMENTAIRE/DOUBLON existe pour la même clé+venue ENTRE deux entrées, elle consomme un
 * rang et décale la vraie clôture postérieure au rang suivant : l'entrée concernée est
 * alors laissée `unmatched`. C'est un échec SÛR (état honnête « inconnu », jamais une
 * mauvaise attribution de PnL) tant qu'il n'existe pas de clôture doublon. Une mauvaise
 * attribution exigerait un VRAI doublon de clôture pour la même clé+venue, ce que le chemin
 * live ne produit pas (`TradingStateSyncRunner` n'émet qu'une `position_closed` par
 * disparition de position suivie, et les clôtures live ne portent pas de `trade_id` —
 * `close_tid` est donc vide en prod). Un rapprochement correct pour TOUTES les imbrications
 * (y compris doublons) demanderait un appariement FIFO récursif (pile open/close) dans la
 * vue ; ce durcissement est suivi par #200 et non requis tant que les données live restent
 * bien formées.
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
    COALESCE(NULLIF(e.position_id, ''), NULLIF(e.extra->> 'position_id', '')) AS match_position_id,
    -- Temps de clôture EFFECTIF : `happened_at` est le moment où la synchro LOGGE
    -- l'événement (peut être très postérieur à la clôture réelle pour un import tardif).
    -- La vraie heure d'exchange est dans extra.close_time ('Y-m-d H:i:s' UTC) : on l'utilise
    -- pour le rang/l'éligibilité/le garde temporel, avec repli sur happened_at si absente
    -- ou mal formée (cast sûr via garde regex).
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
-- Pont trade_id -> position_id : `position_opened` (flux MTF) porte les DEUX. Le pont est
-- BORNÉ à la même VENUE (symbole + exchange + market_type) : le trade_id n'étant unique que
-- par (exchange, market_type, trade_id), un même trade_id réutilisé sur une autre venue ne
-- doit pas faire hériter à une entrée le position_id d'une autre venue (mauvais bucket
-- by_exchange). Le rang est donc partitionné par (trade_id + venue complète).
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
-- (1) Appariement 1-pour-1 par trade_id, BORNÉ à la même VENUE (symbole + exchange +
-- market_type) et à une clôture postérieure à l'entrée. Le trade_id n'est unique que
-- par (exchange, market_type, trade_id) côté table : sans le périmètre venue, deux
-- exchanges/marchés émettant le même trade_id échangeraient leurs outcomes (cross-venue
-- swap). On applique le même garde temporel que le passage position_id.
-- NB (suivi #200) : le rang FIFO est sûr tant qu'il n'existe pas de clôture DOUBLON pour la
-- même clé+venue entre deux entrées (sinon l'entrée concernée reste `unmatched` — échec sûr,
-- jamais une mauvaise attribution). Voir la « LIMITE D'ORDONNANCEMENT CONNUE » du docblock.
-- Garde de RUN (parité v1) : une clôture ne peut être consommée que par une entrée du
-- MÊME run (ou une clôture sans run — `run_id IS NULL` — qui reste rapprochable par tout
-- run, cas du chemin live où la synchro émet les clôtures sans run_id). Sans ce garde, si
-- deux runs produisent le même identifiant exact sur la même venue, la clôture d'un autre
-- run pourrait être consommée et son PnL attribué au run demandé (l'API filtre les entrées
-- par run APRÈS l'appariement). Appliqué à l'éligibilité ET à la jointure (échec sûr en cas
-- de collision multi-run : entrée laissée `unmatched`, jamais une mauvaise attribution).
entry_tid AS (
  SELECT id, match_trade_id, symbol, exchange, market_type, run_id, happened_at,
         ROW_NUMBER() OVER (
           PARTITION BY match_trade_id, symbol, exchange, market_type
           ORDER BY happened_at, id
         ) AS rn
  FROM entry_events WHERE match_trade_id IS NOT NULL
),
-- Ne RANGE que les clôtures ÉLIGIBLES (précédées d'au moins une entrée de même venue ET
-- même trade_id ET même run) : une clôture orpheline/antérieure ne vole pas le rang 1 à la
-- vraie clôture postérieure (symétrique au passage position_id).
close_tid AS (
  SELECT c.id, c.match_trade_id, c.symbol, c.exchange, c.market_type, c.run_id, c.effective_close_time,
         ROW_NUMBER() OVER (
           PARTITION BY c.match_trade_id, c.symbol, c.exchange, c.market_type
           ORDER BY c.effective_close_time, c.id
         ) AS rn
  FROM close_events c
  WHERE c.match_trade_id IS NOT NULL
    AND EXISTS (
      SELECT 1 FROM entry_events e
      WHERE e.match_trade_id = c.match_trade_id
        AND e.symbol = c.symbol
        AND e.exchange = c.exchange
        AND e.market_type = c.market_type
        AND (c.run_id IS NULL OR c.run_id = e.run_id)
        AND e.happened_at <= c.effective_close_time
    )
),
tid_pairs AS (
  SELECT e.id AS entry_event_id, c.id AS close_event_id
  FROM entry_tid e
  JOIN close_tid c
    ON c.match_trade_id = e.match_trade_id
   AND c.rn = e.rn
   AND c.symbol = e.symbol
   AND c.exchange = e.exchange
   AND c.market_type = e.market_type
   AND (c.run_id IS NULL OR c.run_id = e.run_id)
   AND c.effective_close_time >= e.happened_at
),
-- (2) Appariement par position_id effectif, non déjà appariés par trade_id.
entry_pid_base AS (
  SELECT id, eff_position_id, symbol, exchange, market_type, run_id, happened_at
  FROM entry_resolved
  WHERE eff_position_id IS NOT NULL
    AND id NOT IN (SELECT entry_event_id FROM tid_pairs)
),
entry_pid AS (
  -- Rang par (position_id effectif + venue complète) : un position_id réutilisé sur
  -- un autre symbole/exchange/marché ne mélange pas les rangs.
  SELECT id, eff_position_id, symbol, exchange, market_type, run_id, happened_at,
         ROW_NUMBER() OVER (
           PARTITION BY eff_position_id, symbol, exchange, market_type
           ORDER BY happened_at, id
         ) AS rn
  FROM entry_pid_base
),
-- Ne RANGE que les clôtures ÉLIGIBLES (précédées d'au moins une entrée même venue ET même
-- run) : une clôture orpheline/périmée antérieure (ou d'un autre run) est écartée AVANT le
-- rang (et non rejetée après), donc elle ne vole pas le rang 1 à la vraie clôture postérieure.
close_pid AS (
  SELECT c.id, c.match_position_id, c.symbol, c.exchange, c.market_type, c.run_id, c.effective_close_time,
         ROW_NUMBER() OVER (
           PARTITION BY c.match_position_id, c.symbol, c.exchange, c.market_type
           ORDER BY c.effective_close_time, c.id
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
        AND (c.run_id IS NULL OR c.run_id = e.run_id)
        AND e.happened_at <= c.effective_close_time
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
   AND (c.run_id IS NULL OR c.run_id = e.run_id)
   AND c.effective_close_time >= e.happened_at
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
  ce.effective_close_time       AS close_time,
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
