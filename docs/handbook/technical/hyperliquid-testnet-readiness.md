# Hyperliquid testnet readiness

## Statut

ADR documentaire HL-001, accepte le 2026-06-30. HL-002 ajoute le bundle provider
Hyperliquid skeleton. HL-003 active la lecture publique REST `/info`. HL-006
active la lecture account read-only via `/info`. HL-012 implemente le chemin
d'une tentative testnet controlee, avec signer sidecar, SL plein volume,
reconciliation et compensation, mais le laisse desactive par defaut.

Au 12 juillet 2026, DEMO-005 reste `blocked`. Aucun ordre reel, meme sur
testnet, n'a ete envoye par HL-012 et le smoke mutatif est interdit jusqu'a une
decision explicite `ready_for_demo_testnet_trading_attempt`. Hyperliquid reste
donc operationnellement dry-run only. Cette page ne rend jamais Hyperliquid
utilisable en mainnet.

Voir aussi :

- [Hyperliquid dry-run](hyperliquid-dry-run.md)
- [Exchange readiness matrix](exchange-readiness-matrix.md)
- [Exchange runtime gates](exchange-runtime-gates.md)
- [Demo/Testnet Safety Envelope](demo-testnet-safety-envelope.md)
- [Exchange private observability policy](exchange-private-observability-policy.md)
- [Hyperliquid testnet controlled trading](../runbooks/hyperliquid-testnet-controlled-trading.md)

## Decision

Le perimetre initial Hyperliquid est :

| Axe | Decision |
|---|---|
| Exchange | `hyperliquid` uniquement. |
| Environnement | `testnet` uniquement pour toute ecriture controlee HL-012. |
| Market type | `perpetual` d'abord. |
| Spot | Hors perimetre initial tant qu'un support explicite n'est pas ajoute. |
| Public read-only | Requis avant toute readiness testnet. |
| Account read-only | Requis avant toute readiness testnet. |
| Signer | Fake/local pour dry-run ; sidecar testnet dedie, interne et desactive par defaut pour HL-012. |
| Local dry-run | Autorise uniquement sans broadcast `/exchange`. |
| Testnet trading | HL-012 implemente une tentative operateur unique, desactivee par defaut et interdite tant que DEMO-005 reste `blocked`. |
| Mainnet | Hors perimetre et bloque. |

`dry_run=false` ne signifie jamais mainnet dans cette serie. Il signifie
seulement qu'une tentative mutative demande une ecriture testnet, sous guards
explicites et apres decision DEMO-005. Par defaut, `DEMO_TRADING_ENABLED=0`,
`HYPERLIQUID_TESTNET_TRADING_ENABLED=0`, `mainnet_write_enabled=false`,
`demo_testnet_write_enabled=false` et `kill_switch_enabled=true` doivent
maintenir la voie mutative fermee.

## Definitions operationnelles

| Terme | Sens dans TradingV3 |
|---|---|
| Local dry-run | Simulation locale, sans HTTP mutatif, sans signature reelle, sans broadcast exchange. |
| Hyperliquid testnet | Reseau testnet Hyperliquid, avec endpoint testnet et fonds fictifs. |
| API wallet / agent | Cle de signature dediee au bot. Le wallet principal sert d'adresse de compte, pas de secret applicatif. |
| Account address | Adresse du master account observe via `info`, dont `userRole` vaut exactement `user`; elle n'est pas le signer. |
| Controlled testnet trading | Chemin HL-012 implemente mais fail-closed ; son smoke reste interdit avec DEMO-005=`blocked`. |
| Mainnet | Reseau reel Hyperliquid. Interdit en ecriture dans cette serie. |

Les logs, fixtures et docs ne doivent contenir aucun secret. Les exemples ne
doivent utiliser que des noms de variables ou des identifiants factices non
reutilisables.

Avec HL-012, `Controlled testnet trading` designe le chemin implemente mais non
autorise. L'account address identifie exclusivement le master account de role
officiel `user` lu et trade ; subaccounts et vaults sont strictement non
supportes. L'agent address identifie le wallet API de signature. Elles sont
distinctes. La private key de l'agent est presente uniquement dans le sidecar
interne
`hyperliquid-signer` : aucun port hote n'est publie et PHP ne recoit jamais la
private key. Le sidecar signe toujours avec `vaultAddress=null` et rejette toute
autre valeur.

