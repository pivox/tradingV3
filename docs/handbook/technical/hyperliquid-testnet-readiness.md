# Hyperliquid testnet readiness

## Statut

ADR documentaire HL-001, accepte le 2026-06-30. HL-002 ajoute le bundle provider
Hyperliquid skeleton. HL-003 active la lecture publique REST `/info`. HL-006
active la lecture account read-only via `/info`, sans signer ni broadcast.

Hyperliquid reste `target_dry_run_only`. Cette page ne rend pas Hyperliquid
utilisable en ecriture testnet et ne rend jamais Hyperliquid utilisable en
mainnet. Elle documente le perimetre testnet, les differences avec un CEX, les
capacites a livrer dans les PRs suivantes et les gates qui doivent rester
fermees jusqu'a une decision mutative dediee.

Voir aussi :

- [Hyperliquid dry-run](hyperliquid-dry-run.md)
- [Exchange readiness matrix](exchange-readiness-matrix.md)
- [Exchange runtime gates](exchange-runtime-gates.md)
- [Demo/Testnet Safety Envelope](demo-testnet-safety-envelope.md)
- [Exchange private observability policy](exchange-private-observability-policy.md)

## Decision

Le perimetre initial Hyperliquid est :

| Axe | Decision |
|---|---|
| Exchange | `hyperliquid` uniquement. |
| Environnement | `testnet` uniquement pour toute future ecriture controlee. |
| Market type | `perpetual` d'abord. |
| Spot | Hors perimetre initial tant qu'un support explicite n'est pas ajoute. |
| Public read-only | Requis avant toute readiness testnet. |
| Account read-only | Requis avant toute readiness testnet. |
| Signer | D'abord fake/local, puis signer testnet dedie sans secret mainnet. |
| Local dry-run | Autorise uniquement sans broadcast `/exchange`. |
| Testnet trading | Plus tard, par PR dediee, avec activation explicite et SL obligatoire. |
| Mainnet | Hors perimetre et bloque. |

`dry_run=false` ne signifie jamais mainnet dans cette serie. Il signifie
seulement qu'une future tentative mutative demande une ecriture testnet, sous
guards explicites. Par defaut, `DEMO_TRADING_ENABLED=0`,
`HYPERLIQUID_TESTNET_TRADING_ENABLED=0`, `mainnet_write_enabled=false`,
`demo_testnet_write_enabled=false` et `kill_switch_enabled=true` doivent
maintenir la voie mutative fermee.

## Definitions operationnelles

| Terme | Sens dans TradingV3 |
|---|---|
| Local dry-run | Simulation locale, sans HTTP mutatif, sans signature reelle, sans broadcast exchange. |
| Hyperliquid testnet | Reseau testnet Hyperliquid, avec endpoint testnet et fonds fictifs. |
| API wallet / agent | Cle de signature dediee au bot. Le wallet principal sert d'adresse de compte, pas de secret applicatif. |
| Account address | Adresse du compte observe via `info`; elle n'est pas forcement le signer. |
| Controlled testnet trading | Future ecriture testnet, jamais activee par cette ADR. |
| Mainnet | Reseau reel Hyperliquid. Interdit en ecriture dans cette serie. |

Les logs, fixtures et docs ne doivent contenir aucun secret. Les exemples ne
doivent utiliser que des noms de variables ou des identifiants factices non
reutilisables.

## Differences structurantes avec un CEX

| Sujet | Impact TradingV3 |
|---|---|
| Endpoint `info` | Les lectures publiques et compte passent par des requetes `POST /info` typées, pas par une famille REST CEX classique. |
| Endpoint `exchange` | Les actions mutatives passent par `POST /exchange`, signature requise et broadcast on-chain/HyperCore. Interdit avant PR mutative dediee. |
| Asset IDs | Les ordres utilisent des IDs d'asset derives de la metadata, pas seulement un symbole texte. Les IDs mainnet et testnet peuvent diverger. |
| API wallet | Les nonces sont suivis par signer. Un agent dedie par processus reduit les collisions et separe le secret du wallet principal. |
| Nonces | Le compteur doit etre persistant, monotone et restart-safe avant tout broadcast. |
| Account state | Les positions, marges, fills, funding et historique viennent du state utilisateur et doivent rester redacted. |
| SL/TP | Le modele de protection doit etre valide contre les actions Hyperliquid, pas copie depuis un CEX. |
| USDC | Le compte et les couts sont centres sur USDC ; aucune conversion implicite vers USDT ne doit certifier un PnL. |

