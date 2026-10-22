# Le chemin vers un PnL positif : plan de construction et de mesure (2026-10-20)

> **Nature du document.** Plan au propriétaire, rédigé le 2026-10-21 (rédacteur du plan, volet `synthesis` du programme
> `20261020T1430Z-lp-programme`) en réponse au mandat du 2026-10-20 : « Trouve un moyen pour que ça fonctionne, il y a forcément
> un moyen de rendre le PnL positif ». Il met en ordre ce que les volets a1–a5 ont construit et ce que les deux relectures
> (`review/quant.md`, `review/cto.md`, verdicts `NEEDS_CHANGES`) ont fait corriger ; il n'ajoute aucune hypothèse, aucun seuil,
> aucun chiffre de marché. Il repose sur les deux rapports de base, lus en entier :
> `docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md` (théorie : les seules poches accessibles à un participant
> lent sont de la fourniture de liquidité **événementielle** — G2, G4, G5, G1 — séquence P1→P2→P3→G2→G4→G5→G1, règles §3.5) et
> `docs/handbook/reports/research-positive-pnl-structural-2026-10-16.md` (structurel : le seul terme contractuel positif est le
> portage de funding couvert K1, dont la seule variante défendable, (c) auto-collatéralisée, dépend d'une règle de compte non écrite).
> **Interdits respectés** : aucune donnée de marché lue (aucun WebSocket, aucun endpoint `market/*`/`public/*`, aucun jeu de
> données), rien daté 2026-10-14..31 sur une place, rien lu sous `20261013T100000Z-forward-okx-r1/` ni dans `fwd-okx-*`, aucune
> commande git/Docker/systemd, aucun `.env`, clé ou drapeau ; écritures : ce fichier et `synthesis/NOTES.md` (privé). Le seul
> code exécuté est la suite de tests `tests/test_research_lp_*.py` hors réseau (faux serveur sur 127.0.0.1), pour constater son état.
> **Conventions** : « établi » = lu dans le code (chemin:ligne) ou une page officielle (URL, date) ; « programme » = chiffre
> recopié d'un rapport du dépôt ; « calcul d'audit » = arithmétique sans donnée ; **UNKNOWN** = ni sourcé ni mesuré. Rien ici
> n'autorise un ordre, une démo, une capture longue ou un gel : chaque pas est soumis à une décision écrite (§5).

**Contexte fixe** (programme). Compte retail français, 500 USDT, OKX Europe X-Perps (`instType FUTURES`, identifiants
`<BASE>-USD_UM_XPERP-<YYMMDD>`, `ruleType xperp`), marge USDC, frais 2 pb maker / 5 pb taker vérifiés à l'interface du compte le
2026-10-15 (`docs/handbook/research/H1-XPERP-ACCOUNT-CHECK.md`, API non vérifiée), boucle REST à la minute (TradingV3).

---

## 1. La réponse en dix lignes

1. **Il n'y a pas de bouton « PnL positif ».** La théorie et ~1 870 configurations testées ferment le scalping à la minute sur
   états d'indicateurs (signaux = contrôles ; rapport théorie §1 l.1, §5 l.1), et la machine déployée **ne poste aucun ordre**
   (établi : les deux conditions 1 m du profil `scalper_micro` exigent une preuve que seul le chemin Paper fournit ; théorie,
   en-tête). Ce qui reste est un **programme de mesure** en deux tracks, pas une stratégie à déployer.
2. **Track L — fourniture de liquidité événementielle** : être payé pour l'immédiateté là où la théorie nomme un payeur
   (preneur impatient sur carnets larges G2, liquidé G4, flux calendaires G5, impatient en stress G1). Prior **moyen** partout,
   preuve publiée du signe **UNKNOWN** (théorie §2, §3.2). Mesuré **en prospectif** sur X-Perps, par capture publique +
   simulateur de recherche, jamais par un ordre (règle 13).
3. **Track S — portage couvert K1(c)** : short X-Perp BTC/ETH à 1× dont la marge est le **spot** BTC/ETH (Selected Margin).
   Terme contractuel I = 0,01 %/8 h (perps-funding-fee-mechanism, màj 2026-08-27 ; structurel §1 l.1) ; funding réalisé
   **UNKNOWN**. Existe seulement si le compte admet le spot comme marge d'un short : **dix minutes de lecture d'interface**
   tranchent (structurel §1 l.7) ; sinon le track S est clos (K1(a) réfutée à 500 USDT).
4. **Ce qui est construit aujourd'hui** (§4) : un collecteur X-Perps `count`/`capture` par segments append-only avec plancher de
   quarantaine dans le code, un simulateur post-only (touch/through/queue, funding, deux lectures) et trois détecteurs,
   **40 tests verts** (rejoués le 2026-10-21, hors réseau) ; une architecture cible ; trois protocoles `DRAFT_NOT_FROZEN`
   (G2, G4, règles communes) ; un desk documentaire haché (31 pages officielles). Aucun ordre, aucune capture, aucun gel.
5. **Ce qui est mesuré d'abord** : P3 (24–72 h de **comptage**, zéro prix : les X-Perps sont-ils servis par `books5`/`trades`/
   `liquidation-orders` ?), puis **G2** (14 jours : coût réel de fournir à la minute sur carnets ≥ 6 pb, en **aller-retour**),
   en parallèle **G4 Gate 1** sur l'archive Hyperliquid (signe du retour post-cascade, sur consentement) et **G5 Gate 1
   historique** sur les bougies 1 m X-Perps jamais lues (sur décision §9.4).
6. **Ce qui déciderait d'un go** : un `PASS_PROGRAMME` (LB90 > 0 sur le net **aller-retour**, survivant à Holm sur la famille
   m_max = 5 ; `LP-PROGRAMME-GATES.md` §2) **et** la porte économique (« poursuivi » ⇔ point ≥ S_éco **et** UB90 ≥ 2 S_éco ou
   LB90 > 0 ; §1.2). Un go n'autorise qu'une **proposition écrite** de Phase 4 (chemin X-Perp, échelle post-only, **démo**),
   jamais un ordre par lui-même.
7. **Ordre de grandeur honnête** (calcul d'audit, programme). Track L si tout passe : **des dizaines à quelques centaines
   d'USD par an** à 500 USDT (600 fills × ~100 USD × +10 pb ≈ 350–400 USD/an ; 2 créneaux × 5 fills/jour ≈ 90 USD/an ;
   incertitude ×4–15 selon le taux de fill — théorie §5 l.5). Track S à F = I (cas de référence, pas une prévision) :
   41,62 USD/an net pour (c), 24,58 pour (a) ; plage selon le funding réalisé −1,5 … +41,6 (c) ; contrôles SOFR 18,67, Simple
   Earn 17,5 USD/an, Livret A 7,56 EUR/an (structurel §1 l.3). S_éco proposé ≈ 97 USD/an (5 × SOFR 3,89 % × 500).
8. **Le coût fixe dépasse probablement le plafond** : collecteur, **disque** (≈ 170 Mo/jour/instrument de `books5` brut ;
   ≈ 1 To brut sur la séquence, compression UNKNOWN), supervision d'un processus WS de cinq mois, heures du propriétaire,
   Phase 4 « plusieurs semaines » d'un développeur (relecture CTO §4.3). Le programme vaut d'abord par sa capacité à **clore
   proprement** la question (théorie §5 l.6).
9. **Calendrier** : rien avant le verdict `fwd-okx-r1` (après le 2026-10-31) ; capture au plus tôt le **2026-11-02 00:00 UTC**
   (plancher `QUARANTINE_FLOOR` dans le code) ; premier verdict G2 ≈ **2026-11-22** ; G4 Gate 1 novembre–décembre si consenti ;
   G4 Gate 2 et G5 Gate 2 décembre → mars ; clôture ≤ **2027-03-31** (§3).
10. **Tout bloque sur trois décisions du propriétaire** (§5) : la licence OKX API Agreement §9.4 décidée sur l'**échelle réelle**
    (une capture continue de ≈ 5 mois, pas « 2–8 semaines »), les **GET en lecture seule au compte** (posMode, marge gelée,
    devise, spot comme marge), et la **porte économique** (S_éco, coût fixe, durée). Sans la première, le track L s'arrête à P1/P2.

---

## 2. Les deux tracks

### 2.0 Pourquoi deux tracks, et pourquoi des mesures

Le rapport théorie classe les sources de profit à court horizon (§2, 20 lignes) : les poches sous la seconde sont inaccessibles
à une boucle à la minute (et nous y sommes **payeurs** : sélection adverse 1,1–1,5 pb par jambe passive, programme) ; les états
d'indicateurs n'ont **aucun payeur** ; ce qui reste à un participant lent est événementiel et conditionnel (§1 l.3). Le rapport
structurel examine 43 idées non directionnelles et n'en retient aucune comme source de PnL à 500 USDT ; seule la variante (c) de
K1 survit, conditionnée à une règle de compte (§1 l.2, l.7). D'où deux tracks indépendants, l'un **L** (mesures de fourniture
de liquidité, séquence P1→P2→P3→G2→G4→G5→G1), l'autre **S** (portage couvert, vérification de compte puis protocole à 12 mois).
Les règles communes (porte économique, Holm, puissance, deux lectures, funding et marge gelée dans tous les nets, registre,
interdits) sont dans `docs/handbook/research/LP-PROGRAMME-GATES.md` et ne sont pas répétées ici.

### 2.1 Track S — portage de funding couvert auto-collatéralisé K1(c)

Référence : `LP-PROGRAMME-GATES.md` §10 (conditions S-O1…S-O8, amendements A-S1…A-S4) ; rapport structurel §2.1 (K1), §4.1
points 7, 10, 11, 13, 14, 15, §6.2 ; texte de référence du protocole :
`tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-A/notes/PROTOCOL-H3A-GATE1-DRAFT.txt` (« CS-FUND », `DRAFT_NOT_FROZEN`,
2026-10-15 : 12 mois prospectifs, critères C1–C5, futilité après le mois 6 — relu pour ce plan).

