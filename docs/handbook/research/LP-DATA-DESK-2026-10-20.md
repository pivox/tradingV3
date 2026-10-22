# LP-DATA-DESK — ce que la documentation établit pour le programme de fourniture de liquidité événementielle (2026-10-20)

> **Nature du document.** Desk de données et de conformité pour le programme issu de
> `docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md` (séquence P1→P2→P3→G2→G4→G5→G1, règles §3.5) et
> `docs/handbook/reports/research-positive-pnl-structural-2026-10-16.md` (piste K1(c)). Ces deux rapports ne sont pas dans
> le worktree `lp-programme` ; ils ont été lus dans `.worktrees/research-scalping-theory/docs/handbook/reports/` (lecture seule).
> **Méthode.** Documentation officielle seulement (OKX docs-v5 globale et EEE, changelog, pages help OKX Europe, API Agreement
> EEE ; Hyperliquid GitBook en version markdown et README officiel du nœud). Les pages ont été téléchargées en HTML/markdown
> (hachées sha256, dossier privé `a4-data-desk/raw/`) et converties en texte par un outil maison, parce que l'outil de lecture
> tronque la docs-v5 à 406 161 caractères (avant les sections WebSocket Market Data / Public Data) et refuse de citer plus de
> 125 caractères d'un texte juridique. **Aucune donnée de marché lue** : aucun WebSocket, aucun endpoint `market/*`,
> `public/funding-rate*`, `public/instruments`, aucun bucket S3, aucun fichier historique ; aucune date 2026-10-14..31 ;
> rien sous `20261013T100000Z-forward-okx-r1/` ; aucun git, Docker, systemd, `.env`, clé, drapeau. Date de lecture de toutes
> les sources : **2026-10-20**. Chaque fait porte son URL ; ce qui n'est pas écrit est marqué **UNKNOWN**.
> **Conventions.** « docs-v5 » = https://www.okx.com/docs-v5/en/ ; « docs EEE » = https://my.okx.com/docs-v5/en/ ;
> « changelog » = https://www.okx.com/docs-v5/log_en/. Les citations sont recopiées telles quelles (anglais). Rien ici n'autorise
> un ordre, une capture ou un téléchargement : ce sont des faits documentaires soumis aux décisions P2 du propriétaire.
>
> **Révision du 2026-10-20/21** (après les relectures `review/quant.md` et `review/cto.md`, verdicts `NEEDS_CHANGES`). Aucun fait
> documentaire n'a été contesté ; les corrections portent sur l'**échelle** à laquelle les questions P2 sont posées (quant B4 :
> une capture continue de ≈ 5 mois, pas 2–8 semaines ; hôte global), sur l'état du collecteur (alias EEE aligné, CTO m1) et sur
> deux ajouts demandés (budget disque, CTO §4.3 ; seuil relatif de G4, quant B2). Détail en §8 « Traitement de la revue ».
> Aucune page n'a été re-téléchargée ; les empreintes de §9 sont celles du 2026-10-20.

---

## 0. Réponse en dix lignes

1. **Hôte EEE.** La docs-v5 globale impose le domaine régional : « EU users (registered on my.okx.com): Use eea.okx.com as your
   API domain. See the EU API documentation. » La docs EEE donne `REST https://eea.okx.com`, `wss://wseea.okx.com/ws/v5/public`
   (sans port), démo `wss://wseeapap.okx.com/ws/v5/public` + en-tête `x-simulated-trading: 1`. Le collecteur du programme a été
   aligné sur la forme documentée (`python-orchestrator/scripts/research_lp/xperp_capture.py` : `WS_PUBLIC_EEA =
   "wss://wseea.okx.com/ws/v5/public"`, relecture CTO m1, passe `a5-corrections-cto`, vérifié le 2026-10-21) ;
   `trading-app/src/Exchange/Okx/OkxConfig.php` garde `wseeapap.okx.com:8443` (WS privé démo, inchangé) ; l'acceptation d'un port
   par `wseea` reste UNKNOWN mais n'est plus requise par le collecteur.
