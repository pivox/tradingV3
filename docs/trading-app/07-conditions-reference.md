# Référence — conditions & règles MTF (profils + execution selector)

Cette page documente **tous les identifiants de conditions/règles réellement utilisés** par les profils MTF fournis dans le repo, **directement** (dans `validation`, `filters_mandatory`, `execution_selector`) ou **indirectement** (via `mtf_validation.rules`).

Profils source : `docs/trading-app/08-profils-validations.md`

## Périmètre (77 identifiants)

Les identifiants couverts ici (triés) sont ceux extraits des YAML :

`adx_5m_lt`, `adx_min_for_trend`, `adx_min_for_trend_1h`, `atr_pct_15m_gt_bps`, `atr_pct_15m_lte_bps`, `atr_rel_in_range_15m`, `atr_rel_in_range_5m`, `atr_volatility_ok`, `close_above_ema_200`, `close_above_ma_9`, `close_above_vwap`, `close_above_vwap_and_ma9`, `close_above_vwap_or_ma9`, `close_above_vwap_or_ma9_relaxed`, `close_below_ema_200`, `close_below_ma_9`, `close_below_vwap`, `close_below_vwap_or_ma9`, `close_minus_ema_200_gt`, `close_minus_ema_200_lt`, `crash_context_ok`, `crash_pullback_ready`, `crash_short_entry_1m`, `crash_short_pattern_15m`, `crash_short_pattern_1m`, `crash_short_pattern_5m`, `ema200_slope_neg`, `ema200_slope_pos`, `ema20_over_50_with_tolerance`, `ema20_over_50_with_tolerance_moderate`, `ema_20_gt_50`, `ema_20_lt_50`, `ema_20_minus_ema_50_gt`, `ema_20_slope_pos`, `ema_50_gt_200`, `ema_50_lt_200`, `ema_above_200_with_tolerance`, `ema_above_200_with_tolerance_moderate`, `ema_below_200_with_tolerance`, `end_of_zone_fallback`, `entry_zone_width_pct_gt`, `entry_zone_width_pct_lte`, `expected_r_multiple_gte`, `expected_r_multiple_lt`, `get_false`, `lev_bounds`, `ma9_cross_up_ma21`, `macd_hist_decreasing_n`, `macd_hist_gt_eps`, `macd_hist_increasing_n`, `macd_hist_lt_eps`, `macd_hist_slope_neg`, `macd_hist_slope_pos`, `macd_line_above_signal`, `macd_line_below_signal`, `macd_line_cross_down_with_hysteresis`, `macd_line_cross_up_with_hysteresis`, `near_vwap`, `price_below_ma21_plus_2atr`, `price_lte_ma21_plus_k_atr`, `price_regime_ok_long`, `price_regime_ok_short`, `pullback_confirmed`, `pullback_confirmed_ma9_21`, `pullback_confirmed_vwap`, `rsi_1m_lt_extreme`, `rsi_5m_gt_floor`, `rsi_bearish`, `rsi_bullish`, `rsi_gt_30`, `rsi_gt_softfloor`, `rsi_lt_70`, `rsi_lt_softcap`, `scalping`, `spread_bps_gt`, `trailing_after_tp1`, `volume_ratio_ok`.

## Contexte d’évaluation (3 contextes distincts)

### 1) Contexte “ConditionRegistry” (validation MTF moderne)

Construit par `trading-app/src/Indicator/Provider/IndicatorEngineProvider.php` → `buildContext()` → `trading-app/src/Indicator/Context/IndicatorContextBuilder.php`.

Clés importantes (si disponibles) :

- `symbol`, `timeframe`
- `close` (float)
- `ema` (map `period => float`, ex. `ema[20]`, `ema[50]`, `ema[200]`)
- `ema_prev` (map `period => float` sur la bougie précédente)
- `ema_200_slope` (float = `ema[200] - ema_prev[200]`)
- `rsi` (float)
- `macd` (map `{macd, signal, hist}`)
- `previous` (map partiel, ex. `previous.macd.*`)
- `macd_hist_series` (array latest‑first, jusqu’à 60 points)
- `macd_hist_last3` (array latest‑first, 3 points = slice(0..2) de `macd_hist_series`)
- `vwap` (float)
- `atr` (float)
- `volume_ratio` (float)
- `ma_21_plus_k_atr`, `ma_21_plus_1.3atr`, `ma_21_plus_1.5atr`, `ma_21_plus_2atr` (float, dérivés de MA21 + ATR)
- `adx` (map, ex. `{14: adx14, 15: adx15}`) si disponible

