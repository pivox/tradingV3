# Utilisation des positions ouvertes/fermées dans MTF

## Vue d'ensemble

Les positions ouvertes et fermées sont utilisées dans le flux MTF à **deux moments distincts** :

1. **AU DÉBUT** : Filtrage des symboles à traiter
2. **À LA FIN** : Recalcul des TP/SL pour les positions existantes

## 1. AU DÉBUT : Filtrage des symboles (Étape 4)

### Où ?
- `MtfRunnerService::run()` → Étape 4
- `MtfRunOrchestrator::filterSymbolsWithOpenOrdersOrPositions()`
- `MtfController::filterSymbolsWithOpenOrdersOrPositions()`
- `MtfRunCommand::filterSymbolsWithOpenOrdersOrPositions()`

### Quand ?
**Avant l'exécution MTF** - pour éviter de traiter des symboles qui ont déjà une position ou un ordre ouvert.

### Comment ?
```php
// Récupère les positions ouvertes depuis l'exchange
$openPositions = $accountProvider->getOpenPositions();

// Récupère les ordres ouverts depuis l'exchange
$openOrders = $orderProvider->getOpenOrders();

// Exclut les symboles qui ont des positions/ordres ouverts
$symbolsWithActivity = array_unique(array_merge($openPositionSymbols, $openOrderSymbols));
```

### Objectif
- **Éviter les doublons** : Ne pas ouvrir une nouvelle position si une position existe déjà
- **Éviter les conflits** : Ne pas traiter un symbole qui a déjà un ordre en attente
- **Gestion des switches** : Désactiver temporairement les switches pour les symboles exclus

### Configuration
- Peut être désactivé avec `--skip-open-state-filter` ou `skipOpenStateFilter: true`
- Par défaut : **ACTIVÉ**

## 2. À LA FIN : Recalcul TP/SL (Étape 9)

### Où ?
- `MtfRunnerService::run()` → Étape 9
- `MtfRunService::processTpSlRecalculation()`

### Quand ?
**Après l'exécution MTF** - pour mettre à jour les TP/SL des positions existantes.

### Comment ?
```php
// Récupère toutes les positions ouvertes
$openPositions = $accountProvider->getOpenPositions();

// Pour chaque position, recalcule les TP/SL
foreach ($openPositions as $position) {
    // Recalcul basé sur le prix actuel, ATR, etc.
    $this->recalculateTpSl($position);
}
```

### Objectif
- **Mise à jour dynamique** : Ajuster les TP/SL en fonction du prix actuel
- **Gestion du risque** : Maintenir les protections à jour

### Configuration
- Peut être désactivé avec `processTpSl: false` dans la requête
- Par défaut : **ACTIVÉ**

## 3. Synchronisation des tables (Étape 3 - Optionnel)

### Où ?
- `MtfRunnerService::run()` → Étape 3
- `MtfRunnerService::syncTables()`

### Quand ?
**Au début, avant le filtrage** - si `syncTables: true` est activé.

### Comment ?
```php
// Synchronise les positions depuis l'exchange vers la table positions
$this->syncPositions($accountProvider);

// Synchronise les ordres depuis l'exchange vers la table futures_order
$this->syncOrders($orderProvider);
```

### Objectif
- **État à jour** : S'assurer que la BDD reflète l'état réel de l'exchange
- **Base pour le filtrage** : Le filtrage utilise ensuite ces données synchronisées

### Configuration
- Contrôlé par `syncTables: true/false` dans la requête
- Par défaut : **ACTIVÉ** dans `/mtf/run` (API)
- Par défaut : **DÉSACTIVÉ** dans `mtf:run` (CLI)

## Flux complet

