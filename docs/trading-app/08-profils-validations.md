# Profils MTF — spécification fonctionnelle exhaustive

Ce document décrit les **profils MTF** (Multi‑TimeFrame) utilisés par `trading-app` et fournit une **spécification exhaustive** (valeurs, conditions, seuils, overrides) en reprenant la configuration YAML.

Fichiers source (chargés dynamiquement) :

- `trading-app/src/MtfValidator/config/validations.scalper_micro.yaml`
- `trading-app/src/MtfValidator/config/validations.scalper.yaml`
- `trading-app/src/MtfValidator/config/validations.regular.yaml`
- `trading-app/src/MtfValidator/config/validations.crash.yaml`

Chargement :

- Résolution du fichier : `trading-app/src/Config/MtfValidationConfigProvider.php`
- Cache par `version:` : `trading-app/src/Config/MtfValidationConfig.php`

## Schéma fonctionnel (clés du YAML)

Dans chaque fichier, le bloc racine attendu est :

- `version` : version sémantique “fonctionnelle” du profil (sert au refresh du cache).
- `mtf_validation` :
  - `mode` : logique de consolidation du contexte (cf. `ContextValidationService::evaluateContext()`).
  - `context_timeframes` : timeframes qui constituent le contexte (phase “context”).
  - `execution_timeframes` : timeframes candidats pour exécuter (phase “execution”).
  - `execution_timeframe_default` : fallback quand l’execution selector ne tranche pas.
  - `allow_skip_lower_tf` : bool de profil (présent dans YAML ; l’implémentation effective dépend du moteur d’exécution, voir ci‑dessous).
  - `rules` : dictionnaire de règles nommées (atomiques/composites) réutilisables par `validation.*`.
  - `filters_mandatory` : liste de filtres “veto global” appliqués après résolution long/short d’un timeframe.
  - `execution_selector` : règles de sélection du timeframe final (15m/5m/1m) une fois le contexte validé.
  - `validation.timeframe.<tf>.<side>` : scénarios (liste) ; un `timeframe+side` est validé si **au moins un** scénario passe (OR), chaque scénario étant un arbre `all_of`/`any_of`.

## Moteurs d’évaluation (fonctionnement réel)

`trading-app` peut valider un timeframe via :

- **Engine ConditionRegistry (prioritaire)** : `trading-app/src/MtfValidator/Service/TimeframeValidationService.php` → `validateWithConditionRegistry()`.
  - `validation.*` : chaque élément (string/dict `name: override`) est évalué via `ConditionLoader` :
    - si une condition PHP existe (services `app.indicator.condition`), elle est utilisée en priorité ;
    - sinon, si une règle est définie dans `mtf_validation.rules`, elle est évaluée via `ConditionLoader/Cards/Rule/Rule.php`.
  - **Limitation importante** : le langage de règles `Rule.php` ne supporte pas `{ lt: ... }` / `{ gt: ... }`.
    - Conséquence : toute règle “rules‑only” (sans condition PHP homonyme) écrite avec `lt/gt` déclenche une exception, et le service retombe sur l’engine YAML (si possible).
  - **filters_mandatory** : l’implémentation ConditionRegistry ne propage pas les overrides YAML des filtres :
    - `filters_mandatory` est réduit à une liste de noms, puis évalué via `ConditionRegistry->evaluate($context, $names)`.
    - Donc un item du type `- rsi_lt_70: { rsi_lt_70_threshold: 73 }` **n’applique pas l’override** dans cet engine (comportement actuel).
    - Source : `TimeframeValidationService::validateWithConditionRegistry()` (construction de `$filterNames` sans overrides).

- **Engine YAML (fallback historique)** : `trading-app/src/MtfValidator/Service/Rule/YamlRuleEngine.php`.
  - Supporte `all_of` / `any_of`, `lt_fields` / `gt_fields`, `op(left/right/eps)` et les comparaisons scalaires `{lt: ...}/{gt: ...}` (champ par défaut `rsi`).
  - Limitation importante : de nombreux “primitifs” (ex. `near_vwap`, `increasing`, `decreasing`, `derivative_gt/lt`, `slope_left`, etc.) ne sont pas implémentés et le moteur retourne `true` (“ne pas bloquer”).
  - Risque de récursion : une règle nommée qui se redéfinit en `{ rule_name: true }` est interprétée comme un alias de lui‑même et peut provoquer une récursion infinie si ce bloc est effectivement évalué.
    - Source : `YamlRuleEngine::evaluatePrimitiveBlock()` (branche “alias avec override”).

## Profil — SCALPER micro (micro‑structure)

Spécification exhaustive : `trading-app/src/MtfValidator/config/validations.scalper_micro.yaml`