### 2) Indicateurs “plats” (fallback YAML historique)

Construit par `trading-app/src/Indicator/Provider/IndicatorProviderService.php` → `getIndicatorsForSymbolAndTimeframes()` :

- `close`, `rsi`, `ema_20`, `ema_50`, `ema_200`
- `macd_hist` (mais pas `macd`/`signal`)
- `vwap`, `atr`, `adx` (meta), `ma9`, `ma21`, `bb_upper`, `bb_middle`, `bb_lower`

### 3) Contexte “ExecutionSelector” (sélection 15m/5m/1m)

Construit par `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php` → `buildSelectorContext()` :

- `expected_r_multiple`
- `entry_zone_width_pct` (estimé si possible)
- `atr_pct_15m_bps` (calcul : `10000 * atr15m / price`)
- `adx_5m`, `adx_1h` (si récupérables)
- `spread_bps`, `volume_ratio` (souvent null si non alimentés)
- flags booléens : `scalping`, `trailing_after_tp1`, `end_of_zone_fallback`
- enrichissements pour des filtres : `rsi`, `vwap`, `ma` (map `[9]`, `[21]`), `ma_21_plus_*`, `leverage`, `adx_1h_min_threshold`

## Overrides (comment les YAML passent des paramètres)

### Validation (ConditionRegistry)

Dans `validation.timeframe.*`, un élément peut être :

- une string `condition_name` (pas d’override),
- ou un mapping `{condition_name: { ... }}` : le payload est **fusionné** dans le contexte au moment de l’évaluation de cette condition.

Source : `trading-app/src/MtfValidator/ConditionLoader/Cards/Validation/SideElementSimple.php`.

### Règles “rules” (ConditionRegistry)

Si une condition PHP homonyme n’existe pas, la règle nommée est évaluée via :

- `any_of` / `all_of`
- `lt_fields` / `gt_fields`
- `op` avec epsilon (comparaison de `left-right` vs `eps`)
- `increasing` / `decreasing` sur séries `field_series` ou `series[field]`

Source : `trading-app/src/MtfValidator/ConditionLoader/Cards/Rule/Rule.php`.

Limitation : `{lt: ...}` / `{gt: ...}` **n’est pas supporté** par `Rule.php`.

### filters_mandatory (attention)

- Engine YAML : les filtres sont évalués tels quels via `YamlRuleEngine::evaluate()` (donc les overrides du YAML sont pris en compte).
- Engine ConditionRegistry : `filters_mandatory` est réduit à une liste de noms **sans overrides**, puis évalué via `ConditionRegistry->evaluate($context, $names)`.

Source : `trading-app/src/MtfValidator/Service/TimeframeValidationService.php`.

### Execution selector

Le sélecteur injecte les seuils numériques sous la forme `{condition_name}_threshold` :

- ex. `expected_r_multiple_lt: 2.0` → `expected_r_multiple_lt_threshold = 2.0`

Source : `trading-app/src/MtfValidator/Execution/ExecutionSelector.php` → `injectThresholds()`.

## Référence — conditions et règles

### adx_5m_lt
Source : `trading-app/src/Indicator/Condition/Adx5mLtCondition.php`

- Entrée : `adx_5m` (float)
- Seuil : `adx_5m_lt_threshold` sinon défaut `20.0`
- Règle : `passed = adx_5m < threshold`

### adx_min_for_trend
Source : `trading-app/src/Indicator/Condition/AdxMinForTrendCondition.php`

- Entrées :
  - `timeframe` (string, optionnel)
  - `adx` (float) si `timeframe == '1h'`, sinon `adx` attendu comme map (voir remarque ci‑dessous)
- Seuil : `threshold` (prioritaire) sinon `adx_1h_min_threshold` sinon défaut constructeur `15.0`
- Règle : `passed = adx >= threshold` (ADX clampé dans `[0..100]`)
- Remarque (comportement actuel) : la condition essaie d’abord `context['adx'][$this->minAdx]` (clé float `15.0`) ; si `timeframe == '1h'`, fallback sur `context['adx']` (float).

### adx_min_for_trend_1h (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.regular.yaml` (reproduit dans `docs/trading-app/08-profils-validations.md`)

