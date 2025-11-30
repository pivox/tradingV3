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
