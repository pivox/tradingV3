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

**Amendement 2026-10-21 (P-G5-7).** Prérequis P-G5-7 de `LP-G5-DRAFT-PREREGISTRATION.md` (relecture quant de G5 m3, m4 :
`tradingV3-private/research/20261020T2321Z-g5-periods/review/quant.md`), appliqué par le volet `docs-b` du programme
`20261020T2321Z-g5-periods` : §1.1 (jours par an du calendrier du type), §2.1–2.3 (famille m_max = 3 + 2K = 7), §3.1 (blocs
d'un jour UTC pour G5), §3.3 et §8 (instants réellement disponibles pour G5) ; §6.2 reçoit en plus l'état du registre
(P-G5-6). Chaque passage amendé porte une ligne « Amendement 2026-10-21 (P-G5-7) » qui cite le texte remplacé. Statut
inchangé (`DRAFT_NOT_FROZEN`) ; aucune donnée lue.

**Amendement 2026-10-21 (relecture `review-b/quant.md` ; décisions D4–D9).** Relecture quant adversariale des amendements P-G5-7,
du registre, du spec Gate 2 et de l'Annexe B de G5 (`tradingV3-private/research/20261020T2321Z-g5-periods/review-b/quant.md`,
verdict `NEEDS_CHANGES`, 2 bloquants, 5 majeurs, 12 mineurs), appliquée par une passe de correction unique sur ce document, le
plan, le registre, le spec Gate 2 et l'Annexe B de G5. Sections touchées : §1.2 (S_éco fixé par D4, règle unique, coût fixe),
§1.3, §2.1 (Gate 1 de G4 quelle que soit sa source, règle de combinaison), §2.3 (`PASS_PROGRAMME` historique sans effet sur la
Phase 4 ; T05 du Gate 2 dans m ; m_max est une règle), §3.2–3.3 (variance des contrôles ; comptes sous P-S3 et pour un T0
atteignable ; D7), §6.2–6.3 (registre ; D1), §8 (fenêtres ; bornes hautes), §11 (états datés des décisions), §12 (traitement,
refus écrits « Réponse à la revue »). Décisions prises en compte : `LP-OWNER-DECISIONS-2026-10-21.md` D1–D9. Statut inchangé
(`DRAFT_NOT_FROZEN`) ; aucune donnée lue.

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

    plafond_USD_an = fills_admis_par_jour × notionnel_moyen_USD × net_pb / 10 000 × jours_par_an_du_type

avec `fills_admis_par_jour` et `notionnel_moyen_USD` pris dans la **lecture portefeuille** (§4 : 500 USDT, 1×, ≤ 4 engagements
vivants, 100 USD par ordre ; rapportée sous les deux hypothèses de `posMode` tant qu'il n'est pas lu) et `net_pb` = **toujours un
aller-retour** (entrée et sortie réellement simulées, frais des deux jambes au rôle réel, sélection adverse mesurée des deux
jambes, funding des règlements traversés, coût de la marge gelée (§5), queue de liquidation) — jamais un mark-out d'entrée net du
seul frais maker, qui est une demi-transaction (relecture quant B1 ; Part A, `research-execution-tick-2026-10-12.md` l.60–70 :
mark-out net −3,05 … −3,49 pb contre aller-retour taker −8,1 … −8,7 pb) ; rapporté avec ses bornes LB90 / UB90. Ordres de grandeur du rapport théorie (calcul d'audit, §5
ligne 5) : 600 fills en 8 semaines × ~100 USD × +10 pb ≈ 350–400 USD/an ; 2 créneaux de 50 USD × 5 fills/jour ≈ 90 USD/an. Ce
sont les ordres de grandeur **si tout passe** ; ce ne sont pas des prévisions.

**Amendement 2026-10-21 (P-G5-7)** : « × 365 » (texte du 2026-10-20) devient « × jours/an du calendrier du type » : **365** pour
un type à calendrier continu (jours calendaires : G2, G4, G1, G5-T01), **252** pour un type au calendrier NYSE (jours de bourse :
G5-T05, jours de publication macro exclus de l'événement comptés au dénominateur) ; `fills_admis_par_jour` est compté sur les
jours du **même** calendrier (G5 §7.6 ; relecture quant de G5 m3). Annualiser à 365 des fills qui n'existent que les jours de
bourse gonflait le plafond d'un facteur 365 / 252 ≈ 1,45 (calcul d'audit).

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

**Amendement 2026-10-21 (D4 ; relecture review-b quant m5, m6, M1).** « à fixer par le propriétaire ; proposition a3 » (texte du
2026-10-20) : S_éco est **fixé par D4** (« 4 ok », 2026-10-21 : 5 × SOFR × capital). **Règle unique**, recopiée dans chaque spec
avant son gel :

    S_éco = max( 97,25 ; 5 × SOFR publié du jour du gel × 500 ; coût fixe annuel déclaré )      [USD par an]

