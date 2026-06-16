# Fake / Paper gateway

## Objectif

PR10 ajoute deux pièces préparatoires au-dessus du module `OrderPlan / ExecutionPort`
livré en PR09 :

1. `App\TradingCore\OrderPlan\Service\OrderPlanBuilder` — l'assembleur minimal qui
   construit un `OrderPlan` à partir des DTOs TradingCore.
2. `App\TradingCore\Execution\Fake\FakeExecutionPort` — la première implémentation de
   `ExecutionPortInterface`, une gateway **Fake / Paper** qui simule une exécution
   **sans aucun ordre réel ni side effect**.

PR10 ne branche rien dans le runtime : ni `mtf:run`, ni `POST /api/mtf/run`, ni Temporal,
ni `TradeEntry`, ni les YAML stratégie.

## OrderPlanBuilder

`OrderPlanBuilder::build(OrderPlanBuildRequest): OrderPlan` complète PR09 : la PR09 avait
livré le DTO `OrderPlanBuildRequest` sans consommateur. Le builder :

- assemble `TradeCandidate + EntryZone + RiskCalculationResult + LeverageCalculationResult + ProtectionPlan` ;
- dérive `entry_price = entryZone.center`, `quantity = risk.quantity`, `leverage = leverage.finalLeverage`,
  `side = candidate.direction`, exchange/market_type depuis le candidate ;
- dérive `client_order_id` depuis l'`idempotency_key` via `ClientOrderIdFactory` (même contrat que `LegacyOrderPlanMapper`) ;
- **réutilise `OrderPlanValidator`** : le plan est retourné avec sa validation fraîche ;
- ne lève pas d'exception sur input manquant : il produit un `OrderPlan` invalide
  (entry/quantity/leverage dérivés à 0, protection absente) pour permettre l'inspection,
  et expose `metadata.build_missing_inputs` pour l'audit.

Le builder est **pur** : aucune dépendance Symfony, Doctrine, Messenger, provider concret
ou HTTP. Il ne passe aucun ordre.

## FakeExecutionPort

`FakeExecutionPort implements ExecutionPortInterface` est la gateway de test canonique.
Elle respecte strictement l'interface existante : `execute(ExecutionRequest): ExecutionResult`.

Comportement :

| Cas | Résultat |
| --- | --- |
| `ExecutionMode::Live` | `ExecutionStatus::Rejected` (`live_not_supported_by_fake_gateway`), aucun side effect. |
| `DryRun` + plan non exécutable (revalidé) | `ExecutionStatus::Rejected` + `invalid_reasons`. |
| `DryRun` + plan exécutable | `ExecutionStatus::DryRun` (succès simulé), `exchange_order_id = FAKE-{client_order_id}`. |

Propriétés :

- aucun appel HTTP, aucun provider réel, aucun side effect exchange ;
- `client_order_id` et `idempotency_key` conservés (dans le résultat et sa metadata) ;
- mode (`dry_run`) conservé dans la metadata ;
- fake order id **déterministe** : même plan ⇒ même `exchange_order_id` ;
- gateway **sans état** : deux appels identiques donnent un résultat identique ;
- au boundary, la validation portée par le DTO n'est pas crue : `FakeExecutionPort` revalide
  via `OrderPlanValidator` avant de simuler.

Elle est distincte du fake exchange legacy `App\Exchange\Fake\*` (simulation au niveau
adapter / websocket du provider) : la gateway PR10 vit dans le Core et reste pure.

## Pourquoi Fake / Paper avant OKX / Hyperliquid

La gateway Fake / Paper permet d'exercer toute la chaîne
`TradeCandidate → … → OrderPlan → ExecutionPort` sans risque, avant d'écrire le moindre
adapter live. Elle sert de référence pour :

- vérifier l'idempotence sans side effects réels ;
- comparer les payloads legacy `TradeEntry` et TradingCore avant tout branchement ;
- fournir une base déterministe au backtesting / paper-trading futur.

Les gateways OKX et Hyperliquid restent en dry-run / runtime-check (PR11 / PR12). Bitmart
reste legacy.

## Hors-scope PR10

- aucune **gate readiness live** OKX / Hyperliquid — reportée à PR11 / PR12 ;
- aucun branchement runtime (`mtf:run`, `/api/mtf/run`, Temporal, `TradeEntry`, `EffectiveTradingConfigResolver`) ;
- aucun changement EntryZone / Risk / Leverage / SL-TP / YAML ;
- aucune suppression Bitmart, aucun ordre live.

## Limites restantes

- `OrderPlanBuildRequest` porte un `EntryZone`, pas un `EntryZoneDecision` : le statut
  « zone acceptée » n'est pas re-vérifié au build (le builder suppose la décision déjà prise en amont).
- `entry_price = entryZone.center` simplifie le pricing legacy (carnet, hint, quantization) ;
  acceptable hors live, à rapprocher du legacy avant tout branchement.
- Aucune persistance ni audit réel : la gateway est en mémoire.

## Suite

PR11 : OKX dry-run + gate readiness live. PR12 : Hyperliquid dry-run. Le `FakeExecutionPort`
servira de référence de comparaison pour ces adapters.
