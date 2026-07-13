# OKX demo readiness

## Statut

ADR documentaire OKX-001, accepte le 2026-06-29.

OKX reste `target_dry_run_only`. Cette page ne rend pas OKX utilisable en
ecriture demo et ne rend jamais OKX utilisable en mainnet. Elle documente ce qui
existe, ce qui manque pour une demo controlee et les gates qui doivent rester
fermees jusqu'aux PRs suivantes.

Voir aussi :

- [OKX dry-run](okx-dry-run.md)
- [Exchange readiness matrix](exchange-readiness-matrix.md)
- [Exchange runtime gates](exchange-runtime-gates.md)
- [Demo/Testnet Safety Envelope](demo-testnet-safety-envelope.md)
- [Exchange private observability policy](exchange-private-observability-policy.md)
- [OKX private WS observability runbook](../runbooks/okx-private-ws-observability.md)

## Decision

Le perimetre initial OKX est :

| Axe | Decision |
|---|---|
| Exchange | `okx` uniquement. |
| Environnement | `demo` uniquement pour toute future ecriture controlee. |
| Market type | `perpetual` / OKX `SWAP` d'abord. |
| Spot | Hors perimetre initial tant qu'un support explicite n'est pas ajoute. |
| Public read-only | Requis avant toute readiness demo. |
| Private read-only | Requis avant toute readiness demo. |
| Local dry-run | Autorise uniquement sans appel HTTP ni `privatePost`. |
| Demo trading | Plus tard, par PR dediee, avec activation explicite et SL obligatoire. |
| Mainnet | Hors perimetre et bloque. |

`dry_run=false` ne signifie jamais mainnet dans cette serie. Il signifie seulement
qu'une future tentative mutative demande une ecriture `demo`, sous guards
explicites. Par defaut, `DEMO_TRADING_ENABLED=0`,
`OKX_DEMO_TRADING_ENABLED=0`, `mainnet_write_enabled=false`,
`demo_testnet_write_enabled=false` et `kill_switch_enabled=true` doivent maintenir
la voie mutative fermee.

## Definitions operationnelles

| Terme | Sens dans TradingV3 |
|---|---|
| Local dry-run | Simulation locale, sans HTTP, sans socket prive, sans ordre exchange. |
| OKX demo | Environnement demo OKX, avec cles demo dediees et header `x-simulated-trading: 1`. |
| Controlled demo trading | Future ecriture OKX demo, jamais activee par cette ADR. |
| Mainnet | Environnement reel OKX. Interdit en ecriture dans cette serie. |

Les logs, fixtures et docs ne doivent contenir aucun secret. Les exemples ne
doivent utiliser que des noms de variables ou des identifiants factices non
reutilisables.

## Capability matrix

