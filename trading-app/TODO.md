# TODO

- Synchroniser les clés/secret dans l'environnement (BITMART_API_KEY, BITMART_SECRET_KEY, BITMART_API_MEMO).
- Lancer `composer dump-autoload` puis `vendor/bin/phpunit` pour valider tout le flux localement.
- Ajouter des tests d'intégration autour de `MtfValidatorInterface::run` (descente complète jusqu'au 1m + déclenchement trading).
- Persister dans `trade_zone_events` (ou audit équivalent) la valeur de `volume_ratio` lorsqu'un rejet `volume_ratio_ok` survient afin d'analyser les seuils à ajuster.
- Instrumenter la nouvelle file `IndicatorSnapshotPersistRequestMessage` : log `dispatch` côté runner + métriques worker (durée, backlog Redis) pour tracer la persistance asynchrone.
- Corriger l'extension `php-trader` (ATR) dans le container PHP afin d'éviter les recalculs et warnings `[ATR] trader_atr returned invalid value` côté `indicators`.
- Brancher la persistance `entry_zone_live` (service + cleanup TTL) en utilisant `EntryZoneLiveRepository`, puis exposer le flux (API ou audit CLI).
- Précharger ou mettre en cache les klines Bitmart 1m/5m avant `mtf_execution` (ou lisser les batchs) pour éviter les rafales `429 Too Many Requests` et les 3–5 s par symbole observés malgré 8 workers.
- Implémenter la persistance des runs (`mtf_run`, `mtf_run_metric`, `mtf_run_symbol`) depuis `MtfRunnerService`/`MtfValidatorService` afin que l’analyse DB (`scripts/analyze_mtf_runs_since.sh`) retourne des métriques réelles.