```yaml
# =====================================================================
# validations.scalper_micro.yaml – Profil SCALPER micro-structure only
# Version C : Bias 5m + timing 1m, long/short symétriques
# =====================================================================
version: '1.1.3-micro-macd-eps'

mtf_validation:
    mode: 'pragmatic'
    context_timeframes: ['5m']
    execution_timeframes: ['1m']
    execution_timeframe_default: '1m'
    allow_skip_lower_tf: false

    defaults:
        dry_run_validate_all_timeframes: false

    # ================================
    # FILTRES MANDATOIRES (MICRO ONLY)
    # ================================
    filters_mandatory: []
    # Garder vide : préférer des filtres intégrés aux scénarios (plus faciles à ajuster)

    # ================================
    # RÈGLES ATOMIQUES
    # ================================
    rules:
        # IMPORTANT : ce bloc reste nécessaire pour les combinaisons (any_of/all_of)
        # utilisées à la fois par l'engine ConditionRegistry et par le fallback YAML.

        # ====== VWAP / MA9 (structure) ======
        close_above_vwap:
            gt_fields: ['close', 'vwap']

        close_below_vwap:
            lt_fields: ['close', 'vwap']

        close_above_ma_9:
            gt_fields: ['close', 'ma_9']

        close_below_ma_9:
            lt_fields: ['close', 'ma_9']

        close_above_vwap_or_ma9:
            any_of: [close_above_vwap, close_above_ma_9]

        close_below_vwap_or_ma9:
            any_of: [close_below_vwap, close_below_ma_9]

        near_vwap:
            near_vwap: true

    # ================================
    # VALIDATION PAR TIMEFRAME
    # ================================
    validation:

        start_from_timeframe: '5m'

        timeframe:

            # -------------------------
            # 5m : CONTEXTE LOCAL
            # -------------------------
            '5m':
                long:
                    - all_of:
                          - ema20_over_50_with_tolerance_moderate
                          - close_above_vwap_or_ma9
                          - macd_hist_gt_eps: { eps: 1.0e-12 }
                          - rsi_bullish
                          - rsi_lt_70
                          - atr_volatility_ok: { min_atr_pct: 0.0005, max_atr_pct: 0.03 }
                short:
                    - all_of:
                          - ema_20_lt_50
                          - close_below_vwap_or_ma9
                          - macd_hist_lt_eps: { eps: 0.005 }
                          - rsi_bearish
                          - rsi_gt_30
                          - atr_volatility_ok: { min_atr_pct: 0.0005, max_atr_pct: 0.03 }

            # -------------------------
            # 1m : TIMING / EXECUTION
            # -------------------------
            '1m':
                long:
                    - all_of:
                          - ema20_over_50_with_tolerance_moderate
                          - near_vwap: { near_vwap_tolerance: 0.0012 }   # 0.12 %
                          - close_above_vwap_or_ma9
                          - macd_hist_gt_eps: { eps: 1.0e-12 }
                          - macd_hist_increasing_n: { macd_hist_increasing_n: 1 }
                          - rsi_bullish
                          - rsi_lt_70
                          - atr_volatility_ok: { min_atr_pct: 0.00035, max_atr_pct: 0.03 }

                short:
                    - all_of:
                          - ema_20_lt_50
                          - near_vwap: { near_vwap_tolerance: 0.0012 }   # 0.12 %
                          - close_below_vwap_or_ma9
                          - macd_hist_lt_eps: { eps: 0.001 }
                          - macd_hist_decreasing_n: { n: 1, eps: 0.001 }
                          - rsi_bearish
                          - rsi_gt_30
                          - atr_volatility_ok: { min_atr_pct: 0.00035, max_atr_pct: 0.03 }
```

## Profil — SCALPER (agressif)

Spécification exhaustive : `trading-app/src/MtfValidator/config/validations.scalper.yaml`

