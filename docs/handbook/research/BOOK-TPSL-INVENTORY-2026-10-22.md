# TP/SL adaptés au carnet — inventaire de faisabilité (2026-10-22)

**Idée du propriétaire.** Placer ou déplacer le take-profit et le stop-loss d'une position de scalping selon le carnet
d'ordres : profondeur, murs, déséquilibre.

**Question.** Avec les données déjà sur disque et la plateforme telle qu'elle est, un test de cette idée est-il
faisable ? À quelle précision ? Qu'est-ce qui bloque ?

**Nature du document.** Inventaire de bureau. Aucune recherche, aucune recherche de paramètres, aucun backtest. Aucun
prix, aucune taille, aucun rendement et aucun PnL n'a été chargé ou affiché. Seules des métadonnées ont été lues :
manifestes, reçus, noms de champs, dates, nombres de fichiers et d'événements, tailles, cadences, écarts de temps. Aucun
appel réseau, aucun ordre, aucun drapeau modifié. **Ce document ne recommande pas de trader.**

**Réponse courte.**

- Aucune donnée sur disque ne donne le carnet **par niveau de prix** (L2). On ne peut donc localiser un mur sur aucune
  source.
- Ce qui reste : du L1 (meilleur bid/ask avec taille) sur 24 h pour BTC et ETH (OKX `run14`, HL `run15`), et des
  ticks Binance sans carnet. Les carnets Binance `bookDepth` et `bookTicker` téléchargés ont été effacés le
  2026-10-13 ; seuls leurs manifestes restent.
- La plateforme ne lit le carnet qu'en instantané REST, au moment de la décision. Elle ne modifie le TP/SL qu'au plus
  toutes les 3 minutes, par annulation puis replacement, sans `amend`. Elle ne peut donc pas réagir à un changement de
  carnet.
- Le seul test honnête aujourd'hui est une exploration de **développement**, sur données déjà consommées, à faible
  puissance. Elle ne peut rien conclure sur les murs, ni sur les X-Perps.

## 1. Données sur disque

### 1.1 Exclusions (règle de la mission)

Non listé, non ouvert, sans `stat` à l'intérieur :

| Dossier sous `tradingV3-private/research/` | Raison |
|---|---|
| `20261013T100000Z-forward-okx-r1`, `fwd-okx-r1-legacy-format` | fwd-okx-r1, réservé |
| `20261013T100000Z-forward-okx-runtime` | lignée forward-okx (seul son interpréteur a servi à `mkdocs build`) |
| `20261014T1200Z-scalp-q3-r2` | Q3 V2, validation 2024-11, protégé |
| `20261014T130000Z-q3x`, `20261014T130000Z-tape-binance-q3x` | lignée Q3 : **doute, donc sauté**. Ces dossiers contiennent peut-être des `bookDepth` Binance de 2025. Non vérifié. |
| `20261020T2321Z-g5-periods`, `20261021T0700Z-scalping-diagnostic/g5-a1` | G5 |
| `20261021T1500Z-dynamic-exits/run1` | sortie de calcul (résultat), non ouverte |

### 1.2 Inventaire des jeux avec trades ou carnet

« Registre » renvoie à la table « Données consommées » de
[RESEARCH-CAMPAIGN-STATUS.md](RESEARCH-CAMPAIGN-STATUS.md) §3.

