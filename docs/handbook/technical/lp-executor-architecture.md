# Exécuteur « fourniture de liquidité événementielle » (track L) et chemin structurel (track S) — architecture cible (2026-10-20)

> **Nature du document.** Proposition d'architecture, rédigée le 2026-10-20 à partir du **code réel** du dépôt (worktree
> `lp-programme`, lecture seule) et des deux rapports de base :
> `docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md` (théorie : poches accessibles à un participant lent =
> fourniture de liquidité événementielle G2 → G4 → G5 → G1, séquence P1 → P2 → P3, règles transversales §3.5) et
> `docs/handbook/reports/research-positive-pnl-structural-2026-10-16.md` (track structurel : portage couvert K1(c)
> auto-collatéralisé, conditionnel à l'admission du spot BTC/ETH comme *Selected Margin* d'un short X-Perp).
> **Rien ici n'autorise un ordre, un drapeau, une capture longue ou un gel** : ce sont des interfaces, des diagrammes texte
> et une séquence de construction soumise à décision. Aucune donnée de marché n'a été lue ; aucune commande git, Docker ou
> systemd ; aucun fichier `.env`, aucune clé. Notes de travail et sources : `tradingV3-private/research/20261020T1430Z-lp-programme/a1-architecture/NOTES.md`.
>
> **Deuxième passe (2026-10-20, après interruption de session).** Chaque référence de code a été re-vérifiée ligne par ligne sur
> le worktree (`grep`/`sed`, 54 occurrences `SWAP` / 22 fichiers reconfirmées) ; les pages officielles accessibles ont été relues
> (tranches et citations en §8 ; les sections WS Market Data / Public Data du docs-v5 n'ont **pas** pu être atteintes et restent
> marquées « non re-vérifiées »). **Écart de chemin** : les deux rapports de base ne sont pas encore dans la branche
> `lp-programme` ; ils ont été lus en lecture seule dans `.worktrees/research-scalping-theory/docs/handbook/reports/` (branches
> `claude/research-scalping-theory-gap` et `claude/research-positive-pnl-structural`, non fusionnées). Les chemins
> `docs/handbook/reports/…` cités ici désignent ces fichiers.
>
> **Conventions.** « établi » = lu dans le code (chemin et ligne) ou dans une page officielle (URL, date) ; « programme » =
> recopié d'un rapport du dépôt ; **UNKNOWN** = ni l'un ni l'autre. Chemins sous `trading-app/src/` sauf mention contraire.
> Identifiants de code en anglais, prose en français. Aucun chiffre de marché courant.
>
> **Révision du 2026-10-20 (passe unique après les relectures `review/quant.md` et `review/cto.md`, verdicts `NEEDS_CHANGES`).**
> Constats majeurs appliqués dans le corps : `lp_entry` reste soumis à `stop_loss_required` et ajoute `stop_distance_min` (CTO M5),
> §2.10 « langage de l'exécuteur et port vers la porte PHP » (CTO M6), `LadderManager` porté à L, budget disque et supervision au
> coût fixe (CTO §4.3), détecteurs alignés sur les protocoles révisés (quant B2/M4, CTO M2/m5/m7), faits documentaires établis par le
> desk (quant m1), décision §9.4 sur l'échelle réelle (quant B4). Détail constat → traitement en §8 « Traitement de la revue ».
> Le code Python n'est pas modifié par cette passe de documents ; les corrections CTO M1–M4 et m1–m12 ont été appliquées au code
> par la passe parallèle `a5-corrections-cto` (2026-10-20 ; `a5-corrections-cto/NOTES.md`), état **vérifié** le 2026-10-21 (suite
> `tests/test_research_lp_*.py` rejouée hors réseau : **40 passed in 4.87 s** ; digests `cli.code_identity()` inchangés depuis cette
> passe code) et décrit ici en §2.3 et §4.4 ; ce qui reste à coder avant un gel est listé dans `LP-G2` P-G2-4 (B).

---

## 0. La réponse en dix lignes

1. **Ce que l'exécuteur doit faire** : avoir des ordres **au repos, post-only, avant et pendant** un événement (cascade de
   liquidations, excursion en stress, fenêtre d'horloge), les **renouveler**, sortir **passivement** (EMA ou temps), compter le
   **funding**, porter le risque par la **taille et des bornes de perte**, pas par un stop serré (rapport théorie §3.5 règles 6, 7, 12).
2. **Pourquoi la boucle minute ne peut pas le porter** (établi) : décision à hh:mm:00 + durée du run + Messenger + REST ;
   un ordre par symbole, `max_concurrent_positions: 3`, TTL 30 s, annulation à 60 s + 10 s **sans re-post**
   (`LimitFillWatchMessageHandler`) ; `order_mode: 1` = limite GTC **sans** post-only (`ExchangeExecutionService.php:435`) ;
   sortie = TP/SL `conditional` au **marché** sur le mark ; stop **obligatoire** à l'entrée (`OkxDemoWriteGate` :
   `stop_loss_required`) ; déclencheur = **états** d'indicateurs, jamais un événement ; watchers REST toutes les 5 s ;
   `instType=SWAP` codé en dur 54 fois dans 22 fichiers ; USDT supposé ; résolveur qui lève sur `_UM_XPERP`.
3. **Architecture cible** : un **processus événementiel** séparé de la boucle MTF, piloté par le WebSocket public
   (`trades`, `books5`, `liquidation-orders`, `mark-price`, `funding-rate` pour `instType FUTURES`) et privé (`orders`,
   `positions`), avec des **détecteurs purs** (mêmes sorties en simulation et en direct), un **gestionnaire d'échelle**
   post-only renouvelée, une **politique de sortie passive**, un **gouverneur de risque de portefeuille** et un **port
   d'exécution** interchangeable (simulateur → démo). Le même journal d'événements nourrit le simulateur et le direct.
