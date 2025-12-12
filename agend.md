# Agenda MTF – 24 novembre 2025

## Commandes récentes
- `docker-compose exec trading-app-php php bin/console mtf:run --dry-run=0 --workers=8`
- `curl -X POST http://localhost:8082/api/mtf/run -H 'Content-Type: application/json' -d '{"dry_run":false,"force_run":false,"force_timeframe_check":false,"workers":8}'`
- `docker-compose logs -f trading-app-messenger-trading`

## Analyse runs /api/mtf/run (26 nov 2025)
- Logs HTTP: `rg "/api/mtf/run" trading-app/var/log/dev-2025-11-26.log`
- Logs contexte MTF: `rg "reason=" trading-app/var/log/mtf-2025-11-26.log | sed 's/.*reason=\([^ ]*\).*/\1/' | sort | uniq -c | sort -nr`
- Invalidités par TF: `rg "\[MTF\] Context timeframe invalid" trading-app/var/log/dev-2025-11-26.log`
- Filtres de contexte: `rg "\[MTF\] Context filter check" trading-app/var/log/dev-2025-11-26.log`
- BDD (psql / docker-compose): comptages sur `mtf_audit`, `mtf_run*`, `mtf_state`
- Script de synthèse: `./mtf_report.sh 2025-11-26`
- Script condition report (post-run) :
  `trading-app/scripts/mtf_condition_report.py --log var/log/mtf-YYYY-MM-DD.log --since "YYYY-MM-DD HH:MM" --csv-prefix /tmp/mtf-summary`
  → génère les trois CSV `*_failrule.csv`, `*_failsymbol.csv`, `*_passsymbol.csv` utilisables pour tracker le threshold tuning.

## Investigation position fermé
- Export complet des données persistées et logs pour un symbole à une date/heure précise (UTC):
  ```bash
  docker-compose exec trading-app-php php bin/console app:export-symbol-data <SYMBOL> "Y-m-d H:i" [--show-sql] [--show-logs]
  ```
  Exemple:
  ```bash
  docker-compose exec trading-app-php php bin/console app:export-symbol-data LINKUSDT "2025-11-30 13:02" --show-sql --show-logs
  ```
  - Exporte toutes les données BDD (indicator_snapshots, mtf_*, order_intent*, futures_order*, trade_lifecycle_event, trade_zone_events) dans une fenêtre de ±1h
  - Exporte tous les logs (positions, mtf, signals, bitmart, provider, indicators, dev) dans une fenêtre de ±5min
  - Fichier créé dans `investigation/symbol_data_<SYMBOL>_<DATE>_<HEURE>.json`
  - Options:
    - `--show-sql`: Affiche toutes les requêtes SQL exécutées dans la console
    - `--show-logs`: Affiche un aperçu des logs exportés dans la console (5 premières lignes par type)

## Points d'attention
- Deux workers doivent être actifs : `trading-app-messenger-order-timeout` (transport `order_timeout`) et `trading-app-messenger-trading` (transport `mtf_decision`).
- Les décisions MTF sont envoyées via Messenger, surveiller les logs `[MTF Messenger]` pour confirmer les ordres.
- Les messages `App\TradeEntry\Message\LimitFillWatchMessage` restent sur `order_timeout` pour éviter les warnings côté `mtf_decision`.
- Après chaque modification de config Messenger, relancer les deux containers pour appliquer les nouvelles files Redis.
- 08 déc 2025 – changements scalper à rollback si winrate < baseline :
  - `RsiBullishCondition` → seuil 5m abaissé dynamiquement à 49 (fallback automatique si aucun override n’est fourni).
  - `close_above_vwap_or_ma9_relaxed` → nouvelle règle YAML qui tolère une clôture proche du VWAP lorsque `atr_rel_in_range_5m` passe et que le prix reste dans ±0.4 % du VWAP.
- TODO 09 déc 2025 : rétablir la persistance des signaux (SignalValidationService obsolète, aucun dispatch messenger → table `signals` vide). Décider si on reconnecte `SignalPersistenceService` directement dans les services TF ou si on crée un handler messenger dédié.