```
┌─────────────────────────────────────────────────────────────┐
│ MtfRunnerService::run()                                      │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
        ┌───────────────────────────────────────┐
        │ Étape 3: syncTables() [OPTIONNEL]     │
        │ - Synchronise positions/ordres         │
        │ - Écrit en BDD (positions, futures_order) │
        └───────────────────────────────────────┘
                            │
                            ▼
        ┌───────────────────────────────────────┐
        │ Étape 4: filterSymbolsWith...() [DÉBUT] │
        │ - Lit positions ouvertes depuis exchange │
        │ - Lit ordres ouverts depuis exchange    │
        │ - Exclut les symboles avec activité     │
        │ - Met à jour les switches               │
        └───────────────────────────────────────┘
                            │
                            ▼
        ┌───────────────────────────────────────┐
        │ Étape 5-8: Exécution MTF               │
        │ - Validation des conditions            │
        │ - Décision de trading                  │
        │ - Placement d'ordres (si dry-run=false)│
        └───────────────────────────────────────┘
                            │
                            ▼
        ┌───────────────────────────────────────┐
        │ Étape 9: processTpSlRecalculation()    │
        │         [FIN]                          │
        │ - Lit positions ouvertes depuis exchange│
        │ - Recalcule TP/SL pour chaque position │
        │ - Met à jour les ordres TP/SL          │
        └───────────────────────────────────────┘
```

## Différences entre `/mtf/run` (API) et `mtf:run` (CLI)

### `/mtf/run` (API - MtfController)
- `syncTables: true` par défaut
- `processTpSl: true` par défaut
- Utilise `MtfRunnerService` qui gère tout le flux

### `mtf:run` (CLI - MtfRunCommand)
- `syncTables: false` par défaut (option `--sync-contracts` pour forcer)
- `processTpSl: true` par défaut
- Peut utiliser soit `MtfRunnerService` (parallèle) soit `MtfRunService` (séquentiel)

## Positions fermées

Les **positions fermées** ne sont **pas utilisées directement** dans le flux MTF, mais :

1. **Détection automatique** : `TradingStateSyncRunner` détecte les positions fermées lors de la synchronisation
2. **Événements** : `PositionClosedEvent` est dispatché automatiquement
3. **Logging** : `TradeLifecycleLoggerListener` enregistre la fermeture en DB

## Recommandations

### Pour intégrer TradingStateSyncRunner

1. **Au début** : Appeler `TradingStateSyncRunner::syncAndDispatch()` AVANT le filtrage
   - Synchronise l'état depuis l'exchange
   - Dispatche les événements (PositionOpenedEvent, PositionClosedEvent)
   - Le filtrage utilise ensuite les données synchronisées

2. **À la fin** : Appeler `TradingStateSyncRunner::syncAndDispatch()` APRÈS l'exécution MTF
   - Capture les nouvelles positions créées par MTF
   - Détecte les positions fermées pendant l'exécution
   - Dispatche les événements correspondants

### Exemple d'intégration

```php
// Dans MtfRunnerService::run()

// 1. AU DÉBUT (après syncTables, avant filtrage)
if ($request->syncTables) {
    $this->syncTables($context);
    
    // NOUVEAU : Synchroniser avec le bus d'événements
    $this->tradingStateSyncRunner->syncAndDispatch('mtf_run_start', $symbols);
}

// 2. Filtrage (utilise les données synchronisées)
$symbols = $this->filterSymbolsWithOpenOrdersOrPositions(...);

// 3. Exécution MTF
$result = $this->runSequential(...);

// 4. À LA FIN (après processTpSl)
if ($request->processTpSl) {
    $this->processTpSlRecalculation(...);
    
    // NOUVEAU : Synchroniser avec le bus d'événements
    $this->tradingStateSyncRunner->syncAndDispatch('mtf_run_end', $symbols);
}
```

## Résumé

| Moment | Utilisation | Source | Objectif |
|--------|-------------|--------|----------|
| **DÉBUT** | Filtrage symboles | Exchange (API) | Exclure symboles avec positions/ordres ouverts |
| **FIN** | Recalcul TP/SL | Exchange (API) | Mettre à jour les protections des positions existantes |
| **DÉBUT/FIN** | Synchronisation | Exchange → BDD | Maintenir l'état à jour et dispatcher les événements |