## Capability matrix

| Capability | Etat HL-001 | Preuve actuelle | Manque pour testnet controle |
|---|---|---|---|
| Config Hyperliquid testnet | Documente | `config/trading/exchange/hyperliquid.yaml` garde Hyperliquid `dry_run_runtime_check_only`. | Normaliser les variables `HYPERLIQUID_TESTNET_*` et les exposer via runtime-check. |
| Guard mainnet | Present | Les docs et gates existantes bloquent Hyperliquid live/mainnet. | Conserver le blocage dans toutes les PRs HL. |
| Public read-only | HL-003 | `HyperliquidMetadataProvider` et `HyperliquidMarketGateway` lisent `/info` pour `metaAndAssetCtxs`, `allMids`, `l2Book`, `candleSnapshot` et `fundingHistory`. | Freshness runtime/WS public dedie si un flux temps reel est necessaire. |
| Account read-only | HL-006 | `HyperliquidAccountGateway` lit `clearinghouseState`, `userFills`/`userFillsByTime` et `userFunding` via `/info` avec redaction. `HyperliquidExecutionGateway` lit `frontendOpenOrders`. | Observabilite privee WS/equivalent et couts complets avant toute mutation. |
| Provider bundle | Account-read HL-006 | `ExchangeProviderBundle.hyperliquid_perpetual` route `hyperliquid/perpetual` vers les gateways Hyperliquid ; les surfaces publiques et account read-only lisent REST, les mutations restent fail-closed. | Implementer les PRs fees, observabilite, signer crypto et guards mutatifs dedies. |
| WebSocket public | Fallback REST HL-003 | HL-003 choisit le polling REST borne via `/info` au lieu d'un client WS public. | Client public, subscriptions, reconnect et freshness si une PR future remplace le polling. |
| WebSocket prive / equivalent | A livrer | State compte lisible via `info`, streams a valider. | Policy de snapshot initial + delta, ou justification equivalente avant mutation. |
| Signer fake/local | Livre HL-009 | `FakeHyperliquidSigner` produit une signature deterministe de fixture, sans secret et sans HTTP. `HyperliquidDryRunExecutionPort` l'utilise seulement pour signer une preview redacted non diffusee. | Conserver le fake pour les recettes local dry-run et les tests de payload. |
| Signer testnet | Encapsule HL-004 | `HyperliquidAgentSigner` valide `HYPERLIQUID_ENV=testnet`, `HYPERLIQUID_NETWORK=testnet` et les variables agent/account testnet, puis delegue a un backend de signature injecte. Aucun backend crypto n'est cable au client `/exchange` par defaut. | Ajouter le backend crypto officiel/valide par vecteurs dans une PR ulterieure avant tout broadcast. |
| Nonce manager | Livre HL-005 | `PersistentHyperliquidNonceManager` persiste `hyperliquid_nonce_state` par `environment + network + signer_address`, garde `account_address` en audit, rejette la reutilisation d'un signer sur un autre compte, et detecte les replays. | L'utiliser seulement dans une PR mutative future, apres signer crypto officiel et garde `/exchange`. |
| Metadata / precision | Renforce HL-007 | Mapping `symbol -> coin -> asset_id`, `szDecimals`, tick prix, step quantite, max leverage, status marche et flags de qualite sont exposes dans `HyperliquidInstrumentMetadataDto`. Les collisions d'asset et metadata requises manquantes ne sont pas resolues arbitrairement. | Min-notional si Hyperliquid l'expose dans une surface officielle future. |
| Fees / funding / costs | Renforce HL-007 | Funding public absent reste `null` avec `funding_rate_unknown`. `getTradingFees()` lit `userFees` read-only et expose maker/taker `null` + flags si absents. User fills et funding history sont lisibles via account read-only, redacted et non certifies PnL. | Ledger/certification dans les PRs dediees. |
| Lifecycle normalizers | Livre HL-008 | `HyperliquidLifecycleNormalizer` normalise les requetes d'ordre sans broadcast, statuts ordre, fills, positions, funding et erreurs en DTOs stables. Les fills sans ordre passent en `unknown_requires_resync` avec quality flag. | Branchement mutatif testnet, reconciliation privee continue et consommation ledger/position-state complete. |
| Local dry-run no broadcast | Livre HL-009 | `HyperliquidDryRunExecutionPort` construit les actions `/exchange` locales (`updateLeverage`, entry, SL reduce-only, TP reduce-only), applique safety/observability en mode informatif, signe avec le fake signer, expose `local_dry_run_ready` et garde `no_http/no_exchange_call/no_broadcast`. | Recette orchestrateur et preuves sur environnement representatif avant toute PR mutative. |
| Runtime-check candidate | Livre HL-010 | `HyperliquidRuntimeCheck` expose les paliers `public_read_only`, `private_read_only`, `local_dry_run_ready` et `demo_testnet_candidate`. La commande runtime affiche signer, relation compte/agent configuree, nonce store, collateral, WS/polling, guards, kill switch et stop-loss capability. La permission trade exchange reste affichee comme non prouvee en HL-010. | Recette orchestrateur HL et preuves redacted avant toute PR mutative. |
| SL/TP / protection | A valider | Aucun attachement runtime Hyperliquid n'est prouve par HL-001. | Modele ordre/protection testnet avec SL immediat ou compensation fail-safe. |
| Controlled testnet write | Non supporte | Les guards communs existent, mais Hyperliquid reste dry-run only. | PR dediee apres readiness complete, SL obligatoire, reconciliation et rollback. |
| Mainnet write | Interdit | `mainnet_write_enabled=false` reste requis. | Aucun manque a combler dans cette serie. |

