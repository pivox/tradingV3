---
title: TradingV3 - Plateforme de Trading Automatisé
theme: white
transition: slide
---

# TradingV3
## Plateforme de Trading Automatisé Multi-Timeframe

---

## Vue d'ensemble

**TradingV3** est une plateforme de trading automatisé pour Bitmart (futures/spot) centrée sur la validation multi-timeframe (MTF).

### Objectifs principaux
- Validation automatique des opportunités de trading
- Placement d'ordres avec gestion du risque
- Monitoring et audit complet des décisions

---

## Architecture globale

```
┌─────────────────────────────────────────────────────────┐
│              API /api/mtf/run                           │
│              (RunnerController)                         │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│              MtfRunnerService                           │
│  • Résolution symboles                                  │
│  • Sync positions/ordres                                │
│  • Filtrage & orchestration                             │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│              MtfValidatorService                         │
│  • Validation contexte (5m, 15m, 1h...)                │
│  • Sélection timeframe d'exécution (1m, 5m...)          │
│  • Décision de trading (LONG/SHORT)                      │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│              TradeEntryService                          │
│  • BuildPreOrder (preflight checks)                     │
│  • BuildOrderPlan (zone, prix, stops, sizing)           │
│  • ExecuteOrderPlan (placement ordre)                   │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────┐
│              Bitmart Exchange                           │
│              (Ordres placés)                            │
└─────────────────────────────────────────────────────────┘
```

---

## Flux principal : de l'API au placement d'ordre

### Phase 1 : Runner
- Résolution des symboles (contrats actifs + queue switches)
- Synchronisation positions/ordres ouverts
- Filtrage des symboles déjà engagés

### Phase 2 : Validation MTF
- Validation du contexte multi-timeframe
- Sélection du timeframe d'exécution optimal
- Décision LONG/SHORT

### Phase 3 : TradeEntry
- Preflight : vérifications exchange (spread, balance, specs)
- Planification : calcul zone d'entrée, stops, sizing, levier
- Exécution : placement de l'ordre (limit/market)

---

## Composants clés : Runner

### MtfRunnerService
**Rôle** : Orchestrateur principal du pipeline MTF

**Responsabilités** :
- Résolution de la liste de symboles à traiter
- Synchronisation avec Bitmart (positions, ordres)
- Filtrage des symboles avec positions/ordres ouverts
- Exécution séquentielle ou parallèle (workers)
- Dispatch de la persistance des indicateurs
- Recalcul TP/SL pour positions ouvertes
- Enrichissement des résultats

**Entrées** : `MtfRunnerRequestDto` (symbols, dry_run, workers, profile...)

**Sorties** : Résultats MTF par symbole + métriques de performance

---

## Composants clés : Validator

### MtfValidatorCoreService
**Rôle** : Validation multi-timeframe et sélection d'exécution

**Étapes** :
1. **ContextValidationService** : Valide le contexte sur les timeframes de contexte (ex: 5m)
   - Mode `pragmatic` : tolère certains neutres
   - Mode `strict` : exige tous les timeframes valides

2. **ExecutionSelectionService** : Sélectionne le timeframe d'exécution optimal
   - Évalue les timeframes d'exécution (ex: 1m, 5m)
   - Applique les règles de sélection (drop_to_5m_if_any, forbid_drop_to_5m_if_any)
   - Retourne le TF optimal + side (LONG/SHORT)

3. **TradingDecisionHandler** : Déclenche TradeEntry si conditions remplies

---

## Composants clés : TradeEntry

### Workflow en 3 étapes

#### 1. BuildPreOrder
- Récupère les specs du contrat (tick size, precision, min/max volume)
- Vérifie le spread (garde si trop large)
- Récupère la balance disponible
- Collecte les pivots (S/R) pour les stops

#### 2. BuildOrderPlan
- Calcule la zone d'entrée (VWAP/SMA21 + ATR)
- Détermine le prix d'entrée (limit ou market)
- Calcule le stop loss (ATR ou pivot)
- Calcule le take profit (R-multiple + pivots)
- Détermine la taille de position (risk %)
- Calcule le levier dynamique

#### 3. ExecuteOrderPlan
- Soumet le levier à l'exchange
- Place l'ordre (limit ou market)
- Programme les watchers (timeout, fill, out-of-zone)
- Attache les TP/SL (preset ou post-submit)

---

## Technologies & Stack

### Backend
- **PHP 8.2** avec Symfony 6.x
- **PostgreSQL** (klines, contrats, audits, positions)
- **Redis** (cache, queues Messenger)
- **Doctrine ORM** (persistance)

### Communication
- **Symfony Messenger** (queues asynchrones)
  - `mtf_decision` : décisions de trading
  - `order_timeout` : watchers d'ordres
  - `mtf_projection` : persistance indicateurs

