# OrderPlan / ExecutionPort module

## Objectif

PR09 introduit un module `App\TradingCore\OrderPlan` et un contrat
`App\TradingCore\Execution` explicites, testables et preparatoires.

Cette PR ne branche pas le module dans `TradeEntry`, ne modifie pas les YAML et
ne change pas le comportement runtime. Elle formalise le contrat cible entre :

- `TradeCandidate` ;
- `EntryZone` ;
- `Risk / Leverage` ;
- `SLTP / ProtectionPlan` ;
- `OrderPlan` ;
- `ExecutionPort` ;
- les gateways exchange.

## Source runtime actuelle

Le runtime legacy reste le suivant :

```text
TradingDecisionHandler
  -> TradeEntryRequestBuilder
  -> TradeEntryRequest
  -> BuildOrderPlan
  -> OrderPlanBuilder
  -> ExecuteOrderPlan
  -> ExecutionBox / ExchangeExecutionService
  -> ProtectionEnforcer
  -> exchange adapter
```

Le plan d'ordre executable est construit aujourd'hui dans
`App\TradeEntry\OrderPlan\OrderPlanBuilder`. Le modele transporte par le runtime
reste `App\TradeEntry\OrderPlan\OrderPlanModel`.

PR09 ne remplace pas ces classes dans le flux d'execution.

## Decisions legacy

| Decision | Source actuelle |
| --- | --- |
| `side` | `SymbolResultDto` puis `TradeEntryRequestBuilder`. |
| `order_type` | YAML `trade_entry.defaults.market_entry` et fallback maker/taker dans `OrderPlanBuilder`. |
| `quantity` | `OrderPlanBuilder`, avec risk, stop, contract size et clamps exchange. |
| `entry_price` | `OrderPlanBuilder`, carnet, hint, quantization et `EntryZone`. |
| `leverage` | `OrderPlanBuilder`, `DynamicLeverageService`, caps preflight et ajustements execution. |
| `stop_loss` | `OrderPlanBuilder` puis `ProtectionEnforcer` cote execution. |
| `take_profit` | `OrderPlanBuilder`, en R et/ou pivot selon profil. |
| `client_order_id` | `IdempotencyPolicy`. |
| `idempotency_key` | `DecisionKeyFactory` et `OrderIntentManager`. |
| `dry_run/live` | `TradingDecisionHandler`, puis `BuildOrderPlan` ou `ExecuteOrderPlan`. |
| `exchange` | `ExchangeContext`, avec fallback Bitmart legacy. |
| `market_type` | `ExchangeContext`, avec perpetual legacy. |

Les side effects exchange restent concentres dans `PreTradeChecks`,
`ExecutionBox`, `ExchangeExecutionService`, `ProtectionEnforcer`,
`EmergencyCloseService` et les adapters provider.

## Contrat cible

`App\TradingCore\OrderPlan\Dto\OrderPlan` represente le plan cible apres
composition des modules TradingCore :

```text
symbol / instrument
profile
exchange
market_type
side
order_type
margin_mode
time_in_force
entry_price
quantity
leverage
protection_plan
client_order_id
idempotency_key
decision_key
entry_zone
risk_calculation
leverage_calculation
metadata
```

Le plan porte aussi un `OrderPlanValidationResult`. Par defaut, un `OrderPlan`
non valide explicitement est non executable.

`OrderPlanValidator` formalise les contraintes minimales :

```text
OrderPlan invalide si symbole absent
OrderPlan invalide si instrument absent
OrderPlan invalide si profile absent
OrderPlan invalide si exchange absent
OrderPlan invalide si market_type absent
OrderPlan invalide si side inconnu
OrderPlan invalide si type d'ordre inconnu
OrderPlan invalide si margin_mode absent ou hors isolated/cross
OrderPlan invalide si time_in_force absent ou hors gtc/fok/ioc/post_only
OrderPlan invalide si entry_price <= 0 ou non fini
OrderPlan invalide si quantity <= 0 ou non finie
OrderPlan invalide si leverage <= 0
OrderPlan invalide si contract_size fourni <= 0 ou non fini
OrderPlan invalide si client_order_id absent
OrderPlan invalide si idempotency_key absente
OrderPlan invalide si ProtectionPlan absent
OrderPlan invalide si ProtectionPlan invalide
OrderPlan invalide si stop-loss absent
OrderPlan invalide si stop-loss non full size
OrderPlan invalide si stop_price <= 0 ou non fini
OrderPlan invalide si stop_pct <= 0 ou non fini
OrderPlan invalide si stop_distance <= 0 ou non fini
OrderPlan perpetual invalide si liquidation guard absent
OrderPlan perpetual invalide si liquidation guard unsafe
```

Cette validation cible ne change pas le runtime legacy. Elle prepare la regle :
aucun plan live executable ne peut etre considere valide sans stop-loss
automatique full size.

## ExecutionPort

