# Risk / Leverage module

## Objectif

PR07 introduit un module `App\TradingCore\Risk` explicite, testable et
preparatoire.

Cette PR ne branche pas le module dans `TradeEntry`, ne modifie pas les YAML et
ne change pas le comportement runtime. Elle formalise les contrats cibles pour
le calcul du risque, de la taille de position et du levier derive du stop.

## Source de verite actuelle

Le runtime legacy utilise encore le flux suivant :

```text
TradingDecisionHandler
  -> TradeEntryRequestBuilder
  -> TradeEntryRequest
  -> OrderPlanBuilder
  -> DynamicLeverageService
  -> ExecutionBox / ExchangeExecutionService
```

La source effective du risque pour la taille de position est actuellement :

```text
trade_entry.defaults.risk_pct_percent
  -> TradeEntryRequestBuilder
  -> TradeEntryRequest::riskPct
  -> OrderPlanBuilder
```

`OrderPlanBuilder` calcule ensuite :

```text
available_budget = min(initial_margin_usdt, preflight.available_usdt)
risk_usdt = available_budget * TradeEntryRequest::riskPct
size = floor(risk_usdt / (stop_distance * contract_size))
```

`risk.fixed_risk_pct` existe dans certains YAML historiques, mais il n'est pas
la source branchee du runtime TradeEntry actuel pour la taille de position.
Le nouveau `RiskConfigInterpreter` documente donc cette ambiguite au lieu de la
masquer.

## Champs legacy

| Champ | Statut PR07 | Interpretation |
| --- | --- | --- |
| `defaults.risk_pct_percent` | Source runtime actuelle | Converti en ratio dans `TradeEntryRequestBuilder`, puis utilise par `OrderPlanBuilder`. |
| `risk.fixed_risk_pct` | Canonical cible / non branche runtime | Champ explicite du module cible. A documenter comme non effectif tant que TradeEntry n'est pas migre. |
| `defaults.initial_margin_usdt` | Runtime actuel | Budget nominal borne par le solde disponible preflight. |
| `defaults.fallback_account_balance` | Runtime actuel partiel | Utilise seulement si `initial_margin_usdt <= 0`, pour reconstruire une marge initiale de fallback. |
| `defaults.stop_from` | Runtime actuel | Peut etre `risk`, `atr` ou `pivot` selon profil. |
| `defaults.atr_k` | Runtime actuel | Utilise pour le stop ATR et pour les approximations de levier dans certains contextes MTF. |
| `leverage.exchange_cap` | Runtime actuel | Cap config en plus des caps exchange preflight. |
| `leverage.per_symbol_caps` | Runtime actuel | Caps regex par symbole dans `DynamicLeverageService`. |
| `defaults.timeframe_multipliers` | Runtime actuel | Multiplicateur applique au levier dynamique dans `DynamicLeverageService`. |
| `leverage.timeframe_multipliers` | Runtime actuel | Multiplicateur applique plus tard a la taille et au levier dans les couches execution. |
| `leverage.confidence_multiplier` | Documente / peu ou pas branche | Garde pour contrat cible, sans effet PR07. |
| `leverage.conviction` | Documente / peu ou pas branche | Garde pour contrat cible, sans effet PR07. |
| `leverage.max_loss_pct` | Runtime execution | Cappe le multiplicateur de timeframe via taille max autorisee au SL. |
| `leverage.rounding.mode` | Runtime actuel | `ceil`, `floor` ou `round` selon etape. |

## Formule cible

Le module cible rend explicite :

```text
effective_risk_pct = fixed_risk_pct ou legacy risk_pct_percent
risk_usdt = capital_base * effective_risk_pct
stop_pct = abs(entry_price - stop_price) / entry_price, ou stop_pct fourni
position_notional = risk_usdt / stop_pct
quantity = position_notional / entry_price
raw_leverage = risk_pct / stop_pct
final_leverage = raw_leverage
  * timeframe_multiplier
  * liquidity_multiplier
  puis caps, floor et rounding
```

Dans le module pur, `fixed_risk_pct` est le champ canonique quand il est fourni.
Le mapper legacy peut cependant choisir de ne pas le passer au calcul tant que
le runtime reste base sur `defaults.risk_pct_percent`.
Dans PR07, `RiskConfigInterpreter` marque explicitement les requetes issues du
runtime legacy pour que `PositionSizer` preserve `defaults.risk_pct_percent`
quand les deux champs sont presents.

## Formule actuelle si differente

Le calcul live actuel contient des etapes supplementaires :

- le stop peut venir du risque, de l'ATR ou d'un pivot ;
- un stop pivot trop loin peut fallback vers ATR ou risk ;
- une garde minimale de distance stop a 0.5 % peut elargir le stop ;
- la taille est quantifiee et bornee par `minVolume`, `maxVolume` et
  `marketMaxVolume` ;
