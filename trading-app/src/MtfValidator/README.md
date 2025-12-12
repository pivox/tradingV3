# MTF Validator – Architecture 2025

Ce module implémente tout le pipeline de validation multi‑timeframes utilisé par `/api/mtf/run` (RunnerController) et la commande `mtf:run`. Depuis la refonte runner (2024‑2025), **MtfRunnerService** gère l’orchestration complète (filtrage, locks, synchro provider) puis délègue au **module MtfValidator** pour décider si un symbole est prêt (`READY`) ou rejeté (`INVALID`). Cette page explique les composants clés pour contribuer au module.

---

## 1. Vue d’ensemble

```
RunnerController / mtf:run
      │
      └─► MtfRunnerService
             │  (MtfRunRequestDto)
             ▼
        MtfValidatorInterface (MtfValidatorService)
             │
             └─► MtfValidatorCoreService
                    │
                    ├─ ContextValidationService + TimeframeValidationService
                    │        ↳ ConditionLoader + IndicatorEngine
                    └─ ExecutionSelectionService
             ▼
        SymbolResultDto / MtfResultDto
             │
             └─ TradingDecisionHandler → TradeEntry
```

### Points clés
- L’entrée officielle reste `MtfValidatorInterface::run(MtfRunRequestDto)`.
- `MtfValidatorService` boucle sur les symboles, instancie `MtfRunDto`, inflige les messages Messenger (`MtfResultProjectionMessage`, `MtfTradingDecisionMessage`) et flush quand `dry_run = false`.
- `MtfValidatorCoreService` décide du contexte/exécution à partir du YAML (`config/app/mtf_validations*.yaml`) et des indicateurs fournis par `IndicatorProviderInterface`.
- Les résultats normalisés sont exposés via `SymbolResultDto`, utilisés ensuite par `TradingDecisionHandler` (placement ou skip).

---

## 2. Dossiers et responsabilités

| Dossier | Contenu |
| --- | --- |
| `Command/` | CLI liées au module (diagnostics, run worker). Les commandes legacy `TestMtf*` ont été retirées. |
| `ConditionLoader/` | Parsing du YAML et registry Condition ↔ Rule. |
| `Controller/` | Endpoints REST historiques (`MtfController`) : status, audit, locks, sync. L’endpoint `/api/mtf/run` est désormais servi par `RunnerController`. |
| `Decision/` | DTOs/metadonnées sur les décisions (context, execution). |
| `Entity/` `Repository/` | Audits, switches, locks, state (Doctrine). |
| `Event/` `EventSubscriber/` | Événements et listeners spécifiques (audit, monitoring). |
| `Execution/` | `ExecutionSelectionService` et helpers pour choisir le timeframe d’exécution. |
| `Message/` `MessageHandler/` | Messages Messenger (projection des résultats, décisions trading). |
| `Runtime/` | Services transverses (LockManager, caches, concurrency utilities). |
| `Service/` | Cœur métier : `MtfValidatorService`, `MtfValidatorCoreService`, `ContextValidationService`, `TimeframeValidationService`, `TradingDecisionHandler`, `Persistence` sinks, helpers, etc. |
| `Validator/` | Validateurs fonctionnels (cohérences YAML, checkers). |

---

## 3. Flux détaillé

### 3.1 `MtfValidatorService::run`
1. Reçoit un `MtfRunRequestDto`.
2. Pour chaque symbole, construit `MtfRunDto` (profil, mode, options `force_run`, `force_timeframe_check`, `user_id`, etc.).
3. Appelle `MtfValidatorCoreService::validate()`.
4. Stocke le `MtfResultDto` et, si nécessaire, dispatch :
   - `MtfResultProjectionMessage` (persist/resume).
   - `MtfTradingDecisionMessage` si `isTradable`.
5. Compile les statistiques (success/failure/skip) et retourne un `MtfRunResponseDto`.

### 3.2 `MtfValidatorCoreService`
1. Récupère la configuration `MtfValidationConfig` via `MtfValidationConfigProvider`.
2. Détermine les timeframes de contexte/exécution (YAML `context_timeframes`, `execution_timeframes`, fallback).
3. Demande les indicateurs pour tous les TF concernés (via `IndicatorProviderInterface`).
4. `ContextValidationService` (→ `TimeframeValidationService`) vérifie le contexte et renvoie un `ContextDecisionDto`.
5. Si le contexte est valide, `ExecutionSelectionService` choisit le TF d’exécution basé sur `ExecutionDecision`.
6. Construit le `MtfResultDto` (context, execution, reasons, `isTradable`) et le renvoie au service appelant.

