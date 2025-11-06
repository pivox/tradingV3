# Analyse des Problèmes : Positions Touchant SL et Ordres Multiples

**Date:** 2025-01-XX  
**Auteur:** Analyse Automatique  
**Version:** 1.0

## Résumé Exécutif

Deux problèmes critiques ont été identifiés dans le système de trading :
1. **Toutes les positions touchent le stop-loss** : Les SL sont trop serrés et déclenchés par le bruit du marché
2. **Ordres envoyés en rafale** : Plusieurs positions peuvent être ouvertes simultanément sur le même symbole

---

## PROBLÈME 1 : Positions Touchant le Stop-Loss

### Symptômes Observés
- Toutes les positions longues se ferment à perte avec des valeurs négatives (ex: -1.41 USDT, -1.56 USDT, -0.94 USDT)
- Les positions se ferment très rapidement après l'ouverture
- Les pourcentages de perte sont cohérents (6-10%), suggérant des SL déclenchés rapidement

### Causes Racines Identifiées

#### 1.1 Buffer Pivot Trop Petit (CRITIQUE)

**Fichier:** `config/app/mtf_validations.yaml` ligne 28  
**Valeur actuelle:** `pivot_sl_buffer_pct: 0.0015` (0.15%)

**Code concerné:** `src/TradeEntry/RiskSizer/StopLossCalculator.php` lignes 104-105

```php
// Pour un Long: stop = pivot * (1 - 0.0015) = pivot * 0.9985
$stop = $pivot * (1 - abs($bufferPct ?? 0.0));
```

**Problème:**
- Un buffer de 0.15% est **insuffisant** pour absorber le bruit du marché
- Sur les timeframes courts (1m, 5m), la volatilité peut facilement dépasser 0.15%
- Le prix peut osciller de ±0.2-0.5% normalement, déclenchant le SL prématurément

**Impact:**
- Si le pivot S1 est à 19.800 et l'entrée à 19.850, le SL sera à `19.800 * 0.9985 = 19.770`
- Distance SL: `(19.850 - 19.770) / 19.850 = 0.40%` → **TROP SERRÉ**

#### 1.2 Priorité Pivot Sans Vérification de Distance Minimale

**Fichier:** `src/TradeEntry/OrderPlan/OrderPlanBuilder.php` lignes 293-297

```php
$stop = match (true) {
    $stopPivot !== null => $stopPivot,  // PRIORITÉ ABSOLUE au pivot
    $stopAtr !== null => $this->slc->conservative($req->side, $stopAtr, $stopRisk),
    default => $stopRisk,
};
```

**Problème:**
- Le pivot est utilisé **en priorité** même s'il est très proche de l'entrée
- La garde `MIN_STOP_DISTANCE_PCT` (0.5%) est appliquée **APRÈS** le calcul du pivot
- Si le pivot est à 0.3% de l'entrée, le système ajuste après, mais le pivot peut être trop serré dès le départ

**Séquence problématique:**
1. Pivot S1 calculé à 0.3% de l'entrée (trop serré)
2. Buffer de 0.15% appliqué → SL encore plus serré
3. Garde minimale de 0.5% détecte le problème et ajuste
4. Mais le SL final peut encore être trop serré si le pivot était très proche

#### 1.3 Ratio Minimum de Conservation Insuffisant

**Fichier:** `config/app/mtf_validations.yaml` ligne 29  
**Valeur actuelle:** `pivot_sl_min_keep_ratio: 0.8` (80% de la distance ATR)

**Code concerné:** `src/TradeEntry/OrderPlan/OrderPlanBuilder.php` lignes 243-287

**Problème:**
- Si l'ATR est faible (ex: 0.2%), 80% = 0.16% → **insuffisant**
- Le ratio protège contre des SL trop serrés par rapport à l'ATR, mais n'empêche pas les SL trop serrés absolument

**Exemple:**
- ATR = 0.2% du prix
- Distance pivot = 0.25% de l'entrée
- Distance ATR = 0.3% (k=1.5)
- Min distance = 0.8 * 0.3% = 0.24%
- **Pivot à 0.25% > 0.24% → Accepté, mais toujours trop serré !**

#### 1.4 Pas de Vérification de Volatilité Minimale

