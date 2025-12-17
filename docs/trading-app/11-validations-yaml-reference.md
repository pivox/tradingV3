# Référence YAML — `validations*.yaml` (MTF validation)

Cette page documente **les clés, types, valeurs possibles, valeurs par défaut et comportements** des profils :

- `trading-app/src/MtfValidator/config/validations.<mode>.yaml`

Elle complète :

- `docs/trading-app/07-conditions-reference.md` (liste des conditions & sémantiques)
- `docs/trading-app/08-profils-validations.md` (copies complètes des YAML livrés)

## Résolution du fichier (mode → YAML)

Le mode MTF est résolu par `MtfValidationConfigProvider` sur le même principe que TradeEntry :

- `mode` explicite (input) sinon premier mode `enabled: true` trié par `priority` dans `trading-app/config/services.yaml`

Résolution du fichier :

- mapping interne :
  - `regular` → `validations.regular.yaml`
  - `scalping` → `validations.scalper.yaml`
- sinon : pattern `validations.<mode>.yaml` (ex: `validations.scalper_micro.yaml`, `validations.crash.yaml`)

Sources :

- `trading-app/src/Config/MtfValidationConfigProvider.php`
- `trading-app/config/services.yaml`

## Structure racine

- `version` (string) : utilisé pour recharger le YAML si la version change.
- `mtf_validation` (map) : bloc principal.

## `mtf_validation` — clés de haut niveau

- `profile` (string, optionnel) : descriptif (non structurant).

- `mode` (string) : **valeurs fonctionnelles**
  - `pragmatic` (défaut)
  - `strict`
  - `ultra-pragmatig` (alias : normalisé en `pragmatic` pour la décision de contexte)

Source : `trading-app/src/MtfValidator/Service/ContextValidationService.php`

- `context_timeframes` (string | list<string>)
  - si absent : dérivé des clés `validation.timeframe` (sinon fallback `['4h','1h']`)

- `execution_timeframes` (string | list<string>)
  - si absent : dérivé de `validation.timeframe` (timeframes non inclus dans `context_timeframes`)
  - si toujours vide : fallback `['15m']`

Source : `trading-app/src/MtfValidator/Service/MtfValidatorCoreService.php`

- `execution_timeframe_default` (string)
  - utilisé comme fallback par certains sélecteurs
  - valeurs reconnues par l’`ExecutionSelector` (ancienne implémentation) : `15m` | `5m` | `1m` (sinon fallback `5m`)

Source : `trading-app/src/MtfValidator/Execution/ExecutionSelector.php`

- `allow_skip_lower_tf` (bool)
  - présent dans les YAML mais **non consommé** par le cœur `MtfValidatorCoreService` / `TradingDecisionHandler` actuel.

## `mtf_validation.defaults`

- `dry_run_validate_all_timeframes` (bool, défaut `false`)
  - utilisé dans `TradingDecisionHandler` : en `dryRun`, autorise les TF `['1m','5m','15m','1h','4h']` sans appliquer `trade_entry.decision.allowed_execution_timeframes`.

Source : `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php`

## `mtf_validation.rules` (règles nommées / combinatoires)

Deux moteurs peuvent interpréter les règles :

1) **ConditionRegistry** (moteur “nouveau”, utilisé quand disponible)  
2) **YamlRuleEngine** (fallback historique)

### Formes acceptées par ConditionRegistry (`rules.<name>`)

Une règle nommée peut être définie sous forme de :

- combinaison :
  - `{ any_of: [ <spec>, ... ] }` (vide → `false`)
  - `{ all_of: [ <spec>, ... ] }` (vide → `true`)
- comparaison de champs :
  - `{ lt_fields: ['close','vwap'] }`
  - `{ gt_fields: ['ema_20','ema_50'] }`
- opération custom :
  - `{ op: '>', left: 'macd_hist', right: 0.0, eps: 1e-6 }`
  - `op` supportés : `>` | `>=` | `<` | `<=` (autre → exception)