## Surfaces API attendues

Les surfaces ci-dessous servent a cadrer les PRs suivantes. Elles ne sont pas
activees par HL-001.

HL-002 ajoute seulement le chemin de registry :

- `ExchangeProviderBundle.hyperliquid_perpetual` ;
- `HyperliquidMarketGateway` ;
- `HyperliquidAccountGateway` ;
- `HyperliquidExecutionGateway` ;
- `HyperliquidMetadataProvider` ;
- `HyperliquidRuntimeCheck` ;
- `HyperliquidSignerInterface` ;
- `HyperliquidNonceManagerInterface`.

HL-003 branche uniquement les surfaces publiques sur `POST /info`. Les surfaces
account et execution restent fail-closed. Les methodes de persistance locales
des klines restent non implementees et ne modifient pas la base. Aucun contexte
`hyperliquid/spot` n'est enregistre ; une resolution spot doit donc echouer au
lieu de fallback Bitmart.

### Public read-only

| Donnee | Surface Hyperliquid attendue | Utilisation |
|---|---|---|
| Metadata perps | `POST /info` avec type metadata perps | Mapping asset, symboles, size decimals et univers perpetual. |
| All mids / prix courants | `POST /info` type market data | Prix de reference, freshness et sanity checks. |
| Order book | `POST /info` type L2 book | Best bid/ask, spread et controles de prix d'entree. |
| Candles | `POST /info` type candle snapshot | OHLCV pour indicateurs et validation de timeframes. |
| Funding | `POST /info` funding history | Couts de funding et analyse PnL non certifiee tant que le ledger n'est pas complet. |
| WebSocket public | Non branche en HL-003 | Fallback REST polling explicite via `/info`; un client WS public peut remplacer ce fallback dans une PR dediee. |

HL-003 normalise :

- les symboles internes `BTCUSDT` vers coin Hyperliquid `BTC` et asset id issu
  de `meta`/`metaAndAssetCtxs` ;
- les books L2 en listes `price`/`quantity` bornees ;
- les candles en `KlineDto` triees UTC ASC, dedupliquees par open time et
  source `HYPERLIQUID_REST_PUBLIC` ;
- les metadata publiques dans `HyperliquidInstrumentMetadataDto` avec
  `qualityFlags` quand le funding public est absent ;
- les erreurs publiques 429 en `hyperliquid_public_rate_limited`.

HL-007 renforce ce mapping :

- `BTCUSDT` interne est normalise en coin Hyperliquid `BTC`; l'`asset_id`
  reste l'index de `meta.universe`/`metaAndAssetCtxs` et n'est jamais hardcode ;
- `price_tick` est derive de la regle Hyperliquid `max_decimals = 6 -
  szDecimals`, en respectant aussi la limite de 5 chiffres significatifs pour
  les prix non entiers ;