**Problème:**
- Le système utilise les pivots même en période de **faible volatilité**
- En faible volatilité, le bruit relatif est plus important
- Un SL basé sur pivot peut être déclenché par des oscillations normales

**Absence de code:**
- Aucune vérification de `atr_rel` minimum avant d'utiliser les pivots
- Pas de fallback vers ATR si volatilité trop faible

---

## PROBLÈME 2 : Ordres Envoyés en Rafale

### Symptômes Observés
- Plusieurs ordres pour le même symbole envoyés dans les secondes/minutes
- Exemples: 3 ordres ZENUSDT à 22:01:35, 2 ordres ICPUSDT à 22:01:18
- Plusieurs positions ouvertes simultanément sur le même symbole

### Causes Racines Identifiées

#### 2.1 MtfSwitch Désactivé APRÈS l'Envoi (CRITIQUE)

**Fichier:** `src/MtfValidator/Service/TradingDecisionHandler.php` lignes 120-136

```php
// L'ordre est envoyé d'abord
$execution = $mtfRunDto->dryRun
    ? $this->tradeEntryService->buildAndSimulate($tradeRequest, $decisionKey)
    : $this->tradeEntryService->buildAndExecute($tradeRequest, $decisionKey);

// Puis le switch est désactivé APRÈS
if (!$mtfRunDto->dryRun && ($execution->status === 'submitted')) {
    $this->mtfSwitchRepository->turnOffSymbolFor15Minutes($symbolResult->symbol);
}
```

**Problème:**
- **Fenêtre de concurrence critique** entre l'envoi de l'ordre et la désactivation du switch
- Si plusieurs cycles MTF se déclenchent en parallèle, ils peuvent tous passer la validation avant qu'un seul ne désactive le switch
- Le switch est un mécanisme de **prévention**, mais il est appliqué **après coup**

**Séquence problématique:**
```
T0: Cycle MTF 1 → Validation READY → canExecuteTrading() = true
T1: Cycle MTF 2 → Validation READY → canExecuteTrading() = true (switch encore ON)
T2: Cycle MTF 1 → Envoi ordre → switch OFF
T3: Cycle MTF 2 → Envoi ordre (switch déjà vérifié) → ordre envoyé !
```

#### 2.2 Absence de Vérification de Position Existante

**Fichier:** `src/MtfValidator/Service/TradingDecisionHandler.php` méthode `canExecuteTrading()` lignes 209-264

**Problème:**
- Aucune vérification de position existante via `PositionRepository::findOneBySymbolSide()`
- Le système peut ouvrir plusieurs positions si elles passent toutes la validation MTF

**Code manquant:**
```php
// DEVRAIT EXISTER mais absent:
$existingPosition = $this->positionRepository->findOneBySymbolSide(
    $symbolResult->symbol,
    $symbolResult->signalSide
);
if ($existingPosition !== null && $existingPosition->getStatus() === 'OPEN') {
    return false; // Position déjà ouverte
}
```

**Contrainte DB:**
- La table `positions` a une contrainte unique `ux_positions_symbol_side` (ligne 146 de migration)
- Mais cette contrainte ne **prévient pas** les insertions simultanées, elle génère une erreur après coup
- Entre la validation et l'insertion, plusieurs ordres peuvent être envoyés

#### 2.3 Lock Global Mais Pas de Protection par Symbole

**Fichier:** `src/MtfValidator/Service/Runner/MtfRunOrchestrator.php` lignes 233-239

```php
private function determineLockKey(MtfRunDto $mtfRunDto): string
{
    if ($mtfRunDto->lockPerSymbol && count($mtfRunDto->symbols) === 1) {
        return 'mtf_execution:' . strtoupper($mtfRunDto->symbols[0]);
    }
    return 'mtf_execution';  // Lock GLOBAL
}
```

**Problème:**
- Le lock est **global** sauf si un seul symbole est traité ET `lockPerSymbol=true`
- Si plusieurs symboles sont traités ou si plusieurs runs sont lancés, le lock global n'empêche pas les doublons pour un même symbole
- Le lock protège contre les exécutions simultanées, mais pas contre les exécutions **séquentielles rapides** sur le même symbole

