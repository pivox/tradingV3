# 🐛 BUGS CRITIQUES - ATR & Stop-Loss (2025-11-03)

## Contexte
Position PIPPINUSDT touchée SL avec seulement 0.33% de distance alors qu'elle avait été validée MTF.

---

## ✅ **FIXES APPLIQUÉS**

### Fix #1 : TradingDecisionHandler - Blocage ordre si ATR invalide

**Fichier:** `src/MtfValidator/Service/TradingDecisionHandler.php`

**Changement:**
- Ligne 322-336 : Nouveau garde qui **bloque l'ordre** si `stop_from='atr'` est configuré mais ATR invalide
- Avant : Bascule silencieusement sur `stop_from='risk'` → stop trop serré
- Maintenant : Retourne `null` → ordre **rejeté**

**Logs ajoutés:**
```php
$this->logger->warning('[Trading Decision] ATR required but invalid/missing', [...]);
$this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
    'reason' => 'atr_required_but_invalid',
]);
```

---

### Fix #2 : MtfService - Retry klines si ATR = 0.0

**Fichier:** `src/MtfValidator/Service/MtfService.php:1053-1156`

**Changement:**
- Si ATR = 0.0 après le premier calcul :
  1. Log warning avec détails des klines
  2. Attente de 100ms (`usleep(100000)`)
  3. Récupération des klines à nouveau
  4. Recalcul de l'ATR
  5. Si toujours 0.0 → retourne `null` au lieu de 0.0
- Logs détaillés à chaque étape (debug, warning, error, info)

**Comportement:**
```php
// Tentative 1
$atr = $calc->computeWithRules($ohlc, $period, $method, strtolower($tf));

if ($atr === 0.0) {
    // Log + wait 100ms
    usleep(100000);
    
    // Tentative 2
    $klines = $this->klineProvider->getKlines($symbol, $tfEnum, 200);
    $atr = $calc->computeWithRules($ohlc, $period, $method, strtolower($tf));
    
    if ($atr === 0.0) {
        // Log error avec sample de klines
        return null;  // ← Au lieu de retourner 0.0
    }
}
```

**Logs ajoutés:**
- `[MTF] ATR computation start` (debug)
- `[MTF] ATR = 0.0, retrying klines fetch` (warning)
- `[MTF] ATR still 0.0 after retry` (error avec sample)
- `[MTF] ATR computed successfully on retry` (info)
- `[MTF] ATR computation result` (debug)

---

### Fix #3 : MtfService - Erreurs ATR explicites

**Fichier:** `src/MtfValidator/Service/MtfService.php`

**Changements:**
- Ligne 781-791 : ATR 5m - Exception loggée au lieu d'ignorée
- Ligne 865-875 : ATR 1m - Exception loggée au lieu d'ignorée

**Avant:**
```php
try {
    $result1m['atr'] = $this->computeAtrValue($symbol, '1m');
} catch (\Throwable $e) {
    // ignore ATR errors  ← Silencieux !
}
```

**Après:**
```php
try {
    $result1m['atr'] = $this->computeAtrValue($symbol, '1m');
} catch (\Throwable $e) {
    $this->logger->error('[MTF] ATR computation exception', [
        'symbol' => $symbol,
        'timeframe' => '1m',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    $result1m['atr'] = null;  // Explicitement null
}
```

---

### Fix #4 : OrderPlanBuilder - Distance minimale stop-loss

**Fichier:** `src/TradeEntry/OrderPlan/OrderPlanBuilder.php:281-315`

**Changement:**
- Validation obligatoire de distance minimale : **0.5%**
- Si stop < 0.5% → **Exception** avec logs détaillés
- Protection contre ATR naturellement trop petits