## 4 déc 2025 – Persistance indicateurs asynchrone
- `MtfRunnerService` n'appelle plus `getIndicatorsForSymbolAndTimeframes()` directement. À la place, chaque run dispatch un `IndicatorSnapshotPersistRequestMessage` (redis `mtf_projection`) contenant `symbols` + `timeframes` + `run_id`.
- `trading-app-messenger-projection` consomme ces jobs via `IndicatorSnapshotPersistRequestMessageHandler`, qui refait la récupération des indicateurs et projette les snapshots avec `IndicatorSnapshotProjector`. Résultat : `/api/mtf/run` répond ~2× plus vite (plus de boucle bloquante sur 180 snapshots).
- `IndicatorProviderService::getIndicatorsForSymbolAndTimeframes()` renvoie désormais `kline_time`, ce qui permet au handler d'horodater correctement chaque snapshot.
- Logs à surveiller :
  - `var/log/dev-2025-12-04.log` → `Parallel execution completed` puis `Execution completed` ~55 s après le POST.
  - `var/log/indicators-2025-12-04.log` → `[IndicatorProvider] Snapshot persisted` et warnings `[ATR] trader_atr returned invalid value`.
- À garder en tête : tant que `php-trader` renvoie 0, chaque projection retombe sur le fallback ATR PHP (≈30k warnings/jour). Prévoir un fix côté extension pour alléger le worker `mtf_projection`.

## 5 déc 2025 – Audit run workers=8 + indicateurs
- Relance `curl -X POST http://localhost:8082/api/mtf/run -H 'Content-Type: application/json' -d '{"dry_run":false,"force_run":false,"force_timeframe_check":false,"workers":8,"mtf_profile":"scalper_micro"}'` → `run_id=1b6dc4d4-8233-4882-89bf-627687c3b0a5`, durée HTTP 19.5 s (`mtf_execution=18.3 s`). 38/38 symboles invalidés (`NO_LONG_NO_SHORT` sur 5 m ou 1 m).
- `mtf-2025-12-05.log` montre que les 8 workers tournent par vagues de ~3–5 s chacune car chaque symbole déclenche `IndicatorProvider` → fetch Bitmart (250 klines / tf). Plusieurs requêtes `Bitmart` passent en 429 (backoff 2 s) dès qu'on lance 50 symboles, ce qui explique la latence résiduelle même après la persistance asynchrone.
- `IndicatorSnapshotPersistRequestMessage` bien dispatché à la fin du run (voir `messenger.INFO` à 08:12:21 & 08:12:48). Le worker `trading-app-messenger-projection` persiste une fois par symbole/tf (`[IndicatorProvider] Snapshot persisted`), aucun doublon observé.
- Audit indicateurs : les snapshots comportent `kline_time` correct (`5m=08:05`, `1m=08:11`) et l'ATR tombe systématiquement sur le fallback PHP (`[ATR] trader_atr returned invalid value`). Warning volume ~30k/jour confirmé.
- Ajout d'un repository Doctrine dédié (`EntryZoneLiveRepository`) pour piloter la nouvelle entité `EntryZoneLive` (table `entry_zone_live`). Reste à brancher la projection/TTL côté TradeEntry.

## 10 déc 2025 – Entry zone resserrée & politiques pivot

- Zone scalper resserrée pour réduire les entrées trop éloignées : `max_deviation_pct` retombé à 1 %, `implausible_pct` à 3 % et `zone_max_deviation_pct` à 4.5 %. La post-validation limite maintenant `w_max` à 1 % du pivot (TTL 180 s), ce qui force les EntryZone à rester dans ±1 % du VWAP/SMA sur <3 min.
- `EntryZoneCalculator` et `OrderPlanBuilder` loguent désormais ces nouvelles bornes pour suivre les `entry_zone.rejected_by_deviation`. Penser à rejouer `v_zone_width_stats_scalper_1m` pour contrôler que la largeur médiane reste compatible avec la volatilité actuelle.
- `pivot_sl_policy` est unifiée : seules les valeurs `nearest`, `strongest`, `s1...s6` ou `r1...r6` sont supportées côté configs. `nearest` prend automatiquement le support/résistance le plus proche (stop serré → levier plus élevé), tandis que `strongest` priorise `S2/R2` puis `S1/R1` (stop plus large → levier réduit). Les anciennes valeurs `*_below`/`*_above` restent acceptées mais normalisées.

