# Donnees et Persistance

## Entites principales

| Domaine | Entites |
| --- | --- |
| Marche | `Contract`, `Kline`, `IndicatorSnapshot`, `ValidationCache` |
| Selection | `BlacklistedContract`, `ContractCooldown`, `MtfSwitch`, `MtfLock`, `SymbolExecutionLock` |
| Runs MTF | `MtfRun`, `MtfRunSymbol`, `MtfRunMetric`, `MtfAudit`, `MtfState` |
| Ordres | `OrderIntent`, `OrderProtection`, `FuturesOrder`, `FuturesPlanOrder`, `FuturesOrderTrade` |
| Positions | `Position`, `FuturesTransaction`, `PositionTradeAnalysis` |
| Observabilite | `TradeLifecycleEvent`, `TradeZoneEvent`, `EntryZoneLive` |
| Signaux | `Signal` |

## Tables critiques

| Table | Role |
| --- | --- |
| `contracts` | Specs et eligibilite des contrats. |
| `klines` | Bougies marche par symbole/timeframe. |
| `indicator_snapshots` | Snapshots techniques par kline fermee. |
| `mtf_run*` | Historique de runs, symboles et metriques. |
| `mtf_audit` | Details de validation par symbole. |
| `mtf_state` | Etat courant MTF par symbole. |
| `mtf_switch` | Desactivation temporaire de symboles. |
| `order_intent` | Intention locale d'ordre. |
| `order_protection` | SL/TP et protections associees. |
| `futures_order*` | Ordres, plans et fills exchange. |

Dans `futures_order`, `quantity_decimal` et `filled_quantity_decimal` (`NUMERIC(36,18)`) sont les valeurs canoniques pour les calculs précis. Les colonnes entières `size` et `filled_size` restent disponibles pour les consommateurs legacy et servent de fallback aux anciennes lignes.
Dans `futures_order_trade`, `quantity_decimal` (`NUMERIC(36,18)`) conserve de la meme maniere la taille exacte du fill; la colonne entiere `size` reste un champ legacy non canonique.
| `trade_lifecycle_event` | Evenements bout-en-bout. |
| `trade_zone_events` | Diagnostics entry zone. |
| `entry_zone_live` | Zones d'entree actives avec TTL. |

## Migrations

Les migrations Doctrine sont dans `trading-app/migrations`.
Les versions recentes 2026 ajoutent notamment:

- metadata runtime exchange/profile;
- locks cross-profile;
- extensions `order_intent` autour de `decision_key`, profile, timeframe et exchange order.

## Logs

| Canal/fichier | Usage |
| --- | --- |
| `mtf-*` | Runner, validation et decisions MTF. |
| `order-journey*` | TradeEntry, ordres, protections, watchers. |
| `positions*` | Positions et lifecycle. |
| `provider*` | REST/WS providers et rate limits. |
| `indicators*` | Snapshots, calculs et anomalies ATR. |
| `dev-*` | Logs Symfony globaux en dev. |

## Exports d'investigation

```bash
docker-compose exec trading-app-php php bin/console app:export-symbol-data LINKUSDT "2025-11-30 13:02" --show-sql --show-logs
```

La commande exporte une fenetre de donnees persistantes et de logs dans `investigation/`.
