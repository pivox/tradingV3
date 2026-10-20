# LP — Règles transversales du programme « fourniture de liquidité événementielle » et du track S

**Statut : `DRAFT_NOT_FROZEN`** (2026-10-20, volet a3 du programme `20261020T1430Z-lp-programme`). Ce document fixe les règles
que **tout** pré-enregistrement du programme (G1, G2, G4, G5 ; G6 sur décision ; track S) doit instancier. Il ne gèle rien, ne
lit rien, n'autorise rien. Il met en forme les règles 1–14 du §3.5 et les décisions 1–12 du §6 du rapport
`docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md`, et, pour le track S, le §2.1 (K1), §4.1 (points 7, 10, 11,
12, 13, 14) et §6.2 du rapport `docs/handbook/reports/research-positive-pnl-structural-2026-10-16.md` (les deux rapports vivent
sur des branches non fusionnées à `main` au 2026-10-20 : worktree `research-scalping-theory`). Il complète, sans les modifier,
`RESEARCH-ELIGIBILITY-GATES.md` (M1–M5) et `RESEARCH-CAMPAIGN-STATUS.md` §3 (données consommées).

**Révision du 2026-10-20 (passe unique après les relectures `review/quant.md` et `review/cto.md`, verdicts `NEEDS_CHANGES`).**
Statut inchangé. Constats bloquants et majeurs appliqués dans le corps ; détail constat → traitement en §12 « Traitement de la
revue » (refus écrits « Réponse à la revue »). Deux corrections demandées portent sur le rapport théorie (branche
`claude/research-scalping-theory-gap`, hors du périmètre d'écriture de cette passe) : elles sont **signalées** ici (§6.2, §12),
pas appliquées.

**Convention** : « programme » = chiffre recopié d'un rapport du dépôt ; « calcul d'audit » = arithmétique sans donnée ;
**UNKNOWN** = ni sourcé ni mesuré. **Aucun chiffre de marché** ne figure ici.

---

## 0. Ce que le programme est, et n'est pas

Le programme mesure, en **prospectif** sur OKX Europe X-Perps, si un participant **lent** (boucle à la minute, 500 USDT, 2/5 pb)
peut être **payé pour l'immédiateté** dans des poches événementielles que la théorie désigne (rapport théorie §1, lignes 3 et 10) :
capture de spread sur carnets larges (G2), retour post-cascade (G4), horloge (G5), réversion en fournisseur conditionnée (G1,
sur consentement). Ce sont des **mesures**, pas des stratégies. Le programme ne dit d'aucune poche qu'elle paie après coûts ;
sa valeur minimale est de **clore proprement** la question du scalping retail à la minute, mécanisme compris (rapport théorie §5).

Vocabulaire (décision 4 du rapport théorie, en attente) : tant que le propriétaire n'a pas tranché, les textes du programme
emploient « fourniture de liquidité événementielle » pour le track L et « portage de funding couvert » pour le track S, sans
renommer le standing objective.

---

## 1. Porte économique (règle 11 ; décision 9 du rapport théorie)

1.1 **Formule, écrite avant le gel de chaque protocole** :

    plafond_USD_an = fills_admis_par_jour × notionnel_moyen_USD × net_pb / 10 000 × 365

avec `fills_admis_par_jour` et `notionnel_moyen_USD` pris dans la **lecture portefeuille** (§4 : 500 USDT, 1×, ≤ 4 engagements
vivants, 100 USD par ordre ; rapportée sous les deux hypothèses de `posMode` tant qu'il n'est pas lu) et `net_pb` = **toujours un
aller-retour** (entrée et sortie réellement simulées, frais des deux jambes au rôle réel, sélection adverse mesurée des deux
jambes, funding des règlements traversés, coût de la marge gelée (§5), queue de liquidation) — jamais un mark-out d'entrée net du
seul frais maker, qui est une demi-transaction (relecture quant B1 ; Part A, `research-execution-tick-2026-10-12.md` l.60–70 :
mark-out net −3,05 … −3,49 pb contre aller-retour taker −8,1 … −8,7 pb) ; rapporté avec ses bornes LB90 / UB90. Ordres de grandeur du rapport théorie (calcul d'audit, §5
ligne 5) : 600 fills en 8 semaines × ~100 USD × +10 pb ≈ 350–400 USD/an ; 2 créneaux de 50 USD × 5 fills/jour ≈ 90 USD/an. Ce
sont les ordres de grandeur **si tout passe** ; ce ne sont pas des prévisions.

1.2 **Seuil d'arrêt (à fixer par le propriétaire ; proposition a3, amendée par la relecture quant M7)** : une famille est
**« poursuivie »** (Phase 4 d'architecture proposable, proposition démo possible) si et seulement si le **point** de
`plafond_USD_an` est ≥ S_éco **et** (UB90 ≥ 2 × S_éco **ou** LB90 > 0) ; elle est **« non poursuivie »** sinon — une condition
sur la seule UB90 laisserait « poursuivie » toute famille à intervalle large (point 20 USD/an, UB90 120, contre S_éco ≈ 97 USD :
calcul d'audit 5 × 3,89 % × 500). Les deux conditions sont écrites **avant** gel. Proposition : S_éco = max( 5 × rendement sans
risque USD du capital au SOFR du jour du gel (NY Fed, taux fiat, hors quarantaine) ; coût fixe annuel déclaré du programme ). Le
coût fixe déclaré comprend : les heures du propriétaire (relevés, décisions, vérifications), les frais « requester-pays » de
l'archive Hyperliquid (G4), l'entretien du collecteur, le **stockage** de la capture (calcul d'audit a2 §3.1 repris par la
relecture CTO §4.3 : ≈ 170 Mo/jour/instrument de `books5` non compressé ; 40 instruments × 150 jours ≈ 1 To brut ; compression
`savez_compressed` UNKNOWN, à mesurer à P3), la **supervision** d'un processus WS de plusieurs mois (unité de service, alertes
`stale`/`connect_failed`, rotation de `health.log`) et le `verify` quotidien ; le collecteur et le simulateur déjà écrits (a2)
sont des coûts échoués et n'entrent pas. Le propriétaire peut fixer un S_éco plus haut ; jamais plus bas après un résultat.

1.3 **Règle d'arrêt du programme** : si, après les verdicts de G2, G4 et G5, aucune famille n'a simultanément un `PASS_PROGRAMME`
(§2) et la condition « poursuivi » de §1.2, le programme de mesures est **clos** par un rapport ; G1 n'est alors lancé que si le
consentement a été donné **avant** ces verdicts et si la table de coût G2 laisse une marge économique (sinon il est annulé sans
lecture). Durée maximale du programme : **fin du scoring de la dernière fenêtre déclarée ≤ 2027-03-31** (sauf décision écrite
contraire du propriétaire avant cette date).

1.4 **Lecture obligatoire dans chaque verdict** : « PASS, poursuivi » / « PASS, non poursuivi (sous S_éco) » / « FAIL » ; un PASS
scientifique sous la porte économique est publié comme tel.

---

## 2. Budget de multiplicité (règle 10 ; Holm sur G1–G5)

2.1 **Famille** = les tests **confirmatoires primaires** sur le produit cible, un par protocole gelé : **G2-C2** (aller-retour
`net_bp` ; G2-C1 est une mesure de coût, critère de PASS mais pas le test de la famille — quant B1), G4-Gate2-C1, **G5-Gate1-C1**
(Gate 1 **historique confirmatoire** sur `okx-xperp-1m-history`, §8 ; quant B3) et G5-Gate2-C1 (prospectif), G1-C1 (et G6-C1 si le
propriétaire l'autorise). Le Gate 1 d'Hyperliquid (G4) est une **porte de développement** sur une donnée non cible : il n'entre
pas dans la famille (son seuil est strict par construction ; un faux positif y coûte 90 jours de Gate 2, pas un faux PASS du
programme — quant m6, déclaré). Les phases **screen** ne comptent pas **si et seulement si** elles sont gelées comme screen
**avant** la lecture et que le test confirmatoire correspondant est gelé **avant** le screen ; une stratification lue avant le gel
du test qu'elle informe (ex. G2 §7.6 heure/session lue avant le gel de G5, quant M10) rend ce test **post-screen** : le screen
compte alors dans **m** comme un test à p = 1. **m** = nombre de tests de la famille **gelés** à la date de la lecture finale,
qu'ils aient été informatifs ou non (un `NOT_INFORMATIVE` ou `INCONCLUSIVE` compte comme p = 1 : jamais de retrait a posteriori —
règle **figée**, quant m10). **FWER visé : 10 %** (unilatéral) sur la famille ; les critères secondaires obligatoires de chaque
protocole (robustesse, contrôle, économie) abaissent l'α effectif de chaque PASS en dessous de son α nominal (déclaré, non
chiffré).

