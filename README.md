# Trading App – Orchestrateur MTF 2025

Plateforme Symfony dédiée au trading Bitmart futures. Elle combine :
- un **Runner HTTP/CLI** (`/api/mtf/run`, `bin/console mtf:run`) qui orchestre les validations multi‑timeframes ;
- un **Validator** qui applique les règles YAML et décide si un symbole est tradable ;
- un **module TradeEntry** qui calcule zone, taille, levier, SL/TP et place les ordres.

Le dépôt contient aussi les workers Temporal (`cron_symfony_mtf_workers/`) qui déclenchent les runs toutes les minutes, ainsi que les outils Provider/Indicator pour fluidifier les échanges avec Bitmart.

---

## 1. Mise en route

### 1.1 Pré‑requis
| Outil | Version |
| --- | --- |
| PHP | 8.2+ |
| Composer | 2.x |
| Docker / docker compose | dernière stable |
| PostgreSQL | 15+ |

### 1.2 Installation rapide
```bash
cp trading-app/.env trading-app/.env.local        # ajuster les secrets
docker compose up -d trading-app-db trading-app-redis trading-app-php trading-app-nginx
docker compose exec trading-app-php composer install
docker compose exec trading-app-php php bin/console doctrine:migrations:migrate
```

`.env.local` doit au minimum contenir :
```env
DATABASE_URL="postgresql://postgres:password@trading-app-db:5432/trading_app?serverVersion=15&charset=utf8"
BITMART_API_KEY=xxx
BITMART_SECRET_KEY=xxx
BITMART_API_MEMO=prod-trader
REDIS_URL=redis://trading-app-redis:6379
MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=log_messages
```

### 1.3 Commandes essentielles
| Commande | Description |
| --- | --- |
| `bin/console bitmart:fetch-contracts [--symbol=BTCUSDT]` | Sync des contrats Bitmart. |
| `bin/console bitmart:fetch-klines BTCUSDT --timeframe=1h --limit=200` | Ingestion des klines. |
| `bin/console mtf:run --workers=4 --dry-run=1` | Run MTF piloté par le runner. |
| `curl -XPOST http://localhost:8082/api/mtf/run -d '{"dry_run":false,"workers":8,"mtf_profile":"scalper_micro"}'` | Appel HTTP équivalent. |
| `bin/console messenger:consume order_timeout` | Worker TP/SL + dead-man switch. |

---

## 2. Architecture

```
API RunnerController / CLI mtf:run
          │ (MtfRunnerRequestDto)
          ▼
      MtfRunnerService
          ├ resolveSymbols + sync tables Bitmart
          ├ filtre positions/ordres ouverts (switch repository)
          ├ runSequential() / runParallel() → MtfValidatorService
          ├ dispatchIndicatorSnapshotPersistence()
          ├ processTpSlRecalculation()
          └ enrichResults()

      MtfValidatorService
          └ MtfValidatorCoreService
               ├ ContextValidationService → TimeframeValidationService
               ├ ExecutionSelectionService
               └ TradingDecisionHandler → TradeEntry

      TradeEntryService
          └ BuildPreOrder → BuildOrderPlan → ExecuteOrderPlan → AttachTpSl
```

### Modules et documentation
| Module | README |
| --- | --- |
| Runner | `trading-app/src/MtfRunner/README.md` |
| Validator | `trading-app/src/MtfValidator/README.md` |
| TradeEntry | `trading-app/src/TradeEntry/README.md` |
| Indicator | `trading-app/src/Indicator/README.md` |
| Provider (Bitmart) | `trading-app/src/Provider/README.md` |
| Temporal cron | `cron_symfony_mtf_workers/README.md` |

---

## 3. Flux d’exécution détaillé

### 3.1 Runner
- Résout les symboles (contrats actifs + queue `mtf_switch`).
- Synchronise positions/ordres (`FuturesOrderSyncService`), exclut les symboles occupés et prolonge les switches.
- Lance l’exécution MTF en séquentiel (appel direct) ou parallèle (workers `mtf:run-worker`).
- Publie `IndicatorSnapshotPersistRequestMessage` pour persister les contextes.
- Recalcule périodiquement les TP/SL ouverts (`TpSlTwoTargetsService`).
- Enrichit la réponse (summary par TF, `rejected_by`, `orders_placed`).

### 3.2 Validator
1. `MtfRunRequestDto` → `MtfRunDto` (profil, options `force_run`, `current_tf`, `lock_per_symbol`, etc.).
2. `MtfValidatorCoreService` charge `config/app/mtf_validations.<mode>.yaml` via `MtfValidationConfigProvider`.
3. `IndicatorProviderInterface` fournit les contextes multi‑TF.
4. `ContextValidationService` + `TimeframeValidationService` appliquent les règles YAML ou compilées.
5. `ExecutionSelectionService` choisit le timeframe final (stay/drop, allow_1m_only_for…).
6. `TradingDecisionHandler` construit le `TradeEntryRequest` et envoie les signaux prêts.