- `quantity_step` et `min_size` viennent de `szDecimals`; si `szDecimals` est
  absent ou invalide, le DTO porte `missing_size_decimals` ou
  `invalid_size_decimals` et `isCompleteForSizing()` vaut false ;
- `max_leverage` vient de `meta.universe[].maxLeverage`; absence ou valeur
  invalide donne `missing_max_leverage` ou `invalid_max_leverage`, sans fallback
  optimiste ;
- un marche `isDelisted=true` est expose avec `status=suspend`,
  `market_suspended`, et n'est pas considere complet pour sizing ;
- deux assets resolus vers le meme coin provoquent `hyperliquid_asset_collision`
  afin d'eviter une resolution arbitraire.

Configuration testnet public cible :

```dotenv
HYPERLIQUID_ENV=testnet
HYPERLIQUID_NETWORK=testnet
HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz
HYPERLIQUID_WS_URI=wss://api.hyperliquid-testnet.xyz/ws
HYPERLIQUID_MAINNET_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
```

### Account read-only

HL-006 branche les lectures account read-only sur `POST /info`. Ces lectures
n'utilisent pas l'adresse agent pour le state compte, ne signent rien et ne
touchent jamais a `/exchange`.

| Donnee | Surface Hyperliquid attendue | Utilisation |
|---|---|---|
| Account state | `POST /info` user state | Equity, margin summary, positions ouvertes. |
| Open orders | `POST /info` open orders | Reconciliation ordres actifs et `client_order_id` si expose. |
| User fills | `POST /info` user fills / fills by time | Prix, quantite, fees, side, oid, timestamps et pagination. |
| Historical orders | `POST /info` historical orders | Statuts terminaux et reconciliation apres restart. |
| User funding | `POST /info` user funding | Funding utilisateur, couts et quality flags. |
| User fees | `POST /info` user fees | Fee schedule compte, maker=`userAddRate`, taker=`userCrossRate`, devise USDC, flags `*_fee_unknown` si absent. |
| WebSocket account | WS testnet si retenu | Deltas ordres/fills/positions, ou justification d'un polling read-only borne. |

Configuration account read-only cible :

```dotenv
HYPERLIQUID_ENV=testnet
HYPERLIQUID_NETWORK=testnet
HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS=0x0000000000000000000000000000000000000000
HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz
HYPERLIQUID_WS_URI=wss://api.hyperliquid-testnet.xyz/ws
HYPERLIQUID_MAINNET_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
```

L'adresse de compte n'est pas un secret. Les private keys, API wallet keys,
signatures et payloads signes restent interdits dans Git, logs, fixtures,
screenshots et docs.

Regles HL-006 :

- `HYPERLIQUID_ENV` et `HYPERLIQUID_NETWORK` doivent rester `testnet` pour les
  lectures account de cette serie.
- `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` est l'adresse wallet/subaccount a lire.
  Elle ne doit pas etre remplacee par `HYPERLIQUID_TESTNET_AGENT_ADDRESS`.
- Si l'adresse account est absente alors qu'une adresse agent est configuree,
  le provider echoue avec `hyperliquid_account_address_missing_for_signer`.
- Si account et agent sont identiques, le provider echoue avec
  `hyperliquid_account_address_matches_agent`.
- `getAccountBalance('USDC')` expose le withdrawable Hyperliquid. Les demandes
  `USDT` retournent `0.0` pour eviter une conversion implicite USDC->USDT.
- Les fills et fundings sont bornes a 200 elements par appel et filtres par coin
  lorsque le symbole est fourni. Une fenetre temporelle utilise
  `userFillsByTime`; sinon `userFills`. `userFunding` recoit toujours un
  `startTime` en millisecondes ; par defaut HL-006 utilise une fenetre de 30
  jours. `getTransactionHistory()` retourne ce funding uniquement pour
  `flowType=null` ou `flowType=3`; les flux non-funding restent hors HL-006.
- Les champs sensibles usuels (`secret`, `apiKey`, `privateKey`, `signature`,
  `passphrase`) sont retires des metadata/raw_reference.

Regles HL-008 :

- `HyperliquidLifecycleNormalizer::normalizeOrderRequest()` retourne le payload
  action `order` construit localement a partir d'un `PlaceOrderRequest` et d'un
  `asset_id`. Il ne signe pas, ne reserve pas de nonce et ne poste jamais vers
  `/exchange`.