2.2 **Niveau** : α_famille = 0,10 **unilatéral** (c'est le « LB90 > 0 » de chaque protocole, exprimé en p-value bootstrap
unilatérale p_i = P*(statistique ≤ 0)). **Holm** : p_(1) ≤ … ≤ p_(m) ; le test (i) est confirmé si p_(j) ≤ α / (m − j + 1) pour tout
j ≤ i. Avec m = 5 : le plus significatif doit avoir p ≤ 0,02 (LB98 > 0), puis 0,025, 0,033, 0,05, 0,10.

2.3 **Lecture intermédiaire** (les verdicts tombent à des dates différentes) : un protocole qui satisfait son critère local
(LB90 > 0 **et** ses seuils économiques) reçoit `PASS_LOCAL`. Il devient `PASS_PROGRAMME` **immédiatement** si p_i ≤ α / m_max,
où m_max = taille **planifiée** de la famille écrite ici (**m_max = 5** : G2-C2, G4-Gate2, G5-Gate1, G5-Gate2, G1 ; 6 si G6 est
autorisé ; +1 par screen devenu post-screen, §2.1) — c'est
Bonferroni, toujours ⊆ Holm ; sinon il attend la **clôture de la famille** (dernier verdict ou date de §1.3) pour l'application de
Holm. Aucune Phase 4 d'architecture ne démarre sur un `PASS_LOCAL`.

2.4 **Dans chaque protocole** : **un** signe et **une** fenêtre pré-déclarés par event study ; les horizons, règles de fill,
tranches, variantes et stratifications supplémentaires sont **descriptifs** et ne reçoivent ni p-value ni verdict ; une analyse
« secondaire » ne peut pas devenir primaire par amendement après une lecture. Rappel du rapport théorie : neuf tests unilatéraux
à 5 % sans correction ⇒ ~37 % de chance d'un faux pass.

---

## 3. Puissance (règle 9)

3.1 **Unité d'observation** : l'**instant** (événement, cluster par heure UTC inter-instruments : G4, G5), le **slot** (G6 si
autorisé), le **fill** regroupé par **blocs de 10 min** (G2, G1). Jamais le trade isolé, jamais la paire-semaine. L'incertitude
est calculée par **bootstrap par blocs** (`lp_sim.block_bootstrap_lower_bound` : blocs de 600 s, 1 000 rééchantillons, graine
20261102 ; blocs de 3 600 s pour les instants), poolé sur l'univers avec le bloc comme unité.