## Notes – Algorithme de calcul de zone d'entrée
- `EntryZoneCalculator::compute()` fusionne `post_validation.entry_zone` et `entry.entry_zone` du profil YAML (ex: `trade_entry.scalper_micro.yaml`). Les clés du bloc `entry` ont priorité.
- Sélection pivot : `entry.entry_zone.from` force l'ancre (`vwap`, `sma21`, etc.). À défaut, `post_validation.entry_zone.vwap_anchor` décide si l'on tente VWAP puis SMA21 (ou l'inverse). Les pivots proviennent de `IndicatorProvider::getListPivot(symbol, pivot_tf)`.
- Offsets : `offset_atr_tf` (sinon `pivot_tf`) sert à récupérer un ATR via `IndicatorProvider::getAtr`. La demi-largeur brute est `k_atr * ATR`. On clamp ensuite cette largeur entre `pivot * w_min` et `pivot * w_max` (ou `max_deviation_pct` si défini côté `entry`).
- Asymétrie : `asym_bias` (0–0.95) rééquilibre la zone selon le `Side` (plus basse pour les longs si positif). Si absent on reste symétrique.
- Quantification : si `quantize_to_exchange_step`=true, les bornes sont arrondies au tick exchange via `TickQuantizer`, en utilisant `pricePrecision` fourni par `PreflightReport`.
- TTL : `entry.entry_zone.ttl_sec` (sinon `post_validation.entry_zone.ttl_sec`, défaut 240 s) stocké dans l'objet `EntryZone`.
- Contrôles runtime : `BuildOrderPlan` claque le prix candidat sur le carnet puis vérifie `EntryZone::contains()`. Si échec:
  - on calcule `zone_dev_pct = max(|zone_min - mark|, |zone_max - mark|) / mark` où `mark = preflight.markPrice ?? bestAsk ?? bestBid`.
  - si `zone_dev_pct > zone_max_deviation_pct` (valeur issue des defaults YAML, normalisée en %), on log `zone_skipped_for_execution` et on jette `EntryZoneOutOfBoundsException`.
  - sinon, c'est un bug logique → `entry_out_of_zone_after_clamp`.
- Clefs YAML critiques: `trade_entry.defaults.{max_deviation_pct, implausible_pct, zone_max_deviation_pct}`, `trade_entry.entry.entry_zone.{from, offset_atr_tf, offset_k/k_atr, w_min, w_max, max_deviation_pct, asym_bias, ttl_sec, quantize_to_exchange_step}`, `post_validation.entry_zone.{vwap_anchor, k_atr, w_min, w_max, ttl_sec}`.

## 30 nov 2025 – Entrée & stops scalper

- Commit `402acacf5b07752e183a54fcf211f253e1e624b3`
  - `OrderPlanBuilder`:
    - Standardisation du prix de référence pour `zoneDeviation` (mark → mid → ask/bid).
    - Rejet explicite de l'EntryZone si `zoneDeviation > zone_max_deviation_pct` (`entry_zone.rejected_by_deviation`).
    - Fallback `insideTicks` appliqué avant la garde `maxDeviationPct` pour plus de lisibilité.
    - Garde carnet `order_plan.entry_book_clamped`: clamp final de `ideal` dans `[best_bid, best_ask]` avant quantization (symétrique long/short).
    - Nouveau fallback pivot→ATR pour `stop_from: 'pivot'` si le stop pivot est trop loin (`MAX_PIVOT_STOP_DISTANCE_PCT` = 2%).
  - `trade_entry.*.yaml`:
    - `atr_k` remis à `1.5` sur les profils default/regular.
    - Profil scalper: `stop_from: 'pivot'` avec fallback ATR, `risk_pct_percent`=2.5, `r_multiple`=1.3, `tp1_r`=1.0, buffer SL pivot resserré (0.003).

## 1 déc 2025 – Ajustements scalper (zone & sizing)

- Zone d'entrée 1m très rikiki: vues `v_zone_events_scalper_1m` / `v_zone_width_stats_scalper_1m` (cf. requêtes psql) ont montré 24 évènements `[0%,0.8%)` → 100% `skipped_out_of_zone`. Percentiles `zone_dev_pct` = {3.67%, 4.48%, 5.45%} pour `zone_max_dev_pct` fixe à 2%.
- Pour réduire les rejets trop stricts:
  - `trade_entry.scalper.yaml` → `defaults.zone_max_deviation_pct = 0.045` (4.5%, médiane observée) à partir du commit du 1 déc 2025.
  - Sur demande, on a évalué l'impact d'un `risk_pct_percent`=10% (taille ×4, marges/leviers plus élevés, max loss atteint très vite) mais on garde 2.5% pour l’instant.
- Rappel SQL utilisés :
  ```sql
  -- Stats par bucket de largeur
  SELECT * FROM v_zone_width_stats_scalper_1m;

  -- Percentiles zone_dev
  SELECT percentile_cont(ARRAY[0.25,0.5,0.75])
    WITHIN GROUP (ORDER BY zone_dev_pct)
  FROM trade_zone_events
  WHERE config_profile='scalper' AND timeframe='1m';
  ```

## 3 déc 2025 – Garde RSI bull/bear ConditionRegistry

- Ajout des conditions `RsiBullishCondition` / `RsiBearishCondition` (PHP) pour supprimer les erreurs `Missing condition "gt"` et séparer clairement les contextes long/short.
- `validations.scalper_micro.yaml` consomme désormais ces conditions avec des seuils 52/48 (overrides possibles) et les logs `[MTF_RULE_DEBUG]` exposent la valeur RSI et les TF.
- Objectif: réduire les `LONG_AND_SHORT` en bloc tout en gardant `NO_LONG_NO_SHORT` si ni le côté bull ni bear ne valide.
- TODO: Ajouter explicitement `zone_max_deviation_pct` dans `trade_entry.scalper_micro.yaml` (ou overrides symboles) sinon `BuildOrderPlan` retombe sur le fallback 0.007 (0,7 %) malgré `entry_zone.max_deviation_pct = 5%`.

### SQL views utiles pour reproduire l'analyse zone 1m

```sql
CREATE OR REPLACE VIEW v_zone_events_scalper_1m AS
SELECT
    tze.id,
    tze.symbol,
    tze.happened_at,
    tze.timeframe,
    tze.config_profile,
    tze.reason,
    tze.category,
    tze.zone_min,
    tze.zone_max,
    tze.candidate_price,
    tze.entry_zone_width_pct,
    tze.zone_dev_pct,
    tze.zone_max_dev_pct,
    tze.atr_pct,
    tze.volume_ratio,
    tze.spread_bps,
    tze.vwap_distance_pct,
    tze.mtf_level,
    tze.decision_key
FROM trade_zone_events tze
WHERE
    tze.config_profile = 'scalper'
  AND tze.timeframe = '1m';

CREATE OR REPLACE VIEW v_zone_width_stats_scalper_1m AS
SELECT
    CASE
        WHEN tze.entry_zone_width_pct < 0.004  THEN '[0.0%, 0.4%)'
        WHEN tze.entry_zone_width_pct < 0.008  THEN '[0.4%, 0.8%)'
        WHEN tze.entry_zone_width_pct < 0.012  THEN '[0.8%, 1.2%)'
        WHEN tze.entry_zone_width_pct < 0.016  THEN '[1.2%, 1.6%)'
        ELSE '>= 1.6%'
        END AS width_bucket,
    COUNT(*) AS event_count,
    COUNT(*) FILTER (WHERE reason = 'skipped_out_of_zone') AS skipped_out_of_zone_count
FROM trade_zone_events tze
WHERE
    tze.config_profile = 'scalper'
  AND tze.timeframe = '1m'
GROUP BY width_bucket
ORDER BY width_bucket;

-- Jointure lifecycle (derniers 7j)
SELECT
    tze.symbol,
    tze.timeframe,
    tze.config_profile,
    tze.reason,
    tze.entry_zone_width_pct,
    tze.zone_dev_pct,
    tze.zone_max_dev_pct,
    tze.atr_pct,
    tze.volume_ratio,
    tze.spread_bps,
    tle.event_type,
    tle.reason_code,
    tle.happened_at AS lifecycle_happened_at
FROM trade_zone_events tze
LEFT JOIN trade_lifecycle_event tle
    ON (tle.extra->>'decision_key') = tze.decision_key
WHERE
    tze.config_profile = 'scalper'
  AND tze.timeframe = '1m'
  AND tze.happened_at >= now() - interval '7 days';

-- Derniers skips out of zone
SELECT
    tze.symbol,
    tze.happened_at,
    tze.timeframe,
    tze.config_profile,
    tze.reason,
    tze.entry_zone_width_pct,
    tze.zone_dev_pct,
    tze.zone_max_dev_pct,
    tze.atr_pct,
    tze.volume_ratio,
    tze.spread_bps,
    tze.category,
    tle.event_type,
    tle.reason_code
FROM trade_zone_events tze
LEFT JOIN trade_lifecycle_event tle
    ON (tle.extra->>'decision_key') = tze.decision_key
WHERE
    tze.reason = 'skipped_out_of_zone'
ORDER BY tze.happened_at DESC
LIMIT 50;
```