- Spécification : `op: '>='`, `left: 'adx_1h'`, `right: 20` (clé `period` présente mais non consommée par l’engine ConditionRegistry)
- Règle (ConditionRegistry) : `passed = (adx_1h - 20) >= -eps` avec `eps` par défaut `1e-8` (cf. `Rule::evaluateCustomOperation()`)
- Règle (YAML engine) : `passed = adx_1h >= 20` (avec neutralisation si `abs(diff)<eps`)
- Remarque : ni le contexte ConditionRegistry “MTF validation” ni les indicateurs plats n’exposent `adx_1h` ; il est en revanche injecté dans le contexte ExecutionSelector par `TradingDecisionHandler::buildSelectorContext()`.

### atr_pct_15m_gt_bps
Source : `trading-app/src/Indicator/Condition/AtrPct15mGtBpsCondition.php`

- Entrée : `atr_pct_15m_bps` (float)
- Seuil : `atr_pct_15m_gt_bps_threshold` sinon défaut `120.0`
- Règle : `passed = atr_pct_15m_bps > threshold`

### atr_pct_15m_lte_bps
Source : `trading-app/src/Indicator/Condition/AtrPct15mLteBpsCondition.php`

- Entrée : `atr_pct_15m_bps` (float)
- Seuil : `atr_pct_15m_lte_bps_threshold` sinon défaut `120.0`
- Règle : `passed = atr_pct_15m_bps <= threshold`

### atr_rel_in_range_15m
Source : `trading-app/src/Indicator/Condition/AtrRelInRange15mCondition.php`

- Entrées : `atr` (float), `close` (float, `>0`)
- Constantes : `MIN = 0.0010`, `MAX = 0.0250`
- Règle : `ratio = atr/close` ; `passed = MIN <= ratio <= MAX`
- Remarque : les paramètres YAML `min/max` présents dans `mtf_validation.rules.atr_rel_in_range_15m` ne sont pas consommés par la condition PHP.

### atr_rel_in_range_5m
Source : `trading-app/src/Indicator/Condition/AtrRelInRange5mCondition.php`

- Entrées : `atr` (float), `close` (float, `>0`)
- Constantes : `MIN = 0.0007`, `MAX = 0.0200`
- Règle : `ratio = atr/close` ; `passed = MIN <= ratio <= MAX`
- Remarque : les paramètres YAML `min/max` présents dans `mtf_validation.rules.atr_rel_in_range_5m` ne sont pas consommés par la condition PHP.

### atr_volatility_ok
Source : `trading-app/src/Indicator/Condition/AtrVolatilityOkCondition.php`

- Entrées : `atr` (float), `close` (float)
- Seuils : `min_atr_pct` (défaut `0.001`), `max_atr_pct` (défaut `0.03`)
- Règle : `ratio = atr/close` ; `passed = min_atr_pct <= ratio <= max_atr_pct`
- Cas invalides : si `min_atr_pct<=0` ou `max_atr_pct<=0` ou `min_atr_pct>=max_atr_pct` → `passed=false` (`meta.invalid_thresholds=true`)

### close_above_ema_200
Source : `trading-app/src/Indicator/Condition/CloseAboveEma200Condition.php`

- Entrées : `close` (float), `ema[200]` (float)
- Règle : `passed = close > ema200`

### close_above_ma_9
Source : `trading-app/src/Indicator/Condition/CloseAboveMa9Condition.php`

- Entrées : `close` (float), `ema[9]` (float)
- Règle : `passed = close > ema9`
- Valeur retournée : `(close/ema9)-1` (fallback diff si `ema9` quasi nul)

### close_above_vwap
Source : `trading-app/src/Indicator/Condition/CloseAboveVwapCondition.php`

- Entrées : `close` (float), `vwap` (float)
- Règle : `passed = close > vwap`
- Valeur retournée : `(close-vwap)/vwap` (si `vwap != 0`)

### close_above_vwap_and_ma9 (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.*.yaml` (reproduit dans `docs/trading-app/08-profils-validations.md`)

- Définition : `all_of: [close_above_vwap, close_above_ma_9]`
- Règle : `passed = close_above_vwap AND close_above_ma_9`

### close_above_vwap_or_ma9
Source : `trading-app/src/Indicator/Condition/CloseAboveVwapOrMa9Condition.php`

- Entrées : `close` (float), et au moins un de `vwap` (float) / `ema[9]` (float)
- Règle : `passed = (close > vwap) OR (close > ema9)`
- Valeur retournée : ratio vs `vwap` si dispo, sinon vs `ema9`