3.2 **Taille minimale, formule** (calcul d'audit) : n ≈ ((z_{1−α} + z_{1−β}) · σ / δ)² × DE, α = 0,10 unilatéral (z = 1,28),
puissance 0,80 (z = 0,84), δ = effet cherché, σ = écart-type de l'unité, DE = effet de plan du regroupement (1 + ρ(k − 1)).
Pour δ = +10 pb, σ ≈ 100 pb (ordre de grandeur d'audit du rapport théorie, pas une mesure), DE ≈ 1,3 : n ≈ 450 × 1,3 ≈ **600**
(le « ≈ 600 trades » de la règle 9). σ est **UNKNOWN** sur X-Perps avant lecture : les portes sont des **planchers de conception**,
jamais recalculées sur la donnée lue. **Chaque protocole déclare son effet minimal détectable en unités de σ** (quant M6 ; desk
§4.3) : à n observations, δ_min ≈ 2,12 × σ × √DE / √n (≈ 0,24 σ à n = 100, DE 1,3 ; ≈ 0,09 σ à n = 600) — et dit si
`NOT_INFORMATIVE` est l'issue probable au plancher.

3.3 **Planchers par protocole** : G2 ≥ **1 000 fills** dans la cellule primaire (variante minute, spread ≥ 6 pb) et ≥ 5 paires à
1 000 (relaxation « par paire-tranche » déclarée, G2 §7.2) ; G4 ≥ **100 instants** par porte (effet cherché grand et rare : +10 pb
est un seuil économique ; la détection à 100 instants vaut pour ≈ **0,24 σ** de l'unité — ≈ +24 pb si σ ≈ 100 pb, 40–75 pb si σ
est, comme probable après cascade, de 150–300 pb ; `NOT_INFORMATIVE` est l'issue probable sous 0,25 σ, G4 §7.2) ; G5 Gate 1
historique ≥ **100 instants par type d'événement** (le jeu `okx-xperp-1m-history` en offre ≈ 600 par photo de funding sur
≈ 200 jours — calcul d'audit, pas une lecture) et G5 Gate 2 prospectif ≥ **100 instants par type** ; G1 ≥ **600 fills** en
simulation sans contrainte (critère stress − calme). Sous le plancher : `NOT_INFORMATIVE`, **jamais d'extension sur résultat**,
prolongation **pour trous de données** seulement, bornée et écrite au gel.

3.4 **Interdits de puissance** (rapport théorie §4.2 point 6) : pas de porte « 300 trades / 20 paires / 4 semaines » ; pas de
« 70 % des paires » ; la réponse à une puissance insuffisante est d'**élargir l'univers** (plus d'instruments éligibles), pas
d'allonger la fenêtre après lecture.

---

## 4. Deux lectures (règle 7)

4.1 **Simulation sans contrainte** : toutes les intentions ; sert la **puissance** et le **signe** ; c'est sur elle que portent les
critères scientifiques (C1…).

4.2 **Portefeuille 500 USDT** (`lp_sim.portfolio_admit`) : `capital = 500`, `leverage = 1`, `max_live = 4`, `order_notional = 100` ;
ordres admis dans l'ordre des décisions tant que < 4 engagements vivants et Σ notionnels ≤ capital × levier ; un engagement dure
jusqu'à la sortie (rempli), à l'activation (`crossed`) ou au TTL (non rempli) ; marge gelée = notionnel à 1×. Sert
l'**exploitabilité** (M5) et la **porte économique** (§1). Aucun impact de marché supposé (déclaré).

4.3 Les deux lectures sont rapportées dans chaque verdict ; un PASS scientifique sans exploitabilité est publié « PASS, non
poursuivi ».

---

## 5. Funding et marge gelée dans tous les nets (règle 7)

5.1 **Funding** : `funding_bp = −side × Σ taux des règlements traversés (first_fill < T ≤ exit)`, taux **réels** de la bande
(`funding-rate` ; repli REST `public/funding-rate-history` lu **après** T0 seulement), signe réel (un long paie un taux positif).
Intervalle **par instrument, lu dans la tape** (`funding_time_us`, `next_funding_time_us` ; 8 h ou 4 h selon le contrat) ;
le **raccourcissement automatique** à 2 h / 1 h quand le plafond est touché est documenté pour les « crypto perpetual contracts »
seulement (page funding EEE, màj 2026-08-27 : « Automatic frequency adjustment applies only to crypto perpetual contracts »,
`LP-DATA-DESK` §4.1) — son application aux X-Perps (`ruleType xperp`) est **UNKNOWN** (quant M8 ; `H1-XPERP-MARKET-ACCESS.md` l.55
l'affirme sans source distincte) : le code ne suppose aucun intervalle, il lit les règlements. **Stress** : chaque règlement traversé compté au plafond `max_rate` de l'instrument contre la position (sensibilité
obligatoire). Stratification descriptive par signe du prochain taux publié.

5.2 **Marge gelée** : coût d'opportunité = notionnel gelé × durée (jours) / 365 × taux sans risque USD (SOFR du jour du gel),
en pb du notionnel ; négligeable à 60 min, écrit quand même ; la **capacité** (≤ 4 ordres de 100 USD vivants) est la vraie
contrainte et vit dans la lecture portefeuille.

5.3 **Frais** : 2 pb maker / 5 pb taker (vérifiés à l'interface du compte le 2026-10-15, `H1-XPERP-ACCOUNT-CHECK.md` ; barème API
UNKNOWN) ; **robustesse frais × 1,5** (3 / 7,5 pb) obligatoire ; rabais maker sous programme MM : **UNKNOWN**, jamais supposé.

5.4 **Sélection adverse** : **mesurée** (mark-out), jamais supposée ; la table G2 (C1, sélection adverse nette d'entrée) est
l'entrée de coût des autres protocoles ; à défaut, valeur de conception −3,5 pb par jambe (borne haute de Part A). **Écrémage
RPI / ELP** (quant B5 ; docs-v5 : « a taker without RPI access can execute only against nonRpiQty », « resting RPI orders are
currently hidden under the matching rules ») : si des teneurs RPI cotent un X-Perp, le flux retail manuel est servi par eux et le
carnet organique ne reçoit que le flux API/pro — la sélection adverse mesurée sur les X-Perps est **structurellement supérieure**
à Part A (USDT-swaps) et n'est pas comparable à elle. Ce coût est **dans** la mesure (pas ajouté) ; ce qui est exigé : capture de
`trades.source` et du nombre d'ordres par niveau, règle `queue` sur prints `source = 0` (G2 P-G2-4 v), relevé de `instruments.rpi`
et de la part `totalQty − nonRpiQty` (G2 P-G2-9) ; tant que ce n'est pas fait, **biais vers le PASS** déclaré.

5.5 **Queue** : liquidation forcée au taux **taker** (FAQ frais EEE) si la position est liquidée en lecture portefeuille (à 1×
isolé avec `mmr` 0,65 %, mouvement de l'ordre de 99 % requis — calcul d'audit, quant m4) ; ADL sans préavis (page ADL) déclaré
comme risque non modélisable ; **bandes de prix** (`floatPxLmtPct`, `maxPxLmtPct`) : rejet possible d'un post-only au touch après
un mouvement violent, non modélisé, déclaré **biais vers le PASS** et compté (`band_rejected`) ; **aucun stop serré** sur une
règle de fourniture (règle 12) — la taille et des bornes de perte pré-déclarées portent le risque ; coupe de catastrophe ≥ 3 σ en
lecture portefeuille seulement.

---

## 6. Registre des données et statuts

6.1 **Statuts** (un par ligne du registre, jamais rétrogradé) :

| Statut | Définition | Usage permis |
|---|---|---|
| `unread` | jamais lu pour un résultat ni pour une sélection (ex. `okx-xperp-1m-history-2026-03-30..2026-10-13`, §6.2 : jamais lu par H2 — quant B3) | tout, après gel (pour une Market Data : décision P2 préalable) |
| `selection` | relevé unique de **métadonnées** ou d'un **comptage de messages** (zéro valeur de marché ; ex. comptages P3 par instrument à T0 − 1), haché, antérieur au gel ; **jamais** un prix, un spread ou un volume (`volCcy24h` exclu — quant M11, §9.5) | choisir l'univers ; jamais un résultat |
| `prospective` | produit cible, période postérieure au gel, garde `--not-before` | confirmation (le seul statut qui confirme) |
| `development` | donnée d'un autre produit ou période (ex. archive HL pour G4 Gate 1) | screen, Gate 1, calibration ; jamais une confirmation |
| `consumed_equivalent` | lu pour un résultat (une fois) | développement d'une autre hypothèse **déclarée**, jamais sa validation |
| `reserved` | W0–W2 de `fwd-okx-r1` (2026-10-14→16, 19→23, 26→30) | **aucun**, jamais |
| `quarantine` | tout ce qui est daté 2026-10-14..31, toute venue | **aucun** ; métadonnées datées dans la fenêtre à **re-figer après le 2026-10-31** |

6.2 **Registre** : `tradingV3-private/research/20261020T1430Z-lp-programme/REGISTRY.md` (une ligne par jeu : identifiant, venue,
produit, instruments, fenêtre, statut, hash, protocole, date de lecture) ; après chaque verdict, la table §3 de
`RESEARCH-CAMPAIGN-STATUS.md` est complétée (dépôt). Lignes à créer pour ce programme : `okx-xperp-prospective-G2`,
`okx-xperp-prospective-G4`, `okx-xperp-prospective-G5`, `okx-xperp-prospective-G1`, `hl-fills-G4-gate1` (`unread`),
**`okx-xperp-1m-history-2026-03-30..2026-10-13`** (`unread` ; bougies 1 m `history-candles` des X-Perps du listing 2026-03-30 au
2026-10-13, hors quarantaine ; Market Data §9.4 ⇒ lecture soumise à P2, décision 15 de §11 ; réservé au Gate 1 historique de G5,
§8 — quant B3), `okx-xperp-selection-T0-1` (`selection`, comptages P3), `okx-xperp-funding-trackS` (`prospective`).

**Lien avec H2, écrit noir sur blanc** (quant B3). L'« univers secondaire » d'H2 était formé des séries **USDT-SWAP** (OKX Global)
des bases figurant dans la liste des 160 X-Perps crypto du 2026-10-15 (`PREREGISTRATION-H2-GATE1.md` l.82–88 : « the events of the
primary run whose base is one of the 160 live crypto X-Perps ») ; la liste X-Perps n'y est qu'une métadonnée hachée.
`RESEARCH-CAMPAIGN-STATUS.md` l.111–112 : l'historique des X-Perps « n'a servi qu'à des relevés de métadonnées et de
spécifications ». Les bougies 1 m des X-Perps sont donc **`unread`**, et un Gate 1 G5 dessus n'est **pas** une récupération d'H2
au sens d'`H2-CLOSURE-DECISION.md` §4 : hypothèse différente (horloge, signes gelés depuis la théorie ; H2 testait un résidu à
3 jours), produit différent (X-Perps, pas SWAP) ; la seule exposition est une période partiellement commune aux bases lues en
SWAP, déclarée comme telle. Le rapport théorie (l.143 et 353 : « l'univers X-Perps 1 m a été lu par H2 ») porte une **erreur de
fait** à corriger dans sa branche (hors du périmètre de cette passe) ; sa raison de suspendre G6 tient par H2 §4 seul.

6.3 **Licence** (§9.4 de l'OKX API Agreement, « Last Updated: 28 July 2026 », relu le 2026-10-20) : séries et tape dans
`tradingV3-private` seulement ; ce que le dépôt `pivox/tradingV3` reçoit (protocoles, verdicts, agrégats sans série) est une
**décision du propriétaire** (visibilité du dépôt ; « display » de §9.4(a) ; `LP-DATA-DESK` §2.3 Q3 ; rapport structurel §6.2
décision 8 ; décision 14 de §11) — **par défaut, tant qu'elle n'est pas prise, les agrégats chiffrés restent dans
`tradingV3-private`** et le dépôt ne reçoit que protocoles et verdicts qualitatifs (quant m8) ; la décision du propriétaire sur
l'échelle de capture (P-G2-1) précède toute capture longue. Archive Hyperliquid : licence **non publiée** (UNKNOWN), décision du
propriétaire (P-G4-2).

---

## 7. Confirmation : `okx-xperp-prospective` ≥ 2026-11-02 (règle 13)

7.1 **La seule confirmation admise** est une fenêtre `prospective` sur OKX Europe X-Perps, commencée **au plus tôt le 2026-11-02**,
lue par **capture publique + simulateur de recherche** (`research_lp capture` + `lp_sim`/`events`), après gel sha256 et en une
exécution.

7.2 **Jamais** : le chemin Paper **certifié** (`app:paper-market:*`, cellules de certification — le chemin ne connaît pas le
produit X-Perp, `instType SWAP` codé en dur, A1 §4), la **démo** (drapeaux à 0, propriété du propriétaire), un **ordre** réel, un
backtest `app:backtest:*` sur X-Perps, une relecture de `run14`/`run15` comme preuve. Tant que l'écart technique X-Perps
(`H1-XPERP-TECHNICAL-GAP.md`) n'est pas comblé par des travaux séparés et une décision séparée, aucune exécution n'est un
véhicule de confirmation.

7.3 **Pourquoi** : un simulateur de recherche gelé est auditable et rejouable (« rejeu = direct », A1 §5 Phase 3) ; la
certification Paper a été conçue pour d'autres contrats et produit 0 trade par cellule sur 1.1.0 (CLAUDE.md) ; la démo exige des
drapeaux et un chemin produit qui n'existent pas, et n'ajouterait qu'un risque sans ajouter d'information sur le signe.

---

## 8. Séquence (règle 14 ; décisions 1, 7, 8, 11 du rapport théorie)

    P1  diagnostic de la machine (journaux, champs texte, zéro prix)           → livrable avant toute autre action (bloquant G2)
    P2  propriétaire : §9.4 lu et décidé sur l'échelle réelle (capture continue → sans oui, pas de capture longue
        2026-11-02 → ≤ 2027-03-31, ≤ 40 X-Perps + 2 swaps, hôte global) ;
        lecture d'okx-xperp-1m-history (unread, Market Data) pour G5 Gate 1 ;
        option market-data-history (fichiers T+2/T+3, docs globale seule) ;
        GET lecture seule au compte X-Perps (posMode, levier, permissions,
        marge gelée, barème API, programme MM)
    ─── rien ci-dessous avant le verdict fwd-okx-r1 (après le 2026-10-31) ───
    P3  capture de comptage 24–72 h (research_lp count), ≥ 2026-11-02         → couverture × fréquence par canal × instrument
                                                                                 (+ books-rpi : part RPI au touch)
    G5  Gate 1 HISTORIQUE CONFIRMATOIRE sur okx-xperp-1m-history (unread) :    → gelé AVANT la lecture de G2 (G2 P-G2-10) ;
        signes et fenêtres gelés depuis la théorie (§3.3 G5), dans la            une exécution ; PASS/FAIL par type d'événement
        famille Holm, une exécution, ≥ 100 instants/type (≈ 600 disponibles)
    G2  capture ≥ 2 semaines, gel avant T0, une exécution                      → sélection adverse nette d'entrée (C1, entrée
                                                                                 de coût de G1/G4/G5) + aller-retour net (C2, test)
    G4  Gate 1 HL (après consentement ; peut courir pendant la capture G2)     → PASS ⇒ gel du Gate 2 OKX (90 j) ; FAIL ⇒ non
                                                                                 poursuivi faute de preuve de développement
    G5  Gate 2 PROSPECTIF (X-Perps, capture continue) : mêmes signes et       → confirmation, ≥ 100 instants/type ; armé à
        fenêtres que le Gate 1, aucune analyse promue                            arm_ts_us (h−5 / h−2), jamais h−1 (CTO M2)
    G1  réversion en fournisseur conditionnée — seulement sur consentement     → critère stress − calme seul
        explicite portant sur la différence déclarée (décision 5)
    G6  suspendu à une décision explicite (H2-CLOSURE-DECISION.md §4)         → hors plan sans décision
    G7–G9, M3 : seulement s'il reste une marge ; G9 est gratuit dans G2

Chaque étape produit un livrable **avant** que la suivante soit gelée ; le gel d'un protocole n'attend pas le verdict du précédent
sauf là où un prérequis l'exige (G4 Gate 2 attend G4 Gate 1 ; G1 attend la table G2 et le consentement ; **G5 Gate 1 est gelé
avant la lecture de G2**, sinon G2 §7.6 est un screen qui compte dans m — quant M10). **G5 réécrit en deux portes** (quant B3) :
l'ancien plan « screen étiqueté sur X-Perps 1 m `consumed_equivalent`, puis confirmation prospective 4–5 semaines » reposait sur
un statut **faux** (ces bougies n'ont jamais été lues, §6.2) et aurait brûlé en screen un jeu vierge du produit cible offrant
≈ 10 fois la puissance du prospectif (≈ 200 jours × 3 photos ≈ 600 instants par type pour 00/08/16 UTC — calcul d'audit). Le
Gate 1 historique est **confirmatoire** parce que la règle 10 le permet : un signe et une fenêtre par type, venant de la théorie
et du rapport (§3.3 G5 : « si la prime publiée à h−1 est > 0, le mid baisse sur [h−15 ; h] et remonte sur [h ; h+15] ;
ouverture : le signe des 5 premières minutes se poursuit sur [h+5 ; h+60] »), jamais du jeu ; contrôle = mêmes mesures aux heures
sans événement ; F dans le net par signe (intervalle lu par instrument, §5.1) ; sa lecture exige la décision P2 (Market Data
§9.4, décision 15) et passe le jeu à `consumed_equivalent`. Le futur brouillon G5 écrit que la décision d'armer est prise à
`arm_ts_us` (h−5 ou h−2), jamais à la minute suivant h (CTO M2 : **corrigé dans le code** par la passe parallèle
`a5-corrections-cto` — `lp_sim.event_decisions` décide à `arm_ts_us` et l'ordre vit jusqu'à `end_ts_us` ; test dédié « fenêtre
08:00 ⇒ décision 07:55:00, repos jusqu'à 08:01:00 » ; vérifié le 2026-10-21, digests dans G2 P-G2-4 (A)). Calendrier indicatif : P3 2026-11-02→04 ; G5 Gate 1 gelé puis exécuté avant la lecture de G2 (≈ 2026-11-20) ; G2
T0 ≈ 2026-11-05, verdict ≈ 2026-11-22 ; G4 Gate 1 en novembre si consenti ; G4 Gate 2 et G5 Gate 2 décembre → février ; clôture
du programme ≤ 2027-03-31.

---

## 9. Interdits (permanents pour tout agent et tout protocole du programme)

1. **Fenêtres réservées** W0–W2 de `fwd-okx-r1` ; dossier `tradingV3-private/research/20261013T100000Z-forward-okx-r1/` ;
   services `fwd-okx-capture` / `fwd-okx-evaluate` ; rien n'est lu, copié, ni arrêté.
2. **Quarantaine** 2026-10-14..31 sur toute venue ; métadonnées datées dans la fenêtre re-figées après. Dans le code du
   collecteur et des lecteurs (`research_lp`), la garde est un **plancher** : `QUARANTINE_FLOOR = 2026-11-02T00:00:00Z`, aucune valeur
   de `--not-before` inférieure n'est acceptée, aucune lecture antérieure sans `--allow-before-reason` recopié dans les résumés (CTO M1,
   appliqué au code par la passe parallèle `a5-corrections-cto` ; G2 §4.2).
3. **Fenêtres consommées** (`RESEARCH-CAMPAIGN-STATUS.md` §3 : Binance 2023–2026 des 20 paires et des mid-caps, OKX 1 m
   **USDT-SWAP** lu par H1 S_A et H2 — les 1 m **X-Perps** sont `unread`, §6.2 —, aggTrades/bookDepth/bookTicker des semaines
   listées, `run14`/`run15`) : jamais pour une validation ; développement seulement si le protocole le déclare.
4. **Toute récupération d'H1 et d'H2** (`H2-CLOSURE-DECISION.md` §4 : aucune hypothèse dérivée « quelle que soit l'archive »).
5. **Aucune lecture avant le gel** d'une valeur de marché de l'univers d'un protocole (prix, spread, volume, funding, OI, carnet,
   liquidations) ; les métadonnées (instruments, tailles, paliers, décotes, champs de règle) sont permises et hachées ; **seule
   exception** : un relevé unique étiqueté `selection` (§6.1), limité à des **comptages de messages** par instrument (zéro valeur),
   haché, antérieur au gel, jamais un résultat — un volume (`volCcy24h`) n'est **pas** une métadonnée (quant M11).
6. **Aucun ordre, aucune démo, aucun drapeau, aucune clé, aucun `.env`** ; aucune commande git / Docker / systemd par un agent de
   recherche ; écriture limitée aux chemins assignés.
7. **Aucun seuil abaissé, aucune fenêtre allongée, aucune analyse promue** après une lecture ; une seule exécution par protocole ;
   amendements écrits et publiés **avant** toute lecture.
8. **Aucune source non lue citée comme prior** ; UNKNOWN reste écrit UNKNOWN.

---

## 10. Track S — K1(c), portage de funding couvert auto-collatéralisé

10.1 **Objet** (rapport structurel §1 ligne 7, §2.1 K1, §6.2 décision 3, 4, 5, 7). Short X-Perp BTC et ETH à 1× dont la marge est
le **spot BTC/ETH du même actif** posé en « Selected Margin » (variante (c)), au lieu d'USDC (variante (a)). Le seul terme
**contractuel** positif du périmètre est le terme d'intérêt I = 0,01 % par 8 h des X-Perps crypto (perps-funding-fee-mechanism,
màj 2026-08-27) ; le funding réalisé est **UNKNOWN** (jamais lu) et n'est pas une prévision. L'admission du spot comme marge
d'un short X-Perp est une **règle non écrite** (page whats-selected-margin-eea, màj 2026-09-03 : « check your X-Perps margin
settings ») : c'est le fait qui sépare (a) de (c), et il se tranche **au compte, sans ordre**.

10.2 **Conditions d'ouverture du track S (toutes requises, lecture d'interface par le propriétaire, aucun ordre)** :
- **S-O1** réglages de marge X-Perps du compte EEE : BTC et ETH **spot** figurent-ils dans la liste des actifs de marge ? avec
  quelles **décotes** affichées (valeurs X-Perps EEE : UNKNOWN ; compte global relevé : BTC 0,98, ETH 0,98 — métadonnées) ?
  un short `BTC-USD_UM_XPERP-<…>` peut-il être porté par du BTC spot posé en collatéral ? (décision 5a du rapport structurel) ;
- **S-O2** devise de règlement USD-M du sous-compte X-Perps : USD ou USDC ; à fixer sur USDC **avant** toute position (5b) ;
- **S-O3** paires BTC-USDC / ETH-USDC négociables en spot ; transfert USDC Funding → Trading ; aperçu du One Way Convert
  USDT → USDC **sans exécuter** (5g, 5i) ;
- **S-O4** barème du compte « Spot & derivatives » niveau Regular confirmé (5e) ;
- **S-O5** décision écrite de principe sur CS-FUND (décision 3 : protocolé **tel quel** à 12 mois, **amendé** selon 10.4, ou
  classé sans suite) ; **S-O6** conseil fiscal engagé ou déclaré hors périmètre avec acceptation du risque (décision 7) ;
  **S-O7** devise de compte déclarée (décision 1) ; **S-O8** verdict `fwd-okx-r1` rendu.
Si S-O1 est **non** : la variante (c) n'existe pas ; le track S se réduit à (a), déjà **réfutée à 500 USDT** par les sceptiques
(surplus ≈ 0 à −7 USD/an face à Simple Earn pour strictement plus de risque, rapport structurel §2.1 K1) ; le track S est alors
**classé sans suite** sauf décision contraire écrite.

10.3 **La « mesure papier de 8 semaines » est supprimée comme étape distincte** (relecture quant M9a ; question 5 des notes a3).
Motifs : ses « coûts réels » (marge gelée après décote, distance d'appel et de liquidation, `ctVal`, devise) sont de
l'**arithmétique de règle** vérifiable en une lecture de compte (S-O1…O4) ; la distribution du signe du funding sur 8 semaines
était déclarée non décisionnelle **et** devenait `consumed_equivalent` ; le rapport structurel §4.1 point 11 établit qu'un Gate 2
court n'a aucun pouvoir sur le rendement (à F = I, le surplus de 56 jours est de l'ordre d'un dixième de pour cent de base).
L'étape coûtait (relevés, enregistreur) sans rien trancher. Ce qu'elle devait apporter est redistribué : (i) **lecture de compte
unique** (S-O1…O4) : décotes, liste de marge, `posMode`, devise USD-M, `ctVal`, paliers, clause OEM « < 30 % de la valeur du
contrat » (interprétation UNKNOWN) — métadonnées hachées ; (ii) **rodage technique** = les 8 premières semaines de CS-FUND **s'il
est gelé** (enregistreur lecture seule distinct de `fwd-okx-*`, hôtes `eea.okx.com` publics, règlements `fundingTime < T0`
rejetés par le code, hash du code au lancement, indicateur ADL `adl` 0–5 si le propriétaire l'autorise, événements de règle —
`interestRate`, décotes, liste de marge, barème, devise, CGU « Last Updated » + sha256 hebdomadaires) : ces semaines **font
partie** de la fenêtre de CS-FUND (12 mois, futilité ≥ 3 mois), elles ne sont ni lues à part ni consommées à part ; (iii) sans
gel de CS-FUND, aucune capture de funding n'est lancée pour le track S. Décision du propriétaire après S-O1…O8 : geler CS-FUND
amendé (10.4) ou classer sans suite.

10.4 **CS-FUND : brouillon de référence et amendements requis avant tout gel.** Texte de référence :
`tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-A/notes/PROTOCOL-H3A-GATE1-DRAFT.txt` (statut `DRAFT_NOT_FROZEN`,
2026-10-15 ; copie de travail, lecture seule ; décrit dans le rapport structurel §2.1 et §6.2). Il est le **seul** texte existant
du track S et doit recevoir, par amendement écrit **avant** gel, au moins :
- **A-S1 Lecture rétrospective.** CS-FUND §0.4 interdit « toute lecture de l'historique de funding … antérieure à T0 … avant et
  après le gel ». Deux options exclusives à trancher : (i) **prospectif pur** (texte actuel) ; (ii) **Gate 1 rétrospectif
  `consumed_equivalent`** sur consentement écrit (rapport structurel §6.2 décision 4) : lecture **unique** de
  `public/funding-rate-history` pour les deux identifiants figés, code figé avec le paramètre `after = 2026-10-14T00:00Z` (seuls
  les règlements **antérieurs** à la quarantaine sont servis ; profondeur réelle ≈ 3 mois observée, rétention UNKNOWN), usage
  « **tuer seulement, sur des faits de règle** » (quant M9b) : la profondeur « up to three months » (docs-v5) laisse ≈ 2,4 mois
  utiles après la quarantaine (≈ 2026-08-01 → 2026-10-13), alors que les régimes négatifs de funding durent **2 à 12 mois**
  (rapport structurel §5a) — un kill sur la **moyenne** du funding d'un seul régime serait un faux négatif probable ; le Gate 1
  rétrospectif ne peut donc tuer que sur des **faits de règle** observés dans la série : `interestRate` ≠ 0,01 % par 8 h sur les
  identifiants figés, plafonds `maxFundingRate`/`minFundingRate` touchés (fréquence), intervalles `fundingTime → nextFundingTime`
  différents de ceux déclarés, `formulaType`/`method` inattendus ; la moyenne, le signe et la variance du funding sont
  **rapportés, jamais décisionnels** ; un Gate 1 sans fait de règle n'autorise rien de plus que le gel déjà prévu ; biais de
  persistance du funding **déclaré** (un filtre sur le funding passé gonfle la probabilité d'un PASS prospectif, littérature CMU
  citée par le rapport structurel §5a), inscription des X-Perps au registre §6 avant lecture.
- **A-S2 Contrôles.** CS-FUND C2 compare à l'**€STR** ; le capital est en USD et aucun des deux taux n'est achetable sur OKX
  Europe (rapport structurel §4.1 point 1). Amendement : référence USD = **SOFR** (NY Fed, actual/360, moyenne sur la période) ;
  contrôle **atteignable** = Simple Earn USDC EEE (ligne « descriptive, non admise, borne supérieure affichée », statut du
  protocole K8 ; jamais substitué au SOFR comme hurdle) ; contrôle EUR = Livret A (si la devise de compte est l'EUR) ; **les deux
  côtés de toute comparaison sont taxés** ; ligne de change EUR/USD rapportée, jamais coupée.
- **A-S3 Impôt symétrique et asymétrie déclarée.** Tous les tableaux après impôt appliquent le **même** taux aux deux côtés
  (convention PFU 31,4 % = facteur 0,686, ou 30 % = 0,70, à choisir une fois) ; **et** le protocole déclare l'asymétrie
  possible entre la jambe X-Perp (instrument financier MiFID, a priori art. 150 ter CGI) et la jambe spot (actif numérique,
  art. 150 VH bis, pertes non reportables), qualification **UNKNOWN**, conseil requis (S-O6) ; C1–C5 sont évalués **avant**
  impôt, la lecture après impôt est rapportée.
- **A-S4 Validité d'un PASS.** CS-FUND §9 dit « PASS : une proposition au propriétaire pour M5 ». Amendement : (i) un PASS vaut
  **six mois** après le verdict du mois 12 (au-delà, nouveau pré-enregistrement) ; (ii) il n'autorise qu'une **proposition écrite**
  de petite exécution démo, jamais un trading réel ni un levier > 1× ni une sélection par funding (bras (d) descriptif seulement) ;
  (iii) un PASS obtenu avec C2 au SOFR mais sous Simple Earn est publié « PASS, sous le contrôle atteignable » ; (iv) un
  `INCONCLUSIVE` ou un `FAIL_FUTILITY` ferme le track S sans redémarrage sur les mêmes 12 mois.
- **Déjà relevés par le rapport structurel, à traiter dans le même amendement** (§4.1 points 7, 10, 13, 14, 15 ; §6.2 décision 6) :
  règle de **dénouement forcé** (ADL : la jambe gagnante d'une couverture est fermée **en premier**, sans préavis ; suspension ;
  délisting « without notice » CGU OEM §12) ; **coupe-risque sur la structure entière**, pas sur le short seul ; `ctVal`
  0,0001 vs 0,01 BTC tranché par l'API ; devise de règlement USD-M ; re-figer les métadonnées datées 2026-10-14..31 ; deux entités
  (OEM / OKX Europe Ltd) et gage de la jambe spot (CGU OEM §1.9).

10.5 **Ce que le track S ne fait jamais** : aucun ordre, souscription, conversion, virement, stake ou dépôt ; aucune clé API avec
droit Trade ; aucun drapeau ; aucune lecture de funding avant T0 hors A-S1(ii) consentie ; aucune sélection d'actif sur le funding ;
aucun levier > 1×.

---

## 11. Décisions attendues du propriétaire (consolidées ; renvois)

| # | Décision | Renvoi | Bloque |
|---|---|---|---|
| 1 | §9.4 : **capture publique continue du 2026-11-02 au ≤ 2027-03-31** (P3, G2, G4 Gate 2, G5 Gate 2), ≤ 40 X-Perps crypto + 2 swaps globaux (`BTC-USDT-SWAP`, `ETH-USDT-SWAP`), canaux `trades`/`books5`/`bbo-tbt`/`mark-price`/`funding-rate` par instrument et `liquidation-orders`/`instruments`/`books-rpi` par `instType`, cadences documentées (`books5` ≤ 10/s, `bbo-tbt` ≤ 100/s), stockage `tradingV3-private`, travail d'agents ; **question jointe** : hôte global `ws.okx.com` depuis un compte EEE acceptable ? ; tout allongement = nouvelle décision (quant B4 ; le « 2–8 semaines » du rapport théorie §6.2 sous-estimait l'échelle) | théorie §6.2 ; `LP-G2` P-G2-1 ; desk §2.3 Q1–Q2 | P3, G2, G4 Gate 2, G5 Gate 2, G1 |
| 2 | P1 autorisé (diagnostic de la machine, zéro prix) | théorie §6.1 | d de la variante minute ; crédibilité |
| 3 | GET lecture seule au compte X-Perps (posMode, levier, permissions, marge gelée, barème API, MM) | théorie §6.3 ; `H1-XPERP-MARKET-ACCESS.md` §3 | lecture portefeuille ; track S S-O1–O4 |
| 4 | Vocabulaire et objectif (« fourniture de liquidité événementielle » ?) | théorie §6.4 | textes du programme (§0) |
| 5 | Consentement ou refus G1 (différence déclarée) | théorie §6.5 | gel de G1 |
| 6 | Décision G6 (H2 §4) | théorie §6.6 | existence de G6 ; m_max |
| 7 | P3 autorisé (collecteur, comptage 24–72 h) | théorie §6.7 | G2, G4 Gate 2, G5 |
| 8 | Gate 1 historique G4 sur l'archive HL ; licence et plafond requester-pays | théorie §6.8 ; `LP-G4` P-G4-1/2 | G4 Gate 1 |
| 9 | S_éco, coût fixe déclaré, durée maximale (§1) ; m_max confirmé (§2) | théorie §6.9 | lectures économiques ; Holm |
| 10 | Corrections de configuration indépendantes (post-only, frais internes, levier, univers, gardes) — différées ou non | théorie §6.10 | rien dans le programme de mesures |
| 11 | Calendrier confirmé : rien avant le verdict fwd-okx-r1 ; confirmation ≥ 2026-11-02 | théorie §6.11 | tout |
| 12 | Hypothèses hors scalp (M2, G7, M3) : dans ce programme, un autre, ou nulle part | théorie §6.12 | périmètre |
| 13 | Track S : S-O1…O8 ; CS-FUND tel quel / amendé / sans suite ; Gate 1 rétrospectif (faits de règle seulement) consenti ou refusé ; conseil fiscal ; devise de compte | structurel §6.2 décisions 1, 3, 4, 5, 7 ; §10 | track S |
| 14 | Visibilité du dépôt et agrégats : le dépôt `pivox/tradingV3` reçoit-il des agrégats chiffrés (mark-outs moyens, taux de fill) ou seulement protocoles et verdicts qualitatifs ? (« display », §9.4(a)) | structurel §6.2 décision 8 ; desk §2.3 Q3 ; §6.3 | forme des `LP-*-RESULT.md` ; défaut : agrégats privés |
| 15 | Lecture du jeu `okx-xperp-1m-history-2026-03-30..2026-10-13` (`unread`, Market Data §9.4) pour le Gate 1 historique confirmatoire de G5 ; option `market-data-history` (fichiers T+2/T+3, docs globale seule, disponibilité EEE UNKNOWN) pour la calibration C0 et le développement — rien n'est appelé avant décision | §6.2, §8 ; desk §0.5, §1.4, §2.3 Q5 (quant B3, m12) | G5 Gate 1 ; option C0 |

---

## 12. Traitement de la revue (2026-10-20)

Relectures : `review/quant.md` et `review/cto.md` (dossier `20261020T1430Z-lp-programme/review/`). Convention : **appliqué** =
texte modifié ; **signalé** = correction hors du périmètre d'écriture de cette passe ; « **Réponse à la revue** » = refus ou
traitement différent, motivé.

| Constat | Traitement |
|---|---|
| quant **B1** — `net_pb` de §1.1 n'était pas défini comme un aller-retour | **Appliqué** : §1.1 (`net_pb` = toujours un aller-retour) ; §2.1 (test de G2 = C2 aller-retour) |
| quant **B3** — 1 m X-Perps étiquetés « lus par H2 » ; G5 brûlé en screen | **Appliqué** : §6.1/§6.2 (ligne `okx-xperp-1m-history-2026-03-30..2026-10-13` = `unread` ; lien avec H2 écrit) ; §8 (G5 en deux portes : Gate 1 historique confirmatoire gelé avant la lecture de G2, puis prospectif) ; §2.1/§2.3 (famille m_max = 5) ; §3.3 ; §9.3 ; décision 15. **Signalé** : le rapport théorie l.143/353 porte l'erreur de fait — à corriger dans sa branche |
| quant **B4** — licence demandée sur une mauvaise durée ; hôte global | **Appliqué** : §11 décision 1 réécrite (capture continue 2026-11-02 → ≤ 2027-03-31, ≤ 40 + 2, canaux, cadences, hôte global, allongement = nouvelle décision) ; §8 P2 |
| quant **B5** — écrémage RPI absent des coûts | **Appliqué** : §5.4 (coût déclaré, exigences de capture, biais vers le PASS tant que non fait). **Réponse à la revue** : non ajouté comme un terme séparé du net — il est dans la sélection adverse mesurée ; ce qui est corrigé est la comparabilité à Part A |
| quant **M7** — porte économique asymétrique | **Appliqué** : §1.2 (« poursuivi » ⇔ point ≥ S_éco et UB90 ≥ 2 S_éco ou LB90 > 0) ; §1.3 |
| quant **M8** — raccourcissement du funding X-Perps ; `economic-calendar` | **Appliqué** : §5.1 (intervalle lu dans la tape, raccourcissement UNKNOWN). **Signalé** : rapport théorie §3.4 (« filtre gratuit » — VIP1+ d'après le desk §1.3) à corriger dans sa branche ; aucun texte du programme ne s'appuie sur `economic-calendar` |
| quant **M9** — track S : 8 semaines sans information ; kill sur la moyenne du funding | **Appliqué** : §10.3 (étape supprimée, redistribuée en lecture de compte + rodage interne à CS-FUND si gelé) ; §10.4 A-S1(ii) (tuer seulement sur des faits de règle) ; décision 13 |
| quant **M10** — fuite G2 §7.6 → G5 | **Appliqué** : §2.1 (règle screen/post-screen, compte dans m) ; §8 (G5 Gate 1 gelé avant la lecture de G2) |
| quant **M11** — `selection` contredit §9.5 | **Appliqué** : §6.1 (`selection` = métadonnées ou comptages, jamais un volume) ; §9.5 (exception écrite) |
| quant **M6** — unités de σ, puissance | **Appliqué** : §3.2 (effet détectable en σ déclaré par protocole), §3.3 |
| quant **m4** — ≈ 99 % ; bandes | **Appliqué** : §5.5 |
| quant **m6** — famille, α = 0,10 | **Appliqué** : §2.1 (FWER 10 % écrit ; critères secondaires abaissent l'α effectif ; G4 Gate 1 hors famille déclaré). **Réponse à la revue** : α conservé à 0,10 unilatéral — la relecture le juge « déclaré, défendable » ; un α plus strict se décide par le propriétaire (décision 9), jamais après une lecture |
| quant **m8** — agrégats dans le dépôt | **Appliqué** : §6.3 (décision du propriétaire, défaut privé) ; décision 14 |
| quant **m10** — `INCONCLUSIVE` à p = 1 | **Appliqué** : §2.1 (figé) |
| quant **m12** — `market-data-history` en option P2 | **Appliqué** : §8 P2 ; décision 15 ; rien n'est appelé |
| CTO **§4.3** — budget disque, supervision, `verify` au coût fixe | **Appliqué** : §1.2 |
| CTO **M2** — G5 armé à `arm_ts_us` | **Appliqué** : §8 (exigence écrite pour le futur brouillon G5) ; simulateur corrigé par la passe parallèle `a5-corrections-cto`, vérifié le 2026-10-21 (suite `tests/test_research_lp_*.py` : 40 passed ; G2 P-G2-4 (A)) |
| CTO **M1, M3, M4, m1–m12** — code du collecteur et du simulateur | Hors de ce document ; **appliqués au code** par la passe parallèle `a5-corrections-cto`, état consigné dans `LP-G2` P-G2-4 (A) (fait) / (B) (reste : tranche 6 pb, `grid_offset_s`, stratification, rejeu = direct, RPI, `bbo-tbt`, `queue_unknown`, `size_visible`, repli REST funding, `band_rejected`, `posMode`) ; la seule règle de ce document qui en dépend est la quarantaine §9.2, désormais **empêchée** par `QUARANTINE_FLOOR` et plus seulement traçable |

---

## 13. Sources

Dépôt (lecture seule, worktree `lp-programme` sauf mention) : `docs/handbook/research/{RESEARCH-ELIGIBILITY-GATES,
RESEARCH-CAMPAIGN-STATUS (§3), H1-XPERP-MARKET-ACCESS, H1-XPERP-ACCOUNT-CHECK, TRADABLE-INSTRUMENT-UNIVERSE, H2-CLOSURE-DECISION
(§4), PREREGISTRATION-H2-GATE1 (§15, procédure de gel), H1-XPERP-TECHNICAL-GAP}.md` ; `docs/handbook/technical/lp-executor-architecture.md`
(A1, §2.2, §5, §7) ; `python-orchestrator/scripts/research_lp/{tape,xperp_capture,lp_sim,events,cli}.py` (A2) ;
`docs/handbook/reports/research-execution-tick-2026-10-12.md` (Part A). Worktree `research-scalping-theory` :
`docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md`, `docs/handbook/reports/research-positive-pnl-structural-2026-10-16.md`.
Privé (lecture seule) : `tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-A/notes/PROTOCOL-H3A-GATE1-DRAFT.txt` ;
`tradingV3-private/research/20261020T1430Z-lp-programme/a1-architecture/NOTES.md`.

Documentation officielle relue le 2026-10-20 (WebFetch) : OKX API Agreement EEE (https://www.okx.com/en-eu/help/okx-api-agreement,
« Last Updated: 28 July 2026 », §1.8, §9.4 verbatim dans `a3-protocols/NOTES.md`) ; OKX docs-v5 (https://www.okx.com/docs-v5/en/,
page tronquée avant Market Data : canaux non re-vérifiés) ; Hyperliquid « Historical data »
(https://hyperliquid.gitbook.io/hyperliquid-docs/historical-data), « Liquidations »
(https://hyperliquid.gitbook.io/hyperliquid-docs/trading/liquidations), « Subscriptions » (type `WsFill`,
https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/websocket/subscriptions), « Info endpoint »
(https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint). Par A1 (non relues ici) : FAQ frais EEE,
page ADL, x-perps-guide, x-perps-contract-specifications, whats-selected-margin-eea.

Fin. `DRAFT_NOT_FROZEN`.