97,25 = 5 × 3,89 % × 500 : valeur littérale de D4. Son « ≈ 97 » et le 97,0 des deux specs G5 du 2026-10-21 sont des arrondis
**inférieurs** à D4, contraires à « jamais plus bas » : les specs portent au moins 97,25 à leur gel (**écrit 97,25 dans les deux specs G5 le
2026-10-22**, décision du propriétaire « Finir les contrôles G5 réalisables dès maintenant » ; le lecteur refuse moins). Convention de D4 : le taux
publié multiplie le capital, sans conversion de base de jours ; la lecture actual/360 ramenée à 365 jours (× 365/360 : 98,60
USD/an au même SOFR, celle du track S) est une **option du propriétaire pour relever** S_éco, à prendre avant tout gel. **Coût
fixe déclaré** : non chiffré (heures du propriétaire UNKNOWN) ; **déclaré nul** dans la formule, pour G5 Gate 1 (P-G5-8 :
« ≈ nul ») comme pour G5 Gate 2 (part d'une capture continue déjà décidée par D1, qui sert aussi G2 et G4) ; le propriétaire
peut le chiffrer et relever S_éco avant un gel, jamais après une lecture. « Phase 4 d'architecture proposable » ci-dessus ne
vaut que pour un `PASS_PROGRAMME` obtenu par un test **prospectif** (§2.3, amendement du même jour).

1.3 **Règle d'arrêt du programme** : si, après les verdicts de G2, G4 et G5, aucune famille n'a simultanément un `PASS_PROGRAMME`
(§2) et la condition « poursuivi » de §1.2, le programme de mesures est **clos** par un rapport ; G1 n'est alors lancé que si le
consentement a été donné **avant** ces verdicts et si la table de coût G2 laisse une marge économique (sinon il est annulé sans
lecture). Durée maximale du programme : **fin du scoring de la dernière fenêtre déclarée ≤ 2027-03-31** (sauf décision écrite
contraire du propriétaire avant cette date).

**Amendement 2026-10-21 (relecture review-b quant M1).** Le `PASS_PROGRAMME` de cette règle est celui d'un test **prospectif**
de la famille (G2-C2, G4-Gate2, G5-Gate2-T01, G5-Gate2-T05, G1) : un `PASS_PROGRAMME` d'un test historique (G5-Gate1-*) ne
suffit pas à poursuivre le programme (§2.3, §7.1). D7 applique la durée maximale à G5 : sa fenêtre prospective s'arrête au
2027-03-31.

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

**Amendement 2026-10-21 (P-G5-7)** : G5 entre dans la famille avec **un test confirmatoire par type décisif et par porte**
(K = 2 types, T01 et T05 ; G5 §1.2) : G5-Gate1-T01, G5-Gate1-T05, G5-Gate2-T01, G5-Gate2-T05, au lieu de « G5-Gate1-C1 » et
« G5-Gate2-C1 » (texte du 2026-10-20). Les deux specs G5 étant gelés ensemble, les quatre tests sont gelés à la même date ; un
type non inscrit au Gate 2 (`G5_GATE1_FAIL`) y compte p = 1, par la règle ci-dessus (jamais de retrait).

**Amendement 2026-10-21 (relecture review-b quant m11 ; D3, D9).** « Le Gate 1 d'Hyperliquid (G4) est une porte de
développement » (texte du 2026-10-20) se lit : **le Gate 1 de G4, quelle que soit sa source de développement** — dYdX v4 (D9 :
source principale), Hyperliquid (D3 : repli) ou un double Gate 1 —, est une porte de développement hors famille. Sa **règle de
combinaison** est gelée dans le pré-enregistrement de G4 **avant** toute lecture : si deux sources sont lues, **les deux doivent
passer** (règle par défaut) ; le repli sur Hyperliquid n'est déclenché que par une condition de données écrite avant la lecture
de dYdX (échantillon de schéma, rétention de l'indexeur, absence de `LIQUIDATED`), **jamais** par un résultat de dYdX ; « l'une
suffit » est interdit : il doublerait à peu près le taux de faux passage vers le Gate 2 de G4.

2.2 **Niveau** : α_famille = 0,10 **unilatéral** (c'est le « LB90 > 0 » de chaque protocole, exprimé en p-value bootstrap
unilatérale p_i = P*(statistique ≤ 0)). **Holm** : p_(1) ≤ … ≤ p_(m) ; le test (i) est confirmé si p_(j) ≤ α / (m − j + 1) pour tout
j ≤ i. Avec m = 5 : le plus significatif doit avoir p ≤ 0,02 (LB98 > 0), puis 0,025, 0,033, 0,05, 0,10.
**Amendement 2026-10-21 (P-G5-7)** : à la taille planifiée m = 7 (§2.3), l'échelle de Holm est 0,10/7 ≈ 0,0143 ; 0,10/6 ≈ 0,0167 ;
0,02 ; 0,025 ; 0,0333 ; 0,05 ; 0,10 (calcul d'audit). L'exemple à m = 5 ci-dessus est conservé comme texte du 2026-10-20.

2.3 **Lecture intermédiaire** (les verdicts tombent à des dates différentes) : un protocole qui satisfait son critère local
(LB90 > 0 **et** ses seuils économiques) reçoit `PASS_LOCAL`. Il devient `PASS_PROGRAMME` **immédiatement** si p_i ≤ α / m_max,
où m_max = taille **planifiée** de la famille écrite ici (**m_max = 3 + 2K = 7**, K = 2 types décisifs de G5 : **G2-C2,
G4-Gate2, G1, G5-Gate1-T01, G5-Gate1-T05, G5-Gate2-T01, G5-Gate2-T05** ; 8 si G6 est autorisé ; +1 par screen devenu
post-screen, §2.1), soit **p_i ≤ 0,10 / 7 ≈ 0,0143** — c'est
Bonferroni, toujours ⊆ Holm ; sinon il attend la **clôture de la famille** (dernier verdict ou date de §1.3) pour l'application de
Holm. Aucune Phase 4 d'architecture ne démarre sur un `PASS_LOCAL`.

**Amendement 2026-10-21 (P-G5-7)** : texte du 2026-10-20 « **m_max = 5** : G2-C2, G4-Gate2, G5-Gate1, G5-Gate2, G1 ; 6 si G6 est
autorisé » remplacé (relecture quant de G5 m3 ; G5 §1.2, §7.5) : chaque type décisif de G5 est un test à chacune de ses deux
portes, d'où m = 3 + 2K. `PASS_PROGRAMME` immédiat ⟺ p_i ≤ 0,10 / 7 ≈ 0,0143 (champ `pass_programme_immediate` des
évaluateurs ; `multiplicity.programme` des specs G5). Il est distinct de la confirmation Holm **locale** d'un protocole (G5 :
`holm_local_confirmed`, K = 2, α = 0,10, requise avec les critères du type pour `G5_GATE1_PASS`), et n'a **aucun effet** sur
l'inscription d'un type au Gate 2 de G5 (G5 §7.5). Un changement de K (type décisif ajouté ou retiré, gelé avant toute lecture)
réécrit m_max = 3 + 2K par un nouvel amendement daté, avant le gel de G5.

**Amendement 2026-10-21 (relecture review-b quant M1, m1, m2, m6 ; D7).**
- **Ce qu'ouvre un `PASS_PROGRAMME`.** Un `PASS_PROGRAMME` d'un test **historique** (G5-Gate1-T01, G5-Gate1-T05) n'ouvre
  **aucune** Phase 4 et ne compte pas pour la continuation de §1.3 ; seuls les tests **prospectifs** (G2-C2, G4-Gate2,
  G5-Gate2-*, G1) le font (§7.1 : la seule confirmation admise est une fenêtre `prospective`). Le test historique reste dans la
  famille (il entre dans m et dans Holm) ; son seul effet est celui que son protocole lui donne (G5 : l'inscription au Gate 2).
  Pour G5, une proposition de Phase 4 exige `G5_GATE2_PASS(type)` **et** le `PASS_PROGRAMME` de `G5-Gate2-<type>` (immédiat, ou
  Holm à la clôture) **et** la lecture « PASS, poursuivi » (spec Gate 2, `verdict.pass_effect`) ; sinon elle attend la clôture
  de la famille. §1.2 (« Phase 4 proposable »), §1.3 et la ligne Phase 4 du plan se lisent ainsi.
- **G5-Gate2-T05 sous D7.** D7 arrête la fenêtre au 2027-03-31 : T05 au Gate 2 est `NOT_INFORMATIVE` par construction (§3.3).
  Il **reste dans m** avec p = 1 (règle figée de §2.1) ; m_max = 7 inchangé ; aucun retrait sans amendement daté avant le gel de
  G5, et jamais après une lecture.
- **Conséquence écrite d'avance.** Si le refus de P-G5-10 (ii) (« Nn », D7) est confirmé, T01 au Gate 2 est `INCONCLUSIVE` par
  construction : **aucun `G5_GATE2_PASS` n'est alors possible**, et G5 ne peut ouvrir aucune Phase 4 dans ce programme. Son
  apport se limite au Gate 1 (inscriptions, filtres de §9 du protocole) ; ses deux tests du Gate 2 comptent p = 1.
- **Protocole successeur.** Un protocole successeur sur une forme testée à une porte prospective sans scellement (G5 Gate 2)
  entre dans m par un amendement de m_max daté **avant** son propre gel ; aucune prolongation de fenêtre n'est jamais motivée
  par les valeurs imprimées (D, LB90, UB90) d'un `NOT_INFORMATIVE` ou d'un `INCONCLUSIVE`.
- **m_max ne demande pas de décision.** m_max = 3 + 2K découle de la règle de §2.1 (un test par protocole gelé ; pour G5, un par
  type décisif et par porte) : c'est une **règle**, pas un choix, et un m_max plus grand ne fait que durcir `PASS_PROGRAMME`. La
  confirmation « m_max confirmé » de la décision 9 (§11) n'est donc **pas requise** ; tout changement (K, G6, screen devenu
  post-screen) passe par un amendement daté, avant le gel concerné.

2.4 **Dans chaque protocole** : **un** signe et **une** fenêtre pré-déclarés par event study ; les horizons, règles de fill,
tranches, variantes et stratifications supplémentaires sont **descriptifs** et ne reçoivent ni p-value ni verdict ; une analyse
« secondaire » ne peut pas devenir primaire par amendement après une lecture. Rappel du rapport théorie : neuf tests unilatéraux
à 5 % sans correction ⇒ ~37 % de chance d'un faux pass.

---

## 3. Puissance (règle 9)

3.1 **Unité d'observation** : l'**instant** (G4 : événement, cluster par heure UTC inter-instruments ; **G5 : horodatage
d'événement ou de contrôle, valeur = moyenne des instruments retenus, n = instants uniques**), le **slot** (G6 si
autorisé), le **fill** regroupé par **blocs de 10 min** (G2, G1). Jamais le trade isolé, jamais la paire-semaine. L'incertitude
est calculée par **bootstrap par blocs** (`lp_sim.block_bootstrap_lower_bound` : blocs de 600 s, 1 000 rééchantillons, graine
20261102 ; blocs de 3 600 s pour les instants de G4 ; **G5 : blocs d'un jour UTC**, `g5_eval.day_block_bootstrap`,
10 000 rééchantillons, graine 20261102, événements et contrôles d'un même jour rééchantillonnés ensemble, sensibilité
descriptive à blocs de 7 jours), poolé sur l'univers avec le bloc comme unité.

**Amendement 2026-10-21 (P-G5-7)** : texte du 2026-10-20 « cluster par heure UTC inter-instruments : G4, G5 » et « blocs de
3 600 s pour les instants » ; G5 passe à l'instant-horodatage et aux **blocs d'un jour UTC** (G5 §7.3 ; spec
`statistics.bootstrap.block_days = 1` ; relecture quant de G5 m1, m4 ; piège 5 de la red team : les chocs de jour sont
partagés par les événements et les contrôles d'un même jour, que des blocs d'une heure sépareraient). G4 est inchangé.

3.2 **Taille minimale, formule** (calcul d'audit) : n ≈ ((z_{1−α} + z_{1−β}) · σ / δ)² × DE, α = 0,10 unilatéral (z = 1,28),
puissance 0,80 (z = 0,84), δ = effet cherché, σ = écart-type de l'unité, DE = effet de plan du regroupement (1 + ρ(k − 1)).
Pour δ = +10 pb, σ ≈ 100 pb (ordre de grandeur d'audit du rapport théorie, pas une mesure), DE ≈ 1,3 : n ≈ 450 × 1,3 ≈ **600**
(le « ≈ 600 trades » de la règle 9). σ est **UNKNOWN** sur X-Perps avant lecture : les portes sont des **planchers de conception**,
jamais recalculées sur la donnée lue. **Chaque protocole déclare son effet minimal détectable en unités de σ** (quant M6 ; desk
§4.3) : à n observations, δ_min ≈ 2,12 × σ × √DE / √n (≈ 0,24 σ à n = 100, DE 1,3 ; ≈ 0,09 σ à n = 600) — et dit si
`NOT_INFORMATIVE` est l'issue probable au plancher.

**Amendement 2026-10-21 (relecture review-b quant m4).** La formule ci-dessus ignore la variance de la moyenne des contrôles.
Pour D = moyenne des événements − moyenne des contrôles, se(D) ≈ σ × √DE × √(1/n_e + 1/n_c) = (σ √DE / √n_e) × √(1 + n_e/n_c),
d'où δ_min ≈ 2,12 × σ × √DE × √(1/n_e + 1/n_c). Avec n_c ≈ 3 n_e (G5), le facteur √(1 + n_e/n_c) vaut ≈ 1,15–1,16 (calcul
d'audit). La covariance des chocs de jour communs aux événements et aux contrôles d'un même jour (rééchantillonnés ensemble,
blocs d'un jour) joue en sens inverse ; son ampleur est UNKNOWN. Ces chiffres restent des **planchers de conception**, jamais
recalculés sur la donnée ; pour G4 (contrôle continu et contrôle apparié), l'approximation sans ce facteur est **déclarée**.

3.3 **Planchers par protocole** : G2 ≥ **1 000 fills** dans la cellule primaire (variante minute, spread ≥ 6 pb) et ≥ 5 paires à
1 000 (relaxation « par paire-tranche » déclarée, G2 §7.2) ; G4 ≥ **100 instants** par porte (effet cherché grand et rare : +10 pb
est un seuil économique ; la détection à 100 instants vaut pour ≈ **0,24 σ** de l'unité — ≈ +24 pb si σ ≈ 100 pb, 40–75 pb si σ
est, comme probable après cascade, de 150–300 pb ; `NOT_INFORMATIVE` est l'issue probable sous 0,25 σ, G4 §7.2) ; G5 Gate 1
historique ≥ **100 instants et ≥ 30 jours distincts par type décisif** ; instants disponibles (calcul d'audit sur les
calendriers et les `listTime`, pas une lecture) : **T01 ≤ 211 × f_inst** (règlements à 8 h dans la couverture D_cov de
`funding-rate-history`, fetch du funding le 2026-11-02 ; ≈ 157 le 11-20, ≈ 124 le 12-01 ; f_inst = part des instants bruts avec
≥ 1 instrument retenu ; `NOT_INFORMATIVE` attendu si f_inst < 0,47), **T05 = 105** (jours NYSE hors CPI / Employment Situation
avec ≥ 1 instrument éligible, 2026-04-29 → 2026-10-13) ; G5 Gate 2 prospectif ≥ **100 instants et ≥ 30 jours par type**
(T01 ≈ 449 × f_inst sur les 150 jours du 2026-11-02 au 2027-03-31 ; T05 ≈ 92 au 2027-03-31, d'où la décision P-G5-10 (i) du
propriétaire) ; G1 ≥ **600 fills** en
simulation sans contrainte (critère stress − calme). Sous le plancher : `NOT_INFORMATIVE`, **jamais d'extension sur résultat**,
prolongation **pour trous de données** seulement, bornée et écrite au gel.

**Amendement 2026-10-21 (P-G5-7)** : texte du 2026-10-20 « G5 Gate 1 historique ≥ 100 instants par type d'événement (le jeu
`okx-xperp-1m-history` en offre ≈ 600 par photo de funding sur ≈ 200 jours) » remplacé. Les ≈ 600 ignoraient la profondeur
« up to three months » de `funding-rate-history`, qui borne T01 à D_cov, et l'éligibilité `listTime + 30 j`, qui borne T05 à 105
(G5 §4.4, §5.2, §7.4 ; relecture quant de G5 B3, m4 ; piège 9 de la red team). Effet détectable : T05 δ_min ≈ 0,236 σ et
δ₈₀ ≈ +15,6 à +28,3 pb pour σ de conception 60–120 pb (+10 pb n'est pas détectable avec une puissance de 0,80, écrit d'avance) ;
`NOT_INFORMATIVE` de T01 probable, écrit d'avance (G5 §7.4).

**Amendement 2026-10-21 (relecture review-b quant B2, M2, m4 ; D7, D8).**
- **T05 au Gate 1 : 105 instants**, BTC et ETH compris. D8 (piste S classée sans suite) rend P-S3 sans objet : BTC et ETH
  restent dans l'univers de G5 Gate 1. Pour mémoire, l'option α de P-S3 (BTC et ETH retirés) aurait donné **103** instants (ceux
  du 2026-04-29 et du 2026-04-30 reposent sur BTC seul ; marge de 3 sur le plancher) : calcul d'audit, sans objet depuis D8.
- **Puissance de T05 avec la variance des contrôles** (§3.2 amendé ; 105 instants d'événement contre ≈ 311 de contrôle) :
  δ_min ≈ **0,27 σ** et δ₈₀ ≈ **+16,5 à +32,7 pb** pour un σ de conception de 60 à 120 pb, au lieu de 0,236 σ et +15,6 à
  +28,3 pb (approximation sans les contrôles). T01 (211 contre ≈ 665) : facteur ≈ 1,15.
- **Gate 2 : « T05 ≈ 92 » et « T01 ≈ 449 × f_inst » sont des bornes hautes**, celles de T0 = 2026-11-02, instant
  **inatteignable** : T0 ≥ gel ≥ fin de P3, et P3 commence le 2026-11-02. Pour un T0 atteignable, du 2026-11-03 au 2026-11-20
  (calcul d'audit sur les fériés de l'Annexe C de G5 ; dates BLS 2027 UNKNOWN, ≈ 2 par mois) : T05 au 2027-03-31 = 101 → 88 jours
  NYSE, soit **91 → 80 instants** (décompte exact sur les calendriers relevés le 2026-10-22) ; T01 = **446 → 395** instants bruts × f_inst (seuil de f_inst : 0,224 → 0,253).
- **D7** (point 3 du 2026-10-21). P-G5-10 (i) est refusée : la fenêtre de T05 s'arrête au 2027-03-31, et **T05 au Gate 2 est
  `NOT_INFORMATIVE` par construction**, accepté par écrit. Pour mémoire, l'option refusée « fin au 2027-04-12 » ne donnait
  ≈ 97–98 instants qu'à T0 = 2026-11-03 et ≈ 85 à T0 = 2026-11-20, jamais 100 ; seule une fin fixée par le calendrier, refusée
  avec elle, atteignait 100, en capturant au-delà du 2027-03-31. Le corps de G5 (§2.1 « ≥ 100 instants », §10.3 « ≈ 100 ») se
  trompait dans le sens de l'option refusée : D7 n'en est pas affectée. P-G5-10 (ii) : « Nn » est lu comme un refus et une
  confirmation explicite est demandée ; sans source d'indice, T01 au Gate 2 est `INCONCLUSIVE` par construction (conséquence en
  §2.3).
- « d'où la décision P-G5-10 (i) du propriétaire » (ci-dessus) se lit : décision prise, D7.

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

**État 2026-10-21 (P-G5-6, signalé avec l'amendement P-G5-7)** : le registre est **créé** (volet `docs-b` de
`20261020T2321Z-g5-periods`) avec les lignes `okx-xperp-1m-history-2026-03-30..2026-10-13` (`unread`), `okx-xperp-funding-history`
(`unread`, scellé, à remplir au fetch), `okx-xperp-prospective-G5` et `okx-xperp-capture-2026-11-02` (`prospective`),
`hyperliquid-node-fills` (= `hl-fills-G4-gate1` ci-dessus) et `dydx-v4-indexer` (candidats G4 Gate 1, `unread`), et le journal
des décisions D1–D4. Les autres lignes ci-dessus restent à créer par leurs protocoles avant leurs gels.

**État 2026-10-21, seconde mise à jour (relecture review-b quant m10 ; D5–D9).** Le journal du registre reçoit D5–D9 mot pour
mot ; les lignes changent par le journal d'abord : P-G5-1 (D5, facteur 0,4), P-G5-4 (D6), P-G5-10 (D7), P-S3 sans objet (D8 :
BTC et ETH restent ; l'ordre « P-S3 tranché avant le fetch scellé du funding » est écrit et tenu, la liste `--inst-ids` = les 25
analysés), source du Gate 1 de G4 (D9 : dYdX v4, Hyperliquid en repli). Lignes annoncées : `okx-xperp-funding-rate-schema-G5`
(`selection`, échantillon de schéma du canal `funding-rate` sans aucune valeur de taux, avant le gel du Gate 2) et
`okx-xperp-index-history-G5-gate2` (`unread`, créée seulement si P-G5-10 (ii) (b) est décidée, ce que D7 refuse) ;
`okx-xperp-funding-trackS` n'est plus à créer (D8).

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

**Amendement 2026-10-21 (D1 ; relecture review-b quant m6).** La décision 14 est prise par D1 : le dépôt `pivox/tradingV3` ne
reçoit que des **agrégats statistiques sans prix** ; séries, tables chiffrées et fichiers de capture restent dans
`tradingV3-private` ; le travail d'agents sur ces données n'entraîne aucun modèle commercialisé ni partagé. Le défaut « verdicts
qualitatifs seulement » ci-dessus (texte du 2026-10-20) est remplacé ; G5 §4.8 suit déjà D1.

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

7.4 **Fenêtre réservée du pilote de capture L2 et séparation des confirmations (P-L2-1, décision D14 du 2026-10-22,
`LP-OWNER-DECISIONS-2026-10-21.md`).** Le propriétaire réserve au pilote L2 (branche `claude/research-l2-capture-pilot`, X-Perps BTC et
ETH, carnets 400 niveaux et trades, 24 h au plus) une courte fenêtre d'exploration de **développement**, créneau préféré
2026-11-02T00:00Z → 2026-11-03T00:00Z (un départ manuel plus tardif la raccourcit, ne décale jamais la fin). Recouvrement réel, établi sur
les protocoles et les specs seulement (aucune donnée lue) :

| Élément | Données | Début réel de l'évaluation, règle de T0 | Instruments | Périodes antérieures et empreintes | Lecture, scellés | Recouvrement avec le pilote **avant** P-L2-1 | Application de P-L2-1 |
|---|---|---|---|---|---|---|---|
| **P3** (comptage, plan §2.2) | `research_lp count` 24–72 h, aucune valeur persistée ; départ prévu 2026-11-02 00:00 UTC (plancher) | pas de T0 ; sa fin entre dans les T0 de G2 et du Gate 2 de G5 | tous les `FUTURES` comptés, BTC et ETH compris | aucune ; mesure couverture, cadence, reconnexions et budget de souscriptions par IP, charge du collecteur ; comptages `selection` (univers ≤ 40) | table publiée ; comptages hachés, statut `selection` | **certain** : même départ, même hôte EEE, même IP ; le pilote charge la machine et le budget de connexion pendant le comptage (couverture, reconnexions et comptages de sélection biaisés) ; comptages horodatés dans la fenêtre du pilote | P3 compte au plus tôt au plancher F = 2026-11-03T12:00Z (`research_lp count` et `capture --counting-only` refusent un `--not-before` antérieur ; dérogation de développement seulement, tracée) ; jamais pendant le pilote ; durée inchangée ⇒ fin ≥ 2026-11-04T12:00Z |
| **G2** | capture continue D1 ≥ 2026-11-02 | T0 = premier 00:00 UTC ≥ max(2026-11-02 ; fin de P3 ; gel + 24 h) ; rodage = jour T0 ; scoring T0+1 → T0+14 ; prolongation ≤ 7 j, fin dure T0+21 ; futilité au 7ᵉ jour scoré | univers ≤ 40 (métadonnées + comptages P3), BTC et ETH toujours (C0), swaps globaux si acceptés | rodage = jour T0 (liquidité du jour UTC précédent pour T0+1) ; décision sur le dernier `books5` antérieur ; funding traversé | une exécution à la fin ; futilité booléenne seule | **possible** pour le décisif (T0 ≥ 2026-11-05 en pratique, donc après la fenêtre, mais aucune règle ne l'imposait) ; **certain** pour sa sélection (comptages P3 pendant la fenêtre) | T0 = premier 00:00 UTC ≥ max(2026-11-02 ; F ; fin de P3 ; gel + 24 h) ⇒ ≥ 2026-11-06 en pratique ; `simulate` et `events` refusent les fichiers jour 2026-11-02 et 2026-11-03 (`--separation-waiver-reason` : développement seulement, tracé) ; 14 jours scorés et 1 000 fills inchangés |
| **G4 Gate 1** | archives Hyperliquid / dYdX ≤ 2026-09-30 | une exécution | — | — | — | **absent** | rien |
| **G4 Gate 2** | capture continue ; jours capturés avant le gel = historique du percentile | T0 = gel du Gate 2 (≥ `G4_GATE1_PASS`, ≥ 2026-11-02, ≥ P3) ; fin T0 + 90 j ; futilité T0 + 45 j ou 50ᵉ instant | univers large (`liquidation-orders` de tout `FUTURES`) | historique de 2 016 fenêtres de 5 min (7 j), au moins 288 (1 j) ; σ₁₅ et notionnel sur 24 h ; contrôle apparié sur l'historique | une exécution (futilité exceptée) | **possible** : certain si T0 < 2026-11-10T12:00Z (l'historique de 7 j atteint la fenêtre) ; absent au T0 du plan (≈ 2026-12-01 → 12-10) | `events` refuse les jours 2026-11-02 et 2026-11-03 : l'historique commence au plus tôt le 2026-11-04, fenêtres antérieures absentes, jamais imputées ; 90 j et planchers inchangés |
| **G5 Gate 1** | 1 m historique 2026-04-28 → 2026-10-14 (exclu) ; funding scellé le jour du verdict `fwd-okx-r1`, bougies, mark et indice après le gel | une exécution ; `END_EXCLUSIVE` codé | 25 X-Perps (D1 ∩ métadonnées, D6) | éligibilité `listTime` + 30 j ; P̂ 480 min ; D_cov (≈ 3 mois de funding) | statistiques scellées pour `NOT_INFORMATIVE` / `INCONCLUSIVE` jusqu'au verdict du Gate 2 | **absent** | rien (spec Gate 1 `0.4.1-draft` inchangé) |
| **G5 Gate 2** | capture continue ≥ 2026-11-02 ; indice (b) relu une fois après la fenêtre | T0 = premier 00:00 UTC ≥ max(2026-11-02, gel, fin de P3) ; scoring jusqu'au 2027-03-31 (D7) | univers G2 §3 (≤ 40), BTC et ETH (D8) | P̂ : 480 min avant t_arm ; indice lu depuis T0 − 540 min ; exclusions des contrôles par empreintes de règlement à ± 60 min (T0 − 65 min) ; éligibilité `listTime` + 30 j (métadonnée) ; descriptifs : rv24 (24 h) et médiane sur 30 j, σ₁₅ sur 24 h (lecture portefeuille) ; grille chargée dès `window.start` − 1 j | une exécution après la fenêtre ; aucun scellé propre | **certain** pour les descriptifs (la grille et rv24 lisaient le 2026-11-02) ; **possible** pour le décisif (T0 = 2026-11-03, borne « atteignable » du brouillon, mettait le classifieur de la première photo dans la fenêtre) | T0 ≥ 2026-11-04 ; premières observations T01 2026-11-03T16:00Z, T05 2026-11-03T23:59Z ; lignes de E écartées par le chargeur avant tout calcul ; `g5_index_fetch` refuse un départ dans E ; le lecteur refuse un `FROZEN` qui s'en écarte (G5 §10.6) |
| **G1** (aucun protocole) | — | — | — | condition rv24 > médiane de 30 j de capture : 30 jours de rodage | — | **possible** si un protocole futur comptait ses 30 jours depuis le 2026-11-02 | règle générale : tout protocole futur du programme applique E (rodage compté depuis F) |
| **Sélections** (liste D1, liste des 25, univers ≤ 40) | D1 : 29 X-Perps (volume du 2026-10-14, D6) ; G5 Gate 1 : 25 par métadonnées après le 2026-10-31 ; G2 et G5 Gate 2 : métadonnées + comptages P3, BTC et ETH toujours | — | — | — | — | **possible** par deux voies : comptages P3 perturbés par le pilote ; plafond du collecteur (G2 §3.4) décidé sur les volumes du pilote. Le pilote ne choisit aucun instrument (BTC et ETH par métadonnées, toujours inclus ailleurs) | voies fermées : P3 jamais pendant le pilote et compté ≥ F ; plafond décidé sur les faits de P3 seuls ; filtres figés avant toute lecture de P3 (G2 §0.8) ; contenu du pilote jamais une source de seuil, de fenêtre, d'univers ni de plafond |

**Séparation retenue (règle unique, codée).** Fenêtre réservée R = [2026-11-02T00:00Z ; 2026-11-03T00:00Z) ; marge 12 h ; plancher
F = 2026-11-03T12:00Z ; intervalle exclu E = [2026-11-02T00:00Z ; F). Aucune observation horodatée dans E n'entre dans un calcul décisif du
programme, ni dans ses empreintes, lectures préalables, rodages, calibrations, règles de qualité ou sélections ; les données de E (pilote
ou collecteurs LP) sont des données de **développement** (statut au registre). Le texte machine est `research_lp.separation.BLOCK`
(sha256 canonique `4b96e2bd3987e123b597b6534a9c464580d560db399401849cf992af004d489e`), recopié dans le spec Gate 2 de G5
(`pilot_separation`, `0.1.8-draft`) et vérifié par le pilote avant toute capture et toute relecture de son contenu.

**Pourquoi une règle plutôt que la chance.** Les règles de T0 existantes ne garantissaient pas la séparation : avec P3 lancé au plancher
le 2026-11-02 et d'une durée de 24 h, le brouillon G5 tenait 2026-11-03 pour « atteignable », ce qui plaçait le classifieur de la
première photo dans la fenêtre ; ses descriptifs lisaient le 2026-11-02 quel que soit T0 ; P3 aurait compté pendant le pilote ; le Gate 2
de G4 lisait son historique de 7 j sans plancher. P-L2-1 rend la séparation **contraignante** : T0 du Gate 2 de G5 ≥ 2026-11-04
(premier 00:00 UTC ≥ F + 540 min), P3 au plus tôt à F, G2 et G4 privés des jours 2026-11-02 et 2026-11-03.

**Coût et puissance.** Une marge de 12 h ne coûte aucun jour par rapport à une marge nulle (P3 doit suivre le pilote, durer au moins 24 h
et précéder le gel : T0 du Gate 2 de G5 ≥ 2026-11-05, de G2 ≥ 2026-11-06 dans les deux cas) ; 24 h en coûterait un. Effet chiffré
(calcul d'audit, listes du spec `0.1.8-draft`) : T05 au Gate 2 de G5 90 instants au plus (T0 2026-11-04), 89 au T0 réaliste, contre 91 —
`NOT_INFORMATIVE` par construction inchangé sous D7 ; T01 443 / 440 instants bruts contre 446, f_inst ≥ 0,226 / 0,227 pour 100 instants,
δ₈₀ + 0,7 % au plus ; G2 : 14 jours scorés inchangés, verdict décalé d'un jour au plus ; G4 Gate 2 : 90 jours inchangés ; fins décidées
(2027-03-31) et planchers statistiques inchangés.

**Conditions de lecture du pilote.** Tant que la séparation n'est pas démontrée et enregistrée (D14 et `REGISTRY.md`, bloc dans le spec
Gate 2 de G5, vérifié par sha256), seuls les résumés de qualité et de volume (en-têtes, aucun prix) sont permis. Ensuite, le contenu est
ouvert pour le développement seulement (`replay-check --separation-spec … --unsealed-ref "P-L2-1 …"`), jamais pour une confirmation ; tout
protocole gelé après cette ouverture la déclare parmi ses expositions antérieures.

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
        famille Holm, une exécution, ≥ 100 instants/type (T01 ≤ 211 × f_inst,
        T05 105 — amendement 2026-10-21, P-G5-7)
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

**Amendement 2026-10-21 (P-G5-7)** : dans le paragraphe ci-dessus, « ≈ 10 fois la puissance du prospectif (≈ 200 jours × 3 photos
≈ 600 instants par type pour 00/08/16 UTC) » est caduc. Instants disponibles au Gate 1 (calcul d'audit, G5 §4.4, §5.2) : T01
≤ 211 × f_inst (D_cov, fetch du funding le 2026-11-02), T05 105 ; au Gate 2, T01 ≈ 449 × f_inst et T05 ≈ 92 au 2027-03-31. Le
Gate 1 reste **confirmatoire** (signes et fenêtres gelés depuis la théorie, jeu `unread`), mais sa puissance est au plancher, et
`NOT_INFORMATIVE` y est une issue probable de T01, écrite d'avance. Le brouillon G5 existe (`LP-G5-DRAFT-PREREGISTRATION.md`,
`DRAFT_NOT_FROZEN`) ; il passe **en tête de la piste L** (mandat du propriétaire du 2026-10-20, `LP-OWNER-DECISIONS-2026-10-21.md`)
sans changer les dépendances de cette séquence (verdict `fwd-okx-r1`, gel avant la lecture de G2).

**Amendement 2026-10-21 (relecture review-b quant M1, M2, m8 ; D7).** (1) « mêmes signes et fenêtres que le Gate 1 » (ligne G5
Gate 2 du schéma) : « fenêtres » désigne les **fenêtres de signe et de rendement** (et les empreintes). La mesure primaire du
Gate 2, `sim_net_bp`, va jusqu'à la sortie simulée (passive de h+15 à h+60 puis preneur pour T01, de h+60 à h+75 pour T05) :
c'est une règle d'exécution **gelée** au protocole G5 §5.5, pas un choix après lecture ; la construction du Gate 1 sur les
derniers prints (`net_bp_gate1_construction_on_last_prints`, descriptive) est imprimée à côté du primaire. (2) « T05 ≈ 92 au
2027-03-31 » et « T01 ≈ 449 × f_inst » sont des bornes hautes (T0 = 2026-11-02 inatteignable) ; plage réaliste en §3.3 amendé ;
sous D7, T05 au Gate 2 est `NOT_INFORMATIVE` par construction. (3) Une Phase 4 ne s'ouvre que sur un `PASS_PROGRAMME`
prospectif (§2.3 amendé).

**Amendement 2026-10-22 (P-L2-1, D14 ; §7.4).** Dans le schéma ci-dessus, « P3 … ≥ 2026-11-02 » se lit « P3 ≥ 2026-11-03T12:00Z,
jamais pendant le pilote de capture L2 » (`research_lp count` refuse un `--not-before` antérieur) ; tout T0 de G2, du Gate 2 de G4 et du
Gate 2 de G5 suit la règle de §7.4 (aucune observation de [2026-11-02T00:00Z ; 2026-11-03T12:00Z), lectures préalables comprises).

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

**État au 2026-10-21 (décisions D1–D9 de `LP-OWNER-DECISIONS-2026-10-21.md` ; relecture review-b quant m6).** Ajout daté ; la
table ci-dessus n'est pas réécrite.

| # | État | Pièce |
|---|---|---|
| 1 | **Accordée** : capture publique continue du 2026-11-02 au plus tard le 2027-03-31, ≤ 40 X-Perps crypto + 2 swaps globaux, hôte EEE et, pour les deux swaps, hôte global ; tout allongement, instrument, canal ou hôte ajouté = nouvelle décision | D1 |
| 2 | Ouverte | — |
| 3 | Ouverte (D2 est un relevé d'interface, pas un GET) | D2 |
| 4, 5, 6 | Ouvertes | — |
| 7 | Ouverte : D1 couvre la capture ; le lancement de P3 reste à décider | D1 |
| 8 | **Consentie** pour Hyperliquid (plafond provisoire 10 USD) ; source principale du Gate 1 de G4 : **dYdX v4**, Hyperliquid en repli ; règle de combinaison en §2.1 amendé | D3, D9 |
| 9 | S_éco **fixé** (règle unique de §1.2 amendé : ≥ 97,25 USD/an) ; coût fixe **déclaré nul** (§1.2) ; durée maximale ≤ 2027-03-31 inchangée (D7 l'applique à G5) ; m_max = 7 : **règle**, aucune décision requise (§2.3) ; α = 0,10 inchangé ; option ouverte au propriétaire : relever S_éco par la lecture 365/360 | D4, D7 |
| 10, 11, 12 | Ouvertes | — |
| 13 | S-O1 = oui au niveau de l'interface ; **piste S classée sans suite** (S-O5) ; P-S3 sans objet ; une réouverture serait une décision nouvelle | D2, D8 |
| 14 | **Prise** : le dépôt ne reçoit que des agrégats statistiques sans prix (§6.3 amendé) | D1 |
| 15 | Lecture couverte par D1 ; échelle confirmée, facteur de cadence 0,4 retenu par défaut ; dérogation sur l'univers accordée ; option `market-data-history` ouverte | D1, D5, D6 |
| G5 P-G5-10 | (i) **refusée** : fin de T05 au 2027-03-31, `NOT_INFORMATIVE` par construction ; (ii) « Nn » lu comme un refus, **confirmation explicite demandée** | D7 |

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

**Traitement de la relecture `review-b/quant.md` (2026-10-21).** Relecture : `tradingV3-private/research/20261020T2321Z-g5-periods/review-b/quant.md`
(verdict `NEEDS_CHANGES`). Même convention que ci-dessus. Constats propres au spec Gate 2 et au protocole G5 (B1, M3, M4, M5, m3,
m7, m9, m12, et les parts « spec » ou « corps » des autres) : table de l'Annexe B de `LP-G5-DRAFT-PREREGISTRATION.md`.

| Constat | Traitement |
|---|---|
| **B1** — « gelés ensemble » n'est imposé que dans un sens | **Appliqué** au spec Gate 2 (`frozen_with.rule` : la clause invérifiable « refused after any Gate 1 reading » est retirée et remplacée par la règle de la relecture). **Signalé** : capacité `gate2_frozen_together` du spec Gate 1, son code (a2) et sa ligne (B) au corps de G5 (a1) — hors du périmètre d'écriture de cette passe |
| **B2** — P-S3 vise le Gate 1 | **Sans objet depuis D8** (piste S close ; BTC et ETH restent) : §3.3 (T05 = 105 ; 103 sous α, pour mémoire) ; registre (ordre « P-S3 avant le fetch scellé » écrit, tenu) ; plan ; spec Gate 2 (textes). **Signalé** : champ P-S3 du spec Gate 1 rempli `moot_track_s_closed` avec la référence D8, jamais en attente, et valeur à accepter par le lecteur (a2) ; P-G5-3 du corps (a1) |
| **M1** — ce qu'ouvre un `PASS_PROGRAMME` | **Appliqué** : §2.3 (phrase de la relecture), §1.2, §1.3, §8 ; spec Gate 2 `verdict.pass_effect` ; plan (lignes G5, Phase 4, règle d'arrêt). **Signalé** : G5 §8 (a1) |
| **M2** — T0 impossible ; option (c) | **Appliqué** : §3.3 et §8 (bornes hautes, plage réaliste de T0, D7) ; spec et Annexe B. **Signalé** : G5 §2.1 et §10.3 (a1). **Réponse à la revue** : la décision P-G5-10 (i) est prise (D7, refus) ; l'erreur du corps allait dans le sens de l'option refusée, et la plage réaliste ne rend aucune option refusée plus attractive (seule (c) atteignait 100, en capturant au-delà du 2027-03-31, ce que D7 refuse) : aucune question nouvelle n'est posée. `covers` n'est ni étendu, ni l'option (c) bornée : sans objet sous D7, et le lecteur refuse déjà une option (c) qui sortirait de `covers` |
| **M3, M4, M5** | Spec Gate 2 et Annexe B de G5 ; registre : ligne `selection` annoncée pour l'échantillon de schéma `funding-rate` (M3) |
| **m1** | **Appliqué** : §2.3 (protocole successeur, aucune prolongation motivée par les valeurs) ; spec `sealing`. **Signalé** : G5 §10.4 (a1) |
| **m2** | **Appliqué** : §2.3 (G5-Gate2-T05 reste dans m, p = 1) ; spec `multiplicity.programme.otherwise` |
| **m4** | **Appliqué** : §3.2 (facteur √(1 + n_e/n_c)), §3.3 (T05 : 0,27 σ ; +16,5 à +32,7 pb). **Signalé** : G5 §7.4 (a1) |
| **m5** | **Appliqué** : §1.2 (règle unique ≥ 97,25 ; coût fixe déclaré nul). **Réponse à la revue** : la valeur n'est pas recopiée dans les specs par cette passe. Le lecteur strict exige le même S_éco aux deux portes (`g5_gate2.check_gate1_identity`, bloc `economic`) et le spec Gate 1 est hors de son périmètre : changer le seul Gate 2 le ferait refuser. 97,25 est écrit dans les deux specs ensemble (a2 ou passe de gel), avant le gel ; un spec à moins de 97,25 contredit §1.2 |
| **m6** | **Appliqué** : §1.2 (D4), §6.3 (D1), §11 (états datés) ; m_max = 7 déclaré **règle** sans décision (§2.3), l'une des deux voies proposées par la relecture |
| **m8** | **Appliqué** : §8 ; spec `statistics.measure_definition`. **Signalé** : impression côte à côte dans la sortie (a2) |
| **m10** | **Appliqué** au registre : §4 (lignes annoncées, ordre P-S3 → fetch), journal d'abord |
| **m11** | **Appliqué** : §2.1 (toute source du Gate 1 de G4 ; « les deux doivent passer » par défaut ; repli sur condition de données seulement) ; D9 a fait de dYdX la source principale |
| **m3, m7, m9, m12** | Annexe B de G5 |

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