4. **Modèle d'instrument** : un `InstrumentSpec` (compatibilité §3) porté par un `ProductKind` (`usdt_swap` | `xperp` | `spot`)
   dans le bundle OKX ; `MarketType::PERPETUAL` conservé pour le X-Perp (ancré par le funding ; politique d'expiry
   déclarée) ; résolveur `instFamily → instId live` à partir de `/public/instruments?instType=FUTURES`, jamais par
   construction de chaîne ; marge **USDC** (interface du compte, 2026-10-15) et `settleCcy` **USD** (API, note d'accès).
5. **Jambe spot (track S)** : deux entités (OKX Europe Ltd CASP / OEM MiFID), aucun bundle spot dans le code
   (`MarketType::SPOT` n'existe que dans les résolveurs de contexte ; le Fake est `PERPETUAL`) ; K1(c) dépend d'une règle
   **non écrite** (spot comme Selected Margin : la page officielle renvoie aux « margin settings » du compte) → **UNKNOWN**
   tranché par le propriétaire au compte, sans ordre, avant tout code.
6. **Ce qui existe et se réutilise** : signature v5, hôtes EEA (démo), `post_only` dans `OkxActionFactory`, WS privé
   (observateur, démo, SWAP), capture publique Paper (8 094 lignes, épinglée `www.okx.com` + BTC/ETH-USDT-SWAP), collecteur
   de recherche `forward_okx.py` (trades + bbo-tbt + carnet REST, SWAP), simulateur `research_exec/simulate.py`
   (touch/through, latence 250 ms, TTL, mark-outs), moteur Fake (post-only annulé au croisement), gate démo complète.
7. **Ce qui manque** (effort S/M/L en §4) : résolveur X-Perp et `instType` paramétré (M), normaliseurs acceptant
   `_UM_XPERP-` (M), devise de règlement (M), collecteur X-Perps `FUTURES` (S–M), détecteurs (M), échelle renouvelée et
   sortie passive (M–L), gouverneur de risque « marge gelée + perte/jour + kill switch + ADL » (M), simulateur événementiel (M),
   bundle spot (L), structure couverte (L).
8. **Séquence** : **mesure avant exécuteur**. Phase 0 (propriétaire, zéro code) → Phase 1 collecteur de **comptage**
   24–72 h → Phase 2 capture publique longue (après §9.4) → Phase 3 simulateur + protocoles pré-enregistrés → **verdict** →
   Phase 4 seulement si un verdict passe : chemin X-Perp, échelle, démo sur drapeaux du propriétaire. Rien avant le verdict
   fwd-okx-r1 du 31 octobre ; confirmation ≥ 2026-11-02.
9. **Jamais avant un verdict** : un ordre (même démo), un drapeau, un hôte live EEA dans `OkxRestEndpointGuard`, un
   assouplissement de `OkxDemoWriteGate` (le « pas de stop serré » passe par un **nouveau** `write_kind` `lp_entry` qui **reste
   soumis à `stop_loss_required`** — le stop de catastrophe ≥ 3 σ15 est fourni dans `metadata['stop_loss_price']` — et ajoute un
   contrôle `stop_distance_min` qui refuse un stop trop **près** ; relecture CTO M5), une relecture de fenêtres consommées, une
   réutilisation du chemin Paper certifié comme preuve.
10. **Ordre de grandeur** (programme) : 500 USDT à 1× = 4–5 ordres de 100 USDT vivants ; si tout passe, des dizaines à
    quelques centaines d'USD par an. L'architecture décrite est celle d'une **mesure propre**, pas d'un revenu ; elle doit
    être construite à l'économie, en réutilisant ce qui existe.

---

## 1. Le mécanisme visé et pourquoi la boucle minute ne peut pas le porter

### 1.1 Le mécanisme (ce que l'exécuteur doit matérialiser)

Le rapport théorie retient quatre poches accessibles à un participant lent, toutes **événementielles et conditionnelles** :

| Gap | Événement | Ce que fait le fournisseur | Horizon | Coût dominant |
|---|---|---|---|---|
| **G2** capture de spread sur carnets larges | aucun : mesure de coût (mark-outs d'ordres passifs sans signal) | poste achat et vente au touch, variante **minute** (pose à la minute, TTL 30–60 s, post-only, sans recotation) | 60 s → fill opposé ou 60 min | sélection adverse, funding si traversée |
| **G4** retour post-cascade | grappe de liquidations (seuil absolu **et** percentile) | ordre passif à la minute suivante, du côté du retour, sortie passive 60 min, **pas de stop < 3 σ** | 5–60 min | spread ×3 en stress, funding (cascade à 23:50 → photo 00:00), liquidation forcée au taux taker |
| **G5** horloge | instants connus (00/08/16 UTC, 08:00, 13:30/14:30 selon DST, minute 0) | armer à **h−5 ou h−2** (jamais h−1), vivre jusqu'à h+1, signe pré-déclaré par type | 15–60 min | funding stratifié par signe |
| **G1** prime d'immédiateté en fournisseur | excursion k·σ15 **conditionnée au stress** | échelle k σ15 ∈ {1,5 ; 2 ; 2,5} sous/sur l'EMA 15 min, **renouvelée à la minute**, post-only, sortie passive à l'EMA ou au temps 60–120 min | 60–120 min | marge gelée (≤ 4–5 ordres de 100 USDT à 1×), fills post-only perdus au croisement |

Trois propriétés communes, qui dictent l'architecture : (i) l'ordre est **au repos avant** le prix (fournisseur, pas chasseur) ;
(ii) la décision est déclenchée par un **événement** (flux, excursion, horloge), pas par un état de bougie ; (iii) le risque est
porté par la **taille**, des **bornes de perte** et un **kill switch**, pas par un stop serré — ce qui impose une comptabilité
de **marge gelée**, de **funding** et de **liquidation forcée** dans le net (règles 7 et 12 du rapport).

### 1.2 Ce que la machine fait aujourd'hui (établi sur le code) et pourquoi c'est une autre classe de processus

| Dimension | La boucle minute (code) | Ce que le mécanisme exige | Écart |
|---|---|---|---|
| **Instant de décision** | cron `*/1`, `POST /api/mtf/run`, décision à hh:mm:00 + durée du run (UNKNOWN) + Messenger `mtf_decision` + REST | réaction en secondes à un flux (G4), ordres déjà posés avant l'instant (G5), échelle vivante en continu (G1) | structurel : la cadence est celle du cron, pas celle de l'événement |
| **Déclencheur** | conditions d'**état** 5 m / 1 m (`ConditionRegistry`) ; en production les deux conditions 1 m `spread_bps_lte` / `order_flow_imbalance_gte` exigent une preuve que seul `StrictRuleEvaluator` fournit → **la boucle ne poste pas** (rapport théorie, en-tête) | détecteurs d'événements sur flux (`liquidation-orders`, `trades`, `books5`), horloge | aucun détecteur d'événement n'existe |
| **Ordre d'entrée** | un `OrderPlanModel` par symbole ; `order_mode: 1` = GTC ; `postOnly` **seulement** si `orderMode === 4` (`TradeEntry/Execution/ExchangeExecutionService.php:435`) ; `OkxActionFactory::ordType` envoie `post_only` quand `postOnly` (`Exchange/Okx/OkxActionFactory.php`) ; `limit_order_ttl_sec: 30`, `cancel_after_timeout_sec: 60` (`config/app/trade_entry.scalper_micro.yaml`) | **échelle** de 2–3 niveaux par côté, post-only obligatoire, **renouvelée** à la minute ou au déplacement de l'ancre | pas d'échelle, pas de renouvellement ; le post-only existe dans l'adaptateur (`capabilities().supportsPostOnly = true`) mais pas dans le plan |
| **Surveillance de l'ordre** | `LimitFillWatchMessage` : premier poll à 5 s (`LIMIT_WATCH_INITIAL_DELAY_MS = 5000`), puis toutes les 5 s (`POLL_DELAY_MS`), annulation après `cancelAfterSec + 10 s` (`GRACE_SECONDS`), 3 polls de confirmation ; **aucun re-post** (`TradeEntry/MessageHandler/LimitFillWatchMessageHandler.php:31-33,120,527-548`) | fill vu en < 1 s par le WS privé `orders`, re-cotation immédiate | watchers REST à 5 s ; le WS privé existe (`Exchange/Okx/PrivateWebSocket/*`) mais est un **observateur** démo, abonné `instType SWAP` (`OkxPrivateWebSocketSession.php:234-237`) |
| **Sortie** | `ProtectionEnforcer::enforceAfterEntryFill` pose un SL `conditional` reduce-only, déclencheur **mark**, exécution marché (`OkxActionFactory::algoOrder` : `slTriggerPxType = 'mark'`, `slOrdPx = -1`) ; TP via `TpSlTwoTargetsService` (ordres de clôture `postOnly:false`, programme) ; `EmergencyCloseService` au marché | sortie **passive** post-only à l'EMA 15 min ou au temps, puis taker reduce-only de secours ; stop de catastrophe loin (≥ 3 σ15), jamais serré | aucune sortie passive ; le stop est obligatoire et serré |
| **Porte d'écriture** | `OkxDemoWriteGate::evaluate` : `ENTRY` refusé si `stopLossPresent === false` (`stop_loss_required`) ; `requireStopLoss: true` dans la décision kill switch ; `PROTECTIVE` exige reduce-only | une entrée fournisseur **sans stop serré** (stop de catastrophe loin, ≥ 3 σ15) avec borne de perte par fill, marge gelée bornée, perte max/jour | pas de contradiction avec `stop_loss_required` (un stop **existe**, loin) : il faut un **nouveau** `write_kind` `lp_entry` qui garde `stop_loss_required` et ajoute `stop_distance_min` (refus d'un stop trop près), post-only obligatoire et budget de marge gelée — un durcissement, pas un assouplissement (§6.2) |
| **Taille / levier** | `leverage.timeframe_multipliers '1m': 20` (`trade_entry.scalper_micro.yaml:96-97`) multiplie taille et levier avant soumission (`ExchangeExecutionService::applyTimeframeMultiplier`, `:726-803`) ; un second bloc `timeframe_multipliers '1m': 1.2` (`:73-74`) porte sur le levier dynamique (`k_dynamic`) ; `exchange_cap 20` seulement si multiplicateur = 1 (`:93`) ; `max_loss_pct 0.4 × initial_margin_usdt 50` (`:94`, `:35`) | 1× isolé ou Selected Margin ; taille par fill fixe (≈ 50–100 USDT) ; plafond retail X-Perps 10× | levier ×20 incompatible avec X-Perps retail ; sizing par risque de stop, pas par notionnel |
| **Instrument** | `OkxInstrumentResolver::instId` : `BTCUSDT → BTC-USDT-SWAP`, remplace `_` par `-` et **lève** sur `BTC-USD_UM_XPERP-310404` ; `instId('BTCUSD')` = `BTC-USD-SWAP` = **swap inverse** (mauvais instrument) ; `instType=SWAP` : 54 occurrences / 22 fichiers (grep `SWAP` dans `src`, 2026-10-20) ; `OkxMetadataProvider.php:373,385` **refuse** tout instrument dont `ctType !== 'linear'` (README OKX : « OKX-005 only accepts `linear` SWAP instruments ») alors que le `ctType` des X-Perps est **UNKNOWN** | `instType FUTURES`, `ruleType xperp`, id par famille, `settleCcy USD`, marge USDC | aucun chemin X-Perps (`H1-XPERP-TECHNICAL-GAP.md` §3) |
| **Devise** | `OkxAccountGateway::getAccountBalance('USDT')`, ligne USDT requise ; `NetPnlCertificationService.php:174` frais en USDT ; `ModeContractValidator.php:310,330` budgets `quote_currency === 'USDT'` ; `mtf_contracts.yaml quote_currency: 'USDT'` | USDC/USD | USDT supposé partout |
| **Hôtes** | `OkxConfig` : démo `https://eea.okx.com`, live `https://www.okx.com` ; `OkxRestEndpointGuard` : liste exacte démo/live ; WS privé autorisé `wseeapap.okx.com:8443` seulement (`OkxPrivateWebSocketEndpointGuard`) | REST production EEE `eea.okx.com`, WS `wss://wseea.okx.com` (`H1-XPERP-MARKET-ACCESS.md` §2.6) | le live EEE est **refusé par conception** (voulu : §6) |
| **Données publiques** | carnet REST 20 niveaux à l'instant de décision (`TimeframeValidationService::enrichMicrostructureContext`, programme) ; klines REST | flux WS continus : `trades`, `books5`, `liquidation-orders`, `mark-price`, `funding-rate` | aucun flux public consommé en direct par la plateforme (la capture Paper en consomme deux, hors runtime) |
| **Frais internes** | `fees maker_rate 0.0005 / taker_rate 0.0005` (YAML) | 2 pb maker / 5 pb taker (interface du compte, `H1-XPERP-ACCOUNT-CHECK.md`) | modèle interne faux d'un facteur 2,5 côté maker |

**Conclusion.** Ce n'est pas un réglage : la boucle minute est un pipeline *décision d'état → ordre unique → stop → oubli*,
cadencé par un cron. Le mécanisme est un pipeline *flux → événement → échelle au repos → fills → sortie passive →
comptabilité*, cadencé par le marché. Il faut un **processus événementiel séparé**, qui réutilise les briques basses
(signature, adaptateur, gate, normaliseurs, capture) et **pas** la chaîne `MtfRunner → MtfValidator → TradeEntryService`.

---

## 2. Architecture cible : processus événementiel piloté par WebSocket

### 2.1 Vue d'ensemble

```
                 OKX EEA public WS (instType FUTURES, X-Perps)                  OKX EEA private WS
     trades · books5 · liquidation-orders · mark-price · funding-rate          orders · positions · (balance_and_position)
                              │                                                           │
                              ▼                                                           ▼
   ┌──────────────────────────────────────┐                             ┌──────────────────────────────────────┐
   │ P-COLLECT  LpPublicCollector         │                             │ P-PRIVATE  LpPrivateStateFeed        │
   │  · une connexion par groupe de canaux │                             │  · réutilise OkxPrivateWebSocketWorker│
   │  · event log ndjson + manifest sha256 │                             │    (abonnements FUTURES, pas SWAP)    │
   │  · comptage par canal × instrument    │                             │  · snapshot REST + réconciliation     │
   └───────────────┬──────────────────────┘                             └───────────────┬──────────────────────┘
                   │  LpMarketEvent (horodaté exchange + réception)                      │  LpPrivateEvent
                   ▼                                                                     ▼
   ┌──────────────────────────────────────┐                             ┌──────────────────────────────────────┐
   │ P-DETECT  détecteurs purs             │                             │ PositionLedger · OrderLedger          │
   │  · LiquidationClusterDetector         │                             │  · fills, funding, frais, ADL level   │
   │  · StressExcursionDetector (EMA15,σ15)│                             └───────────────┬──────────────────────┘
   │  · ClockWindowScheduler               │                                             │
   └───────────────┬──────────────────────┘                                             │
                   │  LpEvent                                                            │
                   ▼                                                                     ▼
   ┌──────────────────────────────────────────────────────────────────────────────────────────────────────────┐
   │ P-QUOTE  LadderManager  ◄── LadderPlanner(LpEvent, InstrumentSpec, RiskBudget) ──►  ExitPolicy            │
   │  · rungs post-only, TTL, renouvellement, annulation au croisement, pas d'amend (cancel + place)            │
   │  · sorties passives (EMA / temps) puis taker reduce-only de secours                                        │
   └───────────────┬──────────────────────────────────────────────────────────────────────────────────────────┘
                   │  LpOrderIntent (post_only | reduce_only | cancel | cancel_all)
                   ▼
   ┌──────────────────────────────────────┐   gouverne   ┌──────────────────────────────────────────────────────┐
   │ P-RISK  PortfolioRiskGovernor         │◄────────────►│ LpExecutionPort                                      │
   │  · marge gelée ≤ budget (4–5 × 100)   │              │   SimulationPort   (replay du même event log)         │
   │  · taille/fill, perte/jour, /événement│              │   FakePort         (FakeExchangeMatchingEngine)       │
   │  · kill switch, ADL ≥ seuil, funding  │              │   DemoPort         (OkxDemoGuardedExchangeAdapter +   │
   │  · quarantine file, trip              │              │                     nouveau write_kind lp_entry/lp_exit)│
   └──────────────────────────────────────┘              └──────────────────────────────────────────────────────┘
```

Invariant central : **le même journal d'événements publics** (`LpMarketEvent`) alimente le simulateur (P-SIM = P-DETECT +
P-QUOTE + `SimulationPort` sur le journal) et le direct (mêmes classes, `DemoPort`). Les détecteurs sont des **fonctions pures
du journal** : une capture rejouée produit exactement les mêmes `LpEvent` que le direct a vus. C'est ce qui rend la mesure
(Phase 1–3, §5) **antérieure et indépendante** de tout exécuteur. **Langage** (relecture CTO M6) : les détecteurs et le
simulateur ont été livrés en **Python/numpy** (`python-orchestrator/scripts/research_lp/{events,lp_sim}.py`) et les protocoles A3
figent ces paramètres Python ; les interfaces de §2.8 sont des **signatures PHP cibles**, pas une implémentation. L'invariant
« rejeu = direct » n'est donc tenu aujourd'hui que pour la **mesure** (Python contre Python). Le choix du langage de l'exécuteur
et le port vers la porte PHP sont **reportés après verdict** et décrits en §2.10 ; aucun exécuteur n'est construit avant.

### 2.2 Flux publics : canaux, cadence, statut de vérification

| Canal (WS public v5) | `instType` | Cadence documentée | Usage dans l'exécuteur | Statut |
|---|---|---|---|---|
| `trades` | par `instId` | par transaction | prints : σ15, EMA15 sur mid ou last, règle de fill `through`/`touch` du simulateur, mark-outs | établi (utilisé par la capture Paper et `forward_okx.py` sur SWAP) ; couverture X-Perps **UNKNOWN** |
| `books5` | par `instId` | « Snapshot data will be pushed every 100 ms when there are changes in the 5 depth levels snapshot » — **établi** par le desk sur la docs-v5 téléchargée et hachée (`LP-DATA-DESK` §1.3 ; la page rendue par l'outil de lecture s'arrêtait avant les canaux Market Data, d'où le « non re-vérifié » de la passe précédente) ; colonnes `[prix, quantité, "0", nombre d'ordres]` ; « `books5` and `bbo-tbt` channels do not carry a `checksum` field » (changelog 2026-06-23) | top of book + profondeur 5 niveaux : file d'attente du simulateur (`queue`), garde de spread, prix d'ancrage ; le nombre d'ordres par niveau est à capturer (quant B5, `LP-G2` P-G2-4 v) | établi pour SWAP ; X-Perps **UNKNOWN** (P3) |
| `bbo-tbt` | par `instId` | « every 10 ms when there are changes » — **établi** par le desk (`LP-DATA-DESK` §1.3) ; « available to users of any trading fee tier » (changelog 2026-03-02) ; la variante **SBE** « requires login starting 3rd March 2026 » (même changelog) | source de `touch`/`through` et de `m_0` pour G2 (desk §6 ; `LP-G2` §5.3) ; **à ajouter au collecteur** (`LP-G2` P-G2-4 vi) ; `forward_okx.py` l'utilise déjà sur SWAP | établi pour SWAP ; X-Perps **UNKNOWN** (P3) |
| `liquidation-orders` | `SWAP`, `FUTURES`, `MARGIN`, `OPTION` — **établi** par le desk sur la docs-v5 téléchargée et hachée (`LP-DATA-DESK-2026-10-20.md` §1.3 ; quant m1) ; champs `details[]` `side`, `posSide`, `bkPx` (« Liquidation mark price »), `sz` en contrats pour FUTURES/SWAP, `bkLoss`, `ts` | par événement ; « not necessarily in chronological order » ; REST historique retiré (changelog 2023-04-03) | **G4** : cascades de liquidations (seuil relatif à la paire, `LP-G4` §3.3) ; notionnel = `sz × ctVal × bkPx` | établi ; disclaimer « This data doesn't represent the total number of liquidations on OKX » **établi** ; seule la **part des X-Perps** dans le flux reste UNKNOWN (Phase 1) |
| `mark-price` | par `instId` | par changement | distance au mark (liquidation, stop de catastrophe au mark), bandes de prix | établi (changelog 2026-04-15, relu : « Mark price channel » listé parmi les canaux market data) ; cadence de push non re-vérifiée ; X-Perps UNKNOWN |
| `funding-rate` | par `instId` | par changement | **F dans le net** : signe et niveau publiés avant la photo, décision d'armer ou non (G5) ; « Funding rate endpoints now support querying X-Perps futures contracts », `instType` enum `FUTURES` = « X-Perps futures » (changelog 2026-03-31, relu) | établi au REST **et** au canal WS (changelog 2026-03-31 : « Funding rate channel » inclus ; `instType` « FUTURES: X-Perps futures », desk §1.3 ; quant m2) ; cadence « in 30s to 90s » |
| `instruments` | `FUTURES` | par changement | `InstrumentSpec` à chaud (`state`, `expTime`, `ctType`, bandes `initPxLmtPct` « Initial price-limit band applied during the first 10 minutes after contract listing » / `floatPxLmtPct` « Floating price-limit band during normal trading » / `maxPxLmtPct` écart maximal à l'indice ; `ruleType` ∈ normal/pre_market/rebase_contract/xperp ; `ctType` ∈ linear/inverse — champs relus docs-v5 2026-10-20, tranche 0–100 k), détection de délisting (M3) | établi (REST) ; cadence du canal WS non re-vérifiée |
| `adl-warning` | — | « will stop pushing data in normal state » (changelog 2026-06-09) | filtre défensif : ne pas poster en état non normal du fonds | existence établie ; sémantique X-Perps UNKNOWN |

Hôtes : démo `wss://wseeapap.okx.com:8443/ws/v5/public` (`OkxConfig`) ; production EEE `wss://wseea.okx.com/ws/v5/public`
(forme **documentée** par la docs EEE, sans port — `LP-DATA-DESK` §1.1 ; alias du collecteur aligné, CTO m1 fait ; hôte servant
effectivement les X-Perps et acceptation d'un port : **UNKNOWN**, établis au comptage). Limites WS (docs-v5, tranche 0–100 k, relues le 2026-10-20) : « Connection limit: 3 requests
per second (based on IP) » ; « The total number of 'subscribe'/'unsubscribe'/'login' requests per connection is limited to 480
times per hour ». Limite d'ordres : « At the sub-account level, we allow a maximum of 1000 order requests per 2 seconds » ;
« Rate limits for placing orders, amending orders, and cancelling orders are independent from each other », définies **par
`instId`** (la valeur par `instId` n'a pas été relue). Licence : OKX API Agreement §9.4 (« Last Updated: 28 July 2026 »,
extraits relus en §7) à faire lire au propriétaire **avant** toute capture longue (P2).

### 2.3 Détecteurs d'événements (fonctions pures du journal)

Tous les détecteurs consomment des `LpMarketEvent` **dans l'ordre d'horodatage exchange**, maintiennent un état borné par
instrument et émettent des `LpEvent` horodatés. Aucun détecteur ne lit l'heure système : en direct, l'horloge est celle des
messages ; en simulation, celle du journal. Les seuils sont des **paramètres du protocole pré-enregistré** (a3), jamais des
constantes du code.

**`LiquidationClusterDetector` (G4).**
- Entrées : `liquidation-orders` (notionnel = `sz × ctVal × bkPx`, côté `posSide`), `books5` (profondeur cumulée 5 niveaux),
  `mark-price`.
- État : fenêtre glissante de 5 min par instrument et par côté ; distribution glissante 7 j des notionnels de fenêtre (pour
  le percentile), amorcée uniquement par la capture (pas de valeur a priori).
- Règle (paramètres du protocole `LP-G4` §3.3, révisés par la relecture quant B2) : événement si `notional_5m` est une
  **cascade relative à la paire** — ≥ p99,5 glissant **et** ≥ k × médiane des fenêtres non nulles 7 j **et** ≥ x % du notionnel
  échangé 24 h — au-dessus d'un plancher de bruit absolu ; `intensity = ln(notional_5m / médiane non nulle 7 j)` (sans
  dimension ; `notional_5m / depth_top5` rapporté) ; instant de l'événement = **horodatage du dernier ordre liquidé** de la fenêtre
  (`liquidation-orders.ts`), pas la fin de la fenêtre alignée (quant M4) ; version **continue** exportée (intensité et amplitude à
  chaque fenêtre) pour les contrôles du protocole (continu avec amplitude en continu, apparié au prix) ; un `side` inconnu compte
  dans le notionnel total, pas dans le signé (CTO m7).
- Sortie : `LpEvent{kind: liquidation_cluster, instrument, side_liquidated, last_liq_ts, bin_end_ts, intensity, notional, depth}`.
- Garde : si la couverture du canal pour `FUTURES` est partielle (UNKNOWN), le détecteur **ne compense pas** : le comptage
  (Phase 1) décide si G4 est mesurable sur OKX ou doit passer par le Gate 1 historique Hyperliquid (rapport, décision 8).

**`StressExcursionDetector` (G1, condition de G2/G4).**
- Entrées : mid de `books5` (ou last de `trades` si le carnet manque), `funding-rate`.
- État : EMA 15 min du mid, σ15 (écart-type des rendements du mid sur 15 min, temps calendaire), volatilité réalisée 24 h,
  médiane glissante 30 j de cette volatilité (**30 jours de capture nécessaires** avant que la condition de stress soit
  définie ; en attendant, la condition est **absente**, pas approximée), spread courant et tranche de spread, heure UTC.
- Règles : `stress_state ∈ {calm, stress}` = `rv_24h > median_30d` (variantes déclarées : tranche de spread, heures US) ;
  `excursion` = `|mid − EMA15| ≥ k·σ15` pour chaque k de l'échelle ; les deux sont émis séparément pour que le protocole
  calcule **stress − calme**.
- Sortie : `LpEvent{kind: stress_state|excursion, instrument, ts, ema15, sigma15, k, side}`.

**`ClockWindowScheduler` (G5).**
- Entrées : horloge des messages (pas l'horloge système), calendrier DST US, `funding-rate` (`nextFundingTime`, F publié à h−1),
  `instruments` (intervalle de funding par contrat : 8 h sur les majors, 4 h sur certains nouveaux tokens — note d'accès).
- Règles : fenêtres `arm_at = h − 5 min` ou `h − 2 min` (**jamais h−1**, minute 0 toxique), `live_until = h + 1 min` pour
  l'armement ; **la décision de poser est prise à `arm_at`** et l'ordre vit jusqu'à `live_until` (TTL = `live_until − arm_at`) :
  le simulateur fait la même chose depuis la correction CTO M2 (passe `a5-corrections-cto`, vérifiée le 2026-10-21) —
  `lp_sim.event_decisions` décide à `arm_ts_us` quand `arm_ts_us < ts_us` et porte `ttl_us = end_ts_us − arm_ts_us` par intention
  (`INTENT_DTYPE`, `ORDER_DTYPE`) ; un événement **détecté** (grappe G4, excursion G1 : `arm_ts_us = ts_us`) est décidé à la minute
  suivant `ts_us` avec la TTL globale ; test `test_scheduled_clock_windows_are_decided_at_the_arming_instant_and_rest_until_the_window_end`
  (fenêtre 08:00 ⇒ décision 07:55:00, TTL 360 s ; le défaut reproduit par la relecture — 08:01:00 — est conservé comme cas de
  non-régression de l'appel hérité) ; les heures de funding sont **lues par instrument** dans la tape (`events.funding_times_from_tape`
  sur `fundingTime` / `nextFundingTime`, `funding_source: tape`), jamais fixées à 00/08/16 pour tous (CTO m5 fait ; repli
  `--funding-hours` déclaré `funding_source: fixed` quand la tape n'a aucune ligne de funding) ; types : `funding_<h>`, `session_0800`,
  `us_open_1330|1430`, `minute_0` ; le **signe** par type est fourni par le protocole, pas par le détecteur.
- Sortie : `LpEvent{kind: clock_window, type, arm_at, h, live_until, published_funding_sign}`.

**Invariants de détection** : (i) déterminisme sur le journal ; (ii) aucune donnée extérieure au journal ; (iii) un détecteur
désactivé émet `absent`, jamais une valeur par défaut ; (iv) toute sortie porte `source_event_ids` (traçabilité jusqu'aux
messages bruts) ; (v) les seuils et fenêtres sont hachés dans le pré-enregistrement.

### 2.4 Échelle d'ordres post-only renouvelée (`LadderManager`)

```
LadderPlan = { instrument, anchor: ema15 | mid | event_price, side(s), rungs: [{k, price, size_contracts}],
               post_only: true, rung_ttl_s: 30..60, renew: at_minute | on_anchor_move(ticks), max_live_rungs,
               reason: LpEvent.id, risk_budget_id }

Cycle de vie d'un rung
  PLANNED ──place(post_only)──► POSTED ──ack(orders: live)──► LIVE ──fill──► FILLED ──► ExitPolicy
     │                             │                           │ partial ──► PARTIAL (reste LIVE)
     │                             └─ rejected ──► REJECTED    ├─ ttl ──► EXPIRED ──► (renew ? PLANNED : done)
     └─ risk refused ──► SKIPPED                               └─ would cross ──► CANCELLED_CROSS (post-only annulé par OKX)
                                                                                  └─► (renew ? PLANNED : done)
```

Règles d'exécution (toutes mesurables en simulation) :
- **Post-only obligatoire** (`ordType = post_only`) : un post-only qui s'exécuterait à la pose est **annulé** par OKX (docs-v5
  « Place order », relu le 2026-10-20 : « Post-only order, which the order can only provide liquidity to the market and be a
  maker », annulé sinon ; même lecture dans l'aide « Basic order types » citée par la relecture exécution) ; le changelog du
  2026-08-20 (relu) précise que « The `state: live` event is pushed only after the order has successfully entered the order
  book » — l'ack `live` du canal `orders` est donc la preuve du repos. Le simulateur modélise cette annulation (le moteur Fake
  le fait déjà : `post_only_would_cross`, `Exchange/Fake/FakeExchangeMatchingEngine.php:219`).
- **Pas d'amendement** : `OkxExchangeAdapter::capabilities()` déclare `supportsModifyOrder: false` ; renouveler = annuler puis
  poster (deux requêtes, deux limites de taux indépendantes ; budget tenu par le gouverneur).
- **Variante « minute »** (G2) : pose à hh:mm:ss + durée du cycle, TTL 30–60 s, **sans recotation** — c'est la variante qui
  mesure le coût de la lenteur ; la variante « recoteur 5 s » est la référence Part A.
- **Échelle G1** : k σ15 ∈ {1,5 ; 2,0 ; 2,5} de chaque côté de l'EMA 15 min, renouvelée **à la minute** ; taille fixe par rung
  (notionnel ≈ 50–100 USDT → contrats entiers via `ctVal`, `minSz = lotSz = 1`).
- **Deux côtés** seulement si le mode de position du compte le permet (le guide X-Perps, màj 2026-10-20, relu, décrit « One-way
  mode » et « Hedge mode » ; le `posMode` du compte via `GET /api/v5/account/config` reste **UNKNOWN**, P2) ; en mode one-way,
  un seul côté par instrument à la fois.
- **Bandes de prix** : un rung hors `maxPxLmtPct` (écart à l'indice) est **non posté** (`SKIPPED`, raison tracée).
- **Plafond global de rungs vivants** = budget de marge gelée (§2.6), toutes paires confondues.
- **Après un saut du meneur** (G9 défensif) : annulation des rungs au repos si BTC a bougé de plus de x σ dans la minute
  (mesuré gratuitement si BTC est enregistré).

### 2.5 Sorties passives (`ExitPolicy`)

- **`ema_passive`** : ordre reduce-only **post-only** à l'EMA 15 min (côté opposé au fill), recoté à chaque minute quand l'EMA
  bouge de plus d'un tick ; **`time_passive`** : à T = 60–120 min, ordre reduce-only post-only au touch, grâce 60 s, puis
  **taker reduce-only** de secours (coût compté 5 pb + spread).
- **Stop de catastrophe** : `conditional` reduce-only au **mark** (comme aujourd'hui, `slTriggerPxType = 'mark'`), à ≥ 3 σ15
  ou à la distance correspondant à la perte maximale par fill, **jamais plus près** ; son prix est **déclaré dès l'entrée** dans
  `metadata['stop_loss_price']` (ce que `OkxDemoGuardedExchangeAdapter::stopLossPresent()` lit déjà, `:241-247`) pour satisfaire
  `stop_loss_required`, et il est **posé après fill** par le chemin existant `ProtectionEnforcer::enforceAfterEntryFill` (kind
  `protective`). Ce n'est pas la gestion du risque, c'est la queue (relecture CTO M5 : la règle 12 dit « pas de stop **serré** »,
  pas « pas de stop »).
- **Funding dans le net** : si la position traverse une photo, le `FundingLedger` impute F × notionnel du signe réel (bills
  privés `/api/v5/account/bills` ou `positions.fundingFee` = « Accumulated funding fee », canal privé `positions`, docs-v5 relu
  le 2026-10-20 — ingestion à écrire, aucune n'existe : `H1-XPERP-TECHNICAL-GAP.md` §2 « Funding »).
- **Liquidation forcée** : si elle survient (1× isolé, ≈ +100 % de mouvement ; retail à protection de solde négatif), le coût
  est facturé au taux **taker** (FAQ frais EEE, màj 2026-10-07, relue : « Forced liquidation fees are charged according to the
  taker fee rate of your current level ») et compté comme perte de queue de la règle.

### 2.6 Gestion du risque de portefeuille (`PortfolioRiskGovernor`)

| Contrôle | Règle (paramètres pré-déclarés par protocole) | Source d'état |
|---|---|---|
| **Taille par fill** | notionnel fixe par rung (≈ 50–100 USDT), contrats entiers | `InstrumentSpec.contract_value`, mark |
| **Marge gelée** | Σ notionnel des rungs vivants + positions ouvertes ≤ budget (à 1× : 4–5 ordres de 100 USDT sur 500 USDT, programme) ; règle de gel de marge des ordres au repos sur X-Perps **UNKNOWN** (P2) — en attendant, hypothèse conservatrice : 100 % du notionnel gelé | `OrderLedger`, `PositionLedger` |
| **Positions simultanées** | ≤ N (déclaré) ; une par instrument et côté | `PositionLedger` |
| **Perte par événement** | borne en USD par `LpEvent` (toutes jambes) | `FillCostLedger` |
| **Perte max/jour** | réalisé + latent, UTC, déclaré (ordre de grandeur de la plateforme : `daily_max_loss_usdt` dans `trade_entry.*.yaml`, en USDT → à porter en USDC) ; atteinte ⇒ `kill_switch` | ledgers |
| **Kill switch** | arrêt des posts + `cancel_all` (protectif) + option « flatten » taker reduce-only ; **trip durable** par fichier (modèle `FilesystemOkxDemoTrip`, §6) ; levée manuelle | — |
| **ADL awareness** | `positions.adl` 0–5 (REST, relu : « Auto-Deleveraging (ADL) indicator. Range: 0–5, where 0 = lowest ADL priority » ; WS privé `positions`, relu : « Divided into 6 levels, from 0 to 5, the smaller the number, the weaker the adl intensity ») ; aide ADL (relue) : « Positions in the ADL queue are ranked based on the leverage PnL% », « positions with the highest positive leverage PnL% will be offsetted first », fermeture « at the mark price at the time of matching » (prix de faillite si le fonds est quasi épuisé), « Trading fees are not charged on deleveraged positions », « no warnings are issued prior to ADL occurring » (hors canal API et e-mail après coup), indicateur « 1 to 5 bars » ⇒ à `adl ≥ seuil` : ne plus ajouter, réduire si gagnant ; `adl-warning` non `normal` ⇒ ne pas poster | WS privé `positions`, `adl-warning` |
| **Funding** | avant d'armer dans [h−5 ; h+1] : si F publié est adverse au côté et > seuil, ne pas armer (ou armer l'autre côté seulement si le protocole le déclare) ; comptabilité ex post | `funding-rate`, bills |
| **Liquidation** | distance au mark ≥ x % ; 1× isolé ⇒ ≈ 100 % ; en Selected Margin la perte d'une position puise dans le pool commun (page Selected Margin, màj 2026-09-03, relue : « a loss on one position draws on the same collateral as all your others ») ⇒ préférer **isolé** pour le track L | `mark-price`, `positions` |
| **Budget de requêtes** | compteur glissant place/cancel par `instId` et par sous-compte (1 000 / 2 s) ; refus local avant 50061 | — |
| **Bandes de prix** | `initPxLmtPct` (10 premières minutes après cotation), `floatPxLmtPct`, `maxPxLmtPct` (docs-v5) | `instruments` |

Deux lectures obligatoires de tout résultat (règle 7 du rapport) : **simulation sans contrainte de capital** (puissance) et
**portefeuille 500 USDT** (exploitabilité) — le gouverneur est donc paramétrable en « illimité » pour la première lecture et le
simulateur journalise les rungs **refusés pour marge** afin de chiffrer l'écart entre les deux.

### 2.7 Modes d'exécution

| Mode | Port | Données | Drapeaux | Qui décide |
|---|---|---|---|---|
| **Paper (recherche)** — d'abord | `SimulationPort` sur le journal capturé ; `FakePort` pour les tests d'intégration du `LadderManager` | capture publique X-Perps (Phase 2) | aucun ; aucune clé | protocole pré-enregistré (a3) |
| **Démo** — ensuite, sur verdict | `DemoPort` = `OkxDemoGuardedExchangeAdapter` avec les nouveaux `write_kind` (§6) | WS public + privé démo (`wseeapap.okx.com:8443`) ; **X-Perps en démo : UNKNOWN** | `OKX_ENV=demo`, `OKX_SIMULATED_TRADING=1`, `OKX_DEMO_TRADING_ENABLED=1`, `DEMO_TRADING_ENABLED=1`, `OKX_LIVE_ENABLED=0` — **propriétaire seulement** ; le code de recherche ne les lit ni ne les écrit | propriétaire, décision séparée |
| **Live** | — | — | — | **hors programme** ; `OkxRestEndpointGuard` refuse `eea.okx.com` en live et doit le rester |

### 2.8 Interfaces (signatures seulement, pas d'implémentation)

```php
// Journal d'événements publics (collecteur et simulateur partagent le même type)
interface LpMarketEventSourceInterface {
    /** @return iterable<LpMarketEvent> ordered by exchange timestamp, then sequence */
    public function stream(LpInstrumentSet $instruments, ?LpCursor $from = null): iterable;
}
final readonly class LpMarketEvent {   // enveloppe = PaperMarketEvent v2 (schema, event_id, venue, symbol, channel,
    // exchange_timestamp, received_timestamp, sequence, payload, payload_hash) + channel ∈ {public_trade, book5,
    // liquidation_order, mark_price, funding_rate, instrument_metadata, connection_state, snapshot_boundary}
}

// Détecteurs purs
interface LpEventDetectorInterface {
    public function kind(): LpEventKind;                          // liquidation_cluster | stress_state | excursion | clock_window
    public function onMarketEvent(LpMarketEvent $event): iterable; // yields LpEvent, no clock, no I/O
    public function stateDigest(): string;                        // sha256 of bounded state, for replay equality tests
}
final readonly class LpEvent { /* kind, instrument_id, ts, side, params(k, sigma15, ema15, intensity…), source_event_ids */ }

// Échelle
interface LadderPlannerInterface {
    public function plan(LpEvent $event, InstrumentSpec $spec, LpRiskBudget $budget, LpProtocolParams $params): ?LadderPlan;
}
interface LadderManagerInterface {
    public function open(LadderPlan $plan): LadderHandle;
    public function onPrivateEvent(LpPrivateEvent $event): void;  // orders/positions → rung state machine
    public function onMarketEvent(LpMarketEvent $event): void;    // anchor moves, TTL, renewal, leader jump
    public function cancelAll(string $reason): void;               // protective
}
interface ExitPolicyInterface {
    public function onFill(LpFill $fill, LpMarketState $state): iterable;   // yields LpOrderIntent (reduce_only post_only)
    public function onMarketEvent(LpMarketEvent $event): iterable;         // requote EMA, time exit, fallback taker
}

// Risque
interface PortfolioRiskGovernorInterface {
    public function authorize(LpOrderIntent $intent, LpPortfolioState $state): LpRiskDecision; // allowed | refused(reasons)
    public function onFill(LpFill $fill): void;
    public function onFunding(LpFundingSettlement $settlement): void;
    public function onAdlSignal(string $instrumentId, int $adlLevel): void;
    public function tripped(): bool;                 // kill switch (durable file + in-memory)
    public function trip(string $reason): void;
}

// Port d'exécution interchangeable
interface LpExecutionPortInterface {
    public function placePostOnly(LpOrderIntent $intent): LpOrderAck;     // ordType=post_only, reduceOnly=false
    public function placeReduceOnly(LpOrderIntent $intent): LpOrderAck;   // post_only or taker, reduceOnly=true
    public function cancel(LpOrderRef $ref, string $reason): LpCancelAck;
    public function cancelAll(string $instrumentId, string $reason): LpCancelAck;
    public function capabilities(): ExchangeCapabilities;                 // existing DTO
}
// Implémentations : SimulationPort (journal + modèle de fill touch/through + latence + annulation au croisement),
// FakePort (FakeExchangeMatchingEngine), DemoPort (OkxDemoGuardedExchangeAdapter via nouveaux write kinds).

// Instrument
interface InstrumentSpecResolverInterface {
    public function resolve(Exchange $exchange, ProductKind $product, string $symbol): InstrumentSpec; // never guesses
    public function byInstrumentId(string $instId): InstrumentSpec;
}

// Comptabilité
interface FundingLedgerInterface { public function record(LpFundingSettlement $s): void; public function netFor(LpPositionRef $p): string; }
interface FillCostLedgerInterface { /* fees by role, spread paid, slippage, funding, forced-liquidation fee at taker rate */ }
```

### 2.9 Séquence type : grappe de liquidations (G4)

```
t0        liquidation-orders (FUTURES) ─► LiquidationClusterDetector : notional_5m ≥ abs ∧ ≥ p99 → LpEvent(liquidation_cluster, side=long_liquidated, peak t0)
t0+1 min  LadderPlanner : plan = {anchor: mid, side: buy (retour), rungs k∈{1.5,2.0}·σ15 under mid, size 100 USDT, ttl 60 s, renew at_minute}
          PortfolioRiskGovernor.authorize : frozen_margin ok ? daily_loss ok ? adl<seuil ? funding window ? → allowed
          LpExecutionPort.placePostOnly ×2 → orders channel: live
t0+1..61  books5/trades : si croisement → CANCELLED_CROSS → renew ; si fill → FILLED → ExitPolicy.onFill
          ExitPolicy : reduce_only post_only à EMA15 (recoté par minute) ; time exit à +60 min
t0+60 min time_passive → touch, grâce 60 s → taker reduce_only de secours ; FundingLedger si photo traversée
          Mesures : mark-out mid à 5/15/60 min après le dernier fill liquidé, net = PnL − frais rôle réel − funding − (liquidation forcée au taux taker si survenue)
```

### 2.10 Langage de l'exécuteur et port vers la porte PHP (relecture CTO M6)

**Constat.** §2.1 et §2.8 décrivent l'invariant « rejeu = direct » avec des interfaces PHP ; A2 a livré détecteurs et simulateur
en Python/numpy (`research_lp/events.py`, `lp_sim.py`), et les protocoles A3 figent ces paramètres Python (digest
`cli.code_identity()`). Trois issues, dont aucune n'était chiffrée :

| Option | Description | Ce qu'il faut prouver | Effort |
|---|---|---|---|
| **(a) exécuteur Python + port vers PHP** | détecteurs, `LadderPlanner`, `ExitPolicy` et gouverneur en Python (le code gelé) ; **toute écriture** passe par un **port d'exécution** vers PHP — commande interne ou HTTP local — qui est le **seul** détenteur de `OkxDemoGuardedExchangeAdapter`, du kill switch, de la quarantaine et des clés ; chaque intention porte la **preuve HMAC** de readiness (§6.2) émise par une commande opérateur | aucun adaptateur OKX en Python (ce serait **dupliquer la porte** : le contournement à ne jamais construire) ; le Python ne monte jamais de clé ; test d'intégration port ↔ gate sur `FakePort` | **L** (port + preuve + réconciliation d'état du WS privé PHP vers Python) |
| **(b) exécuteur PHP** | réécriture des détecteurs et du modèle de fill en PHP (signatures §2.8) | **équivalence numérique** Python ↔ PHP sur un journal synthétique (EMA, σ15 décalée d'une minute, percentiles, sommes `fsum`, règles de fill, funding) avant tout usage ; le protocole gelé reste la référence Python | **L** |
| **(c) aucun exécuteur avant verdict** | la mesure (Phases 1–3) ne dépend d'aucun exécuteur ; le choix (a)/(b) est la **première tâche** de la Phase 4 | rien maintenant | 0 |

**Décision proposée** : **(c) maintenant** — c'est déjà la séquence de §5 — puis **(a) si un verdict passe** : la porte démo PHP
reste l'unique chemin d'écriture, le Python ne porte jamais de clé, et l'invariant « rejeu = direct » est tenu par construction
(mêmes modules Python en simulation et en direct, port d'exécution interchangeable `SimulationPort` / `FakePort` /
`DemoPort-over-PHP`). Les signatures PHP de §2.8 sont alors celles du **port** (côté PHP : `LpExecutionPortInterface` et la part
de `PortfolioRiskGovernorInterface` qui gouverne l'écriture) ; les détecteurs et l'échelle restent Python. L'option (b) n'est
retenue que si le propriétaire exige un runtime PHP unique ; elle ajoute le test d'équivalence au coût. Dans les deux cas, **rien
n'est construit avant un verdict et une décision écrite** (§5).

---

## 3. Modèle d'instrument X-Perp et jambe spot (track S)

### 3.1 `InstrumentSpec` (compatibilité §3) instancié pour un X-Perp crypto

| Champ | Valeur / règle | Source | Statut |
|---|---|---|---|
| `venue` / `jurisdiction_entity` | `okx-eea` / OKX Europe Markets Ltd (MFSA) | `H1-XPERP-MARKET-ACCESS.md` §1 ; page specs (OEM) | établi |
| `product` | `xperp` (`instType FUTURES`, `ruleType xperp` : « perpetual-style futures, only applicable to certain FUTURES contracts », changelog 2026-03-31) | docs-v5 changelog | établi |
| `instrument_id` / `underlying` | `BTC-USD_UM_XPERP-310404` ; famille `BTC-USD_UM_XPERP` ; sous-jacent `BTC-USD` | `H1-XPERP-TECHNICAL-GAP.md` §1 | établi |
| `settlement_ccy` / `margin_ccys` | `settleCcy` **USD** (API) ; marge et solde **USDC** à l'interface (2026-10-15) ; « settled in USD-equivalent currencies such as USDC, USDG, or USD » (guide X-Perps, màj 2026-10-20, relu) ; page specs (relue) : « cash settled », devise non nommée ; USDT **non** mentionné par le guide et **non** accepté à l'interface (`H1-XPERP-ACCOUNT-CHECK.md`) | notes H1 ; guide ; specs | établi ; devise de règlement du sous-compte **UNKNOWN** (USD par défaut, à fixer USDC : rapport structurel §4.1 point 10) |
| `contract_value` | `ctVal` **0,0001 BTC** (API, `minSz = lotSz = 1`) **vs** « 0.01 BTC » (page specs, màj 19 octobre 2026, relue ; ETH « 0.1 ETH », SOL « 1 SOL ») : **facteur 100 à trancher par lecture API** avant tout sizing | rapport structurel §4.1 point 7 ; page specs | conflit documenté |
| `contract_type` (`ctType`) | `linear` ou `inverse` (docs-v5 `instruments`, « only applicable to FUTURES/SWAP », relu) ; valeur pour les X-Perps **UNKNOWN** ; `OkxMetadataProvider.php:373,385` refuse aujourd'hui tout `ctType !== 'linear'` | docs-v5 ; code | **UNKNOWN** — à lire au canal `instruments` (Phase 1) avant tout sizing |
| `lot` / `min_size` / `tick` | 1 / 1 / 0,1 (BTC) | API (notes) ; page specs | établi |
| `expiry` | « 2031/04/04 16:00 HK time » (page specs, relue) ; guide (relu) : « have a defined future expiration date », « approximately 5 year into the future » ; politique déclarée : **flatten ≥ 30 j avant `expTime`** ; détection de délisting via `instruments.state` (M3) | page specs ; `OkxPublicReadMapper` lit déjà `expTime` → `expireTimestamp`/`delistTime` | établi |
| `funding_interval` / `funding_cap` | page specs (relue) : « 8 hours » ; guide (relu) : collecte « 12:00 am, 8:00 am, 4:00 pm UTC », « you may pay or receive a funding fee at different intervals » ; cap ±0,375 % BTC, 4 h et ±1 % sur certains nouveaux tokens, raccourcissement au plafond : `H1-XPERP-MARKET-ACCESS.md` §2.8 (champs API, programme) — **aucun plafond chiffré sur les deux pages d'aide relues** | page specs ; guide ; note d'accès | établi (programme pour les plafonds) |
| `fees_maker` / `fees_taker` | 2 pb / 5 pb (interface du compte) ; la FAQ frais EEE (màj 2026-10-07, relue) ne les donne qu'en **exemple de calcul** (« Maker fee: 0.02%; Taker fee: 0.05% »), pas en barème ; via API `/account/trade-fee` (groupe « XPERP ») : UNKNOWN | `H1-XPERP-ACCOUNT-CHECK.md` ; FAQ EEE | établi à l'interface |
| `max_leverage_retail` | « Up to 10x » | page specs | établi |
| `price_limits` | `initPxLmtPct` (10 premières minutes), `floatPxLmtPct`, `maxPxLmtPct` (plafond dur d'écart à l'indice) | docs-v5 `instruments` (2026-10-20) | établi |
| `position_mode` | « One-way mode » / « Hedge mode » (guide X-Perps, relu) ; `posMode` du compte **UNKNOWN** | guide ; P2 | UNKNOWN |
| `eligibility_status` | M1 conditionnel (compte vérifié à l'interface, API non vérifiée) | `RESEARCH-ELIGIBILITY-GATES.md` | établi |
| `data_sources` | capture publique propre (Phase 2) ; licence §9.4 à décider | — | UNKNOWN (décision) |

### 3.2 Résolveur et dimension produit

Choix proposé (le moins disruptif, cohérent avec `H1-XPERP-TECHNICAL-GAP.md` §2 et la table §4) : **ne pas** ajouter de valeur à
`MarketType` (l'X-Perp est ancré par le funding, pas par un coût de portage ; `MarketType::PERPETUAL` reste vrai pour tout le
runtime), mais ajouter une **dimension produit** au bundle OKX :

```
enum ProductKind { usdt_swap, xperp, spot }

final readonly class OkxProductProfile {        // injecté dans gateways, adaptateur, normaliseurs, WS privé, resolver ctVal
    ProductKind $kind;                         // usdt_swap | xperp | spot
    string $instType;                          // SWAP | FUTURES | SPOT
    ?string $ruleType;                         // null | xperp | null
    string $instrumentIdPattern;               // '-SWAP$' | '_UM_XPERP-\d{6}$' | '^[A-Z0-9]+-(USDC|EUR)$'
    string $settleCurrency;                    // USDT | USD (API) | —
    string $marginCurrency;                    // USDT | USDC | —
    HostProfile $hosts;                        // demo eea / live refused (unchanged)
}

interface OkxInstrumentResolverInterface {
    public function instId(string $symbol): string;     // xperp : via la carte instruments (instFamily → instId live, state=live,
                                                        // ruleType=xperp) ; lève 'okx_xperp_instrument_unresolved' sinon ;
                                                        // REFUSE toute construction par chaîne (BTCUSD → BTC-USD-SWAP = inverse)
    public function symbol(string $instId): string;     // BTC-USD_UM_XPERP-310404 → BTCUSD-XPERP (symbole interne distinct de BTCUSDT)
}
```

Règles : (i) la carte `instFamily → instId` est chargée depuis `/api/v5/public/instruments?instType=FUTURES` et rafraîchie par
le canal `instruments` ; (ii) un symbole interne X-Perp porte un suffixe distinct (`-XPERP`) pour que **jamais** un résultat
SWAP ne soit relabellisé X-Perp (compatibilité §3 : « Moving it to another spec is a new validation ») ; (iii)
`OkxContractValueResolver::load` et `OkxMetadataProvider` prennent `instType` et le `ctType` attendu du profil (le refus
« `linear` seulement » de `OkxMetadataProvider.php:373,385` devient une règle du profil, lue de l'API, jamais supposée) ;
(iv) `OkxExchangeEventNormalizer` (`:727-745`) et `OkxLifecycleNormalizer` (`:601-620`) acceptent `instType ∈ {SWAP, FUTURES}`
**et** le motif d'id du profil,
au lieu de `str_ends_with(-SWAP)` ; (v) `OkxPrivateWebSocketSession` s'abonne avec l'`instType` du profil ; (vi) le sizing
reste `qty × ctVal × price` (`OkxDemoGuardedExchangeAdapter::notional`) avec `ctVal` lu de l'API (jamais de la page d'aide).

### 3.3 Devise de règlement et de marge

Points durs à paramétrer (établis) : `OkxAccountGateway.php:34,38` (ligne USDT, `getAccountBalance('USDT')`) ;
`Trading/Pnl/NetPnlCertificationService.php:174` (`feeCurrency !== 'USDT'` ⇒ rejet) ; `TradingCore/Mode/ModeContractValidator.php:310,330`
(`quote_currency !== 'USDT'`) ; `Provider/Repository/ContractRepository.php:420` (`quote_currency` défaut USDT) ;
`config/app/mtf_contracts.yaml:10`. Règle proposée : `settleCurrency` et `marginCurrency` portés par `OkxProductProfile` ;
**aucune conversion implicite** USD↔USDC↔USDT : un montant dans une devise non attendue produit un drapeau de qualité
(`funding_currency_not_normalized` existe déjà dans `FillCostLedgerIngestionService`, programme), pas un zéro. La conversion
USDT → USDC du capital est **manuelle, une fois** (note d'accès §2.10).

### 3.4 Hôtes et portes d'endpoint (inchangés pour le live)

`OkxRestEndpointGuard` autorise `https://eea.okx.com` **en démo** et `https://www.okx.com` **en live** : le live EEE est refusé
par conception (`H1-XPERP-TECHNICAL-GAP.md` §3.5) et **doit le rester dans ce programme**. Le collecteur public (Phase 1–2) n'a
pas besoin de la porte (aucune clé) mais lit `eea.okx.com` / `wss://wseea.okx.com/ws/v5/public` (forme documentée par la docs
EEE, sans port — `LP-DATA-DESK` §1.1 ; alias du collecteur aligné, CTO m1) : le service effectif des X-Perps par cet hôte reste
**UNKNOWN**, établi par le comptage (Phase 1, `--host eea` d'abord). La capture Paper actuelle refuse tout autre hôte que `www.okx.com`
(`Trading/Paper/Okx/OkxPaperPublicConfig.php`) : elle n'est **pas** le collecteur X-Perps.

### 3.5 Jambe spot et structure couverte (track S, K1(c))

Faits (rapport structurel) : deux entités — spot chez **OKX Europe Ltd** (CASP MiCA), X-Perp chez **OEM** (MiFID) ; le spot
est **gagé** (CGU OEM §1.9.2, §9.1.2) et peut être **vendu sans ordre** du propriétaire en cas de perte réalisée (§1.9.5) ;
K1(c) suppose que le spot BTC/ETH est admissible comme **Selected Margin** du short — la page officielle (« What's Selected
Margin », màj 2026-09-03, relue le 2026-10-20) dit seulement que les actifs éligibles sont « listed in your X-Perps margin
settings, together with the margin value each one carries » avec des décotes « tiered and can change » ; BTC et ETH spot n'y sont
**pas nommés** : la règle n'est **pas écrite** → **UNKNOWN**, tranché au compte, sans ordre (rapport structurel §6.2, décision 5a).
L'ADL classe par leverage PnL % et ferme d'abord les **gagnants** (« positions with the highest positive leverage PnL% will be
offsetted first », au mark, sans frais, sans préavis — aide ADL relue) : un short couvert gagnant de +30 % est en tête de file
(rapport structurel §4.1 point 13) — une structure couverte n'est donc pas « sans risque de dénouement ».

Code : **aucun bundle spot**. `MarketType::SPOT` n'apparaît que dans les résolveurs d'arguments
(`Provider/Context/ExchangeContextResolver.php:62`, commandes) ; le Fake est `PERPETUAL` partout
(`Exchange/Adapter/FakeExchangeAdapter.php:73`) ; `ExchangeProviderRegistry` a pour défaut `OKX/PERPETUAL` et lève
`ProviderNotFoundException` pour un contexte inconnu ; `OkxActionFactory::tdMode` accepte déjà `'cash'` (mode spot d'OKX).

Architecture minimale du track S (à ne construire qu'après la vérification au compte **et** une décision CS-FUND) :

```
HedgeStructure (agrégat)            = { spot_leg: SpotHolding(BTC-USDC, qty, entity=OKX Europe Ltd),
                                        perp_leg: XperpPosition(BTC-USD_UM_XPERP-…, short, 1×, margin_mode=selected|isolated),
                                        target_delta: 0, tolerance, funding_ledger, adl_level, forced_unwind_rules }
MarginSettingsProbe (lecture seule) : actifs listés dans les « X-Perps margin settings », décotes, devise de règlement USD-M
OkxSpotProviderBundle               : ExchangeContext(OKX, SPOT) → instruments SPOT (BTC-USDC), ticker, balances ; ordre spot
                                      tdMode=cash (achat une fois ; vente de dénouement) — frais spot 0,08/0,10 %
HedgeStructureGuard                 : re-hedge si |delta| > tolérance ; si adl ≥ seuil → réduire le short ; si funding moyen
                                      < seuil déclaré sur N règlements → dénouement ; journal des règlements (I = 0,01 %/8 h
                                      comme cas de référence, jamais une prévision)
```

Ce qui ne relève **pas** du code : qualification fiscale asymétrique (jambe MiFID / jambe actif numérique), KID PRIIPs, passeport
France d'OEM — préalables listés par le rapport structurel §6.2 (décisions 7, 9).

---

## 4. Table « existe / manque / à adapter », fichier par fichier

Effort : **S** < 1 jour ; **M** 1–3 jours ; **L** > 3 jours (ordre de grandeur, un développeur connaissant le dépôt ; tests
`tests/Exchange`, `tests/TradeEntry`, `tests/Provider` à exécuter localement — hors CI, CLAUDE.md).

### 4.1 `instType=SWAP` codé en dur : 54 occurrences dans 22 fichiers (grep `SWAP` sur `trading-app/src`, 2026-10-20)

| Fichier | Occ. | Rôle | Pour X-Perps | Effort |
|---|---:|---|---|---|
| `Exchange/Okx/OkxInstrumentResolver.php` | 4 | `BTCUSDT ↔ BTC-USDT-SWAP` ; lève sur `_` ; `BTCUSD → BTC-USD-SWAP` (inverse) | **remplacer** par un résolveur par carte (§3.2) | M |
| `Exchange/Adapter/OkxExchangeAdapter.php` | 4 | `instType=SWAP` dans positions, pending, algo-pending, fills ; `marketType()` PERPETUAL | paramétrer par `OkxProductProfile` | S |
| `Exchange/Okx/OkxContractValueResolver.php` | 1 | charge `instruments?instType=SWAP` → `ctVal` | paramétrer | S |
| `Exchange/Okx/OkxExchangeEventNormalizer.php` | 3 | `isSwapRow` `:727-733` / `isSwapEvent` `:738-745` jettent les lignes non `SWAP` / non `-SWAP` | accepter FUTURES + motif `_UM_XPERP-` | M |
| `Exchange/Okx/Lifecycle/OkxLifecycleNormalizer.php` | 4 | `isSwapRow` `:601-605` / `isSupportedLifecycleRow` `:610-620` idem (ordres, fills) | idem | M |
| `Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketSession.php` | 4 | abonnements `orders`/`orders-algo`/`positions` `instType SWAP` ; `expectedSubscriptionArg` | `instType` du profil ; ack attendu identique | S |
| `Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketWorker.php` | 1 | snapshot REST SWAP | paramétrer | S |
| `Provider/Okx/OkxMetadataProvider.php` | 2 | `:231,235` instruments SWAP ; funding par `instId` (`:246-280`) ; `:373,385` refuse `ctType !== 'linear'` (`okx_metadata_incomplete` `:334`) | `instType` du profil ; `ruleType=xperp` filtre ; `ctType` attendu déclaré par le profil | S |
| `Provider/Okx/OkxAccountGateway.php` | 4 | fee tier, bills, orders history SWAP ; ligne **USDT** `:34,38` | `instType` + devise du profil | M |
| `Provider/Okx/OkxOrderGateway.php` | 4 | lectures SWAP ; écritures **lèvent** volontairement (`writeNotImplemented`) — ne pas router d'écriture ici (CLAUDE.md) | lectures paramétrées ; écritures restent fermées | S |
| `Provider/Okx/OkxPositionGateway.php` | 1 | positions SWAP | paramétrer | S |
| `Provider/Okx/OkxMarketDataGateway.php` | 1 | health check instruments SWAP | paramétrer | S |
| `Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizer.php` | 4 | exige `instType === 'SWAP'` (`:135,205`) ; carte `BTC-USDT-SWAP → BTC` | **hors périmètre** (chemin Paper certifié ; le collecteur X-Perps est un nouveau composant) | — |
| `Trading/Paper/Okx/OkxPaperInstrumentMap.php` | 2 | BTC/ETH-USDT-SWAP seulement | hors périmètre | — |
| `Trading/Paper/Okx/Live/OkxPaperPublicLiveSourceFactory.php`, `OkxPaperStreamFrontier.php`, `OkxPaperLiveCheckpoint.php`, `Http/OkxPaperPublicRestClient.php` (regex `:318` refuse `_`) | 2+2+1+1 | capture Paper pinnée `www.okx.com`, BTC/ETH | hors périmètre (voir 4.4) | — |
| `Trading/Paper/Capture/PaperPublicLiveManifestFactory.php`, `Dataset/PaperDatasetVerifier.php`, `Execution/Strategy/PaperCanonicalFundingSource.php`, `Backtesting/PaperBacktestDatasetAdapter.php` | 2+4+2+1 | manifestes et vérifs de jeux de données SWAP | hors périmètre | — |

Hors `src` : `python-orchestrator/scripts/research_exec/forward_okx.py` (`REST = https://www.okx.com` `:49`, `WS_PUBLIC =
wss://ws.okx.com:8443/ws/v5/public` `:50`, `inst_id()` exige `USDT` → `-USDT-SWAP` `:74-77`, `instruments?instType=SWAP` `:419`,
canaux `trades` + `bbo-tbt` `:323`, carnet `books-full` REST `:431`), `research_swing2/{okx,okx_universe,okx_audit}.py`,
`research_listing/{audit,xperp_inventory}.py`, `research_h2/protocol.py` : **24 occurrences dans 7 fichiers** sous `scripts/`
(70 dans tout `python-orchestrator/**/*.py`, grep 2026-10-20) ; **ne pas modifier** (`fwd-okx-r1` tourne sur `forward_okx.py` ;
protocole gelé) — le collecteur X-Perps est un **nouveau** paquet (`python-orchestrator/scripts/research_lp/`, squelette a2
présent le 2026-10-20 : `__init__.py` annonce `tape`, `xperp_capture`, `lp_sim`, `events`, `cli` avec `capture`, `count`,
`simulate`, `events`, `verify` et une garde « not-before »), pas une édition.

### 4.2 Chemin d'exécution

| Composant | Existe | Manque / à adapter | Effort |
|---|---|---|---|
| `Exchange/Okx/OkxActionFactory.php` | `ordType = post_only` si `postOnly` ; `tdMode` cross/isolated/**cash** ; `reduceOnly` ; `conditional` au mark ; `clOrdId` 32 car. | rien pour post-only ; `posSide` toujours envoyé long/short → **mode net** à gérer (`posMode` UNKNOWN) ; pas d'`amend` (volontaire : `supportsModifyOrder false`) ; `cancel-batch-orders` / `cancel-all` absent (utile au kill switch) | S (net mode) ; S (cancel-all) |
| `Exchange/Adapter/OkxExchangeAdapter.php` | place/cancel/getOrder/getOpenOrders/getFillsSnapshot/setLeverage ; capabilities post-only, reduce-only, trigger, WS privé | `getOrder` = scan des pending (coût REST) → en événementiel, l'état vient du WS privé ; `cancelAll` | S |
| `Exchange/Adapter/OkxDemoGuardedExchangeAdapter.php` + `Exchange/Okx/Demo/OkxDemoWriteGate.php` | kinds `entry` / `take_profit` / `protective` ; `placeKind()` (`:225-239`) : kind explicite via `metadata['write_kind']`, sinon non-reduce-only ⇒ `entry`, reduce-only LIMIT/TAKE_PROFIT ⇒ `take_profit`, sinon `protective` ; `stopLossPresent()` (`:241-247`) = `attachedStopLossPrice` ou `metadata['stop_loss_price']` ; notionnel = qty × ctVal × prix (`:252-270`, `contract_size_unavailable` / `notional_unavailable`) ; refus `stop_loss_required` ; exemptions protectives (fraîcheur WS, `max_notional_exceeded`) ; trip durable ; audit before/after | **nouveaux kinds** `lp_entry` et `lp_exit`, **toujours explicites** dans `metadata['write_kind']` (aujourd'hui un post-only non reduce-only sans ce champ retombe sur `entry`). `lp_entry` **reste soumis à `stop_loss_required`** (`stopLossPresent()` lit déjà `metadata['stop_loss_price']` = prix du stop de catastrophe, §2.5) et ajoute des contrôles **supplémentaires** : `post_only === true`, `reduce_only === false`, **`stop_distance_min`** (refus si le stop est plus **près** que k σ15 déclaré par le protocole — c'est le durcissement qui porte la règle 12), `frozen_margin_within_budget`, `product === xperp`, symbole dans `allowed_symbols` ; `lp_exit` : `reduce_only === true`. **Sans toucher** aux règles des kinds existants ni à `DemoTradingSafetyPolicy::requireStopLoss` (relecture CTO M5 : la version précédente — `max_loss_budget_present` **à la place** de `stop_loss_present` — était une exemption de l'invariant « toute entrée a un stop », refusée) | M |
| `TradeEntry/Execution/ExchangeExecutionService.php`, `ExecutionBox.php`, `ProtectionEnforcer.php`, `RestingEntryWatcher.php`, `EmergencyCloseService.php` | un ordre par plan ; stop obligatoire ; emergency close marché | **ne pas réutiliser** pour le track L (sémantique « stop ou fermeture ») ; `EmergencyCloseService` réutilisable comme « flatten » du kill switch | — |
| `TradeEntry/MessageHandler/LimitFillWatchMessageHandler.php` (poll 5 s, cancel 60 + 10 s, pas de re-post), `OutOfZoneWatchMessageHandler.php` (notification Redis seulement), `CancelOrderMessageHandler.php` | watchers REST | **remplacés** par `LadderManager::onPrivateEvent` (WS) + minuteries de l'event loop | — |
| `Exchange/Okx/PrivateWebSocket/*` (worker React/Pawl, session, snapshot REST, réconciliation, statut Redis TTL 10 s, ping 5 s / pong 4 s, reconnexion 1/2/4/8/15 s) | observateur démo, read-only, `fills` VIP seulement (64003) → `orders` + REST | abonnements `instType FUTURES` ; **mode consommateur** : publier `LpPrivateEvent` vers le `LadderManager` (bus en mémoire ou Redis) au lieu de projeter seulement ; endpoint guard reste démo | S–M |
| `Exchange/Ws/ExchangeWsIngestionService.php`, `ExchangeWsClientInterface` (`drainPrivateEvents`) | ingestion par drain (pull) | modèle push événementiel → `LpPrivateEventBus` | S |
| `Exchange/Fake/FakeExchangeMatchingEngine.php` (4 173 l.) | post-only refusé au croisement (`post_only_would_cross`), fallback taker, stops, trailing | sert de `FakePort` pour les tests d'intégration du `LadderManager` ; **pas** un simulateur de recherche (couplé au chemin Paper certifié) | S (adaptateur) |
| `TradingCore/Execution/Safety/*` (`DemoTradingKillSwitchService`, policy pure, audit fail-closed) | kill switch commun | réutilisé **tel quel** par la porte `lp_*` ; `requireStopLoss` **inchangé** (CTO M5) ; le contrôle `stop_distance_min` et le budget de marge gelée sont des règles **additionnelles** de la porte `lp_entry`, évaluées après la politique commune | S |
| `Exchange/Okx/Demo/FilesystemOkxDemoTrip.php` (`var/okx-demo-execution.quarantine`) | trip durable | réutilisé ; **plus** un trip propre au track L (`var/lp-executor.quarantine`) pour la perte/jour et l'ADL | S |
| `config/trading/exchange/okx.yaml` (`allowed_symbols` BTCUSDT/ETHUSDT, `max_notional 25.0`, `kill_switch_enabled true`, `require_stop_loss true`, `max_leverage_cap 3`, `margin_mode cross`, `rate_limit_profile 5 rps`) ; `env/demo.yaml` | couche effective ; `ModeContractValidator::MODE_IDS` = `day_trading, scalping, micro_scalping, swing` (`:11`), `PUBLISHED_VERSIONS` (`:14-19`) ; schéma `config/trading/schema/mode-contract.schema.json:10-11` (enums `mode_id`, `mode_version`) | nouvelle couche `mode_contract/lp_event_provider/1.0.0` + `exchange/okx.yaml` : `allowed_products: [xperp]`, `allowed_symbols` X-Perps, `require_stop_loss: true` (inchangé), `stop_distance_min_sigma` (k σ15), `max_frozen_margin`, `max_loss_per_day` ; un nouveau `mode_id` exige **allowlist PHP + schéma JSON + empreinte** (tests hors CI) ; contrats publiés hachés (nouvelle version, jamais édition) | M |

### 4.3 Détecteurs, échelle, sortie, risque (tout **manque**)

| Composant | Existe de proche | À écrire | Effort |
|---|---|---|---|
| `LpEventDetectorInterface` + 3 détecteurs | rien (les conditions `Indicator/Condition/*` sont des états de bougie ; `enrichMicrostructureContext` est un instantané REST) ; `CanonicalPhpIndicatorCalculator` (EMA, σ) réutilisable | détecteurs purs, états bornés, digest de rejouabilité, tests « direct = rejeu » | M |
| `LadderPlanner` / `LadderManager` | `OrderPlanModel` (un ordre) ; `EntryZoneCalculator` (zone, pas échelle) | machine d'états des rungs, renouvellement, annulation au croisement, budget de requêtes par `instId`, mode net/hedge (`posMode` UNKNOWN), **réconciliation d'état après reconnexion du WS privé** (le worker existant est un observateur), `cancel-all` absent de l'adaptateur, tests contre le `FakePort`, **plus** le port inter-langages de §2.10 | **L** (relecture CTO §4.3 : « plusieurs semaines » d'un développeur connaissant le dépôt) |
| `ExitPolicy` | `TpSlTwoTargetsService` (TP fixes, clôtures `postOnly:false`) | sortie passive EMA/temps post-only, recotation, secours taker reduce-only, stop de catastrophe au mark | M |
| `PortfolioRiskGovernor` | `risk.daily_max_loss_*`, `max_concurrent_positions` (YAML, par plan) ; kill switch commun | marge gelée, perte/événement, ADL (`positions.adl`), funding, budget de requêtes, deux lectures (illimité / 500 USDT) | M |
| `FundingLedger` | `PaperCanonicalFundingSource` (Paper), `NetRCostMath` (provision), `FillCostLedgerIngestionService` (drapeau devise) ; **aucune ingestion de bills OKX** | ingestion `account/bills` (type funding) ou `positions.fundingFee`, imputation par position | S–M |

### 4.4 Collecteur et simulateur (ce qui permet la **mesure**)

| Composant | Existe | Réutilisable pour X-Perps ? | À écrire | Effort |
|---|---|---|---|---|
| Capture publique Paper (`Trading/Paper/Okx/Live/OkxPaperPublicLiveSource.php`, 8 094 l. ; `OkxPaperPublicSubscriptionSet` : `trades`, `books` + `candle1m/5m/15m/1H` ; journal ndjson + manifeste sha256 ; reprise, checkpoints, file durable) | oui, pour BTC/ETH-USDT-SWAP sur `www.okx.com` | **non** tel quel : hôte pinné, carte d'instruments figée, regex d'`instId` refuse `_`, `PaperMarketDataChannel` sans `liquidation_order` ni `mark_price` ni `book5`, `PaperMarketEvent::ALLOWED_SYMBOLS` = BTCUSDT/ETHUSDT ; c'est le chemin **certifié** (ne pas y mêler la recherche) | réutiliser le **format** (enveloppe v2, manifeste, sha256) dans un collecteur de recherche léger | — |
| `python-orchestrator/scripts/research_exec/forward_okx.py` (`trades` + `bbo-tbt` WS, `books-full` REST / 30 s, instruments, checkpoints, manifeste, reprise des 500 derniers prints) | oui, SWAP, `www.okx.com` ; **en service pour `fwd-okx-r1`** | **non** (ne pas toucher) ; modèle à copier | `research_lp/xperp_capture.py` + `cli count` / `cli capture` (livrés par a2 le 2026-10-20, 31 tests ; corrigés par `a5-corrections-cto` le même jour, **40 tests** verts rejoués le 2026-10-21). **Fait** : `instType=FUTURES`, canaux `trades`, `books5`, `liquidation-orders`, `mark-price`, `funding-rate`, `instruments` ; mode **comptage** (messages par canal × instrument, zéro prix) puis mode capture ; hôte EEE **sans port** (`WS_PUBLIC_EEA`, CTO m1) ; plancher `QUARANTINE_FLOOR = 2026-11-02` dans tous les modes, `--allow-before-reason` obligatoire et tracé (CTO M1, m3) ; SIGTERM/SIGINT propres, `checkpoint_every_s` 90 s en valeurs (CTO M4) ; `ctVal` obligatoire au simulateur (CTO M3) ; décision à `arm_ts_us` + `ttl_us` (CTO M2) ; m2, m4–m12. **Reste** (`LP-G2` P-G2-4 (B)) : `trades.source` et nombre d'ordres de `books5` (quant B5), `bbo-tbt` (quant m3), `books-rpi` au comptage (P-G2-9), tranche 6 pb, `grid_offset_s`, stratification, test « rejeu = direct », `queue_unknown`, `size_visible`, repli REST funding, `band_rejected` | S (comptage) → M (capture) ; reste (B) : S–M |
| `research_exec/capture.py` (extraction top_of_book / public_trade d'un jeu Paper, hash vérifié) | oui | partiellement (lecteur ndjson) | lecteur des nouveaux canaux | S |
| `research_exec/simulate.py` (grille 5 s, latence 250 ms, TTL 45 s, règles `through`/`touch`, mark-outs 1/10/60/300 s, aller-retour maker/taker 2/5 pb, dérive sans fill) | oui (Part A) | oui pour **G2 variante recoteur** ; à étendre pour la variante **minute** (pose à hh:mm:ss, TTL 30–60 s, sans recotation) et le mark-out **jusqu'au fill opposé ou 60 min** | `research_lp/lp_sim.py` + `research_lp/events.py` (détecteurs) + `cli simulate` / `cli events` / `cli verify` : échelle k σ15, renouvellement, annulation au croisement, sortie passive EMA/temps, funding par photo, marge gelée (deux lectures), liquidation forcée au taux taker, détecteurs importés tels quels | M |
| `research_exec/q3r2_engine.py` (funding par règlement, sortie passive avec volume *through* comme plafond de fill, stop exécuté à latence) | oui | règles de fill passif et funding réutilisables | — | — |
| `Exchange/Fake/FakeExchangeMatchingEngine.php` | oui | `FakePort` pour tests PHP du `LadderManager` | adaptateur | S |

### 4.5 Jambe spot (track S)

| Composant | Existe | À écrire | Effort |
|---|---|---|---|
| `ExchangeContext(OKX, SPOT)` | enum et résolveur d'argument seulement | `OkxSpotProviderBundle` (instruments SPOT, ticker, balances ; ordre `tdMode=cash`), enregistrement dans `ExchangeProviderRegistry` | L |
| `MarginSettingsProbe` | `OkxRuntimeCheck` / `app:exchange:runtime-check` (lecture seule) | sondes `account/config` (`posMode`), `account/instruments?instType=FUTURES`, réglages de marge (endpoint **UNKNOWN**, peut-être interface seulement) | S–M |
| `HedgeStructure`, `HedgeStructureGuard` | rien | agrégat deux jambes, delta, funding ledger, règles de dénouement, ADL | L |
| Comptabilité multi-devise (USDC spot / USD-M) | USDT partout (§3.3) | devise par jambe, pas de conversion implicite | M |

---

## 5. Séquence de construction minimale : la mesure avant tout exécuteur

```
Phase 0  propriétaire, zéro code, zéro donnée        │ P1 la machine poste-t-elle ? (journaux, champs texte)
         rien avant le verdict fwd-okx-r1 (31 oct.)  │ P2 §9.4 lu et décidé ; GET lecture seule : posMode, instruments FUTURES,
                                                      │    réglages de marge, devise USD-M, barème ; démo liste-t-elle les X-Perps ?
──────────────────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────
Phase 1  collecteur de COMPTAGE (P3)        S–M       │ research_lp count (xperp_capture) : 24–72 h, messages par canal × instrument,
         ≥ 2026-11-02, après P2                       │ latence de réception, reconnexions ; AUCUN prix persisté
         livrable : table couverture × fréquence      │ décide : G4 mesurable sur OKX ? (liquidation-orders FUTURES) ; books5 cadence ;
                                                      │ hôte WS EEE ; coût d'une capture longue
──────────────────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────
Phase 2  capture publique longue             M        │ même collecteur, mode capture : npz par instrument-jour + manifeste sha256
         UNE capture CONTINUE 2026-11-02 →            │ (tape a2) ; ≤ 40 X-Perps crypto + BTC/ETH-USDT-SWAP globaux (contrôle
         ≤ 2027-03-31 (G2 2 sem., G4 Gate 2 90 j,     │ Part A, G9 gratuit ; hôte global : question au propriétaire) ; stockage
         G5 Gate 2) — c'est l'échelle sur laquelle    │ privé (§9.4) ; budget disque ≈ 170 Mo/jour/instrument de books5 brut
         §9.4 est décidé (quant B4)                   │ (≈ 1 To brut sur la séquence, compression UNKNOWN) ; supervision du
                                                      │ processus WS, verify quotidien ; aucune lecture avant gel des protocoles
──────────────────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────
Phase 3  simulateur + détecteurs             M        │ research_lp lp_sim + events (détecteurs purs, Python ; référence du futur direct, §2.10) ;
         protocoles pré-enregistrés (a3), gel sha256  │ tests « rejeu = direct » ; deux lectures (illimité / 500 USDT) ; funding ;
         UNE exécution par protocole                  │ post-only annulé au croisement ; liquidation forcée au taux taker
──────────────────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────
         ════════════════════════  VERDICT (G2 → G4 → G5 → G1 sur consentement)  ════════════════════════
──────────────────────────────────────────────────────┼──────────────────────────────────────────────────────────────────────
Phase 4  seulement si un verdict PASSE et sur        │ 0) CHOIX DU LANGAGE et du port vers la porte PHP (§2.10 ; option (a)
         décision écrite du propriétaire              │    recommandée : Python + port, aucun adaptateur OKX Python) (décision)
         ordre de grandeur : plusieurs semaines       │ a) OkxProductProfile + résolveur X-Perp + normaliseurs + WS privé FUTURES (M)
         (CTO §4.3), à mettre en face de dizaines     │ b) LadderManager + ExitPolicy + RiskGovernor contre FakePort, réconciliation
         à centaines d'USD/an                          │    WS privé, cancel-all, net/hedge (L) + port inter-langages (L)
                                                      │ c) write kinds lp_entry (stop_loss_required conservé + stop_distance_min) /
                                                      │    lp_exit dans OkxDemoWriteGate (M) ; contrat lp_event_provider/1.0.0
                                                      │    publié et haché
                                                      │ d) démo : drapeaux flippés par le propriétaire, X-Perps en démo vérifiés,
                                                      │    runtime-check, WS privé frais ; jamais live dans ce programme
```

**Ce qui ne doit jamais être construit avant un verdict** : un chemin d'ordre vers `eea.okx.com` en live (la liste de
`OkxRestEndpointGuard` ne change pas) ; un flip de drapeau (`OKX_LIVE_ENABLED`, `OKX_DEMO_TRADING_ENABLED`, `DEMO_TRADING_ENABLED`,
`HYPERLIQUID_*`) ; un assouplissement de `OkxDemoWriteGate` pour les kinds existants ; un ordre démo « pour voir » ; une
modification de `forward_okx.py`, des jeux `run14`/`run15`, de `fwd-okx-r1` ; une relecture de W0–W2 ou des fenêtres consommées ;
une réutilisation du chemin Paper **certifié** comme preuve (la confirmation est « capture publique + simulateur de
recherche », règle 13 du rapport) ; une cellule de certification ou un backtest `app:backtest:*` sur X-Perps (le chemin
certifié ne connaît pas le produit).

**Porte économique écrite avant la Phase 2** (règle 11) : plafond USD/an = fills/jour × notionnel × net pb **aller-retour** ;
règle d'arrêt (« poursuivi » ⇔ point ≥ S_éco et UB90 ≥ 2 S_éco ou LB90 > 0, `LP-PROGRAMME-GATES.md` §1.2) ; budget de
multiplicité (Holm) ; coût fixe en face : collecteur, **disque** (§5 Phase 2), **supervision** et `verify` quotidien, temps du
propriétaire.

---

## 6. Invariants de sécurité (repris de CLAUDE.md et du code) et ce que le track L y ajoute

### 6.1 Repris tels quels

1. **Drapeaux à 0 par défaut, propriétaire seulement** : `OKX_LIVE_ENABLED`, `OKX_DEMO_TRADING_ENABLED`, `DEMO_TRADING_ENABLED`,
   `HYPERLIQUID_TESTNET_TRADING_ENABLED`, `HYPERLIQUID_MAINNET_ENABLED` (`config/services.yaml:45-60`). Le code de recherche
   (collecteur, simulateur, détecteurs) **ne les lit pas** ; seul `app:exchange:runtime-check` les affiche. `PAPER_EXECUTION_ENABLED`
   ne gate que le dispatcher fake.
2. **Toute écriture OKX passe par `OkxDemoGuardedExchangeAdapter`** (`OkxDemoWriteGate::evaluate`) : refus si
   `okx_demo_tripped`, `okx_env_not_demo`, `okx_simulated_trading_disabled`, `okx_demo_trading_disabled`, `demo_trading_disabled`,
   `okx_live_enabled`, `okx_private_rest_endpoint_not_allowed` ; puis kill switch commun (`DemoTradingKillSwitchService`,
   fail-closed sur `audit_failed`) ; fraîcheur du WS privé ≤ 10 s et `app.okx_demo_max_notional` (1 000) exemptés **seulement**
   pour `protective`. Audit `before`/`after` ; un `after` qui échoue **trip** (`audit_after_failed`).
3. **Quarantaine durable** `var/okx-demo-execution.quarantine` (`FilesystemOkxDemoTrip` : en-tête
   `OKX_DEMO_EXECUTION_QUARANTINED`, deuxième ligne = raison) : bloque **toutes** les écritures, protectives incluses ; levée
   **manuelle** par un opérateur après lecture de l'audit.
4. **Portes d'endpoint exactes** : REST `https://eea.okx.com` (démo) / `https://www.okx.com` (live) ; WS privé
   `wss://wseeapap.okx.com:8443/ws/v5/{private,business}` seulement. Aucun autre hôte, port, chemin.
5. **Aucune valeur de credential** dans les journaux ; WS privé en listes positives de champs ; payloads bruts jamais persistés.
6. **Notionnel = qty × `ctVal` × prix** ; sizing fail-closed (`okx_metadata_incomplete`, `contract_value_missing`).
7. **Hyperliquid** : preuve de readiness HMAC à 60 s, identité versionnée et hachée (`config/trading/env/testnet.yaml`),
   DI sans preuve ⇒ le flux ne peut pas muter. Ce **modèle** (enveloppe identitaire hachée, preuve à durée de vie courte, scope
   symboles/côté/notionnel) est celui à reprendre pour le track L.
8. **Ordres écrits via l'adaptateur API-first** ; `OkxOrderGateway` lève sur toute écriture (voulu).
9. **Contrats publiés immuables** : un changement de profil = nouvelle version hachée, jamais une édition
   (`ModeContractValidator::MODE_IDS` / `PUBLISHED_VERSIONS`, schéma JSON `mode-contract.schema.json`).
10. **Live OKX interdit aussi côté orchestrateur et sondes** : `python-orchestrator/app/services/live_guard.py:39`
    `PERMANENT_LIVE_FORBIDDEN_EXCHANGES = {"okx", "hyperliquid"}` (fail-closed) ; `Command/ExchangeRuntimeCheckCommand.php:433`
    déclare `permissionsTrade: false` en dur — aucune sonde n'affirme jamais un droit d'écriture.
11. **WS privé observé, pas supposé** : statut Redis à TTL 10 s (`RedisOkxPrivateWebSocketStatusStore::TTL_SECONDS`), ping 5 s /
    pong 4 s, login 5 s, readiness 10 s, reconnexion 1/2/4/8/15/15 s (`OkxPrivateWebSocketWorker.php:22-26`) ; le canal `fills`
    retombe sur `orders` + REST au code `64003` (`OkxPrivateWebSocketSession.php:314`). Un `LadderManager` sans statut frais
    **n'a pas le droit** d'avoir des rungs vivants (§6.2, trip).

### 6.2 Ajouts proposés pour le track L (durcissements, pas assouplissements)

| Invariant | Mécanisme | Pourquoi |
|---|---|---|
| **Nouveaux kinds** `lp_entry` / `lp_exit` | `lp_entry` : **soumis à `stop_loss_required`** comme `entry` (prix du stop de catastrophe ≥ 3 σ15 dans `metadata['stop_loss_price']`, posé après fill par le chemin `protective` existant) **plus** `post_only === true`, `reduce_only === false`, **`stop_distance_min`** (refus si le stop est plus près que k σ15 déclaré par le protocole), `frozen_margin_within_budget`, `product === xperp`, symbole dans `allowed_symbols` ; `lp_exit` : `reduce_only === true`. Les kinds existants, `stop_loss_required` et `DemoTradingSafetyPolicy::requireStopLoss` **inchangés** | la règle 12 dit « pas de stop **serré** », pas « pas de stop » : `lp_entry` est un **durcissement** (stop obligatoire **et** pas trop près) — la première version (« `max_loss_budget_present` au lieu de `stop_loss_present` ») exemptait une entrée de l'invariant « toute entrée a un stop » de l'enveloppe démo et a été refusée (relecture CTO M5) |
| **Enveloppe de readiness LP** | `config/trading/env/demo.yaml` → `lp_readiness` : identité `lp_event_provider@1.0.0 / protocol_id@sha256 / okx / demo / xperp`, `allowed_symbols`, `max_frozen_margin`, `max_loss_per_day`, hachée ; preuve HMAC 60 s émise par une commande opérateur, **jamais** par le processus lui-même | copie du modèle Hyperliquid ; sans preuve, le `DemoPort` refuse |
| **Trip propre au track L** | `var/lp-executor.quarantine` : perte/jour atteinte, ADL ≥ seuil, WS privé non frais > 10 s avec rungs vivants, désaccord rejeu/direct des détecteurs, échec d'audit ; action : `cancel_all` (protectif) puis arrêt des posts ; levée manuelle | les ordres au repos sont la surface de risque principale |
| **Budget de requêtes local** | compteur place/cancel par `instId` et sous-compte, refus local avant 50061 | un gestionnaire d'échelle est le premier à frapper les limites |
| **Mode de position vérifié** | `posMode` lu au runtime-check ; en `net_mode`, un seul côté ; en `long_short_mode`, `posSide` envoyé | sinon rejet systématique ou position inverse |
| **Collecteur sans clé** | aucun secret monté ; hôtes publics seulement ; stockage privé (§9.4) ; comptage avant capture | licence et périmètre |
| **Rejeu = direct** | test obligatoire : détecteurs sur une capture = `LpEvent` identiques (digest) à ceux journalisés en direct | la mesure n'a de sens que si le simulateur et l'exécuteur sont le même code |
| **Jamais live** | `OkxRestEndpointGuard::LIVE_URIS` reste `www.okx.com` ; aucune PR de ce programme ne touche cette liste | règle du propriétaire |

---

## 7. Décisions attendues et UNKNOWN qui conditionnent l'architecture

| # | Décision / inconnue | Qui | Effet sur l'architecture |
|---|---|---|---|
| 1 | §9.4 OKX API Agreement (« Last Updated: 28 July 2026 », relu le 2026-10-20) : « You may access and use Market Data solely for your own personal, non-commercial trading and account management purposes » ; « The restrictions in this Section 9.4 apply equally to Market Data accessed through public endpoints » ; « You must not use automated scripts, bots, web scrapers, or any other automated means to extract Market Data » (au-delà des besoins personnels, pour contourner une licence ou charger l'infrastructure) ; (c) ML/IA « intended to be commercialized or made available to third parties » ; accès « high-frequency or large-scale … for commercial or institutional purposes » ⇒ licence séparée. Décision sur l'**échelle réelle** (relecture quant B4) : **une capture publique continue du 2026-11-02 au ≤ 2027-03-31** (P3, G2, G4 Gate 2, G5 Gate 2), ≤ 40 X-Perps crypto + 2 swaps globaux, canaux et cadences nommés (`LP-G2` P-G2-1), stockage privé, travail d'agents dessus ; question jointe : hôte global `ws.okx.com` depuis un compte EEE | propriétaire | sans oui, pas de Phase 2 ; Phase 1 (comptage) reste possible mais à confirmer ; tout allongement = nouvelle décision |
| 2 | `posMode` du compte X-Perps ; règle de gel de marge des ordres au repos ; devise de règlement USD-M ; barème API ; `ctVal` 0,0001 vs 0,01 | propriétaire (GET lecture seule) | mode net ⇒ un côté ; marge gelée ⇒ budget de rungs ; sizing |
| 3 | X-Perps listés en **démo** ? | propriétaire | sans, la Phase 4(d) n'existe pas ; la mesure (Phases 1–3) n'en dépend pas |
| 4 | Part des X-Perps dans `liquidation-orders` (le canal sert `FUTURES` et porte le disclaimer « doesn't represent the total number » : **établis** par le desk, `LP-DATA-DESK` §1.3) | Phase 1 (comptage) | G4 sur OKX ou Gate 1 historique Hyperliquid (transférabilité M9) |
| 5 | Hôte WS public EEE : forme documentée `wss://wseea.okx.com/ws/v5/public` **sans port** (desk §1.1 ; alias du collecteur aligné, CTO m1 fait) ; acceptation du port 8443 (sans objet désormais) et service effectif des X-Perps par cet hôte : UNKNOWN | Phase 1 (comptage, `--host eea` d'abord) | collecteur |
| 6 | Consentement G1 (différence déclarée) ; décision G6 ; porte économique et règle d'arrêt ; vocabulaire « fourniture de liquidité événementielle » | propriétaire | périmètre des protocoles a3, donc des détecteurs à écrire |
| 7 | Track S : spot BTC/ETH admis comme Selected Margin ? CS-FUND 12 mois tel quel ou sans suite ? conseil fiscal | propriétaire | sans oui, aucun bundle spot ni `HedgeStructure` n'est construit |
| 8 | Calendrier : rien avant le verdict fwd-okx-r1 (31 oct.), confirmation ≥ 2026-11-02 | propriétaire | Phase 1 au plus tôt 2026-11-02 |

---

## 8. Traitement de la revue (2026-10-20)

Relectures : `review/cto.md` (M1–M6, m1–m12) et `review/quant.md` (constats touchant l'architecture). Convention : **appliqué** =
texte modifié ; **appliqué au code** = fait par la passe parallèle `a5-corrections-cto` (hors du périmètre d'écriture de cette
passe), vérifié le 2026-10-21 ; **prérequis** = code restant à écrire avant gel (`LP-G2` P-G2-4 (B)) ; « **Réponse à la
revue** » = refus ou traitement différent, motivé.

| Constat | Traitement |
|---|---|
| CTO **M5** — `lp_entry` avec `max_loss_budget_present` **au lieu de** `stop_loss_present` ; `requireStopLoss → requireLossBudget` : exemption de l'invariant « toute entrée a un stop » | **Appliqué** : §0.9, §1.2, §2.5, §4.2 (deux lignes), §6.2 — `lp_entry` reste soumis à `stop_loss_required` (prix du stop de catastrophe dans `metadata['stop_loss_price']`, posé après fill via `protective`), ajoute `stop_distance_min` (refus d'un stop trop près), `post_only`, budget de marge, produit, symboles ; `DemoTradingSafetyPolicy::requireStopLoss` intouché |
| CTO **M6** — « rejeu = direct » défini en PHP, livré en Python ; port ou réécriture non chiffrés | **Appliqué** : §2.1 (déclaration), **§2.10** (trois options chiffrées ; (c) maintenant, (a) si verdict : port vers la porte PHP avec preuve HMAC, aucun adaptateur OKX Python), §5 Phase 3–4 |
| CTO **§4.3** — `LadderManager` M–L → L ; budget disque ; supervision ; `verify` | **Appliqué** : §4.3, §5 Phase 2 et Phase 4, porte économique (§5) ; `LP-PROGRAMME-GATES.md` §1.2 |
| CTO **M1–M4**, **m1–m12** — code du collecteur et du simulateur | **Appliqué au code** par la passe parallèle `a5-corrections-cto` (hors du périmètre d'écriture de cette passe de documents), vérifié le 2026-10-21 (40 passed, digests `cli.code_identity()` identiques à ceux de `a5-corrections-cto/NOTES.md` §5) ; état décrit en §2.3 (M2, m5, m7) et §4.4 (M1, M3, M4, m1) ; liste complète fait / reste dans `LP-G2` P-G2-4 (A)/(B). **Réponse à la revue** : le gel exige le digest du code **après** (B), pas celui d'aujourd'hui |
| CTO **m1** — alias EEE avec port 8443 | **Appliqué** : §2.2, §3.4, §4.4, §7 décision 5 (forme documentée sans port ; port UNKNOWN) ; **appliqué au code** par la passe parallèle (`WS_PUBLIC_EEA = "wss://wseea.okx.com/ws/v5/public"`) |
| quant **m1** — `liquidation-orders` « UNKNOWN jusqu'à P3 » alors que le desk l'a établi ; **m2** — `funding-rate` pour FUTURES | **Appliqué** : §2.2 (deux lignes), §7 décision 4 |
| quant **B2 / M4** — définition de l'événement G4 (seuil relatif, `method`, dernier fill) | **Appliqué** : §2.3 `LiquidationClusterDetector` et §2.9 alignés sur `LP-G4` §3.3 révisé |
| quant **B4** — décision §9.4 sur « 2–8 semaines » | **Appliqué** : §5 Phase 2, §7 décision 1 (échelle réelle, hôte global, allongement = nouvelle décision) |
| quant **B1 / M7** — net aller-retour ; porte économique | **Appliqué** : §5 (porte économique écrite avant la Phase 2) |

---

## 9. Sources

**Code (worktree `lp-programme`, lecture seule, 2026-10-20)** : `Exchange/Okx/{README.md, OkxInstrumentResolver, OkxContractValueResolver,
OkxRestEndpointGuard, OkxConfig, OkxActionFactory, OkxExchangeEventNormalizer:727-745, Lifecycle/OkxLifecycleNormalizer:601-620,
Demo/OkxDemoWriteGate, Demo/OkxDemoWriteKind, Demo/FilesystemOkxDemoTrip, PrivateWebSocket/OkxPrivateWebSocketSession:234-237,
PrivateWebSocket/OkxPrivateWebSocketEndpointGuard, PrivateWebSocket/OkxPrivateWebSocketWorker, PrivateWebSocket/PawlOkxPrivateWebSocketTransport}` ;
`Exchange/Adapter/{OkxExchangeAdapter, OkxDemoGuardedExchangeAdapter, FakeExchangeAdapter:73}` ; `Exchange/Contract/ExchangeAdapterInterface` ;
`Exchange/Dto/{PlaceOrderRequest, ExchangeCapabilities}` ; `Exchange/Ws/*` ; `Exchange/Fake/FakeExchangeMatchingEngine:219,3680-3915` ;
`Exchange/Hyperliquid/HyperliquidMutationReadinessProof` ; `TradeEntry/Execution/{ExchangeExecutionService:35,323-343,435, ExecutionBox,
RestingEntryWatcher, ProtectionEnforcer, EmergencyCloseService}` ; `TradeEntry/MessageHandler/{LimitFillWatchMessageHandler:31-33,120,527-548,
OutOfZoneWatchMessageHandler, CancelOrderMessageHandler}` ; `TradeEntry/OrderPlan/OrderPlanModel:17` ; `Provider/Okx/{OkxMetadataProvider:231-306,
OkxAccountGateway:34-38,87,116,231, OkxOrderGateway, OkxPositionGateway, OkxMarketDataGateway, OkxPublicReadMapper}` ;
`Provider/Registry/ExchangeProviderRegistry` ; `Provider/Context/ExchangeContextResolver:62` ; `Provider/Repository/ContractRepository:420` ;
`Common/Enum/{MarketType, Exchange}` ; `Trading/Pnl/NetPnlCertificationService:174` ; `TradingCore/Mode/ModeContractValidator:310,330` ;
`TradingCore/Execution/Safety/*` ; `Trading/Paper/Okx/{OkxPaperInstrumentMap, OkxPaperPublicConfig, Live/OkxPaperPublicSubscriptionSet,
Live/OkxPaperLivePolicy, Live/OkxPaperPublicLiveSourceFactory, Http/OkxPaperPublicRestClient:117,318, Normalization/OkxPaperMarketEventNormalizer:135,205}` ;
`Trading/Paper/MarketData/{PaperMarketDataChannel, PaperMarketEvent}` ; `config/services.yaml:13-75,470-475` ; `config/packages/messenger.yaml` ;
`config/app/trade_entry.scalper_micro.yaml` ; `config/app/mtf_contracts.yaml:10,17,36` ; `config/trading/exchange/okx.yaml` ; `config/trading/env/{demo,testnet}.yaml` ;
`python-orchestrator/scripts/research_exec/{forward_okx.py, capture.py, simulate.py, q3r2_engine.py}`.

**Documents du dépôt** : `docs/handbook/research/{H1-XPERP-TECHNICAL-GAP, TRADINGV3-EXCHANGE-COMPATIBILITY, H1-XPERP-MARKET-ACCESS,
H1-XPERP-ACCOUNT-CHECK, H1-XPERP-500USDT-FEASIBILITY, RESEARCH-ELIGIBILITY-GATES}.md` ; `docs/handbook/technical/{demo-testnet-safety-envelope,
exchange-runtime-gates, okx-demo-readiness, hyperliquid-testnet-readiness}.md` ; `docs/handbook/runbooks/okx-private-ws-observability.md` ;
`CLAUDE.md` (§ Demo / testnet safety). Dossiers privés : `20261020T1226Z-scalping-theory-gap/review/{exec.md, NOTES-exec.md}` (faits de code
recoupés) ; les deux rapports de base, lus dans `.worktrees/research-scalping-theory/docs/handbook/reports/` (613 et 509 lignes ;
voir l'écart de chemin en en-tête) : théorie §1, §3.3 (fiches G1/G2/G4/G5), §3.5 (14 règles), §6 (12 décisions) ; structurel §2.1 (K1),
§4.1 points 7, 10, 13, 14, §6.2 (13 décisions). Squelette a2 : `python-orchestrator/scripts/research_lp/__init__.py`.

**Documentation officielle (relue le 2026-10-20, deuxième passe ; l'outil de lecture rend le docs-v5 tronqué à 406 161 caractères
et le changelog à 594 429 — tranches citées)** : OKX docs-v5 `https://www.okx.com/docs-v5/en/` — tranche 0–100 k : « At the sub-account
level, we allow a maximum of 1000 order requests per 2 seconds » ; « Connection limit: 3 requests per second (based on IP) » ; « The total
number of 'subscribe'/'unsubscribe'/'login' requests per connection is limited to 480 times per hour » ; limites place/amend/cancel
« independent from each other », par `instId` ; `instruments` : `initPxLmtPct`, `floatPxLmtPct`, `maxPxLmtPct`, `ruleType` (normal /
pre_market / rebase_contract / xperp), `settleCcy` (« Settlement and margin currency »), `ctVal`, `ctType` (linear / inverse) ; tranche
100–200 k : `GET /account/positions` `adl` « Range: 0–5, where 0 = lowest ADL priority » ; tranche 300–400 k : canal privé `positions`
`adl` « Divided into 6 levels, from 0 to 5 », `fundingFee` « Accumulated funding fee » ; Place order `ordType post_only` (« can only
provide liquidity to the market and be a maker », annulé sinon), `tdMode` cash / cross / isolated. Changelog `https://www.okx.com/docs-v5/log_en/`
(tranches 0–200 k) : 2026-03-02 SBE `bbo-tbt` « requires login starting 3rd March 2026 » ; 2026-03-31 « xperp: perpetual-style futures,
only applicable to certain FUTURES contracts », « Funding rate endpoints now support querying X-Perps futures contracts », `instType`
enum `FUTURES` « X-Perps futures » ; 2026-04-15 « Mark price channel » listé ; 2026-05-20 « Regional domains (us.okx.com, eea.okx.com,
tr.okx.com) remain unchanged » ; 2026-06-09 « The WS adl-warning channel will stop pushing data in normal state » ; 2026-06-23 « books5
and bbo-tbt channels do not carry a checksum field » ; 2026-07-16 « A Pre-market X-Perp is identified by ruleType = pre_market together
with instType = FUTURES » ; 2026-08-20 « The state: live event is pushed only after the order has successfully entered the order book ».
Pages d'aide : `https://www.okx.com/en-eu/help/whats-selected-margin-eea` (màj 2026-09-03 : « shared pool of collateral », « listed in your
X-Perps margin settings », « Haircuts are tiered and can change », « a loss on one position draws on the same collateral as all your
others » ; BTC/ETH spot non nommés) ; `https://www.okx.com/en-eu/help/x-perps-guide` (màj 2026-10-20 : « USDC, USDG, or USD », One-way /
Hedge, collecte 00:00 / 08:00 / 16:00 UTC « at different intervals », « defined future expiration date ») ;
`https://www.okx.com/en-eu/help/x-perps-contract-specifications` (màj 2026-10-19 : BTC « 0.01 BTC », tick « 0.1 », « 2031/04/04 16:00 HK
time », « Up to 10x », funding « 8 hours », « cash settled », OEM) ; aide ADL `https://www.okx.com/en-us/help/iv-introduction-to-auto-deleveraging-adl`
(« ranked based on the leverage PnL% », « highest positive leverage PnL% will be offsetted first », « at the mark price », « Trading fees
are not charged on deleveraged positions », « no warnings are issued prior to ADL occurring », « 1 to 5 bars ») ; OKX API Agreement
`https://www.okx.com/en-eu/help/okx-api-agreement` (« Last Updated: 28 July 2026 », §9.4 cité en §7) ; FAQ frais EEE
`https://www.okx.com/en-eu/help/trading-fee-rules-faq-eea` (màj 2026-10-07 : « Forced liquidation fees are charged according to the taker fee
rate of your current level » ; 0,02 % / 0,05 % en exemple de calcul seulement). **Non re-vérifiés aujourd'hui** (sections WS Market Data /
Public Data hors du texte rendu ; restent sur les extraits indexés de la passe précédente) : `books5` 100 ms ; `bbo-tbt` 10 ms ;
`liquidation-orders` (`instType`, champs, disclaimer « doesn't represent the total number », retrait REST 2023) ; cadences `mark-price`,
`funding-rate`, `instruments`, `trades` ; `instType` accepté par le canal privé `orders` ; entrée changelog 2026-05-06 (titre seul).

**UNKNOWN laissés tels quels** : hôte WS public EEE exact ; couverture X-Perps de `liquidation-orders`, `books5`, `mark-price`, `funding-rate` (WS) ;
X-Perps en démo ; `posMode`, règle de gel de marge, devise USD-M, barème API ; `ctVal` 0,0001 vs 0,01 ; `ctType` des X-Perps (linear / inverse) ;
spot comme Selected Margin ; durée du run MTF ; valeur de la limite de taux par `instId` ; endpoint des réglages de marge ; `instType` accepté par
le canal privé `orders` pour FUTURES.