- Les statuts internes stables sont `accepted`, `open`, `partially_filled`,
  `filled`, `canceled`, `rejected`, `failed` et `unknown_requires_resync`.
- Les evenements sont dedupliques puis tries par timestamp et rang de statut
  pour que les replays out-of-order donnent un resultat deterministe.
- Un fill present sans ligne d'ordre reste exploitable comme fill, mais le
  lifecycle global devient `unknown_requires_resync` avec
  `order_absent_fill_present`; aucun fallback par symbole ou fenetre temporelle
  n'est autorise.
- Les erreurs connues sont normalisees au minimum en
  `insufficient_collateral` et `market_unavailable`; les payloads retournes sont
  redacted.
- Les positions zero-size restent visibles avec `position_closed_zero_size` afin
  que les consommateurs position-state puissent enregistrer la fermeture.
- Les rows funding sont exposees comme role `funding`, devise `USDC`, montant
  signe preserve. Elles ne certifient pas encore un PnL net.

Exemple operateur :

```bash
docker-compose exec trading-app-php php bin/console app:exchange:runtime-check hyperliquid perpetual
```

Un compte testnet correctement configure peut atteindre `private_read_only`,
mais `Recommended dry_run: true` et `Schedule ready: no` restent attendus.

### Signer et mutation future

HL-004 ajoute la frontiere de signature sans activer de broadcast :

- `HyperliquidSignerInterface` reste la dependance applicative ;
- `FakeHyperliquidSigner` sert aux tests et recettes sans secret ;
- `HyperliquidAgentSigner` accepte uniquement `testnet` et refuse `mainnet` ou
  tout autre domain/network ;
- les seules variables de secret autorisees sont
  `HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY`,
  `HYPERLIQUID_TESTNET_AGENT_ADDRESS` et
  `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` ;
- `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` est une adresse publique de compte ; la
  private key du wallet principal ne doit jamais entrer dans l'application ;
- le client REST `/exchange` par defaut continue de refuser, meme si les
  variables de signer sont presentes.

### Nonce manager et replay

HL-005 ajoute un compteur nonce persistant sans activer de broadcast :

- `HyperliquidNonceManagerInterface` expose `nextNonce()` et
  `recordObservedNonce()` ;
- `HyperliquidNonceScope` transporte `environment`, `network`,
  `account_address` et `signer_address` ;
- `PersistentHyperliquidNonceManager` reserve `max(now_ms, last_nonce + 1)` ;
- `hyperliquid_nonce_state` porte la cle unique
  `environment + network + signer_address` ;
- `account_address` reste stocke pour audit et pour detecter une tentative de
  reutiliser le meme signer sur un autre compte ;
- la reservation utilise un upsert atomique `ON CONFLICT ... RETURNING`, ce qui
  couvre le premier insert concurrent et les reservations suivantes ;
- un signer deja associe a un autre compte leve
  `hyperliquid_nonce_scope_conflict` ;
- un nonce observe inferieur ou egal au dernier nonce connu leve
  `hyperliquid_nonce_replay_detected` ;
- le compteur survit aux restarts et peut etre resynchronise par un nonce
  observe plus haut ;
- ce compteur n'est pas une cle d'idempotence metier et ne remplace pas les
  `client_order_id`/Cloid applicatifs.

Le signer est l'axe de serialization anti-replay. Le compte reste visible dans
la ligne persistante, mais il ne cree pas un compteur independant pour un meme
signer. Aucun fallback par symbole, horodatage seul ou ordre metier n'est
autorise pour determiner un nonce.

Les actions `POST /exchange` restent hors perimetre. Une future PR mutative
testnet devra prouver :

- API wallet testnet dedie au bot, jamais private key du wallet principal ;
- signer crypto officiel valide par vecteurs non secrets ;
- utilisation explicite du nonce manager persistant par signer ;
- payloads redacted et hashables pour audit ;
- whitelist symbole/marche, notional minimal, leverage cap et SL obligatoire ;
- fail-safe si le SL ne peut pas etre attache ou relu ;
- rollback operationnel qui remet `HYPERLIQUID_TESTNET_TRADING_ENABLED=0`,
  `DEMO_TRADING_ENABLED=0` et `kill_switch_enabled=true`.

## Donnees attendues

Une readiness testnet Hyperliquid ne doit pas etre consideree complete sans ces
donnees :

