# Alternatives à Hyperliquid : DEX de perpétuels pour un résident français (2026-10-21)

> **Demande du propriétaire (2026-10-21)** : « Trouve un autre dex alors au lieu de hyperliquid ».
>
> **Nature.** Travail de bureau du 2026-10-21. Trois dossiers ont été rédigés puis relus par un vérificateur adversarial :
> carnets d'ordres, oracle et pool, juridique. Ils sont dans `tradingV3-private/research/20261021T-dex-alternatives/`, sous-dossiers
> `clob/`, `oracle/`, `legal/` et `verify/`, avec les copies des pages lues et leurs sha256. Le rédacteur de ce rapport a relu en
> plus quatre pages de documentation Kraken et Tardis (§8).
>
> **Ce qui n'a pas été fait.** Aucune série de prix, de funding, d'open interest, de volume ou de carnet n'a été lue, ni aucun
> tableau de bord de marché. Aucun portefeuille n'a été connecté, aucun compte ni aucune clé n'a été créé, aucun jeu de données n'a
> été téléchargé. Rien n'a été lu sous `20261013T100000Z-forward-okx-r1/`. Ni git ni Docker n'ont été utilisés. **Ce rapport ne
> contient aucun chiffre de marché**, et la liquidité de toutes les places est **UNKNOWN** : elle n'a pas été lue, par règle.
>
> **Ce n'est pas un avis juridique.** Ce qui est écrit dans un texte est séparé de ce qui est interprétation (§3). Une question que
> les textes ne règlent pas est marquée **non tranchée** : c'est à un juriste de la trancher.
>
> **Conventions.** M1 désigne la porte permanente de [RESEARCH-ELIGIBILITY-GATES.md](RESEARCH-ELIGIBILITY-GATES.md).
> D1 à D4 sont les décisions de [LP-OWNER-DECISIONS-2026-10-21.md](LP-OWNER-DECISIONS-2026-10-21.md). Le protocole G4 est
> [LP-G4-DRAFT-PREREGISTRATION.md](LP-G4-DRAFT-PREREGISTRATION.md), le desk de données
> [LP-DATA-DESK-2026-10-20.md](LP-DATA-DESK-2026-10-20.md). « pb » veut dire point de base. Une affirmation que le vérificateur a
> jugée FAUSSE est corrigée ; une affirmation NON_VERIFIABLE est écrite **UNKNOWN** (liste en §2.5).

---

## 1. La réponse en dix lignes