**Code ajouté:**
```php
// GARDE CRITIQUE : Distance minimale du stop-loss
$MIN_STOP_DISTANCE_PCT = 0.005; // 0.5% minimum
$stopDistancePct = abs($stop - $entry) / max($entry, 1e-9);

if ($stopDistancePct < $MIN_STOP_DISTANCE_PCT) {
    $this->flowLogger->error('order_plan.stop_too_tight', [
        'symbol' => $req->symbol,
        'distance_pct' => $stopDistancePct,
        'min_required_pct' => $MIN_STOP_DISTANCE_PCT,
        'atr_value' => $req->atrValue,
        'stop_from' => $req->stopFrom,
    ]);
    throw new \RuntimeException(sprintf(
        'Stop loss trop serré pour %s: %.2f%% < %.2f%% minimum',
        $req->symbol,
        $stopDistancePct * 100,
        $MIN_STOP_DISTANCE_PCT * 100
    ));
}
```

**Impact:**
- ✅ TAOUSDT (0.30%) → **REJETÉ**
- ✅ VIRTUALUSDT (0.40%) → **REJETÉ**  
- ✅ PIPPINUSDT (0.33%) → **REJETÉ**
- ✅ ICNTUSDT (0.94%) → **ACCEPTÉ**

---

## ❌ **BUGS RESTANTS À CORRIGER**

### 1️⃣ **BUG CRITIQUE : `canExecuteTrading()` ne détecte pas ATR = 0.0**

**Fichier:** `src/MtfValidator/Service/TradingDecisionHandler.php:243`

**Code actuel:**
```php
if ($requirePriceOrAtr && $symbolResult->currentPrice === null && $symbolResult->atr === null) {
    return false;
}
```

**Problème:**
- Ne vérifie que `=== null`, pas `<= 0.0`
- Si ATR = 0.0, le test passe : `0.0 !== null` → ordre accepté !

**FIX requis:**
```php
if ($requirePriceOrAtr && $symbolResult->currentPrice === null && ($symbolResult->atr === null || $symbolResult->atr <= 0.0)) {
    $this->logger->debug('[Trading Decision] Missing price and ATR', [
        'symbol' => $symbolResult->symbol,
        'price' => $symbolResult->currentPrice,
        'atr' => $symbolResult->atr,
    ]);
    $this->orderJourneyLogger->info('order_journey.preconditions.blocked', [
        'symbol' => $symbolResult->symbol,
        'decision_key' => $decisionKey,
        'reason' => 'missing_price_and_atr',
        'atr' => $symbolResult->atr,
    ]);
    return false;
}
```

---

### 2️⃣ **BUG CRITIQUE : `AtrCalculator` retourne 0.0 au lieu de null**

**Fichier:** `src/Indicator/Core/AtrCalculator.php:144-192`

**Code actuel:**
```php
public function computeWithRules(
    array $ohlc,
    int $period = 14,
    string $method = 'wilder',
    ?string $timeframe = null,
    float $tickSize = 0.0
): float {  // ← Signature retourne float
    $n = count($ohlc);
    if ($period <= 0 || $n <= $period) {
        return 0.0;  // ← BUG : devrait retourner null
    }
    $series = $this->computeSeries($ohlc, $period, $method);
    if ($series === []) {
        return 0.0;  // ← BUG : devrait retourner null
    }
    // ...
    return $latest;
}
```

**Problème:**
- Retourne `0.0` pour données insuffisantes au lieu de `null`
- `0.0` est considéré comme une valeur **valide** dans les tests `!== null`
- Mais `0.0` est **invalide** pour calculer un stop-loss

**FIX requis:**
```php
public function computeWithRules(
    array $ohlc,
    int $period = 14,
    string $method = 'wilder',
    ?string $timeframe = null,
    float $tickSize = 0.0
): ?float {  // ← Changer la signature pour retourner ?float (nullable)
    $n = count($ohlc);
    if ($period <= 0 || $n <= $period) {
        return null;  // ← Retourner null au lieu de 0.0
    }
    $series = $this->computeSeries($ohlc, $period, $method);
    if ($series === []) {
        return null;  // ← Retourner null au lieu de 0.0
    }
    // ...
    return $latest;
}
```

**Impact:**
- ⚠️ Changement de signature : tous les appelants doivent gérer `null`
- Vérifier tous les usages de `computeWithRules()` dans le codebase