- trend :
  - `{ increasing: { field: 'macd_hist', n: 2, strict: true|false, eps: 1e-8 } }`
  - `{ decreasing: { field: 'macd_hist', n: 2, strict: true|false, eps: 1e-8 } }`
- alias d’une condition ou d’une autre règle :
  - `macd_hist_gt_eps: { eps: 1e-12 }` (override *merge* dans le contexte de la condition)
  - `ema_20_minus_ema_50_gt: -0.0012` (override scalaire → injecte `threshold`)

Sources :

- `trading-app/src/MtfValidator/ConditionLoader/Cards/Rule/Rule.php`
- `docs/trading-app/07-conditions-reference.md`

### Limites du fallback YamlRuleEngine

`YamlRuleEngine` supporte seulement un sous-ensemble de primitives :

- `all_of`, `any_of`
- `lt_fields`, `gt_fields`
- `op`+`left`+`right`+`eps` (op supportés : `>`,`>=`,`<`,`<=`,`==`,`!=` ; op inconnu → “true”)
- comparaisons scalaires `{lt:...}` / `{gt:...}` (champ `field` optionnel, défaut `rsi`)

Tout bloc “inconnu” retourne **true** (pour “ne pas bloquer”) : la validation peut devenir trop permissive si le moteur ConditionRegistry n’est pas actif.

Source : `trading-app/src/MtfValidator/Service/Rule/YamlRuleEngine.php`

## `mtf_validation.validation`

### `validation.start_from_timeframe`

Utilisé par le composant “Validation card” (dashboards/outil) :

- valeurs reconnues : `4h` | `1h` | `15m` | `5m` | `1m`
- ordre d’évaluation : `4h → 1h → 15m → 5m → 1m`
- valeur inconnue → fallback sur `4h`

Source : `trading-app/src/MtfValidator/ConditionLoader/Cards/Validation/Validation.php`

### `validation.timeframe.<tf>.<side>` (scénarios long/short)

`<side>` ∈ `long` | `short`.

La valeur est une **liste** d’éléments. Chaque élément peut être :

- string : nom d’une condition (ou règle) (ex: `ema_20_gt_50`)
- map `{ all_of: [ ... ] }` / `{ any_of: [ ... ] }` : combinatoire
- map `{ condition_name: <override> }` :
  - `<override>` peut être scalaire (numérique, bool, string) ou map (ex: `{ near_vwap_tolerance: 0.0012 }`)
  - en moteur ConditionRegistry, l’override est fusionné dans le contexte de la condition

Sémantique ConditionRegistry (via `ListSideElements`) :

- `all_of` vide → `passed=true`
- `any_of` vide → `passed=false`
- une **liste vide** au niveau `<side>` (ex: `long: []`) est évaluée comme `passed=true` (mode `all`, liste vide considérée “valide”)
  - conséquence : `long: []` ne “désactive” pas un side en ConditionRegistry ; pour désactiver, il faut **omettre** `long` ou mettre une condition impossible.

Sources :

- `trading-app/src/MtfValidator/ConditionLoader/Cards/Validation/ListSideElements.php`
- `trading-app/src/MtfValidator/ConditionLoader/Cards/Validation/SideElementSimple.php`
- `trading-app/src/MtfValidator/Service/TimeframeValidationService.php`

Sémantique YAML historique (fallback `TimeframeRuleEvaluator`) :

- un `<side>` vide (`[]`) → `false` (aucun scénario ne passe)
- un scénario explicite `- all_of: []` → `true`

Sources :

- `trading-app/src/MtfValidator/Service/Rule/TimeframeRuleEvaluator.php`
- `trading-app/src/MtfValidator/Service/Rule/YamlRuleEngine.php`

## `mtf_validation.filters_mandatory`

Champ consommé par **deux** composants, avec **deux interprétations** :