### close_above_vwap_or_ma9_relaxed (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.scalper.yaml` (reproduit dans `docs/trading-app/08-profils-validations.md`)

- Définition :
  - `any_of` entre :
    - `close_above_vwap_or_ma9`
    - `all_of: [atr_rel_in_range_5m, near_vwap: { near_vwap_tolerance: 0.0040 }]`

### close_below_ema_200
Source : `trading-app/src/Indicator/Condition/CloseBelowEma200Condition.php`

- Entrées : `close` (float), `ema[200]` (float)
- Règle : `passed = close < ema200`

### close_below_ma_9
Source : `trading-app/src/Indicator/Condition/CloseBelowMa9Condition.php`

- Entrées : `close` (float), `ema[9]` (float)
- Règle : `passed = close < ema9`
- Valeur retournée : `(ema9-close)/ema9` (avec fallback `1.0` si `ema9` quasi nul)

### close_below_vwap
Source : `trading-app/src/Indicator/Condition/CloseBelowVwapCondition.php`

- Entrées : `close` (float), `vwap` (float)
- Règle : `passed = close < vwap`
- Valeur retournée : `(close-vwap)/vwap` (négatif si sous VWAP, si `vwap != 0`)

### close_below_vwap_or_ma9 (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.scalper_micro.yaml` (reproduit dans `docs/trading-app/08-profils-validations.md`)

- Définition : `any_of: [close_below_vwap, close_below_ma_9]`
- Règle : `passed = close_below_vwap OR close_below_ma_9`

### close_minus_ema_200_gt
Source : `trading-app/src/Indicator/Condition/CloseMinusEma200GtCondition.php`

- Entrées : `close` (float), `ema[200]` (float, `!=0`)
- Seuil : `threshold` (float) sinon défaut `0.0`
- Règle : `ratio = (close/ema200)-1` ; `passed = ratio > threshold`

### close_minus_ema_200_lt
Source : `trading-app/src/Indicator/Condition/CloseMinusEma200LtCondition.php`

- Entrées : `close` (float), `ema[200]` (float, `!=0`)
- Seuil : `threshold` (float) sinon défaut `0.0`
- Règle : `ratio = (close/ema200)-1` ; `passed = ratio < threshold`

### crash_context_ok (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.crash.yaml` (reproduit dans `docs/trading-app/08-profils-validations.md`)

- Définition : `all_of: [price_regime_ok_short, ema200_slope_neg, macd_hist_decreasing_n, adx_min_for_trend]`

### crash_pullback_ready (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.crash.yaml`

- Définition : `any_of: [ma9_cross_up_ma21, near_vwap: true]`

### crash_short_entry_1m (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.crash.yaml`

- Définition : `all_of: [crash_short_pattern_1m, crash_pullback_ready]`

### crash_short_pattern_15m (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.crash.yaml`

- Définition : `all_of: [ema_20_lt_50, close_below_vwap, macd_hist_decreasing_n, atr_rel_in_range_15m]`

### crash_short_pattern_5m (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.crash.yaml`

- Définition : `all_of: [ema_20_lt_50, close_below_vwap, macd_hist_decreasing_n, atr_rel_in_range_5m, volume_ratio_ok, rsi_5m_gt_floor]`

### crash_short_pattern_1m (règle “rules”)
Source : `trading-app/src/MtfValidator/config/validations.crash.yaml`

- Définition : `all_of: [macd_hist_decreasing_n, close_below_vwap, atr_rel_in_range_5m, volume_ratio_ok, rsi_1m_lt_extreme]`

### ema200_slope_neg
Source : `trading-app/src/Indicator/Condition/Ema200SlopeNegCondition.php`

- Entrée : `ema_200_slope` (float)
- Règle : `passed = ema_200_slope < 0`

### ema200_slope_pos
Source : `trading-app/src/Indicator/Condition/Ema200SlopePosCondition.php`

- Entrée : `ema_200_slope` (float)
- Règle : `passed = ema_200_slope > 0`

### ema20_over_50_with_tolerance
Source : `trading-app/src/Indicator/Condition/Ema20Over50WithToleranceCondition.php`

- Entrées : `ema[20]` (float), `ema[50]` (float)
- Tolérance : `ema20_over_50_tolerance` sinon défaut `0.0008` (0.08%)
- Règle : `base = (ema20/ema50)-1` (fallback `ema20-ema50` si `ema50==0`) ; `passed = base >= -tolerance`