`App\TradingCore\Execution\Port\ExecutionPortInterface` formalise le futur port
d'execution :

```text
ExecutionRequest -> ExecutionResult
```

`ExecutionRequest` transporte :

- le `OrderPlan` cible ;
- le mode `dry_run` ou `live` ;
- les metadata d'orchestration ;
- l'horodatage de demande.

Le mode live exige un plan executable. Le mode dry-run reste autorise avec un
plan invalide pour permettre inspection, audit et comparaison avec le legacy.

Le boundary live ne fait pas confiance a la validation portee par le DTO. Avant
de construire une requete live, `ExecutionRequest::forPlan()` revalide le plan
avec `OrderPlanValidator` et remplace la validation stale par le resultat frais.
Un appelant ne peut donc pas rendre un plan live executable en injectant un
`OrderPlanValidationResult` forge.

`ExecutionResult` transporte un `ExecutionStatus` enum, pas une string libre :

```text
dry_run
accepted
rejected
failed
skipped
```

PR09 n'ajoute aucune implementation live du port. Le placement cible du port
est entre `ExecuteOrderPlan` et les gateways exchange, apres validation du plan
et avant tout side effect provider.

## Client order id

`ClientOrderIdFactory` formalise la generation cible du `client_order_id`
depuis une cle d'idempotence :

```text
client_order_id = CID + sha256(idempotency_key)[0:29]
```

Le format reste alphanumerique et borne a 32 caracteres pour rester compatible
avec les contraintes legacy Bitmart. La factory est deterministe et ne produit
pas d'identifiant si la cle d'idempotence est vide.

PR09 ne remplace pas `App\TradeEntry\Policy\IdempotencyPolicy` dans le runtime.
Elle rend seulement le contrat cible explicite.

## Mapper legacy

`LegacyOrderPlanMapper` convertit un `App\TradeEntry\OrderPlan\OrderPlanModel`
vers le DTO cible sans inventer de protection et sans modifier les valeurs
d'execution.

Il preserve les champs utiles dans `metadata` :

- stop legacy ;
- take-profit legacy ;
- source finale du stop ;
- entry zone min/max ;
- taille de zone ;
- mode d'ordre legacy ;
- precision prix ;
- contract size.

Comme le modele legacy ne transporte pas encore un `ProtectionPlan`, le plan
mappe reste invalide pour execution cible live. Ce comportement est volontaire :
la future migration devra brancher explicitement `SLTP / ProtectionPlan`.

Quand un `decisionKey` legacy est fourni au mapper preparatoire, il devient la
`idempotency_key` cible et sert a deriver un `client_order_id` deterministe.
Quand il est absent, le plan cible reste invalide pour execution live.

## Idempotence

PR09 ne deplace pas l'idempotence runtime.

Les sources legacy restent :

- `DecisionKeyFactory` pour la cle de decision ;
- `OrderIntentManager` pour l'intent persiste ;
- `IdempotencyPolicy` pour le `client_order_id`.

Le DTO cible transporte `client_order_id`, `idempotency_key` et `decision_key`
et `OrderPlanValidator` les rend obligatoires pour un plan executable. PR09 ne
modifie pas les cles existantes dans le runtime.

## Non branche dans PR09

PR09 ne branche pas :

- `OrderPlan` TradingCore dans `BuildOrderPlan` ;
- `OrderPlanValidator` dans `ExecuteOrderPlan` ;
- `ExecutionPortInterface` dans `ExecutionBox` ;
- les gateways exchange dans un nouveau port ;
- `EffectiveTradingConfigResolver` dans le runtime.

PR09 ne modifie pas :

- `mtf:run` ;
- `POST /api/mtf/run` ;
- Temporal ;
- les schedules ;
- les regles MTF ;
- les decisions `READY` / `REJECTED` ;
- EntryZone ;
- Risk / Leverage ;
- SLTP / LiquidationGuard ;
- les valeurs SL/TP, risk ou leverage dans les YAML ;
- Bitmart, OKX ou Hyperliquid live.

## Contraintes avant branchement live

Avant tout branchement runtime, il faudra :

- comparer les plans TradingCore avec `OrderPlanBuilder` sur un echantillon de
  decisions ;
- brancher explicitement `ProtectionPlan` depuis PR08 ;
- verifier que le SL attache est full size dans tous les payloads exchange ;
- rendre l'idempotence obligatoire avant tout side effect ;
- separer les side effects exchange derriere des gateways testables ;
- conserver un chemin dry-run capable d'inspecter les plans invalides ;
- prouver que les quantites, stops, TP, levier et prix d'entree ne changent pas.

## Suite PR10

PR10 devrait traiter un gateway `Fake / Paper` ou une premiere implementation
adapter du `ExecutionPort`.

Objectif recommande :

- executer un `OrderPlan` cible valide sans toucher aux exchanges live ;
- verifier l'idempotence sans side effects reels ;
- comparer les payloads legacy et TradingCore avant tout branchement live.
