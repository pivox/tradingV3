# Analyse de l'utilisation de `trading.yml`

**Date**: 2025-01-27  
**Objectif**: Identifier les sections réellement utilisées et les problèmes liés à la migration vers des fichiers dédiés.

---

## 📋 Résumé

Le fichier `config/trading.yml` est **partiellement utilisé** mais contient des **sections manquantes** recherchées par certains services après la migration vers des fichiers dédiés.

---

## ✅ Sections RÉELLEMENT UTILISÉES dans `trading.yml`

### 1. `version`
- **Utilisé par** :
  - `DbValidationCache::cacheValidationState()` → ligne 31
  - `TradingParameters::checkVersionAndRefresh()` → ligne 17
- **Usage** : Versioning du cache de validation

### 2. `meta`
- **Utilisé par** :
  - `TradingConfigService::getMetaInfo()` → ligne 130-133
- **Usage** : Informations de métadonnées (nom, description, created_at)

### 3. `risk`
- **Utilisé par** :
  - `TradingConfigService::getRiskConfig()` → ligne 51-54
  - `TradingParameters::riskPct()` → ligne 52-55
- **Usage** : Configuration du risque (fixed_risk_pct, daily_max_loss_pct, max_concurrent_positions)

### 4. `leverage`
- **Utilisé par** :
  - `TradingConfigService::getLeverageConfig()` → ligne 57-60
  - `TradingParameters::getTimeframeMultipliers()` → ligne 86-90
- **Usage** : Configuration du levier (mode, floor, exchange_cap, timeframe_multipliers, etc.)

---

## ❌ Sections MANQUANTES mais RECHERCHÉES

### 1. `timeframes`
- **Recherché par** :
  - `TradingConfigService::getTimeframes()` → ligne 27-30
  - `TradingConfigService::getMinBars()` → ligne 39-42
  - `IndicatorTestController::isTimeframeValid()` → ligne 98
- **Problème** : Cette section a été déplacée vers `config/app/signal.yaml`
- **Impact** : `getTimeframes()` retourne un tableau vide, `isTimeframeValid()` ne fonctionne pas correctement

### 2. `post_validation`
- **Recherché par** :
  - `EntryZoneCalculator::compute()` → ligne 49
- **Problème** : Cette section a été déplacée vers `config/app/trade_entry.yaml`
- **Impact** : `EntryZoneCalculator` ne trouve pas la configuration `entry_zone` et utilise les valeurs par défaut

### 3. `atr`
- **Recherché par** :
  - `TradingConfigService::getAtrConfig()` → ligne 63-66
  - `TradingParameters::atrPeriod()` → ligne 58-61
  - `TradingParameters::slMult()` → ligne 64-67
- **Problème** : Cette section a été déplacée vers `config/app/indicator.yaml`
- **Impact** : Retourne un tableau vide, utilise des fallbacks

### 4. `indicators`
- **Recherché par** :
  - `TradingConfigService::getIndicatorsConfig()` → ligne 45-48
- **Problème** : Cette section a été déplacée vers `config/app/indicator.yaml`
- **Impact** : Retourne un tableau vide

### 5. `indicator_calculation`
- **Recherché par** :
  - `TradingConfigService::getIndicatorCalculationConfig()` → ligne 92-95
  - `TradingConfigService::getIndicatorCalculationMode()` → ligne 98-101
  - `TradingConfigService::isIndicatorCalculationFallbackEnabled()` → ligne 104-107
- **Problème** : Cette section a été déplacée vers `config/app/indicator.yaml`
- **Impact** : Retourne des valeurs par défaut

### 6. `conviction_high`
- **Recherché par** :
  - `TradingConfigService::getConvictionHighConfig()` → ligne 69-72
- **Problème** : Cette section a été déplacée vers `config/app/signal.yaml`
- **Impact** : Retourne un tableau vide

### 7. `reversal_protection`
- **Recherché par** :
  - `TradingConfigService::getReversalProtectionConfig()` → ligne 75-78
- **Problème** : Cette section a été déplacée vers `config/app/signal.yaml`
- **Impact** : Retourne un tableau vide

### 8. `scalp_mode_trigger`
- **Recherché par** :
  - `TradingConfigService::getScalpModeConfig()` → ligne 81-84
- **Problème** : Cette section a été déplacée vers `config/app/signal.yaml`
- **Impact** : Retourne un tableau vide

---

## 🔍 Services utilisant `trading.yml`