## Differences structurantes avec un CEX

| Sujet | Impact TradingV3 |
|---|---|
| Endpoint `info` | Les lectures publiques et compte passent par des requetes `POST /info` typées, pas par une famille REST CEX classique. |
| Endpoint `exchange` | Les actions mutatives passent par `POST /exchange`, signature requise et broadcast on-chain/HyperCore. HL-012 ne l'autorise qu'apres toutes les gates testnet et une confirmation operateur exacte. |
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
| WebSocket prive / equivalent | Polling borne HL-012 | Snapshot initial puis polling ordres/fills/positions avec freshness et reconciliation-in-flight explicites. | Conserver la policy fail-closed et n'adopter le WS que par changement dedie. |
| Signer fake/local | Livre HL-009 | `FakeHyperliquidSigner` produit une signature deterministe de fixture, sans secret et sans HTTP. `HyperliquidDryRunExecutionPort` l'utilise seulement pour signer une preview redacted non diffusee. | Conserver le fake pour les recettes local dry-run et les tests de payload. |
| Signer testnet | Implemente HL-012, desactive | Le sidecar `hyperliquid-signer` valide testnet/master account/agent, impose `vaultAddress=null`, signe et ne broadcast que si son flag dedie est ouvert. Il est interne au reseau Docker, sans port hote, et recoit seul la private key agent. | DEMO-005 `ready` et preuve testnet redacted ; le flag broadcast reste `0` jusque-la. |
| Nonce manager | Utilise par HL-012 | `PersistentHyperliquidNonceManager` persiste `hyperliquid_nonce_state` par `environment + network + signer_address`, garde `account_address` en audit, rejette la reutilisation d'un signer sur un autre compte et detecte les replays. La reservation intervient seulement apres readiness et revalidation flat. | Preuve d'execution encore bloquee par DEMO-005. |
| Metadata / precision | Renforce HL-007 | Mapping `symbol -> coin -> asset_id`, `szDecimals`, tick prix, step quantite, max leverage, status marche et flags de qualite sont exposes dans `HyperliquidInstrumentMetadataDto`. Les collisions d'asset et metadata requises manquantes ne sont pas resolues arbitrairement. | Min-notional si Hyperliquid l'expose dans une surface officielle future. |
| Fees / funding / costs | Renforce HL-007 | Funding public absent reste `null` avec `funding_rate_unknown`. `getTradingFees()` lit `userFees` read-only et expose maker/taker `null` + flags si absents. User fills et funding history sont lisibles via account read-only, redacted et non certifies PnL. | Ledger/certification dans les PRs dediees. |
| Lifecycle normalizers | Utilises par HL-012 | `HyperliquidLifecycleNormalizer` normalise ordres, fills, positions, funding et erreurs. HL-012 reconcilie cancel/close exclusivement par cloid/oid ; les fills sans ordre restent `unknown_requires_resync`. | Execution validation bloquee par DEMO-005 ; ledger/PnL complet reste hors perimetre. |
| Local dry-run no broadcast | Livre HL-009 | `HyperliquidDryRunExecutionPort` construit les actions `/exchange` locales (`updateLeverage`, entry, SL reduce-only, TP reduce-only), applique safety/observability en mode informatif, signe avec le fake signer, expose `local_dry_run_ready` et garde `no_http/no_exchange_call/no_broadcast`. | Continuer la recette orchestrateur dry-run ; elle ne constitue jamais une preuve d'ordre reel. |
| Runtime-check candidate | Renforce HL-012 | La commande runtime sonde account, permission agent, sidecar, nonce store, collateral, polling, guards, kill switch et stop-loss capability. `demo_testnet_candidate` n'est possible qu'avec toutes les gates techniques ouvertes. | La sortie technique ne remplace jamais DEMO-005, actuellement `blocked`. |
| SL/TP / protection | Implemente, non execute | HL-012 exige un SL reduce-only plein volume, confirme par identifiants ; echec ou ambiguite declenche cancel/close compensatoire et quarantaine. Aucun ordre reel n'a valide ce chemin. | Decision DEMO-005 `ready_for_demo_testnet_trading_attempt`, puis preuve testnet redacted. |
| Controlled testnet write | Implemente, desactive | La commande `app:hyperliquid:testnet:smoke` exige schema v1 strict, confirmation exacte, valeur litterale de decision, readiness complete, compte flat/ordres ouverts zero et ownership exclusif. Elle ne lit ni GitHub ni une decision versionnee : DEMO-005 reste un gate humain/documentaire prealable. Flags, config effective, signer broadcast et kill switch restent fail-closed. | DEMO-005 reste `blocked`; live smoke interdit jusqu'a ouverture explicite du gate. |
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
| User role | `POST /info` type `userRole` | Exiger le role officiel `user` du master account ; tout autre role bloque HL-012. |
| Extra agents | `POST /info` type `extraAgents`, expose par le SDK officiel epingle mais absent de l'Info GitBook | Confirmer la relation agent/account avec les autres gates, jamais comme preuve authoritative unique. |
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
- `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` est exclusivement l'adresse du master
  wallet a lire. La surface officielle
  [`userRole`](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint#query-a-users-role)
  doit retourner exactement `{"role":"user"}`. Les roles `subAccount`, `vault`,
  `agent` et `missing` sont fail-closed pour HL-012.
- `extraAgents`, expose par
  [`Info.extra_agents()` dans le SDK Python officiel 0.24.0 epingle](https://github.com/hyperliquid-dex/hyperliquid-python-sdk/blob/0.24.0/hyperliquid/info.py)
  mais non documente dans l'Info GitBook, confirme la relation avec l'agent en
  complement de `userRole` et des autres gates. Une reponse absente, ambigue ou
  mal formee bloque ; cette surface n'est pas une preuve authoritative unique.
- L'account address ne doit pas etre remplacee par
  `HYPERLIQUID_TESTNET_AGENT_ADDRESS`.
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
docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check hyperliquid perpetual
```

Avec les flags et configs fail-closed actuels, `Recommended dry_run: true` et
`Schedule ready: no` restent attendus. Meme une readiness technique positive ne
remplace pas la decision DEMO-005.

### Signer isole et mutation HL-012

HL-004 ajoute la frontiere de signature sans activer de broadcast :

- `HyperliquidSignerInterface` reste la dependance applicative ;
- `FakeHyperliquidSigner` sert aux tests et recettes sans secret ;
- `HyperliquidAgentSigner` accepte uniquement `testnet` et refuse `mainnet` ou
  tout autre domain/network ;
- la private key agent est le seul secret de wallet attendu ; les adresses
  account et agent sont des identifiants publics mais restent redacted dans les
  preuves partagees ;
- `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` est une adresse publique de compte ; la
  private key du wallet principal ne doit jamais entrer dans l'application ;
- la presence de credentials seule n'active jamais le client `/exchange` ;
- le sidecar impose `vaultAddress=null`; subaccounts et vaults sont rejetes.

HL-012 cable le signer crypto dans un sidecar Compose dedie au profile
`hyperliquid-testnet`. Le sidecar n'a aucun port hote et recoit seul la private
key agent ; PHP recoit uniquement l'endpoint interne, l'auth token et les
adresses. Le broadcast reste desactive par defaut et exige simultanement les
flags globaux/HL, la config effective mutative, le kill switch ouvert, la
readiness complete et la confirmation de la commande operateur.
La valeur CLI de decision ne consulte ni GitHub ni un artefact versionne et ne
remplace pas le gate humain/documentaire DEMO-005.

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

HL-012 ajoute donc un journal distinct
`hyperliquid_testnet_execution_attempt`, cle par `idempotency_key`. Une
reclamation atomique lie cette cle a l'empreinte SHA-256 du plan et au
`client_order_id` avant la premiere action mutative. Les etats durables sont
`reserved`, `submitted`, `compensating` puis un etat `terminal_*`. Un rejeu
terminal strictement identique restitue le resultat redacted sans nonce ni
broadcast, avant les gates qui peuvent legitimement avoir change apres le
premier succes. Une contrainte unique sur le slot actif du scope testnet
serialise aussi les cles differentes entre processus et hotes partageant
PostgreSQL. Une tentative non terminale apres crash, une empreinte differente,
un autre `client_order_id` ou une seconde cle concurrente declenche la
quarantaine ; aucune heuristique par symbole ou timestamp n'est autorisee. Un
echec d'ecriture du journal apres broadcast trip le kill switch et conserve le
lease d'execution.

Le signer est l'axe de serialization anti-replay. Le compte reste visible dans
la ligne persistante, mais il ne cree pas un compteur independant pour un meme
signer. Aucun fallback par symbole, horodatage seul ou ordre metier n'est
autorise pour determiner un nonce.

HL-012 limite les actions `POST /exchange` a une tentative testnet controlee.
Avant toute preuve d'execution, il faut encore prouver :

- API wallet testnet dedie au bot, jamais private key du wallet principal ;
- signer crypto valide par vecteurs non secrets et sidecar interne sain ;
- utilisation explicite du nonce manager persistant par signer ;
- payloads redacted et hashables pour audit ;
- whitelist symbole/marche, notional minimal, leverage cap et SL obligatoire ;
- cancel/close fail-safe si le SL ne peut pas etre attache ou relu, avec
  reconciliation par cloid/oid uniquement ;
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
6. Le signer agent testnet est configure et rattache a un master account testnet
   distinct, dont la reponse officielle `userRole` vaut exactement `user` ; le
   sidecar impose `vaultAddress=null`.
7. Le nonce store persistant est disponible ; HL-012 ne reserve un nonce
   qu'apres readiness et revalidation flat du symbole.
8. Le collateral est lisible via account read-only.
9. Le runtime declare un fallback polling REST borne tant que le WS prive n'est pas complet.
10. `stopLossCapability=true` est prouve avant tout statut candidat.
11. Le dry-run local produit une trace deterministe sans broadcast `/exchange`.

Hyperliquid peut progresser de `local_dry_run_ready` vers `demo_testnet_candidate` seulement si :

1. `HYPERLIQUID_TESTNET_TRADING_ENABLED=1`.
2. `demo_testnet_write_guard=true` reste limite a `environment=testnet`, `network=testnet`, endpoints testnet et `HYPERLIQUID_MAINNET_ENABLED=0`.
3. Le kill switch durable DB/filesystem est lisible et non tripped ; la config
   effective doit aussi porter `kill_switch_enabled=false` uniquement pendant
   la fenetre approuvee.
4. L'observabilite privee est complete ou explicitement acceptee en dry-run par la policy documentee.
5. Le role officiel `user` du master account et la relation avec l'agent wallet
   sont confirmes. `extraAgents` complete ce controle avec les autres gates,
   sans etre une preuve authoritative unique ; toute reponse ambigue echoue.
6. Une decision DEMO-005 versionnee et relue porte exactement
   `ready_for_demo_testnet_trading_attempt`. Ce gate est humain/documentaire :
   aucune option CLI ne consulte ou n'impose cette decision. Dans l'etat
   documentaire actuel `blocked`, l'operateur ne doit pas activer le chemin.

Hyperliquid ne peut atteindre `demo_testnet_enabled` que si, en plus :

1. `DEMO_TRADING_ENABLED=1` et `HYPERLIQUID_TESTNET_TRADING_ENABLED=1`.
2. `demo_testnet_write_enabled=true`, `mainnet_write_enabled=false` et
   `kill_switch_enabled=false`.
3. La requete concrete contient `client_order_id`, symbole/marche whitelist,
   notional positif sous plafond et stop-loss present.
4. La private observability policy autorise le statut Hyperliquid testnet.
5. Le signer utilise uniquement une API wallet testnet dediee.
6. `userRole=user` est prouve par la surface officielle pour le master account,
   puis la relation API wallet/account est confirmee avec `extraAgents` et les
   autres gates, en mode fail-closed.
7. Le nonce manager persistant est operationnel et audite.
8. Une compensation fail-safe est documentee si le SL ne peut pas etre attache.
9. L'audit demo/testnet trading est ecrit avant de considerer la mutation
   autorisee.
10. TradingV3 a l'ownership externe exclusif du compte et de l'agent ; le
    symbole est flat et sans ordre ouvert avant puis juste avant nonce.
11. Le plan schema v1 est strict, `isolated`, limit/GTC, a decimales canoniques,
    SL plein volume et preuve `meta` + `activeAssetData` authoritative fraiche.

Les formules authoritative sont celles de Hyperliquid : maintenance margin
`notional * maintenance_margin_rate - maintenance_deduction`, avec rate et
deduction issus des margin tiers de `meta`, puis liquidation
`price - side * margin_available / position_size / (1 - l * side)`. Pour
`isolated`, `margin_available = isolated_margin - maintenance_margin_required`.
HL-012 lie cette evidence au leverage/mode/user/coin de `activeAssetData` et
resout le tier au prix de liquidation. Voir [Margin tiers](https://hyperliquid.gitbook.io/hyperliquid-docs/trading/margin-tiers),
[Liquidations](https://hyperliquid.gitbook.io/hyperliquid-docs/trading/liquidations)
et [Info endpoint perpetuals](https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint/perpetuals).

## Non supporte

Les elements suivants restent explicitement non supportes :

- ordre mainnet Hyperliquid ;
- utilisation d'une private key de wallet principal dans l'application ;
- activation Hyperliquid par presence de credentials ;
- spot ou builder-deployed perps sans PR explicite ;
- vaults et subaccounts, strictement non supportes par HL-012 ;
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
| Secret de wallet principal expose | API wallet dediee obligatoire pour HL-012, private key uniquement dans le sidecar, redaction defensive, aucun secret dans Git. |
| Collision de nonce | Nonce manager persistant par signer avant tout broadcast. |
| Asset id divergent | Mapping issu de metadata testnet, jamais hardcode depuis mainnet ; collision d'asset rejetee avec `hyperliquid_asset_collision`. |
| Frais/funding absents | Flags de qualite et PnL non certifie, jamais zero implicite. |
| Private stream incomplet | `demo_testnet_enabled` bloque par private observability policy. |
| Partial fill ou SL attach failure | Compensation HL-012 par cancel/close et reconciliation cloid/oid ; ambiguite => quarantaine durable. |
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
HYPERLIQUID_SIGNER_BROADCAST_ENABLED=0
DEMO_TRADING_ENABLED=0
# Config effective / safety envelope :
demo_testnet_write_enabled=false
kill_switch_enabled=true
```

HL-012 ajoute un kill switch durable DB avec fallback filesystem et la table
`hyperliquid_testnet_execution_attempt` via une migration additive distincte.
Le rollback doit trip la DB, arreter le profile `hyperliquid-testnet` et
conserver toutes les preuves, y compris les tentatives non terminales. Un
marker fallback ne doit jamais etre supprime a la main : utiliser
`app:hyperliquid:testnet:quarantine-recover` uniquement lorsque la DB est deja
lisible et `tripped`, puis redemarrer les workers. Voir le runbook
[Hyperliquid testnet controlled trading](../runbooks/hyperliquid-testnet-controlled-trading.md)
pour les commandes exactes, et [Demo/Testnet kill switch](../runbooks/demo-testnet-kill-switch.md)
pour les gates communes.

## References officielles

- Hyperliquid API : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api>
- Hyperliquid Info endpoint : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint>
- Hyperliquid Exchange endpoint : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/exchange-endpoint>
- Hyperliquid Nonces and API wallets : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/nonces-and-api-wallets>
- Hyperliquid Asset IDs : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/asset-ids>
- Hyperliquid Tick and lot size : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/tick-and-lot-size>
- Hyperliquid Rate limits : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/rate-limits-and-user-limits>
- Hyperliquid WebSocket : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket>
- Hyperliquid Perpetuals info (`meta`, `activeAssetData`) : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint/perpetuals>
- Hyperliquid Margin tiers : <https://hyperliquid.gitbook.io/hyperliquid-docs/trading/margin-tiers>
- Hyperliquid Liquidations : <https://hyperliquid.gitbook.io/hyperliquid-docs/trading/liquidations>