1. **Aucun DEX ne passe M1.** 25 DEX de perpétuels ont été examinés : 18 à carnet d'ordres, dont Vertex, fermé, et 7 à oracle ou
   pool. Aucun n'a d'agrément MiFID ni de passeport en France (registre MiFID et registre MiCA de l'ESMA, REGAFI, 2026-10-21).
2. **Le problème vient de la catégorie, pas d'Hyperliquid.** Un perpétuel est un instrument financier MiFID, et MiFID II n'exempte
   pas la finance décentralisée. Le régime pilote DLT exclut les dérivés. Il n'existe donc aucune place de perpétuels on-chain ou non
   dépositaire autorisée dans l'UE.
3. **Plusieurs DEX sont explicitement fermés.** Aster et ApeX sont sur la liste noire de l'AMF. Ostium exclut l'UE dans ses CGU.
   dYdX, Gains, Flash Trade et Extended excluent toute personne pour qui l'usage serait illégal ou exigerait une licence.
4. **En pratique, sur un DEX tu perdrais toutes les protections** : test d'adéquation, levier plafonné, protection contre le solde
   négatif, recours. Les textes visent le prestataire, pas l'utilisateur : savoir si tu peux légalement l'utiliser est une question
   pour un avocat.
5. **L'exécution reste sur OKX Europe X-Perps** (cible ; compte encore à vérifier). Kraken EEA puis Coinbase EEA restent les
   alternatives conditionnelles.
6. **Nouveaux candidats autorisés, tous centralisés**, à vérifier sans ordre ni clé : Robinhood Europe (qui route vers la plateforme
   MiFID de Bitstamp), Gemini EU, Backpack EU, One Trading.
7. **Données de G4 : le meilleur remplaçant DEX est dYdX v4.** Ses trades publics sont typés `LIQUIDATED` ou `DELEVERAGED` et
   gratuits. Son moteur envoie les liquidations au carnet à un prix calculé : c'est le moteur le plus proche du prix « entrusted »
   d'OKX.
8. **Ses limites** : la rétention de l'indexeur et la licence des données sont UNKNOWN. Il n'y a pas de carnet historique gratuit :
   il faut amender G4 §5.2 (le mid devient un proxy déclaré) avant le gel.
9. **Hors DEX, Kraken Futures pourrait être meilleur**, à enquêter d'abord sans rien acheter : archive Tardis depuis 2019 avec le
   carnet, tape typée `liquidation`, groupe déjà M1 CONDITIONAL. Le coût et la présence du champ `type` dans l'archive sont UNKNOWN.
   Hyperliquid reste un repli valide (D3, recherche seulement).
10. **Les pools de liquidité des DEX à oracle ne sont pas du scalping** : le déposant est contrepartie des traders et porte des
    pertes de queue documentées. Ils échouent aussi à M1. Les décisions attendues sont en §7.

---

## 2. Toutes les venues examinées

Légende M1 : **FAIL** = aucun prestataire autorisé ; **CONDITIONAL** = prestataire autorisé, compte et API à vérifier ;
**À VÉRIFIER** = prestataire autorisé repéré, produit, compte ou API non vérifiés. Verdicts : NON_RETENU, CONDITIONNEL, CONSENTI.
« Aucune trouvée » veut dire : aucune alerte sur les listes noires AMF du 28/09/2026 et du 08/10/2026, ni dans un sondage FCA (non
probant) ou dans l'alerte de la SEC des Philippines. Ce n'est pas une preuve d'absence.

Familles de moteur de liquidation (dossier `clob` §4) :
- **A** : le protocole envoie les ordres de liquidation au carnet, à un prix qu'il calcule. C'est le plus proche d'OKX.
- **B** : ordres agressifs au carnet, puis reprise par un pool protocolaire. Hyperliquid est dans cette famille.
- **C** : reprise de la position par un fonds d'assurance, qui la déboucle ensuite.
- **D** : transfert à des liquidateurs tiers au prix de l'oracle, hors carnet.
- **Oracle** : la position est fermée au prix de l'oracle contre le pool. Aucun carnet n'est impliqué.

### 2.1 DEX à carnet d'ordres (et Hyperliquid en référence)

| Venue | Marché | Chaîne | Entité, autorisation UE | CGU France / UE (date) | Alertes | M1 | Frais, minimums | Historique de liquidations | Exécution | Données G4 |
|---|---|---|---|---|---|---|---|---|---|---|
| **Hyperliquid** (référence) | carnet on-chain ; famille B (ordres market au carnet, backstop par le vault HLP) | Hyperliquid L1 | « Neither the Interface nor Hyperliquid are licensed, approved, authorized … in any jurisdiction » ; absent du registre MiCA de l'ESMA et de la liste blanche AMF | 15/06/2026 : exclut États-Unis, Ontario, sanctions ; France et UE non nommées ; conformité au droit local à la charge de l'utilisateur (§1.9) | FCA, « unauthorised firm », 21/05/2026 | FAIL | 1,5 pb maker / 4,5 pb taker ; minimum 10 USD | archive S3 requester-pays ; marqueur `liquidation {markPx, method: market\|backstop}` par fill (format API) ; présence et date de début dans l'archive UNKNOWN ; carnets L2 archivés | NON_RETENU | **CONSENTI** (D3, recherche seulement, sous échantillon de schéma) |
| **dYdX v4** | carnet on-chain, appariement par les validateurs ; famille A | dYdX Chain (Cosmos SDK), USDC ponté | logiciel de dYdX Trading Inc. (droit de Californie, arbitrage JAMS à San Francisco) ; front-end exploité par la dYdX Operations subDAO ; aucune licence revendiquée ; 0 résultat ESMA MiFID, MiCA et REGAFI | 12/03/2026 : perpétuels exclus pour États-Unis, Canada, Royaume-Uni (Syrie, Myanmar en annexe) ; France et UE non nommées ; une « Restricted Person » inclut « Any other persons or entities whose use of dYdX Software is contrary to any applicable law » ; VPN interdit | SEC des Philippines, avril 2026 | FAIL | **UNKNOWN** (la page de frais citée redirige, valeur non vérifiée) ; minimum UNKNOWN | trades publics `GET /v4/trades/perpetualMarket/{ticker}`, `type` ∈ {LIMIT, LIQUIDATED, DELEVERAGED, TWAP_SUBORDER}, pagination par hauteur et par date, gratuit ; rétention de l'indexeur UNKNOWN ; reconstructible depuis un nœud ; aucun carnet historique gratuit ; Tardis payant depuis 2024-08-23 | NON_RETENU | **CONDITIONNEL** (premier DEX) |
| **Lighter** | carnet hors chaîne, preuves ZK ; famille B | L2 Ethereum | Elliot Technologies, Inc. (droit de Floride, JAMS à Miami) ; aucune licence revendiquée ; absent ESMA et REGAFI | 29/12/2025 : exclut États-Unis, Canada, Royaume-Uni, Chine, Corée du Nord, Russie, Ukraine, Cuba, Iran, Venezuela, Soudan, Biélorussie, Myanmar, Syrie ; France et UE non nommées ; pas de clause anti-scraping | aucune trouvée | FAIL | comptes Standard : 0 / 0 avec 300 ms de latence taker ; Premium : 0,4 / 2,8 pb ; minimum par marché UNKNOWN | `s3://lighter-historical-data/trade-daily/` en parquet quotidien, requester-pays, depuis le 2025-01-17 ; schéma du parquet (colonne `type`) UNKNOWN ; aucune licence ; accès S3 libre de la condition « 100 LIT » : **UNKNOWN** | NON_RETENU | **CONDITIONNEL** (deuxième ; même famille qu'Hyperliquid) |
| **Paradex** | carnet hors chaîne ; famille C | appchain Starknet | Paradex Corp. (droit anglais, SIAC) : « Paradex is not registered or licensed by any regulatory agency or authority » | 15/02/2026 : exclut US persons, Canadian persons, sanctions (UE comprise) ; France non nommée ; interdit « data mining, robots, scraping » | aucune trouvée | FAIL | retail 0 % ; Pro : 0,3 pb maker, 4,5 pb taker de base (plancher 1,75 pb) ; minimum UNKNOWN | `GET /v1/trades` avec `trade_type` ∈ {FILL, LIQUIDATION, RPI, UNWIND_TRANSFER, SETTLE_MARKET, BLOCK_TRADE} ; rétention et sens du print `LIQUIDATION` UNKNOWN ; reprise par le fonds d'assurance | NON_RETENU | CONDITIONNEL faible (clause anti-scraping écrite, bloquante) |
| **Injective** (Helix) | carnet on-chain ; famille A (ordre au marché, pire prix = faillite ou oracle) | Injective (Cosmos SDK) | Injective Labs ; aucune autorisation UE trouvée | UNKNOWN (page JavaScript) | aucune trouvée | FAIL | UNKNOWN | indexeur : `DerivativeTrade.is_liquidation`, requêtes par marché et par période ; rétention et licence UNKNOWN | NON_RETENU | CONDITIONNEL faible |
| **Velocity** (ex-Drift) | hybride : carnet décentralisé, enchères JIT, vAMM ; famille D | Solana | exploitant UNKNOWN ; fork de Drift v2, « no onchain state carries over », après l'exploit du 01/04/2026 (285 à 295 M USD selon la source) ; date du renommage **UNKNOWN** | UNKNOWN (pages en 404 ou en JavaScript) | aucune trouvée | FAIL | UNKNOWN | Data API sans clé : trades par marché et par jour depuis 2022, en CSV ; granularité des archives de liquidations **UNKNOWN** ; continuité avec Drift UNKNOWN ; licence non écrite ; liquidation = transfert au prix de l'oracle | NON_RETENU | NON_RETENU |
| **Bluefin** | carnet hors chaîne ; famille D | Sui | Bluefin Labs Inc. : « not registered or licensed by the U.S. CFTC, the U.S. SEC or any other financial regulatory authority » | 21/10/2024 : exclut États-Unis, Canada, sanctions ; Royaume-Uni réservé aux « investment professionals » ; VPN interdit ; France non nommée | aucune trouvée | FAIL | UNKNOWN | `/exchange/trades`, `tradeType` ∈ {ORDER, LIQUIDATION, DELEVERAGE} ; profondeur et licence UNKNOWN | NON_RETENU | NON_RETENU |
| **Orderly** (WOOFi Pro et autres) | carnet partagé, front-ends tiers ; famille D | Orderly L2, coffres multi-chaînes | Orderly Network Ltd (infrastructure) ; chaque front-end porte seul les « licensing requirements » | conditions supplémentaires du 01/06/2025 (filtrage par le front-end : sanctions ONU, Royaume-Uni, UE) ; conditions générales en 404 | SEC des Philippines, avril 2026 | FAIL | selon le front-end : UNKNOWN | liquidations publiques « recent » ; profondeur UNKNOWN | NON_RETENU | NON_RETENU |
| **Vertex**, puis **Nado** | carnet ; Nado : famille D (liquidateurs, vault NLP prioritaire) | Vertex : Arbitrum ; Nado : Ink | Vertex : fin annoncée le 08/07/2025, date effective UNKNOWN ; Nado : entité UNKNOWN ; « backed by the team that brought you Kraken » ne vaut pas agrément de Payward Europe | Nado, page non datée : blocage total de pays sanctionnés et de Panama, États-Unis et Canada en « view-only » ; France non nommée | aucune trouvée | FAIL | UNKNOWN | Nado : archive `matches_and_liquidations` (≤ 500 par page) ; accès public et rétention UNKNOWN | NON_RETENU | NON_RETENU |
| **Aster** | hybride : carnet « Pro » (un IOC, puis fonds d'assurance) et mode 1001x à oracle (pool ALP) | BNB Chain, Arbitrum | « Aster », droit de Hong Kong, HKIAC ; aucune licence | 25/02/2026 : exclut États-Unis, Canada, Royaume-Uni, Chine, Corée du Nord, Russie, Ukraine, Cuba, Iran, Venezuela, Syrie ; France non nommée ; bots interdits | **AMF, liste noire « produits dérivés sur crypto-actifs » : www.asterdex.com, 27/02/2026** | FAIL | UNKNOWN | aucun historique public (flux `forceOrder` : un instantané par 1 000 ms) | NON_RETENU | NON_RETENU ; **exclusion de tout usage recommandée** |
| **ApeX Omni** | carnet | UNKNOWN | UNKNOWN (écosystème Web3 de Bybit selon la presse) | UNKNOWN : site non lu, le DNS de la box renvoie `::1`, aucun contournement | **AMF, liste noire : www.apex.exchange, 22/09/2025** ; `apex-trade.capital`, inscrit le même jour : lien avec ApeX **UNKNOWN** | FAIL | UNKNOWN | UNKNOWN | NON_RETENU | NON_RETENU ; **exclusion de tout usage recommandée** |
| **edgeX** | carnet ; moteur UNKNOWN (une phrase de doc) | StarkEx / Ethereum | edgeX Trading Inc. (Californie) : « edgeX is not registered or licensed by any regulatory agency or authority » | non datées : exclut États-Unis, Canada, sanctions ; robots et scrapers interdits | aucune trouvée | FAIL | UNKNOWN | aucune tape de trades publique documentée | NON_RETENU | NON_RETENU |
| **Aevo** | carnet ; famille B (limites toutes les 2 s pendant 30 s, puis fonds d'assurance, puis ADL) | rollup OP Stack | Ribbon Labs Foundation (avertissement du jeton) | UNKNOWN (page JavaScript, `/terms` en 404) | SEC des Philippines, avril 2026 | FAIL | UNKNOWN | `trade-history` sans marqueur de liquidation | NON_RETENU | NON_RETENU |
| **Extended** (ex-X10) | carnet et RFQ ; famille A (FOK de 20 % au prix de faillite) | Starknet, migration vers Arc | X10 Ltd : « not registered or licensed by any regulatory agency or authority » | 08/10/2026 : exclut Royaume-Uni, États-Unis, Canada, Hong Kong et une liste de pays (page Restricted Countries) ; UE non nommée ; exclut « any other individual or entity whose access … would be contrary to any applicable law or regulatory requirement » | aucune trouvée | FAIL | UNKNOWN | trades « latest » seulement (`LIQUIDATION`, `DELEVERAGE`) ; pas d'historique | NON_RETENU | NON_RETENU |
| **Pacifica** | carnet ; famille B (copie du moteur d'Hyperliquid) | Solana | UNKNOWN | UNKNOWN | SEC des Philippines, avril 2026 | FAIL | UNKNOWN | trades récents seulement (`cause` ∈ {market_liquidation, backstop_liquidation…}) | NON_RETENU | NON_RETENU |
| **GRVT** | carnet hybride ; famille C (reprise totale par le fonds d'assurance) | ZK | licence Class M « modified » DABA de la Bermuda Monetary Authority (05/12/2024, selon le communiqué et le cabinet Walkers ; registre BMA non lu) : **pas UE** | page Restricted Jurisdictions mise à jour le 03/08/2026 : France non nommée | aucune trouvée | FAIL | UNKNOWN | UNKNOWN | NON_RETENU | NON_RETENU |
| **Ethereal** | carnet ; famille C | appchain liée à Ethena | UNKNOWN ; 0 au registre MiFID | UNKNOWN | aucune sur les listes AMF ; autres UNKNOWN | FAIL | UNKNOWN | UNKNOWN | NON_RETENU | NON_RETENU |
| **Hibachi** | carnet ; famille B (ordres au marché partiels) | UNKNOWN | UNKNOWN ; 0 au registre MiFID | UNKNOWN | aucune sur les listes AMF ; autres UNKNOWN | FAIL | UNKNOWN | UNKNOWN | NON_RETENU | NON_RETENU |

### 2.2 DEX à oracle ou pool

| Venue | Marché | Chaîne | Entité, autorisation UE | CGU France / UE (date) | Alertes | M1 | Frais, minimums | Historique de liquidations | Exécution | Données G4 |
|---|---|---|---|---|---|---|---|---|---|---|
| **GMX v2** (pools GM / GLV) | oracle et pool ; keepers ; impact simulé, pas de carnet | Arbitrum, Avalanche, MegaETH | « GMX.io », sans entité ; droit des Bahamas, CIMA à Madrid ; « GMX.io is not registered or licensed by any regulatory agency or authority » | 20/08/2026 : embargos US, Royaume-Uni et UE ; « no U.S. Person may enter into Perpetual Contracts » ; VPN interdit ; France et UE non exclues | aucune trouvée | FAIL | frais de liquidation 0,20 / 0,30 / 0,45 % de la taille ; minimums UNKNOWN | on-chain (`OrderType.Liquidation`) ; GraphQL Squid sans documentation du drapeau ni du début ; licence des données non écrite (code BUSL 1.1) | NON_RETENU | NON_RETENU |
| **Jupiter Perps** (pool JLP) | oracle et pool | Solana | Block Raccoon S.A. : « not offering any sort of securities product or regulated service » | 21/09/2024 (conditions de l'application) : exclut États-Unis, « Republic of China », Singapour, sanctions US, Royaume-Uni et UE ; VPN interdit ; France non nommée | aucune trouvée | FAIL | mint/burn JLP 0,20 % de base ; levier d'ouverture jusqu'à 250× ; minimums UNKNOWN | on-chain seulement, aucune archive documentée | NON_RETENU | NON_RETENU |
| **Synthetix / Kwenta** | héritage : oracle (Optimism, Base) ; depuis le 17/12/2025, Synthetix Exchange à carnet, liquidation par ordres au marché (20 % puis 30 s au-delà de 100 000 USDT) ; appariement hors chaîne : **UNKNOWN** | Ethereum ; Optimism et Base pour l'héritage | « SNX is not registered nor licensed with … the CFTC, SEC, or any other financial or banking regulator of any jurisdiction » | synthetix.io, 25/01/2022 : juridictions sanctionnées ; conditions d'`exchange.synthetix.io` et de Kwenta UNKNOWN | aucune trouvée | FAIL | frais de compensation 0,5 % (BTC, ETH) ou 1 % ; autres UNKNOWN | héritage : on-chain (sous-graphes UNKNOWN) ; Exchange : archive publique UNKNOWN, moins de 10 mois, lancement plafonné à 500 utilisateurs | NON_RETENU | NON_RETENU (à revoir si une archive apparaît) |
| **Gains Network** (gTrade, coffres gToken) | oracle et coffre ; « Gains has no resting order book » | Arbitrum, Base, Polygon | Ingenium Labs Foundation (Panama) : « not registered or qualified with or licensed by … any government agency or financial regulatory authority » | 01/11/2023 : États-Unis exclus « regardless of the user's IP address » ; VPN interdit ; exclut « any country where use … is illegal, prohibited, or requires a permit or license » | **SEC des Philippines, avril 2026** (corrigé, §2.5) | FAIL | UNKNOWN | API sur 24 à 72 h seulement | NON_RETENU | NON_RETENU |
| **Avantis**, devenu **Veranta** (coffre avUSDC) | oracle et coffre ; couverture annoncée chez des teneurs de marché | Base | Veranta Foundation (Caïmans) ; aucune licence ; date du renommage UNKNOWN | date non affichée (UNKNOWN) : exclut les « U.S. Person » et les personnes sanctionnées ; France non nommée ; conformité à la charge de l'utilisateur | aucune trouvée | FAIL | jusqu'à 250× sur BTC (doc) ; frais de retrait jusqu'à 2,5 % ; minimums UNKNOWN | aucune archive documentée | NON_RETENU | NON_RETENU |
| **Ostium** (coffre OLP) | oracle maison et coffre, couverture hors chaîne | Arbitrum | Ostium Foundation et Ostium Labs Co. (Caïmans) : « OSTIUM IS NOT AN EXCHANGE, TRUST COMPANY, LICENSED BROKER, DEALER… » | date non affichée : **UE exclue nommément** (« … THE UNITED KINGDOM, THE EUROPEAN UNION, THE PHILIPPINES », « THERE ARE NO EXCEPTIONS ») ; VPN interdit | **SEC des Philippines, avril 2026** (corrigé, §2.5) | FAIL | jusqu'à 200× ; minimums UNKNOWN | sous-graphe `getFillsByTime`, type d'ordre « Liquidation » ; profondeur et licence UNKNOWN | NON_RETENU | NON_RETENU |
| **Flash Trade** (pools FLP) | oracle et pool (« Pool-to-Peer ») | Solana | D5 Labs Inc (Panama) : « not registered or qualified with or licensed by … any government agency or financial regulatory authority » | 18/12/2023 : États-Unis interdits, VPN interdit, exclut « any country where use … requires a permit or license » | aucune trouvée | FAIL | levier de maintenance jusqu'à 1 000× (crypto) ; minimums UNKNOWN | aucune archive documentée | NON_RETENU | NON_RETENU |
| **Aster 1001x** (pool ALP) | voir la ligne Aster en §2.1 | — | — | — | liste noire AMF | FAIL | — | — | NON_RETENU | NON_RETENU |

Non ouverts, conclusion M1 identique par construction mais **non vérifiée** : MUX, HMX, Vela, Level, Perennial, Polynomial, Parcl,
Variational (RFQ), SynFutures, MYX, Bullet/Zeta, Vest, ainsi que les marchés HIP-3, qui sont des marchés Hyperliquid et appellent les
mêmes réserves.

### 2.3 Places centralisées autorisées (référence et candidats)

Codes REGAFI : A1 réception-transmission, A2 exécution, A3 compte propre, A8 exploitation d'un MTF, A9 d'un OTF ; C4 et C10 sont des
catégories de dérivés. **Un passeport couvre des catégories d'instruments, pas un produit précis** : il ne prouve pas que le produit
est offert aux particuliers français.

| Venue | Marché | Chaîne | Entité, autorisation UE | CGU France / UE (date) | Alertes | M1 | Frais, minimums | Historique de liquidations | Exécution | Données G4 |
|---|---|---|---|---|---|---|---|---|---|---|
| **OKX Europe X-Perps** | carnet central, dépositaire ; futures à 5 ans, funding 8 h | hors chaîne | OKX Europe Markets Ltd, entreprise d'investissement MiFID (MFSA) ; REGAFI : libre prestation en France depuis le 05/01/2021, A2/A3 sur C4 (2021) et C10 (09/03/2026) | CGU OEM du 02/10/2026 ; test d'adéquation obligatoire ; jusqu'à 10× ; solde négatif protégé | aucune | **CONDITIONAL** (compte non vérifié) | à partir de 2 / 5 pb ; lot minimal par contrat (`minSz`) | canal `liquidation-orders` sans historique REST ; Tardis archive le flux depuis 2023-03-30 (voir §2.4) | **CONDITIONNEL (cible)** | Gate 2 de G4 (prospectif) |
| **Kraken EEA** | carnet central, dépositaire ; perpétuels et futures à échéance depuis le 20/05/2025 | hors chaîne | Payward Europe Digital Solutions (CY) Ltd, CySEC 342/17 ; REGAFI : libre prestation depuis le 13/12/2017, A1/A2 sur C4/C10, A3 depuis le 01/09/2025 | France dans la liste EEE (support Kraken, 30/09/2026) | aucune | **CONDITIONAL** | 0,02 % / 0,05 % ; minimum 0,0001 BTC ; jusqu'à 10× | voir Kraken Futures (§2.4) ; identité du carnet EEE avec le carnet global UNKNOWN | **CONDITIONNEL** (meilleure alternative) | voir §2.4 |
| **Coinbase EEA** | carnet central, dépositaire ; futures « perpetual-style » à 5 ans depuis le 09/03/2026 | hors chaîne | Coinbase Financial Services Europe Ltd, CySEC 374/19 ; REGAFI : libre prestation depuis le 18/10/2019, A1/A2 sur C4/C10 depuis le 12/12/2025 | France incluse (presse, 09/03/2026) ; périmètre de l'API EEE UNKNOWN | aucune | **CONDITIONAL** | minimum 10 USDC (doc API) ; 10× sur BTC et ETH ; frais UNKNOWN | non étudié (UNKNOWN) | **CONDITIONNEL** | NON_RETENU (non étudié) |
| **Robinhood Europe → Bitstamp** | courtier qui transmet les ordres à un MTF MiFID (Slovénie) ; dépositaire | hors chaîne | Robinhood Europe UAB, Banque de Lituanie (03/04/2025), libre prestation depuis le 17/04/2025, A1/A2 sur C4/C10 ; plateforme : Bitstamp finančne storitve d.o.o. (ATVP), libre prestation depuis le 08/01/2025, A8 sur C4 | DIC en français du 01/07/2026, « Swaps linéaires à durée indéterminée – Crypto-actif / dollars US », « convient uniquement aux investisseurs de détail avertis » ; jusqu'à 10× (page française) | aucune | **À VÉRIFIER** (compte, test et API UNKNOWN) | UNKNOWN | non étudié | candidat | NON_RETENU |
| **Gemini EU** | perpétuels pour l'UE (« up to 100x », « Subject to eligibility ») lancés le 05/09/2025 | hors chaîne | Gemini Intergalactic EU Artemis (MFSA) ; REGAFI : libre prestation depuis le 26/06/2025, A2/A3 sur C4/C10 | France non nommée dans l'annonce ; API non mentionnée | aucune | **À VÉRIFIER** | UNKNOWN | non étudié | candidat (corrigé, §2.5) | NON_RETENU |
| **Backpack EU** | perpétuels jusqu'à 10× depuis septembre 2025 (presse) | hors chaîne | Trek Labs Europe Ltd, CySEC 273/15 ; REGAFI : libre prestation depuis le 04/07/2025, A1/A2/A3 sur C1-C4 et C9 ; licence réémise en juin 2025 après une transaction de 200 000 EUR avec la CySEC (passif de FTX EU, presse) | UNKNOWN | aucune trouvée | **À VÉRIFIER** | UNKNOWN | non étudié | candidat (ajouté par le vérificateur) | NON_RETENU |
| **One Trading Exchange** | OTF MiFID (Pays-Bas) ; perpétuels BTC/EUR et ETH/EUR jusqu'à 10× (presse) | hors chaîne | AFM ; REGAFI : libre prestation depuis le 22/10/2024, A1-A3 et A9 sur C10 (2024) et C4 (04/05/2026) | ouverture aux particuliers allemands, néerlandais et autrichiens en mai 2025 : **UNKNOWN** (presse seulement, blog officiel en 404) ; France UNKNOWN | aucune | UNKNOWN | UNKNOWN | non étudié | candidat | NON_RETENU |
| **PM MTF** (perpetuals.com) | MTF de dérivés crypto, offre B2B | hors chaîne | CySEC ; REGAFI : libre prestation depuis le 29/09/2025, **A8 seulement** sur C4/C10 ; CASP MiCA pour le spot depuis le 20/07/2026 | UNKNOWN | aucune | UNKNOWN | UNKNOWN | non étudié | peu probable en accès direct | NON_RETENU |
| **Bitpanda Financial Services** | CFD crypto contre la maison (« Bitpanda Leverage ») | hors chaîne | Autriche ; REGAFI : A1/A2/A3 sur C4/C10 | UNKNOWN | aucune | hors cible (pas de carnet) | UNKNOWN | — | NON_RETENU | NON_RETENU |
| **Foris Capital CY** | produit UNKNOWN (entité de Crypto.com selon la presse, non vérifié) | hors chaîne | REGAFI : libre prestation depuis le 07/05/2026, A1-A5 sur C4/C10 | UNKNOWN | aucune | UNKNOWN | UNKNOWN | — | à vérifier | NON_RETENU |
| Deribit ; Bybit EU | — | — | aucune entreprise d'investissement au registre MiFID de l'ESMA (MiFID en attente pour Bybit EU) | — | — | non disponible | — | — | NON_RETENU | — |

Gemini, Backpack, Robinhood et Kraken offrent des leviers au-dessus du plafond CFD de 2:1. Cela suppose qu'ils qualifient leur
produit de future, mais aucun dossier ne documente ce choix (§3.3). Ce point est sans effet à 1×.

### 2.4 Sources de données hors DEX (recherche seulement sauf mention)

| Venue | Marché | Chaîne | Entité, autorisation UE | CGU France / UE (date) | Alertes | M1 | Frais, minimums | Historique de liquidations | Exécution | Données G4 |
|---|---|---|---|---|---|---|---|---|---|---|
| **Kraken Futures** (tape publique, archive Tardis) | carnet central ; famille A : vente au carnet « au prix imputé à capital nul », reliquat « assigné à des fournisseurs de liquidité volontaires » hors carnet (support Kraken « Exemple d'assignement », 07/10/2026) | hors chaîne | carnet global ; entité EEE Payward Europe (§2.3) ; identité du carnet EEE avec le carnet global UNKNOWN (paramètre `includeMTFData` non documenté) | — | aucune | voir Kraken EEA | — | WebSocket `trade` : `type` ∈ {fill, liquidation, termination, block} (page non datée) ; REST : fill, liquidation, partial liquidation, assignment, unwind, rfq, 7 jours au plus ; Tardis : depuis le 2019-03-30, trades, `incremental_book_L2` et `book_snapshot` ; présence de `type` dans l'archive **UNKNOWN** | via Kraken EEA | **CONDITIONNEL** (à enquêter en premier) |
| **OKX global** (`liquidation-orders`, archive Tardis) | flux de liquidations du même opérateur que la cible | hors chaîne | OKX Global : produits swaps non disponibles pour un particulier de l'EEE | — | — | swaps globaux : non disponibles | — | depuis le 2023-03-30, mais « at most one update per second per contract » : un **échantillon**, pas un registre ; X-Perps non mentionnés ; funding des X-Perps depuis le 2026-08-05 | NON_RETENU (produit global) | CONDITIONNEL (contraste du même opérateur ; licence Tardis et OKX API Agreement §9.4) |
| **Bybit** (`allLiquidation`, archive Tardis) | — | hors chaîne | Bybit EU sans agrément MiFID | — | — | non disponible | — | depuis le 2025-02-25, « all liquidation events… 500ms » ; licence UNKNOWN | NON_RETENU | contraste possible, non instruit |
| **dYdX v4** via Tardis | — | — | — | — | — | — | — | depuis le 2024-08-23, `v4_trades` « including liquidation info » ; payant ; carnet archivé : UNKNOWN | — | complément payant de la ligne dYdX |
| **Binance Vision** | — | — | Binance : bloqué | CC BY-NC-SA 4.0, recherche personnelle non productive seulement | — | BLOQUÉ | — | jeux de liquidations éventuels : non examinés | NON_RETENU | non examiné |

Pour toute archive Tardis, voici ce qu'on sait du contrat :
- **Licence lisible** (CGU Tardis, « Last modification date » 2026-10-20 ; document juridique, pas une donnée de marché). L'usage est
  permis pour les besoins internes, la recherche, l'éducation ou un usage personnel, y compris le trading. La redistribution des
  données est interdite ; les données dérivées peuvent être publiées si elles sont agrégées sur au moins 10 minutes.
- **Clause 9.7** : les données obtenues directement auprès d'une place par le logiciel de Tardis restent soumises aux conditions de
  cette place. Sa portée pour l'archive historique est UNKNOWN.
- **Restrictions supplémentaires pour Coinbase.**
- **Coût : UNKNOWN.** Le prix figure sur un bon de commande. L'offre est vendue par famille de données, pas par place ni par période
  (« We don't sell single instruments or date ranges »).

### 2.5 Corrections du vérificateur intégrées

| Venue | Affirmation des dossiers | Statut | Ce que dit ce rapport |
|---|---|---|---|
| Ostium | « aucune alerte » (dossier `oracle`) | FAUX | nommé par la SEC des Philippines (Cointelegraph, 21/04/2026) |
| Gains (gTrade) | « aucune alerte » (dossier `oracle`) | FAUX | nommé par la même alerte |
| Gemini EU | lancement UNKNOWN | FAUX | perpétuels lancés pour l'UE le 05/09/2025 (blog Gemini) |
| dYdX v4 | frais 1,0 / 5,0 pb | NON_VERIFIABLE | UNKNOWN (la page citée redirige en 308, valeur non relue) |
| Lighter | voie S3 libre de la condition « 100 LIT » | NON_VERIFIABLE | UNKNOWN avant une lecture des métadonnées du bucket autorisée par le propriétaire |
| ApeX | `apex-trade.capital` attribué à ApeX | NON_VERIFIABLE | lien UNKNOWN (clone possible) ; seul `www.apex.exchange` est attribué |
| Velocity | renommage « le 1er juillet 2026 » | NON_VERIFIABLE | date UNKNOWN ; la bêta privée annoncée est confirmée |
| Velocity | liquidations par compte archivées par mois, marché entier sur 31 jours | NON_VERIFIABLE | UNKNOWN (la page renvoie à `openapi.json`, non relu) |
| Synthetix Exchange | « appariement hors chaîne » | NON_VERIFIABLE | UNKNOWN |
| One Trading | particuliers allemands, néerlandais, autrichiens | NON_VERIFIABLE | UNKNOWN (presse seulement) |
| Velocity | exploit de 295 M$ (`clob`) ou 285 M USD (`oracle`) | CONFIRME, montant divergent | « 285 à 295 M USD selon la source » |
| GRVT | page « non datée » | CONFIRME | page mise à jour le 03/08/2026 ; URL Cointelegraph de la licence en 404 |
| Lighter | droit applicable | CONFIRME | droit de Floride ; aucune clause anti-scraping ; la page ne dit pas explicitement que les IoC entrent dans le carnet public |
| OKX via Tardis | « piste la plus utile » | CONFIRME avec réserve | flux limité à une mise à jour par seconde et par contrat : échantillon, pas registre |
| Velocity | citation « liability transfer » | CONFIRME | elle est sur la page du bot liquidateur ; la page des liquidations dit « The transfer price is the oracle price » |

Manques signalés par le vérificateur et traités ici : Kraken Futures comme source G4 (§2.4, §5), Backpack EU (§2.3), statut de
Gemini EU, flux Bybit `allLiquidation`, réserve sur le flux OKX, DEX non couverts. Restent ouverts : une éventuelle archive de dYdX v3
(avant le 2024-08-23), les jeux de liquidations de Binance Vision, la qualification CFD ou future des produits à plus de 2:1.

---

## 3. Le cadre réglementaire en clair

### 3.1 Ce qui est écrit

| Texte | Ce qu'il dit | Source |
|---|---|---|
| **MiCA**, règlement (UE) 2023/1114, art. 2(4)(a) | MiCA ne s'applique pas aux crypto-actifs qui sont des instruments financiers. **Une licence MiCA (CASP) ne couvre jamais un perpétuel.** | JO L 150 du 09/06/2023 |
| MiCA, considérant 22 | « Where crypto-asset services are provided in a fully decentralised manner without any intermediary, they should not fall within the scope of this Regulation. » | idem |
| MiCA, art. 59(1) et 61(1) | L'agrément est exigé de celui qui fournit le service. L'exemption de « sollicitation inversée » ne vaut que si le client agit « at its own exclusive initiative » ; toute sollicitation dans l'Union l'annule. | idem |
| **MiFID II**, directive 2014/65/UE, annexe I section C (4), (9), (10) | Les futures, swaps, CFD et « any other derivative contracts » sont des instruments financiers. | JO L 173 du 12/06/2014 |
| MiFID II, art. 5(1) et 4(1)(1) | La fourniture de services d'investissement à titre professionnel exige un agrément préalable. | idem |
| MiFID II, texte entier | Le mot « decentralis… » n'y figure **pas une fois** : il n'y a pas d'équivalent du considérant 22 de MiCA. | recherche sur le texte du JO |
| MiFID II, art. 39 et 42 | Un État peut exiger une succursale d'une entreprise de pays tiers pour les clients de détail (la France l'exige). Sollicitation inversée : exemption étroite, perdue si l'entreprise « solicits clients … or promotes or advertises » dans l'Union. | idem ; CMF L.532-48 d'après l'ACPR (2019), version en vigueur non vérifiée |
| **Régime pilote DLT**, règlement (UE) 2022/858, art. 3(1) | Seuls des actions, des obligations sans dérivé incorporé et des parts d'OPCVM peuvent être admis. **Aucun dérivé.** | JO, règlement 2022/858 |
| **ESMA, déclaration du 24/02/2026** (ESMA35-243228190-8024) | Les dérivés « often marketed as perpetual futures » sont « likely to fall within the scope » des mesures CFD. Le nom commercial est « irrelevant ». Ne comptent pas : la négociation sur une plateforme, le funding, la protection contre le solde négatif, les « insurance funds ». Adéquation, DIC PRIIPs et marché cible étroit sont exigés. Le texte est « addressed to firms and NCAs ». Note 5 : « participating in circumvention activities … is prohibited ». | PDF ESMA du 24/02/2026 |
| **Mesure CFD**, décision ESMA (UE) 2018/796, puis mesure nationale de l'AMF du 01/08/2019 | Elle vise « la commercialisation, la distribution et la vente » de CFD aux particuliers. Sur la crypto, la marge initiale est de 50 %, soit un levier de 2:1. S'y ajoutent la clôture de marge, la protection contre le solde négatif et l'absence d'avantage. L'art. 3 interdit de « participate, knowingly and intentionally » à un contournement, « including by acting as a substitute for the CFD provider ». | JO 2018 ; AMF, communiqué du 02/07/2019 |
| **AMF, 22/02/2018** | Une offre de dérivés sur crypto exige un agrément ; la publicité électronique est interdite. | communiqué AMF |
| **AMF, listes noires** | « Produits dérivés sur crypto-actifs », version du 28/09/2026 : `www.apex.exchange` (22/09/2025), `www.asterdex.com` (27/02/2026). Les listes « n'ont pas vocation à être exhaustives ». | PDF AMF du 28/09/2026 |
| **AMF, 05/02/2026** | Depuis le 01/07/2026, seuls les prestataires MiCA autorisés peuvent fournir des services sur crypto-actifs en France. Les peines prévues sont de deux ans et 30 000 € (CMF L.54-10-4 et L.572-23). Ce régime concerne le **spot**. | actualité AMF |
| CMF L.573-1 | La fourniture de services d'investissement sans agrément est punie de trois ans et de 375 000 € (annexe ACPR de 2013 ; version en vigueur non vérifiée, Légifrance inaccessible en lecture scriptée). | ACPR ; ordonnances de 2026 |
| CMF L.621-13-5 et ordonnances du TJ de Paris (29/04/2026, 10/06/2026) | Sur saisine de l'AMF, le juge peut ordonner aux FAI de bloquer un site. Les deux ordonnances de 2026 visent des sites de services sur crypto-actifs, **pas des DEX**. | doctrine.fr |
| FCA, 21/05/2026 | Sur Hyperliquid : pas d'accès au Financial Ombudsman Service ni au Financial Services Compensation Scheme. | fca.org.uk |

### 3.2 Ce que le desk en déduit (interprétation)

- **Un perpétuel de DEX relève du droit des dérivés, pas de MiCA.** L'exemption « fully decentralised » de MiCA ne vaut que pour
  les services sur crypto-actifs : elle ne s'étend pas aux dérivés.
- **M1 échoue dans les deux cas de figure.** Soit une personne fournit le service à titre professionnel, et il lui faut un agrément
  MiFID : aucune ne l'a. Soit il n'y a aucune personne, et alors il n'y a personne à agréer, donc pas de prestataire autorisé.
- **Un perpétuel de DEX offert à un particulier français relève vraisemblablement des règles CFD** : levier de 2:1, solde négatif
  protégé. Aucun DEX lu ne les applique (leviers documentés de 40× à 1 001×).
- **« La France n'est pas nommée » ne veut pas dire « la France est acceptée ».** C'est le niveau contractuel ; le niveau
  réglementaire manque partout.
- **Les clauses de renvoi au droit local** (dYdX, Gains, Flash Trade, Extended) font dépendre l'éligibilité contractuelle de la
  question juridique non tranchée. Un utilisateur français ne peut pas affirmer de bonne foi qu'il remplit la condition.
- **La sollicitation inversée n'est pas une autorisation.** C'est une exemption étroite pour le prestataire. Un DEX qui fait de la
  publicité, des parrainages et tient des réseaux sociaux l'invoquerait difficilement.
- **Utiliser directement les contrats pour contourner un blocage** (interface, géoblocage ou DNS) serait un contournement
  géographique, exclu par M1. La réponse `::1` du DNS de la box pour `apex.exchange` et `bitunix.com`, deux domaines de la liste
  noire, est cohérente avec un blocage par le FAI, sans preuve : aucune ordonnance n'a été trouvée.

### 3.3 Ce qui n'est pas tranché (question pour un juriste)

- **La légalité, pour toi, d'utiliser un DEX non autorisé.** Aucun texte trouvé ne sanctionne le client particulier. La clause
  anti-contournement (décision 2018/796, art. 3 ; ESMA 2026, note 5) est rédigée sans désigner son sujet, et elle n'a été trouvée
  appliquée à un client dans aucune source.
- La validité du contrat et le recouvrement d'une créance, les recours devant le médiateur de l'AMF, la fiscalité, le RGPD des
  données on-chain, la qualification juridique d'un jeton de pool de liquidité : **UNKNOWN**.
- La qualification CFD ou future des produits à plus de 2:1 chez les prestataires autorisés (Gemini, Backpack, Robinhood, Kraken).
  Ces plateformes pourraient passer à 2:1 ou restreindre l'accès ; c'est sans effet à 1×.

### 3.4 Ce que cela veut dire en pratique

- **Ce qui est certain** :
  - aucune protection MiFID ni CFD sur un DEX ;
  - M1 échoue pour tous les DEX ;
  - deux DEX sont désignés par le régulateur français ;
  - Ostium exclut l'UE par contrat.
- **Ce qui ne change pas** : OKX Europe X-Perps reste la cible, Kraken EEA et Coinbase EEA les alternatives. Les drapeaux
  Hyperliquid restent à 0.
- **Le rôle « données » ne dépend pas de M1.** M1 juge l'exécution. La lecture de données publiques relève de M3 (licence, coût) et
  d'une décision écrite par source : D3 ne couvre que l'archive Hyperliquid.

---

## 4. Les places autorisées qui ressemblent le plus à un DEX

**Aucune n'est on-chain ni non dépositaire** (§3.1 : régime pilote, registres). Les places qui se présentent comme « premières
places MiFID de perpétuels crypto » (One Trading, perpetuals.com) sont des moteurs centralisés. Ce qui se rapproche le plus d'un DEX
**en fonction** :

| Critère d'un DEX | Places autorisées qui s'en rapprochent | Limites |
|---|---|---|
| Carnet multilatéral à règles publiques (MTF ou OTF) | Bitstamp finančne storitve (MTF, Slovénie), accessible par Robinhood Europe ; One Trading (OTF, Pays-Bas) ; PM MTF (perpetuals.com) | Robinhood : compte et API UNKNOWN. One Trading : particuliers français UNKNOWN. PM MTF : offre B2B. Toutes dépositaires. |
| Ordres maker post-only par API sur un carnet central (ce qu'exige la piste L) | OKX Europe X-Perps, Kraken EEA, Coinbase EEA | Compte, test d'adéquation et API à vérifier (M1 CONDITIONAL). |
| Liquidations visibles dans la tape publique, comme sur Hyperliquid | Kraken (`type = liquidation` dans la tape des trades) ; OKX (canal `liquidation-orders`, partiel) | Kraken : l'identité du carnet EEE avec le carnet global, et donc la visibilité côté EEE, est UNKNOWN. OKX : « This data doesn't represent the total number of liquidations ». |
| Auto-garde des fonds | **aucune** | Toutes les places autorisées sont dépositaires. |

Lecture du desk : sur les deux critères qui comptent pour le programme, un carnet où poster en maker et des liquidations visibles,
**Kraken EEA est la place autorisée la plus proche d'un DEX**, sous réserve d'établir que sa tape EEE porte le type de chaque trade.
Pour le scalping lui-même, rien ne change : la cible est OKX Europe X-Perps.

---

## 5. Gate 1 de G4 : quelle source remplace Hyperliquid

### 5.1 Ce que G4 exige d'une source

Cinq critères (dossier `oracle` §2, desk §3-4) :
- (a) un marqueur de liquidation par exécution, sur tout le marché, distinguant le carnet du backstop ;
- (b) un mid historique, ou un proxy déclaré par amendement ;
- (c) une couverture point-dans-le-temps sur des mois ;
- (d) une licence et un coût bornés ;
- (e) un flux forcé non informationnel qui **frappe un carnet**.

Les DEX à oracle échouent par construction à (b) et (e) : la position est fermée au prix de l'oracle contre le pool, le prix vient
des CEX, et il n'y a aucun carnet à mesurer. Un test sur eux retomberait sur la réversion après mouvement (familles r1 à r12 closes)
et sur le proxy de flush de r13 O1, négatif sous le contrôle apparié au prix.

### 5.2 Comparaison des candidats sérieux

| Critère | Hyperliquid (D3) | **dYdX v4** | Lighter | Kraken Futures (Tardis) |
|---|---|---|---|---|
| (a) Marqueur | `liquidation.method` ∈ {market, backstop} par fill ; présence dans l'archive UNKNOWN | `type` ∈ {LIQUIDATED, DELEVERAGED} dans les trades publics : **documenté et confirmé** | `type` ∈ {trade, liquidation, deleverage, market-settlement} dans l'API ; dans le parquet : UNKNOWN | `type` ∈ {fill, liquidation, partial liquidation, assignment…} dans la tape publique ; dans l'archive Tardis : **UNKNOWN** |
| (b) Mid | carnets L2 archivés (schéma et cadence UNKNOWN) | **aucun carnet gratuit** : proxy à déclarer (Tardis payant : carnet UNKNOWN) | aucun carnet ; `mark_price` horaire seulement | **carnet archivé** (`incremental_book_L2`, `book_snapshot`) |
| (c) Profondeur | début UNKNOWN (P-G4-3) | rétention de l'indexeur UNKNOWN ; reconstructible depuis un nœud ; Tardis depuis le 2024-08-23 | depuis le 2025-01-17 | depuis le 2019-03-30 |
| (d) Licence | aucune publiée | aucune licence de données (code sous AGPL-3.0) ; clause d'accès automatisé des CGU : UNKNOWN | aucune ; accès S3 sans condition LIT : UNKNOWN | **écrite** : usage personnel et de recherche permis, redistribution interdite (CGU Tardis du 2026-10-20) |
| (d) Coût | requester-pays, plafond de 10 USD (D3) | **gratuit** (débit de l'indexeur UNKNOWN) | requester-pays, même circuit et même plafond que D3 | **UNKNOWN** (abonnement par famille de données) |
| (e) Moteur | famille B | **famille A**, la plus proche d'OKX | famille B (identique à Hyperliquid) | **famille A**, sur un CEX dont l'entité EEE est M1 CONDITIONAL |
| Données personnelles | `liquidatedUser` (adresses) | champs publics sans adresse (`id, side, size, price, type, createdAt, createdAtHeight`) | UNKNOWN | UNKNOWN |
| Ce qui manque | échantillon de schéma | rétention, proxy de mid, décision de licence | schéma, licence, condition LIT | champ `type` archivé, prix, carnet EEE |

### 5.3 Recommandation

1. **Source recommandée : dYdX v4.** C'est le seul DEX dont le marqueur de liquidation est documenté et confirmé, dont l'accès est
   gratuit et dont le moteur appartient à la famille A. Ses ordres de liquidation au « Fillable Price », « to match against
   liquidity resting on the order book », sont l'analogue le plus proche du prix « entrusted » d'OKX, calculé « based on current
   market depth, basis, and mark price ».
2. **À enquêter en parallèle, sans achat : Kraken Futures via Tardis.** Il deviendrait le premier choix si trois conditions sont
   réunies :
   - le champ `type` est conservé dans l'archive ;
   - le prix est acceptable ;
   - le carnet est le même que celui de l'EEE.
   Il supprime l'amendement sur le mid et offre un historique long. **Interprétation sur le prix** : une dépense de données
   supérieure à la porte économique de G4 (97 USD par an, D4) se justifierait mal pour un Gate 1 qui ne mesure qu'un signe.
3. **Hyperliquid reste le repli.** D3 est valide, l'archive a un carnet, et le protocole est déjà écrit pour elle. Aucune règle
   n'oblige à l'abandonner pour la recherche.
4. **Lighter n'apporte rien de plus qu'Hyperliquid** sur le mécanisme (même famille B), sinon de ne pas être Hyperliquid. Non
   recommandé.
5. **Un double Gate 1** (Hyperliquid et dYdX : familles B et A) est possible s'il est **pré-déclaré** avant toute lecture. Il faut
   écrire une lecture unique pour chacun, la règle de lecture d'un PASS sur l'un et d'un FAIL sur l'autre, et l'inscrire au budget
   de multiplicité du programme ([LP-PROGRAMME-GATES.md](LP-PROGRAMME-GATES.md) §2, Holm).

### 5.4 Ce qui se transfère vers OKX X-Perps, et ce qui ne se transfère pas

**Le signe seulement.** Aucun chiffre de la source (fréquence, magnitude, seuil) n'entre dans un critère d'OKX : c'est la règle 8 du
desk (§4.3).

| Dimension | dYdX v4 | Kraken Futures | OKX X-Perps | Se transfère ? |
|---|---|---|---|---|
| Déclencheur | protocole, sous la marge de maintenance | sous la marge de maintenance | ratio de maintenance ≤ 100 %, mark price | **oui** (flux forcé non informationnel) |
| Arrivée au carnet | ordres de liquidation au « Fillable Price » calculé, contre la liquidité au repos | vente au carnet « au prix imputé à capital nul » | position mise « into the market » au prix « entrusted » (profondeur, base, mark), après reprise du compte par le système | **oui pour la forme** (famille A) ; formules de prix différentes |
| Hors carnet | `DELEVERAGED` | `assignment` (fournisseurs de liquidité volontaires) | ADL au mark, fonds de sécurité | **oui pour la séparation** carnet / hors carnet (comme market / backstop) |
| Observabilité | chaîne publique, complète par construction | tape publique ; archive Tardis à vérifier | flux partiel, non chronologique, sans historique REST | **non** : un seuil absolu ne se reporte pas |
| Appariement et microstructure | validateurs, blocs, chaîne Cosmos | CEX central | CEX central, place EEE retail | **non** pour dYdX ; plus proche pour Kraken |
| Frais de liquidation | pénalité au plus 1,5 % versée au fonds d'assurance, modifiable par la gouvernance | UNKNOWN | taux taker et frais de compensation versés au pool OKX | **non** |
| Paliers, levier, funding | UNKNOWN en primaire | UNKNOWN pour Kraken Futures global | paliers propres, jusqu'à 10×, funding 8 h ou 4 h | **non** |
| Instrument | perpétuel | perpétuel | future à 5 ans avec base et bandes de prix | **non** (le mécanisme ne dépend pas de l'échéance) |
| Clientèle et horloge | crypto, mondiale | mondiale ; EEE UNKNOWN | EEE retail, structure horaire UNKNOWN | **non** (pas de stratification horaire transférée) |

La lecture des verdicts ne change pas (G4 §3.4, §8) :
- un `G4_GATE1_PASS` autorise le gel du Gate 2 OKX, et rien d'autre ;
- un `G4_GATE1_FAIL` veut dire « non poursuivi faute de preuve de développement ». Ce n'est pas une preuve d'absence sur OKX.

### 5.5 Ce qui change dans LP-G4-DRAFT-PREREGISTRATION.md

Aucune modification n'est faite par ce rapport : ce sont des amendements à écrire **avant le gel**, si le propriétaire change de
source. **Si Hyperliquid est gardé, rien ne change.**

**Commun à toute nouvelle source**

| Section de G4 | Changement |
|---|---|
| En-tête, « Nature », H-G4-1 | Remplacer « Hyperliquid » par la source choisie ; le Gate 2 reste OKX X-Perps. |
| §0.1, §0.4 | Déclarer « aucune donnée lue » pour la nouvelle source ; recopier les extraits de sa documentation (dYdX : `api-documentation.md` du dépôt `v4-chain`, page des liquidations ; Kraken : pages `trade` et `get-trade-history`, article d'assignation, page Tardis). |
| P-G4-1 | **Nouveau consentement écrit** : D3 ne couvre que l'archive Hyperliquid. |
| P-G4-2 | Décision de licence (dYdX : aucune licence de données écrite ; Kraken : CGU Tardis et clause 9.7) et plafond (dYdX : budget de requêtes et de durée ; Kraken : budget d'achat, nouvelle décision). |
| P-G4-3 | Échantillon de schéma, clés et horodatages minimum et maximum seulement, sans valeur. dYdX : plus ancienne hauteur servie par l'indexeur, présence de `LIQUIDATED` et `DELEVERAGED` depuis cette date, sens du champ `side` sur un trade liquidé, granularité de `createdAt`. Kraken : présence de `type` dans l'archive. |
| P-G4-4, §3.4 | Réécrire la déclaration de transférabilité M9 avec le tableau de §5.4. |
| P-G4-5, §3.3 | Remplacer `method = "market"` par `type = LIQUIDATED` (dYdX) ou `type ∈ {liquidation, partial liquidation}` (Kraken) dans l'intensité primaire L. `DELEVERAGED` (dYdX) et `assignment` (Kraken) sont exclus de L et rapportés à part, comme le backstop. Chez Kraken, `unwind` et `termination` sont exclus. t_e devient le dernier trade liquidé de la fenêtre. Les formules du seuil relatif ne changent pas. |
| P-G4-6 | Nouvelle ligne de registre (`dydx-trades-G4-gate1` ou `krakenf-trades-G4-gate1`), statut `unread`. |
| §3.1 | Univers : marchés perpétuels de la source, instantané des métadonnées haché au gel ; même règle de survie. |
| §4.1 | Fenêtre : du premier jour où le marqueur existe au 2026-09-30, déclarée avant toute lecture. Si la rétention est courte, la fenêtre est celle qui existe, écrite au gel, jamais allongée après un résultat. Quarantaine du 2026-10-14 au 2026-10-31 inchangée. |
| §9 | Mettre à jour la liste des UNKNOWN. |

**Propre à une source sans carnet (dYdX gratuit)**

| Section de G4 | Changement |
|---|---|
| §5.1, §5.2 | Le mid devient un **proxy déclaré**, par exemple la médiane des prix des trades `LIMIT` des 60 secondes qui précèdent l'instant, **hors** trades `LIQUIDATED` et `DELEVERAGED`. Observation manquante s'il n'y a aucun trade `LIMIT` dans les 60 s. σ_15 est calculée sur les rendements à 1 minute du même proxy. |
| §9 | **Biais vers le PASS à déclarer** : un proxy fondé sur les trades contient un rebond entre l'achat et la vente. Une vente forcée s'exécute au bid, les trades suivants se répartissent des deux côtés, ce qui imite une « réversion ». Le contrôle apparié au prix (§6.2), mesuré avec le même proxy, en absorbe une partie ; le reste est déclaré. |
| §7.3 | `INCONCLUSIVE` si le proxy manque pour plus de 20 % des événements (remplace « mid absent à ≤ 60 s »). |

Pour Kraken Futures, le mid vient de `incremental_book_L2` et §5.2 ne change pas.

---

## 6. Les pools de liquidité des DEX à oracle

**Mécanisme.** Sur GMX (GM/GLV), Jupiter (JLP), Gains (gToken), Veranta (avUSDC), Ostium (OLP), Aster (ALP) et Flash Trade (FLP),
**le pool est la contrepartie des traders**. GMX l'écrit : « The GLV / GM pool is the counterparty to traders. If traders profit,
that profit comes from the value of the GLV / GM pool ». Gains : « Vaults serve as a counterparty to all trades ».

Le rendement a plusieurs sources, sans chiffre ici :
- **Une part des frais** : ouverture, fermeture, impact, emprunt, funding, swaps, mint/burn.
- **Le reliquat des liquidations** : JLP, FLP, ALP, GM. Exception : chez Veranta, les frais de liquidation vont à la trésorerie.
- **Le PnL net des traders, de signe opposé.** Leurs pertes sont des gains du pool, leurs gains des pertes du pool.
- **Des variantes** :
  - Veranta et Ostium placent un tampon ou une tranche junior devant le principal des LP ;
  - JLP et GM détiennent des actifs, ce qui ajoute une exposition directionnelle (Jupiter : « During strong bull markets, increased
    trader profitability can reduce JLP value ») ;
  - Veranta écrit « Depositors can lose principal », et Ostium « OLP deposits are not insured ».

**Pourquoi ce n'est pas du scalping.**
- Le déposant ne choisit **ni ses entrées, ni ses sorties, ni ses événements**. Il est exposé en continu au PnL agrégé des traders, y
  compris dans les queues.
- Il n'y a pas de position courte dans le temps, pas de stop, pas de règle : c'est un **portage qui vend une assurance** aux traders
  à levier.
- Il gagne quand les frais sont élevés et que les traders perdent. Il perd quand les traders ont raison en bloc.
- Les retraits sont freinés : époques de 1 à 3 chez gTrade, frais jusqu'à 2,5 % chez Veranta, plafonds d'AUM chez JLP, GM
  illiquides dans GLV. On ne peut donc pas réagir vite.
- Le payeur est le même que dans la piste L (le liquidé, le trader perdant), mais **sans sélection des instants**, ce qui est
  l'inverse de la thèse du programme.

**Risques documentés** (incidents publiés avant le 2026-10-13) :

| Risque | Exemple | Source |
|---|---|---|
| Manipulation d'un actif de référence mince | GMX v1, AVAX, 18/09/2022 | crypto.news |
| Manipulation d'oracle | Mango Markets, 11/10/2022 (poursuites de la CFTC) | cftc.gov, 09/01/2023 |
| Faille de contrat | coffre GLP de GMX v1, 09/07/2025 | certik.com, 16/07/2025 |
| Compromission de gouvernance | Drift, 01/04/2026, 285 à 295 M USD selon la source | trmlabs.com, 02/04/2026 ; thedefiant.io |
| Squeeze d'un marché mince contre le coffre du protocole | HLP et JELLY, 26/03/2025, règlement à un prix administré | halborn.com |
| Panne de chaîne | Optimism, 26/04/2024, pertes des stakers Synthetix | gov.optimism.io |
| Erreur d'opérateur sur le mark | Aster XPL, 25-26/09/2025, liquidations remboursées | theblock.co |

Risques structurels écrits dans les docs :
- valeur de pool négative possible (README de GMX) ;
- plafonds sur les gains des traders (ADL, `MAX_PNL_FACTOR`, plafond de profit) ;
- dépréciation de stablecoin (sUSD) ;
- contreparties de couverture hors chaîne (Ostium).

**Statut M1 : FAIL.** Un dépôt dans le pool d'une place de dérivés non autorisée revient à utiliser un service non autorisé, voire à
se porter contrepartie de son offre. La qualification du jeton de pool en droit français est UNKNOWN. Les vaults des DEX à carnet
(MegaVault, LLP, NLP, VTF) appellent la même conclusion. Sur ces carnets, la tenue de marché maker serait techniquement possible,
mais aucun n'est exécutable pour ce compte.

---

## 7. Décisions attendues du propriétaire

1. **Règle M1 : la garder telle quelle, ou demander l'étude d'un amendement « DEX » ?**
   - **Recommandé : la garder.** L'exécution reste sur des prestataires autorisés, et les DEX ne servent que de données de recherche.
   - Un amendement serait une règle nouvelle, pour les campagnes à venir seulement (« A gate is never relaxed retroactively »). Le
     desk ne peut pas le recommander : la déclaration ESMA de 2026, la liste noire AMF et les blocages ordonnés vont dans l'autre
     sens. S'il est demandé, il exige au minimum :
     - l'avis écrit d'un avocat français sur la position de l'utilisateur ;
     - l'exclusion des places listées noires, de celles qui excluent l'UE et de celles dont les CGU exigent une licence locale
       (Aster, ApeX, Ostium, dYdX, Gains, Flash Trade, Extended) ;
     - l'acceptation écrite de la perte des protections et des risques de contrat, d'oracle, de pont et de gouvernance ;
     - un traitement fiscal à part.
2. **Source du Gate 1 de G4.** Choisir entre :
   - (a) garder Hyperliquid (D3) ;
   - (b) **dYdX v4 (recommandé)** ;
   - (c) Lighter (non recommandé) ;
   - (d) Kraken Futures via Tardis, après l'enquête de la décision 4 ;
   - (e) un double Gate 1 pré-déclaré, Hyperliquid et dYdX.
3. **Si dYdX v4 est choisi**, il faut quatre accords :
   - le consentement écrit qui remplace ou complète D3 ;
   - l'acceptation de données sans licence écrite, pour une recherche personnelle non redistribuée (comme P-G4-2) ;
   - un échantillon de schéma de l'indexeur (rétention, présence de `LIQUIDATED`, sens de `side`), clés et horodatages seulement ;
   - l'amendement de G4 §5.2 (proxy de mid et biais déclaré) avant le gel.
4. **Autoriser une enquête sur Tardis, sans achat ni téléchargement** : prix sur devis, présence de `type` dans l'archive Kraken
   Futures, présence des X-Perps dans `liquidation-orders`, portée de la clause 9.7 et compatibilité avec l'OKX API Agreement §9.4.
   Un achat éventuel serait une décision séparée, avec un plafond écrit.
5. **Exclure Aster et ApeX de tout usage, données comprises** (liste noire AMF). Recommandé.
6. **Faire vérifier, sans ordre ni clé, l'éligibilité de ton compte et l'API** chez les nouveaux candidats autorisés : Robinhood
   Europe (vers Bitstamp), Gemini EU, Backpack EU, One Trading. S'ils passent, ils entrent au tableau M1 comme alternatives.
7. **Confirmer que les pools et vaults des DEX restent hors programme** (M1 FAIL ; ce n'est pas du scalping).

---

## 8. Sources (date de la page ou du document ; toutes lues le 2026-10-21)

Les copies et les sha256 sont dans `tradingV3-private/research/20261021T-dex-alternatives/{clob,oracle,legal,verify}/`.

**Textes et régulateurs**
- MiCA, règlement (UE) 2023/1114, JO L 150 du 09/06/2023 : http://publications.europa.eu/resource/celex/32023R1114
- MiFID II, directive 2014/65/UE, JO L 173 du 12/06/2014 : http://publications.europa.eu/resource/celex/32014L0065
- Régime pilote DLT, règlement (UE) 2022/858 : http://publications.europa.eu/resource/celex/32022R0858
- Décision ESMA (UE) 2018/796 du 22/05/2018 : http://publications.europa.eu/resource/celex/32018X0601%2802%29
- ESMA, déclaration du 24/02/2026 : https://www.esma.europa.eu/sites/default/files/2026-02/ESMA35-243228190-8024_-_Public_statement_on_derivatives_in_scope_of_the_CFD_product_intervention_measures.pdf
- ESMA, registre intérimaire MiCA (lignes jusqu'au 15/10/2026) : https://www.esma.europa.eu/sites/default/files/2024-12/CASPS.csv ; …/NCASP.csv
- ESMA, registre MiFID (requêtes du 2026-10-21) : https://registers.esma.europa.eu/solr/esma_registers_upreg/select
- REGAFI (API ouverte, requêtes du 2026-10-21 ; métadonnées du 13/05/2026, enregistrements jusqu'au 18/09/2026) : https://www.regafi.fr/api/explore/v2.1/catalog/datasets/
- AMF, 22/02/2018 : https://www.amf-france.org/en/news-publications/news-releases/amf-news-releases/amf-considers-offer-cryptocurrency-derivatives-requires-authorisation-and-it-prohibited-advertise
- AMF, 02/07/2019 (mesure CFD) : https://www.amf-france.org/fr/actualites-publications/communiques/communiques-de-lamf/options-binaires-et-cfd-lamf-adopte-des-mesures-dintervention-lechelle-nationale
- AMF, 05/02/2026 (fin de la transition PSAN) : https://www.amf-france.org/fr/actualites-publications/actualites/lamf-rappelle-que-la-periode-transitoire-pour-les-psan-pour-continuer-de-fournir-des-services-sur
- AMF, liste noire « produits dérivés sur crypto-actifs » du 28/09/2026 : https://www.amf-france.org/sites/institutionnel/files/private/2026-09/amf_listenoire_produitsderivescryptoactifs_28_septembre_2026.pdf ; fiches www.asterdex.com (27/02/2026) et www.apex.exchange (22/09/2025) sous https://www.amf-france.org/fr/espace-epargnants/proteger-son-epargne/listes-noires-et-mises-en-garde/produits-derives-sur-crypto-actifs/
- AMF, listes « crypto-actifs » et « autres catégories » du 08/10/2026 : …/files/private/2026-10/
- TJ de Paris, ordonnances du 29/04/2026 (n° 26/51593) et du 10/06/2026 (n° 26/52712) : https://www.doctrine.fr/d/TJ/Paris/2026/TJP4820839B3DAA2CFA654D ; https://www.doctrine.fr/d/TJ/Paris/2026/TJP69C744DB3811CF414B5C
- FCA, Hyperliquid (21/05/2026, mis à jour le 07/06/2026) : https://www.fca.org.uk/news/warnings/hyperliquid
- SEC des Philippines, d'après Cointelegraph (21/04/2026) : https://cointelegraph.com/news/philippines-sec-warns-dydx-crypto-platforms-investor-alert

**DEX à carnet**
- Hyperliquid : https://app.hyperliquid.xyz/terms (15/06/2026) ; https://hyperliquid.gitbook.io/hyperliquid-docs/historical-data (non daté)
- dYdX : https://dydx.exchange/terms (12/03/2026) ; https://docs.dydx.xyz/concepts/trading/liquidations (non daté) ; https://raw.githubusercontent.com/dydxprotocol/v4-chain/main/indexer/services/comlink/public/api-documentation.md (commit du 2026-03-04) ; https://raw.githubusercontent.com/dydxprotocol/v4-chain/main/LICENSE ; https://docs.tardis.dev/historical-data-details/dydx-v4 (non daté)
- Lighter : https://lighter.xyz/terms (29/12/2025) ; https://docs.lighter.xyz/trading/liquidations-and-llp-insurance-fund.md et …/llp-strategies.md (non datés) ; https://apidocs.lighter.xyz/docs/historical-data.md (updatedAt 2026-10-16, documentation) ; https://apidocs.lighter.xyz/reference/recenttrades.md (2026-05-29) ; https://docs.lighter.xyz/trading/trading-fees.md (non daté)
- Paradex : https://www.paradex.trade/terms-of-service (15/02/2026) ; https://docs.paradex.trade/api/prod/trades/trades.md ; https://docs.paradex.trade/risk/liquidations.md ; https://docs.paradex.trade/trading/trading-fees.md (non datés)
- Injective : https://docs.injective.network/defi/trading/margin-performing-liquidations.md (non daté) ; sdk-go `goadesign_goagen_injective_derivative_exchange_rpc.pb.go`
- Velocity : https://docs.velocity.exchange/developers/data-api.md ; …/migrate-from-drift.md ; …/protocol/trading/liquidations.md ; …/developers/trading-automation/keeper-bots/liquidation-bot.md (non datés) ; https://trmlabs.com/resources/blog/north-korean-hackers-attack-drift-protocol-in-285-million-heist (02/04/2026) ; https://thedefiant.io/news/defi/drift-protocol-rebrands-to-velocity-dex-ahead-of-relaunch (non daté)
- Bluefin : https://bluefin.io/terms-of-use (21/10/2024) ; https://bluefin-exchange.readme.io/reference/getrecenttrades.md (2025-09-22)
- Orderly : https://orderly.network/docs/introduction/orderly-one/supplemental-terms-for-dexes (01/06/2025) ; …/public-info-api/market/liquidations.md (non daté)
- Vertex et Nado : https://www.theblock.co/post/361570/vertex-to-sunset-vrtx-token-move-perp-dex-onto-kraken-backed-ink-layer-2 (08/07/2025) ; https://docs.nado.xyz/developer-resources/api/archive-indexer/matches-and-liquidations.md ; https://docs.nado.xyz/legal/legal/restricted-territories.md (non datés)
- Aster : https://docs.asterdex.com/resources/terms-and-conditions.md (25/02/2026) ; https://docs.asterdex.com/earn/overview/aster-alp.md ; api-docs V3 sur GitHub (non datés)
- ApeX : fiche AMF ci-dessus ; https://cryptoast.fr/amf-ajoute-dex-apex-exchange-liste-noire/ (01/10/2025)
- edgeX : https://edgex-1.gitbook.io/edgeX-documentation/about/terms-of-use.md (non daté)
- Aevo : https://www.aevo.xyz/docs/legal-disclaimer.md ; https://api-docs.aevo.xyz/reference/getinstrumentinstrumentnametradehistory.md (2026-07-07)
- Extended : https://docs.extended.exchange/extended-resources/legal/terms-of-use.md (08/10/2026) ; …/restricted-countries.md
- Pacifica : https://docs.pacifica.fi/trading-on-pacifica/liquidations.md (non daté)
- GRVT : https://help.grvt.io/en/articles/9711621-restricted-jurisdictions (03/08/2026) ; https://www.walkersglobal.com/en/About-us/News/2024/12/Advising-on-the-worlds-first-regulated-DEX-and-securing-a-Class-M-Digital-Asset-Business-License (12/2024)
- Ethereal : https://docs.ethereal.trade/trading/perpetual-futures/liquidations.md ; Hibachi : https://docs.hibachi.xyz/hibachi-docs/trading/liquidations.md (non datés)

**DEX à oracle ou pool**
- GMX : https://raw.githubusercontent.com/gmx-io/gmx-interface/master/landing/src/pages/TermsAndConditions/TermsAndConditions.tsx (« Last modified: August 20th, 2026 ») ; https://docs.gmx.io/docs/providing-liquidity/ ; https://github.com/gmx-io/gmx-synthetics/blob/main/README.md
- Jupiter : https://jup.ag/mobile/terms (21/09/2024) ; https://docs.jup.ag/user-docs/earn/jlp/earn.md (non daté)
- Synthetix : https://synthetix.io/terms (25/01/2022) ; https://docs.synthetix.io/trading/liquidations (28/08/2026) ; https://blog.synthetix.io/synthetix-mainnet-launch/ (12/12/2025)
- Gains : https://gains.trade/terms-of-service (01/11/2023) ; https://docs.gains.trade/liquidity-farming-pools/gtoken-vaults.md (non daté)
- Veranta : https://veranta.xyz/docs/tos (non daté) ; https://docs.veranta.xyz/liquidity-providers/veranta-lp-vault-avusdc.md
- Ostium : https://docs.ostium.com/legal/terms-of-use.md (non daté) ; https://docs.ostium.com/vault/overview.md
- Flash Trade : https://docs.flash.trade/flash-trade/resources/terms-of-service.md (18/12/2023)
- Incidents : https://crypto.news/gmx-dex-suffers-565000-exploit-on-avalanche-avax/ ; https://www.cftc.gov/PressRoom/PressReleases/8647-23 (09/01/2023) ; https://www.certik.com/blog/gmx-incident-analysis (16/07/2025) ; https://www.halborn.com/blog/post/explained-the-hyperliquid-hack-march-2025 ; https://gov.optimism.io/t/april-26-outage-synthetix-impact-and-alignment/6097 ; https://www.theblock.co/post/372500/aster-compensates-traders-xpl-liquidations

**Places centralisées et sources hors DEX**
- OKX Europe : https://www.okx.com/en-eu/help/okx-europe-market-ltd-oem-terms-of-service (02/10/2026) ; [FRANCE-EXCHANGE-ELIGIBILITY.md](FRANCE-EXCHANGE-ELIGIBILITY.md) ; [TRADABLE-INSTRUMENT-UNIVERSE.md](TRADABLE-INSTRUMENT-UNIVERSE.md)
- Kraken EEA : https://support.kraken.com/articles/perpetual-contract-specifications-for-clients-in-the-eea (30/09/2026)
- Kraken Futures, relu par le rédacteur : https://docs.kraken.com/exchange/api-reference/futures-websocket/trade.md (non daté) ; https://docs.kraken.com/api-reference/market-data/get-trade-history.md (non daté) ; https://support.kraken.com/gb/articles/360022835231 (« Exemple d'assignement », 07/10/2026)
- Tardis, relu par le rédacteur : https://docs.tardis.dev/historical-data-details/cryptofacilities (non daté) ; https://docs.tardis.dev/faq/billing-and-subscriptions (non daté) ; https://docs.tardis.dev/legal/terms-of-service (« Last modification date » 2026-10-20) ; https://docs.tardis.dev/historical-data-details/okex-swap et …/okex-futures (non datés)
- Robinhood Europe : https://cdn.robinhood.com/assets/robinhood/legal/crypto_perpetual_futures_kid_fr.pdf (01/07/2026) ; https://robinhood.com/eu/fr/perpetual-futures/
- Gemini EU : https://www.gemini.com/en-GB/blog/gemini-launches-staking-and-derivatives-for-eu-customers (05/09/2025)
- One Trading : https://www.financemagnates.com/cryptocurrency/european-retail-gains-regulated-access-to-leveraged-crypto-trading/ (12/05/2025, presse)
- Binance Vision : https://github.com/binance/binance-public-data/blob/master/TERMS_AND_CONDITIONS.md (26/08/2026)