| Jeu | Venue, produit | Symboles | Dates | Granularité et champs | Taille | Registre | Sur disque ? |
|---|---|---|---|---|---|---|---|
| `tape-binance-r10` (aggTrades) | Binance USD-M | 20 paires (ADA … XRP, SEI, TIA) | 4 semaines : 2024-04-15→19, 05-13→17, 06-10→14, 08-12→16 (20 jours, 400 fichiers) | tick : `ts_us`, `price`, `size`, `taker_buy`, `agg_trade_id` | 1,1 Gio | consommé, entraînement (r10–r13, levier > 2×) | oui |
| `tape-binance-r10` (klines) | Binance USD-M | 20 paires | 2024-03 → 2024-08 (120 fichiers) | bougies 1 min | — | idem | oui |
| `tape-binance-r13-train-metrics` | Binance USD-M | 20 paires | mêmes 20 jours | `metrics` toutes les 5 min (OI, ratios) | 6,7 Mio | consommé, entraînement | oui |
| `tape-binance-r12-validation` | Binance USD-M | 10 mid-caps (APT, ARB, ATOM, FIL, INJ, NEAR, OP, SEI, SUI, TIA) | 2024-09-01 → 30 (300 fichiers) | aggTrades (tick) + klines | 385 Mio | consommé, validation (r12) | oui |
| `tape-binance-r14-validation` | Binance USD-M | les mêmes 10 | 2024-10-01 → 31 (310 + 310 fichiers) | aggTrades (tick) + `metrics` 5 min | 549 Mio | consommé, validation (r14) | oui |
| `tape-binance-r8` (bookDepth) | Binance USD-M | 20 paires | 50 jours, 2025-01-13 → 2025-10-17 (9 semaines d'entraînement + janv. 2025), 1 000 fichiers | bandes de profondeur : notionnel **cumulé** dans ±1, 2, 3, 4, 5 % du mid, par côté ; un instantané toutes les 30 s (médiane des écarts 30 s ; écart max 1 106 s) ; 2 842 040 instantanés, 0 écarté | npz 158 Mo | consommé (r5–r8, q3x, Q3 V2 entraînement ; r8b validation) | **non : effacé le 2026-10-13**, manifeste seul |
| `tape-binance-r8-holdout` (bookDepth) | Binance USD-M | 20 paires | 2024-12-09 → 13 (100 fichiers) | idem ; écart max 39 s | npz 19 Mo | consommé, holdout r8b | **non**, manifeste seul |
| `tape-binance-r8`, `-holdout` (aggTrades) | Binance USD-M | 20 paires | 2025, 2024-12 (100 + 100 fichiers) | tick | — | consommé | **non**, manifeste seul |
| `tape-binance-r9` (bookTicker) | Binance USD-M | 18 paires | 25 jours : 2023-07-10→14, 09-11→15, 11-13→17, 2024-01-15→19 (entraînement) et 2024-02-12→16 (validation), 450 fichiers | L1 seulement : meilleur bid/ask et leurs tailles. Brut : chaque mise à jour (≈ 2,2 G mises à jour). Converti : un enregistrement par seconde avec mise à jour, plus le nombre de mises à jour et le déséquilibre moyen de file de la seconde | zip 25 Go, npz 653 Mo | entraînement consommé (r9). Février 2024 : pas de fichier de validation r9 dans `part-b/binance-r9` → **lecture non vérifiée**, sans doute non lu | **non**, manifeste seul |
| `tape-binance-r9-holdout` (bookTicker + aggTrades) | Binance USD-M | 18 paires | 2024-03-25 → 29 (90 fichiers) | idem | — | **non inscrit au registre** ; aucun fichier holdout r9 → sans doute non lu | **non**, manifeste seul |
| `tape-binance-r1`, `midcap-r1` et leurs holdouts | Binance USD-M | 10 majors / mid-caps | semaines 2025, nov. et déc. 2025 | aggTrades | 3,4 Gio en tout | consommé (r1–r7) | **non**, manifestes seuls |
| **OKX `run14`** (`representative-okx-20261002t072026z-…-attempt-002`) | OKX USDT-SWAP, public | BTC, ETH | temps réel 2026-10-02T13:33Z → 10-03T13:33Z (24 h) ; bougies de chauffe depuis 2026-08-21 | `top_of_book` (1 648 858 événements) : `bid/ask_price`, `bid/ask_size_contracts`, `bid/ask_order_count`, `source_seq_id`. `public_trade` (2 758 251) : `price`, `size_contracts`, `taker_side`, `trade_id`. Bougies 1m → 1h, funding, métadonnées. 0 trou de séquence, 18 changements de connexion | 2,7 Gio | consommé, exploratoire (exécution r1, tick r1–r9) | oui (lecture seule) |
| **HL `run15`** (`representative-hyperliquid-20261002t081009z-…-attempt-001`) | Hyperliquid, public | BTC, ETH | 2026-10-02T08:10Z → 10-03T08:10Z (24 h) | `top_of_book` (1 010 279, origine `ws_bbo`) : `bid/ask_price`, `bid/ask_size`, `bid/ask_level_count`. `public_trade` (470 465) : `price`, `size`, `side`. 0 trou de séquence | 1,2 Gio | consommé, exploratoire | oui (lecture seule) |
| Captures paper incomplètes (OKX 2026-10-01 ×2, 2026-10-02 attempt-001 ; HL 2026-10-01 ×2) | OKX, HL | BTC, ETH | jusqu'au 2026-10-02 au plus tard | mêmes canaux, état `incomplete` | 72 Mio → 1,6 Gio | **non inscrites au registre** | oui (lecture seule) |
| `execution-r1/okx.npz`, `hl.npz` | dérivés de `run14` / `run15` | BTC, ETH | idem | extraits L1 et trades (reçus : latence de réception p50 ≈ 127 ms OKX, ≈ 367 ms HL) | 173 Mo, 64 Mo | consommé, exploratoire | oui |
| Capture X-Perps du programme LP (`okx-xperp-capture-2026-11-02`) | OKX Europe X-Perps, WebSocket public | ≤ 40 X-Perps + 2 swaps | **pas démarrée** ; plancher codé 2026-11-02T00:00Z | canaux prévus : `trades`, `books5`, `liquidation-orders`, `mark-price`, `funding-rate`, `index-tickers` ; `bbo-tbt` et `books-rpi` à coder | — | registre LP : `prospective`, partagée par P3, G2, G4, G5 Gate 2 | **non** (rien avant le 2026-11-02) |
| Autres jeux OKX et Binance (`tape-okx-swing-v2*`, `tape-okx-h2`, `h1-listing`, `swing-*`, `regime-*`, `10pairs`, `benchmark-*`) | OKX, Binance | divers | 2017 → 2026-10 | bougies 1 min et plus, funding | — | consommés | oui, **sans carnet** |

Le code de conversion est dans `python-orchestrator/scripts/research_exec/acquire.py:14-16` (bookDepth : seules les
bandes ±1 … 5 % sont gardées, colonne `notional`) et `:194-224` (bookTicker : réduit à une ligne par seconde).

### 1.3 Ce que contiennent vraiment `run14` et `run15`

Le brief supposait des carnets `books5` ou L2. **Ce n'est pas le cas.**

- OKX : l'enregistreur s'abonne au canal `books` (`OkxPaperPublicLiveSource.php:5738`) et matérialise le carnet. Mais
  il n'écrit que le meilleur niveau (`OkxPaperMarketEventNormalizer.php:305-350`, champ `bid_price`,
  `bid_size_contracts`, `bid_order_count`, idem côté ask).
