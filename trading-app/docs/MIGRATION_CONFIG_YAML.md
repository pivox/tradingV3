# Migration des Fichiers de Configuration YAML

**Date**: 2025-01-27  
**Objectif**: Réorganiser les fichiers de configuration par module pour améliorer la maintenabilité.

---

## 📋 Résumé des Changements

### Fichiers Déplacés

1. **`config/app/mtf_contracts.yaml`** → **`src/Provider/config/contracts.yaml`**
   - Module: Provider
   - Classe: `MtfContractsConfig`
   - ✅ Références mises à jour dans `services.yaml`

2. **`config/app/mtf_validations.yaml`** → **`src/MtfValidator/config/validations.yaml`**
   - Module: MtfValidator
   - Classe: `MtfValidationConfig`
   - ✅ Références mises à jour dans `services.yaml`

### Nouveaux Fichiers Créés

3. **`config/app/trade_entry.yaml`** (NOUVEAU)
   - Sections extraites de `trading.yml`:
     - `entry` (❌ non utilisée)
     - `post_validation` (partiellement utilisée)

4. **`config/app/indicator.yaml`** (NOUVEAU)
   - Sections extraites de `trading.yml`:
     - `indicators`
     - `atr`
     - `indicator_calculation`

5. **`config/app/signal.yaml`** (NOUVEAU)
   - Sections extraites de `trading.yml`:
     - `mtf`
     - `runtime` (❌ non utilisée)
     - `timeframes`
     - `conviction_high`
     - `reversal_protection`
     - `scalp_mode_trigger`

### Fichiers Nettoyés

6. **`config/trading.yml`** (NETTOYÉ)
   - Sections conservées:
     - `version`
     - `meta` (⚠️ utilisée indirectement)
     - `symbols` (❌ non utilisée)
     - `risk` (✅ utilisée)
     - `leverage` (✅ utilisée)
   - Sections supprimées (déplacées ou obsolètes):
     - `indicators` → `config/app/indicator.yaml`
     - `atr` → `config/app/indicator.yaml`
     - `indicator_calculation` → `config/app/indicator.yaml`
     - `mtf` → `config/app/signal.yaml`
     - `runtime` → `config/app/signal.yaml`
     - `timeframes` → `config/app/signal.yaml`
     - `conviction_high` → `config/app/signal.yaml`
     - `reversal_protection` → `config/app/signal.yaml`
     - `scalp_mode_trigger` → `config/app/signal.yaml`
     - `entry` → `config/app/trade_entry.yaml`
     - `post_validation` → `config/app/trade_entry.yaml`
     - `integration` → ❌ SUPPRIMÉE (obsolète)
     - `logging` → ❌ SUPPRIMÉE (doublon avec monolog.yaml)
     - `contract_pipeline` → ❌ SUPPRIMÉE (non utilisée)

---

## ⚠️ Actions Requises pour le Code

### 1. TradingConfigService

**Problème**: `TradingConfigService` charge uniquement `trading.yml` mais plusieurs méthodes cherchent des sections qui ont été déplacées.

**Méthodes affectées**:
- `getIndicatorsConfig()` → cherche `indicators` (maintenant dans `indicator.yaml`)
- `getAtrConfig()` → cherche `atr` (maintenant dans `indicator.yaml`)
- `getIndicatorCalculationConfig()` → cherche `indicator_calculation` (maintenant dans `indicator.yaml`)
- `getTimeframes()` → cherche `timeframes` (maintenant dans `signal.yaml`)
- `getMinBars()` → cherche `timeframes` (maintenant dans `signal.yaml`)
- `getConvictionHighConfig()` → cherche `conviction_high` (maintenant dans `signal.yaml`)
- `getReversalProtectionConfig()` → cherche `reversal_protection` (maintenant dans `signal.yaml`)
- `getScalpModeConfig()` → cherche `scalp_mode_trigger` (maintenant dans `signal.yaml`)

**Solutions possibles**:

#### Option A: Créer des services de configuration dédiés (RECOMMANDÉ)
```php
// Créer IndicatorConfigService, SignalConfigService, TradeEntryConfigService
// Chaque service charge son propre fichier YAML
```

