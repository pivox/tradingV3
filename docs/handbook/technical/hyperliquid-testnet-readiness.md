# Hyperliquid testnet readiness

## Statut

ADR documentaire HL-001, accepte le 2026-06-30.

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
| Public read-only | A livrer | Endpoint `info` officiel couvre metadata, market data et candles. | Provider public read-only avec pagination, freshness, rate-limit et erreurs normalisees. |
| Account read-only | A livrer | Endpoint `info` officiel couvre state utilisateur, fills et funding. | Client account read-only testnet, redaction et observabilite privee complete. |
| WebSocket public | A livrer | Endpoint WS testnet officiel disponible. | Client public, subscriptions, reconnect, freshness et fallback documente. |
| WebSocket prive / equivalent | A livrer | State compte lisible via `info`, streams a valider. | Policy de snapshot initial + delta, ou justification equivalente avant mutation. |
| Signer fake/local | A livrer | `HyperliquidDryRunExecutionPort` existant ne signe pas et ne broadcast pas. | Abstraction de signature testable sans private key reelle. |
| Signer testnet | Non supporte | Hors perimetre HL-001. | API wallet testnet dedie, redaction, aucun secret mainnet, tests de payload signe sans broadcast. |
| Nonce manager | Non supporte | Exigence documentaire HL-001. | Compteur persistant par signer, monotone, restart-safe et auditable. |
| Metadata / precision | A livrer | Docs officielles definissent asset IDs, tick et lot size. | Mapping `symbol -> asset`, tick/lot, size decimals, min notional si disponible, flags si absent. |
| Fees / funding / costs | A livrer | Funding et user funding sont exposes par `info`. | DTO couts avec valeurs absentes en `null` + quality flag, jamais zero implicite. |
| Local dry-run no broadcast | Partiel | `HyperliquidDryRunExecutionPort` simule sans HTTP ni `/exchange`. | Serialization future des payloads HL sans broadcast ni secret. |
| Runtime-check candidate | Partiel | `app:exchange:runtime-check hyperliquid perpetual` garde Hyperliquid dry-run only. | Niveaux `public_read_only`, `private_read_only`, `local_dry_run_ready`, puis `demo_testnet_candidate`. |
| SL/TP / protection | A valider | Aucun attachement runtime Hyperliquid n'est prouve par HL-001. | Modele ordre/protection testnet avec SL immediat ou compensation fail-safe. |
| Controlled testnet write | Non supporte | Les guards communs existent, mais Hyperliquid reste dry-run only. | PR dediee apres readiness complete, SL obligatoire, reconciliation et rollback. |
| Mainnet write | Interdit | `mainnet_write_enabled=false` reste requis. | Aucun manque a combler dans cette serie. |

## Surfaces API attendues

Les surfaces ci-dessous servent a cadrer les PRs suivantes. Elles ne sont pas
activees par HL-001.

### Public read-only

| Donnee | Surface Hyperliquid attendue | Utilisation |
|---|---|---|
| Metadata perps | `POST /info` avec type metadata perps | Mapping asset, symboles, size decimals et univers perpetual. |
| All mids / prix courants | `POST /info` type market data | Prix de reference, freshness et sanity checks. |
| Order book | `POST /info` type L2 book | Best bid/ask, spread et controles de prix d'entree. |
| Candles | `POST /info` type candle snapshot | OHLCV pour indicateurs et validation de timeframes. |
| Funding | `POST /info` funding history | Couts de funding et analyse PnL non certifiee tant que le ledger n'est pas complet. |
| WebSocket public | `wss://api.hyperliquid-testnet.xyz/ws` | Flux trades/book/candles si retenus, avec fallback REST explicite. |

Configuration testnet public cible :

```dotenv
HYPERLIQUID_ENV=testnet
HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz
HYPERLIQUID_WS_URI=wss://api.hyperliquid-testnet.xyz/ws
HYPERLIQUID_MAINNET_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
```

### Account read-only

| Donnee | Surface Hyperliquid attendue | Utilisation |
|---|---|---|
| Account state | `POST /info` user state | Equity, margin summary, positions ouvertes. |
| Open orders | `POST /info` open orders | Reconciliation ordres actifs et `client_order_id` si expose. |
| User fills | `POST /info` user fills / fills by time | Prix, quantite, fees, side, oid, timestamps et pagination. |
| Historical orders | `POST /info` historical orders | Statuts terminaux et reconciliation apres restart. |
| User funding | `POST /info` user funding | Funding utilisateur, couts et quality flags. |
| WebSocket account | WS testnet si retenu | Deltas ordres/fills/positions, ou justification d'un polling read-only borne. |

