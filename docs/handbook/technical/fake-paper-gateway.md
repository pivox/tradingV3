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
- COMMON-005 ne couvre pas tout #196 : sa gateway TradingCore en memoire ne porte
  ni funding ni ledger. Le Fake Exchange de niveau adapter couvre maintenant le
  funding persistant decrit plus bas, mais pas la latence, le matching
  multi-ordres ni une reconciliation exchange reelle.
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

## Compensation d echec d attachement SL

Le Fake Exchange de niveau adapter traite maintenant le rejet d une protection
attachee apres fill complet comme une sequence fail-closed :

1. le fill d entree et `protection_status=rejected` restent visibles ;
2. un ordre market reduce-only deterministe retire exactement la quantite fillee
   par l entree en echec via le chemin normal du matching engine ;
3. les couts, le lineage, `order.filled` et les evenements de position sont
   produits par les mecanismes ordinaires ;
4. l entree conserve les identifiants de compensation, l action
   `reduce_only_market_close`, le statut `completed` et la preuve que le fill
   fautif a ete retire sans exposer la position residuelle.

Le replay du `client_order_id` d entree restitue les memes identifiants sans
second fill compensatoire. La sequence complete appartient a la transaction de
l etat Fake. Une entree isolee devient plate. Si l entree augmentait une position
anterieure protegee, seule cette augmentation est retiree et la taille residuelle
doit rester integralement couverte par un stop actif. Une quantite fermee
incorrecte ou un residuel non protege provoque un rollback au snapshot precedent.
Cette integration adapter est distincte de la fixture declarative
`FakeExecutionPort::fullFillStopAttachFailure()` de COMMON-005.

## Injection deterministe des erreurs adapter

Le Fake Exchange de niveau adapter expose une file de fautes typees via
`FakeExchangeScenarioService::failNext()`. Les fixtures supportees sont :

| Kind | Statut HTTP normalise | Contexte |
| --- | --- | --- |
| `network_timeout` | absent | timeout immediat sans attente reelle |
| `transport_error` | absent | echec transport generique |
| `http_429` | `429` | `retry_after_seconds` positif obligatoire |
| `http_500` | `500` | erreur serveur deterministe |

Le resultat `not_applied` consomme la faute avant toute mutation. Pour
`place_order` et `cancel_order`, `applied_response_lost` applique d abord la
mutation puis leve `FakeExchangeInjectedException` avec `outcome_unknown=true`.
Ce second mode reproduit une reponse perdue : le caller doit rejouer avec les memes
identifiants, et le Fake restitue alors l ordre ou l annulation deja appliquee sans
dupliquer les evenements.

La file est FIFO, isolee par operation et persistee dans l enveloppe
`fake-paper-state-v1`. Une faute armee survit donc au restart. Pour un resultat
`applied_response_lost`, la mutation et le retrait de la faute sont commits dans
le meme remplacement atomique du fichier d etat. Un no-op ou une exception restaure
l etat et conserve la faute. Le contexte d erreur ne contient que le kind,
l operation, le resultat, le statut HTTP normalise et le retry-after. Aucun payload
brut, credential, URL ou header sensible n est conserve.

Ce contrat couvre les erreurs adapter REST simulees. Il ne modelise pas encore un
quota glissant, la latence/jitter avec seed, les erreurs de precision/marge, ni les
divergences Bitmart. La deconnexion et le resync private WS sont couverts par la
fixture separee `FakeExchangeWsClient`.

## Runtime-check Fake/Paper

La commande suivante controle l adapter local sans credential ni appel reseau :

```bash
php bin/console app:exchange:runtime-check fake perpetual
```

Elle verifie un carnet explicitement charge, la lecture des balances, une horloge
explicitement controlee, le modele de frais, la capacite stop-loss et l ecriture
puis la reprise d un fichier de sonde distinct. L etat actif (ordres, fills,
positions, evenements, balances et fautes injectees) n est jamais modifie par cette
sonde. Un fichier persistant ne suffit pas a qualifier le mode Paper : une source
marche reelle ou replay doit aussi etre configuree.
Si le fichier d etat actif est absent, la commande sonde uniquement son repertoire
et ne cree pas ce fichier.