### A) TimeframeValidationService (moteur ConditionRegistry)

- extrait uniquement les **noms** (string ou “première clé” si map)
- ignore les overrides/valeurs (pas d’injection de seuil)
- évalue les conditions avec le contexte indicateur complet
- si au moins un filtre échoue → timeframe marqué `invalid` (`FILTERS_MANDATORY_FAILED`)

Source : `trading-app/src/MtfValidator/Service/TimeframeValidationService.php`

### B) ExecutionSelector (ancienne sélection d’exécution)

Quand `execution_selector` est vide (ou pour certaines branches legacy), `ExecutionSelector` lit `filters_mandatory` comme une **liste de conditions** et peut injecter des seuils sous la forme `{condition}_threshold` si et seulement si l’item est `- condition: <nombre>`.

Important :

- si l’item est `- condition: { ... }` (map), la valeur **n’est pas** interprétée comme un seuil numérique (donc pas d’injection).
- l’évaluation reste faite par nom de condition (la valeur peut être ignorée selon les conditions).

Sources :

- `trading-app/src/MtfValidator/Execution/ExecutionSelector.php`
- `docs/trading-app/07-conditions-reference.md`

## `mtf_validation.execution_selector`

Deux implémentations coexistent dans le codebase :

1) `App\\MtfValidator\\Execution\\ExecutionSelector` (utilisé par `TradingDecisionHandler`, donc par le pipeline MTF→TradeEntry)  
2) `App\\MtfValidator\\Service\\Execution\\YamlExecutionSelectorEngine` (utilisé par `MtfValidatorCoreService`)

Les YAML livrés utilisent `execution_selector.per_timeframe` (format recommandé). Le moteur `ExecutionSelector` supporte aussi un format legacy.

### Format `per_timeframe` (recommandé)

Schéma :

```yaml
execution_selector:
  per_timeframe:
    '15m':
      stay_on_if:                # all-of (toutes les conditions non-missing doivent passer)
        - expected_r_multiple_gte: 2.0
      drop_to_lower_if_any:      # any-of (au moins une condition non-missing passe → descente)
        - atr_pct_15m_gt_bps: 130
      forbid_drop_to_lower_if_any: # any-of (si passe → interdit la descente)
        - spread_bps_gt: 9
    '5m':
      stay_on_if: []
    '1m':
      stay_on_if: []
```

Limites :

- seuls les timeframes `15m`, `5m`, `1m` sont pris en charge par le sélecteur (ordre fixe `15m → 5m → 1m`).
- la liste accepte des items string ou `{condition: <nombre>}` ; `<nombre>` est injecté dans le contexte sous `{condition}_threshold` **pour `ExecutionSelector`**.

Source : `trading-app/src/MtfValidator/Execution/ExecutionSelector.php`

### Format legacy (déprécié)

Clés legacy possibles :

- `stay_on_15m_if`
- `drop_to_5m_if_any`
- `forbid_drop_to_5m_if_any`
- `allow_1m_only_for` (avec `enabled` + `conditions`)

Remarque : la sémantique de `allow_1m_only_for.enabled` n’est pas homogène selon les branches ; le format `per_timeframe` est celui à utiliser.

### Spécificités `YamlExecutionSelectorEngine`

`YamlExecutionSelectorEngine` interprète les conditions sous forme de comparaisons suffixées :

- suffixes : `_gte`, `_lte`, `_gt`, `_lt`
- la “métrique” comparée est l’entrée `TimeframeDecisionDto->extra[metric]` (metric = clé sans suffixe)

Exemple : `expected_r_multiple_gte: 2.0` compare `decision.extra['expected_r_multiple'] >= 2.0`.

Attention : les métriques requises doivent être effectivement présentes dans `TimeframeDecisionDto->extra` ; sinon la condition compare `NaN` et retourne `false`.

Source : `trading-app/src/MtfValidator/Service/Execution/YamlExecutionSelectorEngine.php`

