## Module Indicator — mise à jour 2025

Le module Indicator alimente toute la chaîne MTF/TradeEntry : il prend des klines (via les providers), construit un contexte homogène, évalue les conditions (YAML ou compilées) et expose des snapshots/coffres-forts d’indicateurs. Cette page décrit le flux actuel utilisé par `MtfRunnerService` / `MtfValidatorCoreService`.

---

## 1. Entrées et façades

Tout accès passe par des contrats du namespace `App\Contract\Indicator` :

| Contrat | Rôle | Implémentation principale |
| --- | --- | --- |
| `IndicatorMainProviderInterface` | Façade unique. Fournit `getEngine()` et `getIndicatorProvider()` | `App\Indicator\Provider\IndicatorMainProvider` (#[AsAlias]) |
| `IndicatorEngineInterface` | Construit le contexte, évalue YAML & registry, calcule ATR/metrics | `App\Indicator\Provider\IndicatorEngineProvider` |
| `IndicatorProviderInterface` | Persiste/retourne les snapshots et les listes d’indicateurs | `App\Indicator\Provider\IndicatorProviderService` |

Le runner utilise `IndicatorProviderInterface` pour deux tâches :
1. `MtfValidatorCoreService` → `IndicatorProviderInterface::getIndicatorsForSymbolAndTimeframes()` (via `IndicatorProviderService`) pour récupérer les contextes par TF.
2. `MtfRunnerService::dispatchIndicatorSnapshotPersistence()` qui publie `IndicatorSnapshotPersistRequestMessage` afin de sauvegarder les snapshots des symboles récemment évalués.

---

## 2. Chaîne de traitement

```
Klines (MainProvider) ──► IndicatorContextBuilder ──► IndicatorEngineInterface
   │                                                         │
   │                                   ┌─────────────── YAML ├─► ConditionLoader\TimeframeEvaluator
   │                                   │
   └─► Snapshot DTOs ◄── IndicatorProviderInterface ─┴──────────── Compilé (ConditionRegistry)
```

1. `MainProviderInterface` (module Provider) fournit les klines normalisées.
2. `Indicator\Context\IndicatorContextBuilder` enrichit un tableau complet (`ema`, `rsi`, `macd`, `atr`, `adx`, `vwap`, `price_previous`, etc.).
3. `IndicatorEngineInterface` expose deux moteurs :
   - **YAML** : consomme `config/app/mtf_validations*.yaml`. Utilisé par `TimeframeValidationService` pour garder la sémantique historique (rules/filters, `conditions_{long,short}`, `failed_*`…).
   - **Compilé** : basé sur des services annotés `#[AsIndicatorCondition]`. Le `IndicatorCompilerPass` génère des ServiceLocators par timeframe/side et alimente `Indicator\Registry\ConditionRegistry`.
4. `IndicatorProviderInterface`:
   - hydrate les snapshots (`IndicatorSnapshotDto`) et gère leur persistance DB/Redis,
   - fournit `evaluateConditions()` et `getListFromKlines()` pour les contrôleurs/CLI.

---

## 3. Définition des conditions

Chaque condition se trouve sous `src/Indicator/Condition/*` et applique l’attribut :

```php
#[AsIndicatorCondition(
    name: self::NAME,
    timeframes: ['1m','5m','15m','1h','4h'],
    side: null| 'long' | 'short',
    priority: 0
)]
final class RsiBullishCondition implements ConditionInterface
{
    public const NAME = 'rsi_bullish';
    // ...
}
```

Principes :
- Le `CompilerPass` construit des locators indexés (`"15m"` / `"15m:long"`) pour une résolution rapide.
- `Indicator\Registry\ConditionRegistry` expose `evaluate(array $context, ?array $names = null)` et `names()` pour introspection (ex. CLI diagnostics).
- Le YAML reste la source de vérité métier : la compilation accélère certaines commandes/tests mais le runner continue d’utiliser la logique YAML via `TimeframeValidationService`.

---

## 4. Intégration dans MTF

1. `MtfRunnerService` prépare un `MtfRunRequestDto`.
2. `MtfValidatorCoreService` :
   - demande aux `IndicatorProviderInterface` tous les timeframes (contexte + execution) nécessaires,
   - passe les contextes à `ContextValidationService` et `ExecutionSelectionService` (via `TimeframeValidationService` et les configs YAML),
   - construit un `MtfResultDto` (execution_tf, context snapshot, raisons).
3. `TradingDecisionHandler` ne recalculera pas d’indicateurs : il consomme directement les ATR/metrics fournis dans `SymbolResultDto`.
4. `MtfRunnerService::dispatchIndicatorSnapshotPersistence()` envoie de façon asynchrone les snapshots à persister pour suivre l’évolution des indicateurs dans le temps.

---

## 5. Commandes et scripts utiles

| Commande | Description |
| --- | --- |
| `bin/console app:indicator:contracts:validate <tf> [--compiled] [--side=long|short]` | Mesure la perf de fetch/évaluation pour un TF donné. |
| `bin/console app:indicator:conditions:diagnose <symbol> <tf> [--side=...]` | Force une évaluation compilée pour analyser les états `passed/failed`. |
| `bin/console app:indicators:get <symbol> <tf> [--all-conditions]` | Retourne les valeurs d’indicateurs + évaluation d’un sous-ensemble de conditions. |
| `bin/console indicator:snapshot create|compare|list ...` | Gestion des snapshots (création, diff, listing). |

Les contrôleurs REST (`IndicatorTestController`) exposent des diagnostics équivalents pour Grafana/Postman.

---

## 6. Organisation du code

- `src/Contract/Indicator/` — contrats DTO + interfaces.
- `src/Indicator/Core/` — calculs purs (EMA, RSI, ADX…).
- `src/Indicator/Context/` — builders communs (utilisés par le moteur YAML et la compilation).
- `src/Indicator/Condition/` — conditions taggées `AsIndicatorCondition`.
- `src/Indicator/Compiler/` — `IndicatorCompilerPass`.
- `src/Indicator/Registry/` — `ConditionRegistry`.
- `src/Indicator/Provider/` — implémentations des façades `MainProvider`, `EngineProvider`, `IndicatorProviderService`.
- `src/Indicator/Loader` & `src/MtfValidator/ConditionLoader` — moteur YAML et mapping avec `MtfValidationConfig`.

---

## 7. Bonnes pratiques & dépannage

- **Toujours passer par les interfaces** (`IndicatorMainProviderInterface`, `IndicatorEngineInterface`, `IndicatorProviderInterface`). Les classes `Core/*` ou `Condition/*` ne doivent pas être injectées directement.
- **Limiter les timeframes/sides** dans les attributs des conditions pour éviter une explosion du nombre de services instanciés.
- **EMA9 ≈ 0** sur des actifs très bas : vérifier les logs `indicator.ema_fallback`. Le builder va automatiquement fallback sur le calcul PHP et loguera les klines fautives.
- **`cache:clear`/compilation** : si un service de condition échoue à cause d’un constructeur non autowirable, Symfony bloque la compilation. Vérifier l’attribut `#[AsIndicatorCondition]` ou les dépendances optionnelles (utiliser `?LoggerInterface` par exemple).
- **Snapshots désynchronisés** : regarder les messages `IndicatorSnapshotPersistRequestMessage` (message bus). `MtfRunnerService` loggue le nombre de symboles/timeframes persistés par run.

Ce module est ainsi prêt pour d’autres exchanges/timeframes sans modifier `MtfRunnerService` : suffit d’ajouter de nouvelles conditions taggées ou d’étendre les configs YAML.