```yaml
# =====================================================================
# validations.scalper.yaml – Profil SCALPER agressif (MTF 1h→15m→5m/1m)
# =====================================================================
version: '0.1.1pragmatic'   # Version du profil de validation MTF pour le scalper

mtf_validation:
    mode: 'ultra-pragmatig'               # Pas d'unanimité nécessaire entre TF + skip TF 1m si 5m valid
    context_timeframes: ['1h', '15m']     # Timeframes de contexte (tendance + momentum)
    execution_timeframes: ['5m', '1m']    # Timeframes d’exécution (scalping rapide)
    execution_timeframe_default: '5m'     # TF d’exécution par défaut
    allow_skip_lower_tf: true             # Autorise de sauter un TF bas si bruit excessif

    defaults:
        dry_run_validate_all_timeframes: false   # Pas de validation exhaustive en dry-run

    # ================================
    # RÈGLES ATOMIQUES
    # ================================
    rules:

        ema_20_gt_50:
            gt_fields: [ 'ema_20', 'ema_50' ]   # EMA20 > EMA50 → structure haussière court terme

        # ====== EMA 20 slope ======
        ema_20_slope_pos:
            slope_left: 'ema_20'                # Pente EMA20 positive
            slope_lookback: 8                   # Sur 8 bougies
            op: '>'
            right: 0.0
            eps: 1e-12

        macd_hist_increasing_n:
            increasing: { field: 'macd_hist', n: 2 }

        macd_hist_decreasing_n:
            decreasing: { field: 'macd_hist', n: 2 }

        # ====== EMA 200 structure long terme ======
        close_above_ema_200:
            gt_fields: [ 'close', 'ema_200' ]

        close_below_ema_200:
            lt_fields: [ 'close', 'ema_200' ]

        # ====== MOMENTUM (MACD / dérivées) ======
        macd_hist_gt_eps:
            op: '>'
            left: 'macd_hist'
            right: 0.0
            eps: 1e-6

        macd_hist_slope_pos:
            derivative_gt: 0.0
            persist_n: 1

        macd_hist_slope_neg:
            derivative_lt: 0.0
            persist_n: 1

        macd_line_cross_up_with_hysteresis:
            require_prev_below: true
            min_gap: 0.0006
            cool_down_bars: 2

        macd_line_cross_down_with_hysteresis:
            require_prev_above: true
            min_gap: 0.0012
            cool_down_bars: 3

        macd_line_above_signal:
            gt_fields: [ 'macd', 'macd_signal' ]

        macd_line_below_signal:
            lt_fields: [ 'macd', 'macd_signal' ]

        # ====== EMA STRUCTURE COMPOSÉE ======
        ema20_over_50_with_tolerance:
            any_of:
                - ema_20_gt_50
                - ema_20_minus_ema_50_gt: -0.0010
                - ema_20_slope_pos

        ema20_over_50_with_tolerance_moderate:
            any_of:
                - ema_20_gt_50
                - ema_20_minus_ema_50_gt: -0.0015
                - ema_20_slope_pos

        ema_above_200_with_tolerance:
            any_of:
                - close_above_ema_200
                - close_minus_ema_200_gt: -0.0030
                - ema200_slope_pos

        ema_above_200_with_tolerance_moderate:
            any_of:
                - close_above_ema_200
                - close_minus_ema_200_gt: -0.0025
                - ema200_slope_pos

        ema_below_200_with_tolerance:
            any_of:
                - close_below_ema_200
                - close_minus_ema_200_lt: -0.0030
                - ema200_slope_neg

        ema200_slope_pos:
            slope_left: 'ema_200'
            slope_lookback: 13
            op: '>'
            right: 0.0
            eps: 1e-12

        ema200_slope_neg:
            slope_left: 'ema_200'
            slope_lookback: 21
            op: '<'
            right: 0.0
            eps: 1e-12

        # ====== RSI / ANTI-EXTENSION ======
        rsi_lt_70:
            lt: 72

        rsi_lt_softcap:
            lt: 82

        rsi_gt_softfloor:
            gt: 20

        # ====== VWAP / PULLBACK ======
        close_above_vwap:
            gt_fields: [ 'close', 'vwap' ]

        close_above_ma_9:
            gt_fields: [ 'close', 'ma_9' ]

        ma9_cross_up_ma21:
            ma9_cross_up_ma21: true

        near_vwap:
            near_vwap: true

        close_above_vwap_or_ma9:
            any_of: [ close_above_vwap, close_above_ma_9 ]

        close_above_vwap_and_ma9:
            all_of: [ close_above_vwap, close_above_ma_9 ]

        close_above_vwap_or_ma9_relaxed:
            any_of:
                - close_above_vwap_or_ma9
                - all_of:
                    - atr_rel_in_range_5m
                    - near_vwap: { near_vwap_tolerance: 0.0040 }

        pullback_confirmed:
            any_of:
                - ma9_cross_up_ma21
                - near_vwap: true
            validity_bars: 3

        # ====== PRIX / ATR / EXTENSIONS ======
        price_lte_ma21_plus_k_atr:
            lt_fields: [ 'close', 'ma_21_plus_1.3atr' ]

        price_below_ma21_plus_2atr:
            lt_fields: [ 'close', 'ma_21_plus_2atr' ]
            allow_touch: true

        atr_rel_in_range_15m:
            use_atr_tf: ['1m','5m']
            min: 0.0005
            max: 0.045
            adapt_with_vol_bucket: true

        atr_rel_in_range_5m:
            use_atr_tf: ['1m']
            min: 0.0005
            max: 0.045
            adapt_with_vol_bucket: true

        # ====== RÉGIMES LONG / SHORT ======
        price_regime_ok_long:
            any_of:
                - all_of: [ ema_above_200_with_tolerance, ema_50_gt_200 ]
                - all_of: [ close_above_ema_200, ema200_slope_pos ]

        price_regime_ok_short:
            any_of:
                - all_of: [ ema_below_200_with_tolerance, ema_50_lt_200 ]
                - all_of: [ close_below_ema_200, ema200_slope_neg ]

        # ====== DIVERS ======
        close_below_vwap:
            lt_fields: [ 'close', 'vwap' ]

        ema_20_lt_50:
            lt_fields: [ 'ema_20', 'ema_50' ]

        ema_50_gt_200:
            gt_fields: [ 'ema_50', 'ema_200' ]

        ema_50_lt_200:
            lt_fields: [ 'ema_50', 'ema_200' ]

        lev_bounds:
            field: 'leverage'
            min: 2.0
            max: 25.0


    # ================================
    # EXECUTION SELECTOR
    # ================================
    execution_selector:

        per_timeframe:
            '15m':
                stay_on_if:
                    - get_false: true               # 15m = TF de transition
                drop_to_lower_if_any:
                    - expected_r_multiple_lt: 1.4
                    - entry_zone_width_pct_gt: 0.8
                    - atr_pct_15m_gt_bps: 130
                forbid_drop_to_lower_if_any:
                    - adx_5m_lt: 15
                    - spread_bps_gt: 9

            '5m':
                stay_on_if: []                      # 5m = TF d’exécution principal

            '1m':
                stay_on_if: []                      # 1m = ultra scalping, cas spéciaux

        allow_1m_only_for:
            enabled: false
            conditions:
                - scalping: true
                - trailing_after_tp1: true
                - end_of_zone_fallback: true

    # ================================
    # FILTRES GLOBAUX (obligatoires)
    # ================================
    filters_mandatory: []
     #   - rsi_lt_70                     # Filtre anti-surachat global
     #   - price_lte_ma21_plus_k_atr     # Anti-extension (MA21 + ATR)
        # (volume_ratio_ok reste au niveau 5m/1m pour ne pas étouffer le contexte)

    # ================================
    # VALIDATION PAR TIMEFRAME (MTF)
    # ================================
    validation:
        start_from_timeframe: '1h'
        timeframe:
            '4h':
                long:
                    - all_of:
                      - any_of:
                            - all_of: [ ema_50_gt_200, close_above_ema_200 ]
                            - all_of: [ ema_above_200_with_tolerance_moderate, ema_50_gt_200 ]
                      - price_regime_ok_long
                short:
                    - all_of:
                          - any_of:
                                - all_of: [ ema_50_lt_200, close_below_ema_200 ]
                                - all_of: [ ema_below_200_with_tolerance, ema_50_lt_200 ]
                          - price_regime_ok_short

            '1h':
                long:
                    - all_of:
                        - price_regime_ok_long
                        - any_of:
                            - macd_hist_gt_eps
                            - macd_hist_slope_pos
                        - adx_min_for_trend: { threshold: 18 }   # si aujourd'hui c'est 20/25
                short:
                    -  all_of:
                        - price_regime_ok_short
                        - any_of:
                            - macd_hist_decreasing_n
                            - macd_hist_slope_neg
                        - adx_min_for_trend
            '15m':
                long:
                    - any_of:
                            # Scénario A : continuation pro-trend (ce que tu as déjà)
                            - all_of:
                                    - ema20_over_50_with_tolerance_moderate
                                    - close_above_vwap_or_ma9       # momentum MACD explicite
                                    - rsi_lt_70: { rsi_lt_70_threshold: 74 }
                                    - atr_rel_in_range_15m
                                    - any_of:
                                        - macd_hist_gt_eps
                                        - macd_hist_slope_pos
                                        - macd_line_above_signal
                            # Scénario B : pullback confirmé sur support dynamique
                            - all_of:
                                - pullback_confirmed
                                - ema20_over_50_with_tolerance_moderate
                                - rsi_lt_70: { rsi_lt_70_threshold: 70 }
                                - atr_rel_in_range_15m

                short:
                    - all_of:
                        - price_regime_ok_short          # déjà défini, structure globale
                        - any_of:
                            - ema_20_lt_50
                            - ema_50_lt_200             # autoriser aussi les cas où 50 < 200
                        - any_of:
                            - macd_hist_decreasing_n
                            - macd_hist_slope_neg
                            - macd_line_below_signal
                        - close_below_vwap               # à garder, mais tu peux si besoin créer un close_below_vwap_or_ma9 plus tolérant
                        - rsi_gt_softfloor: { rsi_softfloor_threshold: 20 }
                        - atr_rel_in_range_15m

            '5m':
                long:
                    -   all_of:
                            -   any_of:
                                    - macd_hist_gt_eps
                                    - macd_line_cross_up_with_hysteresis
                            - close_above_vwap_or_ma9_relaxed
                            -   rsi_lt_70: { rsi_lt_70_threshold: 72 }
                            - atr_rel_in_range_5m
                            - volume_ratio_ok

                short:
                    - all_of:
                          - any_of:
                                - macd_hist_decreasing_n
                                - macd_line_cross_down_with_hysteresis
                          - close_below_vwap
                          - rsi_gt_softfloor: { rsi_softfloor_threshold: 18 }
                          - atr_rel_in_range_5m
                          - volume_ratio_ok

            '1m':
                long:
                    -   all_of:
                            - macd_hist_gt_eps
                            - close_above_vwap_and_ma9                # plus strict que OR
                            -   rsi_lt_70: { rsi_lt_70_threshold: 68 }  # RSI un peu plus bas
                            - atr_rel_in_range_5m
                            - volume_ratio_ok

                short:
                    -   all_of:
                            - macd_hist_decreasing_n
                            - close_below_vwap
                            -   rsi_gt_softfloor: { rsi_softfloor_threshold: 22 }
                            - atr_rel_in_range_5m
                            - volume_ratio_ok
```

