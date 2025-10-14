# Corrections Force-Run pour MTF

## Problème
Même avec `--force-run`, la commande `mtf:run` skippe tous les symboles.

## Cause Racine
Le paramètre `forceRun` ne contournait pas toutes les conditions de skip :
- ❌ Fenêtre de grâce
- ❌ Klines trop récentes  
- ✅ Kill switches des symboles
- ✅ Kill switches des timeframes

## Corrections Apportées

### 1. Fenêtre de Grâce
**Avant** : Toujours respectée, même avec `forceRun`
```php
if ($this->timeService->isInGraceWindow($now, $timeframe)) {
    return ['status' => 'GRACE_WINDOW', 'reason' => "In grace window"];
}
```

**Après** : Contournée avec `forceRun`
```php
if (!$forceRun && $this->timeService->isInGraceWindow($now, $timeframe)) {
    return ['status' => 'GRACE_WINDOW', 'reason' => "In grace window"];
}
```

### 2. Klines Trop Récentes
**Avant** : Seulement contournée avec `forceTimeframeCheck`
```php
if (!$forceTimeframeCheck) {
    // Vérification des klines récentes
}
```

**Après** : Contournée avec `forceRun` OU `forceTimeframeCheck`
```php
if (!$forceTimeframeCheck && !$forceRun) {
    // Vérification des klines récentes
}
```

### 3. Kill Switches des Timeframes
**Avant** : Déjà corrigé dans la version précédente
**Après** : Maintenant contournés avec `forceRun`

## Conditions qui Peuvent Encore Faire Skip

Même avec `--force-run`, ces conditions peuvent encore faire skip :

1. **Pas de klines disponibles** (`BACKFILL_NEEDED`)
   - Solution : Remplir les klines avec backfill

2. **Erreurs de validation des signaux**
   - Solution : Vérifier la configuration des signaux

3. **Erreurs techniques**
   - API indisponible
   - Base de données inaccessible
   - Configuration manquante

## Test de la Correction

### Commande de Test
```bash
docker-compose exec trading-app-php php bin/console mtf:run --force-run
```

### Résultats Attendus
- ✅ Kill switches contournés
- ✅ Fenêtre de grâce contournée
- ✅ Klines récentes ignorées
- ✅ Symboles traités (sauf erreurs techniques)

### Diagnostic si Ça Skip Encore
```bash
# 1. Vérifier les kill switches
docker-compose exec trading-app-php php bin/console mtf:switches

# 2. Vérifier les contrats
docker-compose exec trading-app-php php bin/console bitmart:fetch-contracts

# 3. Vérifier les klines
docker-compose exec trading-app-php php bin/console bitmart:check-klines --symbol=BTCUSDT --timeframe=4h --limit=5

# 4. Test avec symbole spécifique
docker-compose exec trading-app-php php bin/console mtf:run --symbols=BTCUSDT --force-run --dry-run=1
```

## Impact

Ces corrections permettent à `--force-run` de :
- Contourner la fenêtre de grâce
- Ignorer les klines trop récentes
- Contourner tous les kill switches
- Forcer le traitement même en conditions non optimales

## Compatibilité

- ✅ **Rétrocompatible** : Comportement normal inchangé
- ✅ **Optionnel** : `--force-run` reste un paramètre optionnel
- ✅ **Sécurisé** : N'affecte que les exécutions avec `--force-run`