- environnement `testnet` confirme et endpoints testnet utilises ;
- `market_type=perpetual` confirme ;
- mapping symbole interne vers coin Hyperliquid et asset id testnet ;
- tick size, lot size, size decimals, contraintes de taille et precision prix
  Hyperliquid ;
- validation que les prix respectent les limites Hyperliquid perps : 5 chiffres
  significatifs maximum et nombre de decimales prix compatible avec
  `6 - szDecimals` ;
- best bid/ask, prix courant et timestamps de freshness ;
- candles normalisees, triees et bornees ;
- funding courant ou historique exploitable ;
- account state, equity, margin summary et devise ;
- positions avec taille, side, prix entree, liquidation si expose, PnL provider ;
- open orders, historical orders, `client_order_id`/`cloid` si expose, `oid` ;
- fills avec id exchange, prix, quantite, fee, devise fee et timestamp ;
- statut observabilite privee avec snapshot initial et deltas ou polling borne ;
- quality flags sur toute donnee absente, ambigue ou non comparable.

Les donnees manquantes restent manquantes. Elles ne doivent jamais etre
converties en zero pour obtenir une certification PnL ou une readiness mutative.

## Gates avant testnet controle

Hyperliquid peut progresser vers `local_dry_run_ready` seulement si :

1. `exchange=hyperliquid`, `environment=testnet`, `market_type=perpetual`.
2. Les endpoints REST et WS utilises pointent strictement vers le host `api.hyperliquid-testnet.xyz`.
3. Les metadata et precisions sont coherentes avec le plan d'ordre, y compris
   la precision prix Hyperliquid qui evite les rejets `tickRejected`.
4. La lecture publique est fraiche et bornee.
5. La lecture account est redacted et couvre positions, ordres, fills et funding.
6. Le signer agent testnet est configure et rattache a un compte testnet distinct.
7. Le nonce store persistant est disponible pour reserver des nonces monotones si une PR future active le broadcast.
8. Le collateral est lisible via account read-only.
9. Le runtime declare un fallback polling REST borne tant que le WS prive n'est pas complet.
10. `stopLossCapability=true` est prouve avant tout statut candidat.
11. Le dry-run local produit une trace deterministe sans broadcast `/exchange`.

Hyperliquid peut progresser de `local_dry_run_ready` vers `demo_testnet_candidate` seulement si :

1. `HYPERLIQUID_TESTNET_TRADING_ENABLED=1`.
2. `demo_testnet_write_guard=true` reste limite a `environment=testnet`, `network=testnet`, endpoints testnet et `HYPERLIQUID_MAINNET_ENABLED=0`.
3. Le kill switch reste visible dans le rapport ; en HL-010 il ne debloque pas de mutation car le runtime force encore `dryRun=true`.
4. L'observabilite privee est complete ou explicitement acceptee en dry-run par la policy documentee.
5. La permission trade de l'agent wallet reste `not_proven` en HL-010 ; elle devra etre prouvee avant `demo_testnet_enabled`.
6. Aucun statut `mainnet_ready`, `live_ready` ou `demo_testnet_enabled` n'est expose par cette PR.

Hyperliquid ne peut atteindre `demo_testnet_enabled` que si, en plus :

1. `DEMO_TRADING_ENABLED=1` et `HYPERLIQUID_TESTNET_TRADING_ENABLED=1`.
2. `demo_testnet_write_enabled=true`, `mainnet_write_enabled=false` et
   `kill_switch_enabled=false`.
3. La requete concrete contient `client_order_id`, symbole/marche whitelist,
   notional positif sous plafond et stop-loss present.
4. La private observability policy autorise le statut Hyperliquid testnet.
5. Le signer utilise uniquement une API wallet testnet dediee.
6. `permissions_trade=true` est prouve pour l'API wallet/le compte testnet
   avant tout statut enabled.
7. Le nonce manager persistant est operationnel et audite.
8. Une compensation fail-safe est documentee si le SL ne peut pas etre attache.
9. L'audit demo/testnet trading est ecrit avant de considerer la mutation
   autorisee.

## Non supporte

Les elements suivants restent explicitement non supportes :

- ordre mainnet Hyperliquid ;
- utilisation d'une private key de wallet principal dans l'application ;
- activation Hyperliquid par presence de credentials ;
- spot, vault, subaccount ou builder-deployed perps sans PR explicite ;
- retrait, transfert, bridge ou mutation account ;
- fallback silencieux vers Bitmart quand `exchange=hyperliquid` ;
- ordre testnet sans SL immediat ou compensation fail-safe ;
- certification PnL nette Hyperliquid tant que les fills/couts ne sont pas
  complets ;