### 3.3 `TimeframeValidationService`
- Interprète le YAML (rules, filters, `filters_mandatory`).
- Peut basculer sur le moteur ConditionRegistry compilé si disponible.
- Retourne un `TimeframeDecisionDto` pour chaque TF inspecté (`valid`, `signal`, `invalidReason`, conditions passées/échouées).

### 3.4 `TradingDecisionHandler`
- Reçoit un `SymbolResultDto`.
- Vérifie les préconditions (execution TF choisi, zone disponible, ATR cohérent, `allowed_execution_timeframes` côté TradeEntry).
- Construit la requête `TradeEntryRequest` via `TradeEntryRequestBuilder` et délègue à `TradeEntryService`.
- Génère des résultats `SymbolResultDto` enrichis (status `READY`, `INVALID`, `SKIPPED`, etc.).

---

## 4. Configuration MTF

- Les fichiers YAML se trouvent sous `src/MtfValidator/config/validations.*.yaml`.
- `MtfValidationConfigProvider` sélectionne la config selon le profil (`scalper`, `regular`, `scalper_micro`, …).
- Les champs clés :
  - `rules`: définitions de règles atomiques (composition condition → indicateur).
  - `validation.timeframe`: configuration `long/short`, `filters`, `grace_window`.
  - `execution_selector`: logique de sélection (stay/drop, allow_1m_only_for, etc.).
  - `filters_mandatory`: garde-fous transverses (ex. volatilité, spread).

---

## 5. Messagerie & persistance

| Message | Handler | Rôle |
| --- | --- | --- |
| `MtfResultProjectionMessage` | `MtfResultProjectionMessageHandler` | Persister les résultats détaillés (DB/logs). |
| `MtfTradingDecisionMessage` | `MtfTradingDecisionMessageHandler` | Post‑process (TradingDecisionHandler) dans un worker dédié si nécessaire. |
| `IndicatorSnapshotPersistRequestMessage` | (coté Runner) | Sauvegarder les snapshots d’indicateurs après un run. |

Les repositories `MtfSwitchRepository`, `MtfLockRepository`, `MtfAuditRepository`, `MtfStateRepository` fournissent les opérations SQL nécessaires (locks, audit trail, user switches).

---

## 6. Tests & diagnostics

- **Commandes**
  - `bin/console mtf:run` : CLI orchestrée par `MtfRunnerService`.
  - `bin/console mtf:run-worker` : exécute un symbole par process (utilisé par l’exécution parallèle).
  - Scripts de debug (`debug_mtf_skip.php`, `test_force_run_fix.php`) au niveau racine pour scénarios spécifiques.
- **Tests PHPUnit**
  - `tests/MtfValidator/Service/*` : tests unitaires/métier.
  - `tests/MtfValidator/Integration/*` : autowiring, pipeline complet.
- **Endpoints REST (`MtfController`)**
  - `/mtf/status`, `/mtf/lock/status`, `/mtf/audit`, `/mtf/sync-contracts` fournissent des diagnostics runtime.

---

## 7. Ajouter / modifier des règles

1. Mettre à jour le YAML (`validations.*.yaml`) avec les nouvelles règles/timeframes.
2. Ajouter les conditions correspondantes dans `src/Indicator/Condition` (si nécessaires) et taguer avec `#[AsIndicatorCondition]`.
3. Mettre à jour les tests (`tests/MtfValidator/Service/TimeframeValidationServiceTest.php`, etc.).
4. Vérifier les checkers `Validator/` (ex. `LogicalConsistencyChecker`) pour éviter les règles orphelines.

---

## 8. Bonnes pratiques

- Toujours passer par `MtfValidatorInterface` (pas d’accès direct aux services internes) pour préserver l’encapsulation.
- Utiliser `MtfRunnerService` pour orchestres complets (filtrage ordres/positions, locks, switches). Le module MTF ne gère plus ces responsabilités.
- Documenter toute nouvelle option CLI/API (`RunnerController`) afin que le runner sache la propager à `MtfRunRequestDto`.
- Pour de nouvelles dépendances (logs, providers), utiliser la DI Symfony et privilégier les interfaces existantes.

Ce README doit servir de référence rapide pour naviguer dans le module, comprendre où implémenter une fonctionnalité et quelles sont les interactions avec les autres couches (Runner, Indicator, TradeEntry). Ajoutez‑y vos retours ou liens vers des dossiers spécifiques si vous étendez le périmètre. Bonne contribution !
