# Corrections de la commande mtf:run

## Problèmes identifiés

1. **Paramètre `--symbols` ignoré** : La commande écrasait les symboles fournis par l'utilisateur avec tous les symboles actifs
2. **Paramètre `--force-run` non pris en compte** : Le paramètre n'était pas passé à travers toute la chaîne d'appels

## Corrections apportées

### 1. MtfRunCommand.php
**Problème** : Ligne 82 écrasait les symboles utilisateur
```php
// AVANT (incorrect)
$symbols = $this->contractRepository->allActiveSymbolNames();

// APRÈS (corrigé)
if (empty($symbols)) {
    $symbols = $this->contractRepository->allActiveSymbolNames();
}
```

### 2. MtfRunService.php
**Problème** : Le paramètre `forceRun` n'était pas passé à `runForSymbol`
```php
// AVANT (incorrect)
$results[$symbol] = $this->mtfService->runForSymbol($runId, $symbol, $now, $currentTf, $forceTimeframeCheck);

// APRÈS (corrigé)
$results[$symbol] = $this->mtfService->runForSymbol($runId, $symbol, $now, $currentTf, $forceTimeframeCheck, $forceRun);
```

### 3. MtfService.php
**Problèmes multiples** :

#### a) Signature de `runForSymbol`
```php
// AVANT
public function runForSymbol(..., bool $forceTimeframeCheck = false): array

// APRÈS
public function runForSymbol(..., bool $forceTimeframeCheck = false, bool $forceRun = false): array
```

#### b) Signature de `processSymbol`
```php
// AVANT
private function processSymbol(..., bool $force = false): array

// APRÈS
private function processSymbol(..., bool $forceTimeframeCheck = false, bool $forceRun = false): array
```

#### c) Signature de `processTimeframe`
```php
// AVANT
private function processTimeframe(..., bool $force = false): array

// APRÈS
private function processTimeframe(..., bool $forceTimeframeCheck = false, bool $forceRun = false): array
```

#### d) Utilisation de `forceRun` pour les kill switches
```php
// Kill switch du symbole
if (!$forceRun && !$this->mtfSwitchRepository->canProcessSymbol($symbol)) {
    // Skip si kill switch OFF (sauf si force-run)
}

// Kill switch du timeframe
if (!$forceRun && !$this->mtfSwitchRepository->canProcessSymbolTimeframe($symbol, $timeframe->value)) {
    // Skip si kill switch OFF (sauf si force-run)
}
```

#### e) Correction des appels à `processTimeframe`
Tous les appels ont été mis à jour pour passer les bons paramètres :
```php
$this->processTimeframe($symbol, $timeframe, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
```

## Test de la correction

### Commande à tester
```bash
docker-compose exec trading-app-php php bin/console mtf:run --symbols=DOTUSDT --force-run
```

### Résultats attendus
- ✅ Seul le symbole `DOTUSDT` devrait être traité
- ✅ Les kill switches devraient être contournés
- ✅ Le paramètre `force-run` devrait être respecté

### Vérifications
1. **Symboles** : Vérifier que seul `DOTUSDT` apparaît dans les logs
2. **Force-run** : Vérifier que les kill switches sont contournés
3. **Logs** : Vérifier que `force_run: true` apparaît dans les logs

## Impact

Ces corrections permettent :
- De traiter un symbole spécifique avec `--symbols`
- De contourner les kill switches avec `--force-run`
- De respecter les paramètres fournis par l'utilisateur
- De maintenir la compatibilité avec l'usage existant

## Compatibilité

- ✅ **Rétrocompatible** : Les commandes existantes continuent de fonctionner
- ✅ **Optionnel** : Les nouveaux paramètres ont des valeurs par défaut
- ✅ **Flexible** : Permet l'usage avec ou sans paramètres spécifiques