### ema20_over_50_with_tolerance_moderate
Source : `trading-app/src/Indicator/Condition/Ema20Over50WithToleranceModerateCondition.php`

- Entrées : `ema[20]` (float), `ema[50]` (float)
- Tolérance : `ema20_over_50_tolerance_moderate` sinon défaut `0.0012` (0.12%)
- Règle : `base = (ema20/ema50)-1` ; `passed = base >= -tolerance`

### ema_20_gt_50
Source : `trading-app/src/Indicator/Condition/Ema20Gt50Condition.php`

- Entrées : `ema[20]` (float), `ema[50]` (float)
- Règle : `passed = ema20 > ema50`

### ema_20_lt_50
Source : `trading-app/src/Indicator/Condition/Ema20Lt50Condition.php`

- Entrées : `ema[20]` (float), `ema[50]` (float)
- Règle : `passed = ema20 < ema50`

### ema_20_minus_ema_50_gt
Source : `trading-app/src/Indicator/Condition/Ema20MinusEma50GtCondition.php`

- Entrées : `ema[20]` (float), `ema[50]` (float, `!=0`)
- Seuil : `threshold` (float) sinon défaut `0.0`
- Règle : `ratio = (ema20/ema50)-1` ; `passed = ratio > threshold`

### ema_20_slope_pos
Source : `trading-app/src/Indicator/Condition/Ema20SlopePosCondition.php`

- Entrées : `ema[20]` (float), `ema_prev[20]` (float)
- Règle : `slope = ema20 - ema20_prev` ; `passed = slope > 0`

### ema_50_gt_200
Source : `trading-app/src/Indicator/Condition/Ema50Gt200Condition.php`

- Entrées : `ema[50]` (float), `ema[200]` (float)
- Règle : `passed = ema50 > ema200`

### ema_50_lt_200
Source : `trading-app/src/Indicator/Condition/Ema50Lt200Condition.php`

- Entrées : `ema[50]` (float), `ema[200]` (float)
- Règle : `passed = ema50 < ema200`

### ema_above_200_with_tolerance
Source : `trading-app/src/Indicator/Condition/EmaAbove200WithToleranceCondition.php`

- Entrées : `close` (float), `ema[200]` (float, `!=0`)
- Tolérance : `ema200_bull_tolerance` sinon défaut `0.0015` (0.15%)
- Règle : `ratio = (close/ema200)-1` ; `passed = ratio >= -tolerance`

### ema_above_200_with_tolerance_moderate
Source : `trading-app/src/Indicator/Condition/EmaAbove200WithToleranceModerateCondition.php`

- Entrées : `close` (float), `ema[200]` (float)
- Tolérance : `ema_above_200_tolerance_moderate` sinon défaut `0.0020` (0.20%)
- Règle : `base = (close/ema200)-1` (fallback diff si `ema200==0`) ; `passed = base >= -tolerance`

### ema_below_200_with_tolerance
Source : `trading-app/src/Indicator/Condition/EmaBelow200WithToleranceCondition.php`

- Entrées : `close` (float), `ema[200]` (float, `!=0`)
- Tolérance : `ema200_bear_tolerance` sinon défaut `0.0015` (0.15%)
- Règle : `ratio = (close/ema200)-1` ; `passed = ratio <= +tolerance`

### end_of_zone_fallback
Source : `trading-app/src/Indicator/Condition/EndOfZoneFallbackCondition.php`

- Entrée : `end_of_zone_fallback` (bool)
- Règle : `passed = end_of_zone_fallback` (sinon `missing_data`)

### entry_zone_width_pct_gt
Source : `trading-app/src/Indicator/Condition/EntryZoneWidthPctGtCondition.php`

- Entrée : `entry_zone_width_pct` (float, en %)
- Seuil : `entry_zone_width_pct_gt_threshold` sinon défaut `1.2`
- Règle : `passed = entry_zone_width_pct > threshold`

### entry_zone_width_pct_lte
Source : `trading-app/src/Indicator/Condition/EntryZoneWidthPctLteCondition.php`

- Entrée : `entry_zone_width_pct` (float, en %)
- Seuil : `entry_zone_width_pct_lte_threshold` sinon défaut `1.2`
- Règle : `passed = entry_zone_width_pct <= threshold`

### expected_r_multiple_gte
Source : `trading-app/src/Indicator/Condition/ExpectedRMultipleGteCondition.php`

