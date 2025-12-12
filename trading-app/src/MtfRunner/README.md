# MtfRunner – Orchestrateur MTF

`App\MtfRunner\Service\MtfRunnerService` est la boucle d’exécution principale utilisée par `/api/mtf/run` (RunnerController) et `bin/console mtf:run`. Il gère tout ce qui entoure le validateur (locks, switches, filtres, synchro Bitmart, parallélisation, TP/SL, enrichissement des résultats). Ce README documente chaque étape et chaque paramètre.

---

## 1. Flow global

```
RunnerController / mtf:run
    ├─ Normalise les paramètres (dry_run, force_run, profile, workers, etc.)
    ├─ Construit un MtfRunnerRequestDto
    └─ Appelle MtfRunnerService::run(request)

MtfRunnerService::run()
    1. resolveSymbols()            # fallback DB + switch queue
    2. createContext()             # ExchangeContext (exchange + market type)
    3. syncTables()                # FuturesOrderSyncService (positions, orders)
    4. filterSymbolsWithOpen*()    # évite les symboles avec positions/ordres ouverts
    5. manageLocks()/manageSwitches() # trace/placeholder (locks gérés en amont)
    6. runSequential() ou runParallel() # exécution MTF via MtfValidatorInterface
    7. dispatchIndicatorSnapshotPersistence() # snapshot indicators
    8. updateSwitchesForExcludedSymbols()
    9. processTpSlRecalculation()  # recalcul tiers TP/SL (throttling)
   10. enrichResults()             # summary_by_tf, rejected_by, last_validated, orders_placed
```

Retour : `[
  'summary' => [...],
  'results' => [...],
  'errors' => [...],
  'summary_by_tf' => [...],
  'rejected_by' => [...],
  'last_validated' => [...],
  'orders_placed' => ['count' => ..., 'orders' => ...],
  'performance' => profiler
]`.

---

## 2. `MtfRunnerRequestDto`

Paramètres clés (depuis API ou CLI) :

| Champ | Description |
| --- | --- |
| `symbols` | Liste explicite. Vide → `ContractRepository::allActiveSymbolNames()` + queue `MtfSwitchRepository`. |
| `dry_run` | Ne déclenche pas les messages de projection/trade (coté validateur). |
| `force_run` | Ignore certains guards côté MTF (switches, context check). |
| `current_tf` | Force un TF unique. Sinon, multi‑TF selon config. |
| `force_timeframe_check` | Rejoue un TF même si la dernière bougie est fraîche. |
| `skip_context` | Bypass `ContextValidationService`. |
| `lock_per_symbol` | Indique aux workers d’utiliser les locks symbol (rend `MtfRunRequestDto::lockPerSymbol=true`). |
| `skip_open_state_filter` | (API) Laisse passer les symboles même si positions/ordres ouverts (désactivé par défaut). |
| `workers` | >1 → exécution parallèle via `mtf:run-worker` (Process). |
| `profile`, `validation_mode` | Propagés vers `MtfValidatorService`. |
| `exchange`, `market_type` | Construisent l’`ExchangeContext` (Bitmart perp par défaut). |
| `sync_tables`, `process_tp_sl` | Activer la synchro et le recalcul TP/SL (true par défaut). |

---

## 3. Résolution & filtres

### 3.1 resolveSymbols()
- Si la liste fournie est vide, récupère tous les symboles actifs (`ContractRepository::allActiveSymbolNames([], false)`).
- Ajoute ceux présents dans la queue switch (symbole dont la désactivation expire bientôt) via `MtfSwitchRepository::consumeSymbolsWithFutureExpiration()`.

### 3.2 syncTables(ExchangeContext)
`FuturesOrderSyncService` synchronise les tables internes (positions, futures_order, futures_order_trade) depuis l’exchange avant de filtrer. Retourne `open_positions` et `open_orders` (réutilisés plus tard).

### 3.3 filterSymbolsWithOpenOrdersOrPositions()
- Récupère via `MainProviderInterface` (ou re‑utilise la synchro) les positions et ordres ouverts.
- `MtfSwitchRepository::reactivateSwitchesForInactiveSymbols()` rallume automatiquement les symboles qui n’ont plus d’activité.
- Les symboles ayant une position ou un ordre actif sont exclus (`excludedSymbols`).
- Les switches des symboles exclus sont prolongés via `updateSwitchesForExcludedSymbols()` (1m si déjà OFF, 5m sinon) pour éviter les rechecks incessants.

---

## 4. Locks & switches

- `manageLocks()` : aujourd’hui informatif (log si le lock global existe). Les locks sont gérés par l’orchestrateur/Temporal.
- `manageSwitches()` : placeholder (les switchs sont traités lors du filtrage). Se prête à injecter de futures stratégies (par exemple, auto‑désactivation si un symbole échoue trop souvent).

---

## 5. Exécution MTF

### 5.1 Séquentiel (`runSequential`)
- Construit un `MtfRunRequestDto` adapté et appelle directement `MtfValidatorInterface::run()`.
- Résultats convertis en map [symbol => …] (`SymbolResultDto` → tableau).