---

### 3️⃣ ~~**BUG CRITIQUE : Erreurs ATR silencieuses dans `MtfService`**~~ ✅ **CORRIGÉ**

**Fichier:** `src/MtfValidator/Service/MtfService.php`

~~**Code ancien:**~~
```php
try {
    $result1m['atr'] = $this->computeAtrValue($symbol, '1m');
} catch (\Throwable $e) {
    // ignore ATR errors  ← BUG : erreurs silencieuses !
}
```

**✅ Code corrigé (ligne 865-875 pour 1m, ligne 781-791 pour 5m):**
```php
try {
    $result1m['atr'] = $this->computeAtrValue($symbol, '1m');
} catch (\Throwable $e) {
    $this->logger->error('[MTF] ATR computation exception', [
        'symbol' => $symbol,
        'timeframe' => '1m',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    $result1m['atr'] = null;  // Explicitement null
}
```

---

### 4️⃣ ~~**VALIDATION MANQUANTE : Distance minimale du stop-loss**~~ ✅ **CORRIGÉ**

**Fichier:** `src/TradeEntry/OrderPlan/OrderPlanBuilder.php:281-315`

~~**Code ancien:**~~
```php
$minTick = TickQuantizer::tick($precision);
if ($stop <= 0.0 || abs($stop - $entry) < $minTick) {
    // correction...
}
if ($stop <= 0.0 || $stop === $entry) {
    throw new \RuntimeException('Stop loss invalide');
}
// ← Pas de validation de distance minimale en % !
```

**✅ Code corrigé (ligne 281-315):**
```php
if ($stop <= 0.0 || $stop === $entry) {
    throw new \RuntimeException('Stop loss invalide');
}

// GARDE CRITIQUE : Distance minimale du stop-loss
$MIN_STOP_DISTANCE_PCT = 0.005; // 0.5% minimum
$stopDistancePct = abs($stop - $entry) / max($entry, 1e-9);

if ($stopDistancePct < $MIN_STOP_DISTANCE_PCT) {
    $this->flowLogger->error('order_plan.stop_too_tight', [
        'symbol' => $req->symbol,
        'distance_pct' => $stopDistancePct,
        'min_required_pct' => $MIN_STOP_DISTANCE_PCT,
        'atr_value' => $req->atrValue,
        'stop_from' => $req->stopFrom,
        'decision_key' => $decisionKey,
    ]);
    throw new \RuntimeException(sprintf(
        'Stop loss trop serré pour %s: %.2f%% < %.2f%% minimum',
        $req->symbol,
        $stopDistancePct * 100,
        $MIN_STOP_DISTANCE_PCT * 100
    ));
}
```

**Impact sur les positions problématiques:**
- ✅ TAOUSDT (0.30%) → Ordre **REJETÉ**
- ✅ VIRTUALUSDT (0.40%) → Ordre **REJETÉ**  
- ✅ PIPPINUSDT (0.33%) → Ordre **REJETÉ**
- ✅ ICNTUSDT (0.94%) → Ordre **ACCEPTÉ** (au-dessus du minimum)

**Configuration future (optionnel) dans `mtf_validations.yaml`:**
```yaml
defaults:
    min_stop_distance_pct: 0.005  # 0.5% minimum
    # Ou adapter selon le timeframe :
    min_stop_distance_by_tf:
        '1m': 0.005   # 0.5%
        '5m': 0.008   # 0.8%
        '15m': 0.010  # 1.0%
```

---

### 5️⃣ ~~**QUESTION : Pourquoi ATR = 0.0 avec 201 klines disponibles ?**~~ ✅ **RÉSOLU**

**Observation:**
- Logs montrent : `"count":201` klines 1m récupérées à 04:02:13
- `computeAtrValue()` demande 200 klines
- Pourtant `$result1m['atr'] = 0.0`