### Exchange
- **Bitmart API** (REST + WebSocket)
- Rate limiting & backoff automatique
- Support futures perpétuels

### Observabilité
- **Monolog** (multi-loggers : mtf, positions, provider, indicators)
- **Métriques** (compteurs de soumissions/skips/erreurs)
- **Audit trail** (table `mtf_audit`, `trade_lifecycle_event`)

---

## Profils de configuration

### Profils MTF
Fichiers : `config/app/mtf_validations.<mode>.yaml`
- `scalper_micro` : contexte 5m, exécution 1m
- `scalper` : contexte 5m/15m, exécution 5m
- `regular` : contexte 15m/1h, exécution 15m

### Profils TradeEntry
Fichiers : `config/app/trade_entry.<mode>.yaml`
- Risk management (risk_pct_percent, r_multiple)
- Stop loss policy (atr, pivot, nearest, strongest)
- Entry zone (VWAP/SMA anchor, ATR multiplier)
- Leverage caps (par symbole, par timeframe)

---

## Cas d'invalidation MTF

### Raisons générales
- `no_timeframes_in_config` : aucune liste de timeframes
- `not_enough_klines` : historique insuffisant

### Raisons contexte
- `pragmatic_context_all_neutral` : tous les timeframes neutres
- `pragmatic_context_side_conflict` : conflit LONG/SHORT
- `strict_context_requires_non_neutral_all` : strict exige tous valides

### Raisons exécution
- `no_timeframe_selected` : aucun TF d'exécution sélectionné

---

## Cas de skip TradeEntry

### Préconditions non remplies
- `missing_execution_tf` : TF d'exécution manquant
- `execution_tf_not_allowed_by_trade_entry` : TF non autorisé
- `missing_price_and_atr` : ATR invalide (requis pour stop_from='atr')

### Guards de risque
- `daily_loss_limit_reached` : limite de perte journalière atteinte
- `leverage_below_threshold` : levier trop faible (≤ 1)

### Zone d'entrée
- `skipped_out_of_zone` : zone trop éloignée du marché
- `zone_far_from_market` : déviation > zone_max_deviation_pct

---

## Cas d'erreur TradeEntry

### Preflight
- `market_order_spread_too_wide` : spread trop large pour market
- `contract_specs_unavailable` : specs non récupérées

### Planification
- `entry_not_within_zone` : prix d'entrée hors zone (bug logique)
- `entry_zone_filters_rejection` : filtres de zone rejettent

### Exécution
- `market_order_submit_failed` : échec soumission market
- `market_order_fill_timeout` : timeout fill market
- `all_attempts_failed` : tous les tentatives limit échouées

---

## Succès : Ordre placé

### Statut `submitted`
L'ordre est soumis avec succès à Bitmart.

**Informations retournées** :
- `client_order_id` : ID client généré
- `exchange_order_id` : ID Bitmart
- `order_type` : limit ou market
- `leverage` : levier appliqué
- `size` : taille de position
- `entry` : prix d'entrée
- `stop` : stop loss
- `take_profit` : take profit

**Actions post-soumission** :
- MtfSwitch désactivé pour 4h (anti-retrade)
- Watchers programmés (timeout 120s, fill watch, out-of-zone)
- Audit persisté (`trade_lifecycle_event`)

---

## Watchers & Timeouts

### CancelOrderMessage (120s)
Si l'ordre LIMIT n'est pas rempli après 120s :
- Vérifie le statut de l'ordre
- Annule si toujours PENDING
- Réactive MtfSwitch avec délai réduit (15m au lieu de 4h)

### LimitFillWatchMessage
Surveille le fill d'un ordre LIMIT :
- Si filled → désarme le dead-man switch
- Si timeout → annulation automatique

### OutOfZoneWatchMessage
Surveille si le prix sort de la zone d'entrée :
- Si hors zone → annulation de l'ordre LIMIT

---

## Observabilité & Logs

### Loggers spécialisés
- `monolog.logger.mtf` : Runner, validator, pipelines temporels
- `monolog.logger.positions` : TradeEntry, TP/SL, order journey
- `monolog.logger.provider` : Providers HTTP/WebSocket
- `monolog.logger.indicators` : Calculs d'indicateurs

### Fichiers de logs
- `var/log/mtf-YYYY-MM-DD.log` : Validation MTF
- `var/log/positions-YYYY-MM-DD.log` : TradeEntry & ordres
- `var/log/order-journey-YYYY-MM-DD.log` : Parcours complet signal→ordre
- `var/log/bitmart-http-YYYY-MM-DD.log` : Appels API Bitmart