| Capability | Etat OKX-001 | Preuve actuelle | Manque pour demo controlee |
|---|---|---|---|
| Config OKX demo | Partiel | `OkxConfig` normalise `demo` par defaut, separe demo et live. | Verifier les valeurs effectives via runtime-check et config effective. |
| Guard mainnet | Present | `OKX_LIVE_ENABLED` est separe et le runtime-check annonce `Live allowed: no`. | Conserver le blocage dans toutes les PRs OKX. |
| Header demo | Teste | `OkxRestClient` exige `OKX_SIMULATED_TRADING=1` pour les requetes privees demo et ajoute `x-simulated-trading: 1`. | Conserver ce blocage sur tous les chemins prives. |
| Market type `perpetual` | Scope initial | `OkxDryRunExecutionPort` refuse tout market type different de `perpetual`. | Charger et valider les instruments OKX `SWAP`. |
| Spot | Non supporte | Aucun contrat demo spot explicite dans ce perimetre. | PR separee si spot devient necessaire. |
| Public REST read-only | `public_read_only` partiel | OKX-003 lit instruments SWAP, ticker, candles et order book via REST public EEA demo, avec timestamps UTC et erreurs normalisees. | Funding courant/historique et validation runtime complete restent a traiter dans les PRs suivantes. |
| Public WebSocket read-only | Non pret | URI public configurable. | Client public, subscriptions, reconnect, freshness et tests fixtures. |
| Private REST read-only | `private_read_only` partiel | OKX-004 signe les requetes privees demo, lit balance, positions, ordres ouverts, algo-orders ouverts et fills recents sans mutation. | Details d'ordre historiques et enrichissements frais/funding restent pour les PRs metadata/ledger. |
| Private WebSocket read-only | Capacite exploitable, opt-in | `app:okx:private-ws` fournit connexion demo, auth, snapshot REST initial, streams ordres/fills/positions, fallback fills `orders+REST`, heartbeat, reconnexion et statut Redis borne. Le service Compose reste derriere `okx-observability`. | Produire les preuves de recette runtime #188 sur une stack representative; cette capacite seule n'autorise aucune ecriture. |
| Metadata / precision | Partiel OKX-005 | `OkxMetadataProvider::getInstrumentMetadata()` expose `instId`, tick, step, min/max size, contract value, `ctType`, `ctValCcy`, settle currency et max leverage. Les champs requis invalides ou un SWAP inverse bloquent avec `okx_metadata_incomplete`. | Brancher cette metadata dans tous les chemins de sizing demo avant ordre. |
| Lifecycle normalizers | Partiel OKX-006 | `OkxLifecycleNormalizer` normalise order request/status, fills, positions et erreurs vers statuts stables (`pending`, `accepted`, `open`, `partially_filled`, `filled`, `cancel_pending`, `canceled`, `rejected`, `expired`, `failed`, `unknown_requires_resync`). | Brancher ces snapshots dans ledger/state lors des PRs runtime suivantes. |
| Fees / funding / costs | Partiel OKX-005 | Fees maker/taker lues via `trade-fee` avec `groupId` si present, sinon `instFamily`; funding courant lu via `/public/funding-rate`. Valeur absente => `null` + quality flag, jamais zero. | Ledger/certification OKX restent a traiter dans une PR separee. |
| Local dry-run execution | Partiel OKX-009 | `OkxDryRunExecutionPort` ne fait aucun HTTP, retourne `OKX-DRYRUN-{client_order_id}`, sérialise les payloads `set-leverage`, ordre entry et protections SL/TP via `OkxActionFactory`, vérifie symbole/notional/leverage/mainnet et audite safety + private observability. `OkxRuntimeCheck` peut exposer `local_dry_run_ready` puis `demo_testnet_candidate` sans jamais annoncer `demo_testnet_enabled`, `live_ready` ou `mainnet_ready`. OKX-009 ajoute une fixture orchestrateur `recipe-r1-r16-okx-dry-run` et un mode runner `--target-exchange okx` qui bloque R1/R2/R14 si le runtime-check OKX ne sort pas `Schedule ready: yes`. | Exécuter la recette sur stack représentative et conserver les preuves redacted avant toute PR mutative. |
| Controlled demo write | Non supporte | Les guards communs existent, mais OKX reste dry-run only. | PR dediee avec readiness complete, SL immediat, compensation fail-safe et rollback. |
| Mainnet write | Interdit | Runtime-check OKX garde `Live allowed: no`. | Aucun manque a combler dans cette serie. |

## Capability vs readiness

Le worker prive ajoute une **capability d'observabilite read-only**. Quand son
statut frais et complet est accepte par la policy, le runtime-check peut confirmer
la couverture privee et contribuer a `demo_testnet_candidate`. Cela ne constitue
pas une readiness d'ecriture et ne change pas `target_dry_run_only`.

Deux gates restent distincts et obligatoires :

1. **#188** doit produire la recette runtime representative, redacted et
   reproductible de la capability OKX, y compris l'expiration fail-closed apres
   arret du worker.
2. **DEMO-005** doit traiter separement la decision pre-mutative, les protections,
   la compensation, l'audit et l'activation explicite des gates d'ecriture demo.

Cette PR ne debloque ni `demo_testnet_enabled`, ni ordre demo, ni mutation de
levier/protection, ni mainnet. Elle ne ferme ni #188 ni DEMO-005 et ne modifie
aucune strategie, aucun sizing et aucune logique d'ordre.

## Endpoints requis

Les endpoints ci-dessous sont les surfaces attendues pour les PRs suivantes. Ils
ne sont pas actives par OKX-001.

### Public read-only

| Donnee | Endpoint OKX attendu | Utilisation |
|---|---|---|
| Instruments SWAP | `GET /api/v5/public/instruments` | Liste des symboles, status, tick/lot, contract size, min size. Implante dans OKX-003. |
| Tickers | `GET /api/v5/market/ticker` par instrument | Prix dernier, volume et validation de freshness. Implante dans OKX-003. |
| Candles | `GET /api/v5/market/candles` | Donnees OHLCV pour indicateurs et verification de timeframes. Implante dans OKX-003 avec tri ASC UTC. |
| Order book | `GET /api/v5/market/books` | Spread, best bid/ask et controles de prix d'entree. Implante dans OKX-003. |
| Funding rate | `GET /api/v5/public/funding-rate` | Funding courant et prochain timestamp. Implante dans OKX-005 pour metadata courante. |
| Funding history | Endpoint historique funding si disponible | Reconciliation des couts et analyse PnL. |