#### Option B: Modifier TradingConfigService pour charger plusieurs fichiers
```php
// Fusionner les configurations de plusieurs fichiers
private function loadAllConfigs(): array {
    $trading = Yaml::parseFile($this->tradingPath);
    $indicator = Yaml::parseFile($this->indicatorPath)['indicator'] ?? [];
    $signal = Yaml::parseFile($this->signalPath)['signal'] ?? [];
    $tradeEntry = Yaml::parseFile($this->tradeEntryPath)['trade_entry'] ?? [];
    
    return array_merge($trading, [
        'indicators' => $indicator['indicators'] ?? [],
        'atr' => $indicator['atr'] ?? [],
        'indicator_calculation' => $indicator['calculation'] ?? [],
        'mtf' => $signal['mtf'] ?? [],
        'timeframes' => $signal['timeframes'] ?? [],
        'conviction_high' => $signal['conviction_high'] ?? [],
        'reversal_protection' => $signal['reversal_protection'] ?? [],
        'scalp_mode_trigger' => $signal['scalp_mode_trigger'] ?? [],
        // ...
    ]);
}
```

#### Option C: Adapter le code appelant pour utiliser les nouveaux services
- Remplacer les appels à `TradingConfigService::getIndicatorsConfig()` par un nouveau `IndicatorConfigService`
- Remplacer les appels à `TradingConfigService::getTimeframes()` par un nouveau `SignalConfigService`
- etc.

### 2. EntryZoneCalculator

**Fichier**: `src/TradeEntry/EntryZone/EntryZoneCalculator.php`

**Ligne 49**: Charge `post_validation` depuis `trading.yml`
```php
$post = $cfg['post_validation'] ?? [];
```

**Action requise**: Modifier pour charger depuis `config/app/trade_entry.yaml`
```php
$tradeEntryConfig = Yaml::parseFile($parameterBag->get('kernel.project_dir') . '/config/app/trade_entry.yaml');
$post = $tradeEntryConfig['trade_entry']['post_validation'] ?? [];
```

### 3. Services utilisant getConfig()['mtf']

**Fichiers affectés**:
- `src/Signal/SignalValidationService.php` (lignes 55, 121-122)
- `src/MtfValidator/Service/Timeframe/BaseTimeframeService.php` (ligne 155)
- `src/TradeEntry/Service/TpSlTwoTargetsService.php` (ligne 864)

**Action requise**: Charger depuis `config/app/signal.yaml` au lieu de `trading.yml`

---

## 📝 Commentaires dans les Fichiers

Tous les nouveaux fichiers incluent des commentaires indiquant le statut de chaque clé :

- **✅ UTILISÉES**: Clés référencées directement dans le code
- **⚠️ UTILISÉES INDIRECTEMENT**: Accès via `getConfig()` ou méthodes génériques
- **❌ NON UTILISÉES**: Jamais référencées dans le code
- **🚧 NON IMPLÉMENTÉES**: Marquées "n'est pas encore implémenté" dans les commentaires

---

## 🔄 Compatibilité

### Support de l'Ancien Format

- **MtfContractsConfig**: Supporte les deux formats (`mtf_contracts` et `contracts`)
- **MtfValidationConfig**: Chemin par défaut mis à jour, mais peut être surchargé

### Migration Progressive

Les fichiers peuvent coexister pendant la transition. Les classes de configuration ont été mises à jour pour supporter les deux formats si nécessaire.

---

## ✅ Checklist de Migration

- [x] Créer `src/Provider/config/contracts.yaml`
- [x] Créer `src/MtfValidator/config/validations.yaml`
- [x] Créer `config/app/trade_entry.yaml`
- [x] Créer `config/app/indicator.yaml`
- [x] Créer `config/app/signal.yaml`
- [x] Nettoyer `config/trading.yml`
- [x] Mettre à jour `MtfContractsConfig` (chemin + support ancien format)
- [x] Mettre à jour `MtfValidationConfig` (chemin)
- [x] Mettre à jour `services.yaml` (chemins)
- [ ] **TODO**: Adapter `TradingConfigService` ou créer des services dédiés
- [ ] **TODO**: Adapter `EntryZoneCalculator` pour charger `trade_entry.yaml`
- [ ] **TODO**: Adapter les services utilisant `getConfig()['mtf']`
- [ ] **TODO**: Supprimer les anciens fichiers après migration complète
- [ ] **TODO**: Mettre à jour la documentation

---

## 📚 Références

- Rapport d'analyse: `docs/RAPPORT_CONFIGURATIONS_NON_UTILISEES.md`
- Ancien fichier (backup): `config/app/mtf_validations.yaml.old`

---

**Note**: Cette migration améliore l'organisation du code mais nécessite des adaptations dans le code qui utilise ces configurations. Voir section "Actions Requises" ci-dessus.