### 3.3 TradeEntry
- `BuildPreOrder` collecte specs exchange, balance, spread, pivots.
- `BuildOrderPlan` calcule l’entry zone (VWAP/SMA21), le prix limit/market, le stop (ATR/pivot/risk), les TP multiples et le sizing avec levier dynamique.
- `ExecuteOrderPlan` soumet le levier, place l’ordre (maker/taker), déclenche watchers (limit fill, fallback end-of-zone).
- `AttachTpSl` ou `TpSlTwoTargetsService` ajoute les ordres stop/take restants.

---

## 4. Configuration

| Fichier | Description |
| --- | --- |
| `config/app/mtf_validations.<mode>.yaml` | Règles multi‑timeframes (context/execution, filters, execution_selector). |
| `config/app/trade_entry.<mode>.yaml` | Risk sizing, leverage, stop policies, entry zone, market entry. |
| `config/trading.yml` | Paramètres partagés (entry zone, watchers, market_entry). |
| `config/app/mtf_contracts.yaml` | Contrats activés côté runner. |

- `MtfValidationConfigProvider` + `TradeEntryModeContext` sélectionnent le mode actif (`scalper_micro` par défaut).
- Secrets indispensables : `BITMART_*`, `APP_ENV`, `APP_DEBUG`, `REDIS_URL`, `MESSENGER_TRANSPORT_DSN`, `MTF_LOG_LEVEL`.

---

## 5. Cron & Temporal

`cron_symfony_mtf_workers/` contient les workflows/activities Temporal qui appellent `/api/mtf/run`. Principales schedules :

| Script | Rôle | Cron |
| --- | --- | --- |
| `scripts/manage_mtf_workers_schedule.py` | Runner standard (5 workers, dry-run configurable) | `*/1 * * * *` |
| `scripts/manage_scalper_micro_schedule.py` | Profil `scalper_micro` (8 workers) | `*/1 * * * *` |
| `scripts/manage_contract_sync_schedule.py` | `GET /api/mtf/sync-contracts` | `0 9 * * *` |
| `scripts/manage_cleanup_schedule.py` | Jobs de purge (klines, audits) | selon besoin |

`CronSymfonyMtfWorkersWorkflow` → `mtf_api_call` → `utils/response_formatter.py` réduit les logs à ~15 lignes (succès par TF, invalid par TF, timing) tout en conservant la réponse complète dans Temporal UI.

---

## 6. Observabilité & diagnostics

| Logger / fichier | Usage |
| --- | --- |
| `var/log/mtf-runner.log` (`monolog.logger.mtf`) | Résolution symboles, filtres, exécution MTF, snapshots. |
| `var/log/order-journey*.log` (`monolog.logger.positions`) | Détails TradeEntry (prix, watchers, levier). |
| `var/log/bitmart-http.log` (`monolog.logger.provider`) | Appels Bitmart + rate-limit. |

API / commandes utiles :
- `GET /mtf/status`, `/mtf/lock/status`, `/mtf/audit`.
- `GET /provider/health`.
- `bin/console debug:config app`.
- `bin/console app:indicator:conditions:diagnose <symbol> <tf>`.

---

## 7. Checklists développement

1. **Nouveau mode** : `mtf_validations.<mode>.yaml` + `trade_entry.<mode>.yaml`, enregistrer dans `services.yaml`, docs Runner/TradeEntry.
2. **Nouveau filtre/feature Runner** : modifier `src/MtfRunner/*`, ajouter tests (`tests/MtfRunner/Service/*`), mettre à jour le README du module.
3. **Nouvelle règle MTF** : mettre à jour le YAML, ajouter les conditions `src/Indicator/Condition/*`, couvrir par `tests/MtfValidator`.
4. **Évolution TradeEntry** : modifier builder/plan/execution + documentation `src/TradeEntry/README.md`.
5. **Nouvel exchange** : créer un `ExchangeProviderBundle`, l’enregistrer dans `ExchangeProviderRegistry`, utiliser `MainProviderInterface::forContext()`.
6. **Temporal** : après toute évolution API/Runner, vérifier `cron_symfony_mtf_workers/README.md` et relancer les schedules.
7. **Déploiement** : s’assurer que `messenger:consume order_timeout` (et `indicator_snapshot` si utilisé) tournent, surveiller `mtf_runner` et `order_journey`.

---

## 8. Ressources complémentaires

- `trading-app/src/*/README.md` : documentation approfondie par composant.
- `trading-app/docs/*` : runbooks historiques (`MTF_POSITIONS_USAGE`, `MTF_PERFORMANCE_ANALYSIS`, etc.).
- `cron_symfony_mtf_workers/docs/ARCHITECTURE.md` : schéma complet du workflow Temporal.

Gardez ces documents à jour à chaque évolution : ils constituent la source de vérité pour l’équipe (runbook d’exploitation, onboarding, support Temporal). Bonne orchestration MTF !