2. **Canaux publics** (docs globale et EEE identiques) : `trades` (agrégé « once per taker order, filled price, source », champ
   `count`), `books5` (5 niveaux, instantané **toutes les 100 ms** s'il y a changement, colonnes prix/quantité en contrats/« 0 »
   déprécié/nombre d'ordres, pas de checksum), `bbo-tbt` (1 niveau, **10 ms**, ouvert à tous les paliers depuis 2026-03-02),
   `mark-price` (200 ms si changement, sinon 10 s), `funding-rate` (push « in 30s to 90s », `instType FUTURES: X-Perps futures`),
   `open-interest` (toutes les 3 s), `liquidation-orders` (souscription par `instType` ∈ {SWAP, FUTURES, MARGIN, OPTION},
   champs `side/posSide/bkPx/sz/bkLoss/ts`, « This data doesn't represent the total number of liquidations on OKX », ordre non
   chronologique, REST d'historique supprimé fin avril 2023), `adl-warning` (1/s en état warning/adl seulement).
3. **Couverture X-Perps** : seuls `funding-rate` (canal et REST) sont explicitement documentés pour `FUTURES: X-Perps futures`
   (changelog 2026-03-31). `trades`/`books5`/`mark-price`/`open-interest` se souscrivent par `instId` sans restriction
   documentée ; `liquidation-orders` accepte `FUTURES`. Rien n'exclut, rien ne confirme nommément les X-Perps : la capture de
   **comptage** P3 reste le seul moyen d'établir couverture et cadence.
4. **Limites** : 3 connexions/s par IP ; 480 subscribe/unsubscribe/login par heure et par connexion ; 64 Ko par message de
   souscription ; coupure après 30 s sans donnée (ping `'ping'`/`'pong'`, N < 30 s) ; la limite de 30 connexions par canal ne
   vise que les canaux privés. À l'échelle réelle du programme (≤ 40 X-Perps + 2 swaps globaux, 5 canaux par `instId` + 3 par
   `instType`, ≈ 203 souscriptions sur l'hôte EEE — calcul d'audit §1.2), une connexion par hôte suffit ; la portée du budget de
   480 (un)subscribe/h (par connexion au sens strict, ou par IP) est UNKNOWN et décide du coût d'une reconnexion (§1.2).
5. **Historique REST** : `funding-rate-history` « can return data up to three months », 400 lignes/page (instId X-Perp accepté) ;
   `history-candles` « from recent years » (1 s : 3 mois), 300/page ; `history-mark-price-candles` et `history-index-candles`
   « from recent years », 100/page ; `history-trades` « last 3 months », 100/page ; `market/candles` 1 440 dernières. Un endpoint
   de **fichiers historiques** `GET /api/v5/public/market-data-history` (trades tick, bougies 1 min, funding, carnets 400/5000
   niveaux, agrégation jour/mois, T+2/T+3, « backfill in progress ») existe dans la docs globale seulement — absent de la docs EEE.
6. **API Agreement EEE** (« Last Updated: 28 July 2026 ») : §1.8 définit Market Data (prix, carnet, trades, funding, OI…, « whether
   accessed with or without authentication ») ; §9.2 licence limitée « for your own internal purposes » ; §9.4 : usage « solely for
   your own personal, non-commercial trading and account management purposes », (a) pas de redistribution/publication, (b) pas
   de produit de données concurrent, (c) pas d'entraînement/évaluation d'un modèle IA « intended to be commercialized or made
   available to third parties », (d) pas de substitution aux licences commerciales ; s'applique « equally » aux endpoints publics ;
   scripts automatisés interdits « at a rate or scale that (a) exceeds what is reasonably necessary for your personal trading use ».
   Textes intégraux §2 ; neuf questions que seul le propriétaire tranche (§2.3 ; la neuvième, l'hôte global, ajoutée après la relecture).
7. **Hyperliquid** : bucket `hl-mainnet-node-data` (`node_fills_by_block` via `--write-fills --batch-by-block`, « API fills
   format » ; anciens `node_fills` et `node_trades`), `hyperliquid-archive` (`market_data/[date]/[hour]/l2Book/[coin].lz4`,
   `asset_ctxs/[date].csv.lz4`, « approximately once a month », « data may be missing »), requester-pays (« the requester of the
   data must pay for transfer costs »). Le format API des fills porte un objet optionnel `liquidation {liquidatedUser?, markPx,
   method: "market" | "backstop"}` (types WebSocket officiels). **Couverture temporelle et licence : UNKNOWN** (non écrites ;
   conditions d'utilisation non lisibles sans JavaScript).
8. **Liquidation OKX vs Hyperliquid** : OKX = ratio de maintenance ≤ 100 % → annulation d'ordres, liquidation partielle par
   paliers, reprise par le moteur à un « entrusted price » (profondeur, base, mark), frais de liquidation au taux taker + frais
   de compensation versés au « OKX pool », fonds de sécurité, ADL classé par leverage PnL % ; Hyperliquid = marge de maintenance
   = ½ IM au levier max (1,25–16,7 %), ordres **market** sur le carnet pour toute la taille, backstop par le liquidator vault
   (HLP) sous 2/3 de la MM, « no clearance fee », pas d'ADL documenté. Déclaration M9 en §4.3.
9. **Marge des ordres au repos** : la docs API définit `ordFrozen` (« Margin frozen for open orders »), `ordFroz` (cross, USD) et
   un `imr` qui « includes … pending orders » ; la page des règles de marge donne les formules cross one-way/hedge incluant la
   valeur des ordres ouverts et un « order loss » ajouté au coût d'ouverture — mais pour USDT/crypto-margined, « may not apply to
   all customers » ; la page Selected Margin ne parle que des positions. **Règle exacte X-Perps/Selected Margin : UNKNOWN.**
   Mode de position : `posMode` (`long_short_mode` FUTURES/SWAP, `net_mode`), One-way/Hedge dans le guide X-Perps ; Portfolio
   margin = net seulement ; devise USD-M = USD par défaut, non modifiable avec ordres ou positions ouverts.
10. **Pour le programme** : P3 peut se spécifier entièrement sur la documentation (canaux, cadences, hôte à essayer en premier,
    budget de souscriptions) ; G2 dispose de `bbo-tbt` à 10 ms (meilleur que `books5` pour touch/through) ; G4 n'a **aucun
    historique OKX** et un flux partiel non ordonné ; G5 dispose de `fundingTime/nextFundingTime/settState` ; le Gate 1 HL exige
    une décision de licence et de coût avant tout `aws s3`.

---

## 1. OKX docs-v5 : canaux, limites, hôtes, historiques

### 1.1 Hôtes : global vs EEE

| Fait | Texte | Source |
|---|---|---|
| Obligation régionale | « Depending on where you registered, you must use the correct domain for both API calls and documentation. Using openapi.okx.com will not work for the regions below. […] EU users (registered on my.okx.com): Use eea.okx.com as your API domain. See the EU API documentation. » | docs-v5, Overview |
| Production globale | REST `https://openapi.okx.com` ; WS `wss://ws.okx.com/ws/v5/public` / `private` / `business` ; SBE `wss://ws.okx.com/ws/v5/public-sbe` | docs-v5, Production Trading Services |
| Démo globale | REST `https://openapi.okx.com` + `x-simulated-trading: 1` ; WS `wss://wspap.okx.com/ws/v5/…` | docs-v5, Demo Trading Services |
| **Production EEE** | « REST: https://eea.okx.com — Public WebSocket: wss://wseea.okx.com/ws/v5/public — Private WebSocket: wss://wseea.okx.com/ws/v5/private — Business WebSocket: wss://wseea.okx.com/ws/v5/business » | docs EEE, Production Trading Services |
| **Démo EEE** | « REST: https://eea.okx.com — Public WebSocket: wss://wseeapap.okx.com/ws/v5/public — … » ; « `x-simulated-trading: 1` needs to be added to the header of the Demo Trading request. » | docs EEE, Demo Trading Services |
| Stabilité des domaines | 2026-05-20 : « Regional domains (us.okx.com, eea.okx.com, tr.okx.com) remain unchanged » | changelog |
| Port | La docs EEE n'indique **aucun port**. Au 2026-10-20 le collecteur écrivait `wss://wseea.okx.com:8443/ws/v5/public` ; depuis la passe `a5-corrections-cto` (relecture CTO m1, vérifié le 2026-10-21) `python-orchestrator/scripts/research_lp/xperp_capture.py` écrit `WS_PUBLIC_EEA = "wss://wseea.okx.com/ws/v5/public"` (forme documentée ; `--host` accepte toujours une URL brute pour l'essai). `trading-app/src/Exchange/Okx/OkxConfig.php` garde `wseeapap.okx.com:8443` (WS privé démo, hors programme). Acceptation du port 8443 par l'hôte EEE : **UNKNOWN**, désormais sans objet pour le collecteur. | dépôt + docs EEE |
| Quel hôte sert les canaux publics X-Perps | Non écrit. La règle régionale porte sur « API calls » sans distinguer public/privé. **UNKNOWN** → la capture de comptage P3 l'établit (`--host eea` d'abord, puis `global`). | — |

### 1.2 Limites de connexion et de souscription (WebSocket)

Source : docs-v5 § WebSocket → Connect / Connection count limit / Subscribe (texte identique dans la docs EEE).

- « Connection limit : 3 requests per second (based on IP) ».
- « The total number of 'subscribe'/'unsubscribe'/'login' requests per connection is limited to 480 times per hour. »
- « The connection will break automatically if the subscription is not established or data has not been pushed for more than
  30 seconds. » Maintien : « Set a timer of N seconds whenever a response message is received, where N is less than 30. […] send the
  String 'ping'. Expect a 'pong' as a response. »
- « Users can choose to subscribe to one or more channels, and the total length of multiple channels cannot exceed 64 KB. »
- « The limit will be set at 30 WebSocket connections per specific WebSocket channel per sub-account » — liste des canaux
  concernés : Orders, Account, Positions, Balance and positions, Position risk warning, Account greeks (**canaux privés** ;
  aucun canal public n'est listé).
- Erreur 64003 : canaux `books-l2-tbt` / `books50-l2-tbt` réservés aux VIP4+ (changelog 2026-04-07).

Conséquence (calcul d'audit, pas une mesure), à l'**échelle réelle** du programme (`LP-G2` P-G2-1 révisé, quant B4) : 40 X-Perps ×
5 canaux par `instId` (`trades`, `books5`, `bbo-tbt`, `mark-price`, `funding-rate`) + 3 canaux par `instType` (`liquidation-orders`,
`instruments`, `books-rpi` au comptage) = **203 souscriptions** sur l'hôte EEE (243 avec `open-interest` au comptage), plus 10 sur
l'hôte global pour les deux swaps de calibration (si le propriétaire l'autorise, §2.3 Q9) → une connexion par hôte suffit
(≤ 480/h, paquets de ≤ 64 Ko). Si le budget de 480 est compté **par connexion** au sens strict, une reconnexion repart à zéro ;
s'il est compté par IP (texte : « per connection », portée exacte UNKNOWN), chaque reconnexion coûte ~203 et la seconde dans l'heure
épuise le budget : répartir alors les instruments sur 2–3 connexions (3 connexions/s par IP sont très loin) pour qu'une
reconnexion ne coûte que ~70–100. À établir au comptage P3 (compteur de reconnexions × souscriptions dans `health.log`).

### 1.3 Canaux publics WebSocket — ce qui est écrit

Source : docs-v5 § Order Book Trading → Market Data → WebSocket (`trades`, `Order book channel`) et § Public Data → WebSocket
(`instruments`, `open-interest`, `funding-rate`, `mark-price`, `liquidation-orders`, `adl-warning`). Les mêmes sections, mot
pour mot, existent dans la docs EEE (lignes vérifiées : « doesn't represent the total », « every 100 ms », « 30s to 90s »,
« every 200 ms », « every 3 seconds »).

| Canal | Souscription | Cadence documentée | Champs poussés | Couverture `instType FUTURES` / X-Perps |
|---|---|---|---|---|
| `trades` | `channel`, `instId` | « Data will be pushed whenever there is a trade. Every update may aggregate multiple trades. The message is sent only once per taker order, filled price, source. The count field is used to represent the number of aggregated matches. » | `instId`, `tradeId` (« The last trade ID in the trades aggregation »), `px`, `sz` (« For FUTURES/SWAP/OPTION, the unit is contract »), `side` (« Trade side of taker »), `ts`, `count`, `source` (0 normal / 1 ELP), `seqId` | Par `instId`, aucune liste d'`instType` : non exclu, non nommé → **UNKNOWN** (P3) |
| `books5` | `channel books5`, `instId` | « 5 depth levels snapshot will be pushed in the initial push. Snapshot data will be pushed every 100 ms when there are changes in the 5 depth levels snapshot. » (100 ms depuis 2022-05, changelog 2022-04-25/2022-05-05 : « from pushing data every "200" ms to pushing data every "100" ms ») | `asks`/`bids` = tableaux `[prix, quantité, "0", nombre d'ordres]` : « "411.8" is the depth price — "10" is the quantity at the price (number of contracts for derivatives…) — "0" is part of a deprecated feature and it is always "0" — "4" is the number of orders at the price » ; `ts` ; `seqId` ; **pas de checksum** (« books5 and bbo-tbt channels do not carry a checksum field », changelog 2026-06-23) | idem `trades` → **UNKNOWN** (P3) |
| `bbo-tbt` | `channel bbo-tbt`, `instId` | « 1 depth level snapshot will be pushed in the initial push. Snapshot data will be pushed every 10 ms when there are changes in the 1 depth level snapshot. » ; « `bbo-tbt` channel will be available to users of any trading fee tier » (changelog 2026-03-02) ; `ts` = « timestamp when the book is generated by matching engine » | même format | idem → **UNKNOWN** (P3) |
| `books` | `instId` | 400 niveaux, instantané puis incréments « every 100 ms » ; `seqId`/`prevSeqId` ; checksum déprécié (valeur 0) | — | idem |
| `books-l2-tbt` / `books50-l2-tbt` | login | 10 ms ; **VIP4+** (64003 sinon) | — | hors de portée (palier Regular) |
| `mark-price` | `channel mark-price`, `instId` | « Data will be pushed every 200 ms when the mark price changes, and will be pushed every 10 seconds when the mark price does not change. » | `instType`, `instId`, `markPx`, `ts` | idem → **UNKNOWN** (P3) |
| `funding-rate` | `channel funding-rate`, `instId` | « Retrieve funding rate. Data will be pushed in 30s to 90s. » | `instType` (« SWAP: Perpetual futures — FUTURES: X-Perps futures »), `instId`, `method`, `formulaType`, `fundingRate`, `nextFundingRate`, `fundingTime`, `nextFundingTime`, `minFundingRate`, `maxFundingRate`, `interestRate`, `impactValue`, `settState`, `settFundingRate`, `premium`, `ts` | **Documenté** : changelog 2026-03-31 « Funding rate endpoints now support querying X-Perps futures contracts; request parameter instId accepts X-Perps futures instId values; passing ANY returns funding rates for all X-Perps futures and perpetual contracts; response parameter instType adds new enum value FUTURES » (Get funding rate, Get funding rate history, Funding rate channel) |
| `open-interest` | `channel open-interest`, `instId` | « Retrieve the open interest. Data will be pushed every 3 seconds when there are updates. » | `instType`, `instId`, `oi` (« in units of contracts »), `oiCcy`, `oiUsd`, `ts` | par `instId` → **UNKNOWN** (P3) ; REST `GET /api/v5/public/open-interest` accepte `instType` ∈ {SWAP, FUTURES, OPTION, EVENTS} |
| `liquidation-orders` | `channel liquidation-orders`, **`instType`** ∈ « SWAP FUTURES MARGIN OPTION » (pas d'`instId`) | « Retrieve the recent liquidation orders. **This data doesn't represent the total number of liquidations on OKX.** » ; « Liquidation data comes from different data sources, so the updated data is not necessarily in chronological order. » | `instType`, `instId` (« e.g. BTC-USD-SWAP »), `uly`, `details[]` : `side` (« buy sell — Applicable to FUTURES/SWAP »), `posSide` (« long: Hedge mode long — short: Hedge mode short — net: Net mode »), `bkPx` (« Liquidation mark price. The price of the transaction with the system's liquidation account, only applicable to FUTURES/SWAP »), `sz` (« For FUTURES/SWAP, the unit is contract »), `bkLoss` (« Bankruptcy loss »), `ccy` (MARGIN), `ts` (« Liquidation time ») ; l'exemple montre aussi `instFamily` (absent de la table) | `FUTURES` est dans l'enum (X-Perps = `FUTURES`) ; part des X-Perps dans le flux : **UNKNOWN** ; **aucun historique** : changelog 2023-04-03 « OKX will no longer support users to retrieve platform historical liquidation orders through the REST endpoint by the end of April 2023. Please subscribe to the websocket channel » ; aucun `GET /api/v5/public/liquidation-orders` dans la docs 2026 |
| `adl-warning` | `channel adl-warning`, `instType` ∈ « SWAP FUTURES OPTION », `instFamily` optionnel | « Data is only pushed in the warning or adl state, once every second, displaying the security fund balance and related risk information. No data is pushed in the normal state. » (changelog 2026-06-09 ; plusieurs champs renvoient désormais `""`) | solde du fonds de sécurité et état | `FUTURES` accepté |
| `instruments` | `instType` ∈ « SPOT MARGIN SWAP FUTURES OPTION EVENTS » | incrémental : changement d'état (livraison, cotation, suspension), paramètres (`tickSz`, `minSz`, `maxMktSz`), `expTime`/`listTime` | mêmes champs que REST `public/instruments` | `FUTURES` accepté |
| `economic-calendar` | business WS, **login requis** | « This endpoint is only applicable to VIP 1 and above users in the trading fee tier. » | — | **hors de portée** d'un compte Regular (corrige l'idée de « filtre gratuit » du rapport théorie §3.4) |

Ordre de poussée fixé dans une connexion : « bbo-tbt -> books-l2-tbt -> books50-l2-tbt -> books -> books-elp -> books-rpi -> books5 ».
Place order : « post_only: Post-only order, which the order can only provide liquidity to the market and be a maker. If the order
would have executed on placement, it will be canceled instead. » ; `cancelSource` « 31: The post-only order will take liquidity in
taker orders » (utile au modèle d'annulation au croisement, règle 6 du §3.5).

### 1.4 Endpoints REST d'historique : profondeur et pagination

Source : docs-v5 § Market Data REST et § Public Data REST (mêmes descriptions dans la docs EEE pour `funding-rate-history`,
`candles`, `history-candles`, `history-trades`, `insurance-fund`, `position-tiers`, `open-interest`).

| Endpoint | Profondeur documentée | Pagination / limite | Rate limit | Note X-Perps |
|---|---|---|---|---|
| `GET /api/v5/public/funding-rate-history` | « Retrieve funding rate history. **This endpoint can return data up to three months.** » | `before`/`after` sur `fundingTime` ; `limit` « The maximum is 400; The default is 400 » (changelog 2025-06-19) | 10 req / 2 s, IP + instId | `instId` « e.g. BTC-USD-SWAP or X-Perps futures instId — Applicable to SWAP and X-Perps FUTURES » ; réponse `instType` (« FUTURES: X-Perps futures »), `formulaType`, `fundingRate` (« Predicted »), `realizedRate` (« Actual funding rate »), `fundingTime`, `method` ; avertissement : « users should focus on the difference between fundingTime and nextFundingTime fields to determine the funding fee interval » |
| `GET /api/v5/public/funding-rate` | courant | `instId` ou `ANY` | 10 req / 2 s | champs `fundingRate` (prévision, signe : « positive = long positions pay short positions »), `nextFundingRate`, `fundingTime`, `nextFundingTime`, `minFundingRate`, `maxFundingRate`, `interestRate`, `impactValue`, `settState`, `settFundingRate`, `premium` (formule « [Max (0, Impact bid price – Index price) – Max (0, Index price – Impact ask price)] / Index price »), `ts` |
| `GET /api/v5/market/candles` | « This endpoint can retrieve the latest 1,440 data entries. » | `limit` (non recopié) | 40 req / 2 s | — |
| `GET /api/v5/market/history-candles` | « Retrieve history candlestick charts from recent years(It is last 3 months supported for 1s candlestick). » | `after`/`before` sur `ts` ; `bar` ∈ 1s/1m/3m/5m/15m/30m/1H/2H/4H/… ; `limit` « The maximum is 300. The default is 100. » | 20 req / 2 s, IP | pas de restriction d'`instType` écrite ; profondeur réelle par X-Perp **UNKNOWN** (contrats cotés depuis 2026-03/09 ; le rapport structurel §4.1 p.7 a vu des lignes antérieures au `listTime` sur un daté) |
| `GET /api/v5/market/history-mark-price-candles` | « Retrieve the candlestick charts of mark price from recent years. » | `after`/`before` ; `limit` « The maximum is 100; The default is 100 » | 20 req / 2 s | `instId` « e.g. BTC-USD-SWAP » |
| `GET /api/v5/market/history-index-candles` | « Retrieve the candlestick charts of the index from recent years. » | `limit` max 100 | 10 req / 2 s | `instId` = indice « e.g. BTC-USD — Same as uly » |
| `GET /api/v5/market/trades` | récent | `limit` « The maximum is 500; The default is 100 » | 100 req / 2 s | — |
| `GET /api/v5/market/history-trades` | « Retrieve the recent transactions of an instrument from the last 3 months with pagination. » | `type` « 1: tradeId 2: timestamp » ; `after`/`before` ; `limit` « maximum and default both are 100 » | 20 req / 2 s | — |
| `GET /api/v5/public/open-interest` | courant | — | 20 req / 2 s, IP + instId | `instType` « SWAP FUTURES OPTION EVENTS » ; `oi`, `oiCcy`, `oiUsd` ; historique via `GET /api/v5/rubik/stat/contracts/open-interest-history` (exemple `BTC-USDT-SWAP` ; couverture FUTURES **UNKNOWN**, section non recopiée) |
| `GET /api/v5/public/insurance-fund` (« Get security fund ») | `before`/`after`/`limit` 100 | — | 10 req / 2 s | `instType` « MARGIN SWAP FUTURES OPTION » ; `instFamily` « Required for FUTURES/SWAP/OPTION » ; `type` ∈ `liquidation_balance_deposit`, `bankruptcy_loss` (`adl`, `platform_revenue` dépréciés, vides) ; `details[]` `balance`, `amt` (« generated once per day around 08:00 am (UTC) »), `ccy`, `type`, `ts` |
| `GET /api/v5/public/position-tiers` | — | `instType`, `tdMode` « cross isolated », `instFamily` (requis FUTURES/SWAP/OPTION) | 10 req / 2 s | `tier`, `minSz`, `maxSz`, `mmr`, `imr`, `maxLever` |
| `GET /api/v5/public/instruments` | — | `instType` « FUTURES: Expiry Futures », `instFamily`, `instId` | 20 req / 2 s, IP + instType | champs §1.5 |
| **`GET /api/v5/public/market-data-history`** (« Get historical market data », ajouté 2025-09-02, plage maximale réduite 20 → 10 le 2026-08-06) | « Data availability — Historical data backfill is currently in progress. Data availability may vary by module, instrument, and time period. » ; « Most data for modules 1, 2, 3, 11 is typically available on T+2; order book data is typically available on T+3. » | `module` « 1: Tick-by-tick trade history 2: 1-minute candlestick 3: Funding rate 4: 400-level orderbook 5: 5000-level orderbook (from Nov 1, 2025) 6: 50-level orderbook (will gradually be deprecated…) 11: Borrowing rate » ; `instType` « SPOT FUTURES SWAP OPTION » ; `instFamilyList` (≤ 10, ou `ANY` pour modules 1/2/3/11 en `daily`) ; `dateAggrType` `daily`/`monthly` ; `begin`/`end` « Maximum range: 10 days for daily, 10 months for monthly » ; réponse = liste de fichiers `.zip` (`filename`, `dataTs`, `sizeMB`, `url`) | 5 req / 2 s, IP | **absent de la docs EEE** (`market-data-history` introuvable dans my.okx.com/docs-v5) → disponibilité sur `eea.okx.com` **UNKNOWN** ; format des fichiers carnet **UNKNOWN** ; Market Data au sens §1.8 → soumis à §9.4 ; **non appelé** |

### 1.5 `public/instruments` : champs utiles au programme

Source : docs-v5 § Public Data → Get instruments. `settleCcy` (« Settlement and margin currency ») ; `ctVal` (« for linear contracts,
ctVal is in the base currency […] Notional: linear = sz × ctVal × markPx ») ; `ctType` (« linear … inverse ») ; `ruleType` (« xperp:
perpetual-style futures, only applicable to certain FUTURES contracts. A pre-market X-Perp changes from pre_market to xperp after it
converts ») ; `state` (« live suspend rebase post_only preopen test settling ») ; `expTime` ; `listTime` ; `preMktSwTime` ; `lever`
(« Exchange-defined maximum leverage ceiling […] The actual leverage available to a specific account may be lower ») ; `tickSz`,
`lotSz`, `minSz` (en contrats pour les dérivés) ; `initPxLmtPct` (« Initial price-limit band applied during the first 10 minutes
after contract listing »), `floatPxLmtPct`, `maxPxLmtPct` ; `groupId` (« Expiry futures: 5: Expiry futures group one 6: Expiry
futures group two **8: XPERP group two 10: XPERP RWA group two** […] Users should use this endpoint together with fee rates endpoint
to get the trading fee of a specific symbol ») ; `tradeQuoteCcyList` (« e.g. ["USD", "USDC"] ») ; `futureSettlement` (« Whether
daily settlement for expiry feature is enabled — Applicable to FUTURES cross »). Conflit documentaire sur la taille BTC :
guide X-Perps « Contract size 0.0001 BTC » (BTC-USD_UM_XPERP-040431) vs page des spécifications « BTC-USD_UM XPERP 0.01 BTC » ;
la valeur API `ctVal` n'a pas été lue aujourd'hui → **UNKNOWN**, à trancher sur `public/instruments` au moment de P3.

---

## 2. OKX API Agreement (version EEE)

Source : https://www.okx.com/en-eu/help/okx-api-agreement — titre de page « OKX API Agreement | OKX Europe », en-tête
« **Last Updated: 28 July 2026** ». Le préambule ne nomme pas l'entité (« This OKX API Agreement (the "Agreement") governs access to
and use of the OKX Application Programming Interfaces (the "API Services"). This Agreement supplements the OKX Terms of Service
("OKX ToS") ») ; le pied de page de la version EEE nomme OKX Europe Limited (MiCA, MFSA). La docs-v5 ajoute : « Your continued use of
any OKX API Services following the date of this notice constitutes your acceptance of the Agreement in full. »

### 2.1 Textes recopiés

**§1.8** — « "Market Data" means any price, order book, trade history, ticker, funding rate, index, volatility surface, open interest,
trading volume, or other market information made available through the API Services or through OKX's publicly accessible
endpoints, whether accessed with or without authentication. »

**§9.1 Ownership** — « All rights, title, and interest in and to the OKX Materials, including the API Services, Agent Trade Kit, MCP
Server, Skills, CLIs, SDKs, documentation, specifications, protocols, software, interfaces, data schemas, and Market Data
(collectively, the "OKX Materials"), are and shall remain the exclusive property of OKX or its licensors. Nothing in this Agreement
transfers any ownership rights in the OKX Materials to the User. »

**§9.2 Limited License** — « Subject to compliance with this Agreement and the OKX ToS, OKX grants you a limited, revocable,
non-exclusive, non-transferable, non-sublicensable license to access and use the API Services solely: (a) for your own internal
purposes; (b) in connection with managing and operating your own OKX account; and (c) in a manner consistent with this Agreement and
the OKX ToS. »

**§9.3 Non-Commercial Use Restriction** (extrait pertinent) — « Unless expressly authorized in writing by OKX, you may not: (a)
commercialize, resell, sublicense, distribute, or make available the API Services or Agent Trade Kit to any third party; (b) offer
the API Services as part of a commercial product, SaaS platform, brokerage service, signal service, strategy marketplace, or managed
trading service; (c) use the API Services to operate trading accounts or execute orders on behalf of third parties without OKX's prior
written approval; or (d) create derivative commercial infrastructure […]. For the avoidance of doubt, use of the API Services to
execute trades for your own account, even if profitable, does not constitute commercial resale. »

**§9.4 Market Data — Non-Commercial Use and Redistribution Restrictions** (intégral) —
« General Restriction. Market Data obtained through the API Services is proprietary to OKX or its data licensors. You may access and
use Market Data solely for your own personal, non-commercial trading and account management purposes. You may not:
(a) resell, redistribute, publish, display, or otherwise make Market Data available to any third party, whether for commercial gain
or otherwise, without OKX's prior written consent;
(b) use Market Data to build, operate, or contribute to any competing data product, market data service, financial data aggregator,
price feed, or analytics platform;
(c) use Market Data to train, fine-tune, or evaluate any machine learning model or AI system that is intended to be commercialized or
made available to third parties; or
(d) use Market Data in any manner that competes with or substitutes for OKX's commercial data licensing offerings.
Public Endpoint Data. OKX makes certain Market Data available through unauthenticated public API endpoints without requiring an API
Key. The restrictions in this Section 9.4 apply equally to Market Data accessed through public endpoints as to Market Data accessed
through authenticated endpoints. The fact that Market Data is publicly accessible does not grant any right to redistribute, resell,
or commercially exploit that data.
Automated Scraping and Circumvention. You must not use automated scripts, bots, web scrapers, or any other automated means to
extract Market Data from OKX's public endpoints or website at a rate or scale that: (a) exceeds what is reasonably necessary for
your personal trading use; (b) is intended to circumvent OKX's commercial data licensing requirements; or (c) imposes unreasonable
load on OKX's infrastructure. Users who require high-frequency or large-scale access to Market Data for commercial or institutional
purposes must obtain a separate data licensing agreement with OKX.
Non-OKX Users. OKX acknowledges that certain Market Data endpoints are accessible without an OKX account or API Key. Any person
accessing OKX Market Data through public endpoints without authentication is subject to the same Market Data restrictions set out in
this Section 9.4. OKX reserves the right to implement rate limiting, authentication requirements, or other technical measures on
public endpoints at any time to protect the commercial value of its Market Data. Bypassing or circumventing such technical measures
is expressly prohibited and constitutes a violation of this Agreement and, where applicable, applicable computer fraud or
unauthorized access laws. »

**§9.5 General IP Restrictions** (extrait) — « You shall not: […] circumvent rate limits or security controls; […] ».
**§8.1(c)** — interdit « operating an Authorized AI Agent in a manner that degrades platform performance, imposes unreasonable load on
OKX's infrastructure, or circumvents rate limits ». **§8.2(b)** — interdit « Attempting to circumvent platform restrictions through
the use of multiple accounts, sub-accounts, or coordinated AI agents ». **§9.7** — « OKX may revoke the license granted under this
Section at any time upon breach of this Agreement or if required for security, compliance, or regulatory reasons. »

### 2.2 Ce que le texte dit, sans interprétation au-delà de l'écrit

- Les données publiques (WebSocket public, `public/*`, `market/*`, fichiers `market-data-history`) sont des Market Data (§1.8) et
  relèvent de §9.4 « equally » (Public Endpoint Data).
- L'usage autorisé est formulé comme « your own personal, non-commercial trading and account management purposes » ; le mot
  « research » n'apparaît pas, ni « backtest », ni « storage ».
- L'extraction automatisée n'est pas interdite en soi : elle l'est « at a rate or scale that (a) exceeds what is reasonably necessary
  for your personal trading use ; (b) is intended to circumvent … licensing ; (c) imposes unreasonable load ». La licence séparée
  vise « high-frequency or large-scale access … for commercial or institutional purposes ».
- (a) interdit de « publish, display, or otherwise make Market Data available to any third party, whether for commercial gain or
  otherwise ».
- (c) vise les modèles « intended to be commercialized or made available to third parties ».

### 2.3 Questions que seul le propriétaire tranche (P2)

1. **Une capture publique continue du 2026-11-02 au plus tard le 2027-03-31** (P3, G2, G4 Gate 2, G5 Gate 2 ; ≈ 5 mois, pas
   « 2–8 semaines » — relecture quant B4 : la question doit être posée sur l'échelle réelle de la séquence), sur ≤ 40 X-Perps crypto
   + 2 swaps globaux, **sans ordre**, est-elle à ses yeux « personal, non-commercial trading and account management » (préparation
   de son propre trading) ? Le texte ne nomme ni la recherche ni le stockage. Tout allongement, instrument, canal ou hôte ajouté
   = nouvelle décision.
2. Le volume d'une telle capture (ordre de grandeur documenté : `books5` ≤ 10 instantanés/s, `bbo-tbt` ≤ 100/s, `mark-price` ≤ 5/s,
   `trades` événementiel, × ≤ 40 instruments, une connexion par hôte, ≈ 5 mois) reste-t-il « reasonably necessary for your
   personal trading use » et sans « unreasonable load » ? Le texte ne chiffre rien.
3. Le dépôt `pivox/tradingV3` : des tableaux agrégés (mark-outs moyens, taux de fill) dans un dépôt lisible par des tiers
   sont-ils un « display » de Market Data au sens de (a) ? Position prudente du desk : séries et tables chiffrées dans
   `tradingV3-private` seulement, agrégats statistiques sans prix dans le dépôt — à décider par le propriétaire.
4. Le travail d'agents (LLM) sur ces données : (c) vise des modèles « intended to be commercialized or made available to third
   parties » ; le propriétaire doit dire si son usage entre dans ce périmètre (le desk n'a pas d'avis juridique).
5. L'endpoint `market-data-history` (fichiers) est-il préférable à une capture continue au regard de (c) « unreasonable load » et
   de la clause scraping ? Il est documenté dans la docs globale seulement.
6. §8.2(b) : aucune multiplication de comptes/sous-comptes pour dépasser les limites publiques (sans objet si une seule IP et un
   seul compte).
7. §8.3 : si un fournisseur d'IA tiers est intégré aux « API Services », ses propres conditions s'appliquent (« fully automated trading
   without human oversight » cité en exemple) — pertinent seulement si un agent passe des ordres, ce qui est exclu ici.
8. Acceptation : la docs-v5 dit que la poursuite de l'usage de l'API vaut acceptation de l'accord du 28 juillet 2026.
9. **Hôte global** (ajouté après la relecture quant B4) : la capture de `BTC-USDT-SWAP` / `ETH-USDT-SWAP` (calibration C0 de
   `LP-G2` §6.1, hors univers) passerait par `wss://ws.okx.com/ws/v5/public` depuis un compte EEE ; la docs-v5 écrit « Using
   openapi.okx.com will not work for the regions below » pour les « API calls » (§1.1) sans distinguer le WebSocket public
   (UNKNOWN). Le propriétaire dit si cette capture est acceptable ; sinon C0 porte sur les X-Perps BTC/ETH seuls (signe exigé,
   magnitude rapportée).

---

## 3. Hyperliquid : données historiques

Sources : https://hyperliquid.gitbook.io/hyperliquid-docs/historical-data (version `.md`) ; README officiel du nœud
https://github.com/hyperliquid-dex/node (raw `README.md`, § Flags) ; types WebSocket
https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket/subscriptions ; schémas L1
https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/nodes/l1-data-schemas ; info endpoint
https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint ; limites
https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/rate-limits-and-user-limits.

| Item | Ce qui est écrit | Statut |
|---|---|---|
| Buckets | `hyperliquid-archive` : « `s3://hyperliquid-archive/market_data/[date]/[hour]/[datatype]/[coin].lz4` or `s3://hyperliquid-archive/asset_ctxs/[date].csv.lz4` » ; « L2 book snapshots are available in market_data and asset contexts are available in asset_ctxs. No other historical data sets are provided via S3 (e.g. candles or spot asset data). » ; `hl-mainnet-node-data` : `node_fills_by_block`, `node_fills`, `node_trades`, `explorer_blocks`, `replica_cmds`, `misc_events_by_block` | établi |
| Cadence de publication | « Historical data is uploaded to the bucket `hyperliquid-archive` approximately once a month. There is no guarantee of timely updates and data may be missing. » | établi |
| Format `node_fills_by_block` | « has fills which are streamed via `--write-fills --batch-by-block` from a non-validating node. Older data is in a different format at `s3://hl-mainnet-node-data/node_fills` and `s3://hl-mainnet-node-data/node_trades`. `node_fills` matches the API format, while `node_trades` does not. » ; README : « `--write-fills`: Streams fills in the API fills format to `~/hl/data/node_fills/hourly/{date}/{hour}` » ; « `--batch-by-block`: Writes the above files with one block per line instead of one event per line. The batched data schema is `{local_time, block_time, block_number, events}`, where `events` is a list. » | établi (format JSON par ligne, fichiers horaires ; compression `.lz4` documentée pour `market_data`, **UNKNOWN** pour `node_fills_by_block`) |
| Champs d'un fill (format API) | type `WsFill` : `coin, px, sz, side, time, startPosition, dir, closedPnl, hash, oid, crossed` (« whether order crossed the spread (was taker) »), `fee` (« negative means rebate »), `tid`, **`liquidation?: FillLiquidation`**, `feeToken`, `builderFee?` ; **`interface FillLiquidation { liquidatedUser?: string; markPx: number; method: "market" \| "backstop"; }`** ; l'exemple REST `userFills` montre les mêmes champs sans l'objet optionnel ; `dir` « used for frontend display » (exemples « Open Long », « Sell » ; FAQ : « "Market Liquidation" in your Trade History ») | établi pour le schéma ; présence effective de `liquidation` dans les fichiers S3 et date d'apparition : **UNKNOWN** (à vérifier sur un échantillon de schéma, pas de valeurs) |
| `node_trades` (ancien format) | schéma L1 : `coin, side, time, px, sz, hash, trade_dir_override, side_info[{user, start_pos, oid, twap_id, cloid}]` — **aucun marqueur de liquidation** | établi |
| Événements de liquidation hors fills | `misc_events` → `LedgerDelta.Liquidation { liquidatedNtlPos, accountValue, leverageType, liquidatedPositions[{coin, szi}] }` (pas de prix) ; WS `WsLiquidation { lid, liquidator, liquidated_user, liquidated_ntl_pos, liquidated_account_value }` | établi ; présence dans `misc_events_by_block` S3 : « contains non-trade events such as transfers, staking, and funding » (liquidations non nommées → **UNKNOWN**) |
| Carnet historique | `market_data/.../l2Book/[coin].lz4` ; schéma du fichier et cadence des instantanés **non documentés** ; le WS `l2Book` donne « 5 levels if fast, 20 levels if slow » et `WsBook` « pushed on each block that is at least 0.5 since last push » | **UNKNOWN** (schéma, cadence, profondeur des fichiers) |
| Couverture temporelle | aucune date de début, aucune fin, aucune rupture documentée (l'exemple de commande cite `20230916`) | **UNKNOWN** — un `aws s3 ls` l'établirait, mais c'est un accès au jeu de données, non fait |
| Coût | « Note that the requester of the data must pay for transfer costs. » ; exemple `aws s3 cp … --request-payer requester` | établi (tarif AWS, non chiffré ici) |
| Licence / conditions | la page ne contient aucune mention de licence ; `https://hyperliquid.xyz/terms` → HTTP 403 ; `https://app.hyperliquid.xyz/terms` → page JavaScript sans texte ; `https://hyperfoundation.org/terms` → 404 | **UNKNOWN** ; les résumés tiers (§1.5 « Restricted Persons ») ne sont pas des sources |
| API (si complément) | `userFillsByTime` « Returns at most 2000 fills per response and only the 10000 most recent fills are available » ; limites IP 1 200 poids/min ; « For large batch requests, use the S3 bucket instead. » | établi |

Lecture pour G4 (Gate 1 historique) : le seul jeu documenté avec un marqueur de liquidation **par fill** et un prix de référence
(`markPx`) est `node_fills[_by_block]` (format API). Il faut d'abord (décision propriétaire) un listing du bucket et un fichier
de **schéma** (clés seules) pour dater l'apparition de `liquidation` et de `method`, puis déclarer la transférabilité (§4.3).

---

## 4. Mécanique de liquidation : OKX X-Perps vs Hyperliquid, et déclaration M9

### 4.1 OKX (OKX Europe Markets Ltd, X-Perps = `instType FUTURES`, `ruleType xperp`)

| Élément | Texte | Source (lu le 2026-10-20) |
|---|---|---|
| Déclencheur | « when your maintenance margin ratio falls to 100% or below, meaning your account equity is equal to or less than the required maintenance margin plus any applicable liquidation fees. The specific process includes order cancellation, partial liquidation, and/or full liquidation. » | https://www.okx.com/en-eu/help/tiered-maintenance-margin-ratio-rules-eea (« Published on Dec 8, 2025 Updated on Aug 19, 2026 ») §1.1 |
| Paliers | « Larger positions fall into higher tiers, which require a higher maintenance margin and allow for lower leverage. » ; API `GET /api/v5/public/position-tiers` (`mmr`, `imr`, `maxLever`) | idem §1.2 ; docs-v5 |
| Processus | « OKX will first automatically cancel or reject new orders that require additional margin and kick off the liquidation process. Generally, OKX will take over your account and use the OKX pool to liquidate the position that utilizes the highest margin. […] OKX does not guarantee that your losses will be protected or satisfied by the OKX pool. » | idem §1.4 |
| Prix | « a.Forced-liquidation Price: When the mark price reaches the forced-liquidation price and a user's margin is lower than the required level, force-liquidation will be triggered. b.Bankruptcy Price: The price at which a user loses all margin. c.Entrusted Price of Liquidated Position: The price at which a liquidated position is put into the market by our forced-liquidation engine […] it will not be put into the market at the bankruptcy price directly. Instead, the entrusted price will be calculated based on current market depth, basis, and mark price » ; pertes « compensated by the security fund » | https://www.okx.com/en-eu/help/system-liquidation-mechanism-eea (« Published on Apr 21, 2026 Updated on Aug 13, 2026 ») |
| Frais | « OKX shall impose a liquidation taker fee […] calculated in accordance with the taker fee rate applicable to your current fee tier level. » + « Liquidation clearance fee » (U-margined : « Face value × Contract multiplier × Number of liquidated positions × Mark price × Position tier maintenance margin requirement ») ; « the net proceeds […] will be contributed to the OKX pool » ; FAQ frais EEE (màj 2026-10-07) : « a forced liquidation fee charged at the taker rate of your current tier, and an expiry settlement fee of 0.01% » | tiered-mmr-eea §1.3 ; https://www.okx.com/en-eu/help/trading-fee-rules-faq-eea |
| Mark price | « Mark price = Index price + Basis of moving average […] Moving average of [(Best ask of contract + Best bid of contract) / 2 - Index price] » ; guide X-Perps : « OKX uses the mark price as a reference to prevent liquidation due to a single abnormal transaction » | https://www.okx.com/en-eu/help/ii-mark-price-and-last-price (màj 2026-08-11) ; https://www.okx.com/en-eu/help/x-perps-guide (màj 2026-10-20) |
| Fonds de sécurité | API `GET /api/v5/public/insurance-fund` (`instType FUTURES`, `instFamily` requis ; types `liquidation_balance_deposit`, `bankruptcy_loss`, `amt` quotidien ~08:00 UTC) ; canal `adl-warning` | docs-v5 |
| ADL | « Automatic-deleveraging (ADL) is the final liquidation process deployed to protect the OKX security funds when a fund isn't able to further takeover additional bankrupted positions. […] The ADL mechanism applies to perpetual futures, expiry futures, and options trading positions. » ; déclencheurs : « When the present value of an applicable security fund drops significantly lower than a certain preset threshold » (+ pre-market : volume d'ordres de liquidation) ; « Positions in the ADL queue are ranked based on the leverage PnL%. […] positions with the highest positive leverage PnL% will be offsetted first » ; « In normal circumstances, deleveraged positions are forcibly closed at the mark price at the time of matching. However, if the applicable security fund is nearly depleted, the deleveraged positions could be closed at bankruptcy price. » ; « Trading fees are not charged on deleveraged positions, while liquidation fees apply to the liquidated orders. » ; « Other than the above, no warnings are issued prior to ADL occurring. » ; indicateur 1–5 barres ; API `positions.adl` « Range: 0–5 […] 5 = highest priority » | https://www.okx.com/en-eu/help/iv-introduction-to-auto-deleveraging-adl (« Published on Dec 16, 2020 Updated on Aug 11, 2026 ») ; docs-v5 |
| Clauses contractuelles OEM (X-Perps crypto) | « If the value of the available collateral, net of the applicable discounts, is less than 30% of the value of the Contract, OEM will issue the counterparty with a request to post additional collateral in their trading account (a "Margin Call"). » ; liquidation « if the counterparty has failed to properly collateralise its Contract following the receipt of a Margin Call by OEM and the equity value of the Contract has fallen to 100% of the collateral value, net of applicable discounts » ; « The Funding Rate is settled every 4 or 8 hours, depending on the Contract, and at expiry or liquidation of the Contract. » ; « Negative Balance Protection. Upon liquidation of the counterparty collateral, OEM shall forfeit any negative balance » | https://www.okx.com/en-eu/help/x-perps-contract-specifications (section crypto « Published on Apr 13, 2026 » ; section actions « Last updated: 12 October 2026 ») |
| Funding | « every 8 hours (00:00, 08:00, and 16:00 UTC) by default, unless specified otherwise (i.e. every 1, 2 or 4 hours) » ; « The actual fee assessment may take up to a minute. » ; formule avec diviseur 8/N, N ∈ {1, 2, 4, 8}, intérêt 0,01 % ; escalade automatique au plafond, retour si 12 h dans ±0,20 % ; « Automatic frequency adjustment applies only to crypto perpetual contracts » (X-Perps non nommés → application **UNKNOWN**) | https://www.okx.com/en-eu/help/perps-funding-fee-mechanism (màj 2026-08-27) |

Le ratio de maintenance, le prix de faillite et le prix « entrusted » ne sont pas publiés par instrument ; le flux `liquidation-orders`
donne `bkPx` (« Liquidation mark price. The price of the transaction with the system's liquidation account ») et `bkLoss`.

### 4.2 Hyperliquid (perpétuels on-chain)

Source : https://hyperliquid.gitbook.io/hyperliquid-docs/trading/liquidations ; https://hyperliquid.gitbook.io/hyperliquid-docs/hypercore/vaults/protocol-vaults ;
https://hyperliquid.gitbook.io/hyperliquid-docs/trading/margin-tiers ; https://hyperliquid.gitbook.io/hyperliquid-docs/trading/funding.

- Déclencheur et seuil : « A liquidation event occurs when a trader's positions move against them to the point where the account
  equity falls below the maintenance margin. The maintenance margin is half of the initial margin at max leverage, which varies from
  3-40x. In other words, the maintenance margin is between 1.25% (for 40x max leverage assets) and 16.7% (for 3x max leverage assets) ».
- Exécution sur le carnet : « the positions are first attempted to be entirely closed by sending market orders to the book. The
  orders are for the full size of the position, and may be fully or partially closed. If the positions are entirely or partially
  closed such that the maintenance margin requirements are met, any remaining collateral remains with the trader. »
- Backstop : « If the account equity drops below 2/3 of the maintenance margin without successful liquidation through the book, a
  backstop liquidation happens through the liquidator vault. » ; « During backstop liquidation, the maintenance margin is not returned
  to the user. » ; « Backstop liquidations on Hyperliquid are democratized through the liquidator vault, which is a component
  strategy of HLP. […] the pnl stream from liquidations go entirely to the community through HLP. »
- Partielles : « For liquidatable positions larger than 100k USDC […], only 20% of the position will be sent as a market liquidation
  order to the book. After a block where any position of a user is partially liquidated, there is a cooldown period of 30 seconds. »
- Frais et prix : « Unlike CEXs there is no clearance fee on liquidations. » ; « Liquidations use the mark price, which combines
  external CEX prices with Hyperliquid's book state. » ; aucun ADL mentionné.
- HLP : « Hyperliquidity Provider (HLP) is a protocol vault that provides liquidity to Hyperliquid through multiple market making
  strategies, performs liquidations, supplies USDC in Earn, and accrues a portion of trading fees. »
- Funding : « paid every hour » ; intérêt « 0.01% every 8 hours » ; « Funding on Hyperliquid is capped at 4%/hour. » ; paliers de
  marge BTC « 0-150M: 40 », ETH « 0-100M: 25 », SOL « 0-70M: 20 » (mainnet).

### 4.3 Déclaration de transférabilité M9 (développement HL → confirmation OKX X-Perps, G4)

**Ce qui se transfère (hypothèse de mécanisme, pas de magnitude).**
1. L'existence d'un **flux forcé non informationnel** : dans les deux cas la liquidation est déclenchée par le mark price (indice +
   base / prix CEX + carnet) et non par une décision du porteur ; le liquidé ne choisit ni l'instant ni le prix.
2. Le flux **frappe le carnet** et paie la liquidité : HL « sending market orders to the book » ; OKX met la position « into the market
   […] at the entrusted price » et facture « liquidation taker fee ». Le fournisseur de l'autre côté est un maker dans les deux cas.
3. La **forme de l'event study** (instant du pic, fenêtres 5/15/60 min, contrôle continu « retour ~ intensité », clustering par
   horodatage) et la **direction attendue** (ventes forcées de longs ⇒ fournisseur acheteur) sont indépendantes de la place.
4. La **fragmentation des grosses positions** (HL 20 % + cooldown 30 s ; OKX « partial liquidation » par paliers) : dans les deux cas
   une cascade est une séquence d'ordres, pas un bloc — l'unité d'événement doit être une fenêtre, pas un print.

**Ce qui ne se transfère pas (à déclarer avant tout gel).**
1. **Moteur.** HL : exécution déterministe, publique, par ordres market pour toute la taille, backstop par un vault (prise de
   position hors carnet) sous 2/3 de la MM, pas d'ADL, pas de frais de compensation. OKX : annulation d'ordres d'abord, reprise
   par le moteur à un « entrusted price » calculé « based on current market depth, basis, and mark price » (ce n'est pas un ordre
   market), paliers, fonds de sécurité, ADL au mark classé par leverage PnL % (compensation hors carnet), frais taker + frais de
   compensation. L'empreinte prix d'une liquidation OKX de même notionnel est donc **structurellement différente** (plus lente, plus
   « passive ») — la magnitude HL ne vaut pas sur OKX.
2. **Donnée.** HL : chaque fill liquidé porte `liquidation {liquidatedUser, markPx, method}` et l'archive est complète par
   construction ; OKX : flux « doesn't represent the total number of liquidations », « not necessarily in chronological order », sans
   identifiant d'utilisateur, sans historique (REST retiré en 2023), avec `bkPx`/`bkLoss`/`sz`. Un seuil absolu calibré sur HL
   (notionnel liquidé par fenêtre) ne peut pas être reporté : la base OKX observée est un **sous-échantillon de ratio inconnu**.
3. **Profondeur et participants.** HL : carnets profonds, HLP teneur de marché protocolaire, levier jusqu'à 40× (BTC) ; OKX Europe
   X-Perps : place EEE retail, « Up to 10x » (page des spécifications), ordres RPI/ELP côté OKX, programme de teneurs de marché
   **UNKNOWN**. Plus de levier ⇒ plus de liquidations par unité de mouvement ; moins de profondeur ⇒ plus d'impact par unité
   liquidée : les deux effets vont en sens contraire et ne se compensent pas de façon connue.
4. **Distance de liquidation.** HL MM = ½ IM au levier max (1,25 % BTC) ; OKX paliers propres (valeurs de `position-tiers`, non
   lues ici ; le rapport structurel §4.2 cite mmr 0,65 % BTC/ETH X-Perp) et clause OEM d'appel de marge à 30 % du collatéral.
5. **Funding.** HL : horaire, plafond 4 %/h, intérêt 0,01 %/8 h ; OKX : 8 h par défaut (4 h sur certains X-Perps), plafonds par
   contrat (±0,375 % BTC/ETH ; ±1 % sur de nouveaux tokens d'après `H1-XPERP-MARKET-ACCESS.md`), escalade automatique 2 h/1 h en stress
   (application aux X-Perps **UNKNOWN**). Le coût de funding d'une position tenue 60 min après une cascade n'est pas le même.
6. **Instrument.** HL perpétuel ; X-Perp = futures à 5 ans avec base, « price range adjustment » dynamique et bandes
   `initPxLmtPct/floatPxLmtPct/maxPxLmtPct` qui peuvent bloquer un ordre au repos loin du mark.
7. **Clientèle et horloge.** HL 24/7 crypto-native mondial ; OKX Europe EEE : structure horaire des liquidations **UNKNOWN**.

**Conséquence pour le pré-enregistrement G4.** Le Gate 1 HL ne peut tester que l'**existence et le signe** du retour post-cascade
(et son profil temporel), avec une porte formulée en unités sans dimension (retour en pb rapporté à la volatilité locale, et non
seuil absolu reporté). Un PASS HL n'autorise qu'une capture OKX **prospective** dont le détecteur est pré-déclaré sur la base
observable OKX (`Σ sz × ctVal × bkPx` par fenêtre ; seuil **relatif à la paire** — p99,5 glissant, k × médiane des fenêtres non
nulles 7 j, x % du notionnel 24 h, plancher de bruit absolu — recalculé sur la base OKX, instant = dernier fill liquidé `ts` :
`LP-G4` §3.3 révisé après la relecture quant B2/M4) et dont le ratio de couverture est inconnu et déclaré comme tel ; aucun chiffre HL (fréquence, magnitude, taux de fill) n'entre dans la porte OKX.

---

## 5. Marge des ordres au repos et mode de position (X-Perps)

### 5.1 Ce que la documentation écrit

| Point | Texte | Source |
|---|---|---|
| Marge gelée par les ordres (API) | `GET /api/v5/account/balance` : par devise `ordFrozen` « Margin frozen for open orders — Applicable to Spot mode/Futures mode/Multi-currency margin » ; `frozenBal` « Frozen balance of currency » ; au niveau compte `ordFroz` « Cross margin frozen for pending orders in USD — Only applicable to Spot mode/Multi-currency margin/Portfolio margin » ; `imr` « Initial Margin Requirement (IMR) in USD: equity locked across all open cross-margin positions. The sum of initial margins of all open positions **and pending orders** under cross-margin mode. Formula per position: position size × markPx × initial margin rate (= 1/lever). » ; `mmr` « The sum of maintenance margins of all open positions and pending orders under cross-margin mode. When adjEq ≤ mmr (equivalently, mgnRatio ≤ 1.0), the system begins forced liquidation of positions. » | docs-v5 § Trading Account → Get balance |
| Formules (cross) | « Margin requirement for open orders and positions — One-way mode margin requirement — Long position: Max ((Position notional value + Buy order value), (Sell order value – Position notional value)) / Leverage — Short position: Max ((Buy order value – Position notional value), (Position notional value + Sell order value)) / Leverage — Hedge mode margin requirement = Max（Long position notional value + Open buy order value，Short position notional value + Open sell order value）/ Leverage » | https://www.okx.com/en-eu/help/futures-margin-calculation-rules (« Published on Jun 22, 2020 Updated on Oct 8, 2026 », avertissement « This information may not apply to all customers ») §2 |
| Formules (isolé) | marge initiale par position seulement ; le ratio de maintenance soustrait « Amount required for open orders in isolated margin mode – Order fees » | idem §2, §4 |
| Perte d'ordre | « when your buy order price is higher than the mark price or your sell order price is lower than the mark price, an unrealized loss will occur if the order is executed. […] OKX includes order loss as a part of the cost required to open a position » ; « Buy order loss: Abs (Contract size × \|Number of contracts\| × Multiplier × Min [0, (Mark price – Order price)]) » | idem §7 |
| Selected Margin (X-Perps) | « Selected Margin is one of two margin modes available when trading X-Perps on OKX; the other being Isolated Margin. […] your collectively selected assets act as a shared pool of collateral supporting all your open positions. » ; « Assets supporting an open position aren't available to open another one, so your usable balance can be well below your total holdings. » ; « a haircut applies to each asset […] Haircuts are tiered and can change » ; aucune phrase sur les **ordres** ouverts | https://www.okx.com/en-eu/help/whats-selected-margin-eea (« Published on Apr 15, 2026 Updated on Sep 3, 2026 ») |
| Lecture seule disponible | `GET /api/v5/account/max-avail-size` (`instId`, `tdMode` « cross isolated cash spot_isolated », `ccy`) ; `GET /api/v5/account/balance` | docs-v5 |
| Mode de position (API) | `GET /api/v5/account/config` → `posMode` « long_short_mode: long/short, only applicable to FUTURES/SWAP — net_mode: net » ; `acctLv` « 1: Spot mode 2: Futures mode 3: Multi-currency margin 4: Portfolio margin » ; `perm` « read_only: Read trade: Trade withdraw: Withdraw » ; `level` (palier de frais) ; `POST /api/v5/account/set-position-mode` : « Portfolio margin mode: FUTURES and SWAP only support net mode » ; Place order `posSide` : « The default is net in the net mode. It is required in the long/short mode, and can only be long or short. Only applicable to FUTURES/SWAP. […] Omitting it for FUTURES/SWAP in long/short mode returns error 51000. » ; erreur « The destination account position mode (net/longShort) is not matched with the posSide field » | docs-v5 |
| Mode de position (produit) | « In One-way mode, users can only hold positions in one direction under the same margin mode and instrument. […] In Hedge mode, users can hold both long and short positions under the same margin mode and instrument at the same time. » | guide X-Perps (màj 2026-10-20) |
| Changement de mode | « Activating X-Perps or switching your account mode can be blocked while your account still holds activity tied to its current setup. The most common examples are open positions, open orders that haven't been filled, and trading bots that are still running » | https://www.okx.com/en-eu/help/okx-x-perps-eea-regional-availability-eligibility (màj 2026-09-04) |
| Devise de règlement USD-M | « You can't change the settlement currency if you have open orders or positions in USD-margined futures. If you haven't made a selection, USD will be used as the settlement currency by default. » ; quota sans intérêt 20 000 USD ; équité négative USD/USDG convertie en USDC ; guide EEE des perpétuels : « in certain jurisdictions, USD may not be available as a settlement currency » | https://www.okx.com/en-eu/help/faq-on-usd-margined-futures-contracts (màj 2026-09-02) ; https://www.okx.com/en-eu/help/i-perpetual-swaps (màj 2026-08-27) |
| Frais | `groupId` « 8: XPERP group two 10: XPERP RWA group two » + `GET /api/v5/account/trade-fee` ; interface du compte 2026-10-15 : 0,02 % / 0,05 % (`H1-XPERP-ACCOUNT-CHECK.md`) | docs-v5 ; dépôt |

### 5.2 Ce que seul le compte peut confirmer (lecture seule, aucun ordre)

1. La **règle de gel exacte** d'un ordre au repos X-Perps en Selected Margin et en Isolated (IMR = notionnel / levier ? perte
   d'ordre incluse ? décote de l'actif de marge appliquée ?) : les formules publiées visent les contrats USDT/crypto-margined et
   portent l'avertissement « may not apply to all customers ». Lecture possible : `GET /api/v5/account/balance` (`ordFrozen`,
   `availEq`, `imr`) et `GET /api/v5/account/max-avail-size` sur le compte, par le propriétaire.
2. `posMode` du compte (`GET /api/v5/account/config`), `acctLv`, `perm` de la clé (créer une clé **Read** seulement), `level`.
3. Devise USD-M du sous-compte (USD par défaut, USDC vu à l'interface le 2026-10-15) et la liste des actifs de Selected Margin avec
   leurs décotes (`https://www.okx.com/en-eu/trade-market/discountrate`, page dynamique non lue) — fait qui sépare K1(a) de K1(c).
4. Paliers effectifs (`GET /api/v5/public/position-tiers?instType=FUTURES&tdMode=isolated&instFamily=BTC-USD_UM`) et `lever`
   par instrument (`GET /api/v5/account/instruments?instType=FUTURES`).
5. Présence des X-Perps en démo (`wseeapap`/`eea.okx.com` + `x-simulated-trading: 1`).

---

## 6. Ce que cela change pour P3, G2, G4, G5, G1 (sans ordre, sans capture aujourd'hui)

- **P3 (comptage)** : spécifiable intégralement : hôte `wss://wseea.okx.com/ws/v5/public` (forme documentée, sans port) puis
  `wss://ws.okx.com/ws/v5/public` ; canaux `trades`, `books5`, `bbo-tbt`, `mark-price`, `funding-rate`, `open-interest` par `instId`,
  `liquidation-orders` et `adl-warning` par `instType FUTURES` ; une connexion, paquets ≤ 64 Ko, ≤ 480 (un)subscribe/h ; bornes de
  débit attendues (documentées) : `books5` ≤ 10/s, `bbo-tbt` ≤ 100/s, `mark-price` ≤ 5/s, `open-interest` 1/3 s, `funding-rate`
  1/30–90 s, `trades` événementiel. Suites données après les relectures du 2026-10-20 : alias EEE aligné sur la forme sans port
  (CTO m1, **fait** dans `xperp_capture.py`) ; `bbo-tbt`, `trades.source`, nombre d'ordres de `books5` et `books-rpi` au comptage
  **restent à ajouter** au collecteur (`LP-G2` P-G2-4 (B) v–vi, P-G2-9). **Budget disque** (demandé par la relecture CTO §4.3) :
  ≈ 170 Mo/jour/instrument de `books5` brut à 10 instantanés/s (calcul d'audit a2 §3.1, ≈ 864 k lignes × ~200 o) ; 40 instruments ×
  150 jours ≈ 1 To brut hors `bbo-tbt` (jusqu'à 10 × plus de lignes à 1 niveau : UNKNOWN) ; taux de compression `savez_compressed`
  UNKNOWN (ordre de grandeur plausible 5–10 ×) ; segments de 90 s avant finalisation ; à **mesurer au comptage P3** et à inscrire au
  coût fixe de la porte économique (`LP-PROGRAMME-GATES.md` §1.2).
- **G2** : `bbo-tbt` (10 ms, tous paliers) est la bonne source pour touch/through et le mark-out m_0 ; `books5` (100 ms, nombre
  d'ordres par niveau) pour le modèle de file. L'asymétrie « 1 niveau à 10 ms / 5 niveaux à 100 ms » doit être déclarée dans la règle
  de fill.
- **G4** : aucun historique OKX ; flux partiel et non ordonné ⇒ détecteur prospectif seulement, seuil **relatif à la paire**
  recalculé sur la base OKX et instant = dernier fill liquidé (`LP-G4` §3.3 révisé), ratio de couverture inconnu ; Gate 1
  HL conditionné à (i) décision de licence/coût (requester-pays, conditions non lues), (ii) fichier de schéma daté, (iii) déclaration
  M9 §4.3 dans le pré-enregistrement.
- **G5** : `funding-rate` donne `fundingTime`, `nextFundingTime`, `settState`, `settFundingRate`, `premium` ⇒ instants et signe
  pré-déclarables sans donnée externe ; `economic-calendar` est VIP1+ (inaccessible) ; la photo « may take up to a minute ».
- **G1 / K1(c)** : la marge gelée des ordres au repos reste **UNKNOWN** pour les X-Perps en Selected Margin ; la lecture
  « portefeuille 500 USDT » du simulateur doit rester une borne (IMR = notionnel / levier) tant que le compte n'a pas répondu.
- **Historique de développement** (G5, filtres de G1) : `history-candles` 1 min « from recent years » (300/page), mark et index
  « from recent years » (100/page), funding 3 mois (400/page), trades 3 mois ; fichiers `market-data-history` (docs globale
  seulement, T+2/T+3, « backfill in progress ») — tous soumis à §9.4 et à la décision P2 ; aucun n'a été appelé.

---

## 7. UNKNOWN consolidés

Hôte WebSocket servant les X-Perps (`wseea` vs `ws`) ; acceptation du port 8443 par `wseea` (sans objet pour le collecteur depuis
CTO m1) ; accès au WebSocket public global `ws.okx.com` depuis un compte EEE (question P2 n° 9, §2.3) ; portée exacte du budget de
480 (un)subscribe/h (par connexion ou par IP) ; taux de compression des segments `books5`/`bbo-tbt` ; couverture et cadence réelles de
`trades`/`books5`/`bbo-tbt`/`mark-price`/`open-interest` pour `instType FUTURES` X-Perps ; part des X-Perps dans `liquidation-orders`
et ratio de couverture du flux ; disponibilité de `market-data-history` sur `eea.okx.com` et format des fichiers carnet ; profondeur
réelle de `history-candles` / `history-mark-price-candles` par X-Perp ; couverture FUTURES de `rubik/stat/contracts/open-interest-history` ;
`ctVal` BTC (0,0001 vs 0,01) ; application de l'escalade automatique du funding aux X-Perps ; règle de gel de marge des ordres au repos en
Selected Margin / Isolated X-Perps ; `posMode`, `acctLv`, `perm`, `level`, devise USD-M, actifs et décotes de Selected Margin du compte ;
X-Perps en démo ; programme de teneurs de marché OKX Europe ; Hyperliquid : couverture temporelle des buckets, date d'apparition de
`liquidation`/`method` dans `node_fills`, schéma et cadence des fichiers `l2Book`, compression de `node_fills_by_block`, présence des
liquidations dans `misc_events_by_block`, conditions d'utilisation et licence des données ; coût AWS requester-pays ; qualification
juridique de « personal, non-commercial trading » pour une capture de recherche (décision propriétaire, pas un fait).

---

## 8. Traitement de la revue (2026-10-20/21)

Relectures : `tradingV3-private/research/20261020T1430Z-lp-programme/review/quant.md` et `review/cto.md`. Le desk n'est pas un
protocole : il n'a ni critère ni verdict ; les constats qui le citent le font comme **source** (hôtes, canaux, RPI, funding,
`liquidation-orders`, `economic-calendar`, licence, M9) et sont traités dans `LP-G2`, `LP-G4`, `LP-PROGRAMME-GATES.md` et
`lp-executor-architecture.md` (sections « Traitement de la revue » de chacun). Ce qui change **ici** :

| Constat | Traitement |
|---|---|
| quant **B4** — questions P2 posées sur « 2–8 semaines, 20–30 X-Perps » (§2.3 Q1–Q2 ; table de réconciliation §4 de la relecture : « tous sous-estiment ») | **Appliqué** : Q1–Q2 réécrites sur l'échelle réelle (capture continue 2026-11-02 → ≤ 2027-03-31, ≤ 40 X-Perps + 2 swaps, cadences documentées) ; **Q9** ajoutée (hôte global `ws.okx.com` depuis un compte EEE) ; §0.4 et §1.2 recalculés à cette échelle |
| CTO **m1** — alias EEE avec port 8443 dans le collecteur (§0.1, §1.1) | **Appliqué** : fait constaté — alias aligné sur la forme documentée par la passe `a5-corrections-cto` (vérifié le 2026-10-21) ; `OkxConfig.php` (WS privé démo) inchangé ; acceptation du port : UNKNOWN, sans objet pour le collecteur |
| CTO **§4.3** — budget disque « à écrire dans le desk de données » | **Appliqué** : §6 (P3) — ordre de grandeur, UNKNOWN de compression, mesure à P3, coût fixe |
| quant **B2 / M4** — porte G4 sans dimension, seuil non reportable, instant du pic | **Appliqué** : §4.3 « Conséquence » et §6 (G4) alignés sur `LP-G4` §3.3 révisé (seuil relatif à la paire, plancher de bruit, instant = dernier fill liquidé). **Réponse à la revue** : le desk maintient que la base OKX est un sous-échantillon de ratio inconnu — les **formules** sont communes, les **valeurs** sont recalculées sur OKX |
| quant **m12** — `market-data-history` comme source historique optionnelle (§0.5, §1.4) | Rien à changer ici (le fait était déjà écrit « non appelé, docs globale seule ») ; l'option est portée dans `LP-PROGRAMME-GATES.md` §8 P2 et décision 15 |
| quant **M8** — raccourcissement du funding X-Perps UNKNOWN ; `economic-calendar` VIP1+ | Rien à changer : le desk (§1.3, §4.1, §7) était la source de la correction ; appliquée dans `LP-PROGRAMME-GATES.md` §5.1 et signalée pour le rapport théorie |
| quant **m1 / m2** — `liquidation-orders` et `funding-rate` pour FUTURES établis par le desk | Rien à changer : repris dans `LP-G4` §0.4, `LP-G2` P-G2-5, architecture §2.2 |
| quant **B5** — RPI (`trades.source`, `books-rpi`, `instruments.rpi`) | Rien à changer dans les faits (§1.3) ; exigences portées dans `LP-G2` §0.9, P-G2-4 (B) v, P-G2-9 ; §6 (P3) les liste comme restes |
| §1.2 — « une reconnexion par heure consomme ~127 du budget » | **Réponse à la revue** (constat propre à cette passe) : la portée du budget de 480/h (par connexion ou par IP) n'est pas écrite ; reformulé en UNKNOWN avec les deux lectures et une mesure à P3 |

---

## 9. Sources et empreintes

Toutes lues le 2026-10-20. HTML/markdown bruts et `SHA256SUMS` dans
`tradingV3-private/research/20261020T1430Z-lp-programme/a4-data-desk/raw/` ; textes extraits dans `…/a4-data-desk/txt/` ;
outil `…/a4-data-desk/tools/html2txt.py`.

| Source | URL | sha256 (HTML/MD brut) |
|---|---|---|
| OKX docs-v5 (globale) | https://www.okx.com/docs-v5/en/ | `4f64bdcdb7a52af2bd1a8e1e1e6c4beddaf049b78e01e93b6e614201e235cc13` |
| OKX docs-v5 (EEE) | https://my.okx.com/docs-v5/en/ | `f24eb43e4fb4475f4f60bbecafa3f893361ee94ad93f8efd939a007330b61994` |
| OKX changelog | https://www.okx.com/docs-v5/log_en/ | `a7a0b46a8de7cb65dcbf7fb2412e288001821f6e911be27eb729b571cd477f5a` |
| OKX API Agreement (EEE, 28 July 2026) | https://www.okx.com/en-eu/help/okx-api-agreement | `5049ec62c7b9223841627478c13425522a245232bf77931120d3ce22938d5bed` |
| Selected Margin (EEE) | https://www.okx.com/en-eu/help/whats-selected-margin-eea | `aa9bf8a0bb493d03169287c4b104a3b919e4724b8742e3f395d59c12441ba46d` |
| Guide X-Perps | https://www.okx.com/en-eu/help/x-perps-guide | `f4afef662c1d2f9c20790b480ca80b915f244113a6ef5dadc601055fec326289` |
| Spécifications X-Perps | https://www.okx.com/en-eu/help/x-perps-contract-specifications | `0005d0ef7fb309cb001125cf81a4156b2d90da516e1baf4c2fc71dd092842982` |
| System Liquidation Mechanism (EEE) | https://www.okx.com/en-eu/help/system-liquidation-mechanism-eea | `b8db6a51cbfb4ec53223748e61e5eeea69d39cfc278cb0c148809fa3bf6fcadd` |
| Tiered MMR rules (EEE) | https://www.okx.com/en-eu/help/tiered-maintenance-margin-ratio-rules-eea | `0af7d001ddfb6d82c56330629ea0d6552f1c43c58e48f11c747ad1383166fc80` |
| ADL (EEE) | https://www.okx.com/en-eu/help/iv-introduction-to-auto-deleveraging-adl | voir `SHA256SUMS` (`okx-adl-eea.html`) |
| Futures margin calculation rules (EEE) | https://www.okx.com/en-eu/help/futures-margin-calculation-rules | voir `SHA256SUMS` |
| Mark price and Last price (EEE) | https://www.okx.com/en-eu/help/ii-mark-price-and-last-price | voir `SHA256SUMS` |
| X-Perps regional availability (EEE) | https://www.okx.com/en-eu/help/okx-x-perps-eea-regional-availability-eligibility | voir `SHA256SUMS` |
| USD-margined futures FAQ (EEE) | https://www.okx.com/en-eu/help/faq-on-usd-margined-futures-contracts | voir `SHA256SUMS` |
| Perpetual futures guide (EEE) | https://www.okx.com/en-eu/help/i-perpetual-swaps | voir `SHA256SUMS` |
| Trading fee rules FAQ (EEE) | https://www.okx.com/en-eu/help/trading-fee-rules-faq-eea | `093ba751487eebab3e93adced69d3b23aac19b25e7b6cf54d896057285d7d65d` |
| Funding fee mechanism (EEE) | https://www.okx.com/en-eu/help/perps-funding-fee-mechanism | `ff1eae3a3542826535c27665b0c5362c356f31d5bc740a4cb1ca9da6e6cc26aa` |
| Hyperliquid historical data | https://hyperliquid.gitbook.io/hyperliquid-docs/historical-data.md | voir `SHA256SUMS` (`hl-historical-data.md`) |
| Hyperliquid liquidations | https://hyperliquid.gitbook.io/hyperliquid-docs/trading/liquidations.md | voir `SHA256SUMS` |
| Hyperliquid protocol vaults (HLP) | https://hyperliquid.gitbook.io/hyperliquid-docs/hypercore/vaults/protocol-vaults.md | voir `SHA256SUMS` |
| Hyperliquid WS subscriptions (types) | https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket/subscriptions.md | voir `SHA256SUMS` |
| Hyperliquid info endpoint | https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint.md | voir `SHA256SUMS` |
| Hyperliquid L1 data schemas | https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/nodes/l1-data-schemas.md | voir `SHA256SUMS` |
| Hyperliquid margin tiers / funding / rate limits | …/trading/margin-tiers, …/trading/funding.md, …/for-developers/api/rate-limits-and-user-limits.md | voir `SHA256SUMS` |
| Hyperliquid node README | https://raw.githubusercontent.com/hyperliquid-dex/node/main/README.md | voir `SHA256SUMS` |
| Hyperliquid terms (non lisibles) | https://hyperliquid.xyz/terms (403) ; https://app.hyperliquid.xyz/terms (JS seul) | — |
| Dépôt (lecture seule) | `docs/handbook/research/H1-XPERP-MARKET-ACCESS.md`, `H1-XPERP-ACCOUNT-CHECK.md`, `H1-XPERP-TECHNICAL-GAP.md` ; `python-orchestrator/scripts/research_lp/xperp_capture.py` ; `trading-app/src/Exchange/Okx/OkxConfig.php` | — |

Ce que ce document ne fait pas : aucune valeur de marché, aucun appel à un endpoint de marché ou de métadonnées d'instruments,
aucun accès S3, aucun git, aucun ordre, aucun drapeau ; aucun avis juridique.