- Entrée : `expected_r_multiple` (float)
- Seuil : `expected_r_multiple_gte_threshold` sinon défaut `2.0`
- Règle : `passed = expected_r_multiple >= threshold`

### expected_r_multiple_lt
Source : `trading-app/src/Indicator/Condition/ExpectedRMultipleLtCondition.php`

- Entrée : `expected_r_multiple` (float)
- Seuil : `expected_r_multiple_lt_threshold` sinon défaut `2.0`
- Règle : `passed = expected_r_multiple < threshold`

### get_false
Source : `trading-app/src/Indicator/Condition/GetFalseCondition.php`

- Règle : renvoie systématiquement `passed=false`

### lev_bounds
Source : `trading-app/src/Indicator/Condition/LevBoundsCondition.php`

- Entrée : `leverage` (float)
- Bornes : constructeur `min=2.0`, `max=20.0`
- Règle : `passed = (min <= leverage <= max)`
- Remarque : les `min/max` déclarés dans YAML (`mtf_validation.rules.lev_bounds`) ne sont pas consommés par cette condition PHP.

### ma9_cross_up_ma21
Source : `trading-app/src/Indicator/Condition/Ma9CrossUpMa21Condition.php`

- Entrées : `ema[9]`, `ema[21]`, `ema_prev[9]`, `ema_prev[21]` (tous float)
- Règle : `passed = (ema9_prev <= ema21_prev) AND (ema9 > ema21)`

### macd_hist_decreasing_n
Source : `trading-app/src/Indicator/Condition/MacdHistDecreasingNCondition.php`

- Entrée : `macd_hist_series` (array de valeurs numériques)
- Paramètres :
  - `n` ou `macd_hist_decreasing_n` (défaut `2`, borné `[1..50]`)
  - `eps` ou `macd_hist_decreasing_eps` (défaut `0.0`)
  - `series_order` ou `macd_hist_series_order` (défaut `'latest_first'`)
- Règle (series normalisée latest‑first) : pour `i in [0..n-1]`, `delta = series[i] - series[i+1]` doit vérifier `delta < -eps`
- Valeur retournée : `avg_step = (series[0] - series[n]) / n` ; seuil retourné = `eps`

### macd_hist_gt_eps
Source : `trading-app/src/Indicator/Condition/MacdHistGtEpsCondition.php`

- Entrée : `macd.hist` (float)
- Paramètre : `eps` (défaut `1e-6`, valeur absolue si négatif)
- Règle : `passed = macd_hist >= (0 - eps)`

### macd_hist_increasing_n
Source : `trading-app/src/Indicator/Condition/MacdHistIncreasingNCondition.php`

- Entrée : `macd_hist_last3` (array)
- Paramètre : `macd_hist_increasing_n` (défaut `2`)
- Règle : compte des hausses consécutives sur l’ordre du tableau fourni ; `passed = increases >= required`
- Remarque : `IndicatorContextBuilder` remplit `macd_hist_last3` en **latest‑first**.

### macd_hist_lt_eps
Source : `trading-app/src/Indicator/Condition/MacdHistLtEpsCondition.php`

- Entrée : `macd.hist` (float)
- Paramètre : `eps` (défaut `1e-6`, valeur absolue si négatif)
- Règle : `passed = macd_hist <= (0 + eps)`

### macd_hist_slope_neg
Source : `trading-app/src/Indicator/Condition/MacdHistSlopeNegCondition.php`

- Entrée : `macd_hist_last3` (array, au moins 2 valeurs)
- Règle : `slope = last - prev` (sur les 2 dernières entrées du tableau) ; `passed = slope < 0`
- Remarque : `macd_hist_last3` est latest‑first (voir ci‑dessus).

### macd_hist_slope_pos
Source : `trading-app/src/Indicator/Condition/MacdHistSlopePosCondition.php`

- Entrée : `macd_hist_last3` (array, au moins 2 valeurs)
- Règle : `slope = last - prev` (sur les 2 dernières entrées du tableau) ; `passed = slope > 0`
- Remarque : `macd_hist_last3` est latest‑first (voir ci‑dessus).

### macd_line_above_signal
Source : `trading-app/src/Indicator/Condition/MacdLineAboveSignalCondition.php`

- Entrées : `macd.macd` (float), `macd.signal` (float)
- Règle : `diff = macd - signal` ; `passed = diff > 0`

### macd_line_below_signal
Source : `trading-app/src/Indicator/Condition/MacdLineBelowSignalCondition.php`