### 1. `TradingConfigService`
- **Fichier** : `src/Service/TradingConfigService.php`
- **Charge** : `config/trading.yml`
- **Méthodes problématiques** :
  - `getTimeframes()` → cherche `timeframes` (n'existe plus)
  - `getAtrConfig()` → cherche `atr` (n'existe plus)
  - `getIndicatorsConfig()` → cherche `indicators` (n'existe plus)
  - `getIndicatorCalculationConfig()` → cherche `indicator_calculation` (n'existe plus)
  - `getConvictionHighConfig()` → cherche `conviction_high` (n'existe plus)
  - `getReversalProtectionConfig()` → cherche `reversal_protection` (n'existe plus)
  - `getScalpModeConfig()` → cherche `scalp_mode_trigger` (n'existe plus)
- **Méthodes fonctionnelles** :
  - `getRiskConfig()` → ✅ `risk` existe
  - `getLeverageConfig()` → ✅ `leverage` existe
  - `getMetaInfo()` → ✅ `meta` existe
  - `getVersion()` → ✅ `version` existe

### 2. `TradingParameters`
- **Fichier** : `src/Config/TradingParameters.php`
- **Charge** : `config/trading.yml` (via paramètre `trading.file`)
- **Méthodes problématiques** :
  - `atrPeriod()` → cherche `atr.period` (n'existe plus)
  - `slMult()` → cherche `atr.sl_multiplier` (n'existe plus)
  - `getFetchLimitForTimeframe()` → cherche `timeframes[$tf].guards.min_bars` (n'existe plus)
- **Méthodes fonctionnelles** :
  - `riskPct()` → ✅ `risk.fixed_risk_pct` existe
  - `getTimeframeMultipliers()` → ✅ `leverage.timeframe_multipliers` existe

### 3. `EntryZoneCalculator`
- **Fichier** : `src/TradeEntry/EntryZone/EntryZoneCalculator.php`
- **Ligne 48-49** : Cherche `post_validation.entry_zone` dans `trading.yml`
- **Problème** : Cette section a été déplacée vers `config/app/trade_entry.yaml`
- **Impact** : Utilise des valeurs par défaut (constantes) au lieu de la configuration

### 4. `IndicatorTestController`
- **Fichier** : `src/Controller/Web/IndicatorTestController.php`
- **Ligne 98** : Utilise `TradingConfigService::isTimeframeValid()` qui dépend de `getTimeframes()`
- **Problème** : `getTimeframes()` cherche `timeframes` qui n'existe plus
- **Impact** : La validation des timeframes ne fonctionne pas correctement

### 5. `DbValidationCache`
- **Fichier** : `src/Runtime/Cache/DbValidationCache.php`
- **Ligne 31** : Utilise `TradingConfigService::getVersion()`
- **Status** : ✅ Fonctionne correctement

---

## 🚨 Problèmes identifiés

### Problème 1 : `EntryZoneCalculator` ne trouve pas `post_validation`
```php
// EntryZoneCalculator.php ligne 48-49
$cfg = $this->config?->getConfig() ?? [];
$post = $cfg['post_validation'] ?? []; // ❌ Cette section n'existe plus dans trading.yml
```
**Solution** : Modifier `EntryZoneCalculator` pour charger depuis `TradeEntryConfig` au lieu de `TradingConfigService`.

### Problème 2 : `TradingConfigService::getTimeframes()` retourne un tableau vide
```php
// TradingConfigService.php ligne 27-30
public function getTimeframes(): array
{
    $this->checkVersionAndRefresh();
    return array_keys($this->config['timeframes'] ?? []); // ❌ timeframes n'existe plus
}
```
**Solution** : Modifier pour charger depuis `SignalConfig` (à créer) ou `signal.yaml`.

### Problème 3 : `IndicatorTestController::isTimeframeValid()` ne fonctionne pas
```php
// IndicatorTestController.php ligne 98
if (!$this->tradingConfigService->isTimeframeValid($timeframe)) {
    // ❌ Retourne toujours false car getTimeframes() retourne []
}
```
**Solution** : Utiliser une source de vérité pour les timeframes (ex: `signal.yaml`).

### Problème 4 : `TradingParameters` cherche des sections déplacées
```php
// TradingParameters.php
public function atrPeriod(): int {
    $cfg = $this->all();
    return (int) ($cfg['atr']['period'] ?? 14); // ❌ atr n'existe plus
}
```
**Solution** : Modifier pour charger depuis `IndicatorConfig` (à créer) ou `indicator.yaml`.

---

## 💡 Recommandations

### Option A : Nettoyer `TradingConfigService` (RECOMMANDÉ)
1. Supprimer les méthodes qui cherchent des sections déplacées :
   - `getTimeframes()`, `getAtrConfig()`, `getIndicatorsConfig()`, etc.
2. Créer des services de configuration dédiés :
   - `SignalConfig` pour `signal.yaml`
   - `IndicatorConfig` pour `indicator.yaml`
3. Migrer les usages vers les nouveaux services

### Option B : Adapter `TradingConfigService` pour charger plusieurs fichiers
1. Modifier `TradingConfigService` pour charger et fusionner :
   - `trading.yml` (version, meta, risk, leverage)
   - `signal.yaml` (timeframes, conviction_high, etc.)
   - `indicator.yaml` (atr, indicators, etc.)
   - `trade_entry.yaml` (post_validation)
2. Maintenir la compatibilité avec le code existant

### Option C : Garder `trading.yml` minimal et migrer progressivement
1. Garder uniquement `version`, `meta`, `risk`, `leverage` dans `trading.yml`
2. Migrer progressivement les services vers les nouveaux fichiers de configuration
3. Déprécier les méthodes obsolètes de `TradingConfigService`

---

## 📊 Statistiques

- **Sections utilisées** : 4 (`version`, `meta`, `risk`, `leverage`)
- **Sections manquantes recherchées** : 8 (`timeframes`, `post_validation`, `atr`, `indicators`, `indicator_calculation`, `conviction_high`, `reversal_protection`, `scalp_mode_trigger`)
- **Services affectés** : 5 (`TradingConfigService`, `TradingParameters`, `EntryZoneCalculator`, `IndicatorTestController`, `DbValidationCache`)

---

**Généré le**: 2025-01-27  
**Version**: 1.0