- HL : abonnements `trades` et `bbo` (`HyperliquidPaperPublicSubscriptionSet.php:12`). Toutes les tops du jeu viennent
  de `ws_bbo`.
- Cadence moyenne (comptage) : ≈ 19 tops par seconde pour OKX et ≈ 12 pour HL, deux symboles réunis.

On ne peut donc reconstruire **ni la profondeur à un prix, ni la file au-delà du premier niveau**, sur aucune des deux
captures.

### 1.4 Ce que chaque source permet de reconstruire

| Source | Profondeur à un niveau de prix | File d'attente | Déséquilibre | Mur |
|---|---|---|---|---|
| bookDepth Binance (effacé) | non : notionnel cumulé dans ±k %, k = 1 … 5 | non | oui, grossier : bandes de 1 %, toutes les 30 s | non : un mur se voit seulement s'il change le cumul d'une bande de 1 % |
| bookTicker Binance (effacé) | premier niveau seulement | taille au meilleur prix, par seconde | oui, L1 (`qi_mean` par seconde) | non |
| OKX `run14`, HL `run15` | premier niveau seulement | taille et nombre d'ordres au meilleur prix, à chaque mise à jour | oui, L1, à la mise à jour | non |
| aggTrades Binance (sur disque) | non | non | flux agresseur seulement | non |
| Capture X-Perps prévue (`books5`) | 5 niveaux | 5 niveaux | oui, 5 niveaux | seulement dans les 5 premiers niveaux |

