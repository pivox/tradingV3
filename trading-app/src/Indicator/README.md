## Module Provider et Module Indicator — Vue d’ensemble

Ce document décrit le rôle, l’architecture et l’usage des deux modules clés:

- Module Provider: accès unifié aux sources externes (klines, contrats, comptes, ordres, système).
- Module Indicator: calcul des indicateurs, évaluation des conditions par timeframe/side, persistance de snapshots, et façade d’accès.

---

## Module Provider

Objectif: offrir une façade unique pour la donnée de marché et les opérations, en s’appuyant sur des contrats stables.

- Contrats exposés (namespace `App\Contract\Provider`)
  - `MainProviderInterface`: point d’entrée unique.
    - `getKlineProvider(): KlineProviderInterface`
    - `getContractProvider(): ContractProviderInterface`
    - `getOrderProvider(): OrderProviderInterface`
    - `getAccountProvider(): AccountProviderInterface`
    - `getSystemProvider(): SystemProviderInterface`
  - Les providers concrets (ex. Bitmart) implémentent ces interfaces.

- Implémentations principales (namespace `App\Provider`)
  - `MainProvider` (#[AsAlias] vers `MainProviderInterface`): façade runtime.
  - Providers spécifiques (ex: `Provider\Bitmart\BitmartKlineProvider` alias de `KlineProviderInterface`).

- Santé et métadonnées
  - `MainProvider::healthCheck()` agrège des checks par sous‑provider.
  - `getSystemProvider()` expose les infos système (ex: heure serveur).

- Exemple d’usage
  ```php
  public function __construct(MainProviderInterface $main) { $this->main = $main; }

  $klines = $this->main->getKlineProvider()->getKlines('BTCUSDT', Timeframe::TF_15M, 150);
  ```

---

## Module Indicator

Objectif: standardiser le calcul des indicateurs (RSI/MACD/EMA/VWAP/ATR…), la définition/évaluation des conditions et fournir des moteurs d’évaluation (YAML vs compilé) à faible couplage.

### Frontières et principes
- Les classes internes de calcul (`App\Indicator\Core\*`) ne doivent PAS être utilisées hors du module Indicator.
- Tout accès externe passe par des contrats/facades:
  - `IndicatorMainProviderInterface` (façade): donne accès à
    - `getEngine(): IndicatorEngineInterface`
    - `getIndicatorProvider(): IndicatorProviderInterface`
  - `IndicatorEngineInterface`: construit le contexte et évalue (YAML/compilé).
  - `IndicatorProviderInterface`: calcule/persiste des snapshots et des listes d’indicateurs.

### Contrats et services
- `App\Contract\Indicator\IndicatorEngineInterface`
  - `buildContext(symbol, timeframe, klines, options=[])`
  - `evaluateYaml(timeframe, context)` (moteur YAML)
  - `evaluateCompiled(timeframe, context, ?side)` (registre compilé)
  - `computeAtr(highs,lows,closes,?ohlc,period=14)`
  - `evaluateAllConditions(context)` / `evaluateConditions(context, names)`
  - `listConditionNames()`
- `App\Indicator\Provider\IndicatorEngineProvider` (#[AsAlias] → `IndicatorEngineInterface`)
- `App\Contract\Indicator\IndicatorProviderInterface`
  - `getSnapshot(symbol, timeframe): IndicatorSnapshotDto`
  - `saveIndicatorSnapshot(IndicatorSnapshotDto)`
  - `getListFromKlines(klines): ListIndicatorDto`
  - `evaluateConditions(symbol, timeframe): array`
- `App\Indicator\Provider\IndicatorProviderService` (implémentation du provider d’indicateurs)
- `App\Contract\Indicator\IndicatorMainProviderInterface` + `App\Indicator\Provider\IndicatorMainProvider`

### Conditions, attributs et compilation
- Chaque condition implémente `ConditionInterface` et porte l’attribut:
  ```php
  #[AsIndicatorCondition(
      timeframes: ['1m','5m','15m','1h','4h'],
      side: 'long'|'short'|null,
      name: self::NAME,
      priority: 0
  )]
  ```
- Le `CompilerPass` (`IndicatorCompilerPass`) scanne ces services et construit des ServiceLocators:
  - par timeframe: `"15m"`
  - par timeframe+side: `"15m:long"`, `"15m:short"`
- Ces locators sont injectés dans `Indicator\Registry\ConditionRegistry` pour des lookups O(1).

### Moteurs d’évaluation
- YAML (legacy structure)
  - `ConditionLoader\TimeframeEvaluator` lit `config/app/mtf_validations.yaml` et produit `long/short` avec `requirements`, `conditions`, `failed`, `passed`.
- Compilé (attributs + registry)
  - `ConditionRegistry::evaluateForTimeframe()` évalue la liste compilée.
  - Note: l’agrégation est volontairement simple (ex: “au moins une condition vraie”) dans les commandes de bench; elle ne reflète pas la logique YAML complète.

### Contexte indicateurs (IndicatorContextBuilder)
- Construit un tableau cohérent à partir d’OHLCV: `rsi`, `macd(macd/signal/hist)`, `ema{9,20,21,50,200}`, `vwap`, `atr`, `adx`, `previous`…
- Robustesse EMA9: si l’extension TRADER retourne 0.0 sur de très petits prix, un fallback pur‑PHP est utilisé pour éviter des artefacts à zéro (avec log d’avertissement + échantillon de klines).

### Commandes utiles
- `app:indicator:contracts:validate <tf> [--compiled] [--side=long|short] [--limit=150]`
  - Mesure le temps de fetch vs validation, affiche par symbole et liste les “passés”.
- `app:indicator:conditions:diagnose <symbol> <tf> [--side=...] [--limit=150] [--no-json] [--json-results]`
  - Liste des conditions pour le scope, charge des klines via Provider et évalue via le registre compilé.
- `app:indicators:get <symbol> <tf> [--limit=100] [--all-conditions|--conditions=...]`
  - Calcule une liste d’indicateurs (DTO) et peut évaluer un sous‑ensemble de conditions.
- `indicator:snapshot create|compare|list [...]`
  - Calcule/persist des snapshots d’indicateurs et compare les résultats.

### Exemples d’usage
```php
public function __construct(IndicatorMainProviderInterface $indicatorMain) { $this->ind = $indicatorMain; }

// Construire un contexte et évaluer en YAML
$engine = $this->ind->getEngine();
$context = $engine->buildContext('BTCUSDT', '15m', $klines);
$evaluation = $engine->evaluateYaml('15m', $context);

// Lister/évaluer des conditions compilées
$names = $engine->listConditionNames();
$subset = array_slice($names, 0, 10);
$results = $engine->evaluateConditions($context, $subset);

// Calculer/persister un snapshot
$provider = $this->ind->getIndicatorProvider();
$snap = $provider->getSnapshot('BTCUSDT', '15m');
$provider->saveIndicatorSnapshot($snap);
```

### Performances et bonnes pratiques
- Pour le mode compilé, utiliser `--side=long|short` réduit le nombre de conditions évaluées.
- Restreindre `timeframes` et `side` dans les attributs des conditions pour éviter d’en évaluer trop.
- Comparer les moteurs: YAML (structure complète) vs Compilé (liste plate) — ne pas confondre les sémantiques.

### Organisation
- Contrats: `src/Contract/Indicator/` et `src/Contract/Provider/`
- Provider (façade): `src/Provider/MainProvider.php` et implémentations spécifiques (Bitmart, …)
- Indicator: `src/Indicator/`
  - `Core/` (implémentations de calcul internes)
  - `Context/` (construction du contexte)
  - `Condition/` (conditions + attributs)
  - `Compiler/` (compiler pass)
  - `Registry/` (registre compilé)
  - `Provider/` (façades Engine/Indicator)
  - `Loader/` et `ConditionLoader/` (moteur YAML)

### Dépannage rapide
- `cache:clear` échoue avec un argument inconnu → vérifier les arguments nommés dans `services.yaml`/constructeurs.
- EMA9 ≈ 0 avec close > 0 → les logs incluent un tail des klines; la voie pur‑PHP est activée par défaut en fallback.
