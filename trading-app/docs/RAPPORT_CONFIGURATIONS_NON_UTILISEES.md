# Rapport d'Analyse : Configurations Non Utilisées

**Date**: 2025-01-27  
**Objectif**: Identifier les clés et sections des fichiers de configuration qui ne sont pas référencées dans le code source.

---

## 📋 Résumé Exécutif

Ce rapport analyse 5 fichiers de configuration :
- `config/trading.yml`
- `config/mtf.yaml`
- `config/app/mtf_validations.yaml`
- `config/app/mtf_contracts.yaml`
- `config/app/trading_decision.yaml`

**Catégories d'analyse** :
1. ❌ **Non utilisées** : Clés/sections jamais référencées dans le code
2. ⚠️ **Utilisées indirectement** : Accès via `getConfig()` ou méthodes génériques
3. 🚧 **Non implémentées** : Sections marquées "n'est pas encore implémenté" dans les commentaires

---

## 1. 📄 `config/trading.yml`

### ❌ Sections/Clés NON UTILISÉES

#### `meta` (lignes 3-9)
- **Clés**: `name`, `description`, `created_at`
- **Statut**: Jamais référencées dans le code
- **Note**: Utilisé uniquement via `getMetaInfo()` dans `TradingConfigService` (accès indirect)

#### `symbols` (lignes 12-17)
- **Clés**: `allowed_quotes`, `blacklist`, `meta`
- **Statut**: Non utilisées
- **Note**: La blacklist est gérée via une table `blacklisted_contract` en base, pas via cette config

#### `entry` (lignes 183-196)
- **Clés complètes**:
  - `prefer_maker`
  - `fallback_taker`
  - `budget.mode`
  - `budget.fixed_usdt_if_available`
  - `quantization.price_tick`
  - `quantization.qty_step`
  - `slippage_guard_bps`
  - `spread_guard_bps`
- **Statut**: Aucune référence dans le code source

#### `integration` (lignes 199-222)
- **Clés complètes**:
  - `services.kline_provider`
  - `services.indicators.*` (ema, macd, vwap, atr, rsi)
  - `services.signal.*` (h4, h1, m15, m5, m1, mtf_coord, tie_breaker, cross_tf_tb)
  - `services.risk.position_sizer`
  - `services.logging.signal_logger`
  - `meta`
- **Statut**: Section complète non utilisée (mapping de services Symfony, probablement obsolète)

#### `logging` (lignes 225-239)
- **Clés complètes**:
  - `audit_table`
  - `metrics.pnl`
  - `metrics.expectancy`
  - `metrics.profit_factor`
  - `metrics.max_drawdown`
  - `evidence.store_condition_evidence`
  - `streams.ws_private_positions`
  - `streams.ws_private_orders`
  - `meta`
- **Statut**: Non utilisées (la configuration de logging est dans `monolog.yaml`)

#### `contract_pipeline` (lignes 168-180)
- **Clés complètes**:
  - `persist_last_result`
  - `fields_per_tf` (last_status, last_side, last_eval_ts, last_reason, retries, stale)
  - `idempotency.decision_key`
  - `meta`
- **Statut**: Non utilisées (le système utilise `decision_key` mais pas via cette config)

#### `runtime` (lignes 105-109)
- **Clés complètes**:
  - `eps`
  - `use_last_closed`
  - `meta`
- **Statut**: Non utilisées (eps est défini en dur dans les conditions)

### ⚠️ Sections/Clés UTILISÉES INDIRECTEMENT

#### `meta` (ligne 3)
- **Méthode**: `TradingConfigService::getMetaInfo()`
- **Usage**: Accès générique via `$config['meta']`

#### `mtf` (lignes 99-103)
- **Méthode**: Accès via `getConfig()['mtf']` dans plusieurs services
- **Fichiers**: `SignalValidationService.php`, `BaseTimeframeService.php`, `TpSlTwoTargetsService.php`
- **Clés utilisées**: `context`, `execution`

### 🚧 Sections NON IMPLÉMENTÉES (marquées dans les commentaires)

#### `post_validation` (lignes 242-326)
**Section complète marquée "n'est pas encore implémenté"** :

- `entry_zone.spread_bps_max` (ligne 250)
- `entry_zone.depth_min_usd` (ligne 251)
- `entry_zone.mark_index_gap_bps_max` (ligne 252)
- `execution_timeframe` (lignes 255-265) - Section complète
  - `default`
  - `upshift_to_1m.*`
  - `downshift_to_5m.*`
- `sizing` (lignes 266-271) - Section complète
  - `risk_pct`
  - `sl_mult_atr`
  - `tp_r_multiple`
  - `budget_mode`
  - `budget_usdt`
- `leverage` (lignes 273-285) - Section complète
  - `use_submit_leverage`
  - `respect_bracket`
  - `cap_pct_of_exchange`
  - `timeframe_multipliers.*`
  - `conviction.*`
- `order_plan` (lignes 287-300) - Section complète
  - `prefer_maker`
  - `maker.*`
  - `fallback_taker.*`
  - `tp_sl.*`
