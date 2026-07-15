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

Comportement par defaut, sans scenario explicite :

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

## Minimum COMMON-005 pour recette demo

COMMON-005 ajoute un mode scenario optionnel au `FakeExecutionPort`. Ce mode reste
local, en memoire et sans side effect exchange. Il sert uniquement a prouver les
branches de protection avant une future recette demo/testnet OKX ou Hyperliquid.

Le comportement historique ci-dessus reste inchange quand aucun scenario n'est
passe au constructeur.

Fixtures PHP :

- `FakeExecutionScenarioFixtures::orderAccepted()`
- `FakeExecutionScenarioFixtures::orderRejected()`
- `FakeExecutionScenarioFixtures::fullFillStopAttachSuccess()`
- `FakeExecutionScenarioFixtures::fullFillStopAttachFailure()`
- `FakeExecutionScenarioFixtures::partialFillStopRejected()`
- `FakeExecutionScenarioFixtures::cancelAcceptedOrder()`

Fixture JSON de recette :

```text
trading-app/tests/fixtures/fake-paper/demo-recipe-scenarios.json
```

Scenarios couverts :

| Scenario | Resultat attendu |
| --- | --- |
| `order_accepted` | Ordre accepte sans fill, aucune protection demandee. |
| `order_rejected` | Refus structure avec `reject_reason=fake_exchange_rejected_order`. |
| `full_fill_stop_attach_success` | Fill complet, stop attache full-size, aucun flag qualite. |
| `full_fill_stop_attach_failure` | Fill complet, echec d'attache stop, `fail_safe.action=cancel_or_reduce_only_close_required`, resultat `failed`. |
| `partial_fill_stop_rejected` | Fill partiel, stop partiel refuse explicitement, flags `partial_fill` et `partial_stop_rejected`, resultat `failed`. |
| `cancel_accepted_order` | Annulation simulee sans fill, resultat `skipped`. |

Pour les scenarios, `ExecutionResult::raw` expose `order`, `fills`,
`protection` et, en cas d'echec de protection, `fail_safe`. Les flags qualite
sont dans `ExecutionResult::metadata.quality_flags`. Un fill ou stop incomplet
n'est jamais presente comme protege : `metadata.demo_recipe_protected=false`.

Le port garde un etat memoire minimal par `client_order_id` en mode scenario :

- rejoue le meme `client_order_id` => `duplicate_client_order_id`, aucun second fill ;
- `snapshot()` exporte l'etat memoire ;
- `FakeExecutionPort::fromSnapshot(..., scenario: ...)` recharge cet etat en
  conservant le mode scenario necessaire aux gardes de replay ;
- `resyncPosition(client_order_id)` reconstruit une position simulee depuis les fills.

Cette resynchronisation est un outil de recette locale, pas une source de verite
exchange.

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
- Aucune persistance ni audit reel : la gateway est en memoire.
- COMMON-005 ne couvre pas tout #196 : pas de carnet d'ordres, pas de latence,
  pas de funding, pas de ledger persistant, pas de matching multi-ordres, pas de
  websocket et pas de reconciliation exchange reelle.
- Les scenarios Fake/Paper ne certifient ni OKX demo ni Hyperliquid testnet. Ils
  prouvent seulement que les branches locales de protection sont visibles avant
  toute tentative mutative.

## Reprise persistante du Fake Exchange

Le fake exchange de niveau adapter (`App\Exchange\Fake\*`), distinct du
`FakeExecutionPort` TradingCore en memoire, conserve son etat HTTP local dans
`var/fake_exchange_state.dat`. Son format v1 persiste ensemble les ordres,
positions, balances, protections, evenements, index d idempotence et prochaine
sequence d evenement.

Le fichier porte une version de format, une version moteur, un hash de
configuration de scenario et un checksum. Une ecriture passe par un fichier
temporaire puis un remplacement atomique. Au restart, un etat present mais
corrompu ou incompatible echoue explicitement avec
`FakeExchangeStateCorruptedException`; il n est jamais transforme silencieusement
en etat vide. L ancien payload non versionne reste lisible et est migre lors de
la prochaine ecriture.

Cette reprise prouve uniquement la continuite locale Fake/Paper. Elle ne remplace
ni une reconciliation REST/WS exchange, ni le ledger PostgreSQL, et n active
aucune permission demo, testnet ou live.

## Rollback

Le rollback de COMMON-005 consiste a retirer le mode scenario et revenir au
`FakeExecutionPort` par defaut. Aucun schema, aucun secret, aucune config runtime
et aucun endpoint n'est modifie. Les tests de recette demo peuvent alors revenir
au simple resultat `dry_run` deterministe.

## Suite

Le Paper complet reste suivi par #196. OKX demo et Hyperliquid testnet doivent
continuer a passer par leurs PR dediees avec activation explicite, sans mainnet
et sans fallback silencieux vers Bitmart. Le `FakeExecutionPort` servira de
reference de comparaison pour ces adapters.
