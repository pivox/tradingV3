# Clés de `mtf_validations.yaml` Utilisées dans TradeEntry

**Date**: 2025-01-27  
**Objectif**: Identifier toutes les clés de `mtf_validations.yaml` (section `defaults`) utilisées dans le module TradeEntry.

---

## 📋 Résumé

Le module **TradeEntry** utilise **22 clés** de `mtf_validation.defaults` via `MtfValidationConfig`.

**Services TradeEntry utilisant la config** :
1. `TradeEntryRequestBuilder` (construction de requêtes)
2. `DynamicLeverageService` (calcul du levier)
3. `TpSlTwoTargetsService` (calcul SL/TP)
4. `ConfigurableOrderModePolicy` (politique de mode d'ordre)
5. `ExampleTradeEntryRunner` (exemples)

---

## ✅ Clés UTILISÉES dans TradeEntry

### 1. **TradeEntryRequestBuilder** (`src/TradeEntry/Builder/TradeEntryRequestBuilder.php`)

Utilise **20 clés** pour construire `TradeEntryRequest` :

| Clé | Ligne | Usage | Fallback |
|-----|-------|-------|----------|
| `timeframe_multipliers` | 48 | Multiplicateur par TF pour ajuster sizing | `[]` |
| `risk_pct_percent` | 51 | % risque de base (converti en décimal) | `2.0` |
| `initial_margin_usdt` | 57 | Marge initiale en USDT | `100.0` |
| `fallback_account_balance` | 59 | Solde de fallback si marge invalide | `0.0` |
| `stop_from` | 67 | Source du stop ('atr' ou 'risk') | `'risk'` |
| `atr_k` | 68 | Multiplicateur ATR pour stop | `1.5` |
| `order_type` | 93 | Type d'ordre ('limit' ou 'market') | `'limit'` |
| `market_max_spread_pct` | 97 | Spread max accepté (converti si > 1.0) | `0.001` |
| `inside_ticks` | 102 | Nombre de ticks à l'intérieur | `1` |
| `max_deviation_pct` | 103 | Déviation max (optionnel) | `null` |
| `implausible_pct` | 104 | Seuil d'implausibilité (optionnel) | `null` |
| `zone_max_deviation_pct` | 105 | Déviation max de zone (optionnel) | `null` |
| `tp_policy` | 107 | Politique de Take Profit | `'pivot_conservative'` |
| `tp_buffer_pct` | 108 | Buffer TP en % (optionnel, validé > 0) | `null` |
| `tp_buffer_ticks` | 112 | Buffer TP en ticks (optionnel, validé > 0) | `null` |
| `tp_min_keep_ratio` | 116 | Ratio min à conserver pour TP | `0.95` |
| `tp_max_extra_r` | 117 | R supplémentaire max pour TP (optionnel, validé >= 0) | `null` |
| `pivot_sl_policy` | 122 | Politique de Stop Loss pivot | `'nearest'` |
| `pivot_sl_buffer_pct` | 123 | Buffer SL pivot en % (optionnel, validé >= 0) | `null` |
| `pivot_sl_min_keep_ratio` | 127 | Ratio min à conserver pour SL pivot (optionnel, validé > 0) | `null` |
| `open_type` | 138 | Type d'ouverture ('isolated' ou 'cross') | `'isolated'` |
| `order_mode` | 139 | Mode d'ordre (1=taker, 4=maker) | `1` |
| `r_multiple` | 142 | Multiple R pour TP | `2.0` |

**Total**: **23 clés** (certaines utilisées plusieurs fois)

---

### 2. **DynamicLeverageService** (`src/TradeEntry/Service/Leverage/DynamicLeverageService.php`)

Utilise **3 clés** pour le calcul dynamique du levier :

| Clé | Ligne | Usage | Fallback |
|-----|-------|-------|----------|
| `k_dynamic` | 53 | Multiplicateur dynamique pour cap du levier | `10.0` |
| `risk_pct_percent` | 54 | % risque pour calcul du risk USDT | `5.0` |

**Note**: `k_dynamic` est utilisé dans la formule : `dynCap = min(maxLeverage, kDynamic / stopPct)`

---

### 3. **TpSlTwoTargetsService** (`src/TradeEntry/Service/TpSlTwoTargetsService.php`)

Utilise **11 clés** pour le calcul SL/TP :

| Clé | Ligne | Usage | Fallback |
|-----|-------|-------|----------|
| `risk_pct_percent` | 121 | % risque par défaut | `2.0` |
| `initial_margin_usdt` | 123 | Marge initiale | `100.0` |
| `tp_policy` | 124 | Politique TP | `'pivot_conservative'` |
| `tp_min_keep_ratio` | 125 | Ratio min TP | `0.95` |
| `tp_buffer_pct` | 126 | Buffer TP % (optionnel) | `null` |
| `tp_buffer_ticks` | 127 | Buffer TP ticks (optionnel) | `null` |
| `pivot_sl_policy` | 128 | Politique SL pivot | `'nearest'` |
| `pivot_sl_buffer_pct` | 129 | Buffer SL pivot % (optionnel) | `null` |
| `pivot_sl_min_keep_ratio` | 130 | Ratio min SL pivot (optionnel) | `null` |
| `r_multiple` | 131 | Multiple R | `2.0` |
| `sl_full_size` | 132 | **⚠️ NON DÉFINIE dans mtf_validations.yaml** | `true` |

**Note**: `sl_full_size` est référencée mais **n'existe pas** dans `mtf_validations.yaml` (utilise le fallback `true`)

---

### 4. **ConfigurableOrderModePolicy** (`src/TradeEntry/Policy/ConfigurableOrderModePolicy.php`)

Utilise **1 clé** :

| Clé | Ligne | Usage | Fallback |
|-----|-------|-------|----------|
| `order_mode` | 22 | Mode d'ordre par défaut (1=taker, 4=maker) | `4` |

---

### 5. **ExampleTradeEntryRunner** (`src/TradeEntry/Example/ExampleTradeEntryRunner.php`)

Utilise **10 clés** (exemple d'utilisation) :

| Clé | Usage |
|-----|-------|
| `risk_pct_percent` | Calcul du risk % |
| `pivot_sl_policy` | Politique SL |
| `pivot_sl_buffer_pct` | Buffer SL |
| `pivot_sl_min_keep_ratio` | Ratio min SL |
| `open_type` | Type d'ouverture |
| `order_mode` | Mode d'ordre |
| `initial_margin_usdt` | Marge initiale |
| `r_multiple` | Multiple R |
| `stop_from` | Source du stop |
| `atr_k` | Multiplicateur ATR |
| `market_max_spread_pct` | Spread max |

---

## 📊 Statistiques

### Clés Utilisées par Service

| Service | Nombre de Clés | Clés Principales |
|---------|----------------|------------------|
| `TradeEntryRequestBuilder` | 23 | Toutes les clés de configuration TradeEntry |
| `TpSlTwoTargetsService` | 11 | SL/TP policies, buffers, ratios |
| `DynamicLeverageService` | 2 | `k_dynamic`, `risk_pct_percent` |
| `ConfigurableOrderModePolicy` | 1 | `order_mode` |
| `ExampleTradeEntryRunner` | 10 | Exemple (référence) |

### Clés les Plus Utilisées

| Clé | Utilisée dans | Fréquence |
|-----|---------------|-----------|
| `risk_pct_percent` | Builder, Leverage, TpSl, Example | 4 services |
| `order_mode` | Builder, Policy, Example | 3 services |
| `pivot_sl_policy` | Builder, TpSl, Example | 3 services |
| `initial_margin_usdt` | Builder, TpSl, Example | 3 services |
| `r_multiple` | Builder, TpSl, Example | 3 services |
| `atr_k` | Builder, Example | 2 services |
| `tp_policy` | Builder, TpSl | 2 services |

---

## ⚠️ Clés Référencées mais NON DÉFINIES

| Clé | Service | Ligne | Fallback Utilisé |
|-----|---------|-------|------------------|
| `sl_full_size` | `TpSlTwoTargetsService` | 132 | `true` |

**Action requise**: Ajouter `sl_full_size` dans `mtf_validations.yaml` ou documenter comme optionnel.

---

## 🔄 Recommandation

### Option A: Créer un fichier dédié TradeEntry

**Créer** `config/app/trade_entry_defaults.yaml` :
```yaml
trade_entry:
    defaults:
        # Clés utilisées uniquement par TradeEntry
        risk_pct_percent: 5.0
        initial_margin_usdt: 20.0
        r_multiple: 2.0
        order_type: 'limit'
        open_type: 'isolated'
        order_mode: 1
        stop_from: 'atr'
        atr_k: 1.5
        # ... etc
```

**Avantages** :
- Séparation claire des responsabilités
- TradeEntry indépendant de MTF
- Plus facile à maintenir

### Option B: Garder dans mtf_validations.yaml (actuel)

**Avantages** :
- Configuration centralisée
- Pas de duplication
- Cohérence entre MTF et TradeEntry

**Inconvénients** :
- Couplage entre MTF et TradeEntry
- Fichier volumineux

---

## 📝 Liste Complète des Clés Utilisées

### ✅ Utilisées (22 clés)

1. `timeframe_multipliers` → Builder
2. `risk_pct_percent` → Builder, Leverage, TpSl, Example
3. `initial_margin_usdt` → Builder, TpSl, Example
4. `fallback_account_balance` → Builder
5. `stop_from` → Builder, Example
6. `atr_k` → Builder, Example
7. `order_type` → Builder
8. `market_max_spread_pct` → Builder, Example
9. `inside_ticks` → Builder
10. `max_deviation_pct` → Builder
11. `implausible_pct` → Builder
12. `zone_max_deviation_pct` → Builder
13. `tp_policy` → Builder, TpSl
14. `tp_buffer_pct` → Builder, TpSl
15. `tp_buffer_ticks` → Builder, TpSl
16. `tp_min_keep_ratio` → Builder, TpSl
17. `tp_max_extra_r` → Builder
18. `pivot_sl_policy` → Builder, TpSl, Example
19. `pivot_sl_buffer_pct` → Builder, TpSl, Example
20. `pivot_sl_min_keep_ratio` → Builder, TpSl, Example
21. `open_type` → Builder, Example
22. `order_mode` → Builder, Policy, Example
23. `r_multiple` → Builder, TpSl, Example
24. `k_dynamic` → Leverage

### ⚠️ Référencées mais Non Définies

25. `sl_full_size` → TpSl (fallback: `true`)

---

## 🔍 Détails par Fichier

### `TradeEntryRequestBuilder.php`
- **Lignes 47-142**: Utilise `$this->mtfConfig->getDefaults()` et lit 23 clés
- **Responsabilité**: Transformation MTF → TradeEntryRequest

### `DynamicLeverageService.php`
- **Lignes 52-54**: Utilise `k_dynamic` et `risk_pct_percent`
- **Responsabilité**: Calcul dynamique du levier

### `TpSlTwoTargetsService.php`
- **Lignes 120-132**: Utilise 11 clés pour SL/TP
- **Responsabilité**: Calcul des stops et take profits

### `ConfigurableOrderModePolicy.php`
- **Ligne 22**: Utilise `order_mode`
- **Responsabilité**: Politique de mode d'ordre

---

**Généré le**: 2025-01-27  
**Version**: 1.0
