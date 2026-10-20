# État des campagnes de recherche TradingV3 (au 2026-10-15 ; §4 complété le 2026-10-20)

Cet inventaire est fait en lecture seule. Il reprend :

- les rapports `docs/handbook/reports/*.md` d'origin/main (`1f64006e`) ;
- la branche `claude/research-h2-gate1-a1` (PR #511, ouverte, non fusionnée) ;
- les titres des PR.

Aucun rendement n'a été recalculé. Les données du test forward `fwd-okx-r1` n'ont pas été lues : seul son calendrier
publié est repris.

**Bilan.**

- Aucune campagne ne fournit aujourd'hui de stratégie validée hors échantillon.
- Aucune campagne ne s'exécute sur un produit accessible depuis un compte français.
- **H1 et H2 sont clos.** Le propriétaire l'a confirmé le 2026-10-15 à 19:05:53Z : « conserver H1 et H2 clôturées,
  puis examiner les éventuelles propositions H3 avant d'autoriser une nouvelle campagne ».
- La seule campagne en cours est le test forward `fwd-okx-r1` (verdict prévu après le 2026-10-30).

> **Correction importante.** H1 n'attend plus de décision d'exécutabilité. Après GATE1_PASS (#505), le Gate 2
> historique a été exécuté sous l'amendement A3 et a donné **`GATE2_FAIL`** (#508, 2026-10-15). Par la règle v1 §17,
> **H1 est clos**. Il n'y aura ni moteur, ni test prospectif S_C, ni chemin d'exécution X-Perps pour H1
> (`docs/handbook/research/H1-GATE2-RESULT.md`). Détails dans [H1-NEXT-DECISION.md](H1-NEXT-DECISION.md).

## 1. Légende

- **Verdict scientifique** : le résultat du protocole tel que figé.
- **Exploitabilité** : le marché et le produit sur lesquels la campagne a été testée, et s'ils sont utilisables
  depuis la France selon `RESEARCH-ELIGIBILITY-GATES.md` :
  - Binance : **bloqué**, source statistique uniquement ;
  - swaps USDT d'OKX Global : **non disponibles** pour un résident français ;
  - X-Perps d'OKX Europe : **conditionnels** (compte vérifié à l'interface, API non vérifiée).
- **Statut opérationnel** : `clos` (verdict final, rien en cours), `en cours`, `bloqué` ou `en attente` (il manque
  une décision du propriétaire).

## 2. Tableau consolidé

| Campagne | Verdict scientifique | Exploitabilité (France / X-Perps) | Statut opérationnel | PR | Données consommées | Date |
|---|---|---|---|---|---|---|
| **H1** — dérive post-listing des nouveaux perpétuels (short) | S_A (OKX) : `S_A_SCREEN_PASS`, −11,4 % à 14 j, instable (positif en 2023–24). S_B (Binance) : `GATE1_PASS`, 238 événements, S14 −16,1 %. **Gate 2 historique : `GATE2_FAIL`**. 70 trades exécutés sur 321 événements (205 écartés par le minimum Binance de 5 USDT), +0,027 R, PF 1,10 < 1,2, −0,023 R sans le top 5 %, stress pessimiste −0,007 R. Le diagnostic OKX-first (+0,092 R sur 52 trades) n'est pas décisif et ne peut pas sauver H1. | Audit `H1_XPERP_CONDITIONAL` : 9 tokens éligibles, tous OKX-first, environ 2,2 événements par mois, funding toutes les 4 h, plafond ±1 %. Compte vérifié à l'interface (marge USDC), API non vérifiée. Connecteurs TradingV3 en USDT-SWAP seulement. **Sans objet depuis le GATE2_FAIL.** | **clos** (v1 §17) | #502, #503, #505, #506, #507, #508 | OKX 1m : t0 → t0 + 31 j des 83 (+13) listings S_A, t0 dans [2023-09-01, 2026-09-01). Binance : 1m des deux premiers mois de chaque événement ; archives mensuelles journalières et horaires de l'indice (30 membres) et des 20 contrôles par événement (S_B : 501 archives 1m, 10 246 journalières, 2 164 horaires). Funding Binance (1 208 fichiers) et OKX (55 fichiers) pour le Gate 2. | 2026-10-14 → 2026-10-15 |
| **H2** — retour d'un mouvement résiduel (net du marché) sur 3 j, Gate 1 (étude d'effet, A1) | **`FAIL`**, `fail_due_to_G4_alone=false`. G2, G3, G4, G5 et G7 échouent ; G1 et G6 passent. 890 événements, 113 bases, 45 mois. y moyen −9,0 pb, IC 95 [−90,6 ; +67,8]. S1 −45 pb. Sans le top 5 % : −125 pb. Côté court +29,3 pb : observation exploratoire seulement. | Testé sur l'archive OKX USDT-SWAP, étiquetée « développement ». **Rien n'est autorisé** : ni Gate 2, ni setup, ni trading. | **clos : `H2_GATE1_FAIL_CLOSED`** (décision du propriétaire, 2026-10-15T19:00:27Z). PR #511 **ouverte, non fusionnée** ; elle porte `H2-CLOSURE-DECISION.md`. | #504 (prérequis : `PREREQUISITES_NOT_MET`), #510 (gel v1), #511 (A1, regel, résultat, clôture) | OKX 1m dernier prix, de 2022-09 (chauffe) à 2026-10-06T16:00Z. Les 20 bases du programme sont lues sur OKX pour la première fois ; les autres familles via 9 665 npz swing v2. Décisions dans [2023-01-01, 2026-10-01). Fenêtres β de 60 j : les 42 premiers jours de chaque série sont **nouvellement consommés**. Ces données sont consommées pour tout usage confirmatoire futur. | gel 15:57:04Z, verdict 17:11:30Z, clôture 19:00:27Z, le 2026-10-15 |
| **Q3 V2 / scalp-q3-r2** (Q3 long, stop 2 %, portefeuille 500 USDT) | Entraînement : RESEARCH_CANDIDATE, C4-SL +13,0 pb, négatif sans le top 5 %. Gate corrigé : C4-SL passe, C2-SL en `STATISTICAL_BLOCKER`. **Validation nov. 2024 : `VALIDATION_FAIL`** (−20,7 pb, PF 0,71, 5 paires positives sur 20, 10 critères échoués sur 12). | Testé sur Binance USD-M (bloqué). Pas de capture prospective OKX dédiée. X-Perps : non testé. | **clos**. C2-SL : ne pas rouvrir. | #498, #499 | Binance aggTrades et bookDepth : 9 semaines de 2025 (entraînement, déjà lues), 2024-11-04 → 29 (validation), funding 2024-11. | 2026-10-14 |
| **q3x** — ablation exploratoire de Q3 | Exploratoire, sans verdict. Le flux porte l'avantage (+9,6 pb) ; la profondeur ajoute +0,5 pb. Négatif sans le top 5 % (−1,2 pb). | Binance (bloqué). | **clos** (exploratoire) | #497 | Les 9 fenêtres d'entraînement r8 de 2025, déjà lues. | 2026-10-14 |
| **fwd-okx-r1** — test forward de la règle `Q3-W900-K1.5-T0.1` long (lignée r8b) | **En attente.** Repères historiques : r8 entraînement +12,7 pb ; r8b validation (janv. 2025) +18,6 pb, passe ; r8b holdout (déc. 2024) +6,5 pb, échoue au contrôle des paires (9/20). | Données publiques OKX USDT-SWAP, aucun ordre. Le produit n'est pas accessible depuis un compte français. Le transfert vers les X-Perps est **NON PROUVÉ** (carnet, funding et liste de paires différents). | **en cours**. Services `fwd-okx-capture` et `fwd-okx-evaluate` actifs (relevé du 2026-10-15T19:11Z). | #483, #490, #491, #492 | W0 2026-10-14 → 16 (rodage, ne compte pas), W1 2026-10-19 → 23 (validation), W2 2026-10-26 → 30 (holdout). **Réservées, à ne jamais recycler.** | capture depuis le 2026-10-13 ; verdict prévu le 31 oct. (selon l'orchestrateur, non vérifié dans un fichier) |
| **Swing v1** — exploration 4h, breakouts bilatéraux, campagne canonique `B30_2s × S3.T5.H14d` | Exploration : aucun candidat éligible. Breakouts bilatéraux : 19 éligibles, non robustes. **Campagne canonique : échec au holdout 2026** (PF 1,01 / 0,97, 267 trades, +0,078 R / +0,019 R). | Binance (bloqué). | **clos** (le claim de holdout 2026 n° 1 est consommé) | #462, #463, #464–#473, #475 | Majors 2023-24 (entraînement), 2025 (validation), 2026-01-01 → 2026-10-09T06Z (holdout, claim 1). | 2026-10-11 → 12 |
| **Swing v2** — breakouts classés 4h | **Aucun candidat** : validation 0/96 (mid-caps 2023-25). Lecture 2026 post hoc : +0,17 R, surplus +0,05 R (p 0,35), indicative seulement. | Binance (bloqué). | **clos**. Le test paper forward approuvé (#494/#495) est caduc depuis la clôture par la règle 7 (#500). Aucune preuve qu'il ait démarré : **INCONNU**. | #494, #495 | Majors 2023-24 ; mid-caps 2023-01 → 2026-01-01 ; mid-caps 2026-01-01 → 2026-10-09T06Z (claim de holdout 2026 n° 2). | 2026-10-14 |
| **Swing v2 OKX** | **Rejeté** : 360 trades, −0,027 R, PF 0,95, surplus −0,047 R (p 0,77). Swing V2 clos par la règle 7. | OKX USDT-SWAP (non disponible en France). | **clos** | #500 | 193 séries OKX 1m et funding, 2023-01 → 2026-10-12T16Z (jours ≥ 42 après le début de chaque série). | 2026-10-14 |
| **regime-r2** — détecteurs de régime D1–D6 | Aucun détecteur ne passe Q1 et Q2. D5 passe Q1 et échoue à Q2 (prédiction INCONCLUSIVE, entraînement seulement). | Binance (bloqué). | **clos**. Validation (2023-24) et test final (2025-26) jamais téléchargés par regime-r2. | #496 | Binance journalier : spot 2017-08 → 2023-01, perpétuels et funding 2020-01 → 2023-01. | 2026-10-14 |
| **Levier > 2×** (hors BTC) | **NOT_INFORMATIVE** : principal sans trade, S1–S9 avec p Holm = 1. S4 : Δ −0,095 R sur 240 trades (< 400). Post hoc, sur données consommées. | Sans objet (étude descriptive). | **clos** | #509 | Les 4 semaines d'entraînement 2024 de r10–r12, déjà lues. Aucune donnée nouvelle. | 2026-10-15 |
| **r10 / r10x** — setups plateforme 1.1.0 / 1.2.0 sur ticks | r10 : 0/96, aucun candidat. r10x : exploratoire (zone + sortie temporelle, piste reprise par r11). | Binance (bloqué). | **clos**. Aucun contrat 1.2.0 publié. | #484, #485 | aggTrades : semaines 2024-04, 05, 06, 08, 20 paires. | 2026-10-13 |
| **r11** — zone VWAP + sortie temporelle | 0/12 contre le contrôle restreint à la zone. Clos dès l'entraînement. | Binance (bloqué). | **clos** | #486 | Idem r10 (entraînement). | 2026-10-13 |
| **r12** — zone seule | Validation sept. 2024 (mid-caps) : −10,4 / −5,2 pb, sous le contrôle. | Binance (bloqué). | **clos** | #487 | Entraînement 2024 ; mid-caps 2024-09 (validation). | 2026-10-13 |
| **r13** — positionnement open interest (OI) | Clos à l'entraînement : 0/8. O2 bat le contrôle sans passer les gates. | Binance (bloqué). | **clos** | #488 | aggTrades et metrics, semaines 2024. | 2026-10-13 |
| **r14** — OI build-up (O2) | Validation oct. 2024 : −11,7 pb, sous le contrôle. Holdout non exécuté. | Binance (bloqué). | **clos**. Nov. 2024 non lu par r14. Il est **partiellement consommé** depuis : aggTrades et bookDepth 2024-11-04 → 29 des 20 paires Q3 (#499). Le recouvrement avec les mid-caps de r14, et la lecture des metrics de nov. 2024, sont **NON VÉRIFIÉS**. | #489 | Mid-caps 2024-10, aggTrades et metrics. | 2026-10-13 |
| **Tick r1–r9** — exécution, scalping tick, mid-caps r6/r7, carnet r8/r8b/r9 | r1/r2 : 0 passe. r3–r7 : le signe dépend de la fenêtre (r7 : +12,8 pb en nov., −20,9 pb en déc.). r8 profondeur : −7 à −9 pb ; Q3 : +14 pb. r8b : échoue à la cohérence des paires. r9 file d'attente / microprix : −2,1 pb avant frais. | Binance, et captures OKX/HL de 24 h (exploratoire). | **clos**. Seule piste transmise : Q3 long → fwd-okx-r1. | #478, #480, #481 | Semaines 2025 (aggTrades, bookDepth) ; nov. et déc. 2025 ; 2025-01-13 → 17 et 2024-12-09 → 13 ; bookTicker 2023-07, 09, 11 et 2024-01 ; OKX run14 / HL run15 (24 h). | 2026-10-12 → 13 |
| **Day trading** (setup 1.1.0, pullback MTF) | INVALIDATED : filtres publiés à 0 trade ; la meilleure version relâchée fait +0,12 R sur 31 trades en 2025. | Binance (bloqué). | **clos** | #461 | Binance 1m → 15m, majors 2023-24 et 2025. | 2026-10-11 |
| **Sélection v2** (24 setups, 15m/4h) | INVALIDATED : 8 passent l'entraînement ; les 4 sélectionnés perdent en 2025 (PF 0,72–0,88). | Binance (bloqué). | **clos** | #476 | Majors 2023-24 et 2025. | 2026-10-12 |
| **Scalping sur bougies** (5m/1m) | INVALIDATED : 0/40, médianes −0,30 R / −0,62 R. | Binance (bloqué). | **clos** | #477 | Majors 2023-24. | 2026-10-12 |

Sources des verdicts :

- `docs/handbook/reports/research-*.md` (origin/main) ;
- `docs/handbook/research/H1-*.md` ;
- sur la branche `claude/research-h2-gate1-a1` : `docs/handbook/reports/research-h2-gate1-2026-10-15.md`,
  `docs/handbook/research/H2-GATE1-RESULT.json` et `docs/handbook/research/H2-CLOSURE-DECISION.md` ;
- la matrice de `research-strategy-landscape-2026-10.md`.

## 3. Données consommées

« Consommé » signifie lu pour un résultat. Une donnée consommée peut encore servir au développement, jamais à une
validation indépendante. Ce tableau complète celui du rapport de paysage (`research-strategy-landscape-2026-10.md`,
« Data already consumed ») avec les lectures faites depuis le 2026-10-14.

| Fenêtre | Univers / produit | Niveau | Études | Rôle |
|---|---|---|---|---|
| 2023-01 → 2024-12 | 10 majors Binance | 1m → 4h + funding | day trading, swing, campagne canonique, v2, scalping bougies, swing v2 | entraînement (lu de nombreuses fois) |
| 2025 | 10 majors Binance | 1m → 4h | day trading, swing, campagne, v2 | validation |
| 2026-01-01 → 2026-10-09T06Z | 10 majors / 10 mid-caps Binance | 4h + funding | campagne swing (claim 1) ; swing v2 post hoc (claim 2) | holdout, **2 claims utilisés** |
| 2023-01 → 2026-01-01 | 10 mid-caps Binance | 1m → 4h + funding | swing v2 | validation |
| 9 semaines de 2025 | 20 paires Binance | aggTrades + bookDepth | r5–r8, q3x, Q3 V2 | entraînement |
| Nov. et déc. 2025 | majors / mid-caps | aggTrades | r3, r7 | validation / holdout |
| 2025-01-13 → 17 ; 2024-12-09 → 13 | 20 paires | aggTrades + bookDepth | r8b | validation ; holdout |
| Semaines 2023-07, 09, 11 ; 2024-01 | 18 paires | bookTicker | r9 | entraînement |
| Semaines 2024-04, 05, 06, 08 | 20 paires | aggTrades + metrics | r10–r13, levier > 2× (relecture) | entraînement |
| 2024-09 ; 2024-10 | mid-caps | aggTrades (+ metrics) | r12 ; r14 | validation |
| 2024-11-04 → 29 | 20 paires Q3 | aggTrades + bookDepth + funding | Q3 V2 (#499) | validation (VALIDATION_FAIL) |
| 2017-08 → 2023-01 (spot) ; 2020-01 → 2023-01 (perps) | top 50 Binance | journalier | regime-r2 | entraînement |
| 2023-01 → 2026-10-12T16Z | 193 séries OKX, jours ≥ 42 après le début | OKX 1m + funding | swing v2 OKX (#500) | test unique |
| **t0 → t0 + 31 j des 83 (+13) listings S_A**, t0 dans [2023-09-01, 2026-09-01) | **listings OKX** | **OKX 1m** | **H1 screen S_A, Gate 2** | **développement (screen)** |
| **2023-09 → 2026-08** | **listings Binance (S_B, 240 événements), indice (30 membres) et 20 contrôles par événement** | **Binance perps : 1m des deux premiers mois de chaque événement ; archives mensuelles journalières et horaires ; funding** | **H1 Gate 1 S_B, Gate 2** | **Gate 1 décisive, puis Gate 2** |
| **2022-09 → 2026-10-06T16Z** (décisions 2023-01-01 → 2026-10-01) | **20 bases du programme et familles OKX hors programme** | **OKX 1m (dernier prix)** | **H2 Gate 1** | **développement ; inclut les fenêtres β de 60 j** |
| 2026-10-02/03, 24 h | BTC, ETH | OKX run14 / HL run15 | exécution r1 | exploratoire |
| W0 / W1 / W2 (2026-10-14 → 16, 19 → 23, 26 → 30) | 20 paires OKX | données publiques en direct | fwd-okx-r1 | **réservé, à ne jamais recycler** |

**Correction de la liste « jamais lu » du rapport de paysage.** Ce rapport compte encore « les 42 premiers jours de
chaque série OKX » parmi les données jamais lues. Ce n'est plus vrai :

- H1 S_A a lu t0 → t0 + 31 j des 83 (+13) listings OKX de sa fenêtre ;
- H2 a lu les fenêtres β de 60 j, donc les 42 premiers jours de chaque série de son univers ;
- les rendements, résidus et événements H2 2023–2026 sur OKX sont lus aussi.

Restent non lus d'après les documents consultés (à notre connaissance, sans preuve exhaustive) :

- l'intraday Binance hors des 20 paires du programme, hors des symboles lus par H1 S_B (listings, indice, contrôles)
  et hors des mid-caps des études tick ;
- les ticks des paires du programme hors des semaines listées ;
- les metrics OI hors r13/r14 ;
- le journalier Binance 2023–2026 que regime-r2 réservait à sa validation et à son test final : **partiellement
  consommé** par H1 S_B (perpétuels de l'indice et des contrôles) ; le spot n'a pas été lu. Le recouvrement exact est
  **INCONNU** tant que les manifestes ne sont pas comparés ;
- l'historique des X-Perps (qui existent depuis le 2026-03-30). Aucun résultat n'a été calculé dessus à notre
  connaissance : il n'a servi qu'à des relevés de métadonnées et de spécifications ;
- toute donnée future.

## 4. Ce qui reste ouvert

| Sujet | État | Qui décide / suite |
|---|---|---|
| **fwd-okx-r1** | En cours. W1 et W2 en attente. Résultats non lus ici. | Exécution automatique selon le protocole figé. Le rejeu de W0 (rodage) est annoncé par l'orchestrateur vers le 17 oct. 02:00, heure de Paris (non vérifié dans un fichier). Verdict après W2 (31 oct.). Même un PASS ne porterait que sur l'OKX USDT-SWAP, non accessible depuis la France ; le transfert vers les X-Perps resterait à démontrer. |
| **PR #511** (H2 A1, résultat, clôture) | Ouverte, non fusionnée. Le texte de description à jour est prêt ; l'orchestrateur doit l'appliquer. | Orchestrateur et propriétaire (pas de fusion automatique). |
| **H2** | **Clos** : `H2_GATE1_FAIL_CLOSED`. Gate 2, setup, trading et récupération (côté court, long inversé, autres horizons) sont interdits. | Décision prise. Toute idée voisine devra déclarer avoir vu les résultats H2 (`H2-CLOSURE-DECISION.md` §4). |
| **Incident disque pendant H2** | Cause établie après coup : une écriture d'environ 36 Gio liée à une activité personnelle sans rapport avec la recherche, sur le même disque (≈ 15:58Z → 16:18Z). H2 n'a écrit que 24 blocs. L'autre session locale (nettoyage de fichiers personnels) n'a fait que supprimer des fichiers après l'arrêt : c'est elle qui a rendu l'espace. Swap saturé depuis le 11 oct. | Voir [RESEARCH-INFRA-DIAGNOSTIC.md](RESEARCH-INFRA-DIAGNOSTIC.md). Aucun effet sur le verdict (§12 du protocole respecté : une reprise sur une autorisée). |
| **Vérification API des X-Perps** | Non faite. Le conteneur PHP ne contient que des clés démo OKX EEA. Le script `tradingV3-private/tools/okx_xperp_readonly_check.py` est fourni au propriétaire ; aucune preuve qu'il ait été exécuté. | Le propriétaire. C'est la prochaine action (§5). |
| **Écart technique X-Perps** | Le code OKX ne gère que l'USDT-SWAP : `instType=SWAP` est codé en dur et l'USDT est supposé partout. Les X-Perps sont réglés en USD avec une marge en USDC. | À traiter seulement si une hypothèse franchit ses gates. Rien n'est construit pour H1. |
| **Questions du rapport de paysage** | Breakouts rejetés : `NOT_TESTED`. Prédiction de régime D5 : `INCONCLUSIVE` (entraînement seulement). Q3 C2-SL : `STATISTICAL_BLOCKER` (`research-scalp-q3-r2-correction-2026-10-14.md`), à ne pas rouvrir. Le rapport de paysage emploie l'ancien libellé `TECHNICAL_BLOCKER`. Cycle de vie des instruments : testé par H1, clos. Mouvements relatifs au marché : testés par H2, clos. | Le propriétaire examine les propositions H3 (`H1-NEXT-DECISION.md` §3) avant d'autoriser une campagne, en passant les gates M1–M5 dans l'ordre. |
| **Théorie du scalping et gaps (desk 2026-10-20)** | Rapport `../reports/research-scalping-theory-gap-2026-10-20.md` : le scalping à la minute sur états est fermé par la théorie et par le programme ; ce qui reste est un programme de fourniture de liquidité événementielle à mesurer (préalables P1–P3, puis G2 mark-outs sur carnets larges, G4 cascades de liquidation, G5 horloge, G1 réversion en fournisseur sur consentement). Fait établi : le profil `scalper_micro` 1.1.7 ne peut poster aucun ordre en production (preuve microstructure absente du chemin vivant). Douze décisions du propriétaire (§6). | Le propriétaire. Rien n'est lancé. |
| **Contrat scalping 1.2.0** | Non publié (r10, 0/96). | Aucun contrat n'est publiable en l'état. |

**Aucune campagne n'autorise aujourd'hui de trading, réel ou en démo.** Les drapeaux d'exécution restent à 0.

## 5. Prochaine action unique (non lancée)

**Le propriétaire exécute lui-même `tradingV3-private/tools/okx_xperp_readonly_check.py`.** Le détail et la
justification sont dans [H1-NEXT-DECISION.md](H1-NEXT-DECISION.md) §4.

- Le script fait deux GET en lecture seule.
- Il ne couvre que l'accès aux X-Perps. Le spot d'OKX Europe (prestataire MiCA, nécessaire à l'idée H3-A) demanderait
  une vérification séparée.
- Rien d'autre n'est lancé tant que le propriétaire n'a pas examiné les propositions H3.

**Backlog (décisions du propriétaire, ce n'est pas la prochaine action) :**

- les mesures et la surveillance proposées dans [RESEARCH-INFRA-DIAGNOSTIC.md](RESEARCH-INFRA-DIAGNOSTIC.md) §6–§7 ;
- les corrections documentaires de [H1-NEXT-DECISION.md](H1-NEXT-DECISION.md) §5 ;
- la copie, dans `run/` de H2, du journal de la première tentative, aujourd'hui conservé dans un répertoire temporaire
  de session.