Configuration demo public OKX-003 :

```dotenv
OKX_ENV=demo
OKX_DEMO_API_KEY=
OKX_DEMO_API_SECRET=
OKX_DEMO_API_PASSPHRASE=
OKX_API_BASE_URI=https://eea.okx.com
OKX_WS_PUBLIC_URI=wss://wseeapap.okx.com:8443/ws/v5/public
OKX_SIMULATED_TRADING=1
OKX_DEMO_TRADING_ENABLED=0
OKX_LIVE_ENABLED=0
```

Si `OKX_API_BASE_URI` ou `OKX_WS_PUBLIC_URI` sont vides, `OkxConfig` applique ces
valeurs demo EEA par defaut. OKX-003 ne demarre pas de client WebSocket public :
le fallback volontaire est le polling REST public.

### Private read-only

| Donnee | Endpoint OKX attendu | Utilisation |
|---|---|---|
| Balance/account | `GET /api/v5/account/balance` | Equity, marge disponible, devise de compte. |
| Positions | `GET /api/v5/account/positions` | Positions ouvertes, taille, marge, liquidation, PnL provider. |
| Open orders | `GET /api/v5/trade/orders-pending` | Reconciliation des ordres encore actifs. |
| Open algo orders | `GET /api/v5/trade/orders-algo-pending` | Reconciliation des protections conditionnelles SL/TP. |
| Order details | `GET /api/v5/trade/order` | Etat d'un ordre par `ordId` ou `clOrdId`. |
| Algo order details | Endpoint detail algo si retenu | Etat d'une protection conditionnelle par identifiant exchange. |
| Fills | `GET /api/v5/trade/fills` | Prix, quantite, fee, devise fee et id fill. |
| Trade fee | `GET /api/v5/account/trade-fee` | Maker/taker courants par `instFamily`. Implante dans OKX-005 pour metadata couts. |
| Bills / ledger account | Endpoint account bills si retenu | Frais/funding non presents dans les fills. |

Configuration demo private OKX-004 :

```dotenv
OKX_ENV=demo
OKX_DEMO_API_KEY=...
OKX_DEMO_API_SECRET=...
OKX_DEMO_API_PASSPHRASE=...
OKX_API_BASE_URI=https://eea.okx.com
OKX_SIMULATED_TRADING=1
OKX_DEMO_TRADING_ENABLED=0
OKX_LIVE_ENABLED=0
```

Les credentials doivent etre crees dans l'environnement demo OKX avec permissions
read-only minimales. Le provider bloque les requetes privees demo si le flag
`OKX_SIMULATED_TRADING=1` est absent, si l'URL REST pointe vers
`https://www.okx.com`, ou si `OKX_LIVE_ENABLED=1` est combine avec
`OKX_ENV=demo`. Les logs et DTOs ne doivent jamais contenir de secret.

### Demo write future

Les endpoints mutatifs, par exemple placement/cancel d'ordre et protection
conditionnelle, sont hors perimetre OKX-001. Ils ne pourront etre utilises qu'en
OKX demo, avec cles demo dediees, `x-simulated-trading: 1`, whitelists, plafond
notionnel, SL immediat, audit redacted et rollback teste.

## Donnees attendues

Une readiness demo OKX ne doit pas etre consideree complete sans ces donnees :

- instrument OKX normalise vers le symbole interne ;
- `market_type=perpetual` et type OKX `SWAP` confirmes ;
- precision prix, precision quantite, tick size, lot size, contract size, min size ;
- statut de trading instrument et devise de reglement ;
- best bid/ask, last/mark si disponible, timestamps de freshness ;
- funding courant et historique exploitable ;
- balance, equity, marge disponible et devise ;
- positions avec taille, side, prix entree, liquidation si expose, PnL provider ;
- open orders, details ordre, `client_order_id`, `exchange_order_id` ;
- protections conditionnelles relues via les surfaces algo-order ;
- fills avec id exchange, prix, quantite, fee amount, fee currency et timestamp ;
- statut observabilite privee avec snapshot initial et streams ordres/fills/positions.

Les donnees manquantes restent manquantes. Elles ne doivent jamais etre converties
en zero pour obtenir une certification PnL ou une readiness mutative.

## Gates avant demo controlee

OKX peut progresser vers `demo_testnet_candidate` seulement si :