## Profil — REGULAR (intra‑day / swing propre)

Spécification exhaustive : `trading-app/src/MtfValidator/config/validations.regular.yaml`

```yaml
# =====================================================================
# validations.regular.yaml
# Profil REGULAR (setup plus "swing / intra-day propre")
# =====================================================================
version: '0.0.12'

mtf_validation:
    mode: 'pragmatic'
    context_timeframes: ['4h','1h']
    execution_timeframe_default: '15m'
    allow_skip_lower_tf: true

    defaults:
        dry_run_validate_all_timeframes: false

    rules:

        ema_20_gt_50:
            gt_fields: [ 'ema_20', 'ema_50' ]

        # ====== EMA 20 slope (ajouté) ======
        ema_20_slope_pos:
            slope_left: 'ema_20'
            slope_lookback: 8
            op: '>'
            right: 0.0
            eps: 1.0e-12

        # ================================
        # EMA 200 - règles de base
        # ================================
        macd_hist_gt_eps:
            op: '>'
            left: 'macd_hist'
            right: 0.0
            eps: 1.0e-6

        ema20_over_50_with_tolerance:
            any_of:
                - ema_20_gt_50
                - ema_20_minus_ema_50_gt: -0.0012
                - ema_20_slope_pos

        ema_above_200_with_tolerance:
            any_of:
                - close_above_ema_200
                - close_minus_ema_200_gt: -0.0030
                - ema200_slope_pos

        ema_below_200_with_tolerance:
            any_of:
                - close_below_ema_200
                - close_minus_ema_200_lt: -0.0025
                - ema200_slope_neg

        rsi_lt_softcap: { lt: 78 }
        rsi_gt_softfloor: { gt: 30 }
        rsi_lt_70: { lt: 70 }

        # ====== VWAP / MA9 (ajout close_above_vwap / close_above_ma_9) ======
        close_above_vwap:
            gt_fields: [ 'close', 'vwap' ]

        close_above_ma_9:
            gt_fields: [ 'close', 'ma_9' ]

        close_above_vwap_or_ma9:
            any_of: [ close_above_vwap, close_above_ma_9 ]

        close_above_vwap_and_ma9:
            all_of: [ close_above_vwap, close_above_ma_9 ]

        price_below_ma21_plus_2atr:
            lt_fields: [ 'close', 'ma_21_plus_2atr' ]
            allow_touch: true

        # ====== Pullback / VWAP (ajout ma9_cross_up_ma21, near_vwap) ======
        ma9_cross_up_ma21:
            ma9_cross_up_ma21: true

        near_vwap:
            near_vwap: true

        pullback_confirmed:
            any_of:
                - ma9_cross_up_ma21
                - near_vwap: true
            validity_bars: 3

        macd_line_cross_up_with_hysteresis:
            require_prev_below: true
            min_gap: 0.0008
            cool_down_bars: 2

        macd_line_cross_down_with_hysteresis:
            require_prev_above: true
            min_gap: 0.0015
            cool_down_bars: 4

        macd_hist_slope_pos:
            derivative_gt: 0.0
            persist_n: 2

        macd_hist_slope_neg:
            derivative_lt: 0.0
            persist_n: 2

        ema200_slope_pos:
            slope_left: 'ema_200'
            slope_lookback: 13
            op: '>'
            right: 0.0
            eps: 1.0e-12

        ema200_slope_neg:
            slope_left: 'ema_200'
            slope_lookback: 21
            op: '<'
            right: 0.0
            eps: 1.0e-12

        atr_rel_in_range_15m:
            use_atr_tf: [ '1m', '5m' ]
            min: 0.0008
            max: 0.060
            adapt_with_vol_bucket: true

        atr_rel_in_range_5m:
            use_atr_tf: [ '1m' ]
            min: 0.0005
            max: 0.060
            adapt_with_vol_bucket: true

        macd_line_below_signal:
            lt_fields: [ 'macd', 'macd_signal' ]

        ema20_over_50_with_tolerance_moderate:
            any_of:
                - ema_20_gt_50
                - ema_20_minus_ema_50_gt: -0.0012
                - ema_20_slope_pos

        ema_above_200_with_tolerance_moderate:
            any_of:
                - close_above_ema_200
                - close_minus_ema_200_gt: -0.0020
                - ema200_slope_pos

        macd_line_above_signal:
            gt_fields: [ 'macd', 'macd_signal' ]

        macd_hist_increasing_n:
            increasing: { field: 'macd_hist', n: 2 }

        macd_hist_decreasing_n:
            decreasing: { field: 'macd_hist', n: 2 }

        ema_20_lt_50:
            lt_fields: [ 'ema_20', 'ema_50' ]

        ema_50_gt_200:
            gt_fields: [ 'ema_50', 'ema_200' ]

        ema_50_lt_200:
            lt_fields: [ 'ema_50', 'ema_200' ]

        close_above_ema_200:
            gt_fields: [ 'close', 'ema_200' ]

        close_below_ema_200:
            lt_fields: [ 'close', 'ema_200' ]

        close_below_vwap:
            lt_fields: [ 'close', 'vwap' ]

        adx_min_for_trend_1h:
            op: '>='
            left: 'adx_1h'
            period: 14
            right: 20

        lev_bounds:
            field: 'leverage'
            min: 2.0
            max: 20.0

        pullback_confirmed_ma9_21:
            all_of: [ ma9_cross_up_ma21 ]

        pullback_confirmed_vwap:
            all_of: [ near_vwap ]

        price_lte_ma21_plus_k_atr:
            lt_fields: [ 'close', 'ma_21_plus_2atr' ]

        price_regime_ok_long:
            any_of:
                - all_of: [ ema_above_200_with_tolerance, ema_50_gt_200 ]
                - all_of: [ close_above_ema_200, ema200_slope_pos ]

        price_regime_ok_short:
            any_of:
                - all_of: [ ema_below_200_with_tolerance, ema_50_lt_200 ]
                - all_of: [ close_below_ema_200, ema200_slope_neg ]

    execution_selector:
        # Format per_timeframe (nouveau format recommandé)
        per_timeframe:
            '15m':
                stay_on_if:
                    - expected_r_multiple_gte: 2.0
                    - entry_zone_width_pct_lte: 1.3
                    - atr_pct_15m_lte_bps: 130

                drop_to_lower_if_any:
                    - expected_r_multiple_lt: 2.0
                    - atr_pct_15m_gt_bps: 120
                    - entry_zone_width_pct_gt: 1.2

                forbid_drop_to_lower_if_any:
                    - adx_5m_lt: 20
                    - spread_bps_gt: 8

            '5m':
                stay_on_if: []  # 5m devient le TF cible après 15m

            '1m':
                stay_on_if: []  # Si tu réactives 1m plus tard

        # Format legacy (deprecated, maintenu pour backward compatibility)
        allow_1m_only_for:
            enabled: false
            conditions:
                - scalping: true
                - trailing_after_tp1: true
                - end_of_zone_fallback: true

    filters_mandatory:
        - rsi_lt_70: { rsi_lt_70_threshold: 73 }
        - adx_min_for_trend_1h
        - pullback_confirmed_ma9_21
        - pullback_confirmed_vwap
        - price_lte_ma21_plus_k_atr

    validation:
        start_from_timeframe: '4h'
        timeframe:
            '4h':
                long:
                    - all_of:
                          - any_of:
                                - all_of: [ ema_50_gt_200, close_above_ema_200 ]
                                - all_of: [ ema_above_200_with_tolerance, ema_50_gt_200 ]
                          - price_regime_ok_long
                short:
                    - all_of:
                          - any_of:
                                - all_of: [ ema_50_lt_200, close_below_ema_200 ]
                                - all_of: [ ema_below_200_with_tolerance, ema_50_lt_200 ]
                          - price_regime_ok_short

            '1h':
                long:
                    - all_of:
                          - any_of:
                                - all_of: [ ema_above_200_with_tolerance, ema_50_gt_200 ]
                                - all_of: [ ema200_slope_pos, ema_50_gt_200 ]
                          - any_of:
                                - all_of: [ macd_hist_gt_eps ]
                                - all_of:
                                      - macd_line_cross_up_with_hysteresis: { min_gap: 0.0012, cool_down_bars: 3, require_prev_below: true }
                                - all_of: [ macd_hist_slope_pos ]
                    - price_regime_ok_long

                short:
                    - all_of:
                          - any_of:
                                - all_of: [ ema_below_200_with_tolerance, ema_50_lt_200 ]
                                - all_of: [ ema200_slope_neg, ema_50_lt_200 ]
                          - any_of:
                                - all_of:
                                      - macd_line_cross_down_with_hysteresis: { min_gap: 0.0018, cool_down_bars: 4, require_prev_above: true }
                                - all_of: [ macd_hist_decreasing_n ]
                                - all_of: [ macd_hist_slope_neg ]
                    - price_regime_ok_short

            '15m':
                long:
                    - all_of:
                          - any_of:
                                - all_of: [ macd_hist_gt_eps, ema20_over_50_with_tolerance ]
                                - all_of:
                                      - macd_line_cross_up_with_hysteresis: { min_gap: 0.0012, cool_down_bars: 3, require_prev_below: true }
                                      - close_above_vwap_or_ma9
                          - ema20_over_50_with_tolerance
                          - rsi_lt_70: { rsi_lt_70_threshold: 73 }
                          - close_above_vwap_or_ma9
                          - atr_rel_in_range_15m
                short:
                    - all_of:
                          - any_of:
                                - all_of:
                                      - macd_line_cross_down_with_hysteresis: { min_gap: 0.0018, cool_down_bars: 4, require_prev_above: true }
                                - all_of: [ macd_hist_decreasing_n, ema_20_lt_50 ]
                          - ema_20_lt_50
                          - rsi_gt_softfloor: { rsi_softfloor_threshold: 28 }
                          - close_below_vwap
                          - atr_rel_in_range_15m

            '5m':
                long:
                    - all_of:
                          - any_of:
                                - all_of: [ macd_hist_gt_eps, ema20_over_50_with_tolerance ]
                                - all_of:
                                      - macd_line_cross_up_with_hysteresis: { min_gap: 0.0012, cool_down_bars: 3, require_prev_below: true }
                                      - close_above_vwap_or_ma9
                          - ema20_over_50_with_tolerance
                          - close_above_vwap_or_ma9
                          - rsi_lt_70: { rsi_lt_70_threshold: 73 }
                          - atr_rel_in_range_5m
                short:
                    - all_of:
                          - any_of:
                                - all_of:
                                      - macd_line_cross_down_with_hysteresis: { min_gap: 0.0018, cool_down_bars: 4, require_prev_above: true }
                                - all_of: [ macd_hist_decreasing_n, ema_20_lt_50 ]
                          - ema_20_lt_50
                          - rsi_gt_softfloor: { rsi_softfloor_threshold: 28 }
                          - close_below_vwap
                          - atr_rel_in_range_5m

            '1m':
                long:
                    - all_of:
                          - macd_hist_gt_eps
                          - ema20_over_50_with_tolerance
                          - close_above_vwap_or_ma9
                          - rsi_lt_70: { rsi_lt_70_threshold: 73 }
                          - macd_hist_slope_pos
                          - atr_rel_in_range_5m
                short:
                    - all_of:
                          - macd_hist_decreasing_n
                          - ema_20_lt_50
                          - rsi_gt_softfloor: { rsi_softfloor_threshold: 28 }
                          - close_below_vwap
                          - macd_hist_slope_neg
                          - atr_rel_in_range_5m
```