- Entrées : `macd.macd` (float), `macd.signal` (float)
- Règle : `diff = macd - signal` ; `passed = diff < 0`

### macd_line_cross_down_with_hysteresis
Source : `trading-app/src/Indicator/Condition/MacdLineCrossDownWithHysteresisCondition.php`

- Entrée : `macd_hist_last3` (array, au moins 2 valeurs)
- Paramètres : `min_gap` (défaut `0.0003`), `cool_down_bars` (défaut `2`), `require_prev_above` (défaut `true`)
- Règle : recherche sur `off in [0..min(cool_down_bars,n-2)]` un point où :
  - `curr <= -min_gap`
  - et (si `require_prev_above`) `prev >= +min_gap`

### macd_line_cross_up_with_hysteresis
Source : `trading-app/src/Indicator/Condition/MacdLineCrossUpWithHysteresisCondition.php`

- Entrée : `macd_hist_last3` (array, au moins 2 valeurs)
- Paramètres : `min_gap` (défaut `0.0003`), `cool_down_bars` (défaut `2`), `require_prev_below` (défaut `true`)
- Règle : recherche sur `off in [0..min(cool_down_bars,n-2)]` un point où :
  - `curr >= +min_gap`
  - et (si `require_prev_below`) `prev <= -min_gap`

### near_vwap
Source : `trading-app/src/Indicator/Condition/NearVwapCondition.php`

- Entrées : `close` (float), `vwap` (float, `!=0`)
- Tolérance : `near_vwap_tolerance` sinon défaut `0.0015`
- Règle : `ratio = abs((close/vwap)-1)` ; `passed = ratio <= tolerance`

### price_below_ma21_plus_2atr
Source : `trading-app/src/Indicator/Condition/PriceBelowMa21Plus2AtrCondition.php`

- Entrées : `close` (float), `ema[21]` (float), `atr` (float)
- Règle : `threshold = ema21 + 2*atr` ; `passed = close < threshold`
- Remarque : le flag YAML `allow_touch` n’est pas consommé par cette condition PHP.

### price_lte_ma21_plus_k_atr
Source : `trading-app/src/Indicator/Condition/PriceLteMa21PlusKAtrCondition.php`

- Entrées : `close` (float) et un des niveaux (float) :
  - `ma_21_plus_k_atr` (prioritaire) sinon `ma_21_plus_1.5atr` / `ma_21_plus_1.3atr` / `ma_21_plus_2atr`
- Constante : `EPS = 1e-8`
- Règle : `passed = close <= level * (1 + EPS)`
- Cas missing data :
  - si `timeframe == '1h'` → `passed=true` (soft‑pass)
  - sinon → `passed=false`

### price_regime_ok_long
Source : `trading-app/src/Indicator/Condition/PriceRegimeOkLongCondition.php`

- Entrées : `close`, `ema[50]`, `ema[200]`, `adx` (float ou `adx[14]`)
- Constantes : `adxMin=20.0`, `eps=1e-9`
- Règle : `passed = (close > ema200) OR (close > ema50 AND adx >= 20)`
- Attention : en cas de données manquantes, la condition exécute un `dd(...)` (arrêt d’exécution).

### price_regime_ok_short
Source : `trading-app/src/Indicator/Condition/PriceRegimeOkShortCondition.php`

- Entrées : `close`, `ema[50]`, `ema[200]`, `adx` (float ou `adx[14]`)
- Constantes : `adxMin=20.0`, `eps=1e-9`
- Règle : `passed = (close < ema200) OR (close < ema50 AND adx >= 20)`

### pullback_confirmed
Source : `trading-app/src/Indicator/Condition/PullbackConfirmedCondition.php`

- Entrées : `close` (float), `ema[21]` (float), `macd_hist_last3` (array, au moins 3 valeurs)
- Règle :
  - `vShape = (b < a) AND (c > b)` sur les 3 valeurs extraites (dans l’ordre du tableau fourni)
  - `aboveEma = close > ema21`
  - `passed = vShape AND aboveEma`

### pullback_confirmed_ma9_21
Source : `trading-app/src/Indicator/Condition/PullbackConfirmedMa921Condition.php`

- Entrées : `close` (float), `ma9` (= `ma[9]` ou `ema[9]`), `ma21` (= `ma[21]` ou `ema[21]`)
- Constante : `RATIO_EPS = 0.0005`
- Règle :
  - `aboveMa21 = close >= ma21*(1-RATIO_EPS)`
  - `ma9Above = ma9 >= ma21*(1-RATIO_EPS)`
  - `passed = aboveMa21 AND ma9Above`