Le resultat Fake/Paper impose toujours `dry_run=true`, `permissions_trade=false`,
kill switch actif et ecriture demo/testnet desactivee. Les credentials sont
`not_required`; le provider bundle Fake est operationnel uniquement pour le
contexte `fake/perpetual` et ne peut emettre aucun ordre exchange.
Les fills taker publient un cout adverse deterministe de 5 bps sous
`fixed_adverse_slippage_bps_v1`; les fills maker post-only publient zero. Le prix
d execution reste le top of book, donc le spread additionnel est explicitement
zero sous `top_of_book_embedded_spread_v1`. Un modele absent, nul, malforme ou non
supporte bloque la readiness avec `fake_paper_slippage_model_not_ready`.

Le modele d instrument versionne utilise Brick Math sur les textes decimaux
exacts. L endpoint HTTP Fake exige des chaines decimales pour `quantity`, `price`
et les prix stop; il refuse les nombres JSON dont la precision lexicale serait
perdue au decodage. Les metadata de requete sont reduites a une whitelist de
lineage scalaire avant persistance et emission d evenement.

La readiness reste fail-closed si la source marche, l horloge controlee, les
fixtures versionnees, le modele de precision ou la reprise persistante ne sont
pas disponibles. La marge d un SELL limit crossing est verifiee avec le meilleur
bid executable, et un `LIMIT` legacy accompagne d un `stopPrice` reste un ordre
declenche au lieu d etre rempli immediatement.

## Funding deterministe Fake/Paper v1

Le modele `fake-funding-notional-rate-interval-v1` consomme uniquement une
echeance explicite, un taux nullable, les intervalles de taux/application et un
snapshot de position perpetuelle a l horloge Fake controlee. Le calcul decimal
est :

```text
notional = abs(size) * mark_price * contract_size
amount = notional * rate * applied_interval / rate_interval
LONG = -amount ; SHORT = +amount
```

Le signe monetaire normalise est donc credit positif et debit negatif. Les taux
positifs font payer les longs et creditent les shorts; les taux negatifs font
l inverse. Le snapshot exact a l echeance permet de facturer une position
partielle. Sans position, aucun montant n est produit. Un taux absent donne le
statut `unknown` et aucun evenement : il n est jamais transforme en zero.

Chaque application persiste un evenement `funding.accrued` dans l etat
`fake-paper-state-v1`. Son identite est `position_id + due_at + model_version`.
Le replay identique, y compris apres restart, ne change ni le ledger ni la
sequence; un payload different sous la meme identite echoue avec
`fake_funding_idempotency_conflict`. Une echeance ancienne recue en retard garde
son identite propre et est appliquee une fois.

`FakeExchangeEventNormalizer` projette cet evenement en
`ExchangeFundingReceived`; `DoctrineExchangeLocalProjectionStore` ecrit alors
une row `fill_cost_ledger` avec `fill_role=funding`. Il ne cree ni fill entree,
ni fill sortie, ni ordre legacy. `internal_trade_id` et
`internal_position_id` sont conserves lorsqu ils sont disponibles. Les montants
USDT alimentent `funding_usdt`; une devise non normalisee conserve le montant
natif mais laisse `funding_usdt=NULL` avec
`funding_currency_not_normalized`.

La fixture versionnee est
`trading-app/tests/fixtures/fake-paper/funding-model-v1.json`. Le scenario golden
18 couvre long/short, taux positif/negatif/absent, partiel, devise inconnue,
duplicate, restart et retard hors ordre. Ce chemin est strictement local : aucun
client reseau, secret ou droit d ecriture demo/testnet/mainnet.

## Fallback taker deterministe de fin de zone

Le moteur Fake/Paper supporte une politique opt-in versionnee
`fake-fallback-taker-v1`, persistee dans les metadata d un ordre parent `LIMIT`
post-only. Elle contient un flag d activation, les bornes de zone valides et le
slippage adverse maximal en points de base. La politique absente, malformee ou
desactivee echoue fermee. Seuls les cinq champs types de cette politique
traversent les metadata d une requete ordinaire ; les identifiants parent, le
reliquat, la quantite de protection, le trigger et le slippage mesure sont
derives et injectes par le moteur.

Quand la zone se termine,
`FakeExchangeScenarioService::fallbackTaker(exchange_order_id)` execute une
transition atomique locale :

