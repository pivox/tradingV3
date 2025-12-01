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
