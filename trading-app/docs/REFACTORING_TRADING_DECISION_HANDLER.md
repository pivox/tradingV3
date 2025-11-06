# Refactorisation de TradingDecisionHandler

**Date**: 2025-01-27  
**Objectif**: Déléguer les responsabilités à `TradeEntryService` et créer des composants réutilisables.

---

## 📋 Résumé des Changements

### Nouveaux Composants Créés

1. **`TradeEntryRequestBuilder`** (`src/TradeEntry/Builder/TradeEntryRequestBuilder.php`)
   - **Responsabilité**: Construction de `TradeEntryRequest` depuis un `SymbolResultDto` MTF
   - **Méthode**: `fromMtfSignal(SymbolResultDto, ?float $price, ?float $atr): ?TradeEntryRequest`
   - **Avantages**: Réutilisable, testable indépendamment, réduit la taille de `TradingDecisionHandler`

2. **`PostExecutionHookInterface`** (`src/TradeEntry/Hook/PostExecutionHookInterface.php`)
   - **Responsabilité**: Interface pour les hooks post-exécution
   - **Méthodes**: `onSubmitted()`, `onSimulated()`
   - **Avantages**: Extensible, permet d'ajouter des comportements sans modifier `TradeEntryService`

3. **`MtfPostExecutionHook`** (`src/TradeEntry/Hook/MtfPostExecutionHook.php`)
   - **Responsabilité**: Hook spécifique MTF pour gérer switches et audit
   - **Fonctionnalités**:
     - Désactive le symbole 15 minutes après soumission (live uniquement)
     - Enregistre l'audit (`TRADE_ENTRY_EXECUTED` / `TRADE_ENTRY_SIMULATED`)
   - **Avantages**: Centralise la logique post-exécution, réutilisable

### Modifications

4. **`TradeEntryService`** (`src/TradeEntry/Service/TradeEntryService.php`)
   - **Changements**:
     - `buildAndExecute()` accepte maintenant un `?PostExecutionHookInterface $hook = null`
     - `buildAndSimulate()` accepte maintenant un `?PostExecutionHookInterface $hook = null`
     - Appelle `$hook->onSubmitted()` ou `$hook->onSimulated()` si fourni
   - **Avantages**: Extensible sans breaking changes (hook optionnel)

5. **`TradingDecisionHandler`** (`src/MtfValidator/Service/TradingDecisionHandler.php`)
   - **Refactorisation**:
     - ✅ **Garde**: Validation MTF spécifique (`canExecuteMtfTrading()`)
     - ✅ **Délègue**: Construction de `TradeEntryRequest` → `TradeEntryRequestBuilder`
     - ✅ **Délègue**: Post-exécution (switches, audit) → `MtfPostExecutionHook`
     - ✅ **Garde**: Retour `SymbolResultDto` pour l'orchestrator
   - **Réduction**: ~200 lignes → ~150 lignes (méthode `buildTradeEntryRequest` supprimée)

---

## 🔄 Nouveau Flux

### Avant
```
TradingDecisionHandler
  ├─ canExecuteTrading() [validation]
  ├─ buildTradeEntryRequest() [construction - 120 lignes]
  ├─ tradeEntryService->buildAndExecute()
  ├─ Gestion switches [post-exécution]
  └─ Audit [post-exécution]
```

### Après
```
TradingDecisionHandler
  ├─ canExecuteMtfTrading() [validation MTF spécifique]
  ├─ requestBuilder->fromMtfSignal() [délégation]
  ├─ tradeEntryService->buildAndExecute(..., hook) [délégation]
  │   └─ hook->onSubmitted() [switches + audit]
  └─ Retour SymbolResultDto [pour orchestrator]
```

---

## 📊 Bénéfices

### 1. Séparation des Responsabilités
- **TradingDecisionHandler**: Orchestration MTF uniquement
- **TradeEntryRequestBuilder**: Transformation MTF → TradeEntry
- **MtfPostExecutionHook**: Post-traitement spécifique MTF
- **TradeEntryService**: Exécution générique (réutilisable par d'autres sources)

### 2. Réutilisabilité
- `TradeEntryRequestBuilder` peut être utilisé par d'autres callers (API, CLI, etc.)
- `PostExecutionHookInterface` permet d'ajouter d'autres hooks (notifications, métriques, etc.)

### 3. Testabilité
- Chaque composant peut être testé indépendamment
- Mocks plus simples (hook vs service complet)

### 4. Maintenabilité
- Code plus court et focalisé
- Responsabilités claires
- Moins de duplication

---

## 🔧 Utilisation

### Depuis TradingDecisionHandler (existant)
```php
// 1. Validation MTF spécifique
if (!$this->canExecuteMtfTrading($symbolResult, $decisionKey)) {
    return $this->createSkippedResult(...);
}

// 2. Construction via Builder
$tradeRequest = $this->requestBuilder->fromMtfSignal(
    $symbolResult,
    $symbolResult->currentPrice,
    $symbolResult->atr
);

// 3. Exécution avec hook
$hook = new MtfPostExecutionHook(
    $this->mtfSwitchRepository,
    $this->auditLogger,
    $mtfRunDto->dryRun,
    $this->logger,
    $this->orderJourneyLogger,
);

$execution = $this->tradeEntryService->buildAndExecute(
    $tradeRequest,
    $decisionKey,
    $hook
);
```

### Depuis un autre caller (exemple)
```php
// Utilisation directe du builder
$request = $requestBuilder->fromMtfSignal($symbolResult);

// Exécution sans hook (ou avec hook personnalisé)
$result = $tradeEntryService->buildAndExecute($request);
```

---

## ⚠️ Breaking Changes

**Aucun breaking change** :
- `TradeEntryService::buildAndExecute()` et `buildAndSimulate()` acceptent un hook optionnel
- Les appels existants sans hook continuent de fonctionner
- `TradingDecisionHandler` reste compatible avec `MtfRunOrchestrator`

---

## 📝 Notes Techniques

### Hook Pattern
Le pattern hook permet d'ajouter des comportements sans modifier `TradeEntryService` :
- ✅ Extensible (nouveaux hooks possibles)
- ✅ Testable (hook mockable)
- ✅ Optionnel (backward compatible)

### Builder Pattern
Le builder centralise la logique de construction :
- ✅ Réutilisable
- ✅ Testable
- ✅ Évolutif (peut gérer d'autres sources que MTF)

---

## ✅ Checklist

- [x] Créer `TradeEntryRequestBuilder`
- [x] Créer `PostExecutionHookInterface`
- [x] Créer `MtfPostExecutionHook`
- [x] Modifier `TradeEntryService` pour accepter hook
- [x] Refactoriser `TradingDecisionHandler`
- [x] Supprimer méthode `buildTradeEntryRequest` obsolète
- [x] Vérifier linter (aucune erreur)
- [ ] Tests unitaires (à créer)
- [ ] Tests d'intégration (à vérifier)

---

## 🔄 Prochaines Étapes Possibles

1. **Factory pour le hook** : Créer un `MtfPostExecutionHookFactory` pour éviter l'instanciation manuelle
2. **Validation générique** : Déplacer `require_price_or_atr` dans `TradeEntryService`
3. **Autres hooks** : Créer des hooks pour notifications, métriques, etc.

---

**Généré le**: 2025-01-27  
**Version**: 1.0