| Étape | Livrable | Prérequis | Qui décide | Durée | Critère de passage | Ce qu'un échec ferme |
|---|---|---|---|---|---|---|
| **S1 — Vérification du compte (dix minutes, aucun ordre)** | Métadonnées hachées : (S-O1) liste des actifs de marge X-Perps du compte EEE et décotes affichées — le **spot BTC/ETH** y figure-t-il ? un short `BTC-USD_UM_XPERP-…` peut-il être porté par du BTC spot ? ; (S-O2) devise de règlement USD-M (USD par défaut, à fixer USDC avant toute position) ; (S-O3) paires BTC-USDC / ETH-USDC, transfert USDC Funding → Trading, aperçu One Way Convert sans exécuter ; (S-O4) barème « Spot & derivatives » Regular. Compléments lecture seule : `GET /api/v5/account/config` (posMode, acctLv, perm), `account/balance` (`ordFrozen`, `imr`), `account/max-avail-size` (`LP-DATA-DESK` §5.2) | Clé API **Read** seule ; aucune position ouverte requise | propriétaire | ≈ 10 min d'interface + un GET | **S-O1 = oui** (spot admis comme Selected Margin) | S-O1 = non ⇒ la variante (c) n'existe pas ; (a) est **réfutée à 500 USDT** (surplus ≈ 0 à −7 USD/an face à Simple Earn pour strictement plus de risque, structurel §2.1 K1) ⇒ **track S classé sans suite** sauf décision écrite contraire |
| **S2 — Décision de principe et amendement de CS-FUND** | CS-FUND **amendé** par écrit, avant gel : A-S1 lecture rétrospective (prospectif pur **ou** Gate 1 rétrospectif `consumed_equivalent` sur consentement, « tuer seulement sur des faits de règle » : `interestRate` ≠ 0,01 %, plafonds touchés, intervalles ; jamais sur la moyenne du funding — profondeur utile ≈ 2,4 mois contre des régimes négatifs de 2 à 12 mois) ; A-S2 contrôles (référence USD = **SOFR**, Simple Earn USDC « descriptive, non admise », Livret A si devise EUR, deux côtés taxés, ligne de change rapportée) ; A-S3 impôt symétrique et asymétrie MiFID / actif numérique déclarée ; A-S4 validité d'un PASS (6 mois, proposition démo seulement, jamais > 1×, jamais de sélection par funding) ; + règle de dénouement forcé (ADL ferme d'abord la jambe **gagnante**, sans préavis ; délisting « without notice » CGU OEM §12), coupe-risque sur la **structure entière**, `ctVal` tranché par l'API (0,0001 BTC) vs 0,01 BTC de la page specs, devise USD-M, deux entités (OEM / OKX Europe Ltd) et gage de la jambe spot (CGU OEM §1.9) — `GATES` §10.4 | S1 ; S-O5 décision CS-FUND (tel quel / amendé / sans suite) ; S-O6 conseil fiscal engagé ou risque accepté ; S-O7 devise de compte ; S-O8 verdict `fwd-okx-r1` rendu | propriétaire (décisions) ; a3 (rédaction de l'amendement) | décision : propriétaire ; rédaction : 1–2 jours ; gel avant T0 | Amendement publié et gelé sha256 **avant** T0 ; ou classement sans suite écrit | Sans gel : **aucune capture de funding** n'est lancée pour le track S (`GATES` §10.3 iii) |
| **S3 — Mesure papier = CS-FUND amendé, 12 mois** | Enregistreur lecture seule distinct de `fwd-okx-*` (hôtes publics `eea.okx.com`, règlements `fundingTime < T0` rejetés par le code, hash du code au lancement), journal des **événements de règle** (interestRate, décotes, liste de marge, barème, devise, CGU « Last Updated » + sha256 hebdomadaires), indicateur ADL 0–5 si autorisé. Les **8 premières semaines sont un rodage technique interne** à la fenêtre : ni lues à part, ni consommées à part — l'« étape papier de 8 semaines » distincte a été **supprimée** après la relecture quant M9 (ses « coûts réels » sont de l'arithmétique de règle vérifiable en S1 ; un Gate 2 court n'a aucun pouvoir sur le rendement : à F = I le surplus de 56 jours vaut ≈ 0,1 % de base, structurel §4.1 p.11) — `GATES` §10.3 | S2 gelé ; T0 ≥ 2026-11-02 | propriétaire (lancement) | **12 mois** depuis T0 ; futilité **après le mois 6** si Σ y₁..₆ ≤ 0 et ≤ 3 mois positifs (texte CS-FUND l.82–83 ; `GATES` §10.3 écrit « ≥ 3 mois » — à aligner dans l'amendement) | Lecture **unique** au mois 12 : C1 Σ y_m > 0 après tous coûts ; C2 rendement net annualisé sur le capital engagé ≥ référence (**SOFR** après A-S2 ; le texte dit €STR) ; C3 y_m > 0 dans ≥ 10 mois sur 12 ; C4 tient sans le meilleur mois ; C5 > 0 pour BTC et ETH séparément (CS-FUND l.72–76) | `FAIL` / `FAIL_FUTILITY` / `INCONCLUSIVE` ⇒ track S **fermé** sur ces 12 mois, aucune variante (autres actifs, sélection par funding, levier, autre durée — CS-FUND l.94 ; A-S4 iv) |
| **S4 — Décision** | Verdict publié (`LP-S-RESULT.md` ou équivalent) : « PASS » / « PASS, sous le contrôle atteignable » (C2 au SOFR mais sous Simple Earn) / « FAIL » | S3 | propriétaire | — | PASS valable **6 mois** ; n'autorise qu'une **proposition écrite** de petite exécution démo à 1× (A-S4) | — |

Ce que le track S ne fait jamais (`GATES` §10.5) : aucun ordre, souscription, conversion, virement, stake ou dépôt ; aucune clé
avec droit Trade ; aucun drapeau ; aucune lecture de funding avant T0 hors A-S1(ii) consentie ; aucun levier > 1×.

### 2.2 Track L — fourniture de liquidité événementielle (G5 en tête ; puis P1→P2→P3→G2→G4→G1)

Références : rapport théorie §3.2–3.3 (fiches), §3.5 (règles 1–14), §6 (décisions) ; `LP-PROGRAMME-GATES.md` §8 (séquence) ;
`LP-G2-DRAFT-PREREGISTRATION.md` ; `LP-G4-DRAFT-PREREGISTRATION.md` ; `docs/handbook/technical/lp-executor-architecture.md` §5 (phases).

**Amendement 2026-10-21 (P-G5-7).** Les deux lignes G5 sont réécrites sur le protocole révisé
`docs/handbook/research/LP-G5-DRAFT-PREREGISTRATION.md` (`DRAFT_NOT_FROZEN`, révision du 2026-10-21 après
`tradingV3-private/research/20261020T2321Z-g5-periods/review/quant.md`) et ses deux specs machine, brouillons :
`python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json` et `…/g5_gate2_spec.json`. **G5 passe en tête de la piste L**
sur mandat du propriétaire du 2026-10-20 (« Ok go. Mais ta mission reste de trouver comment faire un pnl positif avec le
scalping (même sur des périodes précises) », recopié dans `LP-OWNER-DECISIONS-2026-10-21.md`) : priorité de rédaction, de code
(B) et de gel ; ses lignes sont donc en tête du tableau. Aucun prérequis ne change : rien de G5 ne tourne avant le verdict
`fwd-okx-r1` (P-G5-2) ; le funding est récupéré **scellé** le jour du verdict (P-G5-3) ; G5 est gelé **avant la lecture de G2**
(P-G5-9) ; le Gate 1 (lecture historique) ne dépend ni de P1 ni de P3, le Gate 2 dépend de la capture (D1, P3). Texte remplacé :
les lignes « G5 Gate 1 — horloge, historique confirmatoire (brouillon **à écrire**) » et « G5 Gate 2 — horloge, prospectif (dans
le brouillon G5 à écrire) » de la version du 2026-10-21, et la séquence « P1→P2→P3→G2→G4→G5→G1 » du titre. **Lecture des autres
sections de ce plan** (non réécrites par cet amendement) : « m_max = 5 » (§1 point 6, §3 ligne « ≤ 2027-03-31 », §4.3, §5 d.9) se
lit **m_max = 3 + 2K = 7** ; « ≈ 600 instants » (§3 ligne « novembre ») se lit « T01 ≤ 211 × f_inst, T05 105 » ; « brouillon G5 à
écrire » et « `REGISTRY.md` absent » (§3, §4.3, §4.6 ; §1 point 4 ne compte que trois protocoles) sont satisfaits au 2026-10-21 (`LP-PROGRAMME-GATES.md` §2.3,
§3.3, §6.2 amendés le même jour). Constat du même jour (seconde passe `docs-b`, après le commit du code `g5_eval/3`) : le parseur strict
accepte le brouillon du spec Gate 1 et refuse celui du Gate 2 ; les écarts entre le code et le Gate 2 sont tenus en Annexe B du protocole.
**Correction datée (2026-10-21, relecture `review-b/quant.md` m7).** La phrase précédente vaut pour l'état de 07:52. Depuis
08:18 (`g5_gate2.py` `94b8bec7…`), `g5_eval.load_spec` accepte aussi le brouillon du spec Gate 2 (statut `DRAFT_NOT_FROZEN`,
trois décisions en attente, tous ses types `G5_GATE2_INCONCLUSIVE`) ; le code est encore réécrit le même matin (passe de code
qui suit la relecture CTO `review-b/cto.md`) : le tableau des écarts de l'Annexe B du protocole est à refaire sur le code gelé
(P-G5-11).