### pullback_confirmed_vwap
Source : `trading-app/src/Indicator/Condition/PullbackConfirmedVwapCondition.php`

- Entrées : `close` (float), `vwap` (float, `>0`)
- Constante : `MAX_DIST_RATIO = 0.003`
- Règle :
  - `aboveVwap = close > vwap`
  - `dist = abs(close-vwap)/vwap`
  - `near = dist <= MAX_DIST_RATIO`
  - `passed = aboveVwap AND near`

### rsi_1m_lt_extreme (règle YAML scalaire)
Source : `trading-app/src/MtfValidator/config/validations.crash.yaml`

- Définition : `{ lt: 10 }`
- Règle (YAML engine) : `passed = rsi < 10` (champ par défaut `rsi`)
- Limitation : `Rule.php` (ConditionRegistry) ne supporte pas `lt` → cette règle “rules‑only” provoque une exception si évaluée via ConditionRegistry.

### rsi_5m_gt_floor (règle YAML scalaire)
Source : `trading-app/src/MtfValidator/config/validations.crash.yaml`

- Définition : `{ gt: 20 }`
- Règle (YAML engine) : `passed = rsi > 20` (champ par défaut `rsi`)
- Limitation : `Rule.php` (ConditionRegistry) ne supporte pas `gt` → exception si évaluée via ConditionRegistry.

### rsi_bearish
Source : `trading-app/src/Indicator/Condition/RsiBearishCondition.php`

- Entrée : `rsi` (float)
- Seuil : `threshold` ou `rsi_bearish_threshold` sinon défaut `48.0`
- Règle : `passed = rsi < threshold`

### rsi_bullish
Source : `trading-app/src/Indicator/Condition/RsiBullishCondition.php`

- Entrée : `rsi` (float)
- Seuil : `threshold` ou `rsi_bullish_threshold` sinon défaut `52.0`
- Spécifique : si `timeframe == '5m'` et aucun override, le seuil devient `49.0`
- Règle : `passed = rsi > threshold`

### rsi_gt_30
Source : `trading-app/src/Indicator/Condition/RsiGt30Condition.php`

- Entrée : `rsi` (float)
- Constante : seuil `30.0`
- Règle : `passed = rsi > 30`

### rsi_gt_softfloor
Source : `trading-app/src/Indicator/Condition/RsiGtSoftfloorCondition.php`

- Entrée : `rsi` (float)
- Seuil : `rsi_softfloor_threshold` sinon défaut `22.0`
- Règle : `passed = rsi > threshold`

### rsi_lt_70
Source : `trading-app/src/Indicator/Condition/RsiLt70Condition.php`

- Entrée : `rsi` (float)
- Seuil : `rsi_lt_70_threshold` sinon défaut `70.0`
- Règle : `passed = rsi < threshold`
- Remarque : cette condition ne lit pas la clé `threshold` (un override YAML `{rsi_lt_70: 72}` n’a pas d’effet).

### rsi_lt_softcap
Source : `trading-app/src/Indicator/Condition/RsiLtSoftcapCondition.php`

- Entrée : `rsi` (float)
- Seuil : `rsi_softcap_threshold` sinon défaut `78.0`
- Règle : `passed = rsi < threshold`

### scalping
Source : `trading-app/src/Indicator/Condition/ScalpingCondition.php`

- Entrée : `scalping` (bool)
- Règle : `passed = scalping` (sinon `missing_data`)

### spread_bps_gt
Source : `trading-app/src/Indicator/Condition/SpreadBpsGtCondition.php`

- Entrée : `spread_bps` (float)
- Seuil : `spread_bps_gt_threshold` sinon défaut `8.0`
- Règle : `passed = spread_bps > threshold`

### trailing_after_tp1
Source : `trading-app/src/Indicator/Condition/TrailingAfterTp1Condition.php`

- Entrée : `trailing_after_tp1` (bool)
- Règle : `passed = trailing_after_tp1` (sinon `missing_data`)

### volume_ratio_ok
Source : `trading-app/src/Indicator/Condition/VolumeRatioOkCondition.php`

- Entrée : `volume_ratio` (numeric)
- Seuil : `volume_ratio_ok_threshold` ou `volume_ratio_threshold` sinon défaut `1.4`
- Constante : `EPS = 1e-9`
- Règle : `passed = (volume_ratio + EPS) >= threshold`