- logs de payloads bruts sensibles, signatures, private keys, tokens ou secrets ;
- changement de strategie, EntryZone, Risk/Leverage ou SL/TP metier dans cette
  serie de readiness.

## Risques et mitigations

| Risque | Mitigation |
|---|---|
| Confusion testnet/mainnet | Endpoints testnet obligatoires, `mainnet_write_enabled=false`, `HYPERLIQUID_MAINNET_ENABLED=0` dans les templates de recette. |
| Secret de wallet principal expose | API wallet dediee obligatoire pour toute future mutation, redaction defensive, aucun secret dans Git. |
| Collision de nonce | Nonce manager persistant par signer avant tout broadcast. |
| Asset id divergent | Mapping issu de metadata testnet, jamais hardcode depuis mainnet ; collision d'asset rejetee avec `hyperliquid_asset_collision`. |
| Frais/funding absents | Flags de qualite et PnL non certifie, jamais zero implicite. |
| Private stream incomplet | `demo_testnet_enabled` bloque par private observability policy. |
| Partial fill ou SL attach failure | Scenario Fake/Paper d'abord, compensation fail-safe avant toute ecriture testnet. |
| Rate limits ou donnees stale | Pagination, bornes de timeout/retry read-only, freshness explicite, pas de mutation si donnees obsoletes. |

## Rollback

HL-001 est docs-only. Le rollback applicatif HL-001 est le revert de cette page
et de son entree de navigation.

HL-002 ajoute du code/configuration applicative. Son rollback doit aussi retirer :

- les services `App\Provider\Hyperliquid\*` et les interfaces signer/nonce ;
- l'entree `ExchangeContext.hyperliquid_perpetual` dans `services.yaml` ;
- le bundle `ExchangeProviderBundle.hyperliquid_perpetual` et son injection dans
  `ExchangeProviderRegistry` ;
- le branchement `HyperliquidRuntimeCheck` dans `ExchangeRuntimeCheckCommand` ;
- les tests HL-002 associes.

HL-003 ajoute la lecture publique. Son rollback doit aussi retirer :

- `HyperliquidPublicReadMapper` ;
- `HyperliquidProviderUnavailableException` ;
- `Provider\Hyperliquid\Dto\HyperliquidInstrumentMetadataDto` ;
- les injections client/resolver dans `HyperliquidMarketGateway` et
  `HyperliquidMetadataProvider` ;
- le passage `public_read_only` de `HyperliquidRuntimeCheck` ;
- les tests `HyperliquidPublicReadProviderTest`.

HL-005 ajoute le compteur nonce persistant. Son rollback doit aussi retirer :

- la table `hyperliquid_nonce_state` via la migration inverse ;
- `HyperliquidNonceScope`, `HyperliquidNonceReplayException`,
  `HyperliquidNonceScopeConflictException`, `PersistentHyperliquidNonceManager`
  et `HyperliquidNonceStateRepository` ;
- l'entite `HyperliquidNonceState` ;
- l'alias DI `HyperliquidNonceManagerInterface` vers
  `PersistentHyperliquidNonceManager` ;
- les tests `HyperliquidNonceManagerTest`.

Le rollback operationnel a conserver pour les PRs suivantes reste :

```bash
HYPERLIQUID_ENV=testnet
HYPERLIQUID_MAINNET_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
DEMO_TRADING_ENABLED=0
# Config effective / safety envelope :
demo_testnet_write_enabled=false
kill_switch_enabled=true
```

Voir aussi le runbook [Demo/Testnet kill switch](../runbooks/demo-testnet-kill-switch.md)
pour refermer les gates effectives et verifier que les processus ont recharge
la configuration.

## References officielles

- Hyperliquid API : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api>
- Hyperliquid Info endpoint : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint>
- Hyperliquid Exchange endpoint : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/exchange-endpoint>
- Hyperliquid Nonces and API wallets : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/nonces-and-api-wallets>
- Hyperliquid Asset IDs : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/asset-ids>
- Hyperliquid Tick and lot size : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/tick-and-lot-size>
- Hyperliquid Rate limits : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/rate-limits-and-user-limits>
- Hyperliquid WebSocket : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket>