- `guards` (lignes 302-308) - Section complète
  - `stale_ticker_sec`
  - `max_slip_bps`
  - `min_liquidity_usd`
  - `funding_cutoff_min`
  - `max_funding_rate`
  - `mark_index_gap_bps_max`

**Note**: Seule `post_validation.entry_zone.*` (partiellement) est utilisée dans `EntryZoneCalculator.php`

---

## 2. 📄 `config/mtf.yaml`

### ❌ Sections/Clés NON UTILISÉES

**⚠️ ATTENTION**: Le fichier `mtf.yaml` n'est **PAS chargé** par aucune classe de configuration identifiée.

#### Toutes les sections sont NON UTILISÉES :

- `mtf.temporal.*` (lignes 3-7)
  - `address`
  - `namespace`
  - `task_queue`
  - `workflow_id`
- `mtf.bitmart.*` (lignes 10-16)
  - `api_key`
  - `secret_key`
  - `base_url`
  - `ws_url`
  - `timeout`
  - `max_retries`
- `mtf.rate_limiter.*` (lignes 19-22)
  - `capacity`
  - `refill_rate`
  - `refill_interval`
- `mtf.grace_window_minutes` (ligne 25)
- `mtf.max_candles_per_request` (ligne 26)
- `mtf.max_retries` (ligne 27)
- `mtf.database.*` (lignes 30-35)
  - `host`
  - `port`
  - `name`
  - `user`
  - `password`
- `mtf.cache.*` (lignes 38-40)
  - `ttl_default`
  - `ttl_validation`
- `mtf.security.*` (lignes 43-45)
  - `max_clock_drift_seconds`
  - `signature_timeout_seconds`
- `mtf.monitoring.*` (lignes 48-50)
  - `metrics_enabled`
  - `health_check_interval`

**Note**: Les variables d'environnement référencées (ex: `TEMPORAL_ADDRESS`, `BITMART_API_KEY`) sont utilisées directement via `%env()%` dans `services.yaml`, mais pas via ce fichier de configuration.

---

## 3. 📄 `config/app/mtf_validations.yaml`

### ❌ Clés NON UTILISÉES dans `defaults`

- `tick_size` (ligne 6)
- `zone_ttl_sec` (ligne 7)
- `k_low` (ligne 8)
- `k_high` (ligne 9)
- `k_stop_atr` (ligne 10)
- `tp1_size_pct` (ligne 13)
- `lev_min` (ligne 14)
- `lev_max` (ligne 15)
- `rsi_cap` (ligne 17)
- `require_pullback` (ligne 18)
- `min_volume_ratio` (ligne 19)

### ✅ Clés UTILISÉES dans `defaults`

