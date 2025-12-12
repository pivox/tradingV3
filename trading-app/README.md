# Trading App – Runner, Validator & TradeEntry (2025)

Plateforme de trading Bitmart (futures/spot) centrée sur la validation multi‑timeframe (MTF). Cette version introduit un orchestrateur unique (`MtfRunnerService`) piloté par `/api/mtf/run` et `bin/console mtf:run`. Le README décrit tout le flux – de la configuration jusqu’au placement d’ordres.

---

## 1. Démarrage rapide

### 1.1 Pré‑requis
| Outil | Version conseillée |
| --- | --- |
| PHP | 8.2 |
| Composer | 2.x |
| Docker / docker‑compose | dernière stable |
| PostgreSQL | 15+ |

### 1.2 Configuration
Copier `.env.local` et renseigner les secrets :

```env
# DB
DATABASE_URL="postgresql://postgres:password@localhost:5433/trading_app?serverVersion=15&charset=utf8"

# Bitmart futures (public + private)
BITMART_API_KEY="xxxx"
BITMART_SECRET_KEY="xxxx"
BITMART_API_MEMO="prod-trader"

# WebSocket & rate-limit optional overrides
BITMART_WS_URL="wss://ws-manager-compress.bitmart.com/api?protocol=1.1"
# BITMART_RATE_PRIVATE_POSITION="3/2"
# BITMART_RATE_PRIVATE_GET_OPEN_ORDERS="20/2"
```

### 1.3 Docker
```bash
docker compose up -d trading-app-db trading-app-redis trading-app-php trading-app-nginx
docker compose exec trading-app-php php bin/console doctrine:migrations:migrate
```

### 1.4 Commandes principales
| Commande | Description |
| --- | --- |
| `bin/console bitmart:fetch-contracts` | Synchronise les contrats (option `--symbol=BTCUSDT`). |
| `bin/console bitmart:fetch-klines BTCUSDT --timeframe=1h --limit=200` | Récupère des klines. |
| `bin/console mtf:run --symbols=BTCUSDT,ETHUSDT --workers=4 --dry-run=1` | Lance un run MTF via le runner. |
| `curl -XPOST http://localhost:8082/api/mtf/run -d '{"dry_run":false,"workers":8,"mtf_profile":"scalper_micro"}'` | API HTTP équivalente. |
| `bin/console messenger:consume order_timeout` | Watcher des orders/timeouts (dead‑man interne). |

---

## 2. Architecture logique

```
RunnerController (/api/mtf/run) / CLI mtf:run
        │ (MtfRunnerRequestDto)
        ▼
    MtfRunnerService
        ├─ resolveSymbols + sync tables Bitmart
        ├─ filtre ordres/positions (Switches/Locks)
        ├─ runSequential() / runParallel()    => MtfValidatorService
        ├─ dispatchIndicatorSnapshotPersistence()
        ├─ processTpSlRecalculation()
        └─ enrichResults()

    MtfValidatorService
        └─ MtfValidatorCoreService
             ├─ ContextValidationService → TimeframeValidationService
             ├─ ExecutionSelectionService
             └─ TradingDecisionHandler (dispatch Messenger → TradeEntry)

    TradeEntryService
        └─ Workflow BuildPreOrder → BuildOrderPlan → ExecuteOrderPlan → AttachTpSl
```

### Modules clés
| Module | README |
| --- | --- |
| Runner (orchestration) | `src/MtfRunner/README.md` |
| Validator (règles MTF) | `src/MtfValidator/README.md` |
| TradeEntry (pricing + ordres) | `src/TradeEntry/README.md` |
| Indicator/Provider (conditions, snapshots, accéder à Bitmart) | `src/Indicator/README.md`, `src/Provider/README.md` |

---

## 3. Flux d’exécution

### 3.1 Runner
- Résout la liste des symboles (contrats DB + queue `mtf_switch`).
- Synchronise positions et ordres via `FuturesOrderSyncService`.
- Filtre les symboles déjà engagés (positions/ordres). Les switches sont prolongés automatiquement.
- Lance l’exécution MTF soit séquentielle (direct `MtfValidatorInterface::run()`), soit parallèle (workers `mtf:run-worker`).
- Publie un message `IndicatorSnapshotPersistRequestMessage` pour sauvegarder les indicateurs.
- Post‑traitements : recalcul TP/SL (si `process_tp_sl=true`), enrichissement des résultats (summary TF, orders, etc.).

### 3.2 Validator
- `MtfValidatorService` transforme `MtfRunnerRequestDto` → `MtfRunDto`.
- `MtfValidatorCoreService` :
  1. Charge `MtfValidationConfig` (profils `config/app/mtf_validations*.yaml`).
  2. Récupère les indicateurs via `IndicatorProviderInterface`.
  3. Valide le contexte (ContextValidationService) puis sélectionne le TF d’exécution (ExecutionSelectionService).
  4. Retourne `MtfResultDto` (`isTradable`, `executionTimeframe`, raisons).