**Amendement 2026-10-21 (décisions D5–D9 ; relecture `review-b/quant.md`).** Les lignes G5, G4 Gate 1 et Phase 4 ci-dessous
reçoivent les réponses du propriétaire du 2026-10-21 (`LP-OWNER-DECISIONS-2026-10-21.md`, second lot) et les corrections de la
relecture quant `tradingV3-private/research/20261020T2321Z-g5-periods/review-b/quant.md` : **D5** (P-G5-1 : échelle confirmée,
facteur de cadence 0,4 retenu par défaut) ; **D6** (P-G5-4 : dérogation sur la liste D1) ; **D7** (P-G5-10 : (i) fin de T05
au Gate 2 refusée, la fenêtre s'arrête au 2027-03-31 ; (ii) « Nn » lu comme un refus, confirmation explicite demandée) ; **D8**
(piste S classée sans suite ; P-S3 sans objet ; BTC et ETH restent dans G5) ; **D9** (G4 Gate 1 : dYdX v4, Hyperliquid en
repli). Un `PASS_PROGRAMME` d'un test **historique** (G5-Gate1-*) n'ouvre aucune Phase 4 et ne compte pas pour `GATES` §1.3
(`GATES` §2.3 amendé). **Lecture des autres sections du plan sous D7** : « G5 Gate 2 : les types funding atteignent ≈ 100
instants » et « G5 Gate 2 types quotidiens (≈ 100 jours) » (§3) se lisent : T05 au Gate 2 est `NOT_INFORMATIVE` par
construction (≈ 91 → 79 instants pour un T0 du 2026-11-03 au 2026-11-20) ; la tension (ii) de la fin du §3 est tranchée par D7 ;
T01 au Gate 2 vaut ≈ 446 → 395 instants bruts × f_inst, et `INCONCLUSIVE` par construction si le refus de (ii) est confirmé.

| Étape | Livrable | Prérequis | Qui décide | Durée | Critère de passage | Ce qu'un échec ferme |
|---|---|---|---|---|---|---|
| **G5 Gate 1 — horloge et calendrier, historique confirmatoire** (en tête de la piste L ; amendement 2026-10-21, P-G5-7) | Protocole `LP-G5-DRAFT-PREREGISTRATION.md` (`DRAFT_NOT_FROZEN`) et spec `g5_gate1_spec.json` (schéma `g5-spec/2`, brouillon ; sha256 en Annexe A du protocole, informatif). **Deux types décisifs** (K = 2, Holm local α = 0,10) : **T01** photo de funding 8 h quand la prime reconstituée **à la décision** vérifie \|P̂(h−5)\| ≥ 0,09 % (⟺ \|R\| ≥ 4 pb, payeur contractuel nommé), ordre passif post-only à c(h−5) du côté s, rempli selon le proxy de bougies `through`, tenu à travers la photo, sorti à h+15, coûts 13 pb maker/maker, funding réel ; **T05** ouverture cash US 09:30 New York, jours NYSE hors CPI / Employment Situation, continuation **preneur** du signe de [h ; h+5] sur [h+5 ; h+60], coûts 15,5 pb. Prix de référence = dernier prix ; contrôles à la **même minute de l'heure** (T01 hh:00, T05 hh:30), toutes les heures admissibles des jours d'événement (empreintes à ≥ 60 min des règlements, de la grille par défaut, des instants T05, des sessions en heure locale, des publications macro) ; unité = instant ; bootstrap par blocs d'un jour UTC (10 000) ; les 20 autres types de la red team sont rapportés, filtres, différés ou rejetés (§1.3 du protocole). Exécution unique sur `okx-xperp-1m-history-2026-03-30..2026-10-13` (`unread` : H2 a lu les USDT-SWAP des bases, pas les X-Perps) → `LP-G5-GATE1-RESULT.md` | P-G5-1 … P-G5-11 du protocole : échelle de la lecture confirmée par écrit : **D5** (≈ 58 300 requêtes, 25 instruments, facteur de cadence 0,4 retenu par défaut) ; verdict `fwd-okx-r1` ; fetch **scellé** du seul funding le jour du verdict (D_cov au registre) ; univers par métadonnées post-2026-10-31 ∩ liste D1, avec dérogation écrite (**D6**, accordée) ; calendriers gelés ; registre (créé le 2026-10-21) ; amendements de `GATES` (faits le 2026-10-21) ; S_éco ≥ 97,25 USD/an (D4 ; règle unique de `GATES` §1.2 amendé, à recopier dans les deux specs) ; gel avant la lecture de G2 ; spec Gate 2 gelé en même temps, vérifié par l'évaluateur du Gate 1 avant toute lecture (capacité `gate2_frozen_together`, à écrire : relecture review-b quant B1) ; code (B) i–xxii testé hors ligne (constat du 2026-10-21 sur le code du commit `67bc547c`, seconde passe `docs-b` : `g5_eval/3` accepte le brouillon du spec Gate 1, 84 tests hors réseau verts ; la vérification item par item de (B) i–xxii et la relecture CTO du code restent à faire, P-G5-11). **Hors du protocole G5 (écart signalé)** : **P-S3** du track S (`LP-S-CSFUND-K1C-AMENDMENT-DRAFT.md` §4.3), décision écrite avant le gel de G5 Gate 1 : (α, recommandée par le track S) BTC et ETH retirés de l'univers et des sorties de G5 Gate 1, ou (β) amendement de CS-FUND §0.4 — **sans objet depuis D8** (piste S close le 2026-10-21) : BTC et ETH restent, T05 = 105 (103 sous α, pour mémoire) ; l'ordre « P-S3 avant le fetch scellé du funding » est tenu, la liste `--inst-ids` = les 25 analysés | propriétaire (P-G5-1, 3, 4, 10, P-S3 ; D1 couvre la lecture) ; a1 (protocole) ; a2 (code (B)) ; a3 (amendements, registre) | rédaction faite ; code (B) : effort non estimé ici (a2) ; fetch après le gel ≈ 2,8 h (0,8 ×) à 5,6 h (0,4 ×) ; exécution < 1 jour | par type, **`G5_GATE1_PASS`** ⟺ C1 (D ≥ +10 pb au point **et** LB90(D) > 0), C2–C5, et pour T01 C6 (validation du classifieur contre R_h) et C7 (D > 0 aux seules photos de 16:00), **et** confirmation Holm locale K = 2 ; plancher 100 instants et 30 jours. Instants disponibles (calcul d'audit) : **T01 ≤ 211 × f_inst** (D_cov, fetch du funding le 2026-11-02), **T05 105** ; `NOT_INFORMATIVE` probable pour T01 ; T05 ne détecte pas +10 pb avec une puissance de 0,80 (δ₈₀ ≈ +15,6 à +28,3 pb sans la variance des contrôles, ≈ +16,5 à +32,7 pb avec elle : `GATES` §3.3 amendé) ; un PASS de T05 sans net absolu ≥ 10,6 pb est « PASS, non poursuivi » (écrits d'avance) ; un `PASS_PROGRAMME` de ce test **historique** n'ouvre aucune Phase 4 et ne compte pas pour `GATES` §1.3 (relecture review-b M1) | `G5_GATE1_FAIL(type)` ⇒ la **forme testée** est close (annotée « absence d'effet non démontrée » si UB90 ≥ +10 pb, « effet ≥ +10 pb exclu sous la forme testée » sinon), type non inscrit au Gate 2, au plus un filtre ; `NOT_INFORMATIVE` / `INCONCLUSIVE` ⇒ inscription **automatique** au Gate 2, statistiques **scellées** jusqu'au verdict du Gate 2 du type ; jeu passé `consumed_equivalent` dans tous les cas |
| **G5 Gate 2 — horloge et calendrier, prospectif** (amendement 2026-10-21, P-G5-7) | Spec `g5_gate2_spec.json` (`g5-spec/2`, brouillon du 2026-10-21, Annexe B du protocole), gelé **avec** le Gate 1 : mêmes types, classifieur, seuils, signes, fenêtres, contrôles, unité et critères, sur la capture continue ≥ 2026-11-02 (`okx-xperp-prospective-G5`). Exécution **simulée** sur carnet réel (`lp_sim`, règle `touch`, latence 250 ms) : T01 post-only au touch décidé à h−5 (jamais h−1), vivant jusqu'à h+1, un fill dans [h ; h+1) traverse la photo, sortie passive de h+15 à h+60 puis taker ; T05 preneur au touch opposé à h+5 plus glissement, sortie passive de h+60 à h+75 puis taker ; sélection adverse et demi-spread **mesurés** ; validation C6 sur le taux du canal `funding-rate`. Seuls les types inscrits par le résultat du Gate 1 sont lus | G5 Gate 1 rendu (inscriptions) ; capture continue (D1, P3) ; univers G2 §3 au gel ; **décisions P-G5-10** (**D7**, 2026-10-21) : (i) **fin de T05 refusée** — la fenêtre s'arrête au 2027-03-31 ⇒ ≈ 91 → 79 instants pour un T0 atteignable (du 2026-11-03 au 2026-11-20 ; « ≈ 92 » n'est que la borne de T0 = 2026-11-02, inatteignable), `NOT_INFORMATIVE` par construction, accepté par écrit ; les options « 2027-04-12 » et « fin par le calendrier », qui exigeaient une exception à `GATES` §1.3 et une extension de D1, sont refusées (la première n'atteignait d'ailleurs jamais 100 pour un T0 atteignable : le « ≈ 100 » du protocole est faux) ; (ii) **source d'indice à la minute** pour P̂ : « Nn » lu comme un refus, **confirmation explicite demandée** ; sans source — et `history-index-candles` n'a pas de fetcher pour la période du Gate 2 —, T01 est `INCONCLUSIVE` par construction ; réponses recopiées dans les blocs `owner_decision_pending` du spec au gel (échec fermé d'ici là) ; sémantique du canal `funding-rate` vérifiée sur la documentation et sur un **échantillon de schéma** seulement (aucun taux lu avant le gel, `GATES` §9.5 ; relecture review-b M3) | propriétaire (gel, P-G5-10) ; a2 (code (B) xxi : écrit en partie dans `g5_eval/3` ; dix écarts listés en Annexe B du protocole, dont la couverture des calendriers, bloquante ; le brouillon du spec Gate 2 reste refusé par le parseur — constat périmé : depuis 08:18 `g5_gate2` l'accepte en `DRAFT_NOT_FROZEN`, relecture review-b m7 ; code demandé par la relecture review-b : co-gel contrôlé par le Gate 1, contenu des calendriers, marqueurs `freeze_pending:`) ; a1 (choix du brouillon à écrire au §10 du corps) | T01 : 3 photos par jour sur ≤ 150 jours ⇒ ≈ 449 × f_inst instants (≥ 100 si f_inst ≥ 0,23 ; borne de T0 = 2026-11-02 : 446 → 395 × f_inst pour un T0 du 11-03 au 11-20) ; T05 : jours NYSE jusqu'au 2027-03-31 (D7) ; une seule exécution à la fin, aucune lecture intermédiaire ; prolongation pour trous : jours perdus par tous les instruments éligibles, ajoutés en jours civils, ≤ 14 jours, **nulle sous D7** (aucune au-delà du 2027-03-31) | par type, `G5_GATE2_PASS` ⟺ mêmes critères qu'au Gate 1 sur la mesure simulée (`sim_net_bp`) et Holm local ; plancher 100 instants et 30 jours ; lecture économique « poursuivi » / « non poursuivi » | `G5_GATE2_FAIL` ⇒ forme close pour cette architecture, au plus un **filtre** (quand ne pas poster) pour G1/G2/G4, sans test ; une **proposition écrite** de Phase 4 (démo) exige `G5_GATE2_PASS` **et** le `PASS_PROGRAMME` de `G5-Gate2-<type>` (immédiat ou Holm à la clôture) **et** « PASS, poursuivi » ; jamais un ordre ; sous D7, si le refus de (ii) est confirmé, aucun `G5_GATE2_PASS` n'est possible (écrit d'avance, `GATES` §2.3 amendé) |
| **P1 — La machine poste-t-elle ?** (diagnostic, zéro donnée de marché) | Table « déclaré / réel » : ordres postés depuis 1.1.7 (`order-journey*`), `proof_reason`/`rulesFailed` 1 m (`mtf-runner.log`), `rejected_by` de la certification Paper #132, **durée réelle du run** et instant de décision (champ `performance` de `/api/mtf/run`) — c'est la valeur **d** de la variante minute de G2 | journaux en champs texte ; aucun prix | propriétaire (ou agent habilité, décision 2) | heures | livrable publié ; `d` écrit avec sa source | **Bloquant pour G2** (P-G2-8, quant m14) : sans P1, d = 5 s reste une valeur de conception et le gel de G2 est refusé |
| **P2 — Décisions et GET du propriétaire** | (i) Décision écrite §9.4 sur l'échelle réelle (§5 d.1) ; (ii) GET lecture seule au compte (§5 d.3) ; (iii) décision sur la lecture d'`okx-xperp-1m-history` pour G5 Gate 1 et sur l'option `market-data-history` (§5 d.15) ; (iv) porte économique (§5 d.9) ; (v) consentement Gate 1 HL (§5 d.8) | lecture de `LP-DATA-DESK-2026-10-20.md` §2 (textes §1.8, §9.2, §9.4 recopiés intégralement, neuf questions §2.3) | propriétaire | — | phrases datées au registre du programme | Sans (i) : **pas de capture longue**, le track L s'arrête à P1/P2 ; sans (ii) : lecture portefeuille rapportée sous deux hypothèses de `posMode` ; sans (iv) : aucun verdict économique |
| **P3 — Capture de comptage 24–72 h** (`research_lp count`) | Table couverture × fréquence par canal × instrument pour `instType FUTURES` (`trades`, `books5`, `bbo-tbt`, `mark-price`, `funding-rate`, `open-interest` par `instId` ; `liquidation-orders`, `instruments`, `adl-warning`, `books-rpi` par `instType`), hôte servant les X-Perps (`--host eea` d'abord : `wss://wseea.okx.com/ws/v5/public`, forme documentée sans port ; puis `global`), reconnexions × souscriptions (portée du budget 480/h), **taux de compression** des segments, part RPI au touch (P-G2-9). **Aucune valeur de prix persistée** (établi : `handle_raw` retourne après `Counter.observe`, `TapeStore.append` lève en mode `counting`) | verdict `fwd-okx-r1` ; décision 1 ou lancement avec `--instruments-file` (le `count` par défaut fait un `GET /public/instruments?instType=FUTURES`, métadonnées — relecture CTO rec. 2) ; ≥ 2026-11-02 (plancher dans le code) | propriétaire (lancement, décision 7) ; a2/a4 (exécution) | 24–72 h | table publiée ; `books5` et `trades` servent les X-Perps de l'univers | `books5` ou `trades` muets pour `FUTURES` ⇒ **G2 `NOT_MEASURABLE`** ; aucun X-Perp dans `liquidation-orders` ⇒ **G4 `NOT_MEASURABLE_ON_OKX`** (G4 s'arrête au Gate 1) |
| **G2 — Capture de spread sur carnets larges (mesure de coût, 14 jours)** | Verdict `LP-G2-RESULT.md` : C1 **sélection adverse nette d'entrée** (mark-out 60 s − 2 pb + funding traversé ; demi-transaction, entrée de coût de G1/G4/G5) ; **C2 aller-retour `net_bp`** (sortie passive ≤ 60 min, reliquat taker, funding réel) — le seul PnL réalisable et le seul `net_pb` de la porte économique (quant B1 ; Part A : mark-out net −3,05…−3,49 pb mais aller-retour taker −8,1…−8,7 pb, `research-execution-tick-2026-10-12.md` l.60–70) ; C3 coût de file ; C4 robustesse (frais × 1,5, sans les 5 % meilleurs, médiane par paire, variante RPI « tous prints ») ; C5 calibration C0 (reproduire le signe de Part A sur S0 des X-Perps BTC/ETH et, s'il est capturé, le swap global) ; table « sélection adverse × tranche × paire (× heure si G5 gelé) » | P-G2-1…10 : licence, verdict fwd, P3, **code (B)** (§4.6), funding établi, registre, porte éco, **P1**, RPI relevé, G5 gelé | propriétaire (gel) ; a2/a3 (évaluateur, exécution unique) | T0 ≈ premier 00:00 UTC ≥ max(2026-11-02 ; fin P3 ; gel + 24 h) ; rodage T0 ; scoring T0+1 → T0+14 ; prolongation **trous de données** seulement (≤ 7 j) ; futilité au 7ᵉ jour (booléen seul) | `G2_PASS` : C1 **et** C2 > 0 avec LB90 > 0 sur ≥ 1 000 fills (variante minute, règle `queue` sur prints `source = 0`, cellule S≥6, poolé) et ≥ 5 paires à 1 000, C3–C5 ; strate porteuse nommée (« PASS, porté par S_k ») | `G2_FAIL` : la fourniture passive **inconditionnelle** à la minute ne paie pas ⇒ famille « capture de spread » **close** ; la table de coût reste obligatoire pour G1/G4/G5 (un FAIL n'arrête pas le programme). `NOT_INFORMATIVE`/`INCONCLUSIVE` : fenêtre consommée, nouveau pré-enregistrement requis |
| **G4 Gate 1 — retour post-cascade, Hyperliquid (développement, statistique seule)** | `LP-G4-GATE1-RESULT.md` : sur l'archive `node_fills_by_block` (objet `liquidation {liquidatedUser?, markPx, method}`), événement = **cascade relative à la paire** (p99,5 glissant **et** ≥ 20 × médiane non nulle 7 j **et** ≥ 0,5 % du notionnel 24 h, plancher de bruit 50 k USD), intensité `method = market` seul, t_e = **dernier fill liquidé** ; Z₁₅ = R₁₅/σ₁₅ ; contrôle continu (A et A² dans les déciles) **et** contrôle apparié au prix, co-primaires | décision 8 (consentement : **D3 accordé** le 2026-10-21 pour Hyperliquid) ; **amendement 2026-10-21 (P-G5-7)** : source du Gate 1 à trancher (`DEX-ALTERNATIVES-2026-10-21.md` §7 d.2 : Hyperliquid (D3), **dYdX v4 recommandé**, Lighter, Kraken Futures via Tardis, ou double Gate 1 pré-déclaré ; dYdX exige un consentement écrit, l'acceptation de données sans licence écrite, un échantillon de schéma et l'amendement de G4 §5.2) ; **tranché par D9** (2026-10-21) : dYdX v4 source principale, Hyperliquid en repli ; règle de combinaison gelée avant lecture (« les deux doivent passer » si deux sources sont lues ; repli déclenché par une condition de données écrite d'avance, jamais par un résultat : `GATES` §2.1 amendé) ; P-G4-2 (licence HL **non publiée**, plafond requester-pays en octets et USD), P-G4-3 **échantillon de schéma** (noms de champs, dates min/max, aucune valeur : date T_liq), P-G4-4 déclaration M9 (`LP-G4` §3.4), **P-G4-5 chargeur HL + évaluateur à écrire**, registre | propriétaire (8, licence, plafond) ; a4 (schéma) ; a2 (code) | schéma : jours ; code : M ; exécution unique | `G4_GATE1_PASS` : β_intensité > 0 LB90 > 0 **et** D₁₅ > 0 LB90 > 0 **et** Z₁₅ ≥ +0,25 LB90 > 0, Z₆₀ même signe, tient sans les 5 % plus intenses et dans les deux moitiés, n ≥ 100 instants | `FAIL` ⇒ G4 « **non poursuivi faute de preuve de développement** » (décision de ressources, pas une preuve d'absence sur OKX : moteur, profondeur, clientèle différents — quant M5) ; un Gate 2 sans Gate 1 reste possible sur décision écrite |
| **G4 Gate 2 — retour post-cascade, OKX X-Perps (prospectif, 90 jours)** | `LP-G4-GATE2-RESULT.md` : ordre passif post-only à la minute suivant la **clôture** de la fenêtre (pas t_e : look-ahead sinon), 100 USD, TTL 60 s, sortie passive ≤ 60 min, reliquat taker, **aucun stop** < 3 σ en lecture portefeuille ; coûts : maker/maker, stress spread × 3, funding réel et au plafond, liquidation au taux taker, `band_rejected` | `G4_GATE1_PASS` (ou consentement à sauter), P3 (X-Perps présents dans `liquidation-orders`), licence, verdict fwd, table G2 ou valeur de conception −3,5 pb/jambe, porte éco | propriétaire (gel) | T0 = gel ; fin T0 + 90 j ; futilité à T0 + 45 j ou au 50ᵉ instant (UB90 < +10 pb et point ≤ 0) ; prolongation trous ≤ 14 j | `G4_GATE2_PASS` : net ≥ +10 pb **et** net/σ₆₀ ≥ +0,25, LB90 > 0, ≥ 100 instants remplis ; frais × 1,5 ; sans les 5 % meilleurs ; mécanisme confirmé (β > 0 et D₁₅ > 0 sur OKX) ; funding au plafond > 0. **Effet détectable à n = 100 ≈ 0,24 σ** (≈ 40–75 pb si σ post-cascade ≫ 100 pb) : `NOT_INFORMATIVE` est l'issue **probable** sous 0,25 σ (G4 §7.2) | `FAIL`/`FAIL_FUTILITY` ⇒ famille « retour post-cascade » **close** pour cette architecture |
| **G1 — prime d'immédiateté en fournisseur, conditionnée au stress** (sur **consentement**, brouillon à écrire) | Échelle k σ₁₅ ∈ {1,5 ; 2 ; 2,5} sous/sur l'EMA 15 min, renouvelée à la minute, post-only, sortie passive EMA ou temps 60–120 min, aucun stop < 3 σ₁₅ ; condition **rv24 > médiane 30 j** (30 jours de capture nécessaires avant que la condition existe) ; **critère = stress − calme > 0 avec LB90 > 0 seul** ; contrôle « bande sans flux » ; deux lectures (n ≥ 600 fills ; portefeuille 500 USDT) | décision 5 (différence déclarée, famille « suffisamment explorée » : q3x attribue l'essentiel du gain à l'état de bande, r12 négatif hors échantillon) ; table G2 ; marge économique restante ; consentement donné **avant** les verdicts G2/G4/G5 (`GATES` §1.3) | propriétaire | ≈ 4 mois pour 600 fills en stress (ordre de grandeur r6 : ~5 fills/jour sur 29 paires) — **déborde** la clôture au 2027-03-31 sauf démarrage en décembre ou décision d'allongement | stress − calme > 0, LB90 > 0 ; frais × 1,5 et sélection adverse × 2 ; perte max par fill/jour pré-déclarées | FAIL sous cette forme **ferme la famille, mécanisme compris** |
| **G6 — réversion transversale intraday** | — | **suspendu** à une décision explicite (`H2-CLOSURE-DECISION.md` §4 ; décision 6) | propriétaire | — | — | sans décision, hors plan |
| **Phase 4 — exécuteur (démo)** | Proposition écrite seulement : `OkxProductProfile` + résolveur X-Perp (M), `LadderManager`/`ExitPolicy`/`RiskGovernor` (**L**), write kinds `lp_entry` (soumis à `stop_loss_required` **plus** `stop_distance_min`) / `lp_exit` (M), choix du langage et port vers la porte PHP (§2.10 : option (a) Python + port, aucun adaptateur OKX Python), contrat `lp_event_provider/1.0.0` haché, démo sur drapeaux du propriétaire | un `PASS_PROGRAMME` d'un test **prospectif** (G2-C2, G4-Gate2, G5-Gate2-*, G1 ; jamais G5-Gate1-* : `GATES` §2.3 amendé) **et** « poursuivi » (porte éco) **et** décision écrite ; X-Perps en démo : **UNKNOWN** | propriétaire | « plusieurs semaines » (CTO §4.3) | — | **jamais live** dans ce programme (`OkxRestEndpointGuard::LIVE_URIS` inchangé) |

**Amendement 2026-10-21 (P-G5-7) — état des autres pistes au 2026-10-21, pour la lecture de ce tableau.**
- **Piste S (§2.1).** D2 (« spot admin en marge ») ouvre K1(c) : S-O1 = oui au niveau de l'interface. L'amendement de CS-FUND est
  rédigé : `LP-S-CSFUND-K1C-AMENDMENT-DRAFT.md` (`DRAFT_NOT_FROZEN`, révisé le 2026-10-21 après `track-s/review-quant.md` et
  `track-s/review-risk.md`). Son résultat, écrit d'avance (calcul d'audit sur des champs de règle ; « Réponse en dix lignes »,
  points 2–6) : sous ses défauts prudents (lectures des clauses OEM 30 % / 100 %, choc de décote K3), K1(c) n'a plus d'avantage de
  capital sur K1(a) ; à F = I, l'excédent sur le SOFR va de −4,67 à +31,28 USD/an contre S_éco = 98,60 USD/an dans sa lecture de
  `GATES` §1.1 ⇒ **la piste S n'est poursuivable à 500 USDT sous aucune lecture au cas de référence** (il faudrait un funding moyen de
  2,27 × I au mieux, 7,60 × I au défaut ; au défaut, même C2 échoue à F = I). **Décision S-O5 attendue** du propriétaire : geler
  CS-FUND amendé (le texte reste gelable sur choix écrit) ou classer sans suite (l'issue cohérente sous ces défauts, selon
  l'amendement). P-S3 (BTC et ETH dans l'univers de G5 Gate 1) est à décider avant l'un ou l'autre gel (ligne G5 Gate 1). **Mise à jour (D8, 2026-10-21)** : la piste S est **classée sans suite** ; P-S3 n'a plus d'objet ; BTC et ETH restent dans G5 ; aucun enregistreur de funding n'est lancé pour la piste S ; la rouvrir serait une décision nouvelle.
- **DEX** (`DEX-ALTERNATIVES-2026-10-21.md`, demande du propriétaire du 2026-10-21 : « Trouve un autre dex alors au lieu de
  hyperliquid »). Aucun des 25 DEX de perpétuels examinés ne passe M1 : l'exécution reste sur OKX Europe X-Perps (Kraken EEA puis
  Coinbase EEA en alternatives conditionnelles ; nouveaux candidats centralisés à vérifier sans ordre ni clé). Pour les
  **données** de G4 Gate 1, dYdX v4 est recommandé (trades publics typés `LIQUIDATED` / `DELEVERAGED`, gratuits ; rétention de
  l'indexeur et licence UNKNOWN ; aucun carnet historique gratuit) ; Hyperliquid reste consenti (D3). **Décisions attendues** :
  DEX §7 d.1–7. Au registre : `hyperliquid-node-fills` et `dydx-v4-indexer`, candidats `unread`. **Mise à jour (D9, 2026-10-21)** : dYdX v4 est la source principale du Gate 1 de G4 et Hyperliquid le repli ; données de l'indexeur sans licence écrite acceptées pour une recherche personnelle non redistribuée ; échantillon de schéma pris le 2026-10-21 (noms de champs seulement, selon D9) ; amendement de G4 §5.2 avant tout gel.
- **Registre** : `tradingV3-private/research/20261020T1430Z-lp-programme/REGISTRY.md`, créé le 2026-10-21 (P-G5-6).

**Règle d'arrêt du programme** (`GATES` §1.3) : si, après les verdicts de G2, G4 et G5, aucune famille n'a simultanément un
`PASS_PROGRAMME` (d'un test prospectif, `GATES` §1.3 amendé le 2026-10-21) et la condition « poursuivi », le programme est **clos par un rapport** ; G1 n'est alors lancé que si le
consentement a été donné avant ces verdicts et si la table de coût G2 laisse une marge. Durée maximale : fin du scoring de la
dernière fenêtre ≤ **2027-03-31**.

---

## 3. Calendrier (du verdict fwd-okx-r1 aux premiers verdicts G2 et G4)

Dates **indicatives** ; chaque ligne suppose la décision qui la précède. Les dates de verdict sont des bornes de lecture, pas des
promesses de résultat. `fwd-okx-r1` est un test sur OKX USDT-SWAP (inaccessible depuis la France) ; son verdict ne conditionne
rien scientifiquement ici, mais la règle du propriétaire est « rien avant » (théorie §6.11 ; `RESEARCH-CAMPAIGN-STATUS.md` l.18,
l.121 : verdict prévu après W2, le 31 octobre).

| Quand | Track | Quoi | Qui | Dépend de |
|---|---|---|---|---|
| **Dès maintenant → 2026-10-31** (sans donnée de marché) | L | **P1** diagnostic de la machine (journaux, champs texte) ; valeur de `d` | propriétaire / agent habilité | décision 2 |
| idem | L + S | **P2** : lecture de `LP-DATA-DESK` §2 ; décisions **1** (§9.4, échelle réelle, hôte global), **3** (GET lecture seule), **8** (Gate 1 HL, licence, plafond), **9** (S_éco, coût fixe, durée), **13** (track S), **14** (agrégats), **15** (1 m X-Perps) | propriétaire | — |
| idem | S | **S1** vérification du compte (10 min) ; **S2** décision CS-FUND et rédaction de l'amendement A-S1…A-S4 si « amendé » | propriétaire ; a3 | S-O1…O7 |
| idem | L | **Code (B)** avant gel : tranche 6 pb, `grid_offset_s`, stratification, `trades.source` + nombre d'ordres `books5` + règle `queue` RPI, `bbo-tbt`, `books-rpi` au comptage, `queue_unknown`, `size_visible`, repli REST funding, `band_rejected`, `posMode` ×2, test « rejeu = direct » ; **chargeur HL + évaluateur G4** (P-G4-5) ; **brouillon G5** (deux portes) ; **`REGISTRY.md`** (absent au 2026-10-21) | a2 / a3 | aucune donnée ; tests synthétiques |
| idem | L + S | Corrections documentaires : rapport théorie l.143/353 (« lu par H2 » — erreur de fait, quant B3) et l.400 (`economic-calendar` « filtre gratuit » — VIP1+, quant M8), toujours présentes dans la copie `lp-programme` au 2026-10-21 ; `FRANCE-EXCHANGE-ELIGIBILITY.md` §2 (structurel §6.2 d.11) | auteurs / propriétaire | décision 16 |
| **2026-10-31** | — | Verdict **`fwd-okx-r1`** (hors programme) | orchestrateur / propriétaire | — |
| **2026-11-01** | L | Snapshot `public/instruments?instType=FUTURES` (métadonnées, haché) : filtres G2 §3.2 figés **avant** lecture de P3 ; relevé `rpi`, `ctVal` (0,0001 vs 0,01 BTC tranché), `ctType` (UNKNOWN → lu) ; re-figer les métadonnées datées dans la quarantaine (structurel §6.2 d.10) | a3 | décision 1 ou 7 |
| **2026-11-02 00:00 UTC** | L | **`QUARANTINE_FLOOR`** : premier instant admis par le code. **P3 `count`** 24–72 h, `--host eea` puis `global` → table couverture × fréquence, compression, reconnexions, part RPI | propriétaire (lancement) ; a2/a4 | décision 7 (+1) |
| ≈ 2026-11-04/05 | L | Fin de P3 ; décision « G2 mesurable ? G4 mesurable sur OKX ? » ; capture **valeurs** démarre (dossier distinct du comptage ; sert d'historique `lookback` à G4, jamais scoré avant gel) | a4 (table) ; propriétaire | P3 |
| ≈ 2026-11-04 | L | **Gel G2** (sha256, publication avant T0 ; digest `cli.code_identity()` **après** (B)) ; registre `okx-xperp-prospective-G2` | propriétaire ; a3 | P-G2-1…10 |
| **≈ 2026-11-05/06 00:00 UTC** | L | **T0 G2** : rodage (classification liquidité ≥ 1 M USD/j, jour par jour) | — | gel + 24 h |
| ≈ 2026-11-06/07 → 2026-11-20 | L | **Scoring G2** 14 jours UTC ; futilité au 7ᵉ jour scoré (≈ 11-13 : `UB90 < −2 pb` et ≥ 500 fills ⇒ `FAIL_FUTILITY`, booléen seul, l'auteur du verdict ne voit rien) | script | — |
| novembre (avant la lecture de G2) | L | **Brouillon G5 gelé** puis **G5 Gate 1 historique** exécuté une fois sur les 1 m X-Perps `unread` (≈ 600 instants/type pour les photos) → `LP-G5-GATE1-RESULT.md` | a3 ; propriétaire (décision 15) | P-G2-10 |
| novembre (en parallèle) | L | **G4 Gate 1 HL** : échantillon de schéma (P-G4-3, aucune valeur) → fenêtre [T_liq ; 2026-09-30] et sous-fenêtre **par calendrier** écrites → gel Gate 1 → téléchargement sous plafond → exécution unique | a4 ; a2 ; propriétaire | décision 8 ; P-G4-1…6 ; **code P-G4-5** |
| **≈ 2026-11-22** (≤ ≈ 11-29 avec prolongation trous) | L | **Verdict G2** (`LP-G2-RESULT.md`) : PASS / FAIL / NOT_INFORMATIVE / INCONCLUSIVE + lecture économique sous les deux hypothèses de `posMode` ; table de coût publiée (entrée de G1/G4/G5) | a3 | — |
| **novembre → début décembre** | L | **Verdict G4 Gate 1** (`LP-G4-GATE1-RESULT.md`) — date réelle fixée par le plafond de téléchargement et le code P-G4-5 ; `GATES` §8 : « en novembre si consenti » | a3 | — |
| ≈ 2026-12-01 → 12-10 | L | **Gel G4 Gate 2** (si `GATE1_PASS` ou décision de sauter) ; **gel G5 Gate 2** ; T0 des deux | propriétaire | P-G4-7…11 |
| ≈ 2027-01-15 → 01-25 | L | Futilité G4 Gate 2 (T0 + 45 j ou 50ᵉ instant) ; G5 Gate 2 : les types funding atteignent ≈ 100 instants | script | — |
| **≈ 2027-03-05 → 03-15** | L | **Verdict G4 Gate 2** (T0 + 90 j, prolongation trous ≤ 14 j) ; G5 Gate 2 types quotidiens (≈ 100 jours) | a3 | — |
| **≤ 2027-03-31** | L | **Clôture du programme** : Holm sur la famille (m_max = 5), lecture « poursuivi / non poursuivi », rapport ; G1 seulement si consenti avant et marge économique | propriétaire | `GATES` §1.3 |
| 2026-11-02 → ≈ 2026-12-28 | S | Rodage technique de CS-FUND (8 semaines **dans** la fenêtre, non lues) si gelé | enregistreur | S2 |
| ≈ 2027-05 | S | Futilité CS-FUND (mois 6) | script | — |
| ≈ 2027-11 | S | **Verdict CS-FUND** (mois 12) → S4 | propriétaire | — |

Deux tensions de calendrier à trancher par le propriétaire : (i) **G1** (≈ 4 mois pour 600 fills en stress) ne tient pas dans
« ≤ 2027-03-31 » sauf consentement et démarrage en décembre, ou allongement écrit ; (ii) **G5 Gate 2** pour les types
quotidiens (≈ 100 jours) exige un T0 ≤ ≈ 2026-12-20.

---

## 4. Ce qui a été construit

Tout ce qui suit est **écrit, non gelé, non lancé** : aucune capture, aucun ordre, aucun drapeau. Empreintes sha256 (16 premiers
hexadécimaux, calculées le 2026-10-21 ; **pas des gels**) : G2 `8a6a6234be7a0f8b`, G4 `dd9223e6dda702a3`, GATES
`8b88394728284ba1`, DESK `fe4e7e5b90b0d328`, architecture `93a40439d44cfb32`.

### 4.1 Architecture cible (`docs/handbook/technical/lp-executor-architecture.md`, 831 lignes)

Proposition, pas une implémentation. Diagnostic ligne à ligne de pourquoi la boucle minute ne peut pas porter le mécanisme
(§1.2 : décision à hh:mm:00 + run + Messenger + REST ; un ordre par symbole ; `order_mode: 1` = GTC **sans** post-only,
`ExchangeExecutionService.php:435` ; watchers REST 5 s, annulation 60 + 10 s **sans re-post** ; stop obligatoire et serré ;
`instType=SWAP` codé en dur **54 fois dans 22 fichiers** ; USDT supposé ; résolveur qui lève sur `_UM_XPERP`). Architecture
d'un **processus événementiel séparé** piloté par WebSocket (collecteur → détecteurs purs → échelle post-only renouvelée →
sortie passive → gouverneur de risque → port d'exécution interchangeable), §2 ; `InstrumentSpec` X-Perp et `ProductKind`
(`usdt_swap | xperp | spot`), §3 ; table « existe / manque / à adapter » fichier par fichier avec effort S/M/L, §4 ; séquence
Phase 0–4 **mesure avant exécuteur**, §5 ; invariants de sécurité repris et durcis, §6. Corrections de relecture appliquées :
`lp_entry` **reste soumis à `stop_loss_required`** et ajoute `stop_distance_min` (CTO M5 : la règle 12 dit « pas de stop
**serré** », pas « pas de stop ») ; §2.10 langage de l'exécuteur et port vers la porte PHP (CTO M6 : option (c) maintenant,
(a) si verdict) ; `LadderManager` porté à **L**, budget disque et supervision au coût fixe (CTO §4.3).

### 4.2 Collecteur, simulateur, détecteurs (`python-orchestrator/scripts/research_lp/`, 2 904 lignes ; tests 1 095 lignes)

| Module | Rôle (établi, code lu) | État |
|---|---|---|
| `xperp_capture.py` (818 l.) | Collecteur public OKX `instType FUTURES` : canaux `trades`, `books5`, `liquidation-orders` (par `instType`), `mark-price`, `funding-rate`, `instruments` ; hôtes `WS_PUBLIC_EEA = "wss://wseea.okx.com/ws/v5/public"` (forme documentée sans port, CTO m1) et `WS_PUBLIC_GLOBAL = "wss://ws.okx.com:8443/ws/v5/public"` ; mode **comptage** (aucune valeur décodée ni écrite) et mode **valeurs** ; `QUARANTINE_FLOOR = "2026-11-02T00:00:00Z"` (l.87) comme **plancher** dans tous les modes (exit 2 `not_before_below_floor`, CTO M1) ; SIGTERM/SIGINT propres, `close()` idempotent, `DEFAULT_CHECKPOINT_S = {values: 90, counting: 600}` (CTO M4) ; verrou `flock` ; `health.log` ; `touch STOP` ; `--liq-selected-only` | fait ; **reste** : `trades.source`, nombre d'ordres `books5`, `bbo-tbt`, `books-rpi` au comptage, repli REST funding |
| `tape.py` (611 l.) | Magasin npz par instrument-jour en **segments append-only** (`segNNNN` par checkpoint ; rien d'antérieur n'est jamais réécrit — par construction, défaut fwd-okx du 2026-10-13 impossible), fusion/dédoublonnage/sha256 à la finalisation (≤ 1/min ; refinalisation ≤ 1/h), `tmp → fsync → rename → fsync répertoire`, manifeste non autoritaire, clé `books5` = `(ts_us, seq_id, bid_px[0], ask_px[0])` | fait ; **reste** : layouts `source`, nombre d'ordres, `bbo` |
| `lp_sim.py` (701 l.) | Règles de fill `touch` / `through` / `queue` (file devant = taille affichée, bornée, partiels ; optimisme `queue_ahead → 0` hors affichage documenté), post-only annulé au croisement à l'activation, latence 250 ms, mark-outs m₀/60/300/900 s, sortie passive au touch opposé puis taker à T max (3 600 s), frais 2/5 pb, funding sur règlements **traversés** (taux de la tape ou grille), deux lectures (illimité ; `portfolio_admit` 500 USDT, 1×, `max_live 4`, 100 USD) avec `refused_capacity`, `math.fsum`, LB90 par **bootstrap par blocs** 10 min (1 000 rééchantillons, graine 20261102) ; `event_decisions` : décision à `arm_ts_us` + `ttl_us` par intention (CTO M2) | fait ; **reste** : `SPREAD_TRANCHES_BP` coupe à 1/2/4/8 (l.65) → **6 pb** requis, `grid_offset_s`, stratification, `queue` sur `source = 0`, `queue_unknown`, `size_visible`, `band_rejected`, `posMode` ×2, test « rejeu = direct » |
| `events.py` (325 l.) | Grappes de liquidations (bins 5 min, seuil absolu **et** percentile trailing sans look-ahead, `min_history_bins 288`, `side == 0` dans le total jamais dans le signé — CTO m7 ; `arm_ts_us = end_ts_us = ts_us`) ; excursions k·σ₁₅ vs EMA 15 min, σ **décalée d'une minute**, conditionnées à rv24 > médiane 30 j (−1 inconnu) ; fenêtres d'horloge 00/08/16 UTC et ouverture US 13:30/14:30 par règle DST calendaire, armement h−5/h−2 → h+1 ; `funding_times_from_tape` par instrument (CTO m5) | fait ; **reste** pour G4 : seuil **relatif** (p99,5 ∧ k × médiane ∧ x % 24 h), `method = market`, t_e = dernier fill, cool-down 60 min, cluster horaire, évaluateur du contrôle continu et de l'appariement au prix (P-G4-5) ; chargeur archive HL |
| `cli.py` (426 l.) | `capture` / `count` / `simulate` / `events` / `verify` (`allow_abbrev=False`) ; `simulate`/`events` refusent les dates < plancher sans `--allow-before-reason TEXT` (recopié dans `meta.guard` + `WARN event=guard_lifted`) et les tapes de comptage ; `ctVal` **obligatoire** (`SystemExit "ct_val_missing"`, `ct_val_source`, CTO M3) ; `code_identity()` = sha256 par module dans chaque résumé | fait |

**Tests** : `tests/test_research_lp_{capture,events,sim}.py` — **40 passed**, rejoués par le rédacteur le 2026-10-21 depuis
`python-orchestrator/` avec le runtime `tradingV3-private/research/20261013T100000Z-forward-okx-runtime/bin/python` (Python
3.12.3, numpy 2.5.4, pytest 9.1.1 selon `a5-corrections-cto/NOTES.md` §1), `--noconftest -p no:cacheprovider` (le `conftest.py`
du projet importe `httpx`, absent de ce runtime) ; aucun trafic hors du faux serveur `websockets` sur 127.0.0.1 ; aucune date de
la quarantaine utilisée comme donnée (2026-10-15/20 n'apparaissent que comme arguments **refusés**). Digests `cli.code_identity()`
constatés : `__init__ 225e8273…`, `cli b82dcd22…`, `events 53252f3f…`, `lp_sim a75eefd2…`, `tape 98267c53…`,
`xperp_capture b1a09f70…` — identiques à `a5-corrections-cto/NOTES.md` §5 ; **le digest inscrit au gel sera celui recalculé
après (B)**. CI (`.github/workflows/python-orchestrator.yml`) : Python **3.11**, `requirements-dev.txt` sans `websockets`/`orjson`
⇒ les tests du faux serveur se skippent par `importorskip` ; exécution sous un interpréteur 3.11 réel : **UNKNOWN**
(syntaxe validée par `ast.parse(feature_version=(3, 11))`).

### 4.3 Protocoles (`docs/handbook/research/`, tous `DRAFT_NOT_FROZEN`)

- **`LP-PROGRAMME-GATES.md`** (475 l.) : porte économique (formule aller-retour, S_éco proposé, « poursuivi » ⇔ point ≥ S_éco et
  UB90 ≥ 2 S_éco ou LB90 > 0, coût fixe déclaré, règle d'arrêt ≤ 2027-03-31) ; Holm sur la famille G2-C2 / G4-Gate2 / G5-Gate1 /
  G5-Gate2 / G1 (m_max = 5, α 0,10 unilatéral, `INCONCLUSIVE` à p = 1, screen post-lecture compté) ; puissance (n ≈ ((z_α+z_β)σ/δ)²
  × DE, planchers 1 000 fills / 100 instants / 600 fills, effet détectable déclaré en σ) ; deux lectures ; funding et marge gelée
  dans tous les nets (écrémage RPI ajouté, bandes de prix, liquidation au taux taker) ; registre à sept statuts (`okx-xperp-1m-history`
  = **`unread`**) ; confirmation = `okx-xperp-prospective` ≥ 2026-11-02 ; séquence ; huit interdits ; **track S** §10 ; table des
  **15 décisions** §11 ; traitement de la revue §12.
- **`LP-G2-DRAFT-PREREGISTRATION.md`** (485 l.) : hypothèse en trois volets (C1 sélection adverse nette, **C2 aller-retour**, C3 coût
  de file), **10 prérequis bloquants** P-G2-1…10 avec responsable (dont P-G2-4 (A) fait / (B) reste à coder), univers par
  métadonnées au gel (la liste des 29 du 2026-10-14 est en **quarantaine**), liquidité point-dans-le-temps, deux variantes
  (recoteur 5 s / **minute** TTL 60 s), strates coupées à 6 et 8 pb (gardes de la plateforme), C0 = signe de Part A, fenêtre
  14 jours, futilité à sens unique, ce qu'un verdict autorise (PASS ⇒ table de coût + proposition de révision des gardes, rien
  d'autre), procédure de gel §11.
- **`LP-G4-DRAFT-PREREGISTRATION.md`** (444 l.) : deux portes (Gate 1 HL développement, Gate 2 OKX prospectif), 11 prérequis,
  événement relatif à la paire, t_e = dernier fill liquidé, déclaration de transférabilité M9 copiée du desk (§3.4), contrôles
  co-primaires (continu avec A, A² ; apparié au prix), effet détectable en σ, `NOT_INFORMATIVE` issue probable déclarée, deux gels.
- **Absents au 2026-10-21** : brouillon **G5** (deux portes ; exigé **avant** la lecture de G2 par P-G2-10), brouillon **G1**
  (après consentement), **`REGISTRY.md`** du programme (`GATES` §6.2 le nomme ; non créé), amendement **CS-FUND** (track S).

### 4.4 Desk de données et de conformité (`docs/handbook/research/LP-DATA-DESK-2026-10-20.md`, 554 l.)

Faits documentaires hachés (31 pages HTML/MD dans `a4-data-desk/raw/` + `SHA256SUMS`, textes dans `txt/`, outil `html2txt.py`),
lus le 2026-10-20 : hôtes global/EEE (`wss://wseea.okx.com/ws/v5/public` sans port ; obligation régionale « EU users … Use
eea.okx.com ») ; canaux publics et cadences (`books5` 100 ms, `bbo-tbt` 10 ms ouvert à tous paliers, `mark-price` 200 ms/10 s,
`funding-rate` 30–90 s avec `instType FUTURES = X-Perps futures`, `open-interest` 3 s, `liquidation-orders` par `instType` avec
« doesn't represent the total », `adl-warning`, `economic-calendar` **VIP1+**) ; limites (3 conn/s/IP, 480 (un)subscribe/h,
64 Ko, ≈ 203 souscriptions à l'échelle réelle) ; historiques REST (funding « up to three months », `history-candles` « recent
years », `market-data-history` fichiers T+2/T+3 **docs globale seule**) ; **API Agreement EEE** « Last Updated: 28 July 2026 »
§1.8, §9.1–9.5, §9.7, §8.1(c), §8.2(b) **recopiés mot pour mot** (§2.1) et neuf questions au propriétaire (§2.3) ; Hyperliquid
(buckets, format `WsFill.liquidation`, requester-pays, licence **UNKNOWN**) ; mécanique de liquidation OKX vs HL et déclaration M9
(§4) ; marge des ordres au repos et `posMode` (§5 : règle X-Perps **UNKNOWN**) ; implications P3/G2/G4/G5/G1 (§6) ; UNKNOWN
consolidés (§7).

### 4.5 Relectures et leur traitement

| Relecture | Verdict | Constats | Traitement (vérifié dans les textes et le code le 2026-10-21) |
|---|---|---|---|
| `review/quant.md` | `NEEDS_CHANGES` | **5 bloquants** : B1 critère G2 = demi-transaction ; B2 événement G4 ≠ cascade, contrôle n'isole pas ; B3 1 m X-Perps faussement `consumed_equivalent` ; B4 licence demandée sur une mauvaise durée/hôte ; B5 prints RPI ⇒ biais vers le PASS. 14 majeurs, 14 mineurs | **Tous appliqués** dans G2, G4, GATES, desk, architecture (passe `a5-revision`, sections « Traitement de la revue » de chaque fichier ; refus motivés « Réponse à la revue » : écrémage RPI dans la mesure et non ajouté, α = 0,10 conservé, plafond N = 40 conditionné à la licence). Deux corrections **signalées** au rapport théorie (l.143/353, l.400), **non appliquées** à ce jour |
| `review/cto.md` | `NEEDS_CHANGES` | 0 bloquant ; **M1–M4** code (plancher not-before, `arm_ts_us`, `ctVal`, SIGTERM/checkpoint), **M5–M6** architecture (`lp_entry` exempt de stop refusé ; PHP/Python « rejeu = direct »), m1–m12 | M1–M4, m1–m12 **appliqués au code** (passe `a5-corrections-cto`, 31 → 40 tests ; écarts assumés : m3 trace dans `meta.guard` et stderr plutôt que `health.log`, m2 sans segment `late` nommé, m6 documenté seulement) ; M5, M6, §4.3 **appliqués à l'architecture** |

### 4.6 Ce qui reste à construire avant tout gel (aucune donnée nécessaire)

1. **Code (B)** de `LP-G2` P-G2-4 : (i) tranche 6 pb ; (ii) `grid_offset_s` ; (iii) stratification tranche × heure × instrument ;
   (iv) test « rejeu = direct » ; (v) RPI : `trades.source`, nombre d'ordres `books5`, `queue` sur `source = 0`, `rpi` dans
   `instruments-futures.json` ; (vi) `bbo-tbt` ; (xii) `queue_unknown` ; (xiii) `size_visible` et éligibilité instrument-jour D−1 ;
   (xiv) repli REST `funding-rate-history` ; (xv) `band_rejected` ; (xvi) lecture portefeuille sous deux `posMode`. Tests dédiés ;
   digest recalculé. Effort S–M (architecture §4.4).
2. **G4** : chargeur archive HL → layouts `tape`, détecteur relatif, évaluateur continu + apparié, σ₁₅ locale (P-G4-5, effort M) ;
   script d'échantillon de schéma S3 (P-G4-3, noms de champs seuls).
3. **Brouillon G5** (deux portes, signes et fenêtres depuis la théorie §3.3 G5), gelé avant la lecture de G2.
4. **Brouillon G1** — seulement après la décision 5.
5. **`REGISTRY.md`** du programme et lignes `okx-xperp-prospective-G2/G4/G5/G1`, `hl-fills-G4-gate1`,
   `okx-xperp-1m-history-2026-03-30..2026-10-13` (`unread`), `okx-xperp-selection-T0-1`, `okx-xperp-funding-trackS`.
6. **Amendement CS-FUND** (A-S1…A-S4 + dénouement forcé) — track S, si « amendé ».
7. **Corrections documentaires** du rapport théorie (l.143/353, l.400) et de `FRANCE-EXCHANGE-ELIGIBILITY.md` §2 — par leurs auteurs.

---

## 5. Décisions attendues du propriétaire (numérotées)

Numérotation alignée sur `LP-PROGRAMME-GATES.md` §11 (1–15) ; la 16 regroupe les actions documentaires. **Pour tenir le
calendrier du §3, les décisions 1, 2, 3, 7, 8, 9, 13, 15 sont attendues avant le 2026-11-02** ; 5, 6, 12 peuvent attendre le
verdict G2 (mais 5 doit précéder les verdicts G2/G4/G5 pour que G1 reste lançable, `GATES` §1.3).

| # | Décision | Ce qu'elle bloque | Renvoi |
|---|---|---|---|
| **1** | **Licence OKX API Agreement §9.4** (EEE, « Last Updated: 28 July 2026 ») décidée sur l'**échelle réelle** : **une capture publique continue du 2026-11-02 au plus tard le 2027-03-31** (P3, G2, G4 Gate 2, G5 Gate 2), ≤ 40 X-Perps crypto + 2 swaps globaux (`BTC-USDT-SWAP`, `ETH-USDT-SWAP`), canaux `trades`/`books5`/`bbo-tbt`/`mark-price`/`funding-rate` par instrument et `liquidation-orders`/`instruments`/`books-rpi` par `instType`, cadences documentées (`books5` ≤ 10/s, `bbo-tbt` ≤ 100/s), une connexion par hôte, stockage `tradingV3-private` seulement, travail d'agents (LLM) dessus ; **question jointe** : hôte global `ws.okx.com` depuis un compte EEE acceptable ? (sinon C0 sur les X-Perps BTC/ETH seuls). Le texte dit « personal, non-commercial trading and account management », « reasonably necessary for your personal trading use » ; il ne nomme ni la recherche ni le stockage (desk §2.2). Tout allongement, instrument, canal ou hôte ajouté = nouvelle décision | P3, G2, G4 Gate 2, G5 Gate 2, G1 | théorie §6.2 ; `LP-G2` P-G2-1 ; desk §2.3 Q1–Q2, Q9 |
| **2** | **P1 autorisé** : diagnostic de la machine sur journaux (champs texte, zéro prix) ; valeur de `d` | gel de G2 (P-G2-8 **bloquant**) | théorie §6.1 |
| **3** | **GET en lecture seule au compte X-Perps** (clé **Read** seule) : `account/config` (`posMode`, `acctLv`, `perm`, `level`), `account/instruments?instType=FUTURES` (levier, paliers), `account/balance` et `account/max-avail-size` (règle de gel de marge des ordres au repos), devise USD-M du sous-compte, liste des actifs de Selected Margin et décotes (**spot BTC/ETH ?** = S-O1), `account/trade-fee` (barème API), X-Perps en démo | lecture portefeuille (sinon deux hypothèses de `posMode`) ; track S S-O1…O4 ; Phase 4(d) | théorie §6.3 ; desk §5.2 ; `H1-XPERP-MARKET-ACCESS.md` l.85–86 |
| **4** | **Vocabulaire et objectif** : le standing objective « scalping / micro-scalping » devient-il « fourniture de liquidité événementielle à la minute », ou la question est-elle close ? | textes du programme | théorie §6.4 ; `GATES` §0 |
| **5** | **Consentement ou refus G1** (famille « suffisamment explorée »), portant sur la différence déclarée : ordres au repos avant le touch, post-only, sans stop serré, critère stress − calme seul ; en sachant que q3x attribue l'essentiel du gain à l'état de bande et que r12 est négatif hors échantillon ; **avant** les verdicts G2/G4/G5 | gel de G1 | théorie §6.5 ; `GATES` §1.3 |
| **6** | **G6** : hypothèse nouvelle autorisée sur données non lues par H2 (ou prospectif pur), ou refus (`H2-CLOSURE-DECISION.md` §4) | existence de G6 ; m_max | théorie §6.6 |
| **7** | **P3 autorisé** : comptage 24–72 h ≥ 2026-11-02 ; le `count` par défaut fait un `GET /public/instruments?instType=FUTURES` (métadonnées) — accepté, ou lancement avec `--instruments-file` | G2, G4 Gate 2, G5 | théorie §6.7 ; CTO rec. 2 |
| **8** | **Gate 1 historique G4 sur l'archive Hyperliquid** : consentement (donnée de **développement**, HL « non retenu » à M1) ; licence HL **non publiée** (terms en 403 / JS) — l'absence suffit-elle pour une recherche personnelle non redistribuée ? ; **plafond requester-pays** (octets, USD) ; fenêtre [T_liq ; 2026-09-30], sous-fenêtre par **calendrier** | G4 Gate 1 | théorie §6.8 ; `LP-G4` P-G4-1/2 |
| **9** | **Porte économique et règle d'arrêt** : S_éco (proposition a3 : max(5 × SOFR du jour du gel × 500 ≈ 97 USD/an ; coût fixe annuel déclaré)) ; coût fixe déclaré (heures, requester-pays, disque ≈ 1 To brut / compression UNKNOWN, supervision, `verify`) ; durée maximale ≤ 2027-03-31 ; m_max = 5 et α = 0,10 confirmés. Jamais abaissé après un résultat | tout verdict économique ; Holm | théorie §6.9 ; `GATES` §1–2 |
| **10** | **Corrections de configuration indépendantes** (post-only, frais internes 5/5 → barème réel, levier ×20 vs plafond 10×, univers USDT-swaps vs X-Perps, gardes de spread) — différées ou non, en sachant qu'elles ne rendent pas le profil rentable | rien dans le programme de mesures | théorie §6.10 |
| **11** | **Calendrier confirmé** : rien avant le verdict `fwd-okx-r1` ; confirmation ≥ 2026-11-02 ; W0–W2 et fenêtres consommées jamais réutilisées ; tensions G1 / G5 Gate 2 (§3) tranchées | tout | théorie §6.11 |
| **12** | **Hypothèses hors scalp** (M2 imbalance d'ouverture → 4–12 h ; G7 temps-volume ; M3 délistings) : dans ce programme, un autre, ou nulle part | périmètre | théorie §6.12 |
| **13** | **Track S** : S-O1…S-O8 faits ; CS-FUND **tel quel / amendé (A-S1…A-S4) / sans suite** ; Gate 1 rétrospectif « faits de règle seulement » consenti ou refusé ; **conseil fiscal** (150 ter vs 150 VH bis, PFU 31,4 % ?) ; **devise de compte** (EUR ou USD ; si EUR, acter que 500 USDC = exposition de change non couverte de 100 %) ; limites de risque de la structure couverte (perte max, distance d'appel, dénouement forcé) | track S | structurel §6.2 d.1, 3, 4, 5, 6, 7 ; `GATES` §10 |
| **14** | **Visibilité du dépôt et agrégats** : `pivox/tradingV3` reçoit-il des agrégats chiffrés (mark-outs moyens, taux de fill) ou seulement protocoles et verdicts qualitatifs ? (« display », §9.4(a)). Défaut tant que non tranché : agrégats dans `tradingV3-private` | forme des `LP-*-RESULT.md` | structurel §6.2 d.8 ; `GATES` §6.3 ; desk §2.3 Q3 |
| **15** | **Lecture du jeu `okx-xperp-1m-history-2026-03-30..2026-10-13`** (`unread`, Market Data §9.4) pour le **G5 Gate 1 historique confirmatoire** ; option `market-data-history` (fichiers T+2/T+3, docs globale seule, disponibilité EEE UNKNOWN) pour C0 et le développement — rien n'est appelé avant décision | G5 Gate 1 ; option C0 | `GATES` §6.2, §8, d.15 ; desk §1.4 |
| **16** | **Actions documentaires** (propriétaire ou auteur habilité) : corriger le rapport théorie l.143/353 (« l'univers X-Perps 1 m a été lu par H2 » — faux : H2 a lu les USDT-SWAP des bases) et l.400 (`economic-calendar` « filtre gratuit » — VIP1+) ; `FRANCE-EXCHANGE-ELIGIBILITY.md` §2 (« France is in its EEA list » → « non exclue, non nommée ») ; re-figer après le 2026-10-31 les métadonnées datées dans la quarantaine (`x-perps-contract-specifications` màj 2026-10-19) | cohérence des sources | quant B3, M8 ; structurel §6.2 d.10, d.11 |

**État au 2026-10-21 (décisions D1–D9).** Tableau d'état daté dans `LP-PROGRAMME-GATES.md` §11 (ajout du 2026-10-21). En bref :
d.1 accordée (D1) ; d.8 consentie (D3) et source principale dYdX v4 (D9) ; d.9 : S_éco fixé (D4 ; règle unique ≥ 97,25 USD/an,
`GATES` §1.2 amendé), coût fixe déclaré nul, m_max = 7 est une règle qui ne demande pas de décision ; d.13 : piste S classée sans
suite (D8) ; d.14 prise par D1 (agrégats sans prix dans le dépôt) ; d.15 couverte par D1, échelle D5, dérogation D6 ; G5
P-G5-10 : D7 ((i) refusée ; (ii) confirmation demandée). Les autres décisions restent ouvertes.

---

## 6. Ce que ce plan ne promet pas

1. **Qu'une poche paie après coûts sur OKX.** Aucune des quatre fiches L n'a de preuve publiée du signe au bon horizon
   (théorie §2 : A mécanisme, signe B/C/UNKNOWN) ; la probabilité a priori d'un succès **exploitable** à 500 USDT est modeste
   (théorie §5 l.6). Le track S repose sur un terme contractuel **révocable** (I est un paramètre d'exchange, déjà 0 pour SAND et
   pour tous les X-Perps TradFi/or/pré-market ; structurel §1 l.5) et sur un funding réalisé jamais lu.
2. **Un revenu.** Si tout passe : dizaines à quelques centaines d'USD par an, queues épaisses non bornées par un stop, coût fixe
   probablement supérieur (théorie §5 l.5 ; CTO §4.3). La valeur minimale du programme est de **clore proprement** la question du
   scalping retail à la minute, mécanisme compris.
3. **Un ordre, même démo.** Aucun verdict n'autorise un ordre par lui-même ; un `PASS_PROGRAMME` « poursuivi » n'autorise qu'une
   **proposition écrite** de Phase 4 ; les drapeaux (`OKX_LIVE_ENABLED`, `OKX_DEMO_TRADING_ENABLED`, `DEMO_TRADING_ENABLED`,
   `HYPERLIQUID_*`) restent à 0 et appartiennent au propriétaire ; `OkxRestEndpointGuard::LIVE_URIS` ne change pas ; X-Perps en
   démo : **UNKNOWN**.
4. **Que la mesure soit sans biais.** Biais vers le PASS déclarés tant que (B) n'est pas codé : prints RPI comptés comme
   consommant la file, `queue_ahead → 0` hors affichage, rejet par bande non modélisé, aucun impact de marché ; biais vers le FAIL :
   observabilité partielle de `liquidation-orders`, stress spread × 3, cool-down (G2 §9, G4 §9). La sélection adverse mesurée sur
   un carnet organique écrémé par des teneurs RPI n'est **pas comparable** à Part A.
5. **Que les dates du §3 tiennent.** Elles supposent des décisions avant le 2026-11-02, un hôte EEE qui sert les X-Perps (UNKNOWN
   jusqu'à P3), un code (B) livré et testé, un chargeur HL écrit, un brouillon G5 gelé avant la lecture de G2 ; un `NOT_INFORMATIVE`
   ou un `INCONCLUSIVE` consomme la fenêtre sans conclusion et exige un nouveau pré-enregistrement.
6. **Un avis juridique ou fiscal.** La qualification de la capture sous §9.4 (« personal, non-commercial trading »), la licence
   Hyperliquid, la fiscalité asymétrique des structures couvertes et le passeport France d'OEM sont des **décisions ou des
   consultations du propriétaire**, pas des faits (desk §2.3 ; structurel §6.2 d.7, d.9).
7. **Que la machine actuelle devienne rentable par réglage.** Corriger post-only, frais internes, levier, univers (décision 10) ne
   change pas le fait que le déclencheur est un **état** sans payeur (théorie §6.10).
8. **Un contournement des règles du programme** : aucun seuil abaissé, aucune fenêtre allongée, aucune analyse promue après une
   lecture ; une exécution par protocole ; W0–W2 et les fenêtres consommées jamais relues ; quarantaine 2026-10-14..31 empêchée
   par le code ; rien sous `fwd-okx-r1` (`GATES` §9).

---

## 7. Sources

**Rapports de base (lus en entier, worktree `lp-programme`)** : `docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md`
(613 l., sha256 `7cc79d0abb491a4b…` ; §1, §2, §3.1–3.5, §4–§7) ; `docs/handbook/reports/research-positive-pnl-structural-2026-10-16.md`
(509 l., sha256 `198b2be1c971421c…` ; §1, §2.1–2.2, §3, §4.1–4.2, §5, §6, §7).

**Livrables du programme (lus en entier)** : `docs/handbook/research/{LP-PROGRAMME-GATES, LP-G2-DRAFT-PREREGISTRATION,
LP-G4-DRAFT-PREREGISTRATION, LP-DATA-DESK-2026-10-20}.md` ; `docs/handbook/technical/lp-executor-architecture.md` ;
`python-orchestrator/scripts/research_lp/{__init__,cli,events,lp_sim,tape,xperp_capture}.py` (constantes et sous-commandes
vérifiées par grep : `QUARANTINE_FLOOR` l.87, `DEFAULT_CHECKPOINT_S` l.89, `WS_PUBLIC_EEA` l.76, `WS_PUBLIC_GLOBAL` l.75 de
`xperp_capture.py` ; `SPREAD_TRANCHES_BP` l.65 de `lp_sim.py` ; `add_parser` l.364–414 de `cli.py`) ;
`python-orchestrator/tests/test_research_lp_{capture,events,sim}.py` (exécutés) ; `.github/workflows/python-orchestrator.yml` l.66, 108.

**Relectures et notes (privé, lecture seule)** : `tradingV3-private/research/20261020T1430Z-lp-programme/review/{quant,cto}.md` ;
`a1-architecture/NOTES.md`, `a2-collector-sim/NOTES.md`, `a3-protocols/NOTES.md` (§3, §4, §7, §8), `a4-data-desk/NOTES.md`,
`a5-corrections-cto/NOTES.md` (§0–§2, §5), `a5-revision/NOTES.md` ; `tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-A/notes/PROTOCOL-H3A-GATE1-DRAFT.txt`
(CS-FUND : l.18, 38, 68–69, 72–76, 82–83, 94).

**Dépôt (lecture seule)** : `docs/handbook/research/{RESEARCH-CAMPAIGN-STATUS (l.9, 18, 45, 94, 121, 147), H1-XPERP-ACCOUNT-CHECK
(table des frais), H1-XPERP-MARKET-ACCESS (l.85–86), RESEARCH-ELIGIBILITY-GATES, H2-CLOSURE-DECISION, PREREGISTRATION-H2-GATE1}.md` ;
`CLAUDE.md` (§ Demo / testnet safety, § Tests).

**Documentation officielle** : citée **par les livrables** (desk §9 : URL et sha256 des 31 pages, lues le 2026-10-20 ;
architecture §9 ; GATES §13). Aucune page n'a été relue pour ce plan ; aucune donnée de marché, aucun endpoint, aucun S3.

**UNKNOWN laissés tels quels** : hôte WS servant les X-Perps publics ; couverture et cadence réelles des canaux pour `instType
FUTURES` ; part des X-Perps dans `liquidation-orders` ; `ctVal` 0,0001 vs 0,01 BTC et `ctType` des X-Perps ; `posMode`, règle de
gel de marge, devise USD-M, barème API, spot comme Selected Margin, X-Perps en démo ; durée réelle du run MTF ; taux de
compression des segments ; licence et couverture temporelle de l'archive Hyperliquid ; σ des mark-outs et des aller-retours
sur X-Perps ; funding réalisé ; comportement de la suite de tests sous Python 3.11 réel ; qualification juridique de la capture.

Fin du plan. Rien n'est gelé, rien n'est lancé, rien n'est ordonné.