### 5.2 Parallèle (`runParallel`)
- Utilise une `SplQueue` et lance N `Process` (`php bin/console mtf:run-worker --symbols=...`).
- Options propagées via `buildWorkerCommand()` (`--force-run`, `--tf`, `--profile`, `--validation-mode`, etc.).
- Chaque worker renvoie un JSON contenant `final.results`.
- Le runner agrège les résultats, compte les succès / échecs / skipped, calcule un `summary`.
- Un watcher interne log poll time, start/end workers, errors, etc.

---

## 6. Post-traitements

### 6.1 Indicator snapshots
`dispatchIndicatorSnapshotPersistence()` envoie un `IndicatorSnapshotPersistRequestMessage` si des résultats sont disponibles :
- Timeframes : `current_tf` si forcé, sinon `MtfValidatorInterface::getListTimeframe(profile)`.
- Message bus : asynchrone (persist/compare côté indicator service).

### 6.2 Recalcul TP/SL (`processTpSlRecalculation`)
- Exécuté si `process_tp_sl=true` **et** `shouldRunTpSlNow()` (toutes les 3 minutes).
- Récupère les positions & ordres ouverts via `MainProviderInterface`.
- Pour chaque position avec exactement un ordre TP, appelle `TpSlTwoTargetsService` (mise à jour SL + 2 TP) en mode synchrone (dry-run respecte l’option).
- Nombreuses gardes (provider manquant, service absent, position side invalide, etc.).

### 6.3 Enrichissement des résultats (`enrichResults`)
- `summary_by_tf` via `buildSummaryByTimeframe()`.
- `rejected_by` : tous les symboles dont `status` ≠ `SUCCESS|COMPLETED|READY`.
- `last_validated` : symboles prêts, remontés sur `tf-1` (ex : un 1m valide → `READY`, un 5m valide → `15m`).
- `orders_placed` : `OrdersExtractor::extractPlacedOrders()` + `countOrdersByStatus()`.

---

## 7. Paramètres avancés & options

| Option | Effet |
| --- | --- |
| `skip_open_state_filter=true` | Process tous les symboles (utile pour diagnostics). |
| `lock_per_symbol=true` | Les workers `mtf:run-worker` créent des locks symbol (évite les collisions quand plusieurs runners tournent). |
| `sync_tables=false` | Passe la synchro Bitmart (utiliser avec précaution). |
| `process_tp_sl=false` | Désactive le recalcul post‑run (runner se focalise uniquement sur la validation). |
| `force_run` | Propagé jusqu’à `MtfValidatorCoreService` → bypass context/switch guards. |
| `force_timeframe_check` | Rejoue un TF même si la bougie précédente vient de se fermer. |
| `profile` / `validation_mode` | Orientent la config MTF/TradeEntry à utiliser (ex: `scalper_micro`, `regular`). |
| `current_tf` | Limite le run à un seul timeframe (ex: recheck `1h` uniquement). |

---

## 8. Logs & observabilité

Les loggers dédiés :
- `monolog.logger.mtf` (`$mtfLogger`) : cycle runner (résolution, perf, switch, snapshot).
- `monolog.logger.positions` (`$positionsLogger`) : recalcul TP/SL, interactions TradeEntry.
- `monolog.logger` (`$logger`) : warnings généraux (ex: `filterSymbolsWithOpenOrdersOrPositions`).

Chaque étape logge un identifiant `run_id` (UUID) et un `decision_key` lorsqu’il est disponible (propagation jusqu’à TradeEntry).

Le `PerformanceProfiler` retourne des timings par catégorie (`resolve_symbols`, `sync_tables`, `filter_symbols`, `mtf_execution`, `tp_sl_recalculation`, `post_processing`).

---

## 9. Extension & maintenance

- **Nouveaux exchanges** : `RunnerRequestDto` supporte `exchange`/`market_type`. Il suffit d’enregistrer le bundle dans `ExchangeProviderRegistry` et le runner basculera sur ce contexte (`MainProviderInterface::forContext()`).
- **Nouveaux filtres** : implémenter dans `filterSymbolsWithOpenOrdersOrPositions` ou ajouter un nouveau responsable (ex: filtrer par PnL, volatilité, etc.).
- **Hooks post-run** : étendre `enrichResults` ou ajouter des dispatchs Messenger supplémentaires.
- **Tests** : cibler `tests/MtfRunner/Service/*` pour vérifier la résolution, le filtrage et la gestion des workers (mocks de providers). Les tests MTF proprement dits restent dans `tests/MtfValidator`.

---

## 10. Rappels d’usage

1. **Toujours** passer par `MtfRunnerService` (ne jamais appeler directement `MtfValidatorService` depuis l’API). Le runner gère les responsabilités périphériques indispensables.
2. Guarder l’API/CLI : valider les options et leur cohérence (`--workers`, `--symbols`, `--force-run`, etc.).
3. Documenter toute nouvelle option dans les controllers/Command README + ce fichier pour garder la vision globale du module.
4. Ces fonctionnalités servent aussi bien aux run manuels (CLI) qu’aux orchestrations Temporal/cron : respecter la compatibilité ascendante lorsque vous ajoutez un paramètre.

Avec ce README, vous disposez d’une carte complète du runner. Ajoutez vos retours lorsque vous introduisez un nouveau filtre ou changez l’orchestration, afin que tout le monde sache où se brancher. Bonne exécution MTF !