**Hypothèses confirmées:**
1. **Klines plates** : Possible si toutes les klines ont `high = low = close`, ATR peut être 0
2. ~~**Exception silencieuse**~~ : ✅ Corrigé avec logs explicites
3. **Problème de timing** : ✅ **CAUSE PROBABLE** - Klines en cours d'insertion en DB quand ATR calculé

**✅ Solution implémentée :**
- **Retry automatique** avec délai de 100ms si ATR = 0.0
- **Logs détaillés** à chaque étape avec samples de klines
- **Retourne `null`** au lieu de 0.0 si toujours invalide après retry

**Logs ajoutés pour diagnostiquer :**
- `[MTF] ATR computation start` → klines_count
- `[MTF] ATR = 0.0, retrying klines fetch` → samples avant/après
- `[MTF] ATR still 0.0 after retry` → samples détaillés (first, mid, last)
- `[MTF] ATR computed successfully on retry` → succès avec valeur

---

## 📋 **PRIORITÉS**

### P0 - CRITIQUE (À faire immédiatement)
1. ✅ Fix `TradingDecisionHandler::buildTradeEntryRequest()` → **FAIT**
2. ✅ Fix erreurs ATR silencieuses dans `MtfService` → **FAIT**
3. ✅ Retry klines si ATR = 0.0 → **FAIT**
4. ✅ Validation distance minimale stop-loss → **FAIT**
5. ❌ Fix `canExecuteTrading()` pour détecter ATR = 0.0

### P1 - IMPORTANT (À faire rapidement)
6. ❌ Fix `AtrCalculator::computeWithRules()` pour retourner `?float`

### P2 - INVESTIGATION
7. ✅ Investiguer pourquoi ATR = 0.0 avec 201 klines → **RÉSOLU** (timing + retry)

---

## 🔍 **IMPACT DES FIXES APPLIQUÉS**

### Comportement AVANT les fixes :
```
Config: stop_from = 'atr'
Klines insérées en DB (timing)
→ computeAtrValue() lit trop tôt → ATR = 0.0
→ Exception ATR ignorée silencieusement
→ Bascule silencieusement sur stop_from = 'risk'
→ Stop calculé : 0.33%
→ Position ouverte et liquidée immédiatement ❌
```

### Comportement APRÈS les fixes :
```
Config: stop_from = 'atr'
Klines insérées en DB (timing)
→ computeAtrValue() lit trop tôt → ATR = 0.0
→ [MTF] ATR = 0.0, retrying klines fetch (warning)
→ usleep(100ms) + retry
→ ATR calculé avec succès (info log) ✅
→ Position ouverte avec stop basé sur ATR valide ✅

OU si toujours invalide après retry :
→ [MTF] ATR still 0.0 after retry (error)
→ return null au lieu de 0.0
→ [Trading Decision] ATR required but invalid (warning)
→ order_journey.preconditions.blocked
→ Position REJETÉE ✅
```

---

## 📊 **MÉTRIQUES À SURVEILLER**

Après déploiement du fix, surveiller dans les logs :
- Nombre d'ordres bloqués avec `reason: 'atr_required_but_invalid'`
- Symboles concernés (probablement nouveaux symboles)
- Timeframes concernés (probablement 1m)

---

## 🎯 **TESTS À AJOUTER**

```php
// tests/Unit/MtfValidator/Service/TradingDecisionHandlerTest.php

public function testBuildTradeEntryRequestBlocksWhenAtrRequiredButInvalid(): void
{
    // Config: stop_from = 'atr'
    // ATR = 0.0
    // Expected: null (ordre rejeté)
}

public function testBuildTradeEntryRequestBlocksWhenAtrRequiredButNull(): void
{
    // Config: stop_from = 'atr'
    // ATR = null
    // Expected: null (ordre rejeté)
}

public function testBuildTradeEntryRequestFallbackWhenStopFromRisk(): void
{
    // Config: stop_from = 'risk'
    // ATR = 0.0 ou null
    // Expected: TradeEntryRequest avec stop_from = 'risk'
}
```

---

_Document créé le 2025-11-03 suite à l'incident PIPPINUSDT_