## 2. Plateforme : comment le TP/SL est posé et modifié

### 2.1 Pose et modification

| Étape | Comportement | Référence |
|---|---|---|
| SL à l'entrée | Ordre `STOP_LOSS` séparé, reduce-only, sans prix limite (OKX : `slOrdPx = -1`, donc au marché au déclenchement) | `trading-app/src/TradeEntry/Execution/ProtectionEnforcer.php:96-117` ; `src/Exchange/Okx/OkxActionFactory.php:60-68` |
| Pas de TP/SL attaché à l'entrée | Refusé par l'adaptateur OKX | `OkxActionFactory.php:21-23` ; `src/Exchange/Adapter/OkxExchangeAdapter.php:57-74` (`supportsAttachedStopLossOnEntry: false`, `supportsModifyOrder: false`) |
| TP après remplissage d'une limite | `TpSlTwoTargetsService` appelé sans annulation (`cancelExisting…: false`) | `src/TradeEntry/MessageHandler/LimitFillWatchMessageHandler.php:293-313` |
| Type d'ordre OKX | SL et TP = ordres algo `conditional` sur `/api/v5/trade/order-algo`, déclenchés sur le **prix mark** ; annulation par `/api/v5/trade/cancel-algos` | `OkxExchangeAdapter.php:158-166`, `:223-231` ; `OkxActionFactory.php:40-72` |
| SL du recalcul | Stop-limit avec limite = prix de stop (« for determinism ») : risque de non-exécution sur un saut | `src/TradeEntry/Service/TpSlTwoTargetsService.php:686-700` |
| Modification | **Annuler puis replacer.** Aucun `amend` dans le code OKX ni HL | `TpSlTwoTargetsService.php:514-611` (annulations), `:614-770` (placements) ; `src/Exchange/Adapter/HyperliquidExchangeAdapter.php:82` |
| Source des niveaux | ATR 1 min × 4 et pivots, pas le carnet | `config/app/trade_entry.scalper_micro.yaml:41-55` ; `TpSlTwoTargetsService.php:154-193` |

### 2.2 La fenêtre non protégée

Dans `TpSlTwoTargetsService`, l'ordre est :

1. lecture des ordres ouverts (`:527`) ;
2. annulation du SL s'il diffère d'un tick ou plus (`:550-572`) ;
3. annulation des TP (`:588-611`) ;
4. calcul du partage TP1/TP2 : peut lire le cache de validation et l'ATR (`:618-640`) ;
5. placement du nouveau SL (`:686-700`), puis TP1 (`:715`) et TP2 (`:745`).

Entre 2 et 5, la position n'a **pas de stop**. La durée est au moins deux allers-retours REST, plus les annulations de
TP et le calcul. Si le placement du SL est refusé, le code ne fait que l'omettre de `submitted` (`:690`) : pas de
nouvel essai ni de fermeture d'urgence dans ce service. La réconciliation détecte les positions sans protection
(`src/Exchange/Reconciliation/ExchangeReconciliationService.php:144`, `:235`).

### 2.3 Ce que le chemin live voit du carnet