- Publie `MtfResultProjectionMessage` et `MtfTradingDecisionMessage` (bus Messenger).

### 3.3 TradeEntry
- `TradingDecisionHandler` consomme `SymbolResultDto`, vérifie les préconditions, construit le `TradeEntryRequest`.
- `TradeEntryService` orchestre :
  - `BuildPreOrder` → récupère specs/balance/spread/pivots.
  - `BuildOrderPlan` → entry zone (VWAP/SMA21), prix limit, stop ATR/pivot/risk, TP (k·R + pivots), sizing, levier dynamique.
  - `ExecuteOrderPlan` → soumet levier, orders, watchers maker/taker, dead‑man switch, fallback end‑of‑zone.
  - `AttachTpSl` ou `TpSlTwoTargetsService` recalculera les TP/SL à chaud si besoin.

---

## 4. Configuration & profiles

### 4.1 Modes MTF / TradeEntry
- `config/app/mtf_validations.<mode>.yaml` : règles multi‑timeframes, execution_selector, filters, etc.
- `config/app/trade_entry.<mode>.yaml` : risk/r_multiple, `stop_from`, policies TP, `market_entry`, `post_validation.entry_zone`, leverage caps.
- `config/trading.yml` : paramètres partagés (entry zone, fallback, watchers).
- `TradeEntryModeContext` + `MtfValidationConfigProvider` sélectionnent le profil actif (`scalper_micro` par défaut).

### 4.2 Secrets / .env
- `BITMART_*` : credentials Bitmart.
- `APP_ENV`, `APP_DEBUG`.
- `REDIS_URL`, `MESSENGER_TRANSPORT_DSN`.
- `MTF_LOG_LEVEL`, `LOG_LEVEL_*` (multi‑logger).

---

## 5. Observabilité

| Logger | Usage |
| --- | --- |
| `monolog.logger.mtf` | Runner, validator, temporal pipelines. |
| `monolog.logger.positions` | TradeEntry, TP/SL, order journey. |
| `monolog.logger.provider` | Providers HTTP/WS. |

Fichiers utiles :
- `var/log/order-journey*.log` : relecture d’un plan complet (signal → plan → ordre).
- `var/log/mtf-runner.log` : résolution, filtres, watchers, snapshots.
- `var/log/bitmart-http.log` : détails REST (avec rate-limit).

Profils perf :
- `PerformanceProfiler` (Runner) renvoie des timings par étape.
- Commande `bin/console app:indicator:conditions:diagnose` permet d’inspecter une condition (YAML vs compilée).

---

## 6. Services/Workers externes

| Process | Description |
| --- | --- |
| `messenger:consume order_timeout` | Traite les jobs d’annulation/TP‑SL asynchrones. |
| `messenger:consume indicator_snapshot` (optionnel) | Persiste les snapshots d’indicateurs. |
| `scheduler/cron` | Déclenche `mtf:run` ou API Runner selon vos besoins (ex: toutes les 5 min). |

---

## 7. Diagnostics rapides

| Endpoint / Commande | Description |
| --- | --- |
| `GET /mtf/status` | Vérifie la santé (locks, timestamp, workflow). |
| `GET /mtf/lock/status` / `/mtf/lock/cleanup` | Inspecte ou nettoie les locks. |
| `GET /mtf/audit?symbol=BTCUSDT` | Liste les derniers audits MTF (DB). |
| `GET /provider/health` (si exposé) | Vérifie les providers Kline/Order/Account. |
| `bin/console debug:config app` | Vérifie la config active. |
| `docs/*` | Documentation détaillée par module (`Indicator`, `Provider`, `MtfRunner`, `MtfValidator`, `TradeEntry`). |

---

## 8. Checklist dev

1. **Ajouter un mode** → YAML `mtf_validations.<mode>` + `trade_entry.<mode>` + activer dans `services.yaml (mode list)`.
2. **Modifier un flux** → mettre à jour le README du module concerné, ajouter tests (Runner/Validator/TradeEntry).
3. **Nouvel exchange** → implémenter `ExchangeProviderBundle`, enregistrer dans `ExchangeProviderRegistry`, passer `exchange`/`market_type` côté Runner.
4. **Déploiement** → faire tourner les workers Messenger, surveiller `order_journey` et `mtf_runner` pour valider l’intégration.

---

## 9. Ressources

- `docs/README.md` : architecture globale + liens vers les modules.
- `docs/MTF_POSITIONS_USAGE.md`, `docs/MTF_PERFORMANCE_ANALYSIS.md`, `docs/BUGS_ATR_STOP_LOSS.md` : runbooks historiques.
- `src/*/README.md` : documentation approfondie par composant (Runner, Validator, TradeEntry, Provider, Indicator).

Ce README donne les points d’entrée essentiels. Pensez à maintenir les README des sous-modules lorsque vous introduisez une nouvelle fonctionnalité, afin de garder l’architecture claire pour toute l’équipe. Bonne exécution MTF !