- le parent actif expire une seule fois ; un parent deja expire avant restart
  peut reprendre sans dupliquer l evenement d expiration ;
- la quantite deja remplie en maker reste acquise et seul le reliquat decimal
  exact est soumis en `MARKET` ;
- le `client_order_id` enfant derive deterministement de l identite du parent ;
- le prix top-of-book executable doit rester dans la zone et sous le seuil de
  slippage adverse total, qui additionne le deplacement depuis la limite maker
  et les 5 bps du modele taker versionne ;
- precision, marge, rejet persiste, fill, frais et position passent par les
  chemins ordinaires du moteur Fake ;
- le cout maker reste separe du cout taker et la protection couvre la quantite
  logique totale parent plus enfant ;
- si l attachement de protection echoue, la compensation reduce-only ferme cette
  meme quantite logique totale.

Un parent annule ou sans reliquat ne genere aucun enfant. Si une annulation ou un
rejet zone/slippage/marge/validation laisse un fill maker partiel, son exposition
exacte recoit le SL/TP attache ; un rejet de cette protection compense ce fill
partiel. Le replay d un succes, d un rejet de garde ou d un restart persiste ne
cree aucun second ordre, fill, frais, evenement ou stop. Un enfant persiste n est
reconnu qu apres comparaison de son intention immutable complete et des metadata
terminales du parent. Les mutations directes du service de scenario rechargent
l etat persiste sous le meme verrou avant modification. Ce trigger est une
fixture Fake/Paper locale : il n appelle aucun provider, ne lit aucun credential
et ne peut ecrire ni en mainnet, ni en demo, ni en testnet.

## TP1 partiel puis trailing deterministe

Le moteur Fake/Paper accepte la politique opt-in versionnee
`fake-tp1-trailing-v1`. Elle est fournie uniquement par une fixture locale et
contient un flag actif, une quantite TP1 decimale exacte et un offset trailing
absolu en unite de prix. Elle n est lue dans aucun profil de strategie. Une
capability demandee mais incomplete, desactivee, malformee, sans SL/TP attaches,
ou dont la quantite TP1 n est pas strictement inferieure a la quantite d entree
echoue explicitement avant mutation. Sans aucune cle de cette politique, le
comportement SL/TP historique reste inchange.

Le SL initial couvre toute l exposition et TP1 couvre seulement la quantite
declaree par la fixture. Un fill incomplet de l ordre TP1 conserve le SL. Quand
TP1 atteint sa quantite configuree, la transaction fichier existante enchaine le
fill reduce-only, le ledger de couts, le reliquat decimal exact, l annulation du
SL avec `tp1_replaced_by_trailing`, puis la creation d un `TRIGGER` reduce-only
sur ce reliquat. La transition normalisee `trailing_stop.armed` porte le lineage
derive et redacted.

Si une sortie reduce-only manuelle a deja diminue la position avant la fin de
TP1, la quantite trailing est le minimum entre le reliquat protege planifie et
l exposition agregee encore ouverte apres TP1. Elle ne peut donc ni depasser la
position courante, ni absorber une exposition anterieure au-dela du reliquat de
l entree protegee. Le modele Fake agrege les positions par symbole et cote : il
ne pretend pas attribuer cette reduction manuelle a un lot d entree precis.

Si l entree utilise aussi le fallback taker de fin de zone, la politique trailing
est propagee a l enfant `MARKET`. La quantite TP1 est alors validee contre
l exposition logique totale parent plus enfant, jamais contre le seul petit
reliquat fallback. Le replay de l enfant compare les deux politiques persistantes.

Le `TRIGGER` est l etat trailing versionne et persistant : ses metadata portent
la version, le statut `active` ou `triggered`, l ordre TP1 d activation, le
watermark favorable, l offset fixe et les decimaux derives. Aucun registre BDD ni
migration n est ajoute. Un restart restaure donc watermark, stop, ordre, position
et sequence d evenements depuis `fake-paper-state-v1`.

L algorithme est monotone :

- long : `watermark = max(watermark, mid)` et
  `stop = watermark - offset` ; le stop ne peut que monter ;
- short : `watermark = min(watermark, mid)` et
  `stop = watermark + offset` ; le stop ne peut que descendre ;
- prix adverse ou duplique : aucune reecriture et aucun evenement ;
- nouveau watermark : une seule transition `trailing_stop.updated`.

