# Interfaces, APIs et Commandes

## Endpoints HTTP principaux

| Endpoint | Controleur | Usage |
| --- | --- | --- |
| `GET /health` | `HealthController` | Sante app. |
| `POST /api/mtf/run` | `RunnerController` | Run MTF principal. |
| `GET /api/mtf/status` | `MtfController` | Statut MTF legacy/web. |
| `GET /api/mtf/lock/status` | `MtfController` | Etat locks. |
| `POST /api/mtf/lock/cleanup` | `MtfController` | Nettoyage locks. |
| `GET /api/mtf/switches` | `MtfController` | Switches MTF. |
| `GET /api/mtf-audits` | `MtfAuditController` | Audits MTF JSON. |
| `GET /api/mtf-audits?runId={runId}` | `MtfAuditController` | Lecture des audits d'un run. |
| `GET /api/indicators/available` | `IndicatorApiController` | Conditions/indicateurs disponibles. |
| `GET /api/indicators/pivots` | `IndicatorApiController` | Pivots. |
| `GET /api/indicators/values` | `IndicatorApiController` | Valeurs indicateurs. |
| `POST /api/trade-entry/execute` | `TradeEntryController` | Execution TradeEntry directe. |
| `POST /api/trade-tpsl/two-targets` | `TradeTpSlController` | TP/SL deux targets. |
| `POST /api/provider/positions/protection` | `PositionProtectionController` | Modification SL/TP provider. |
| `POST /api/maintenance/cleanup` | `MaintenanceController` | Nettoyage global. |
| `/app/*` | `Front/*Controller` | Interface Ops Twig. |

## Commandes CLI principales

| Commande | Role |
| --- | --- |
| `mtf:run` | Lance un cycle MTF via runner. |
| `mtf:run-worker` | Traite un sous-ensemble de symboles. |
| `mtf:core:run` | Lance le core validator. |
| `app:validate:mtf-config` | Valide la structure config MTF. |
| `app:validate:mtf-config-functional` | Valide la config fonctionnellement. |
| `bitmart:fetch-contracts` | Synchronise les contrats. |
| `bitmart:fetch-klines` | Recupere des klines. |
| `app:indicator:conditions:list` | Liste les conditions disponibles. |
| `app:indicator:conditions:diagnose` | Diagnostique une condition. |
| `populate:indicators` | Peuple les snapshots indicateurs. |
| `populate:validation-cache` | Peuple le cache validation. |
| `app:exchange:runtime-check` | Controle runtime exchange/profile. |
| `app:export-symbol-data` | Exporte donnees et logs d'un symbole. |
| `app:export-execution-data` | Exporte donnees d'execution. |
| `position:close-manual` | Ferme une position manuellement. |
| `trade-entry:zone:auto-adjust` | Ajuste les seuils zone. |
| `stats:entry-zone` | Statistiques entry zone. |
| `mtf:health-check` | Health check MTF. |

## Messenger

| Transport | Messages |
| --- | --- |
| `mtf_projection` | `MtfResultProjectionMessage`, `IndicatorSnapshotProjectionMessage`, `IndicatorSnapshotPersistRequestMessage` |
| `mtf_decision` | `MtfTradingDecisionMessage` |
| `order_timeout` | `CancelOrderMessage`, `LimitFillWatchMessage`, `OutOfZoneWatchMessage` |
| `failed_order_timeout` | Messages echoues avec retry strategy. |

Workers attendus:

```bash
docker-compose exec trading-app-php php bin/console messenger:consume mtf_projection
docker-compose exec trading-app-php php bin/console messenger:consume mtf_decision
docker-compose exec trading-app-php php bin/console messenger:consume order_timeout
```