- le levier peut etre ajuste ensuite pour rapprocher la marge initiale de la
  marge cible ;
- les couches execution peuvent encore appliquer `leverage.timeframe_multipliers`
  et `max_loss_pct`.

PR07 ne deplace pas ces etapes.

## Caps de levier

Le nouveau `LeverageCapResolver` formalise les caps suivants :

```text
exchange_cap
profile_cap
symbol_cap
```

Le runtime actuel applique principalement :

- cap preflight exchange via `PreflightReport::maxLeverage` ;
- `TradeEntryRequest::leverageExchangeCap`, issu de `leverage.exchange_cap` ;
- `leverage.exchange_cap` dans `DynamicLeverageService` ;
- caps regex `leverage.per_symbol_caps` ;
- `floor` ;
- min/max exchange ;
- rounding.

`profile_cap` est present dans le contrat cible pour les futures configs
effectives, mais il n'est pas branche dans PR07.

## max_loss_pct

`leverage.max_loss_pct` n'est pas applique au `raw_leverage` dans le plan
initial.

Il intervient plus tard dans `ExecutionBox` et `ExchangeExecutionService` pour
limiter le multiplicateur de timeframe :

```text
max_loss_usdt = initial_margin_usdt * max_loss_pct
risk_per_contract = abs(entry - stop) * contract_size
max_size_allowed = floor(max_loss_usdt / risk_per_contract)
effective_multiplier = min(tf_multiplier, max_size_allowed / plan.size)
```

Le module PR07 le transporte et genere un warning pour rappeler qu'il s'agit
d'un cap execution-time, pas d'un composant du `raw_leverage`.

## Liquidation guard

Le guard live actuel (`App\TradeEntry\Policy\LiquidationGuard`) verifie surtout
que la distance entre entry et stop n'est pas nulle. La formule exacte de
liquidation reste a brancher.

PR07 ne modifie pas ce guard. La suite logique est PR08, qui doit traiter SLTP
et `LiquidationGuard` avec un contrat dedie.

## Contraintes avant branchement live

Avant de brancher ce module dans `TradeEntry`, il faudra :

- comparer les resultats du module avec les plans legacy sur un echantillon de
  decisions ;
- verifier que `defaults.risk_pct_percent` et `risk.fixed_risk_pct` ne creent
  pas deux sources contradictoires ;
- decider explicitement si le runtime migre vers `risk.fixed_risk_pct` ou garde
  `defaults.risk_pct_percent` comme source effective ;
- couvrir les ajustements de marge de `OrderPlanBuilder` ;
- couvrir les multipliers execution et `max_loss_pct` ;
- verifier que le levier final ne peut pas augmenter par rapport au legacy ;
- conserver les checks `mtf:run`, `/api/mtf/run`, container lint et mkdocs.

## Ajouts PR07

Namespace ajoute :

```text
App\TradingCore\Risk
```

DTOs et enum :

- `Dto\RiskCalculationRequest` ;
- `Dto\RiskCalculationResult` ;
- `Dto\LeverageCalculationRequest` ;
- `Dto\LeverageCalculationResult` ;
- `Enum\RiskSource`.

Services :

- `Service\PositionSizer` ;
- `Service\LeverageCalculator` ;
- `Service\RiskConfigInterpreter` ;
- `Service\LeverageCapResolver`.

## Non branche dans PR07

PR07 ne branche pas :

- `PositionSizer` TradingCore dans `OrderPlanBuilder` ;
- `LeverageCalculator` TradingCore dans `DynamicLeverageService` ;
- `RiskConfigInterpreter` dans `TradeEntryRequestBuilder` ;
- `EffectiveTradingConfigResolver` dans le runtime ;
- `LiquidationGuard` cible.

PR07 ne modifie pas :

- `mtf:run` ;
- `POST /api/mtf/run` ;
- Temporal ;
- les schedules ;
- les regles MTF ;
- les decisions `READY` / `REJECTED` ;
- EntryZone ;
- SL / TP ;
- ExecutionPort ;
- les valeurs risk/leverage dans les YAML ;
- Bitmart, OKX ou Hyperliquid live.

## Tests

Tests ajoutes :

- `tests/TradingCore/Risk/RiskConfigInterpreterTest.php` ;
- `tests/TradingCore/Risk/PositionSizerTest.php` ;
- `tests/TradingCore/Risk/LeverageCalculatorTest.php`.

Ils couvrent :

- source runtime legacy `defaults.risk_pct_percent` ;
- fallback `risk.fixed_risk_pct` si la source legacy est absente ;
- warning quand deux champs de risque coexistent ;
- sizing depuis `risk_usdt / stop_pct` ;
- rejet d'un `stop_pct` nul ;
- levier brut `risk_pct / stop_pct` ;
- caps exchange/profil/symbole ;
- floor et rounding ;
- representation de `max_loss_pct` sans changer le calcul initial ;
- absence de levier arbitraire sans stop valide.