Configuration account read-only cible :

```dotenv
HYPERLIQUID_ENV=testnet
HYPERLIQUID_ACCOUNT_ADDRESS=0x0000000000000000000000000000000000000000
HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz
HYPERLIQUID_WS_URI=wss://api.hyperliquid-testnet.xyz/ws
HYPERLIQUID_MAINNET_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
```

L'adresse de compte n'est pas un secret. Les private keys, API wallet keys,
signatures et payloads signes restent interdits dans Git, logs, fixtures,
screenshots et docs.

### Signer et mutation future

Les actions `POST /exchange` sont hors perimetre HL-001. Une future PR mutative
testnet devra prouver :

- API wallet testnet dedie au bot, jamais private key du wallet principal ;
- signer abstrait et testable avec fixtures sans secret ;
- nonce manager persistant par signer ;
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
- tick size, lot size, size decimals et contraintes de taille ;
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

Hyperliquid peut progresser vers `demo_testnet_candidate` seulement si :

1. `exchange=hyperliquid`, `environment=testnet`, `market_type=perpetual`.
2. Les endpoints utilises pointent vers `api.hyperliquid-testnet.xyz`.
3. Les metadata et precisions sont coherentes avec le plan d'ordre.
4. La lecture publique est fraiche et bornee.
5. La lecture account est redacted et couvre positions, ordres, fills et funding.
6. Les nonces et le signer restent inactifs ou fake tant que le mode est dry-run.
7. `stopLossCapability=true` est prouve avant tout statut candidat mutatif.
8. L'observabilite privee est complete ou explicitement acceptee par une policy
   future documentee.
9. Fake/Paper reste disponible pour rejouer les scenarios protection, duplicate
   `client_order_id`, partial fill et restart.
10. Le dry-run local produit une trace deterministe sans broadcast `/exchange`.

Hyperliquid ne peut atteindre `demo_testnet_enabled` que si, en plus :

1. `DEMO_TRADING_ENABLED=1` et `HYPERLIQUID_TESTNET_TRADING_ENABLED=1`.
2. `demo_testnet_write_enabled=true`, `mainnet_write_enabled=false` et
   `kill_switch_enabled=false`.
3. La requete concrete contient `client_order_id`, symbole/marche whitelist,
   notional positif sous plafond et stop-loss present.
4. La private observability policy autorise le statut Hyperliquid testnet.
5. Le signer utilise uniquement une API wallet testnet dediee.
6. Le nonce manager persistant est operationnel et audite.
7. Une compensation fail-safe est documentee si le SL ne peut pas etre attache.
8. L'audit demo/testnet trading est ecrit avant de considerer la mutation
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
| Asset id divergent | Mapping issu de metadata testnet, jamais hardcode depuis mainnet. |
| Frais/funding absents | Flags de qualite et PnL non certifie, jamais zero implicite. |
| Private stream incomplet | `demo_testnet_enabled` bloque par private observability policy. |
| Partial fill ou SL attach failure | Scenario Fake/Paper d'abord, compensation fail-safe avant toute ecriture testnet. |
| Rate limits ou donnees stale | Pagination, bornes de timeout/retry read-only, freshness explicite, pas de mutation si donnees obsoletes. |

## Rollback

HL-001 est docs-only. Le rollback applicatif est le revert de cette page et de
son entree de navigation.

Le rollback operationnel a conserver pour les PRs suivantes reste :

```bash
HYPERLIQUID_ENV=testnet
HYPERLIQUID_MAINNET_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
DEMO_TRADING_ENABLED=0
```

## References officielles

- Hyperliquid API : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api>
- Hyperliquid Info endpoint : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint>
- Hyperliquid Exchange endpoint : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/exchange-endpoint>
- Hyperliquid Nonces and API wallets : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/nonces-and-api-wallets>
- Hyperliquid Asset IDs : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/asset-ids>
- Hyperliquid Rate limits : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/rate-limits-and-user-limits>
- Hyperliquid WebSocket : <https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket>
