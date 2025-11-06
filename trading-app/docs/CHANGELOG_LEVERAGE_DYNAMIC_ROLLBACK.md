# Changelog - Calcul Dynamique du Levier basé sur Stop Loss

**Date:** 2025-01-XX  
**Feature:** Implémentation du calcul dynamique du levier basé sur la distance du Stop Loss  
**Status:** ✅ Implémenté

**Note:** Distance minimale SL maintenue à 0.5% (tentative d'augmentation à 1.0% annulée)

## Résumé

Remplacement du calcul de levier statique (`notional / budget`) par un calcul dynamique basé sur la distance du Stop Loss (`risk_pct / stop_pct`).

### Problème résolu

- Le levier était calculé sans tenir compte de la distance du SL
- SL trop serré (0.5%) avec levier élevé (10X) = déclenchements prématurés fréquents
- Perte observée sur ICPUSDT, 1000RATSUSDT, BNBUSDT

### Solution

- Levier dynamique : `leverage = risk_usdt / (stop_pct * budget_usdt)`
- Cap dynamique : `min(levMax, kDynamic / stop_pct)`
- Levier s'adapte automatiquement : SL serré → levier réduit, SL large → levier plus élevé

---

## Fichiers créés

### `trading-app/src/TradeEntry/Service/Leverage/DynamicLeverageService.php` [NEW]

**Contenu:** Implémentation du calcul dynamique du levier

**Fonctionnalités:**
- Lit `kDynamic` et `risk_pct_percent` depuis `mtf_validations.yaml`
- Calcule `leverage_base = risk_usdt / (stop_pct * budget_usdt)`
- Applique cap dynamique : `min(levMax, kDynamic / stop_pct)`
- Lance exception si `stopPct` manquant (pas de fallback)
- Logs détaillés pour traçabilité

**Pour rollback:** Supprimer ce fichier

---

## Fichiers modifiés

### `trading-app/src/Contract/EntryTrade/LeverageServiceInterface.php` [EDIT]

**Changement:**
```php
// AVANT
public function computeLeverage(
    string $symbol,
    float $entryPrice,
    float $contractSize,
    int $positionSize,
    float $budgetUsdt,
    float $availableUsdt,
    int $minLeverage,
    int $maxLeverage
): int;

// APRÈS
public function computeLeverage(
    string $symbol,
    float $entryPrice,
    float $contractSize,
    int $positionSize,
    float $budgetUsdt,
    float $availableUsdt,
    int $minLeverage,
    int $maxLeverage,
    ?float $stopPct = null  // ← NOUVEAU PARAMÈTRE
): int;
```

**Pour rollback:** Retirer le paramètre `?float $stopPct = null`

---

### `trading-app/src/TradeEntry/OrderPlan/OrderPlanBuilder.php` [EDIT]

**Note:** Distance minimale SL maintenue à 0.5% (0.005) - aucun changement effectué

---

**Changements initiaux (calcul dynamique du levier):**

**Changements:**

1. **Calcul de `stopPct`** (ligne ~554-555):
```php
// NOUVEAU CODE AJOUTÉ
// Calculer stopPct pour le calcul dynamique du levier
$stopPct = abs($stop - $entry) / max($entry, 1e-9);
```

2. **Ajout de `stop_pct` dans les logs** (ligne ~567):
```php
'stop_pct' => $stopPct,  // ← NOUVEAU
```

3. **Passage de `stopPct` au service** (ligne ~599):
```php
$leverage = $this->leverageService->computeLeverage(
    $req->symbol,
    $entry,
    $contractSize,
    $sizeContracts,
    $req->initialMarginUsdt,
    $pre->availableUsdt,
    $pre->minLeverage,
    $pre->maxLeverage,
    $stopPct  // ← NOUVEAU PARAMÈTRE
);
```

**Pour rollback:**
- Supprimer le calcul de `stopPct` (lignes ~554-555)
- Retirer `'stop_pct' => $stopPct,` des logs
- Retirer `$stopPct` de l'appel à `computeLeverage()`

---

### `trading-app/config/services.yaml` [EDIT]

**Changement** (ligne ~229):
```yaml
# NOUVEAU CODE AJOUTÉ
# Leverage Service - Dynamic calculation based on stop loss distance
App\Contract\EntryTrade\LeverageServiceInterface: '@App\TradeEntry\Service\Leverage\DynamicLeverageService'
```

**Pour rollback:** Supprimer ces 2 lignes (ou remplacer par `DefaultLeverageService` si on recrée ce service)

---

### `trading-app/src/TradeEntry/README.md` [EDIT]

**Changement** (ligne ~45):
```markdown
# AVANT
│   ├── Leverage/DefaultLeverageService.php

# APRÈS
│   ├── Leverage/DynamicLeverageService.php
```

**Pour rollback:** Remplacer `DynamicLeverageService` par `DefaultLeverageService`

---

## Fichiers supprimés

### `trading-app/src/TradeEntry/Service/Leverage/DefaultLeverageService.php` [DEL]

**Contenu supprimé:** Ancien service avec calcul statique `leverage = ceil(notional / budget)`

**Pour rollback:** Recréer le fichier avec le contenu suivant:

```php
<?php
declare(strict_types=1);

namespace App\TradeEntry\Service\Leverage;

use App\Contract\EntryTrade\LeverageServiceInterface;

final class DefaultLeverageService implements LeverageServiceInterface
{
    public function __construct() {}

    public function computeLeverage(
        string $symbol,
        float $entryPrice,
        float $contractSize,
        int $positionSize,
        float $budgetUsdt,
        float $availableUsdt,
        int $minLeverage,
        int $maxLeverage,
        ?float $stopPct = null  // Paramètre optionnel pour compatibilité
    ): int {
        $effectiveBudget = min(max($budgetUsdt, 0.0), max($availableUsdt, 0.0));
        if ($effectiveBudget <= 0.0) {
            throw new \RuntimeException('Budget indisponible pour calculer le levier');
        }

        $notional = $entryPrice * $contractSize * $positionSize;
        if ($notional <= 0.0) {
            return max(1, $minLeverage);
        }

        $raw = (int)ceil($notional / max($effectiveBudget, 1e-9));
        $clamped = max(1, max($minLeverage, $raw));

        return (int)min($maxLeverage, $clamped);
    }
}
```

---

## Procédure de rollback complète

### Option 1: Rollback complet vers l'ancien système

1. **Recréer `DefaultLeverageService.php`** (voir contenu ci-dessus)

2. **Modifier `services.yaml`**:
```yaml
# Remplacer
App\Contract\EntryTrade\LeverageServiceInterface: '@App\TradeEntry\Service\Leverage\DefaultLeverageService'
```

3. **Modifier `LeverageServiceInterface.php`**:
   - Retirer `?float $stopPct = null` de la signature

4. **Modifier `OrderPlanBuilder.php`**:
   - Supprimer le calcul de `stopPct` (lignes ~554-555)
   - Retirer `'stop_pct' => $stopPct,` des logs
   - Retirer `$stopPct` de l'appel à `computeLeverage()`

5. **Supprimer `DynamicLeverageService.php`**

6. **Mettre à jour `README.md`**:
   - Remplacer `DynamicLeverageService` par `DefaultLeverageService`

### Option 2: Rollback partiel (garder l'interface mais utiliser ancien calcul)

1. Garder `LeverageServiceInterface` avec `stopPct` (pour compatibilité future)
2. Recréer `DefaultLeverageService` qui ignore `stopPct`
3. Modifier `services.yaml` pour pointer vers `DefaultLeverageService`
4. Modifier `OrderPlanBuilder` pour ne plus passer `stopPct` (ou passer `null`)

---

## Impact des changements

### Avant (ancien système)
- Levier = `ceil(notional / budget)` = 10X pour BNBUSDT
- Indépendant de la distance SL
- SL à 0.5% avec 10X = risque élevé

### Après (nouveau système)
- Levier = `risk_usdt / (stop_pct * budget_usdt)`
- S'adapte à la distance SL
- SL à 0.5% → 10X (pas d'amélioration si SL reste serré)
- SL à 1.0% → 5X (réduction du risque)

### Note sur la distance minimale SL
- **Distance minimale SL maintenue à 0.5%** dans `OrderPlanBuilder.php`
- Tentative d'augmentation à 1.0% testée puis annulée (rollback effectué)
- Le mécanisme de levier dynamique fonctionne avec le minimum de 0.5%

---

## Tests de validation

### Test 1: SL à 0.5%
- Input: `stopPct = 0.005`, `budget = 20 USDT`, `riskPct = 5%`
- Expected: `leverage = 10X` (comme avant, pas d'amélioration)

### Test 2: SL à 1.0%
- Input: `stopPct = 0.01`, `budget = 20 USDT`, `riskPct = 5%`
- Expected: `leverage = 5X` (réduction du risque)

### Test 3: SL à 2.0%
- Input: `stopPct = 0.02`, `budget = 20 USDT`, `riskPct = 5%`
- Expected: `leverage = 2.5X` → `ceil(2.5) = 3X` (levier minimal)

---

## Configuration requise

### `config/app/mtf_validations.yaml`
```yaml
defaults:
    k_dynamic: 10.0          # Utilisé pour le cap dynamique
    risk_pct_percent: 5.0    # Utilisé pour calculer riskUsdt
    lev_min: 2.0              # Levier minimum
    lev_max: 20.0             # Levier maximum
```

---

## Logs à surveiller

### Logs de debug
- `order_plan.leverage.dynamic` : Détails du calcul (leverage_base, dyn_cap, leverage_final)

### Logs d'erreur
- `order_plan.leverage.missing_stop_pct` : Si `stopPct` est manquant ou invalide

---

## Notes importantes

1. **Pas de fallback** : Si `stopPct` est manquant, une exception est levée (comportement voulu)
2. **Ordre d'exécution** : Le SL doit être calculé AVANT le levier (déjà le cas dans `OrderPlanBuilder`)
3. **Compatibilité** : L'interface accepte `stopPct` optionnel pour rétrocompatibilité, mais `DynamicLeverageService` le requiert

---

## Références

- Issue originale : Analyse des pertes sur ICPUSDT, 1000RATSUSDT, BNBUSDT
- Configuration : `trading.yml` ligne 28 : `mode: dynamic_from_risk` (maintenant implémenté)
- Formule théorique : `trading-app/src/TradeEntry/todo.txt` lignes 252-265

