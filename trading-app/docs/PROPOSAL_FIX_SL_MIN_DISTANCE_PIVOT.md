# Proposition de Correction : Garde Minimale 0.5% pour SL Basés sur Pivot

## Problème Identifié

### Situation Actuelle

Le calcul du stop-loss basé sur pivot suit cette séquence :

1. **Calcul du SL pivot** (ligne 217-225) : Le SL est calculé depuis les niveaux de pivot
2. **Garde `pivot_sl_min_keep_ratio`** (ligne 243-287) : Si le pivot est trop serré, on ajuste à **au moins 80% de la distance ATR**
3. **Sélection du stop final** (ligne 293-297) : Priorité au `stopPivot` si disponible
4. **Garde minimale de 0.5%** (ligne 334-397) : Appliquée APRÈS la sélection du stop final

### Scénario Problématique

Exemple avec un ATR très faible :

```
Entry = 100 USDT
ATR = 0.3 (0.3% de 100)
ATR avec k=1.5 = 0.45% de distance
pivot_sl_min_keep_ratio = 0.8 (80%)

Distance ATR = 0.45%
Distance minimale garantie par pivot_sl_min_keep_ratio = 0.8 × 0.45% = 0.36%

Si le pivot est très proche du prix d'entrée :
- Le pivot peut être à 0.2% de distance
- La garde pivot_sl_min_keep_ratio ajuste à 0.36% (meilleur que 0.2%)
- MAIS 0.36% < 0.5% (garde minimale absolue)
- Le SL reste à 0.36%, ce qui est trop serré et risque de toucher trop facilement
```

### Impact

- Les positions peuvent toucher le SL trop rapidement en cas de volatilité normale
- Les SL basés sur pivot peuvent être plus serrés que les SL basés sur risk (qui respectent toujours 0.5%)
- Incohérence entre les méthodes de calcul de SL

## Solution Proposée

### Principe

**Appliquer la garde minimale de 0.5% IMMÉDIATEMENT après la garde `pivot_sl_min_keep_ratio`**, avant la sélection du stop final. Cela garantit que TOUS les SL (pivot, ATR, ou risk) respectent toujours le minimum absolu de 0.5%.

### Implémentation

**Emplacement** : `trading-app/src/TradeEntry/OrderPlan/OrderPlanBuilder.php`

**Modification** : Ajouter la vérification de la garde minimale de 0.5% juste après la garde `pivot_sl_min_keep_ratio` (après la ligne 288, avant la ligne 290).

### Code à Ajouter

```php
// Après la ligne 288 (après la garde pivot_sl_min_keep_ratio)
// AVANT la ligne 290 (avant le calcul de size)

// CRITICAL GUARD: Appliquer la garde minimale absolue de 0.5% aussi pour les SL pivot
// Cette garde doit être appliquée AVANT la sélection du stop final pour garantir
// que tous les SL respectent le minimum absolu, indépendamment de la méthode de calcul
if ($stopPivot !== null) {
    $MIN_STOP_DISTANCE_PCT = 0.005; // 0.5% minimum absolu
    $pivotStopDistancePct = abs($entry - $stopPivot) / max($entry, 1e-9);
    
    if ($pivotStopDistancePct < $MIN_STOP_DISTANCE_PCT) {
        $minAbsoluteDistance = max($tick, $MIN_STOP_DISTANCE_PCT * $entry);
        $target = $req->side === Side::Long
            ? max($entry - $minAbsoluteDistance, $tick)
            : $entry + $minAbsoluteDistance;
        $target = $req->side === Side::Long
            ? TickQuantizer::quantize(max($target, $tick), $precision)
            : TickQuantizer::quantizeUp($target, $precision);
        
        if (\is_finite($target) && $target > 0.0 && $target !== $entry) {
            $previousPivotStop = $stopPivot;
            $stopPivot = $target;
            $sizingDistance = max(abs($entry - $stopPivot), $tick);
            
            $this->flowLogger->info('order_plan.pivot_stop_min_absolute_distance_enforced', [
                'symbol' => $req->symbol,
                'side' => $req->side->value,
                'entry' => $entry,
                'pivot_stop_before' => $previousPivotStop,
                'pivot_stop_after' => $stopPivot,
                'distance_pct_before' => round($pivotStopDistancePct * 100, 4),
                'distance_pct_after' => round($MIN_STOP_DISTANCE_PCT * 100, 2),
                'min_absolute_distance_pct' => $MIN_STOP_DISTANCE_PCT * 100,
                'decision_key' => $decisionKey,
            ]);
            $this->journeyLogger->info('order_journey.plan_builder.pivot_stop_min_absolute_distance_enforced', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'entry' => $entry,
                'pivot_stop_before' => $previousPivotStop,
                'pivot_stop_after' => $stopPivot,
                'distance_pct_before' => round($pivotStopDistancePct * 100, 4),
                'distance_pct_after' => round($MIN_STOP_DISTANCE_PCT * 100, 2),
                'reason' => 'pivot_stop_min_absolute_distance_0_5_pct_enforced',
            ]);
        }
    }
}
```

### Avantages

1. **Cohérence** : Tous les SL (pivot, ATR, risk) respectent maintenant le minimum absolu de 0.5%
2. **Protection renforcée** : Les SL basés sur pivot ne peuvent plus être trop serrés
3. **Traçabilité** : Logs dédiés pour identifier quand cette garde est appliquée
4. **Application anticipée** : La garde est appliquée avant le calcul de la taille de position, ce qui permet de réajuster correctement la taille si nécessaire

### Ordre Final des Gardes

1. Calcul du SL pivot
2. Garde `pivot_sl_min_keep_ratio` (80% de l'ATR minimum)
3. **NOUVELLE : Garde minimale absolue de 0.5%** ← AJOUT
4. Sélection du stop final (pivot > ATR > risk)
5. Garde minimale de 0.5% (fallback pour les autres méthodes)

Note : La garde à l'étape 5 reste nécessaire comme fallback pour les SL basés sur ATR ou risk qui ne passeraient pas par les étapes précédentes.

## Tests Recommandés

1. **Test avec ATR faible** : Vérifier qu'un SL pivot est ajusté à 0.5% minimum même si l'ATR suggère moins
2. **Test avec ATR normal** : Vérifier que la garde ne casse pas les SL pivot valides (> 0.5%)
3. **Test avec pivot très proche** : Vérifier que le SL est toujours à au moins 0.5%
4. **Vérification des logs** : S'assurer que les logs `pivot_stop_min_absolute_distance_enforced` apparaissent quand nécessaire

## Impact

- **Risque** : Faible (ajout d'une garde supplémentaire)
- **Rétrocompatibilité** : Oui (améliore la protection sans casser le comportement existant)
- **Performance** : Négligeable (une vérification supplémentaire)