Un gap au-dela du stop remplit au prochain bid/ask Fake disponible par le chemin
de fill ordinaire, ferme seulement le reliquat reduce-only, puis emet une seule
transition `trailing_stop.triggered`. La fermeture complete annule les
protections residuelles. Entree, TP1 et trailing gardent les frais, slippage,
spread, PnL et versions de modele du ledger normal ; un cout inconnu ne devient
pas zero silencieusement.

La race est serialisee par le verrou d etat. SL-first ferme et annule TP1 ; TP1
devient ensuite un no-op. TP1-first remplace atomiquement le SL ; le SL stale
devient un no-op. Une exception pendant le remplacement restaure ensemble
position, ordres, evenements et ledger. Les replays d entree comparent aussi la
politique immutable ; TP1, prix, gap ou fill terminal rejoues ne dupliquent ni
ordre, ni evenement, ni cout, ni PnL.

Les cas long et short sont explicites dans
`trading-app/tests/fixtures/fake-paper/tp1-trailing-v1.json`. Ils ne contactent
aucun reseau, ne lisent aucun secret et n activent ni demo, ni testnet, ni
mainnet.

## Private WS explicite duplicate et out-of-order

Le parcours ordinaire de `FakeExchangeWsClient` livre par #274 reste inchange :
chaque nouvelle instance garde son propre curseur de replay, la deconnexion
deterministe reste locale, et un acquittement n intervient qu apres projection
reussie.

Une fixture peut maintenant activer explicitement un
`FakePrivateWsScenario`, liste finie et ordonnee de `FakePrivateWsDelivery`.
Chaque entree porte un ID stable, une sequence declaree, l enveloppe brute
complete et un fingerprint SHA-256. Le fingerprint couvre type, symbole en
majuscules, timestamp ISO-8601 et payload complet. Les cles associatives sont
triees recursivement mais l ordre des listes est conserve. Le timestamp ne sert
jamais a trier les livraisons : seul l ordre ecrit dans la fixture fait foi.

Scenario, curseur, fingerprints acquittes, watermarks, etat de connexion,
compteurs et audit sont stockes dans le meme remplacement atomique checksumme
`fake-paper-state-v1` que les evenements Fake. Un ancien payload sans bloc
`privateWs` reste compatible et revient a un scenario inactif connecte. Un bloc
present mais malforme echoue avec `fake_exchange_state_shape_invalid`.

Le contrat runtime est le suivant :

- un doublon exact est elimine avant normalisation/projection et incremente
  `duplicate_total` ;
- une meme sequence avec un autre fingerprint incremente `conflict_total`,
  persiste `resync_required` et leve
  `fake_private_ws_sequence_conflict` ;
- une sequence numerique future incremente `gap_total`, persiste
  `resync_required` et leve `fake_private_ws_sequence_gap` ;
- aucun evenement suivant n est projete pendant cet etat et un simple
  `reconnect()` est refuse ;
- crash du client, exception de projection ou rollback DB avant reprise du
  generateur laisse le meme evenement disponible apres restart ;
- un lease de consommation non bloquant reste tenu pendant le `yield`; un
  second consommateur recoit `fake_private_ws_consumer_busy` avant lecture ou
  projection, pour un store memoire partage comme pour deux stores sur le meme
  `stateFile` ;
- le filtre symbole ne consomme jamais une livraison d un autre symbole.

La reprise impose d abord un `ExchangeReconciliationService` global sur les
snapshots REST Fake locaux. En mode scenario, `completeSnapshotResync()` exige
le `ExchangeReconciliationResult` Fake/Perpetual correspondant, avec
`symbol === null` et aucune erreur. Une preuve absente, echouee ou limitee a un
symbole ne modifie ni curseur ni `resync_required`. Ensuite seulement, la
sequence numerique maximale de l etat canonique sert de watermark : le curseur
avance dans l ordre declare sur toutes les livraisons couvertes, y compris `3`
puis `2`, et incremente `resync_total` une fois. Un evenement canonique ajoute
apres ce watermark prolonge la fixture active et reprend sur la sequence
contigue.

`privateWsAudit()` expose les cinq compteurs, l etat et la raison de resync, les
watermarks et au plus 100 enregistrements. Ces enregistrements sont rediges :
kind, codes/sequences, ID de fixture et prefixe de fingerprint uniquement, sans
payload brut, credential, URL ni header.

