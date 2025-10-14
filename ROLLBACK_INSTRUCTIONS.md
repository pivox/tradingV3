# Instructions de Rollback - MtfService

## 🔄 Comment Revenir à l'Ancienne Logique

Si la nouvelle logique d'insertion en masse pose des problèmes, voici comment revenir à l'ancienne logique de backfill complexe :

### 1. Restaurer l'Ancienne Logique

Dans `trading-app/src/Domain/Mtf/Service/MtfService.php` :

#### A. Remplacer la nouvelle logique (lignes 379-409) par l'ancienne :

```php
// REMPLACER CETTE NOUVELLE LOGIQUE :
if (count($klines) < $limit) {
    $this->logger->info('[MTF] Insufficient klines, filling in bulk', [...]);
    $this->fillMissingKlinesInBulk($symbol, $timeframe, $limit, $now, $runId);
    $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);
    if (count($klines) < $limit) {
        // Désactiver temporairement
    }
}

// PAR CETTE ANCIENNE LOGIQUE :
if (count($klines) < $limit) {
    // Désactiver le symbole pour une durée basée sur le nombre de barres manquantes
    $missingBars = $limit - count($klines);
    $duration = ($missingBars * $timeframe->getStepInMinutes() + $timeframe->getStepInMinutes()) . ' minutes';
    $this->mtfSwitchRepository->turnOffSymbolForDuration($symbol, $duration);
    
    $this->auditStep($runId, $symbol, "{$timeframe->value}_INSUFFICIENT_DATA", "Insufficient bars for {$timeframe->value}", [
        'timeframe' => $timeframe->value,
        'bars_count' => count($klines),
        'min_bars' => $limit,
        'missing_bars' => $missingBars,
        'duration_disabled' => $duration
    ]);
    return ['status' => 'SKIPPED', 'reason' => 'INSUFFICIENT_DATA', 'failed_timeframe' => $timeframe->value];
}
```

#### B. Décommenter l'ancienne logique de backfill (lignes 445-529) :

```php
// REMPLACER :
// 🔄 ANCIENNE LOGIQUE COMMENTÉE POUR ROLLBACK POSSIBLE
/*
// --- Détection et comblement des trous via getMissingKlineChunks ---
...
*/

// PAR :
// --- Détection et comblement des trous via getMissingKlineChunks ---

// Calculer la plage temporelle à analyser
$intervalMinutes = $timeframe->getStepInMinutes();
$startDate = (clone $now)->sub(new \DateInterval('PT' . ($limit * $intervalMinutes) . 'M'));
$endDate = $now;

// Utiliser la fonction PostgreSQL pour détecter les trous
$missingChunks = $this->klineRepository->getMissingKlineChunks(
    $symbol,
    $timeframe->value,
    $startDate,
    $endDate,
    500 // max_per_request
);

if (!empty($missingChunks)) {
    // ... toute la logique de backfill par chunks
}

// --- Fin de la logique de comblement ---
```

#### C. Supprimer la nouvelle méthode `fillMissingKlinesInBulk()` (lignes 307-358)

#### D. Supprimer l'injection de `KlineJsonIngestionService` du constructeur

### 2. Restaurer les Dépendances

Si vous voulez garder l'ancienne logique, vous pouvez aussi :

- Supprimer `KlineJsonIngestionService.php`
- Supprimer l'import dans `MtfService.php`
- Supprimer le paramètre du constructeur

### 3. Tester le Rollback

```bash
# Tester que l'ancienne logique fonctionne
php bin/console mtf:run --symbol=BTCUSDT --dry-run

# Vérifier les logs pour s'assurer que la logique de backfill complexe est active
tail -f var/log/dev.log | grep "GAPS_DETECTED"
```

## 📊 Comparaison des Deux Approches

| Aspect | Nouvelle Logique (JSON) | Ancienne Logique (Backfill) |
|--------|-------------------------|------------------------------|
| **Simplicité** | ✅ Simple (insertion en masse) | ❌ Complexe (détection gaps + chunks) |
| **Performance** | ✅ 1 requête SQL | ❌ N+1 requêtes |
| **Fiabilité** | ✅ Plus prévisible | ❌ Plus de points de défaillance |
| **Maintenance** | ✅ Code plus court | ❌ Code plus long |
| **Précision** | ❌ Moins précise (récupère tout) | ✅ Plus précise (gaps spécifiques) |

## 🚨 Signaux d'Alerte pour Rollback

Si vous observez ces problèmes, considérez un rollback :

1. **Performance dégradée** : Insertions JSON plus lentes que prévu
2. **Erreurs de données** : Klines corrompues ou manquantes
3. **Problèmes de mémoire** : Consommation excessive lors des insertions en masse
4. **Incompatibilité** : Problèmes avec d'autres parties du système

## 🔧 Rollback Rapide

Pour un rollback rapide, utilisez le fichier de backup :

```bash
# Copier le backup vers le fichier principal
cp trading-app/src/Domain/Mtf/Service/MtfService_Backup_Original.php trading-app/src/Domain/Mtf/Service/MtfService.php

# Supprimer les commentaires /* et */ dans le fichier
# Tester le fonctionnement
```

## 📝 Notes Importantes

- **L'ancienne logique est plus précise** pour détecter les gaps spécifiques
- **La nouvelle logique est plus simple** et plus performante
- **Les deux approches sont valides** selon les besoins
- **Le rollback est toujours possible** grâce aux commentaires dans le code

---

**Date de création** : 2025-01-15  
**Statut** : Instructions de rollback prêtes  
**Auteur** : Assistant IA