- `allowed_execution_timeframes` → `TradingDecisionHandler.php`
- `require_price_or_atr` → `TradingDecisionHandler.php`
- `atr_k` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`, `OrderPlanBuilder.php`
- `tp1_r` → Utilisé (via `r_multiple`)
- `r_multiple` → `TradingDecisionHandler.php`
- `order_type` → `TradingDecisionHandler.php`, `OrderPlanBuilder.php`
- `open_type` → `TradingDecisionHandler.php`, `OrderPlanBuilder.php`
- `order_mode` → `TradingDecisionHandler.php`, `OrderPlanBuilder.php`
- `stop_from` → `TradingDecisionHandler.php`, `OrderPlanBuilder.php`
- `pivot_sl_policy` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`
- `pivot_sl_buffer_pct` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`
- `pivot_sl_min_keep_ratio` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`
- `market_max_spread_pct` → `TradingDecisionHandler.php`
- `inside_ticks` → `OrderPlanBuilder.php`
- `max_deviation_pct` → `TradingDecisionHandler.php`
- `implausible_pct` → `TradingDecisionHandler.php`
- `zone_max_deviation_pct` → `TradingDecisionHandler.php`
- `tp_policy` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`
- `tp_buffer_pct` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`
- `tp_buffer_ticks` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`
- `tp_min_keep_ratio` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`
- `tp_max_extra_r` → `TradingDecisionHandler.php`
- `timeframe_multipliers` → `TradingDecisionHandler.php`, `TradingParameters.php`
- `atr_pct_thresholds` → `IndicatorEngineProvider.php`, `AtrCalibrateCommand.php`
- `fallback_account_balance` → `TradingDecisionHandler.php`
- `risk_pct_percent` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`, `DynamicLeverageService.php`
- `initial_margin_usdt` → `TradingDecisionHandler.php`, `TpSlTwoTargetsService.php`, `OrderPlanBuilder.php`
- `k_dynamic` → `DynamicLeverageService.php`

### ⚠️ Clés UTILISÉES INDIRECTEMENT

- Toutes les clés de `rules.*` sont chargées dynamiquement via `ConditionRegistry` et `ConditionLoader`
- Toutes les clés de `validation.timeframe.*` sont utilisées via `MtfValidationConfig::getValidation()`

---

## 4. 📄 `config/app/mtf_contracts.yaml`

### ✅ Clés UTILISÉES

- `selection.enabled` → `MtfContractsConfig::get()`
- `selection.filters.quote_currency` → `ContractRepository.php`
- `selection.filters.status` → `ContractRepository.php`
- `selection.filters.min_turnover` → `ContractRepository.php`
- `selection.filters.mid_max_turnover` → `ContractRepository.php`
- `selection.filters.require_not_expired` → `ContractRepository.php` (ligne 306)
- `selection.filters.max_age_hours` → `ContractRepository.php` (ligne 308)
- `selection.filters.expire_unit` → `ContractRepository.php` (ligne 307)
- `selection.filters.open_unit` → `ContractRepository.php` (ligne 309)
- `selection.limits.top_n` → `MtfContractsConfig::getLimit()`
- `selection.limits.mid_n` → `MtfContractsConfig::getLimit()`
- `selection.refresh_interval_minutes` → `MtfContractsConfig::getRefreshInterval()`

### ❌ Clés NON UTILISÉES

- `selection.order.*` → Méthode `getOrder()` existe dans `MtfContractsConfig` mais jamais appelée dans le code

---

## 5. 📄 `config/app/trading_decision.yaml`

### ✅ Clés UTILISÉES

- `mtf_decision.allowed_execution_timeframes` → `TradingDecisionHandler.php` (ligne 220)
- `mtf_decision.require_price_or_atr` → `TradingDecisionHandler.php` (ligne 244)

### 🚧 Clés NON IMPLÉMENTÉES

- `mtf_decision.price_resolution.*` (lignes 9-12)
  - `atr_ratio_factor` → Commentaire: "n'est pas encore implémenté"
  - `min_allowed_diff` → Commentaire: "n'est pas encore implémenté"
  - `max_allowed_diff` → Commentaire: "n'est pas encore implémenté"

---

## 📊 Statistiques Globales

### Par Fichier

| Fichier | Total Clés | ❌ Non Utilisées | ⚠️ Indirectes | 🚧 Non Implémentées | ✅ Utilisées |
|---------|-----------|------------------|---------------|---------------------|--------------|
| `trading.yml` | ~150 | ~80 | ~10 | ~30 | ~30 |
| `mtf.yaml` | ~20 | ~20 | 0 | 0 | 0 |
| `mtf_validations.yaml` | ~60 | ~11 | ~40 | 0 | ~9 |
| `mtf_contracts.yaml` | ~12 | ~1 | 0 | 0 | ~11 |
| `trading_decision.yaml` | ~5 | 0 | 0 | ~3 | ~2 |
| **TOTAL** | **~247** | **~112** | **~50** | **~33** | **~52** |

### Par Catégorie

- ❌ **Non utilisées** : 45% des clés
- ⚠️ **Indirectes** : 20% des clés
- 🚧 **Non implémentées** : 13% des clés
- ✅ **Utilisées** : 22% des clés

---

## 🔍 Recommandations

### 1. Fichiers à Nettoyer

#### `config/mtf.yaml`
- **Action**: Supprimer ou documenter comme "réservé pour usage futur"
- **Raison**: Aucune classe ne charge ce fichier

#### `config/trading.yml`
- **Sections à supprimer**:
  - `integration` (obsolète, services Symfony gérés autrement)
  - `logging` (doublon avec `monolog.yaml`)
  - `entry` (non utilisée)
  - `contract_pipeline` (non utilisée)
  - `runtime` (non utilisée)
  - `symbols` (blacklist gérée en base)

### 2. Sections à Documenter

#### `config/trading.yml` - `post_validation`
- **Action**: Ajouter un commentaire en tête de section indiquant que seule `entry_zone.*` est partiellement implémentée
- **Action**: Marquer clairement les sous-sections non implémentées

### 3. Clés à Vérifier

#### `config/app/mtf_validations.yaml` - `defaults`
- **Action**: Vérifier si `tick_size`, `zone_ttl_sec`, `k_low`, `k_high`, `k_stop_atr`, `tp1_size_pct`, `lev_min`, `lev_max`, `rsi_cap`, `require_pullback`, `min_volume_ratio` sont vraiment obsolètes ou prévues pour usage futur

---

## 📝 Notes Techniques

### Méthodologie

1. **Recherche par grep** : Recherche de références directes aux clés dans le code source
2. **Recherche sémantique** : Utilisation de `codebase_search` pour trouver les usages indirects
3. **Analyse des classes de configuration** : Vérification des méthodes `get*()` dans les classes `*Config.php`
4. **Vérification des commentaires** : Identification des sections marquées "n'est pas encore implémenté"

### Limitations

- Les clés utilisées via `getConfig()['key']` générique sont classées comme "indirectes"
- Les clés utilisées dans des fichiers de template ou de configuration externe ne sont pas analysées
- Les clés utilisées uniquement dans des tests ne sont pas différenciées

---

**Généré le**: 2025-01-27  
**Version du rapport**: 1.0