### Tables d'audit
- `mtf_audit` : Résultats MTF par run
- `mtf_state` : État MTF par symbole
- `trade_lifecycle_event` : Événements de trading
- `trade_zone_events` : Événements de zone d'entrée

---

## Endpoints API

### POST /api/mtf/run
**Description** : Lance un run MTF complet

**Paramètres** :
- `symbols` : Liste de symboles (optionnel, défaut: tous actifs)
- `dry_run` : Mode simulation (défaut: true)
- `workers` : Nombre de workers parallèles (défaut: 1)
- `mtf_profile` : Profil de validation (défaut: scalper_micro)
- `force_run` : Ignore les kill switches
- `process_tp_sl` : Recalcule TP/SL pour positions ouvertes

**Réponse** :
```json
{
  "status": "success",
  "summary": {...},
  "results": [...],
  "summary_by_tf": {...},
  "orders_placed": {...},
  "performance": {...}
}
```

---

## Commandes CLI

### Runner
```bash
bin/console mtf:run --symbols=BTCUSDT,ETHUSDT --workers=4 --dry-run=0
```

### Workers Messenger
```bash
bin/console messenger:consume mtf_decision
bin/console messenger:consume order_timeout
bin/console messenger:consume mtf_projection
```

### Synchronisation
```bash
bin/console bitmart:fetch-contracts
bin/console bitmart:fetch-klines BTCUSDT --timeframe=1h --limit=200
```

### Diagnostics
```bash
bin/console app:export-symbol-data LINKUSDT "2025-11-30 13:02"
bin/console app:indicator:conditions:diagnose
```

---

## Métriques & Performance

### Métriques TradeEntry
- `submitted` : Ordres soumis avec succès
- `skipped` : Ordres skippés (raisons structurées)
- `errors` : Erreurs lors de l'exécution

### Performance Runner
- `mtf_execution` : Temps d'exécution MTF total
- `tp_sl_recalculation` : Temps de recalcul TP/SL
- `indicator_snapshot_persistence` : Temps de persistance

### Profiling
- `PerformanceProfiler` : Timings par étape
- Logs avec timestamps pour corrélation
- Export JSON des résultats de run

---

## Sécurité & Garde-fous

### Guards de risque
- **DailyLossGuard** : Limite de perte journalière
- **LiquidationGuard** : Vérifie la distance au prix de liquidation
- **SpreadGuard** : Rejette si spread trop large (market orders)

### Anti-retrade
- **MtfSwitch** : Désactive un symbole après ordre placé (4h)
- **Idempotency** : Vérifie les doublons via `decision_key`
- **Lock per symbol** : Évite les runs concurrents

### Validation
- **Preflight checks** : Vérifie balance, specs, spread avant planification
- **Entry zone validation** : Vérifie que le prix est dans la zone
- **Leverage validation** : Vérifie min/max leverage

---

## Évolutions & Roadmap

### Améliorations en cours
- Persistance asynchrone des indicateurs (déjà implémenté)
- Recalcul TP/SL dynamique pour positions ouvertes
- Support de multiples exchanges (architecture extensible)

### À venir
- Dashboard de monitoring temps réel
- Backtesting des stratégies MTF
- Optimisation automatique des paramètres

---

## Ressources & Documentation

### Documentation technique
- `docs/trading-app/` : Documentation fonctionnelle complète
- `src/*/README.md` : README par module (Runner, Validator, TradeEntry)
- `docs/trading-app/12-api-mtf-run-flux-scalper-micro.md` : Flux détaillé

### Configuration
- `config/app/trade_entry.*.yaml` : Profils TradeEntry
- `config/app/mtf_validations.*.yaml` : Profils MTF
- `config/app/mtf_contracts.*.yaml` : Filtres de contrats

### Scripts d'analyse
- `scripts/mtf_condition_report.py` : Rapport des conditions MTF
- `scripts/mtf_report.sh` : Synthèse des runs MTF

---

## Questions & Support

### Logs à consulter
1. `var/log/mtf-YYYY-MM-DD.log` : Validation MTF
2. `var/log/positions-YYYY-MM-DD.log` : TradeEntry
3. `var/log/order-journey-YYYY-MM-DD.log` : Parcours complet

### Commandes de diagnostic
```bash
# Export données complètes pour un symbole
bin/console app:export-symbol-data SYMBOL "Y-m-d H:i"

# Vérifier la santé MTF
curl http://localhost:8082/mtf/status

# Analyser les raisons de skip
rg "reason=" var/log/mtf-YYYY-MM-DD.log | sort | uniq -c
```

---

## Merci !

**TradingV3** - Plateforme de Trading Automatisé Multi-Timeframe

Documentation complète : `docs/trading-app/`