| Lecture | Profondeur | Quand | Référence |
|---|---|---|---|
| `getOrderBookTop` | REST `market/books`, `sz=1` | à la demande | `OkxExchangeAdapter.php:294-310` |
| `MarketStructureSampler` | REST, 10 niveaux | contrôles avant l'entrée | `src/TradeEntry/Service/MarketStructureSampler.php:16`, `:35` |
| Validation (spread, « OFI ») | REST, 20 niveaux | pendant la validation MTF | `src/MtfValidator/Service/TimeframeValidationService.php:710` |
| `getOrderBook` | REST, jusqu'à 400 niveaux | à la demande | `src/Provider/Okx/OkxMetadataProvider.php:91-107` |
| WebSocket public | **aucun** : « OKX-003 keeps runtime market data on REST polling » | — | `src/Exchange/Okx/README.md:28` |
| WebSocket privé | observateur en lecture seule, **désactivé par défaut** (profil Compose `okx-observability`) | — | `src/Exchange/Okx/README.md:29` ; `docker-compose.yml:381` |

Aucune lecture du carnet n'a lieu pendant la vie d'une position.

### 2.4 Cadences et temps de réaction

| Composant | Cadence | Référence |
|---|---|---|
| Boucle MTF | une fois par minute (`*/1 * * * *`) | `cron_symfony_mtf_workers/scripts/manage_scalper_micro_schedule.py:53` |
| Recalcul TP/SL | seulement si la minute UTC est multiple de 3, après l'exécution MTF | `src/MtfRunner/Service/MtfRunnerService.php:260-266`, `:1096-1101` |
| Gardes du recalcul | position ≥ 180 s ; saut si le TP est à moins de 0,3 % ; saut si le TP est partiellement rempli | `trade_entry.scalper_micro.yaml:173-176` |
| Surveillance d'une limite d'entrée | premier passage à 5 s, puis toutes les 5 s (Messenger `order_timeout`, transport Doctrine) | `src/TradeEntry/Execution/ExchangeExecutionService.php:35`, `:330-343` ; `LimitFillWatchMessageHandler.php:31` ; `config/packages/messenger.yaml:4-7`, `:31` |

**Temps de réaction minimal réaliste à un changement de carnet : environ 60 s pour voir le changement (prochaine
boucle), et jusqu'à 3 min pour modifier un TP/SL.** Un mur qui apparaît puis disparaît entre deux boucles n'est jamais
vu. La durée de vie des murs n'est pas mesurée ici.

### 2.5 Contrainte France

- Seuls les X-Perps d'OKX Europe sont une cible d'exécution ([FRANCE-EXCHANGE-ELIGIBILITY.md](FRANCE-EXCHANGE-ELIGIBILITY.md)).
- Le code OKX ne gère que l'USDT-SWAP : `instType=SWAP` est codé en dur
  (`src/Exchange/Okx/OkxContractValueResolver.php:68`, `src/Exchange/Okx/OkxInstrumentResolver.php:16-37`).
- Binance est bloqué : données de recherche seulement. Hyperliquid n'est pas une place d'exécution (drapeaux à 0).
- Les captures `run14` (USDT-SWAP) et `run15` (HL) ne portent pas sur le produit exécutable.

## 3. Tableau de faisabilité

| Variante de l'idée | Donnée nécessaire | Meilleure source sur disque | Précision possible | La plateforme peut-elle l'exécuter ? |
|---|---|---|---|---|
| A. TP placé juste avant un mur, SL juste derrière un mur, **fixés à l'entrée** | L2 par niveau de prix à l'instant d'entrée, jusqu'à la distance du SL | **aucune** | nulle : aucun L2 sur disque. bookDepth (effacé) ne descend pas sous ±1 % (100 pb) et ne donne qu'un cumul ; poser un ordre devant un mur demande la profondeur au tick près | en partie : un instantané REST jusqu'à 400 niveaux est disponible à l'entrée, mais `TpSlTwoTargetsService` ne s'en sert pas (code à écrire) |
| B. TP/SL modulés par un déséquilibre **grossier** (bandes ±1 … 5 %) | bandes de profondeur, toutes les 30 s | bookDepth Binance 2025 / 2024-12, **effacé** (à retélécharger) ; peut-être dans les dossiers Q3 (non inspectés) | grossière : 30 s, bandes de 1 %, aucun mur | non : pas de lecture du carnet en vie de position, cadence 3 min |
| C. Sortie anticipée ou TP tenu selon le déséquilibre **L1** | L1 avec tailles, à la seconde ou mieux | `run14` / `run15` : 24 h, BTC et ETH, 2 venues non exécutables ; bookTicker Binance (effacé) | faible puissance : 24 h × 2 symboles ; mesure L1 seulement | non : pas de flux L1 en direct, réaction ≥ 60 s |
| D. TP/SL **déplacés** quand un mur apparaît ou disparaît | flux L2 à la sous-seconde | **aucune** | nulle | non : pas de flux, pas d'`amend`, annuler-replacer avec fenêtre non protégée, cadence 3 min |
| E. Même chose sur X-Perps | L2 X-Perps | **aucune** avant le 2026-11-02 (et `books5` seulement, 5 niveaux) | inconnue | non : X-Perps non gérés par le code |