La DSL executable est
`trading-app/tests/fixtures/fake-paper/private-ws-out-of-order-v1.json`. Elle
prouve le doublon, `1,3,2`, le restart en resync, la reconstruction snapshot, la
reprise contigue et le conflit dans deux etats frais. Tout reste local : aucun
appel reseau exchange, aucune permission demo/testnet/mainnet et aucun
changement de strategie, MTF, EntryZone, sizing, levier, SL/TP, frais ou
slippage.

## Mode One-Way Fake/Paper

Le runtime supporte uniquement le mode One-Way pour l'adapter local
`fake/perpetual`. La cle d'exposition est exacte et deterministe :

```text
exchange + market_type + uppercase(symbol)
```

`positionSide` est obligatoire et n'est jamais deduit de BUY/SELL. Une entree
LONG utilise BUY, une entree SHORT utilise SELL, une sortie reduce-only LONG
utilise SELL et une sortie reduce-only SHORT utilise BUY. Aucun mode hedge,
netting arbitraire ou fallback Bitmart/autre exchange n'est disponible.

Pour toute nouvelle entree non reduce-only, `FakeOneWayConflictGuard` inspecte
les positions ouvertes et les ordres actifs non reduce-only du scope exact. Une
position ou entree active opposee produit `one_way_position_conflict`. Un ordre
actif legacy sans `positionSide` echoue ferme avec la meme raison et une source
`ambiguous_active_order`. Les sorties/protections reduce-only, les augmentations
du meme cote et les symboles differents restent independants. Apres cloture et
absence d'ordre d'entree incompatible, le cote oppose est autorise.

La garde s'execute apres le replay exact du `clientOrderId`, mais avant lecture
du carnet, calcul/reservation de marge, validation de marge et creation d'un
ordre actif. Le rejet seul est persiste comme ordre `rejected`, accompagne d'un
unique evenement `order.rejected`; il ne change ni exposition ni marge. Son
contexte contient uniquement version du mode, scope, cote demande, source et
cote conflictuel connu. Aucun payload brut, credential, header ou URL n'est
copie. Un replay identique renvoie le meme rejet avec
`idempotent_replay=true`. Le fichier checksume `fake-paper-state-v1` conserve
positions, ordres, rejet et sequence au restart sans migration.

Le scenario golden 19 `one_way_conflict` execute position LONG contre SHORT,
position SHORT contre LONG, sortie reduce-only, entree opposee apres cloture,
ordre actif sans position, replay, restart et symboles independants. Tout reste
local, sans credential, reseau exchange ou permission mutative
demo/testnet/mainnet.

Le rollback retire la garde, remet le scenario 19 a `unsupported` avec
`one_way_conflict_guard_not_implemented` et restaure la presente section. Aucun
schema ni format d'etat n'est a supprimer ; les rejets historiques restent des
preuves v1 ordinaires.

## Golden suite Fake/Paper v1

Le catalogue versionne des 20 scenarios obligatoires de #196 est disponible dans :

```text
trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json
```

Il utilise trois statuts stricts :

- `executable` : le scenario possede un runner deterministe et est execute deux fois
  depuis des etats frais ; les deux resultats normalises doivent etre identiques ;
- `partial` : une partie du comportement existe, mais le critere golden complet reste
  bloque par au moins un `gap_code` stable ;
- `unsupported` : la capability necessaire n existe pas encore et aucun runner ne
  peut etre declare.

Une ligne presente dans le catalogue n est pas un PASS. Seul le statut `executable`
avec un test vert constitue une preuve. Les lignes `partial` et `unsupported` ne
peuvent ni rendre le runtime-check ready, ni autoriser une mutation demo/testnet.