1. `exchange=okx`, `environment=demo`, `market_type=perpetual`.
2. Les instruments publics OKX `SWAP` sont charges et valides.
3. Les metadata et precisions sont coherentes avec le plan d'ordre.
4. La lecture publique est fraiche et bornee.
5. La lecture privee account/positions/orders/fills est authentifiee et redacted.
6. Les protections SL/TP conditionnelles sont visibles par la lecture algo-order.
7. `stopLossCapability=true` est prouve pour que `demo_testnet_candidate` soit
   atteignable par `ExchangeReadinessEvaluator`.
8. L'observabilite privee est complete ou explicitement acceptee par une policy
   future documentee.
9. Fake/Paper reste disponible pour rejouer les scenarios protection et duplicate
   `client_order_id`.
10. Le dry-run local produit une trace deterministe sans HTTP.

OKX ne peut atteindre `demo_testnet_enabled` que si, en plus :

1. `DEMO_TRADING_ENABLED=1` et `OKX_DEMO_TRADING_ENABLED=1`.
2. `demo_testnet_write_enabled=true`, `mainnet_write_enabled=false` et
   `kill_switch_enabled=false`.
3. La requete concrete contient `client_order_id`, symbole/marche whitelist,
   notional positif sous plafond et stop-loss present.
4. La private observability policy autorise le statut OKX demo.
5. Le header `x-simulated-trading: 1` est garanti sur toute requete demo.
6. Une compensation fail-safe est documentee si le SL ne peut pas etre attache.
7. L'audit demo trading est ecrit avant de considerer la mutation autorisee.

## Non supporte

Les elements suivants restent explicitement non supportes :

- ordre mainnet OKX ;
- activation OKX live par presence de credentials ;
- utilisation de cles mainnet dans l'application ;
- spot, margin, options ou tout autre marche hors `perpetual` ;
- retrait, transfert, sub-account management ou mutation account ;
- fallback silencieux vers Bitmart quand `exchange=okx` ;
- ordre demo sans SL immediat ou compensation fail-safe ;
- certification PnL nette OKX tant que les fills/couts ne sont pas complets ;
- logs de payloads bruts sensibles, signatures, cookies, tokens ou secrets ;
- changement de strategie, EntryZone, Risk/Leverage ou SL/TP metier dans cette
  serie de readiness.

## Risques et mitigations

| Risque | Mitigation |
|---|---|
| Confusion demo/mainnet | Pairing strict `okx/demo`, `mainnet_write_enabled=false`, `Live allowed: no`, header demo obligatoire. |
| Metadata incomplete | Fail closed : pas de sizing, pas d'ordre, readiness non complete. |
| Frais/funding absents | Flags de qualite et PnL non certifie, jamais zero implicite. |
| Private stream indisponible | `demo_testnet_enabled` bloque par private observability policy. |
| Partial fill ou SL attach failure | Scenario Fake/Paper d'abord, compensation fail-safe avant toute ecriture demo. |
| Rate limits ou donnees stale | Bornes de timeout/retry read-only, freshness explicite, pas de mutation si donnees obsoletes. |
| Secret dans logs/docs | Redaction defensive, exemples sans valeurs, audit sans payload brut. |

## Rollback

Le rollback de l'observabilite privee consiste d'abord a arreter et supprimer le
service profile, puis a laisser expirer son statut Redis. Le runbook detaille la
procedure complete; aucune gate d'ecriture ne doit etre ouverte pendant ce
rollback.

Le rollback operationnel a conserver pour les PRs suivantes reste :

```bash
OKX_ENV=demo
OKX_LIVE_ENABLED=0
DEMO_TRADING_ENABLED=0
OKX_DEMO_TRADING_ENABLED=0
```

`OKX_LIVE_ENABLED=0` est obligatoire dans un rollback OKX, meme si les gates
demo sont fermees, afin de conserver le blocage mainnet dans les chemins qui
consultent directement la configuration OKX.

et cote config effective :

```yaml
trading:
  execution:
    mainnet_write_enabled: false
    demo_testnet_write_enabled: false
    kill_switch_enabled: true
```

Apres changement d'environnement ou de config, redemarrer les processus qui les
lisent avant toute nouvelle tentative.

## Consequences ADR

Cette ADR rend explicite que la prochaine etape OKX doit combler les lectures et
normalisations avant toute ecriture. Elle autorise les PRs de skeleton, public
read-only, private read-only, metadata, normalizers et dry-run serialization.

Elle interdit de traiter `OkxDryRunExecutionPort` comme une readiness demo
mutative : ce port est une preview locale sans HTTP. Elle interdit aussi de
considerer OKX mainnet comme une cible de cette serie, meme si des credentials ou
un adapter existent.