## 4. Conclusion

### 4.1 Ce qui ne peut pas être conclu

- Rien sur les murs : aucune source sur disque ne donne la profondeur par prix.
- Rien sur les X-Perps : aucune donnée de carnet X-Perps n'existe avant le 2026-11-02. Le transfert depuis Binance,
  l'USDT-SWAP ou Hyperliquid n'est pas prouvé.
- Rien d'exécutable : la plateforme réagit en minutes, pas en secondes.
- Aucun PnL : ce document n'en calcule aucun et n'en annonce aucun.

### 4.2 Étape minimale et honnête, si le propriétaire veut la tester

1. **Exploration de développement, pré-enregistrée, sur données déjà consommées.** Candidat le moins cher :
   `run14` + `run15` (L1, 24 h, BTC, ETH, statut « exploratoire »). Question limitée à la variante C : le déséquilibre
   L1 au moment d'une sortie change-t-il le résultat d'une règle TP/SL fixe ? Étiquette obligatoire :
   « développement, non confirmatoire ». Puissance faible, résultat non transférable.
2. **Variante B**, si elle intéresse : retélécharger un bookDepth Binance **déjà consommé** (semaines 2025 d'r8) avec
   les aggTrades des mêmes jours. Il faut une décision du propriétaire (réseau, disque). Les fichiers de février et
   mars 2024 (bookTicker r9) semblent non lus : ils doivent rester réservés à une validation, pas servir à explorer.
3. **Capture prospective avec le bon canal.** Pour les variantes A, D et E, il faut du L2 par prix sur les X-Perps :
   `books` (400 niveaux, incrémental) ou au moins `books5`, **écrit en L2** (l'enregistreur paper n'écrit que le L1).
   La capture LP prévue au 2026-11-02 inclut `books5`. Mais elle est réservée aux gates P3, G2, G4 et G5 Gate 2. S'en
   servir pour ce test demande une nouvelle décision et une ligne au registre LP. Tout ce qui est daté du 2026-10-14 au
   2026-10-31 reste en quarantaine.

### 4.3 À faire avant tout test d'exécution

| Prérequis | Pourquoi |
|---|---|
| Modifier les algos par `amend` au lieu d'annuler-replacer (OKX propose un amend des ordres algo : à vérifier dans la doc OKX Europe, pas vérifié ici) | supprimer la fenêtre sans stop (§2.2) |
| Flux carnet en direct (WebSocket public) dans le chemin d'exécution | aujourd'hui seulement des instantanés REST (§2.3) |
| Surveillant de position piloté par événement, sous la seconde | aujourd'hui 60 s à 3 min (§2.4) |
| Support des X-Perps (`instType=FUTURES`, marge USDC) | seul produit exécutable depuis la France (§2.5) |
| SL du recalcul au marché au déclenchement, comme celui de l'entrée | la limite égale au stop peut ne pas s'exécuter |

Aucune de ces étapes n'est lancée par ce document. Les drapeaux d'exécution restent à 0.