Les vingt scenarios executes dans cette version sont : maker limit rempli, limit
IOC expire sans fill, partial fill puis cancel, fallback taker de fin de zone sur
le reliquat exact, market avec slippage 5 bps, insufficient balance, precision
reject, leverage cap reject, replay du `client_order_id`, timeout apres
acceptation, attachement SL reussi, echec d attachement SL compense par fermeture
market reduce-only, TP1 partiel puis trailing persistant long/short, gap au SL au
prochain prix disponible, deconnexion/reprise private WS, duplicate/out-of-order
private WS avec snapshot resync, restart avec position protegee ouverte,
funding perpetuel deterministe/persistant, et conflit One-Way position/ordre
actif avec replay et restart, puis la recette dry-run `regular` / `scalper` /
`scalper_micro` sur le meme symbole Fake avec hashes/lineages distincts, rapports
JSON/Markdown deterministes et zero appel OKX, Hyperliquid ou Bitmart. La preuve
v2 mesure OKX/Hyperliquid aux guards HTTP et etablit `bitmart=0` par la frontiere
des providers Fake, sans pretendre mesurer Bitmart par HTTP. Le routage des
indicateurs injecte directement `FakeKlineProvider`, sans registre ou bundle
global sur cette route; aucun decorateur ni changement n'est ajoute a Bitmart.

Les vingt lignes du catalogue sont maintenant `executable`; cela clot le
catalogue golden v1, mais ne clot pas a lui seul l'issue #196 ni n'autorise une
mutation demo/testnet/mainnet.

Le scenario multi-profils prouve la coexistence Fake dry-run sans effet de bord.
Il marque le lock metier `not_exercised` et `observed=false`; son statut contractuel
`blocked/cross_profile_symbol_locked` est documente separement et reste exerce par
les tests de repository dedies.

Commande consolidee :

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php \
  tests/TradingCore/Execution/FakeExecutionScenarioFixtureParityTest.php
```

La suite appelle uniquement les moteurs Fake locaux, avec horloge controlee et etat
ephemere. Elle ne lit aucun secret, ne contacte aucun endpoint prive exchange et
n envoie aucun ordre reel, demo ou testnet.

## Rollback

Le rollback de COMMON-005 consiste a retirer le mode scenario et revenir au
`FakeExecutionPort` par defaut. Aucun schema, aucun secret, aucune config runtime
et aucun endpoint n'est modifie. Les tests de recette demo peuvent alors revenir
au simple resultat `dry_run` deterministe.

Le rollback de la compensation adapter doit aussi restaurer le scenario golden
12 en `partial` avec un `gap_code` explicite. Le fichier d etat Fake doit etre
archive puis retire ou valide contre la revision cible avant reprise locale ; il
ne doit jamais etre reutilise silencieusement par une version incompatible.

Le rollback du fallback taker doit retirer la politique et le trigger locaux,
puis restaurer le scenario golden 4 en `unsupported` avec
`fallback_taker_not_implemented`. Le fichier d etat doit etre archive ou
quarantaine avant de lancer une revision qui ne connait pas ses metadata
additives. Ce rollback ne doit jamais servir a activer une ecriture exchange.

Le rollback de TP1/trailing doit retirer `FakeTp1TrailingPolicy`, les transitions
locales d armement/ratchet/trigger et la fixture long/short, puis restaurer le
scenario golden 13 en `partial` avec `trailing_stop_not_implemented`. Tout fichier
d etat Fake contenant `fake-tp1-trailing-v1` doit etre archive ou place en
quarantaine avant une revision qui ne connait pas ces metadata additives. Il ne
doit jamais etre reutilise silencieusement ni servir a activer demo, testnet ou
mainnet.

Le rollback de l injection private WS out-of-order consiste a revertir le lot
atomique, remettre le scenario golden 16 en `partial` avec
`out_of_order_event_injection_not_implemented`, puis relancer les regressions
#274. Le champ d etat `privateWs` est additif et sera ignore par l ancien chemin
d hydratation. Aucun ordre exchange ni nettoyage demo/testnet/mainnet n est
necessaire.

Le rollback du funding doit retirer le modele, la fixture et la projection
cost-only, puis remettre le scenario golden 18 en `unsupported` avec
`funding_model_not_implemented`. Tout etat Fake contenant `funding.accrued` doit
etre archive ou mis en quarantaine avant une revision qui ne connait pas cet
evenement. Aucun nettoyage exchange ni activation demo/testnet/mainnet n est
necessaire.

## Suite

Le Paper complet reste suivi par #196. OKX demo et Hyperliquid testnet doivent
continuer a passer par leurs PR dediees avec activation explicite, sans mainnet
et sans fallback silencieux vers Bitmart. Le `FakeExecutionPort` servira de
reference de comparaison pour ces adapters.