## Profil — CRASH (shorts sur dumps violents)

Spécification exhaustive : `trading-app/src/MtfValidator/config/validations.crash.yaml`

```yaml
# =====================================================================
# validations.crash.yaml – Profil CRASH (shorts sur dumps violents)
# MTF 4h / 1h (contexte) → 15m / 5m / 1m (exécution)
# =====================================================================
version: '0.1.0'

mtf_validation:
    profile: 'crash'
    mode: 'strict'                         # Contexte strict : on ne short que si tout est aligné
    context_timeframes: ['4h', '1h']       # Contexte long terme / intermédiaire
    execution_timeframes: ['15m','5m','1m']
    execution_timeframe_default: '5m'
    allow_skip_lower_tf: true

    defaults:
        dry_run_validate_all_timeframes: false

    # ================================
    # RÈGLES ATOMIQUES
    # (reprend la base scalper, + règles crash)
    # ================================
    rules:

        # ====== EMA structure courte / long terme ======
        ema_20_gt_50:
            gt_fields: [ 'ema_20', 'ema_50' ]

        ema_20_lt_50:
            lt_fields: [ 'ema_20', 'ema_50' ]

        ema_50_gt_200:
            gt_fields: [ 'ema_50', 'ema_200' ]

        ema_50_lt_200:
            lt_fields: [ 'ema_50', 'ema_200' ]

        close_above_ema_200:
            gt_fields: [ 'close', 'ema_200' ]

        close_below_ema_200:
            lt_fields: [ 'close', 'ema_200' ]

        ema200_slope_pos:
            slope_left: 'ema_200'
            slope_lookback: 13
            op: '>'
            right: 0.0
            eps: 1e-12

        ema200_slope_neg:
            slope_left: 'ema_200'
            slope_lookback: 21
            op: '<'
            right: 0.0
            eps: 1e-12

        ema_below_200_with_tolerance:
            any_of:
                - close_below_ema_200
                - close_minus_ema_200_lt: -0.0030
                - ema200_slope_neg

        ema_above_200_with_tolerance:
            any_of:
                - close_above_ema_200
                - close_minus_ema_200_gt: -0.0030
                - ema200_slope_pos

        # ====== MACD / momentum ======
        macd_hist_gt_eps:
            op: '>'
            left: 'macd_hist'
            right: 0.0
            eps: 1e-6

        macd_hist_increasing_n:
            increasing: { field: 'macd_hist', n: 2 }

        macd_hist_decreasing_n:
            decreasing: { field: 'macd_hist', n: 2 }

        macd_line_above_signal:
            gt_fields: [ 'macd', 'macd_signal' ]

        macd_line_below_signal:
            lt_fields: [ 'macd', 'macd_signal' ]

        macd_line_cross_up_with_hysteresis:
            require_prev_below: true
            min_gap: 0.0006
            cool_down_bars: 2

        macd_line_cross_down_with_hysteresis:
            require_prev_above: true
            min_gap: 0.0012
            cool_down_bars: 3

        # ====== VWAP / PULLBACK ======
        close_above_vwap:
            gt_fields: [ 'close', 'vwap' ]

        close_below_vwap:
            lt_fields: [ 'close', 'vwap' ]

        close_above_ma_9:
            gt_fields: [ 'close', 'ma_9' ]

        ma9_cross_up_ma21:
            ma9_cross_up_ma21: true

        near_vwap:
            near_vwap: true

        close_above_vwap_or_ma9:
            any_of: [ close_above_vwap, close_above_ma_9 ]

        close_above_vwap_and_ma9:
            all_of: [ close_above_vwap, close_above_ma_9 ]

        pullback_confirmed:
            any_of:
                - ma9_cross_up_ma21
                - near_vwap: true
            validity_bars: 3

        # ====== RSI / bornes globales ======
        rsi_lt_70:
            lt: 72

        rsi_gt_softfloor:
            gt: 20

        rsi_1m_lt_extreme:
            lt: 10          # RSI(1m) très bas → crash zone

        rsi_5m_gt_floor:
            gt: 20          # On évite que 5m soit déjà complètement cramé

        # ====== ATR relatif ======
        atr_rel_in_range_15m:
            use_atr_tf: ['1m','5m']
            min: 0.0005
            max: 0.045
            adapt_with_vol_bucket: true

        atr_rel_in_range_5m:
            use_atr_tf: ['1m']
            min: 0.0005
            max: 0.045
            adapt_with_vol_bucket: true

        # ====== Régimes long / short (structure macro) ======
        price_regime_ok_long:
            any_of:
                - all_of: [ ema_above_200_with_tolerance, ema_50_gt_200 ]
                - all_of: [ close_above_ema_200, ema200_slope_pos ]

        price_regime_ok_short:
            any_of:
                - all_of: [ ema_below_200_with_tolerance, ema_50_lt_200 ]
                - all_of: [ close_below_ema_200, ema200_slope_neg ]

        # ====== Divers / levier ======
        lev_bounds:
            field: 'leverage'
            min: 2.0
            max: 25.0

        # ====== RÈGLES SPÉCIFIQUES "CRASH SHORT" ======
        crash_context_ok:
            # Contexte 4h/1h déjà franchement baissier
            all_of:
                - price_regime_ok_short   # sous 200 + EMA200 baissière
                - ema200_slope_neg
                - macd_hist_decreasing_n
                - adx_min_for_trend       # règle existante globale (non redéfinie ici)

        crash_short_pattern_15m:
            # 15m: trend baissier + sous VWAP + momentum en baisse + ATR dans le range
            all_of:
                - ema_20_lt_50
                - close_below_vwap
                - macd_hist_decreasing_n
                - atr_rel_in_range_15m

        crash_short_pattern_5m:
            # 5m: cascade propre + volume anormal
            all_of:
                - ema_20_lt_50
                - close_below_vwap
                - macd_hist_decreasing_n
                - atr_rel_in_range_5m
                - volume_ratio_ok
                - rsi_5m_gt_floor      # 5m pas totalement mort pour éviter le "dernier tic"

        crash_short_pattern_1m:
            # 1m: survente extrême mais toujours dans la tendance crash
            all_of:
                - macd_hist_decreasing_n
                - close_below_vwap
                - atr_rel_in_range_5m
                - volume_ratio_ok
                - rsi_1m_lt_extreme

        crash_pullback_ready:
            # Mini pullback / retest (éviter de shorter la mèche la plus basse)
            any_of:
                - ma9_cross_up_ma21
                - near_vwap: true

        crash_short_entry_1m:
            all_of:
                - crash_short_pattern_1m
                - crash_pullback_ready


    # ================================
    # EXECUTION SELECTOR (simplifié pour crash)
    # ================================
    execution_selector:

        per_timeframe:
            '15m':
                stay_on_if:
                    - get_false: true        # 15m = transition
                drop_to_lower_if_any:
                    - atr_pct_15m_gt_bps: 130
                    - spread_bps_gt: 9

            '5m':
                stay_on_if: []               # 5m = TF principal crash

            '1m':
                stay_on_if: []               # 1m = timing ultra précis

        allow_1m_only_for:
            enabled: false
            conditions:
                - scalping: true
                - trailing_after_tp1: true
                - end_of_zone_fallback: true


    # ================================
    # FILTRES GLOBAUX
    # ================================
    filters_mandatory:
        - rsi_lt_70                     # Filtre anti-surachat global
        - lev_bounds                    # bornes sur levier (par sécurité)


    # ================================
    # VALIDATION PAR TIMEFRAME (MTF)
    # ================================
    validation:
        start_from_timeframe: '4h'
        timeframe:

            '4h':
                long: []                 # Profil crash → on ne traite que les shorts
                short:
                    - all_of:
                          - price_regime_ok_short

            '1h':
                long: []
                short:
                    - all_of:
                          - crash_context_ok   # 1h doit valider le contexte crash

            '15m':
                long: []
                short:
                    - all_of:
                          - crash_context_ok
                          - crash_short_pattern_15m

            '5m':
                long: []
                short:
                    - any_of:
                          # short standard crash 5m
                          - all_of:
                                - crash_context_ok
                                - crash_short_pattern_5m

                          # éventuelle branche plus stricte à activer plus tard
                          # - all_of:
                          #       - crash_context_ok
                          #       - crash_short_pattern_5m
                          #       - extra_filter: true

            '1m':
                long: []
                short:
                    - any_of:
                          # short normal crash 1m (sans pullback)
                          - all_of:
                                - crash_context_ok
                                - crash_short_pattern_1m

                          # short crash après micro-pullback (plus safe)
                          - all_of:
                                - crash_context_ok
                                - crash_short_entry_1m
```