**Scénario problématique:**
```
Run 1: Symboles [ZENUSDT, ICPUSDT] → Lock global acquis
Run 2: Symboles [ZENUSDT] → Lock global bloqué → attend
Run 1: Traite ZENUSDT → ordre envoyé → switch OFF
Run 1: Traite ICPUSDT → ordre envoyé
Run 1: Release lock
Run 2: Lock acquis → Traite ZENUSDT (switch peut être expiré ou réactivé) → ordre envoyé !
```

#### 2.4 Pas de Vérification de Position dans PreTradeChecks

**Fichier:** `src/TradeEntry/Policy/PreTradeChecks.php`

**Problème:**
- `PreTradeChecks::run()` vérifie le spread, le balance, les contrats, mais **pas les positions existantes**
- Cette vérification devrait être faite **avant** de construire le plan d'ordre

---

## Impact des Problèmes

### Impact Problème 1 (SL Trop Serrés)
- **Perte financière:** Toutes les positions se ferment à perte systématiquement
- **Taux de réussite:** Proche de 0% (toutes les positions touchent le SL)
- **Confiance système:** Le système est inefficace et génère des pertes

### Impact Problème 2 (Ordres Multiples)
- **Surachat:** Risque multiplié sur le même symbole
- **Gestion de risque:** Le système ne respecte pas `max_concurrent_positions` efficacement
- **Performance:** Gaspillage de capital et de commissions

---

## Recommandations de Correction

### Pour Problème 1 (SL)

1. **Augmenter le buffer pivot** : `pivot_sl_buffer_pct: 0.0015 → 0.003` (0.3%)
2. **Ajouter garde minimale absolue** : Forcer 0.5% minimum même si pivot plus proche
3. **Vérifier volatilité** : N'utiliser pivots que si `atr_rel >= 0.002` (0.2%)
4. **Logger les distances** : Ajouter logs détaillés pour diagnostic

### Pour Problème 2 (Ordres Multiples)

1. **Désactiver switch AVANT** : Désactiver le MtfSwitch **avant** l'envoi de l'ordre
2. **Vérifier position existante** : Ajouter check dans `canExecuteTrading()`
3. **Lock par symbole** : Utiliser lock par symbole systématiquement
4. **Vérifier dans PreTradeChecks** : Ajouter check de position dans preflight

---

## Fichiers à Modifier

### Problème 1
- [ ] `config/app/mtf_validations.yaml` → Augmenter `pivot_sl_buffer_pct`
- [ ] `src/TradeEntry/OrderPlan/OrderPlanBuilder.php` → Ajouter vérification volatilité
- [ ] `src/TradeEntry/RiskSizer/StopLossCalculator.php` → Améliorer logique pivot

### Problème 2
- [ ] `src/MtfValidator/Service/TradingDecisionHandler.php` → Désactiver switch AVANT + vérifier position
- [ ] `src/TradeEntry/Policy/PreTradeChecks.php` → Ajouter vérification position
- [ ] `src/MtfValidator/Service/Runner/MtfRunOrchestrator.php` → Lock par symbole
- [ ] `src/Repository/PositionRepository.php` → Ajouter méthode `hasOpenPosition()`

---

## Métriques de Succès

### Problème 1
- ✅ Moins de 10% des positions touchent le SL dans les premières minutes
- ✅ Distance SL moyenne >= 0.5% de l'entrée
- ✅ Logs montrent des ajustements de SL quand nécessaire

### Problème 2
- ✅ Aucun ordre multiple sur le même symbole dans une fenêtre de 5 minutes
- ✅ Logs montrent des blocks de positions existantes
- ✅ Switch désactivé avant l'envoi dans 100% des cas

---

## Notes Techniques

### Contrainte DB Unique
La contrainte `ux_positions_symbol_side` empêche les doublons en base, mais :
- Les erreurs de violation sont **après coup**
- Plusieurs ordres peuvent être envoyés à l'exchange avant l'erreur
- Le système doit **prévenir** plutôt que **guérir**

### Race Conditions
Les problèmes identifiés sont des **race conditions classiques** :
- **Time-of-check to time-of-use (TOCTOU)**
- **Check-then-act** non atomique
- Solutions: Locks, vérifications atomiques, désactivation préventive

---

## Conclusion

Les deux problèmes sont **critiques** et nécessitent une correction immédiate :
1. Les SL trop serrés génèrent des pertes systématiques
2. Les ordres multiples multiplient le risque et violent les règles de gestion

Les corrections proposées sont **simples** mais **essentielles** pour la viabilité du système.

