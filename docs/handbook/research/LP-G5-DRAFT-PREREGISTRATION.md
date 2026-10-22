# LP-G5 — Scalping sur périodes précises : horloge, calendrier et régimes (pré-enregistrement, brouillon)

**Statut : `DRAFT_NOT_FROZEN`, prêt à geler après les prérequis de §2.** Brouillon du 2026-10-21 (volet `a1-protocol` du
programme `20261020T2321Z-g5-periods`). Aucun sha256 de gel, **aucune donnée de marché lue ni appelée** (aucun endpoint
`market/*` ni `public/*`, aucun WebSocket, aucun jeu de données), aucun ordre. Ce texte n'autorise rien par lui-même : il
décrit ce qui sera gelé si le propriétaire le décide. Les règles communes du programme sont dans `LP-PROGRAMME-GATES.md`
(porte économique, multiplicité, puissance, deux lectures, registre, interdits) ; ce document ne les répète que là où G5
les instancie.

**Révision du 2026-10-21 (passe unique après la relecture `tradingV3-private/research/20261020T2321Z-g5-periods/review/quant.md`,
verdict `NEEDS_CHANGES`).** Statut inchangé. Les cinq constats bloquants (B1–B5), les onze majeurs (M1–M11) et les mineurs justes
(m1–m13) sont appliqués dans le corps du texte ; le détail constat → traitement est en §12 « Traitement de la revue », où les refus
et les traitements différents sont écrits « **Réponse à la revue** ». En bref : un **seul classifieur** P̂ évalué à la décision
(h−5) pour les événements et les contrôles (B1) ; contrôles à la **même minute de l'heure** que l'événement, sessions exclues en
heure locale (B2, M4) ; éligibilité codée, **T05 = 105 instants** et non 123 (B3) ; une **seule règle** de PASS (B4) ; issues
**automatiques** et statistiques **scellées** pour `INCONCLUSIVE` et `NOT_INFORMATIVE` (B5) ; seuil d'incitation |R| ≥ 4 pb (M1) ;
proxy de fill en primaire de T01 (M11) ; coûts 13 / 15,5 pb (M6 ; T01 à **9,5 pb** depuis l'amendement A1) ; fetch pré-gel limité au funding, scellé ; lecture ramenée à
≈ 58 000 requêtes sur 25 instruments (M7, M8). La **spécification machine** du Gate 1 est désormais un fichier du dépôt :
`python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json` (statut `DRAFT_NOT_FROZEN`, cohérent avec ce texte) ; c'est ce
fichier, et lui seul, que l'évaluateur lira ; **son sha256 fait partie du gel** (§13). La relecture CTO (`review/cto.md`) porte sur
le code : ses constats sont repris comme prérequis de code (P-G5-11 (B)), pas appliqués par cette passe.

**Amendement A1 du 2026-10-21 (demandé par le propriétaire ; écrit avant tout gel et toute lecture, déclaré comme tel ; section
« Amendement A1 » avant §13).** Statut inchangé (`DRAFT_NOT_FROZEN`). Objet : une expérience qui distingue « signal sans avantage »,
« avantage trop faible pour les coûts », « signal exploitable mal exécuté » et « résultat insuffisamment documenté », en mesurant à part
**A** (mouvement après le signal), **B** (mouvement après un remplissage simulé) et **C** (net : coûts, funding, lecture portefeuille),
sans compter deux fois le spread ni la sélection adverse. En bref : la sélection adverse de la jambe d'entrée de T01 est **mesurée par le
conditionnement au remplissage** (0 supposé) ; T01 coûte **9,5 pb** (stress **14,25 pb**) au lieu de 13 / 19,5 pb, l'ancien total devenant
une **sensibilité rapportée** ; T05 vérifié et inchangé (15,5 / 23,25 pb) ; tableau A/B/C, analyse des non-remplis, variante preneur de
T01, stabilité chronologique et classification diagnostique **rapportés, jamais décisifs** (§9.9) ; frontières de fenêtre exclues et
comptées. C1–C7, Holm et la porte économique sont inchangés, sauf le total de coût de T01 dans C2 / C3 (et donc dans le net absolu lu par
la porte économique), déclaré. Spec Gate 1 `0.3.0-draft`, spec Gate 2 `0.1.3-draft` (identité de la règle, piège 7).

**État au 2026-10-21 (soir, passe avant gel ; détail en §2.4).** Statut inchangé (`DRAFT_NOT_FROZEN`). Spec Gate 2 `0.1.5-draft` : D7 (i)
(fin de T05 au Gate 2 au 2027-03-31, aucune prolongation) et D8 (P-S3 sans objet, BTC et ETH aux deux portes) écrites dans leurs blocs
de décision, plus aucune valeur `owner_decision_pending` ; T05 au Gate 2 `NOT_INFORMATIVE` **par construction**, écrit par le lecteur ;
fetcher de l'option (b) de D10 écrit et testé hors réseau (`g5_index_fetch`). Le gel reste impossible : prérequis ouverts en §2.4.

**Décisions du propriétaire du 2026-10-22 (avant tout gel et toute lecture ; détail en §2.3 (B) xxiv–xxv, §2.4, §10.2, §11.1, Annexes
A et B).** Statut inchangé (`DRAFT_NOT_FROZEN`). **(a)** « Finir les contrôles G5 réalisables dès maintenant » : les cinq demandes de code 11, 13, 14, 16 et 18 de
l'Annexe B sont codées et testées hors réseau (co-gel contrôlé par le Gate 1, test de contenu des calendriers aux deux portes, refus de
`FROZEN` sur `freeze_pending:`, S_éco = 97,25 USD/an dans les deux specs, descriptif de T01 filtré sur le seul règlement précédent).
**(b)** « Choisir la traversée comme remplissage principal du Gate 2, et garder le simple contact comme comparaison descriptive » : `execution_sim.entry_fill_rule = through` au Gate 2 ; `touch` (et `queue`) rapportées, **jamais
décisives** — le résultat `touch` ne peut jamais sauver un `FAIL`, un `NOT_INFORMATIVE` ni un `INCONCLUSIVE`. Spec Gate 1 `0.4.0-draft`,
spec Gate 2 `0.1.6-draft`. Le gel reste impossible : prérequis ouverts en §2.4.

**Passe calendriers et rédaction du 2026-10-22 (après-midi ; avant tout gel et toute lecture ; aucune donnée de marché ; réseau limité
aux pages officielles des calendriers ; détail en §2.4, Annexes A à C).** Statut inchangé (`DRAFT_NOT_FROZEN`). Calendriers des deux
portes relevés le 2026-10-22 sur bls.gov, federalreserve.gov et nyse.com (Annexe C ; sources datées dans `calendars.sources`) : le test
de contenu ne rapporte plus aucun problème, ni au Gate 1 (CPI 2026-10-14 inscrite) ni au Gate 2 (CPI 2027-01 → 04, Employment Situation
2026-11 → 2027-04, FOMC 2027-04-28, demi-séances NYSE 2026-11-27 et 2026-12-24). Les sept choix de rédaction de l'Annexe B (huit
marqueurs `freeze_pending:`) et les cinq écritures signalées par la relecture review-b (M1, M3, m1, m3, m4) sont écrits au corps (§7.4,
§8, §10.1 à §10.4) ; plus aucun marqueur dans les specs. Spec Gate 1 `0.4.1-draft`, spec Gate 2 `0.1.7-draft`. Le gel reste impossible :
prérequis ouverts en §2.4, dont deux constatés par cette passe (règle d'une date de calendrier changée après le gel ; liste des
publications de 10:00 ET).

**Décision P-L2-1 et règle des dates de calendrier changées après le gel (2026-10-22, soir ; avant tout gel et toute lecture ; aucune
donnée de marché, aucun réseau ; détail en §2.4, §10.1, §10.3, §10.5, §10.6 et Annexe B).** Statut inchangé (`DRAFT_NOT_FROZEN`).
**(a) P-L2-1** (`LP-OWNER-DECISIONS-2026-10-21.md` D14) : la fenêtre d'exploration de développement du pilote de capture L2 (X-Perps BTC
et ETH, [2026-11-02T00:00Z ; 2026-11-03T00:00Z), fin dure quel que soit le départ) et une marge de 12 h forment l'**intervalle exclu**
[2026-11-02T00:00Z ; 2026-11-03T12:00Z) ; aucune observation de cet intervalle n'entre dans le Gate 2, ses empreintes ni ses lectures
préalables ; **T0 ≥ 2026-11-04** ; bloc `pilot_separation` du spec, lu et appliqué par le code (§10.6). **(b) Règle des calendriers**
(§2.4 ligne 15 levée) : instantanés officiels datés et hachés après le gel, classement à la date de décision, un instant retenu seulement
s'il est admissible sous toutes les versions, instantané final exigé avant l'exécution (§10.5). Spec Gate 2 `0.1.8-draft` ; spec Gate 1
inchangé (`0.4.1-draft` : sa fenêtre est passée, aucun recouvrement). Le gel reste impossible : prérequis ouverts en §2.4.

**Mandat.** Propriétaire, 2026-10-20 : « Ok go. Mais ta mission reste de trouver comment faire un PnL positif avec le
scalping, même sur des périodes précises ». Décisions du 2026-10-21 (`LP-OWNER-DECISIONS-2026-10-21.md`) : D1 licence §9.4
accordée, **y compris** « la lecture historique unique de G5 Gate 1 : bougies 1 minute dernier prix, mark et indice, et
historique de funding des 29 X-Perps liquides, du listing au 2026-10-13 inclus, fin dure codée au 2026-10-14 00:00 UTC » ;
D4 porte économique S_éco ≈ 97 USD/an (valeur littérale 97,25 USD/an, `GATES` §1.2 amendé ; écrite dans les deux specs le 2026-10-22).
G5 passe en tête de la piste L.

**Base.** Fiche G5 (§3.3, rang 3) et règles transversales §3.5 du rapport
`docs/handbook/reports/research-scalping-theory-gap-2026-10-20.md` ; `LP-PROGRAMME-GATES.md` (§1 porte économique, §2 Holm,
§3 puissance, §4 deux lectures, §5 coûts, §6 registre, §8 séquence, §9 interdits) ; `LP-PROGRAMME-PLAN-2026-10-20.md` §2.2 (lignes
G5 Gate 1 / Gate 2) ; `LP-DATA-DESK-2026-10-20.md` (§1.4 endpoints, §2 licence) ; relecture adversariale
`tradingV3-private/research/20261020T2321Z-g5-periods/a3-redteam/PERIODS.md` (sha256
`8c991988556aff82a7aa907d9088a9f638d8bf3a9aef07f9118e36d60243038d`, 22 types, 24 pièges) ; relectures `review/quant.md` et
`review/cto.md` du même dossier ; code `python-orchestrator/scripts/research_lp/{tape,g5_fetch,g5_eval,lp_sim,events,cli}.py`
(réutilisé, pas dupliqué ; état au 2026-10-21 en §2.2). Style et structure : `LP-G2-DRAFT-PREREGISTRATION.md`,
`LP-G4-DRAFT-PREREGISTRATION.md`.

**Nature.** G5 est la seule poche où un participant à la cadence minute **n'est pas en retard** : l'instant est connu à
l'avance, l'ordre est armé avant lui. Deux portes, **une seule famille de signes**, gelées ensemble. **Gate 1 (historique,
confirmatoire)** : une lecture unique du jeu `okx-xperp-1m-history-2026-03-30..2026-10-13` (statut `unread`, §0.2), signes et
fenêtres pré-déclarés par type, contrôles aux instants sans événement à la même minute de l'heure, Holm sur les types décisifs.
**Gate 2 (prospectif)** : mêmes signes, classifieur et fenêtres sur la capture publique ≥ 2026-11-02, exécution simulée sur carnet
réel. **Deux types décisifs** (famille Holm, K = 2) : **T01** la photo de funding 8 h quand la prime reconstituée à la décision
dépasse le seuil d'incitation (|R| ≥ 4 pb ; payeur contractuel nommé) et **T05** l'ouverture cash US (seul prior interne positif
contre contrôle). Tout le reste des vingt-deux types de la red team est **rapporté non décisif**, **filtre**, **différé au Gate 2**
ou **rejeté** avec sa raison (§1.3). Un PASS n'autorise que la porte suivante ; jamais un ordre (§8).

**Convention.** « établi » = lu dans le code (chemin) ou une page officielle (URL, date) ; « programme » = chiffre recopié
d'un rapport du dépôt ; « calcul d'audit » = arithmétique sans donnée ; **UNKNOWN** = ni sourcé ni mesuré. **Aucun chiffre de
marché** ne figure ici. Notation : `c(t)` = clôture de la bougie 1 m **dernier prix** qui se termine à t (bougie `ts = t − 60 s`) ;
`m(t)` = idem sur le mark ; `idx(t)` = idem sur l'indice ; π(t) = (m(t) − idx(t)) / idx(t) ; **P̂(t)** = moyenne pondérée de π sur
les 480 bornes de minute qui finissent à t (poids 1 à 480, la plus récente pèse 480, §5.1) ; h = instant d'événement (frontière de
minute UTC) ; t_arm = instant de décision et d'armement ; « empreinte » d'un instant = intervalle [début ; fin] de ses fenêtres de
signe, d'armement et de trade (§7.1) ; pb = point de base.

---

## 0. Déclarations préalables

0.1 **Données jamais lues.** L'auteur de ce brouillon n'a lu aucun prix, spread, volume, carnet, funding ou prime d'aucun
X-Perp (`instType FUTURES`), courant ou historique, ni d'aucune autre venue pour cette passe. Les seules lectures « données »
sont des **métadonnées** : le snapshot `public/instruments` du 2026-10-16
(`tradingV3-private/research/20261016T2145Z-positive-pnl-structural/eea-instruments-FUTURES-20261016.json`, sha256
`f8a7f046efb0ff4b64952edf76d604ecfb79d5fc04fa60a6d7e08721084be316`, champs `listTime`, `expTime`, `uly`, `ruleType`, `state`,
`ctVal`, `tickSz`, bandes de prix — §3), et des **calendriers publics** (§4.6). Ce snapshot est **daté dans la quarantaine**
(2026-10-14..31) : ses `listTime` sont des faits immuables, mais la liste est re-figée après le 2026-10-31 (P-G5-4). La passe de
révision n'a lu, en plus, que la documentation déjà extraite par le desk (`a4-data-desk/txt/funding-mechanism.txt` l.225–300,
`docs-v5-en.txt` l.41454, 41634, 43897) et des rapports du dépôt.

0.2 **Registre et statut du jeu.** `okx-xperp-1m-history-2026-03-30..2026-10-13` (`LP-PROGRAMME-GATES.md` §6.2) est **`unread`** :
H2 a lu les **USDT-SWAP** d'OKX Global des bases figurant dans la liste des X-Perps, jamais les X-Perps eux-mêmes
(`PREREGISTRATION-H2-GATE1.md` l.82–88 ; `RESEARCH-CAMPAIGN-STATUS.md` §3 : l'historique des X-Perps « n'a servi qu'à des
relevés de métadonnées et de spécifications »). La lecture unique du Gate 1 le fait passer à **`consumed_equivalent`** à la
première valeur lue par l'évaluateur. Ce Gate 1 n'est **pas** une récupération d'H2 (`H2-CLOSURE-DECISION.md` §4) : hypothèse
d'horloge à signes gelés depuis la théorie (H2 testait un résidu transversal à 3 jours), produit différent (X-Perps, pas SWAP) ;
la seule exposition est une période partiellement commune aux mêmes bases lues en SWAP, déclarée ici.

0.3 **Expositions antérieures déclarées comme influence (programme), jamais comme prior fort ni comme seuil.**
- S3 « opening drive » (`research-scalping-2026-10-12.md` l.59, 97–98, 105–108) : Binance USDT-perps, bougies 5 min, entrée
  au marché, sorties taker ; S3.US −0,225 R contre contrôle −0,312 ; S3.ASIA −0,285 contre −0,378 ; « the opening drives are the
  only setups that beat their random controls (by about 0.1 R) ». Preuve **B** au mieux ; transférabilité aux X-Perps minces
  **non déclarée** — influence pour T05, pas un prior fort (red team piège 24).
- Desk FUND-SETTLE du 2026-10-16 (rapport théorie §3.4 ligne 16) : le **snipe** du funding (encaisser F) est clos ; la **dérive
  de prix** autour de la photo n'a jamais été lue — c'est l'objet de T01.
- **D4 « funding contrarien »** (`research-regime-r2-2026-10-14.md` l.43 : détecteur de régime à l'horizon du jour, 2021-01-08 →
  2022, surplus −802 pb, p = 1,000 ; venue Binance d'après la relecture quant m11) : le signe du funding comme **signal de
  régime** a échoué ; T01 ne lit pas le funding comme signal de direction mais comme incitation datée autour d'un instant (règle
  différente, horizon 20 min) — exposition déclarée (quant m11).
- **Études tick r1–r10** (`research-execution-tick-2026-10-12.md`, `research-midcap-tick-2026-10-13.md`,
  `research-book-families-2026-10-13.md`, `research-scalping-120-2026-10-13.md` ; Binance, prints, bookDepth, bookTicker) :
  contrôles **appariés à l'heure du jour**, donc aveugles à un effet d'horloge par construction (rapport théorie §3.3 G5), mais
  séries lues aux mêmes heures ; aucun résultat horaire n'en est tiré ici (quant m11).
- Part A (`research-execution-tick-2026-10-12.md`) : sélection adverse nette d'entrée −3,05 à −3,49 pb (règle `through`) sur
  BTC/ETH à 1 tick — fonde la **valeur de conception** −3,5 pb par jambe de `GATES` §5.4 (§6), jamais un seuil de PASS.

0.4 **Interdits respectés par ce brouillon et imposés à son exécution.** Rien daté ≥ 2026-10-14T00:00:00Z n'est lu ni
persisté (constante `g5_fetch.END_EXCLUSIVE`, §4.2) ; jamais W0–W2 de `fwd-okx-r1` ni le dossier
`tradingV3-private/research/20261013T100000Z-forward-okx-r1/` ni les services `fwd-okx-*` ; aucune commande git, Docker,
systemd ; aucun `.env`, clé, drapeau ; écritures de cette passe limitées à ce fichier, au spec
`python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json` et à
`tradingV3-private/research/20261020T2321Z-g5-periods/a1-protocol/NOTES.md`. Aucun ordre, aucune démo, aucun drapeau à
aucune étape de G5 (règle 13).

0.5 **Licence OKX API Agreement §9.4 (EEE, « Last Updated: 28 July 2026 », texte intégral dans `LP-DATA-DESK` §2.1).** Toutes
les séries de §4 sont des Market Data (§1.8) ; l'usage autorisé est « your own personal, non-commercial trading and account
management purposes » ; la clause scraping vise « a rate or scale that (a) exceeds what is reasonably necessary for your
personal trading use ». Consentement consigné : « Ok go » du 2026-10-20 et **D1 du 2026-10-21** (« Couvert aussi. La lecture
historique unique de G5 Gate 1 … »). Ce protocole lit D1 comme le **consentement de lecture** et **minimise l'échelle** à ce que
l'hypothèse exige (quant M8) : **25** instruments seulement (les 4 sans aucun jour éligible ne sont pas récupérés) ; bougies dernier
prix depuis l'éligibilité − 1 jour ; mark et indice **uniquement** sur la fenêtre du classifieur de T01, [D_cov − 1 j ;
END_EXCLUSIVE) ; funding sur sa profondeur servie. Calcul d'audit (§4.3) : **≈ 58 300 requêtes** (contre ≈ 190 000 dans la première
version du brouillon), ≈ 2,8 h séquentielles à 0,8 × les limites documentées, ≈ 5,6 h à 0,4 ×. Ce chiffre, et le facteur de cadence,
sont soumis au propriétaire **avant le gel** (P-G5-1). Règles conservées : séries et tables chiffrées dans `tradingV3-private`
seulement ; le dépôt reçoit ce protocole et des agrégats statistiques sans prix (D1, §4.8) ; aucun modèle commercialisé ou partagé ;
tout instrument, série ou hôte ajouté = nouvelle décision.

0.6 **Payeur nommé, preuve du signe (règles 1–2 du rapport théorie).**
- **T01** — payeur : l'**éviteur** (le long qui va payer R > 0 ferme avant h et rouvre après) et le **sniper** (ouvre un short
  avant h pour encaisser R, rachète après) ; tous deux vendent avant h et achètent après ; le fournisseur qui absorbe ce flux est
  **long à travers la photo et paie R**. Fondement contractuel (page funding EEE, màj 2026-08-27, `a4-data-desk/txt/funding-mechanism.txt`
  l.232) : « You're obligated to pay or receive the funding fee if you hold open positions at the point of fee assessment. If you
  close your position before the funding fee assessment, you're exempt ». Preuve : **A** pour le mécanisme, **UNKNOWN** pour la
  dérive de prix (aucune event study de la photo trouvée : He-Manela-Ross-von Wachter 2212.06888 / 2506.08573 traitent le design
  du funding — recherche red team 2026-10-21). **L'incitation n'existe que si |R| dépasse un aller-retour maker/maker (4 pb)** :
  le seuil primaire est donc |R| ≥ 4 pb, soit, par la formule (§5.1), une prime moyenne ≥ +0,09 % ou ≤ −0,09 %, appliquée à P̂ à la
  décision (quant M1, option b). Les versions « prime > 0 sans seuil » (fiche G5) et « hors zone morte » (première version de ce
  brouillon) sont **descriptives seulement** (piège 20).
- **Mécanisme transversal aux places (quant m10).** Le flux d'évitement se joue surtout sur les swaps mondiaux (OKX global,
  Binance, même horloge 00/08/16 UTC) ; l'indice des X-Perps et leur base en héritent. T01 mesure donc **en partie l'effet du
  funding mondial transmis par l'indice**, sans pouvoir le stratifier (les USDT-SWAP sont consommés, `GATES` §9.3) : déclaré comme
  interprétation, pas comme biais.
- **T05** — payeur : l'exécuteur du **métaordre** institutionnel / AP d'ETF qui arrive à l'ouverture et laisse une continuation
  (mémoire du flux, Bouchaud-Farmer-Lillo 2009, cité par le rapport théorie, non relu ici) ; celui qui suit les cinq premières
  minutes est **preneur** (règle 6 : exécution preneur pour un retard). Preuve : **B** (concentration de la variance et du volume
  pendant les heures US : notes d'industrie K33 2022, Kaiko 2024, Kraken 2016–2025, non revues ; S3 interne) ; signe de la
  continuation au bon horizon : **UNKNOWN**.
Prior : **moyen** pour les deux (rapport théorie §3.3 G5 : « A volatilité ; signe B/UNKNOWN »).

0.7 **Vocabulaire déclaré.** T01 est une règle de **fournisseur** (ordre au repos, post-only) ; **T05 est une règle preneur**
(continuation, entrée au marché à h+5, sortie passive) — elle n'entre pas dans le vocabulaire « fourniture de liquidité
événementielle » du programme (décision 4 du propriétaire en attente) et est écrite **telle quelle** (piège 21). Le mandat du
propriétaire est le **scalping**, pas la fourniture ; les deux règles sont des scalps de 20 à 55 minutes.

0.8 **Aucune prédiction d'état, aucune information après la décision.** Ni T01 ni T05 ne lisent un indicateur. T01 lit P̂(h−5),
reconstitution de la prime qui fixera le taux contractuel, sur des bornes de minute ≤ h−5 : **rien n'est lu après la décision**
(quant B1 ; la première version classait par R_h, calculé à h−1, quatre minutes à l'intérieur de la fenêtre de trade). R_h ne sert
plus qu'au **coût** de funding réel et à la validation du classifieur (§7.2). T05 lit le signe de [h ; h+5] après un instant
**certain**, et décide à h+5. Le parseur doit refuser tout champ de condition ou de signe observé après `arm_min` (P-G5-11 (B) xvii ;
CTO B2).

0.9 **Ce qu'un Gate 1 sur bougies mesure, et ne mesure pas (pièges 22 ; quant M11).** Sans carnet, pas de spread, pas de file,
pas de `trades.source`. Pour **T01** (ordre au repos), la mesure primaire est **conditionnée au fill** par un proxy de bougies
(règle `through` : un long est rempli si un plus bas de [h−5 ; h+1) passe strictement sous c(h−5) − 1 tick ; miroir pour un
short ; §5.4), appliqué de la même façon aux contrôles : l'estimand du Gate 1 se rapproche de celui du Gate 2 (fill réel sur
carnet) ; la dérive inconditionnelle devient **descriptive**. Le proxy reste une approximation (le prix limite est la clôture
c(h−5), pas le bid réel ; direction du biais UNKNOWN, §11.1). Pour **T05** (preneur), le fill est certain par construction ; le
demi-spread payé est une valeur de conception (§6). Un PASS Gate 1 ne vaut qu'avec le Gate 2 sur tape ; un FAIL Gate 1 ferme la
forme testée du type (§8).

0.10 **Écarts déclarés avec la red team (`PERIODS.md`, quant m5).** (i) PERIODS l.75 date le Good Friday « 03-04 » : lire
**2026-04-03** ; (ii) PERIODS écrit pour T05 « 122 hors CPI/NFP » et « 13 sur 135 » : sans éligibilité, c'est 123 et 12 (04-03 est
férié) ; (iii) **les deux documents ignoraient l'éligibilité** `listTime + 30 j` : avec elle, **116** jours NYSE et **105** instants
T05 (§5.2) ; (iv) PERIODS met le **mark** en prix primaire de T01 et des coûts de 4 pb ; ce protocole prend le **dernier prix**
(§5.3) et **13 pb** (§6 ; **9,5 pb** depuis l'amendement A1) ; (v) PERIODS propose 10:30 UTC comme contrôle de T05 ; ce protocole prend **toutes** les minutes 30
admissibles des jours d'événement (§7.1). PERIODS n'est pas modifié (hors du périmètre d'écriture) ; ces écarts y font foi.

---

## 1. Hypothèses

### 1.1 Types décisifs (famille Holm, K = 2)

**H-G5-T01 (photo de funding 8 h, prime au-delà du seuil d'incitation).** Sur les X-Perps crypto d'OKX Europe éligibles (§3.2),
aux règlements h à intervalle 8 h (§5.1) de la fenêtre couverte par l'historique de funding (D_cov, §4.4), quand la prime
reconstituée à la décision vérifie **|P̂(h−5)| ≥ 0,09 %** (équivalent, par la formule officielle, d'un taux |R| ≥ 4 pb), un ordre
passif posé à **h−5** au prix c(h−5) du côté s (bid si P̂ ≥ +0,09 % : les longs paieront ; ask si P̂ ≤ −0,09 %), vivant jusqu'à h+1,
**rempli** selon le proxy de bougies (§5.4), tenu à travers la photo et sorti passivement à **h+15**, obtient sur [h−5 ; h+15] une
dérive signée nette du funding réellement payé (−s·R_h) et des coûts supposés (9,5 pb, §6, amendement A1 ; 13 pb avant A1) **supérieure d'au moins +10 pb** à la même
règle — même classifieur, même seuil, même proxy de fill, mêmes coûts — appliquée aux instants hh:00 sans règlement des mêmes jours
(§7.1). Forme attendue (descriptive) : le prix baisse sur [h−15 ; h] et remonte sur [h ; h+15] quand P̂ > 0 (miroir sinon) ; l'effet
croît avec |R_h| (dose). Écart déclaré à la fiche G5 (« prime publiée à h−1 ») : la prime de h−1 n'est pas observable à la
décision (h−5) ; P̂(h−5) en est la version négociable.

**H-G5-T05 (ouverture cash US, continuation preneur).** Les jours de bourse NYSE hors publications CPI / Employment Situation,
à h = 09:30 New York (13:30 UTC sur toute la fenêtre du Gate 1, heure d'été ; 14:30 UTC en heure d'hiver au Gate 2), sur les
X-Perps éligibles, le signe s du mouvement des cinq premières minutes, s = sign(ln c(h+5) − ln c(h)), **se poursuit** sur
[h+5 ; h+60] : une entrée preneur à h+5 du côté s, sortie passive à h+60, obtient une dérive signée nette des coûts supposés
(15,5 pb) supérieure d'au moins +10 pb à la même règle appliquée aux minutes hh:30 sans événement des mêmes jours (§7.1).

**Un signe et une fenêtre par type** (règle 10 ; `GATES` §2.4) : les horizons, formes en V, strates et variantes de §9 sont
descriptifs et ne reçoivent ni p-value ni verdict ; aucune analyse secondaire ne devient primaire par amendement après une
lecture.

### 1.2 Pourquoi K = 2 et pas plus

Chaque type testé est un test de la famille du programme : m = 3 + 2K (Gate 1 et Gate 2 pour chaque type ; `GATES` §2.3
compte aujourd'hui « G5-Gate1 » et « G5-Gate2 » comme un test chacun, m_max = 5 — à amender en **m_max = 7**, P-G5-7). Un
type à n < 100 par calendrier achète un `NOT_INFORMATIVE` certain qui compte p = 1 dans la famille (`GATES` §2.1) : les types
T08 (28 vendredis), T11 (17 publications), T19 (6–8), T13 (≈ 0), T15 (0) sont **rejetés comme tests**, pas différés (piège 8).
Les types à même instant qu'un type décisif (T03, T04, T16 au Gate 2) tombent sous la règle **« un instant, un type testé »**
(§1.4). Les types qui exigent le carnet ou le flux signé (T02, T06, T18, T20) sont différés au Gate 2 en **strate descriptive**,
jamais promus après lecture (§9).

### 1.3 Disposition des vingt-deux types de la red team

| Id | Type (instants UTC Gate 1) | Payeur / preuve | n Gate 1 (calcul) | Disposition | Raison |
|---|---|---|---|---|---|
| **T01** | Photo de funding 8 h, \|P̂(h−5)\| ≥ 0,09 % (00:00, 08:00, 16:00 lus dans `fundingTime`) | éviteur / sniper ; A mécanisme, UNKNOWN signe | 588 bruts ; **≤ 211** dans D_cov (fetch du funding le 2026-11-02, §4.4) × f_inst UNKNOWN | **DÉCISIF (Gate 1)** | seul payeur contractuel nommé ; classifieur observable à la décision ; jeu `unread` |
| T02 | Photo de funding 4 h (04/12/20 en plus ; nouveaux tokens, plafond ±1 %) | idem ; R peut dominer tout rebond | 2–3 instruments minces | **DIFFÉRÉ Gate 2**, strate de T01 | trop peu d'instruments, F dominant, carnets minces ; leurs règlements sont dans l'union d'exclusion des contrôles (§7.1) |
| T03 | Ouverture du jour UTC / Tokyo 09:00 JST (00:00) | flux diffus ; C | 196 (= T01 00:00) | **REJETÉ** (strate descriptive de T01) | même instant que T01 ; composite déclaré, d'où C7 (§7.5) ; S3.ASIA = contenu de timing ; Hansen-Kim-Kimbrough = volatilité, pas un signe |
| T04 | 08:00 UTC comme instant de session (HKEX, Deribit quotidien, Londres en hiver) | non isolable ; UNKNOWN | 196 (= T01 08:00) | **REJETÉ** (T01 08:00 écrit comme composite) | même instant que T01 ; aucun signe publié ; d'où C7 |
| **T05** | Ouverture cash US 09:30 NY (13:30 UTC, EDT) | exécuteur de métaordre / AP ETF ; B | **116** jours NYSE avec ≥ 1 instrument éligible, **105** hors CPI/NFP | **DÉCISIF (Gate 1)** | n ≥ 100 (marge de 5), instant certain, seul prior interne positif contre contrôle (S3) ; effet attendu petit, puissance au plancher (§7.4) |
| T06 | Clôture cash US 16:00 NY (20:00 UTC EDT ; 21:00 EST) | AP / rééquilibreur ; UNKNOWN signe | 116 | **DIFFÉRÉ Gate 2** (strate descriptive) ; **exclue des contrôles** (session) | mécanisme plausible, aucun signe publié ni interne ; ne pas dépenser un test Holm |
| T07 | Minute 0 de chaque heure | personne : un coût ; A vol./volume | 4 704 | **FILTRE** | jamais d'armement à h−1 ; contrôles **à la même minute de l'heure** que l'événement (quant B2) ; rapport §9.1 |
| T08 | Vendredis 08:00 : expiries datés OKX (16:00 HKT), Deribit, fins de mois | livreur / couvreur ; UNKNOWN crypto | **28** | **REJETÉ** (strate « vendredi » de T01 08:00) | n < 100 ; [07:30 ; 08:00] du vendredi (prix de règlement = moyenne 30 min de l'indice, `xperps-specs.txt` l.944) exclu des contrôles |
| T09 | Week-ends et fériés US (régime) | personne ; A volume, C rendement | 56 + 5 jours | **FILTRE** (strate ouvré / non ouvré) | un régime n'est pas un instant |
| T10 | Jour de la semaine | aucun ; C (Baur et al. 2019) | 28 par jour | **REJETÉ** | pas de mécanisme ; rapport §9.3 seulement |
| T11 | Annonces macro US : CPI, Employment Situation (08:30 NY), FOMC (14:00 NY) | l'informé rapide contre nous ; A réaction, UNKNOWN dérive | **17** | **FILTRE** (exclusion des contrôles ; jours CPI/NFP exclus de T05), instants générés **en heure de New York** (quant m8) | n ≪ 100 ; saut inaccessible à la minute |
| T12 | Jours de listing X-Perp | vendeurs structurels ; A au jour | ≈ 160 listings | **REJETÉ** | H1 clos ; le filtre `listTime + 30 j` (§3.2) retire ces jours de tous les types |
| T13 | Délistings / changements de contrat (M3) | porteur fermé d'office ; UNKNOWN | ≈ 0 crypto | **REJETÉ** (inventaire seul) | rien à tester dans la fenêtre |
| T14 | Régimes : rv24 > médiane ; post-cascade | conditionnalité (Nagel) ; UNKNOWN intraday | ≈ 2 690 instrument-jours éligibles | **FILTRE** (strate stress / calme) | un régime n'est pas un instant ; médiane sur l'historique éligible ≤ 30 j (§9.4) ; post-cascade impossible au Gate 1 |
| T15 | Changements d'heure (DST) | UNKNOWN | **0** | **REJETÉ** (piège de définition) | T05, T11 et les sessions sont définis en heure locale |
| T16 | Ouverture Europe (07:00 UTC en été ; 08:00 en hiver) | UNKNOWN | 140 | **REJETÉ** ; **exclue des contrôles** (session `london_xetra_open`) | aucune preuve ; au Gate 2 l'instant coïncide avec la photo 08:00 |
| T17 | Ouverture Hong Kong / Shanghai (01:30 UTC) | UNKNOWN | ≈ 130 | **REJETÉ** ; **exclue des contrôles** (session `hong_kong_open`) | aucun mécanisme crypto ni preuve |
| T18 | Fixing CME CF BRR (15:00–16:00 Londres) ; expiration CME | couvreurs en TWAP ; UNKNOWN signe ; spécifications CME **non consultées** | 196 ; 6 expirations | **DIFFÉRÉ Gate 2** (strate descriptive) ; fenêtre **exclue des contrôles** (à vérifier au gel, P-G5-5) | mécanisme défendable, aucun prior de signe |
| T19 | Fins de mois / de trimestre | UNKNOWN | 6 / 2 | **REJETÉ** | n ≪ 100 |
| T20 | Imbalance d'ouverture de quart d'heure → 4–12 h (Kim & Hansen 2026) | algos périodiques ; A/B hors échantillon | **non calculable sur bougies** | **DIFFÉRÉ Gate 2**, hors scalp | exige le flux signé ; autre protocole (décision 12) |
| T21 | Creux de liquidité 02:00–06:00 UTC | personne ; A volume | 196 nuits | **FILTRE** (strate de coût pour G2) | aucun signe de rendement |
| T22 | Décisions BCE (12:15 UTC en été) | informé rapide ; UNKNOWN | ≈ 4–5 | **REJETÉ** | n ≪ 100 ; pertinence crypto faible |

Non retenus comme types (red team §3) : instantané quotidien du fonds de sécurité « around 08:00 am (UTC) », rate limit
00:00 UTC, règlement quotidien des datés (hors X-Perps), « max pain » (folklore).

### 1.4 Règle « un instant, un type testé » (piège 2) et instants composites (quant M4)

Chaque instant appartient à **exactement un** type testé, priorité **T01 > T05**. **00:00** (jour UTC, Tokyo) et **08:00** (HKEX,
Deribit, datés du vendredi ; Londres en hiver) sont des **composites** que les contrôles ne portent pas ; **16:00 est la seule photo
pure**. D'où le critère **C7** (§7.5) : le point de D doit être > 0 sur les seuls règlements de 16:00 ; interprétation écrite
d'avance : un effet porté par 00:00 / 08:00 seuls est attribué à la session, pas au funding. Au Gate 2 (heure d'hiver), 08:00 UTC =
photo **et** ouverture de Londres : même règle. Les sessions définies en heure locale sont **exclues des contrôles** à ± 60 min
(liste gelée, §7.1) ; aucun type « Europe » n'est testé.

---

## 2. Prérequis bloquants (responsable entre parenthèses)

### 2.1 Décisions et calendrier

| Id | Prérequis | Responsable | Preuve | Sans quoi |
|---|---|---|---|---|
| **P-G5-1** | **Confirmation écrite de l'échelle de la lecture** sous §9.4 (quant M8) : 25 X-Perps (les 4 sans jour éligible ne sont pas récupérés) ; `funding-rate-history` ≈ 50 requêtes avant le gel ; après le gel, `history-candles` ≈ 13 000 requêtes (depuis l'éligibilité − 1 j), `history-mark-price-candles` et `history-index-candles` ≈ 22 600 chacun, bornés à [D_cov − 1 j ; END_EXCLUSIVE) ; total **≈ 58 300** (calcul d'audit §4.3, D_cov = 2026-08-04) ; facteur de cadence 0,8 (≈ 2,8 h) ou **0,4 proposé** (≈ 5,6 h), choix du propriétaire ; une IP ; stockage `tradingV3-private`. Le texte de la confirmation devient le `--decision-ref` du fetcher et fixe `--max-requests` | propriétaire | phrase datée au registre | aucun fetch (`g5-fetch` sort en code 2 sans `--decision-ref`) |
| **P-G5-2** | **Verdict `fwd-okx-r1` rendu** (après le 2026-10-31) : rien de G5 ne tourne avant (`GATES` §8 ; plan §5 d.11) | — | `fwd-okx-r1` clos | pas de fetch, pas de gel |
| **P-G5-3** | **Fetch aveugle du funding seul avant le gel** (quant M7) : `funding-rate-history` est le seul flux qui se périme (« up to three months », docs-v5) ; il est récupéré **le jour du verdict** en mode **scellé** (P-G5-11 (B) xix) : console et `fetch.log` ne portent que des totaux globaux (jobs, jobs complets, jobs en erreur) et D_cov ; le manifeste par instrument (lignes, bornes, pages), qui révélerait les escalades 2 h / 1 h donc des taux au plafond, est écrit dans un fichier **scellé**, haché, ouvert par aucun agent avant l'exécution ; ligne de registre `unread` avec sha256 avant le gel ; la PR de gel cite ces hashes. Bougies, mark et indice sont récupérés **après** le gel (`GATES` §9.5, lecture littérale). Chaque jour d'attente fait perdre ≈ 3 instants T01 (§4.4) | propriétaire | décision datée | perte de 3 instants T01 par jour de délai |
| **P-G5-4** | **Univers** (quant M9) : règle par métadonnées appliquée au snapshot `public/instruments` (`instType FUTURES`, hôte EEE) relevé **après le 2026-10-31** (haché) : `ruleType xperp`, sous-jacent crypto `<BASE>-USD`, `listTime + 30 j < 2026-10-14T00:00Z`, intersectée avec la liste D1 (enveloppe de licence) ; attendu : les 25 instruments « analysés » de §3.1 (sinon le spec est corrigé au gel et l'écart écrit) ; **dérogation écrite du propriétaire** nommant l'exception : « liste D1 issue d'un volume du 2026-10-14 (quarantaine, `GATES` §9.5, M11), sélection sur la liquidité de fin de fenêtre ; biais déclarés §3.1 » ; aucun instrument ajouté par un volume ; retraits constatés et comptés | a2 / propriétaire | snapshot haché, Annexe I, phrase datée | gel impossible |
| **P-G5-5** | **Calendriers et sessions gelés en listes explicites** (Annexe C, spec `calendars`, `sessions`, `macro_filter`) : fériés NYSE ; dates CPI et Employment Situation (BLS) ; FOMC (Fed) ; **re-vérifiés** sur bls.gov / federalreserve.gov / nyse.com au gel (`freeze_fill.calendars_verified_utc`) ; à relever en plus : fenêtre CME CF BRR (15:00–16:00 Londres, publication le week-end UNKNOWN), demi-séances NYSE, liste descriptive des publications de 10:00 ET (JOLTS, ISM) pour la strate §9.5 ; calendriers 2026-11 → 2027-04 pour le Gate 2 | a1 | listes dans les specs (relevées le 2026-10-22, Annexe C ; restent §2.4 lignes 5 et 15) | T05 et les contrôles indéfinis |
| **P-G5-6** | **Registre** : `tradingV3-private/research/20261020T1430Z-lp-programme/REGISTRY.md` **existe** (présence du fichier constatée le 2026-10-21 en fin de journée, contenu non relu dans cette passe) ; à créer avec les lignes `okx-xperp-1m-history-2026-03-30..2026-10-13` (`unread` → `consumed_equivalent` au jour de la lecture, D_cov et sha256 du fetch funding scellé inscrits) et `okx-xperp-prospective-G5` (`prospective`) | a1 / a3 | lignes | gel impossible |
| **P-G5-7** | **Amendements de `LP-PROGRAMME-GATES.md` et du plan** (quant m3, m4), hors du périmètre d'écriture de cette passe, **avant** le gel : §2.3 m_max = 3 + 2K = **7** (G2-C2, G4-Gate2, G1, G5-Gate1-T01, G5-Gate1-T05, G5-Gate2-T01, G5-Gate2-T05), `PASS_PROGRAMME` immédiat si p ≤ 0,10/7 ≈ 0,0143 ; §1.1 « × 365 » → « × jours/an du calendrier du type (365 continu, 252 NYSE) » ; §3.1 « blocs de 3 600 s pour les instants » → G5 : blocs d'un jour UTC ; §3.3 et plan §2.2 « ≈ 600 instants par photo » → « ≤ 211 × f_inst (T01, D_cov), 105 (T05) » | a3 | textes amendés | Holm du programme et porte économique faux |
| **P-G5-8** | **Porte économique** : S_éco = **97,25** USD/an (D4 ; règle unique de `GATES` §1.2 amendé le 2026-10-21 : max(97,25 ; 5 × SOFR du jour du gel × 500 ; coût fixe déclaré)), inscrite ici (§7.6) et dans les deux specs (`economic.s_eco_usd_per_year`, le lecteur refuse moins : capacité `economic_s_eco_at_least_d4`, décision (a) du 2026-10-22) ; coût fixe déclaré de G5 Gate 1 ≈ nul (code écrit, ≈ 3–6 h de requêtes, stockage de l'ordre de quelques centaines de Mo) ; Gate 2 : part du coût de la capture continue (`GATES` §1.2) | propriétaire | fait (D4 ; specs le 2026-10-22) | — |
| **P-G5-9** | **Gel avant la lecture de G2** (P-G2-10) : sinon G2 §7.6 (heure / session) devient le screen de G5 et compte dans m ; conséquence : la table de coût G2 **ne peut pas exister** au gel du Gate 1 (§6) | a1 / propriétaire | horodatages de gel | G5 post-screen |
| **P-G5-10** | **Gate 2** : capture continue en cours (P3, D1) ; spec Gate 2 gelé **en même temps** que le Gate 1 (piège 7) ; décisions du propriétaire, **prises le 2026-10-21** : (i) fin de fenêtre T05 Gate 2 — **D7 : option (a)**, fin au **2027-03-31**, aucune prolongation ; T05 au Gate 2 est `NOT_INFORMATIVE` **par construction** (≈ 91 instants au plus pour un T0 atteignable ≥ 2026-11-03, plancher 100 ; §10.3) et le lecteur l'écrit (audit `power_by_construction`, raison `power_floor_unreachable_by_construction`) ; l'option 2027-04-12, refusée, n'atteignait pas 100 non plus pour un T0 atteignable (≈ 97–98 au mieux ; le texte antérieur « ≥ 2027-04-12 ⇒ ≥ 100 instants » était faux, relecture review-b M2) ; (ii) **source d'indice à la minute** pour P̂ — **D10 : les deux sources** : (a) canal public `index-tickers` ajouté à la capture (extension de D1 ; `xperp_capture.CHANNELS`, layout `index_tick`) et (b) `history-index-candles` récupéré **une fois après la fin de la fenêtre** par le fetcher dédié `g5_index_fetch` (écrit et testé le 2026-10-21, §10.1) ; primaire choisie par la règle de qualité de §10.1 avant toute évaluation de T01 ; sans source disponible, T01 Gate 2 est `INCONCLUSIVE` par construction (écrit d'avance). Recopie faite dans le spec `0.1.5-draft` (blocs `owner_decided`, textes datés dans `freeze_fill.decision_ref_p_g5_10_i` / `_ii`) | propriétaire | D7, D10 (`LP-OWNER-DECISIONS-2026-10-21.md`) | **fait** ; reste au gel : `freeze_fill.t05_end_inclusive_utc = 2027-03-31` |
| **P-G5-11** | **Code (B)** ci-dessous, livré et testé hors ligne ; relecture du code (CTO) refaite ; état (A) re-constaté au gel. **État au 2026-10-21 (soir)** : toutes les capacités `requires` des deux specs sont implémentées (le lecteur strict refuse sinon ; les deux brouillons sont acceptés) ; fetcher de l'indice du Gate 2 écrit (`g5_index_fetch`, CTO review-b M3 levé) ; valeur `moot_track_s_closed` lue ; demandes de code 11, 13, 14, 16 et 18 de l'Annexe B **codées le 2026-10-22** (décision (a) du propriétaire ; (B) xxiv–xxv ; commit `4dd1f6e8`) ; restent la relecture CTO du code (B) et le digest `cli.code_digest()` au gel (§2.4) | a2 | digests, suite verte | gel impossible |

### 2.2 Code — (A) existant, re-constaté le 2026-10-21 vers 02:50 (lecture du code et tests hors réseau ; aucune donnée)

- Digests sha256 identiques à ceux de fin de passe a2 (`a2-code/NOTES.md` §1) et à l'état relu par le CTO : `g5_eval.py`
  `3224cfdf…31dd46`, `g5_fetch.py` `32fa8ae3…cf07b`, `cli.py` `3c98c059…acfcc171`, `tape.py` `33ccd656…dd6e5f`, `events.py`
  `53252f3f…a946e9d`, `lp_sim.py` `a75eefd2…f6646b1`, `xperp_capture.py` `b1a09f70…26d298`, `__init__.py` `6704e3e2…cd8b17` (valeurs
  complètes dans `a1-protocol/NOTES.md`). L'affirmation de la première version « aucun test de `g5_eval` n'existe » est **périmée**
  (quant m6) : `tests/test_research_lp_g5_eval.py` (9 tests) et `tests/test_research_lp_g5_fetch.py` (9 tests) existent.
- Suite `tests/test_research_lp_{capture,events,sim,g5_fetch,g5_eval}.py` rejouée hors réseau (runtime privé
  `20261014T090000Z-swing-v2-runtime`, Python 3.12.3, numpy 2.5.4, `--noconftest`) : **56 passed, 2 skipped** (les deux tests de
  capture qui importent `websockets`, absent de ce runtime ; le CTO compte 58 passed sous un autre runtime). Python 3.11 : syntaxe
  vérifiée par a2 (`ast.parse(feature_version=(3, 11))`), exécution sous 3.11 UNKNOWN.
- `tape.HISTORY_LAYOUTS = ("candles_1m", "mark_1m", "index_1m", "funding_hist")` ; `candles_1m` porte `open`, `high`, `low`,
  `close`, **`vol`**, `vol_ccy`, `vol_ccy_quote`, `confirm` (`tape.py` l.93–95 : le proxy de fill et la règle `vol = 0` sont
  calculables sans nouveau champ) ; `funding_hist` porte `funding_rate` (prévu), `realized_rate` (réel), `method`, `formula_type`.
- `g5_fetch.END_EXCLUSIVE = "2026-10-14T00:00:00Z"` est une **constante de module** sans option ni crochet de test ; `EndGuard.admits`
  refuse toute ligne ≥ cette borne **avant** le store et compte les refus ; pagination `after` vers le passé ; limiteurs à 0,8 × les
  limites documentées ; backoff ; reprise ; `--dry-run` n'ouvre rien ; **`--decision-ref` obligatoire** (sinon code 2). Constats CTO
  ouverts : troncature silencieuse (M1), échelle non bornée (M3), compteurs remis à zéro (m1), `--force` inopérant (m2).
- `g5_eval` (version actuelle) : `parse_spec` **ignore les clés inconnues**, prend le **mark** comme prix (repli dernier prix),
  n'accepte `field_sign` que sur `premium`, tire des contrôles à une minute quelconque, compte n en instrument-instants, n'a ni
  C2–C7 ni `INCONCLUSIVE`, nomme `pass_programme` la confirmation Holm **locale** (CTO B1, B3 ; quant B4). Sonde du 2026-10-21 :
  `parse_spec` **refuse** le nouveau spec (`SpecError: spec: missing key 'alpha'`) — la version actuelle ne peut donc pas
  l'exécuter (échec fermé).
- `lp_sim.event_decisions` : décision à `arm_ts_us`, ordre vivant jusqu'à `end_ts_us` (CTO M2 de G2) ; `FundingSchedule.crossed`
  (règlements `open < T ≤ close`) ; `events.us_dst_active` / `us_open_utc_minutes` (DST US par date) ; `cli.code_identity()`.

### 2.3 Code — (B) à écrire avant le gel (prérequis **P-G5-11**, responsable a2 ; tests synthétiques hors ligne)

Le spec nomme ces fonctions dans `requires` ; l'évaluateur **refuse** un spec qui exige une fonction qu'il n'implémente pas, et
toute clé inconnue (xviii). Les constats CTO B1–B3, M1–M3 et m1–m7 sont intégrés aux lignes concernées.

| # | Fonction (clé `requires` du spec) | Pourquoi |
|---|---|---|
| i | `price_source_last_only` : prix de référence = `candles_1m.close` ; mark et indice réservés au classifieur ; tout rendement dont les deux bouts n'ont pas la même source est refusé | §5.3 ; CTO B1-i |
| ii | `generator_funding_settlements_interval_filter` : instants de T01 = `fundingTime` de `funding_hist` ; retenu si les écarts au règlement précédent **et** au suivant valent 8 h ± 1 min ; voisin inconnu (premier règlement servi, ou suivant ≥ END_EXCLUSIVE) ⇒ exclu et compté ; `realized_rate` requis (ligne sans lui exclue, comptée ; jamais le taux prédit — CTO B1-ii) | §5.1 |
| iii | `funding_coverage_window` : D_cov lu dans le manifeste (`coverage_start_us`, `end_reason` par flux — CTO M1) ; T01 événements **et** contrôles restreints à D_cov ; D_cov inconnu ⇒ T01 `INCONCLUSIVE` | §4.4 |
| iv | `classifier_premium_wavg_at_arm` : P̂(t_arm) = Σ_{k=1}^{480} k·π(t_arm − (480 − k) min) / Σ k sur les bornes présentes, ≥ 90 % présentes sinon exclu (compté) ; +1 si P̂ ≥ +0,0009, −1 si P̂ ≤ −0,0009, exclu sinon ; **même fonction** aux événements et aux contrôles ; une seule grandeur nommée (`premium_mark_index`), jamais le champ `premium` du canal (CTO M2) | §5.1, quant B1 |
| v | `classifier_validation_confusion_3x3` : matrice (classe de P̂ × classe de R_h, seuils ±0,0009 et ±0,0004) sur les instrument-règlements bruts de T01 ; sensibilité, taux de faux positifs, dénominateurs ≥ 30 ; C6 | §7.2 |
| vi | `fill_proxy_through_candles` (T01, événements et contrôles) : long rempli si min(`low`) des bougies ouvertes de t_arm à h (6 bougies, [h−5 ; h+1)) < c(t_arm) − `tick_sz` ; short si max(`high`) > c(t_arm) + `tick_sz` ; non rempli ⇒ exclu du primaire, compté, taux de fill rapporté | §5.4, quant M11 |
| vii | `eligibility_list_time_plus_days` : un instrument-instant n'existe que si le début de son empreinte est ≥ `listTime + 30 j` (événements **et** contrôles), compté (`excluded_ineligible`) ; `listTime`, `tick_sz`, `ct_val` lus dans le spec et vérifiés contre le snapshot du gel (divergence ⇒ refus) | §3.2, quant B3 |
| viii | `candle_confirm_and_vol_zero_exclusion` : lignes `confirm = 0` écartées et comptées (CTO m5) ; instrument-instant exclu si une **bougie clé** (T01 : celles qui finissent à h−5 et h+15 ; T05 : à h, h+5 et h+60) a `vol = 0` ; part exclue par instrument rapportée | §4.5, quant M3 |
| ix | `unit_instant_equal_weight` : valeur d'un instant = moyenne des instruments retenus ; D = moyenne des instants d'événement − moyenne des instants de contrôle ; n = instants uniques ; `min_instruments_per_instant = 1` ; distribution du nombre d'instruments par instant et strate ≥ 3 rapportées | §7.3, quant M2 |
| x | `generator_local_time_exclude_dates` : T05 = 09:30 `America/New_York` des jours NYSE de la liste gelée, hors dates CPI et Employment Situation (CTO B1-vii) | §5.2 |
| xi | `controls_minute_of_hour_all_admissible` et `exclusion_by_footprint_with_margin` : contrôles d'un type à sa `minute_of_hour` (T01 : 0 ; T05 : 30), **toutes** les heures admissibles des jours d'événement de l'instrument (T01 : dans D_cov) ; admissible si l'empreinte du contrôle est à ≥ 60 min de **toute** empreinte de l'union d'exclusion (règlements connus de l'instrument, grille par défaut du type, instants T05, sessions, macro) ; aucun tirage aléatoire | §7.1, quant B2, M5 |
| xii | `sessions_local_time_dst` et `macro_filter_new_york_time` : sessions et publications converties depuis l'heure locale (`zoneinfo` ; New York vérifié contre `events.us_dst_active`) | §7.1, quant M4, m8 |
| xiii | `costs_by_execution_role` : `maker_maker` (T01) / `taker_maker` (T05), sélection adverse 3,5 pb par jambe maker **dont la sélection adverse est supposée** (amendement A1 : la jambe d'entrée de T01, conditionnée au remplissage, est mesurée, 0 supposé), glissement 2 pb, demi-spread d'entrée preneur 3 pb, stress × 1,5 sur **tous** les termes de coût ; le spec déclare le total attendu (9,5 / 15,5 pb ; 13 pb pour T01 avant A1, gardé en `cost_sensitivity_pre_a1`) et l'évaluateur vérifie l'égalité (CTO B1-vi) | §6, quant M6, A1 |
| xiv | `funding_unknown_outside_coverage_counted` : funding d'une fenêtre hors D_cov supposé nul et compté (`funding_unknown`) ; funding des contrôles calculé (attendu nul ; non nul compté comme anomalie) | §4.4, quant M5 ; CTO M1 |
| xv | `verdict_c1_c7_precedence` : précédence `INCONCLUSIVE` > `NOT_INFORMATIVE` > critères ; C1–C7 (§7.5) ; annotation du FAIL (UB90 ≥ / < +10 pb) ; lecture « poursuivi » (CTO B3) | §7.5, §8 |
| xvi | `holm_local_and_programme_fields` : `holm_local_confirmed` (K = 2) et `pass_programme_immediate` (p ≤ 0,10/7) ; le champ `pass_programme` disparaît (quant B4) | §7.5 |
| xvii | `no_information_after_decision` : refus de tout champ de condition, de classifieur ou de signe observé après `arm_min` (seuls le fill, la sortie et le rendement sont postérieurs par nature) (CTO B2) | §0.8 |
| xviii | `spec_schema_g5_v2_strict_keys`, `status_frozen_required_for_market_data`, `end_exclusive_constant_check`, `universe_from_spec_checked_against_snapshot` : liste blanche de clés, `requires` couvert, statut `FROZEN` et `freeze_fill` non nul exigés pour lire une donnée de marché (sinon tests synthétiques seulement), `end_exclusive_utc` du spec égal à `g5_fetch.END_EXCLUSIVE` | CTO B1-xii |
| xix | **Fetcher** : `--inst-ids` (liste hachée) et `--max-requests` obligatoires pour un vrai run, refus si le plan dépasse ; départ par flux (bougies : éligibilité − 1 j ; mark / indice : max(D_cov − 1 j, éligibilité − 1 j)) ; mode **scellé** pour le funding pré-gel (totaux globaux et D_cov seulement à l'écran et au journal, manifeste par instrument dans un fichier scellé haché) ; `end_reason` et `coverage_start_us` ; `data` non-liste traité comme transitoire puis fatal ; compteurs cumulés ; `--force` réel (CTO M1, M3, m1, m2, m3) | §4.3, P-G5-3, quant M7, M8 |
| xx | `sealed_statistics_for_inconclusive_and_not_informative` : pour un type `INCONCLUSIVE` ou `NOT_INFORMATIVE`, D, LB90, UB90, p, moyennes, critères et descriptifs du type écrits dans `sealed/<type>.json`, sha256 imprimé, rien d'autre ; `economic_reading_days_per_year` (365 / 252) ; `bootstrap_day_blocks` (10 000 rééchantillons ; sensibilité 7 jours descriptive) ; `descriptive_reports_g5_v2` (§9, avertissement en tête du Markdown, CTO m7) | §7.3, §7.6, §8, quant B5, m1 |
| xxi | **Gate 2** : fill dans [h ; h+1) compté comme traversant la photo ; entrée **preneur** de T05 au touch opposé + glissement dans `measure_lp_sim` ; classifieur P̂ sur mark de capture et indice (P-G5-10 ii) ; validation C6 avec R du canal `funding-rate` | §10 |
| xxiii | **Amendement A1** (capacités `a1_*`, Gate 1) : `a1_entry_adverse_selection_measured_by_fill_proxy` (clé `adverse_selection_entry_leg`, `type_cost_terms`, sensibilité `cost_sensitivity_pre_a1`), `a1_footprint_boundaries_counted` (`crosses_end_exclusive`, éligibilité, événements et contrôles), `a1_descriptive_abc_signal_execution_net`, `a1_descriptive_filled_vs_unfilled_signal`, `a1_descriptive_taker_variant_without_fill_conditioning`, `a1_descriptive_chronological_stability`, `a1_diagnostic_classification_reported_never_a_verdict` (bloc `descriptive.a1`, lu mot pour mot) ; tests `tests/test_research_lp_g5_a1.py` | section Amendement A1 |
| xxii | **`tests/test_research_lp_g5_eval.py`** étendu (synthétique, hors ligne, garde `socket` au niveau du module — CTO m6) : calibration sous H0 sur ≥ 1 000 jeux (marche aléatoire avec rebond bid-ask, sursauts de minute 0, chocs de jour partagés ; 1 000 rééchantillons dans les tests, déclaré) : taux de {LB90(D) > 0} ≤ 0,10 + 3 erreurs types Monte-Carlo, uniformité de p (Kolmogorov-Smirnov, non rejetée à 1 %), FWER de Holm ≤ 0,10 + 3 erreurs types ; **non-régression de B2** : sursaut et rebond plantés à la minute 0, événement à la minute 30 ⇒ D sans biais (valeur absolue de la moyenne ≤ 3 erreurs types) avec les contrôles à la minute 30, biais détecté avec des contrôles à la minute 0 ; effet planté de +30 pb : **puissance rapportée** (pas un booléen) ; éligibilité, `vol = 0`, proxy de fill, classifieur à t_arm, refus d'un champ après la décision, clé inconnue refusée, scellement, constante END (quant m7) | gel impossible sans |
| xxiv | **`gate2_frozen_together`** (Gate 1 ; Annexe B demande 11 ; relecture review-b quant B1 ; décision (a) du propriétaire du 2026-10-22) : avant toute donnée de marché, le run du Gate 1 lit le spec Gate 2 de `frozen_with.spec` (ou `--gate2-spec`) et refuse de tourner (`refuse_run`, rien lu) s'il n'est pas `FROZEN`, s'il garde une valeur `owner_decision_pending`, un marqueur `freeze_pending:` ou un `null`, si le lecteur strict du Gate 2 le refuse face à ce Gate 1, ou si son `freeze_fill.gate1_spec_sha256` diffère du sha256 du spec Gate 1 lu (`g5_gate2.check_frozen_together`, `g5_eval.command_eval`) ; le sha256 du Gate 2 est imprimé et écrit dans le résultat (`gate2_spec`), puis inscrit au registre le jour du run ; bloc `frozen_with` du spec Gate 1 (chemin et règle lus mot pour mot) | §13 point 5 ; piège 7 ; Annexe B `frozen_with` |
| xxv | **Contrôles de gel** (deux portes ; Annexe B demandes 13, 14, 16 et 18 ; décision (a) du 2026-10-22) : `calendars_content_test_at_freeze` — en `FROZEN`, sur R = [T0 ; dernier jour scoré + prolongation pour trous] (Gate 1 : la fenêtre du spec), au moins une date CPI et une date Employment Situation par mois civil qui touche R, sauf exception datée et sourcée (`calendars.content_exceptions` : report, fermeture des services fédéraux) ; une date FOMC dans toute fenêtre de 56 jours de R ; fériés NYSE de R égaux au générateur par règles (`g5_eval.nyse_holidays_by_rule` : Pâques calculé, n-ièmes lundis, Thanksgiving = 4ᵉ jeudi de novembre, jours reportés, Jour de l'an d'un samedi non reporté), aux exceptions écrites près ; demi-séances listées (`calendars.nyse_half_days`, jours de bourse NYSE) et exclusion `us_cash_close` déplacée à 13:00 ET ces jours-là (`sessions[].on_nyse_half_days`) ; texte `calendars.content_rule` lu mot pour mot ; problèmes rapportés à tout statut (`audit.calendar_content`), refus en `FROZEN` seulement — `freeze_pending_marker_refuses_frozen` : aucune clé ni valeur contenant `freeze_pending:` en `FROZEN` (`g5_eval.refuse_freeze_pending`) — `economic_s_eco_at_least_d4` : S_éco ≥ 97,25 à tout statut (`g5_eval.check_s_eco`) — descriptif `T01_previous_settlement_filter_only` (Gate 1 : T01 retenu sur l'écart au seul règlement précédent, critère du suivant coupé ; jamais décisif ; §9.4, §11.1) | P-G5-5, §7.6, §11.1 ; `GATES` §1.2 |

### 2.4 État au 2026-10-22 et ce qui bloque encore le gel

Passe avant gel du 2026-10-21 (branche `claude/g5-gate2-prefreeze`, aucune donnée de marché, aucun réseau) : spec Gate 2 `0.1.5-draft`
(D7 (i) et D8 recopiées, `moot_track_s_closed` lu, textes datés dans `freeze_fill.decision_ref_p_g5_10_i` / `_ii` / `decision_ref_p_s3`,
plus aucune valeur `owner_decision_pending`) ; T05 au Gate 2 `NOT_INFORMATIVE` par construction écrit par le lecteur (§10.3) ; fetcher de
l'indice du Gate 2 `g5_index_fetch` (§10.1) ; repli REST du funding : **pas** un prérequis de code — le gel écrit
`costs.funding.fallback = none…` (choix (7) de l'Annexe B ; le lecteur refuse tout autre texte en `FROZEN`) ; fill du Gate 2 : biais
`touch` déclaré (§11.1) ; §8 précise ce que `NOT_INFORMATIVE` / `INCONCLUSIVE` ne disent pas.

Passe du 2026-10-22 (même branche, aucune donnée de marché, aucun réseau ; décisions du propriétaire du jour) : **(a)** « Finir les contrôles G5 réalisables dès maintenant »
— demandes de code 11, 13, 14, 16 et 18 de l'Annexe B codées et testées ((B) xxiv–xxv ; ligne 7 ci-dessous) ; **(b)** « Choisir la traversée comme remplissage principal du Gate 2, et garder le simple contact comme comparaison descriptive »
— `through` primaire au Gate 2, `touch` comparaison descriptive (ligne 9 ci-dessous ; §10.2, §11.1). Spec Gate 1 `0.4.0-draft`, spec
Gate 2 `0.1.6-draft` (sha256 informatifs en Annexes A et B) ; commit `4dd1f6e8`. Sur ces brouillons, le test de contenu rapportait
(sans refuser, `audit.calendar_content`) : Gate 1 — aucune date CPI en octobre 2026 (2026-10-14, Annexe C, était hors de la liste) ;
Gate 2 — CPI 2027-01 → 03 et Employment Situation 2026-11 → 2027-03 absents ; huit marqueurs `freeze_pending:` restaient au Gate 2.

Passe calendriers et rédaction du 2026-10-22 (après-midi ; même branche ; aucune donnée de marché ; réseau limité aux pages officielles
des calendriers, Annexe C) : listes des deux portes relevées sur bls.gov, federalreserve.gov et nyse.com le 2026-10-22, sources datées
dans `calendars.sources` ; le test de contenu ne rapporte **plus aucun problème** aux deux portes (Gate 1 : CPI 2026-10-14 inscrite ;
Gate 2 : CPI, Employment Situation, FOMC 2027-04-28 et demi-séances 2026-11-27 / 2026-12-24 inscrits) ; `calendars.incomplete_at_draft`
du Gate 2 réduit à deux entrées **bloquantes** (lignes 5 et 15) ; les huit marqueurs `freeze_pending:` retirés, les choix (1) à (7) de
l'Annexe B et les écritures M1, M3, m1, m3, m4 étant au corps (§7.4, §8, §10.1 à §10.4 ; lignes 8 et 13) ; `costs.funding.fallback`
écrit `none…` ; texte `costs.stress_rule_status` corrigé (+4,75 pb au Gate 1 depuis A1 et non +6,5 pb, valeur d'avant A1). Borne de T05
au Gate 2 sur les listes complètes : 92 au T0 provisoire du brouillon, 91 au plus pour un T0 atteignable (§10.3). Spec Gate 1
`0.4.1-draft`, spec Gate 2 `0.1.7-draft` (sha256 informatifs en Annexes A et B). **État au 2026-10-22, fin de passe — restent, tous
bloquants** :

| # | Bloque le gel | Responsable | Preuve |
|---|---|---|---|
| 1 | Verdict `fwd-okx-r1` (après le 2026-10-31) | — (P-G5-2) | §2.1 P-G5-2 ; §13 point 1 |
| 2 | Fetch scellé du funding le jour du verdict, reçu haché, D_cov au registre ; `freeze_fill.funding_seal_receipt_sha256` du Gate 1 | propriétaire (lancement daté) ; a2 | §2.1 P-G5-3 ; §13 points 1 et 5 |
| 3 | Snapshot `public/instruments` relevé **après** le 2026-10-31, haché ; Annexe I ; `universe.analysed` des deux specs ; `freeze_fill.instruments_snapshot_*` | a2 ; propriétaire (D6 accordée) | §2.1 P-G5-4 ; §13 point 2 |
| 4 | Comptages P3 (`selection`, univers ≤ 40 du Gate 2) et `freeze_fill.p3_counts_selection_sha256` ; T0 = premier 00:00 UTC ≥ max(plancher, gel, fin de P3) ; **depuis P-L2-1 (D14)** : P3 compte au plus tôt à partir du plancher de séparation 2026-11-03T12:00Z (`research_lp count` refuse avant), jamais pendant le pilote, et T0 ≥ 2026-11-04 (§10.6) | a2 (capture ≥ 2026-11-02, canal `index-tickers` compris ; lignes antérieures au 2026-11-03T12:00Z jamais lues par le Gate 2) | spec Gate 2 `window.start_rule`, `freeze_fill` |
| 5 | Calendriers — **listes relevées le 2026-10-22** (Annexe C ; test de contenu sans problème aux deux portes) ; restent : (a) `freeze_fill.calendars_verified_utc` aux deux specs (re-lecture des pages officielles au gel) et session `cme_cf_brr_window` (`verify_at_freeze`) aux deux specs ; (b) **liste des publications de 10:00 ET** (JOLTS, ISM ; strate descriptive de §9.5, P-G5-5) : bls.gov ne publie aucune date JOLTS de janvier à avril 2027 (calendrier 2027 modifié le 2026-10-09) ; le calendrier ISM d'ismworld.org renvoie à une page de connexion ; aucun champ de liste dans les specs et strate `T05_ten_am_et_release_days` `UNAVAILABLE` dans `g5_eval` ; entrée `calendars.incomplete_at_draft.ten_am_et_releases` du Gate 2 (le Gate 1 n'a pas ce bloc : sa strate est aussi `UNAVAILABLE`, JOLTS de sa fenêtre relevés en Annexe C, ISM non relevé) — à lever par le relevé des dates une fois publiées, le champ et la strate codés, ou par une décision datée qui retire la strate de P-G5-5 | a1 (relevé) ; a2 (champ, strate) ; propriétaire (retrait éventuel) | §2.1 P-G5-5 ; Annexe C ; spec Gate 2 `calendars.incomplete_at_draft` ; `g5_eval` (`_ten_am_et_release_days`) |
| 6 | Registre `REGISTRY.md` : le fichier existe (constaté le 2026-10-21) ; présence des lignes `unread` / `prospective` à vérifier au gel | a1 / a3 | §2.1 P-G5-6 ; plan §2.2 |
| ~~7~~ | ~~Code (Annexe B, demandes 11, 13, 14, 16, 18) : capacité `gate2_frozen_together` au Gate 1 ; test de contenu des calendriers aux deux portes ; refus de `FROZEN` sur `freeze_pending:` ; S_éco ≥ 97,25 dans les deux specs (97,0 aujourd'hui) ; descriptif T01 filtré sur le seul règlement précédent~~ — **fait le 2026-10-22** (décision (a) du propriétaire, commit `4dd1f6e8`) : `gate2_frozen_together` (`g5_gate2.check_frozen_together`, `g5_eval.command_eval`, option `--gate2-spec`, bloc `frozen_with` du spec Gate 1) ; test de contenu (`g5_eval.calendar_content`, `calendars.content_rule`) ; refus de `FROZEN` sur le marqueur (`g5_eval.refuse_freeze_pending`) ; S_éco = 97,25 dans les deux specs et refus de moins (`g5_eval.check_s_eco`) ; descriptif `T01_previous_settlement_filter_only` ((B) xxiv–xxv) | ~~a2~~ | Annexe B, table « Code demandé » (lignes 11, 13, 14, 16, 18 faites) |
| ~~8~~ | ~~Huit marqueurs `freeze_pending:` du spec Gate 2 (choix (1) à (7) de l'Annexe B) : écrits au corps (§10, §11.1) ou retirés~~ — **fait le 2026-10-22** (passe calendriers et rédaction) : (1), (2), (6), (7) en §10.2 ; (4) en §10.3 ; (3) en §10.4 ; (5) en §10.1 ; marqueurs retirés du spec `0.1.7-draft` (`notes.freeze_pending_markers` vide) ; `costs.funding.fallback` commence par `none` ; le lecteur refuse toujours `FROZEN` sur un marqueur (demande 14) | ~~a1~~ | Annexe B « Choix de rédaction » ; spec Gate 2 |
| ~~9~~ | ~~Règle de fill primaire du Gate 2 (`touch`, borne optimiste, contre `through` au Gate 1) : garder déclarée ou changer~~ — **résolu par le propriétaire le 2026-10-22** (décision (b) : « Choisir la traversée comme remplissage principal du Gate 2, et garder le simple contact comme comparaison descriptive » ; commit `4dd1f6e8`) : `execution_sim.entry_fill_rule = through`, `touch` et `queue` descriptives (`fill_rules_touch_and_queue`), jamais décisives ; le lecteur refuse un autre primaire (`g5_gate2.PRIMARY_FILL_RULE`) | ~~a1 / relecture quant~~ propriétaire | §10.2, §11.1 ; spec `execution_sim.entry_fill_rule`, `entry_fill_rule_decision` |
| 10 | Texte `FROZEN` de `costs.funding.source` = `g5_gate2.FUNDING_SOURCE`, après l'échantillon de schéma du canal `funding-rate` (aucun taux) | a2 / a4 | Annexe B, ligne `costs.funding` |
| 11 | Relecture CTO du code (B), `g5_index_fetch` compris ; digest `cli.code_digest()` recalculé et recopié aux deux `freeze_fill` | CTO ; a2 | §2.1 P-G5-11 ; §13 point 4 |
| 12 | Valeurs nulles restantes : Gate 2 `t0_utc`, `gate1_spec_sha256`, `instruments_snapshot_*`, `p3_counts_selection_sha256`, `calendars_verified_utc`, `code_digest_sha256`, `decision_ref_d1_capture` (texte de D1), `t05_end_inclusive_utc` (= 2027-03-31) ; Gate 1 `decision_ref_p_g5_1` (texte de D5) et les valeurs des points 2, 3, 5, 11 ; constat du 2026-10-22 : `decision_ref_p_g5_1` (texte de D5), `decision_ref_d1_capture` (texte de D1) et `t05_end_inclusive_utc` (2027-03-31, D7) ne dépendent pas du gel (textes datés dans `LP-OWNER-DECISIONS-2026-10-21.md`) et peuvent être recopiés avant lui, comme les trois `decision_ref_*` du Gate 2 — non faits par cette passe ; les autres valeurs dépendent du gel (T0, `gate1_spec_sha256`, snapshots, P3, digest, `calendars_verified_utc`, reçu du funding scellé) | a1 / a2 au gel | specs `freeze_fill` |
| ~~13~~ | ~~Corps : écritures signalées à a1 par la relecture review-b (M1 §8 `pass_effect`, M3 §10.1, m1 §10.4, m3 §10.3, m4 §7.4)~~ — **fait le 2026-10-22** (passe calendriers et rédaction) : M1 en §8 (conjonction `G5_GATE2_PASS` + `PASS_PROGRAMME` de `G5-Gate2-<type>` + « PASS, poursuivi »), M3 en §10.1 (sémantique du funding sur la documentation et un échantillon de schéma, aucun taux), m1 en §10.4 (aucun scellement au Gate 2, protocole successeur), m3 en §10.3 (prolongation pour trous telle que codée, nulle sous D7), m4 en §7.4 (variance des contrôles) ; B1 et m12 faits plus tôt le même jour ((B) xxiv, §11.1) | ~~a1~~ | Annexe B, table de traitement |
| 14 | Gel avant la lecture de G2 ; PR de gel publiée avant tout fetch des bougies, du mark et de l'indice du Gate 1 | a1 / propriétaire | §2.1 P-G5-9 ; §13 points 5–6 |
| ~~15~~ | ~~**Règle d'une date de calendrier changée après le gel** (constat de la passe du 2026-10-22 : dates FOMC « tentative » jusqu'à la réunion précédente, calendrier BLS mis à jour « as needed », aucune règle pour une date déplacée, ajoutée ou annulée après le gel)~~ — **résolu le 2026-10-22 (soir)** : règle déterministe écrite en §10.5 (instantanés officiels datés et hachés, classement à la date de décision, union des versions, instantané final exigé, journal de chaque changement, dates non publiées jamais inventées) ; spec Gate 2 `0.1.8-draft`, bloc `calendars.versioning` lu mot pour mot, capacité `calendars_versioned_as_of_union` (`research_lp/calendar_versions.py`, `g5-eval --calendar-snapshots`, tests `tests/test_research_lp_separation_calendars.py`) ; entrée `calendars.incomplete_at_draft.fomc_statement` retirée ; la règle ne touche ni la fenêtre ni les listes gelées | ~~a1~~ | §10.5 ; spec `calendars.versioning` |

Hors blocage : P-G5-7 (amendements de `GATES` et du plan, faits le 2026-10-21, `GATES` §1.1, §2.3, §3.1 ; plan §2.2) ; P-G5-8 (D4) ;
P-G5-10 (décidé, ci-dessus) ; champ P-S3 du spec Gate 1 (Annexe B, demande 12 : sans objet sous D8, le spec Gate 1 n'a pas ce bloc).

Passe P-L2-1 et calendriers du 2026-10-22 (soir ; même branche ; aucune donnée de marché, aucun réseau) : ligne 15 résolue (§10.5) ;
séparation du pilote L2 écrite et codée (§10.6 ; `LP-PROGRAMME-GATES.md` §7.4 pour le tableau des recouvrements de P3, G2, G4 et G5) ;
ligne 4 complétée (P3 au plus tôt au plancher de séparation, T0 ≥ 2026-11-04) ; spec Gate 2 `0.1.8-draft` (Annexe B). Exigence
d'**exécution** (pas de gel) ajoutée : un instantané final des calendriers relevé après le 2027-03-31 et avant l'exécution du Gate 2
(§10.5 ; le lecteur refuse l'exécution sans lui). Restent bloquants : lignes 1 à 6, 10 à 12 et 14 ci-dessus.
---

## 3. Instruments figés

3.1 **Règle d'univers (quant M9) et liste.** L'univers est **la liste D1** (« les 29 X-Perps liquides ») intersectée avec une **règle
par métadonnées** appliquée au snapshot post-2026-10-31 (P-G5-4) : `instType FUTURES`, `ruleType xperp`, sous-jacent crypto
`<BASE>-USD`, `listTime + 30 j < 2026-10-14T00:00Z` ; **aucun volume, aucune valeur de marché**. Les 4 instruments sans aucune minute
éligible (PONS, STRK, CT, QUANT) ne sont **ni récupérés ni analysés** : **25 instruments**. **Biais déclarés** : la liste D1 vient
d'un relevé « ≥ 1 M USD échangés le **2026-10-14** » (`TRADABLE-INSTRUMENT-UNIVERSE.md` §2) — une valeur de marché datée en
quarantaine (`GATES` §6.1, §9.5, M11), sélection sur la liquidité de **fin de fenêtre** (regard en avant sur le Gate 1) et biais de
survie (instruments retirés « not listed ») ; G2 §0.3 refuse cette liste pour lui-même ; D1 la nomme sans écrire la dérogation : la
dérogation est exigée par écrit (P-G5-4). Effet attendu : faible sur D (mêmes instruments aux événements et aux contrôles), plus
fort sur C2, C3 et la lecture économique (rapportés par instrument). Élargir au-delà de D1 exigerait une nouvelle décision de
licence. Au Gate 2, l'univers est celui de G2 §3 (métadonnées et comptages P3, jamais un volume). Métadonnées lues dans le snapshot
du 2026-10-16 (§0.1), recopiées dans le spec (`universe.analysed`), re-vérifiées au gel.

| Instrument | `uly` (indice) | `listTime` (UTC) | Éligible dès (UTC, +30 j) | `ctVal` | `tickSz` | `maxPxLmtPct` / `floatPxLmtPct` | Gate 1 |
|---|---|---|---|---|---|---|---|
| BTC-USD_UM_XPERP-310404 | BTC-USD | 2026-03-30 04:30 | 2026-04-29 04:30 | 0,0001 BTC | 0,1 | 0,01 / 0,005 | analysé |
| ETH-USD_UM_XPERP-310404 | ETH-USD | 2026-04-01 04:00 | 2026-05-01 04:00 | 0,001 ETH | 0,01 | 0,01 / 0,005 | analysé |
| ADA-USD_UM_XPERP-310404 | ADA-USD | 2026-04-01 04:05 | 2026-05-01 04:05 | 10 ADA | 0,0001 | 0,01 / 0,005 | analysé |
| DOGE-USD_UM_XPERP-310404 | DOGE-USD | 2026-04-01 04:10 | 2026-05-01 04:10 | 10 DOGE | 0,00001 | 0,01 / 0,005 | analysé |
| PEPE-USD_UM_XPERP-310404 | PEPE-USD | 2026-04-01 04:15 | 2026-05-01 04:15 | 1 000 000 PEPE | 1e−9 | 0,01 / 0,005 | analysé |
| PUMP-USD_UM_XPERP-310404 | PUMP-USD | 2026-04-01 04:20 | 2026-05-01 04:20 | 1 000 PUMP | 1e−6 | 0,01 / 0,005 | analysé |
| SOL-USD_UM_XPERP-310404 | SOL-USD | 2026-04-01 04:25 | 2026-05-01 04:25 | 0,01 SOL | 0,01 | 0,01 / 0,005 | analysé |
| SUI-USD_UM_XPERP-310404 | SUI-USD | 2026-04-01 04:30 | 2026-05-01 04:30 | 1 SUI | 0,0001 | 0,01 / 0,005 | analysé |
| XRP-USD_UM_XPERP-310404 | XRP-USD | 2026-04-01 04:35 | 2026-05-01 04:35 | 1 XRP | 0,0001 | 0,01 / 0,005 | analysé |
| TAO-USD_UM_XPERP-310523 | TAO-USD | 2026-05-20 08:00 | 2026-06-19 08:00 | 0,01 TAO | 0,1 | 0,3 / 0,015 | analysé |
| HYPE-USD_UM_XPERP-310523 | HYPE-USD | 2026-05-20 08:30 | 2026-06-19 08:30 | 0,1 HYPE | 0,001 | 0,3 / 0,02 | analysé |
| LINK-USD_UM_XPERP-310523 | LINK-USD | 2026-05-20 08:45 | 2026-06-19 08:45 | 1 LINK | 0,001 | 0,02 / 0,01 | analysé |
| AVAX-USD_UM_XPERP-310530 | AVAX-USD | 2026-05-27 08:15 | 2026-06-26 08:15 | 10 AVAX | 0,001 | 0,3 / 0,015 | analysé |
| ZEC-USD_UM_XPERP-310530 | ZEC-USD | 2026-05-27 09:00 | 2026-06-26 09:00 | 0,01 ZEC | 0,01 | 0,3 / 0,02 | analysé |
| NEAR-USD_UM_XPERP-310613 | NEAR-USD | 2026-06-11 08:30 | 2026-07-11 08:30 | 1 NEAR | 0,001 | 0,3 / 0,015 | analysé |
| ENA-USD_UM_XPERP-310613 | ENA-USD | 2026-06-11 08:45 | 2026-07-11 08:45 | 100 ENA | 0,00001 | 0,3 / 0,02 | analysé |
| ONDO-USD_UM_XPERP-310620 | ONDO-USD | 2026-06-17 08:15 | 2026-07-17 08:15 | 10 ONDO | 0,0001 | 0,3 / 0,015 | analysé |
| LIT-USD_UM_XPERP-310704 | LIT-USD | 2026-06-28 09:00 | 2026-07-28 09:00 | 1 LIT | 0,0001 | 0,3 / 0,025 | analysé |
| INJ-USD_UM_XPERP-310711 | INJ-USD | 2026-07-08 09:00 | 2026-08-07 09:00 | 0,1 INJ | 0,001 | 0,3 / 0,02 | analysé |
| RENDER-USD_UM_XPERP-310711 | RENDER-USD | 2026-07-08 09:30 | 2026-08-07 09:30 | 1 RENDER | 0,001 | 0,3 / 0,02 | analysé |
| DOT-USD_UM_XPERP-310808 | DOT-USD | 2026-08-07 10:15 | 2026-09-06 10:15 | 1 DOT | 0,0001 | 0,02 / 0,01 | analysé |
| ARB-USD_UM_XPERP-310815 | ARB-USD | 2026-08-11 09:15 | 2026-09-10 09:15 | 10 ARB | 0,00001 | 0,3 / 0,015 | analysé |
| OP-USD_UM_XPERP-310815 | OP-USD | 2026-08-11 09:30 | 2026-09-10 09:30 | 1 OP | 0,00001 | 0,3 / 0,015 | analysé |
| GRASS-USD_UM_XPERP-310829 | GRASS-USD | 2026-08-25 08:00 | 2026-09-24 08:00 | 1 GRASS | 0,0001 | 0,3 / 0,02 | analysé |
| FET-USD_UM_XPERP-310912 | FET-USD | 2026-09-07 08:00 | 2026-10-07 08:00 | 10 FET | 0,0001 | 0,3 / 0,02 | analysé |
| PONS-USD_UM_XPERP-310919 | PONS-USD | 2026-09-16 08:00 | après END | 10 PONS | 0,0001 | 0,3 / 0,02 | **non récupéré** |
| STRK-USD_UM_XPERP-310919 | STRK-USD | 2026-09-17 08:15 | après END | 1 STRK | 0,00001 | 0,3 / 0,025 | **non récupéré** |
| CT-USD_UM_XPERP-311003 | CT-USD | 2026-09-30 12:00 | après END | 10 CT | 0,0001 | 0,3 / 0,02 | **non récupéré** |
| QUANT-USD_UM_XPERP-311003 | QUANT-USD | 2026-10-01 09:00 | après END | 0,01 QUANT | 0,1 | 0,05 / 0,02 | **non récupéré** |

Tous : `instType FUTURES`, `ruleType xperp`, `state live` au 2026-10-16, `settleCcy USD`, `lotSz`/`minSz` 1 (0,1 pour ARB, AVAX),
`groupId 7`, `initPxLmtPct` 0,02–0,04. Le snapshot compte 244 X-Perps `live` ; les autres ne sont pas dans l'univers.

3.2 **Filtres (règles, pas lectures).** (i) **Éligibilité** (quant B3) : un instrument-instant n'existe, pour un événement **comme
pour un contrôle**, que si le début de son empreinte est ≥ `listTime + 30 jours` (bandes de listing, carnets vides, 4 h ±1 % des
premières semaines : `H1-XPERP-MARKET-ACCESS.md` §2.8 et §2.9) ; codé (P-G5-11 (B) vii), compté ; premier instrument éligible :
BTC le 2026-04-29 04:30 UTC, puis les huit autres majors le 2026-05-01 ; (ii) **T01** : règlements à intervalle 8 h seulement
(§5.1) — un contrat à 4 h ou escaladé (2 h / 1 h) sort de T01 pour ces règlements et entre dans la strate T02 ; (iii) **lignes
antérieures à `listTime`** : écartées et comptées (`dropped_before_start`, fait dans `g5_fetch`).

3.3 **Couverture (calcul d'audit sur `listTime`).** Éligibles au 2026-05-01 : 9 ; 07-01 : 14 ; 08-01 : 18 ; 09-01 : 20 ; 10-01 : 24 ;
2026-10-13 : **25**. Instrument-jours éligibles : ≈ **2 690** ; dans D_cov (2026-08-04 → 10-13, fetch du funding le 2026-11-02) :
≈ **1 545**, 18 instruments éligibles au 2026-08-04. Nombre d'instruments par instant T05 (105 instants) : 1 (BTC seul) aux 2
premiers instants, puis 9 à 25 ; 103 instants à ≥ 3 instruments. Le nombre d'instruments par instant est **rapporté**, aucune
pondération n'est choisie après lecture (piège 10 ; pondération égale par instant, §7.3).

3.4 **`InstrumentSpec`** (Gate 2) : `ctVal`, `tickSz`, `lotSz`, `minSz` lus dans le snapshot du gel (`lp_sim.InstrumentSpec.from_okx_row`).

---

## 4. Données et registre

4.1 **Flux (tous publics, REST, non authentifiés ; `LP-DATA-DESK` §1.4 ; `g5_fetch.STREAMS`)** :

| Flux (`tape` layout) | Endpoint | Page | Limite documentée | Profondeur documentée | Usage | Période récupérée |
|---|---|---|---|---|---|---|
| `candles_1m` | `GET /api/v5/market/history-candles` (`bar=1m`) | ≤ 300 | 20 req / 2 s (IP) | « from recent years » ; par X-Perp **UNKNOWN** | **prix de référence** `c(t)`, `low`/`high` du proxy de fill, `vol` (§4.5) | éligibilité − 1 j → END |
| `mark_1m` | `GET /api/v5/market/history-mark-price-candles` | ≤ 100 | 20 req / 2 s | « from recent years » ; X-Perps **UNKNOWN** | classifieur P̂ de T01 seulement | max(D_cov − 1 j ; éligibilité − 1 j) → END |
| `index_1m` | `GET /api/v5/market/history-index-candles` (`instId = uly`) | ≤ 100 | 10 req / 2 s | « from recent years » ; id = `uly` (docs-v5 « Same as uly ») — à confirmer par la réponse | classifieur P̂ de T01 seulement | idem |
| `funding_hist` | `GET /api/v5/public/funding-rate-history` | ≤ 400 | 10 req / 2 s (IP + instId) | **« up to three months »** ; « Applicable to SWAP and X-Perps FUTURES » (établi) | instants réels de règlement, intervalle, R_h (coût et validation C6), D_cov | profondeur servie, **avant le gel, scellé** (P-G5-3) |

`public/premium-history` est « Applicable to SWAP » : **inutilisable** pour les X-Perps (établi, docs-v5). `market-data-history`
(fichiers T+2, docs globale seule, disponibilité EEE UNKNOWN) : option non appelée (décision 15 ; `GATES` §8 P2).

4.2 **Borne dure, dans le code.** `END_EXCLUSIVE = 2026-10-14T00:00:00Z` (constante de module `g5_fetch`, recopiée dans le spec
`end_exclusive_utc` et vérifiée à l'égalité) : aucune ligne ≥ cette borne n'est persistée, quel que soit ce que le serveur renvoie ;
la photo du 2026-10-14 00:00 est **perdue** par construction, et le règlement du 2026-10-13 16:00 sort de T01 (son suivant est
inconnu, §5.1). L'évaluateur (`guard_window`) refuse toute fenêtre touchant [2026-10-14 ; 2026-11-02). Fenêtre du spec :
2026-04-28 → 2026-10-14 (exclu) ; premier jour = éligibilité de BTC − 1 j.

4.3 **Plan de fetch (calcul d'audit ; `g5-fetch --dry-run` le recalculera sans rien appeler ; spec `fetch_plan`).** Deux phases.
**Avant le gel** (P-G5-3) : `funding_hist`, 25 instruments, ≈ 50 requêtes, mode scellé. **Après le gel** : `candles_1m` ≈ **13 000**
pages de 300 (depuis l'éligibilité − 1 j de chaque instrument), `mark_1m` et `index_1m` ≈ **22 600** pages de 100 chacun (depuis
max(D_cov − 1 j ; éligibilité − 1 j), D_cov supposé au 2026-08-04) ; total **≈ 58 300 requêtes** ; à 0,8 × les limites
(16 req / 2 s pour bougies et mark, 8 req / 2 s pour l'indice) ≈ 0,45 h + 0,8 h + 1,6 h ≈ **2,8 h** séquentielles ; ≈ 5,6 h à 0,4 ×
(proposé, P-G5-1). Comparaison : ≈ 190 000 (borne haute de la première version), ≈ 118 000 (première version recalculée sur les
`listTime`), ≈ 87 000 (relecture quant M8). Stockage : quelques centaines de Mo. Hôte : `https://eea.okx.com` (`DEFAULT_HOST`).
Reprise par curseur ; `fetch.log` ; manifeste `lp-xperp-1m-history` avec `decision_ref`, `end_exclusive`,
`rejected_at_or_after_end`, `end_reason`, `coverage_start_us`, sha256 par jour finalisé et sha256 du plan dry-run. **Aucune valeur
n'est affichée** par le fetcher.

4.4 **Couverture de `funding-rate-history` : la contrainte dure de T01 (piège 12).** Trois mois glissants depuis la date du fetch :
fetch le 2026-11-02 ⇒ règlements depuis ≈ 2026-08-04 ⇒ 71 jours, 213 instants 8 h bruts, **≈ 211** après les deux bords (le premier
règlement servi n'a pas de précédent, le dernier, 2026-10-13 16:00, n'a pas de suivant persisté) ; le 11-20 ⇒ ≈ 157 ; le 12-01 ⇒ ≈ 124.
Avant la couverture : ni R_h, ni F dans le net, ni les vrais instants (escalade 2 h / 1 h inconnue) ⇒ **T01 entier (événements et
contrôles) se restreint à D_cov** = [premier `fundingTime` dont le précédent est servi ; END_EXCLUSIVE), lu dans le manifeste
(`coverage_start_us`, `end_reason`, CTO M1) et écrit au registre le jour du fetch. T05 utilise toute la fenêtre (2026-04-29 →
10-13) ; son funding hors D_cov est **« supposé nul, non observé »** et compté (`funding_unknown` ; quant M5) — aucun règlement 8 h
ou 4 h ne tombe dans (h+5 ; h+60] = (13:35 ; 14:30] UTC, une escalade à 2 h y placerait 14:00.

4.5 **Qualité (règles écrites avant lecture).** Minute sans bougie dernier prix ⇒ observation manquante (comptée ; aucun report
avant) ; lignes `confirm = 0` écartées et comptées ; **bougie figée** (aucune transaction : « the opening high and closing low default
take the closing price of the previous cycle », docs-v5 l.29195) : une borne figée importe dans le rendement le mouvement antérieur
à l'armement (ou au signe pour T05), de direction non garantie (quant M3) ⇒ **tout instrument-instant dont une bougie clé a
`vol = 0` est exclu** (T01 : bougies qui finissent à h−5 et h+15 ; T05 : à h, h+5 et h+60), compté, part exclue rapportée par
instrument — même règle aux contrôles ; instrument dont > 20 % des minutes de ses empreintes d'événement manquent ⇒ exclu du type et
compté ; P̂ incalculable (< 90 % des 480 bornes de mark et d'indice) ⇒ instrument-instant exclu ; si > 50 % des instrument-jours de
T01 dans D_cov sont sans P̂ ⇒ T01 `INCONCLUSIVE` ; `funding_hist` : ligne sans `realized_rate` exclue (comptée) ; job de fetch en
erreur sur > 10 % des (instrument, flux), ou `end_reason` absent ⇒ `INCONCLUSIVE` global ; aucune nouvelle fenêtre sans nouveau
pré-enregistrement.

4.6 **Calendriers et sessions (publics, non-marché ; listes explicites gelées dans le spec et en Annexe C, P-G5-5).** NYSE
(nyse.com/markets/hours-calendars) ; BLS (bls.gov/schedule/2026/home.htm : CPI, Employment Situation, 08:30 ET) ; Federal Reserve
(federalreserve.gov, FOMC ; 14:00 ET = usage, non écrit sur la page). **Sessions** (heure locale, conversion par la base `zoneinfo`) :
ouverture et clôture cash US (09:30 / 16:00 `America/New_York`, jours NYSE), ouverture Londres / Xetra (08:00 `Europe/London` =
09:00 `Europe/Berlin`, lundi–vendredi), ouverture Hong Kong (09:30 `Asia/Hong_Kong`), ouverture Tokyo (09:00 `Asia/Tokyo`), fenêtre
CME CF BRR (15:00–16:00 `Europe/London`, tous les jours, à vérifier au gel), moyenne de règlement des datés OKX (vendredi
07:30–08:00 UTC). Heure d'été US du 2026-03-08 au 2026-11-01 (toute la fenêtre Gate 1 en EDT), 2027 à partir du 03-14 ; UE / UK
2026-03-29 → 10-25, 2027 à partir du 03-28. Fériés locaux UK / HK / JP ignorés (exclusion appliquée tous les jours ouvrés :
conservateur).

4.7 **Registre et statuts** (`GATES` §6) : `okx-xperp-1m-history-2026-03-30..2026-10-13` `unread` (avec le sha256 du fetch funding
scellé et sa date) → `consumed_equivalent` au jour de l'exécution de `g5-eval` ; `okx-xperp-prospective-G5` `prospective` ; snapshot
d'instruments `selection` (métadonnées). Après le verdict, la table §3 de `RESEARCH-CAMPAIGN-STATUS.md` est complétée.

4.8 **Stockage et visibilité (alignés sur D1, quant m13).** Séries, segments, manifestes, résumés JSON chiffrés et fichiers scellés
dans `tradingV3-private` ; le dépôt reçoit ce protocole, le spec, et `LP-G5-GATE1-RESULT.md` : verdicts, n, taux de fill, matrice C6,
et — D1 l'autorise — des **agrégats statistiques sans prix** (D, LB90, UB90, p en pb ou sans unité) pour les types `PASS` / `FAIL`
seulement ; jamais une série, un prix, un spread ; jamais les valeurs scellées (§8).

---

## 5. Événements, signes, construction du scalp papier

5.1 **T01 — définition.**
- **Instants** : règlements h lus dans `fundingTime` de `funding_hist`, dans D_cov, tels que `fundingTime(h) − fundingTime(h⁻) = 8 h
  ± 1 min` **et** `fundingTime(h⁺) − fundingTime(h) = 8 h ± 1 min` (h⁻, h⁺ = règlements voisins ; voisin inconnu ⇒ exclu, compté) ;
  `realized_rate` présent ; instrument éligible (§3.2).
- **Classifieur unique (quant B1)**, évalué à **t_arm = h−5**, identique aux contrôles : P̂(h−5) = Σ_{k=1}^{480} k · π(h−5 −
  (480 − k) min) / Σ k, sur les bornes présentes (≥ 90 %), π = (m − idx) / idx à la borne de minute ; la dernière borne utilisée est
  h−5 elle-même (bougies de mark et d'indice qui finissent à h−5, ouvertes à h−6) : **aucune information postérieure à la décision**.
  Même forme que la formule officielle (moyenne pondérée sur n = 480, `funding-mechanism.txt` l.258–260), sur une fenêtre glissante.
- **Seuil et signe (quant M1, option b)** : s = **+1** si P̂ ≥ **+0,09 %** (les longs paieront ; le fournisseur se place au **bid**),
  s = **−1** si P̂ ≤ **−0,09 %** (les shorts paieront ; ask), **instant exclu** sinon. Origine du seuil (calcul d'audit sur la
  formule `funding-mechanism.txt` l.238–240, I = 0,01 %, clamp ±0,05 %, N = 8 : R = P̄ + clamp(I − P̄, −0,05 % ; +0,05 %)) : R = 0,01 % pour P̄ ∈ [−0,04 % ; +0,06 %],
  R = P̄ − 0,05 % au-dessus, R = P̄ + 0,05 % au-dessous ; donc **|R| ≥ 4 pb ⟺ P̄ ≥ +0,09 % ou P̄ ≤ −0,09 %**, symétrique en prime.
  Le seuil traduit l'incitation (un aller-retour maker/maker coûte 4 pb, §0.6) ; le prix payé est une fraction f plus faible
  (puissance, §7.4).
- **Différences déclarées entre P̂ et la prime contractuelle** : P̂ est une base **au prix moyen** (mark = indice + moyenne mobile de
  la base, fenêtre non publiée, `okx-mark-last-price.txt` l.238–250), la prime officielle est calculée sur des **prix d'impact**
  (« Impact value = 200 × Max leverage », `funding-mechanism.txt` l.256–266) et a sa propre zone morte (la demi-largeur du carnet
  d'impact) ; P̂ finit à h−5, la prime officielle à h−1. C'est pourquoi le classifieur est **validé** contre R_h (C6, §7.2) ; R_h ne
  sert qu'au coût de funding et à cette validation.
- |R_h| est la **dose** (descriptif). « The actual fee assessment may take up to a minute » ⇒ une position ouverte dans [h ; h+1)
  **paie** (pessimiste ; Gate 2, (B) xxi).

5.2 **T05 — définition.** Jour de bourse NYSE (liste gelée des fériés), **hors** dates CPI et Employment Situation (liste par dates,
jamais « premier vendredi » : Employment Situation le vendredi saint 2026-04-03, NYSE fermée, et le **jeudi** 2026-07-02 ; piège 6) ;
h = 09:30 `America/New_York` (13:30 UTC en EDT, 14:30 en EST) ; par instrument **éligible à h** (§3.2), **s = sign(ln c(h+5) −
ln c(h))**, **instrument-instant exclu** si s = 0 ; sensibilité descriptive : |mouvement| ≥ 1 tick. Fenêtre [h+5 ; h+60].
**Instants (calcul d'audit, Annexe C) : 116 jours NYSE avec ≥ 1 instrument éligible (du 2026-04-29 au 2026-10-13), dont 105 hors
CPI/NFP** (11 dates retirées) ; 103 à ≥ 3 instruments (les 2 premiers reposent sur BTC seul). La première version et PERIODS
comptaient 135 / 123 sans éligibilité (quant B3, §0.10).

5.3 **Prix de référence.** **Dernier prix** `c(t)` (bougie 1 m `candles_1m`, clôture de la bougie qui se termine à t) pour les deux
types. Motif : c'est le prix du contrat que le scalp traite ; le mark = indice + moyenne mobile de la base atténue précisément le
mouvement de base que T01 prédit et la continuation que T05 lit. Le mark et l'indice ne servent **qu'au classifieur** P̂. Coût déclaré
du choix : **rebond bid-ask** sur dernier prix (Roll). Pour T01 c'est un bruit sur une dérive signée par la prime. Pour T05, le rebond
induit un retour mécanique de signe opposé au signe des cinq premières minutes : il **abaisse le net absolu** (biais vers le FAIL sur
C2, C3), et il est **le même aux contrôles** depuis qu'ils sont pris à la même minute de l'heure (quant B2) ; la première version, qui
prenait les contrôles de T05 à la minute 0 (sursaut T07 dans leur fenêtre de signe), biaisait D **vers le PASS**. Résidu déclaré :
l'ampleur du rebond croît avec le spread, qui peut différer entre 13:30 et les minutes 30 de contrôle (direction UNKNOWN, §11.1).

5.4 **Scalp papier (Gate 1, sans carnet — approximations déclarées).**
- **T01** : décision et **armement à t_arm = h−5** (`arm_min = −5` ; jamais h−1 : piège 4) ; ordre au repos au prix limite c(h−5)
  du côté s, vivant jusqu'à h+1 (`rest_until_min = 1`) ; **proxy de fill (primaire, quant M11)** : un long est rempli si le plus bas
  (`low`) des six bougies ouvertes de h−5 à h (soit [h−5 ; h+1)) est **strictement inférieur à c(h−5) − `tickSz`** ; un short si le
  plus haut (`high`) est **strictement supérieur à c(h−5) + `tickSz`** (règle `through`, conservatrice) ; non rempli ⇒ exclu de la
  mesure primaire, compté ; taux de fill rapporté aux événements et aux contrôles ; sortie passive **supposée** à c(h+15) ;
  r = s × ln(c(h+15) / c(h−5)) × 10⁴ pb ; funding_bp = −s × R_h × 10⁴ (règlement h ∈ (h−5 ; h+15], `FundingSchedule.crossed`) ;
  net = r + funding_bp − **9,5 pb** (§6 ; amendement A1 : r part du prix limite et n'existe que pour un ordre traversé, donc le mouvement
  défavorable qui suit le remplissage — la sélection adverse de la jambe d'entrée — est **déjà dans r** et n'est plus supposée ; 13 pb
  avant A1, rapporté en sensibilité). Lecture : le fournisseur du côté s **paie** R_h quand le signe de P̂ et celui de R_h concordent
  (piège 19) ; net = rebond − R − coûts. La version **sans** proxy de fill est descriptive (§9.4) : elle n'est pas conditionnée au
  remplissage, rien n'y mesure la jambe d'entrée, son net garde 13 pb ; sa dérive brute est la ligne **A** du tableau A/B/C (§9.9).
- **T05** : décision à **h+5**, entrée **preneur** à c(h+5) du côté s, demi-spread de conception payé (§6) ; sortie passive supposée à
  c(h+60) ; r = s × ln(c(h+60) / c(h+5)) × 10⁴ ; funding_bp = −s × Σ taux des règlements dans (h+5 ; h+60] (D_cov ; hors D_cov
  supposé nul, compté) ; net = r + funding_bp − 15,5 pb (vérification du double compte : §6 et amendement A1, A1.2 ; inchangé).
- **Pas de stop** dans l'analyse primaire (règle 12) ; lecture portefeuille : coupe de catastrophe à **3 σ₁₅** (σ₁₅ = écart-type des
  rendements 1 m du dernier prix sur les 24 h précédant h × √15), jamais plus serrée ; taille **100 USD** par ordre.

5.5 **Scalp simulé (Gate 2, carnet réel ; `measure_lp_sim`, `lp_sim`).** T01 : même classifieur P̂(h−5) (mark de la capture, indice
selon P-G5-10 ii) ; intention post-only au touch côté s décidée à `arm_ts_us = h−5`, vivante jusqu'à `end_ts_us = h+1` (règle
`through` depuis la décision (b) du propriétaire du 2026-10-22, pour toute jambe passive ; latence 250 ms), non remplie ⇒ pas
d'observation (taux de fill compté) ; sortie passive au touch opposé à partir de h+15
jusqu'à h+60, reliquat **taker** ; fill dans [h ; h+1) traversant la photo ((B) xxi). T05 : entrée preneur à h+5 au touch opposé +
glissement, sortie passive dès h+60, reliquat taker à h+75. Règles de fill `touch` / `queue` descriptives (§10.2), jamais décisives. Les intervalles de
funding sont lus dans la tape (`funding_time_us`, `next_funding_time_us`) ; la validation C6 utilise R du canal `funding-rate`.

5.6 **Deux lectures** (`GATES` §4). Sans contrainte : toutes les intentions ; critères scientifiques. **Portefeuille 500 USDT** :
`capital 500`, `leverage 1`, `max_live 4`, `order_notional 100` ; à un instant où plusieurs instruments sont retenus, au plus **4**
sont admis, dans l'ordre des identifiants (règle fixe) ; `posMode` UNKNOWN ⇒ rapportée sous les deux hypothèses (G2 §5.7) ; aucun
impact de marché supposé (déclaré).

---

## 6. Coûts (pessimistes, dans le net ; `GATES` §5 ; quant M6 ; amendement A1)

| Terme | T01 (maker / maker) | T05 (preneur / maker) | Stress (× 1,5 sur tous les termes) |
|---|---|---|---|
| Frais | 2 + 2 = 4 pb | 5 + 2 = 7 pb | 6 / 10,5 pb |
| Sélection adverse (valeur de conception `GATES` §5.4 : **3,5 pb par jambe maker dont la sélection adverse est supposée**, borne haute de Part A, structurellement plus élevée sur X-Perps à cause des RPI) | **3,5 pb sur la seule jambe de sortie** (sortie passive supposée à c(h+15), non conditionnée). **Jambe d'entrée : mesurée** par le conditionnement au remplissage (amendement A1) — le mouvement défavorable qui suit le remplissage est dans r —, **0 supposé**. Avant A1 : 3,5 × 2 = 7 pb, double compte partiel déclaré | 3,5 pb sur la jambe maker de sortie | 5,25 / 5,25 pb (10,5 pb pour T01 avant A1) |
| Demi-spread d'entrée preneur | — | **3 pb** (conception : la moitié du seuil de 6 pb de G2 ; spreads des X-Perps minces UNKNOWN) | 4,5 pb |
| Glissement | 2 pb | 2 pb | 3 pb |
| **Total supposé** | **9,5 pb** (A1 ; 13 pb avant A1 : sensibilité rapportée, `T01_cost_sensitivity_pre_a1`) | **15,5 pb** | **14,25 / 23,25 pb** (T01 : 19,5 pb avant A1, rapporté) |
| Funding | **réel**, `−s × R_h` (traversé par construction) ; stress au plafond de l'instrument contre la position : descriptif | réel dans D_cov ; hors D_cov supposé nul, compté | idem |
| Marge gelée | 100 USD × 20 min / 365 j × SOFR : négligeable, écrit (`GATES` §5.2) | idem (55 min) | — |
| Liquidation, ADL, bandes | sans objet à 1× sur 20–55 min (mouvement de l'ordre de 99 % requis, calcul d'audit G2 §5.6) ; rejet par bande (`maxPxLmtPct`) non modélisé au Gate 1, compté au Gate 2 (`band_rejected`) | idem | — |

**Règle anti-double compte (amendement A1).** Chaque terme de coût est soit **mesuré** dans le rendement, soit **supposé** dans le coût, jamais
les deux, jamais aucun. Un ordre au repos dont la mesure est **conditionnée au remplissage** (proxy `through`, T01 primaire) a sa sélection
adverse d'entrée mesurée : 0 supposé. Une mesure **non conditionnée** (T01 sans proxy, §9.4 ; variante preneur, §9.9) ne la mesure pas : la
valeur de conception s'applique (13 pb pour la version maker sans proxy, 15,5 pb pour la variante preneur). La jambe de sortie (sortie
passive **supposée** remplie à c(h+15) / c(h+60)) n'est conditionnée à rien : sa sélection adverse est supposée (3,5 pb), pour les deux
types. **T05 vérifié** (A1.2) : frais, sélection adverse de la jambe de sortie, demi-spread d'entrée (milieu → touch) et glissement
(touch → prix moyen) sont des termes distincts ; le seul chevauchement est **partiel** et déclaré — conditionnée au signe s de
[h ; h+5], la clôture c(h+5) est plus souvent du côté du touch que le preneur paie (rebond de Roll), si bien qu'une part de 0 à 3 pb du
demi-spread est déjà dans c(h+5) : surcharge vers le FAIL sur C2 / C3, nulle sur D ; non corrigée (non mesurable sur bougies ; la corriger
serait une hypothèse vers le PASS ; le Gate 2 mesure le demi-spread réel). T05 reste à 15,5 / 23,25 pb.

Les coûts sont **identiques** à l'événement et au contrôle : dans D (§7.3) ils s'annulent, sauf le funding (payé à l'événement, nul au
contrôle). Le seuil +10 pb porte donc sur l'**excès de dérive net du funding** ; C2 et C3 exigent en plus un net **absolu** positif à
l'événement, coûts compris, et à coûts × 1,5. **Aucune substitution par la table G2** : G5 est gelé avant la lecture de G2 (P-G5-9),
la table ne peut pas exister au gel ; les valeurs de conception sont **définitives** pour le Gate 1 (au Gate 2, la sélection adverse
est mesurée par `lp_sim`).

---

## 7. Contrôles, unité, puissance, critères

7.1 **Contrôles (règle 5 : « mêmes règles à d'autres heures », jamais apparié à l'heure ; quant B2, M4, M5).**
- **Minute de l'heure du type** : T01 à **hh:00** (comme ses photos), T05 à **hh:30** (comme 13:30) — le sursaut du haut de l'heure
  (T07) et le rebond qu'il entraîne sont ainsi dans les contrôles exactement comme dans les événements.
- **Jours** : pour chaque instrument, les jours où il a ≥ 1 événement **brut** du type (avant classifieur et proxy de fill pour T01 ;
  jours NYSE hors CPI/NFP où il est éligible pour T05) ; T01 : dans D_cov seulement.
- **Candidats et sélection** : **toutes** les heures du jour à la minute du type, retenues si **admissibles** ; aucun tirage aléatoire,
  aucune heure fixe choisie à la main.
- **Admissibilité par empreintes** (et non par instants) : un contrôle c est admissible si son empreinte (T01 : [c−5 ; c+15] ;
  T05 : [c ; c+60]) finit ≥ 60 min avant le début, ou commence ≥ 60 min après la fin, de **chaque** empreinte de l'union d'exclusion :
  (a) les règlements de l'instrument lus dans `funding_hist` (tous intervalles, [s−5 ; s+15]) ; (b) la **grille par défaut** du type,
  tous les jours, déclarée comme hypothèse (T01 : 00/08/16 UTC ; **T05 : 00/04/08/12/16/20 UTC**, pour couvrir les contrats à 4 h et
  garder une composition constante dans et hors D_cov) ; (c) les instants T05 ([h ; h+60]) ; (d) les **sessions** de §4.6 (instants
  ou fenêtres en heure locale) ; (e) les publications macro de T11 en heure de New York (CPI, Employment Situation : [08:30 ; 09:30] ;
  FOMC : [14:00 ; 15:30]).
- **Résultat (calcul d'audit, heure d'été US et UK du Gate 1)** : T01 un jour NYSE ordinaire : **03, 04, 05, 10, 11, 12, 18, 22 h**
  (8 contrôles ; 12 h sort les jours CPI/NFP, 18 h les jours FOMC ; 13 heures un samedi) ⇒ ≈ 665 instants de contrôle bruts sur 71 jours
  de D_cov ; T05 un jour d'événement : **09:30, 17:30, 21:30 UTC** (2 les jours FOMC) ⇒ ≈ 311 instants de contrôle bruts sur 105 jours.
  En heure d'hiver (Gate 2) : T05 05:30, 09:30, 17:30 ; T01 03, 04, 05, 06, 10, 11, 12, 13, 18, 19 h — la **règle** est identique, sa
  traduction UTC suit les sessions (quant M4).
- **Même règle** qu'à h : même classifieur et même seuil (T01), même règle de signe (T05), même proxy de fill, même règle `vol = 0`,
  mêmes coûts, même formule de funding (attendu nul par construction ; tout funding non nul est compté comme anomalie).

7.2 **Validation du classifieur de T01 (C6 ; quant B1).** Sur les instrument-règlements **bruts** de T01 (éligibles, filtre 8 h,
`realized_rate` présent, P̂ calculable), matrice **3 × 3** classe de P̂(h−5) (+1 si ≥ +0,09 %, −1 si ≤ −0,09 %, 0 sinon) × classe de
R_h (+1 si ≥ +4 pb, −1 si ≤ −4 pb, 0 sinon), **toujours imprimée**. Validations pré-déclarées : **sensibilité** = part des R hors zone
dont la classe de P̂ est la même ≥ **0,80** ; **taux de faux positifs** = part des R en zone (|R| < 4 pb) dont P̂ est hors zone ≤ **0,20**
(spécificité ≥ 0,80) ; chaque dénominateur ≥ **30** ; sinon T01 `INCONCLUSIVE` (ni PASS ni FAIL ; p = 1 dans la famille ; issue
automatique §8). Rapporté sans verdict : la distribution de |P̂| aux événements et aux contrôles retenus (contrôle d'**équilibre**).
La version conditionnée par R_h (ancien classifieur) est **descriptive** (§9.4).

7.3 **Unité et statistique.** Unité = l'**instant** (horodatage) : pour chaque instant (événement ou contrôle), **moyenne sur les
instruments retenus** du net signé ; au moins **1** instrument retenu par instant (distribution rapportée ; strate ≥ 3 instruments
descriptive) ; n = nombre d'instants **uniques** (piège 23) ; D = moyenne des instants d'événement − moyenne des instants de contrôle ;
incertitude par **bootstrap par blocs de jours UTC** (`block_days = 1`, **10 000** rééchantillons — 1 000 laissaient 14 tirages au
seuil du programme p ≤ 0,0143, quant m1 —, graine `20261102`, événements et contrôles d'un même jour rééchantillonnés **ensemble** :
piège 5) ; LB90 / UB90 = quantiles 0,10 / 0,90 ; p unilatérale = part des rééchantillons où D* ≤ 0 (`GATES` §2.2). **Sensibilité
descriptive** à blocs de **7 jours** (persistance du signe du funding sur plusieurs semaines, quant m1).

7.4 **Porte de puissance et effet détectable (règle 9 ; `GATES` §3.2–3.3 ; quant B3, M2).** Plancher : **≥ 100 instants**
d'événement **et** ≥ 30 jours distincts par type ; sous le plancher : `NOT_INFORMATIVE`, **jamais d'extension sur résultat**.
Avec se = σ × √1,3 / √n (σ = écart-type de la valeur par instant, DE 1,3), la condition LB90 > 0 seule détecte δ_min ≈ 2,12 se ; la
règle C1 exige **aussi** D ≥ +10 pb au point, donc l'effet vrai qui donne une puissance de 0,80 est **δ₈₀ = max(10 + 0,84 se ;
2,12 se)** (si l'effet vrai vaut +10 pb, la probabilité de PASS est d'au plus 50 %). σ est **UNKNOWN** avant lecture.
**Variance des contrôles** (relecture review-b quant m4 ; `GATES` §3.2 amendé le 2026-10-21 ; écrit au corps le 2026-10-22) : se
ci-dessus ignore la variance de la moyenne des contrôles. Pour D = moyenne des événements − moyenne des contrôles, se(D) ≈ σ × √1,3 ×
√(1/n_e + 1/n_c) = (σ √1,3 / √n_e) × √(1 + n_e/n_c), soit un facteur ≈ 1,15–1,16 avec n_c ≈ 3 n_e : **T05** (105 événements contre
≈ 311 contrôles) δ_min ≈ **0,27 σ** et δ₈₀ ≈ **+16,5 à +32,7 pb** pour σ de conception 60–120 pb (les valeurs 0,236 σ et +15,6 à
+28,3 pb ci-dessous sont l'approximation sans les contrôles, gardée pour mémoire) ; **T01** (≈ 211 contre ≈ 665) : facteur ≈ 1,15. La
covariance des chocs de jour communs aux événements et aux contrôles d'un même jour (rééchantillonnés ensemble) joue en sens inverse,
ampleur UNKNOWN. Calcul d'audit : planchers de conception, jamais recalculés sur la donnée.
- **T05** : n = **105** par calendrier (marge de 5 sur le plancher ; la perte d'un instant exige que tous ses instruments soient
  exclus, ce qui n'est plausible qu'aux 2 instants à BTC seul) ; se ≈ 0,111 σ, δ_min ≈ **0,236 σ** ; σ de conception 60–120 pb
  (hypothèse, pas une mesure) ⇒ δ₈₀ ≈ **+15,6 à +28,3 pb** : **+10 pb n'est pas détectable** avec une puissance de 0,80 (écrit
  d'avance). Un FAIL sera annoté « non poursuivi ; absence d'effet non démontrée » si UB90(D) ≥ +10 pb, « effet ≥ +10 pb exclu sous la
  forme testée » si UB90(D) < +10 pb (§8).
- **T01** : deux fractions, définies d'avance : **f_obs** = part des instrument-règlements bruts retenus (classifieur hors zone, rempli,
  `vol` > 0) et **f_inst** = part des instants bruts de D_cov avec ≥ 1 instrument retenu ; n = f_inst × N_cov, N_cov ≈ 211 (fetch du
  funding le 2026-11-02), ≈ 157 (11-20), ≈ 124 (12-01). `NOT_INFORMATIVE` est l'issue **attendue** si f_inst < 100 / 211 ≈ **0,47**
  (fetch le 11-02), < 0,64 (11-20), < 0,81 (12-01). Le seuil d'incitation (M1) et le proxy de fill (M11) abaissent f_inst : un
  `NOT_INFORMATIVE` de T01 est **probable** et écrit d'avance ; il envoie T01 au Gate 2 (§8). À n = 105 et σ de conception 30–60 pb
  (hypothèse) : δ₈₀ ≈ +12,8 à +15,6 pb. La réponse réglementaire à une puissance insuffisante est d'élargir l'univers au Gate 2, jamais
  d'allonger.

7.5 **Critères et verdict (évalués une seule fois, après la fin de la lecture ; quant B4).**
- **Précédence** : (1) `G5_GATE1_INCONCLUSIVE` si une condition de dispositif tient : globale (sha256 du spec différent de
  `--expect-spec-sha256`, digest du code différent du gel, statut du spec ≠ `FROZEN`, `freeze_fill` incomplet, fetch en erreur sur plus de
  10 % des jobs, `end_reason` absent) ou propre à T01 (D_cov inconnu, > 50 % des instrument-jours sans P̂, C6 en échec ou
  incalculable) ; (2) `G5_GATE1_NOT_INFORMATIVE` sous la porte (§7.4) ; (3) les critères puis Holm.
- **Critères** (tous requis pour le type) :
  - **C1** (test confirmatoire de la famille) : D ≥ **+10 pb** au point (mesure `net_bp`, coûts §6, funding réel) **et LB90(D) > 0** ;
  - **C2** : net moyen des instants d'événement **> 0** (point), coûts compris (T01 : 9,5 pb depuis l'amendement A1, 13 pb avant ; la valeur
    à 13 pb est rapportée, jamais un critère) ;
  - **C3** : C2 tient avec **tous** les termes de coût × 1,5 (T01 : 14,25 pb depuis A1, 19,5 pb avant, rapporté) ;
  - **C4** : D (point) **> 0 dans chaque moitié** ; moitiés = jours distincts d'événement du type, triés, coupés à la **médiane
    inférieure** (première moitié = jours ≤ jour médian ; T05 : 53 / 52 jours), avec les contrôles des mêmes jours (quant m2 : c'est la
    règle du code, et non des moitiés calendaires) ;
  - **C5** : LB90(D) > 0 après retrait des ⌈0,05 n⌉ instants d'événement au **net par instant** le plus élevé ; contrôles inchangés ;
    même graine, mêmes rééchantillons (quant m2) ;
  - **C6** (T01 seulement) : validation du classifieur (§7.2) ;
  - **C7** (T01 seulement ; quant M4) : D (point) > 0 sur les seuls instants d'événement de **16:00 UTC**, contre les contrôles des
    mêmes jours UTC.
- **Règle unique d'inscription au Gate 2** : **`G5_GATE1_PASS(type)` ⟺ C1–C7 du type (C1–C5 pour T05) et confirmation Holm locale
  à K = 2, α = 0,10** (`holm_local_confirmed` ; un type `NOT_INFORMATIVE` ou `INCONCLUSIVE` y compte p = 1) ; **seul ce verdict** ouvre
  le Gate 2 au titre d'un PASS. Sinon, porte atteinte et aucune condition de dispositif : `G5_GATE1_FAIL(type)`, annoté (critère en
  échec, ou « non confirmé par Holm » ; UB90 ≥ / < +10 pb).
- **`PASS_PROGRAMME`** est le statut de `GATES` §2.3 à **m_max = 7** (P-G5-7) : immédiat si p ≤ 0,10 / 7 ≈ 0,0143
  (`pass_programme_immediate`), sinon à la clôture de la famille ; il n'a **aucun effet** sur l'inscription au Gate 2. Le champ
  actuel `summary.pass_programme` du code (qui désigne la confirmation Holm locale) est renommé (P-G5-11 (B) xvi).

7.6 **Porte économique (`GATES` §1 ; D4 : S_éco = 97,25 USD/an, `GATES` §1.2 amendé, écrit dans les deux specs le 2026-10-22 ; quant m3).** Par type : plafond_USD_an = fills admis par jour du
calendrier du type (lecture portefeuille : ≤ 4 par instant, 100 USD, 1×) × 100 × net_bp moyen d'événement (aller-retour **absolu**,
coûts compris, jamais D) / 10⁴ × jours/an (**365** pour T01, jours calendaires de D_cov ; **252** pour T05, jours NYSE avec ≥ 1
instrument éligible, jours macro comptés au dénominateur ; amendement de `GATES` §1.1, P-G5-7), avec LB90 / UB90. « Poursuivi » ⇔
point ≥ S_éco **et** (UB90 ≥ 2 S_éco **ou** LB90 > 0) (`GATES` §1.2). Seuils de rentabilité (calcul d'audit, pas une prévision) :
**T05** à 4 fills par instant, 105 instants pour 116 jours NYSE : net d'événement ≥ **10,7 pb** (10,66 avec S_éco = 97,25 ; 10,6 avec
l'arrondi 97 d'avant le 2026-10-22 ; la relecture écrivait 9,6 pb en
supposant un instant chaque jour NYSE), soit une continuation brute de l'ordre de **26 pb** après 15,5 pb de coûts — **un PASS de T05 à
D = +10 pb sans net absolu de cet ordre est « PASS, non poursuivi » par construction** (écrit d'avance) ; **T01** : ≥ 2,2 pb nets à
12 fills par jour (3 photos × 4 instruments ; 2,22), ≥ 8,9 pb à 3 fills par jour (8,88 ; seuils **nets**, inchangés par l'amendement A1 ; en dérive
brute de remplissage, ils baissent de 3,5 pb pour T01 puisque le net y retire 9,5 pb au lieu de 13 pb, funding en plus).

---

## 8. Ce que chaque verdict autorise, et ferme (quant B5, M10)

- **Issues fixées au gel, sans discrétion** (spec `gate2_inscription`) : `G5_GATE1_PASS` ⇒ inscrit au Gate 2 ; `G5_GATE1_NOT_INFORMATIVE`
  ⇒ **inscrit automatiquement**, sans futilité ni condition sur le point du Gate 1 ; `G5_GATE1_INCONCLUSIVE` ⇒ **inscrit
  automatiquement**, sans futilité ni condition ; `G5_GATE1_FAIL` ⇒ **non inscrit**. Le propriétaire ne décide rien après la lecture.
- **Scellement** : pour un type `INCONCLUSIVE` ou `NOT_INFORMATIVE`, l'évaluateur **n'imprime pas** D, LB90, UB90, p, les moyennes, les
  valeurs des critères ni les descriptifs du type ; il imprime le verdict, ses raisons, n, les jours, les exclusions, le taux de fill
  et la matrice C6 ; le reste va dans `sealed/<type>.json`, dont le sha256 est imprimé et inscrit au registre ; le fichier n'est ouvert
  qu'**après le verdict du Gate 2** du même type. Un D défavorable ne peut donc ni se faire classer en défaut du dispositif pour une
  seconde chance, ni influencer quoi que ce soit avant le Gate 2.
- **`G5_GATE1_PASS` (par type)** autorise **une seule chose** : l'inscription au **Gate 2 prospectif** (§10) avec les **mêmes**
  classifieur, signe, fenêtre, contrôles et coûts (gelés dans le spec Gate 2). Ni ordre (démo ou réel), ni chemin d'exécution, ni usage
  du jeu pour une autre hypothèse (statut `consumed_equivalent`), ni réglage d'un paramètre sur la donnée lue.
- **`G5_GATE1_FAIL` (par type)** : FAIL = FAIL (`GATES` §9.7 : aucune relecture, aucune fenêtre allongée). Annotation obligatoire :
  « **non poursuivi dans ce programme ; absence d'effet non démontrée** » si UB90(D) ≥ +10 pb ; « **effet ≥ +10 pb exclu sous la forme
  testée** » si UB90(D) < +10 pb. La clôture porte sur la **forme testée** seulement — T01 : photo 8 h, |P̂(h−5)| ≥ 0,09 %, ordre au
  repos à c(h−5) rempli `through`, sortie h+15 ; T05 : continuation preneur du signe de [h ; h+5], sortie h+60, jours non macro —, pas
  sur le mécanisme. Au plus, le type devient un **filtre** descriptif pour G1/G2/G4 (« ne pas poster autour de la photo à |P̂| élevé »,
  « ne pas suivre l'ouverture »), sous la règle de §9 (p = 1 s'il entre dans un protocole gelé après cette lecture).
- **`G5_GATE2_PASS(type)`** n'ouvre une **proposition écrite** au propriétaire — Phase 4 de l'architecture A1
  (`lp-executor-architecture.md` §5, **démo seulement**), avec la lecture économique et le budget de multiplicité du programme — **que
  si** trois conditions tiennent ensemble (relecture review-b quant M1 ; `GATES` §2.3 amendé le 2026-10-21 ; spec Gate 2
  `verdict.pass_effect` ; écrit au corps le 2026-10-22) : `G5_GATE2_PASS(type)` **et** le `PASS_PROGRAMME` de `G5-Gate2-<type>`
  (immédiat si p ≤ 0,10 / 7 ≈ 0,0143, sinon Holm à la clôture de la famille) **et** la lecture « PASS, poursuivi » (§7.6) ; sinon la
  proposition attend la clôture de la famille. Un `PASS_PROGRAMME` d'un test historique (`G5-Gate1-*`) n'ouvre **aucune** Phase 4 et
  ne compte pas pour `GATES` §1.3. Sous D7, T05 est `NOT_INFORMATIVE` par construction au Gate 2 (§10.3) : seul T01 peut atteindre
  `G5_GATE2_PASS`. **Aucun ordre** par lui-même.
- **`G5_GATE2_FAIL`** : forme close pour cette architecture ; filtre au plus ; même annotation qu'au Gate 1 (spec `verdict.fail_annotation`) :
  « absence d'effet non démontrée » si UB90(D) ≥ +10 pb, « effet ≥ +10 pb exclu sous la forme testée » sinon.
- **`NOT_INFORMATIVE` et `INCONCLUSIVE` (aux deux portes)** : **aucune conclusion sur l'effet**, ni preuve d'un effet, ni preuve de son
  absence ; jamais lus comme un FAIL ni comme « absence d'effet » ; « aucune amélioration démontrée » est la seule lecture permise. Au
  Gate 2, le `NOT_INFORMATIVE` de T05 sous D7 est fixé **par construction** avant toute donnée (§10.3) et n'apprend rien sur T05 ; un
  `INCONCLUSIVE` est un défaut du dispositif (source d'indice, C6, données, décision en attente), pas un résultat de marché.
- **Lecture obligatoire** dans chaque verdict : « PASS, poursuivi » / « PASS, non poursuivi (sous S_éco) » / « FAIL » (`GATES` §1.4).
- **Si les deux types échouent au Gate 1** : la valeur de G5 se réduit à ses **filtres** (§9) ; le programme continue (G2, G4) ; les
  deux formes testées sont closes pour le scalping à la minute sur X-Perps ; **la question « périodes précises » n'est pas déclarée
  close mécanisme compris** (quant M10) : une autre forme (autre fenêtre, autre seuil, autre type de §1.3) exige un **nouveau**
  pré-enregistrement, qui déclare avoir vu ces résultats et compte comme post-screen (p = 1 dans m) s'il est gelé après cette lecture.

---

## 9. Rapports non décisifs (descriptifs ; aucune p-value, aucun verdict ; jamais promus)

**Règle (quant m12 ; CTO m7, en tête du Markdown de sortie).** Ces rapports forment un crible de fait. Aucun n'est un résultat. Un
filtre G5 (« quand poster », « quand ne pas poster ») **n'entre dans un protocole gelé après la lecture du Gate 1 de G5 (G1, G4, un G2
ultérieur) qu'en y comptant p = 1** (post-screen, `GATES` §2.1) ; il n'y entre gratuitement que si ce protocole a été gelé **avant**
cette lecture. Pour un type scellé (§8), ses descriptifs propres sont scellés aussi.

9.1 **Minute 0 comme coût (T07)** : |rendement| 1 m moyen de la minute hh:00→hh:01 contre les autres minutes, par minute de l'heure,
sur le dernier prix ; au Gate 2, sélection adverse « hh:00–hh:01 » contre le reste de l'heure (G2). Usage : filtre (jamais d'armement
à h−1 ; fait).

9.2 **Saisonnalité de volatilité par heure UTC** (dernier prix, toute la fenêtre éligible ; `spread_seasonality` sur capture seulement :
« UNAVAILABLE » sur l'historique) ; strate « creux 02:00–06:00 » (T21) ; strate [14:00 ; 14:30] ∩ T05 (fixing BRR, T18) ; usage :
**quand poster** pour G1/G2/G4, sous la règle ci-dessus.

9.3 **Jour de la semaine** (T10) et **ouvré / non ouvré US** (T09) ; T01 par strate « jour ouvré US / week-end et fériés ».

9.4 **T01** : version **classée par R_h** (ancien classifieur, zone morte du taux) ; version **zone morte** (P̂ > +0,06 % ou < −0,05 %) ;
version **sans proxy de fill** (dérive inconditionnelle ; son net garde 13 pb car rien n'y mesure la jambe d'entrée, A1 ; sa dérive brute
est la ligne A de §9.9) ; forme en V ([h−15 ; h] contre [h ; h+15]) ; dose |R_h| ; par heure de photo
(00:00 « jour UTC / Tokyo », 08:00 « composite HKEX / Deribit / datés le vendredi », 16:00 seul — en plus de C7) ; vendredi contre autres
jours (T08) ; strate T02 (règlements à 4 h, 2 h, 1 h : net et F) ; version **filtrée sur le seul règlement précédent** (critère du
règlement suivant coupé : `T01_previous_settlement_filter_only`, Annexe B demande 18, codée le 2026-10-22 ; §11.1) ; **sur le mark, dans D_cov seulement** ; équilibre de |P̂| entre
événements et contrôles ; par instrument ; par mois ; par signe ; histogramme des intervalles de funding et part des règlements escaladés ;
régime rv24 > médiane (rv24 sur les 24 h précédant h ; médiane sur l'historique éligible ≤ 30 j, indéfinie avant 7 jours ; T14) ; stress
du funding au plafond.

9.5 **T05** : horizons 15 / 30 min ; |mouvement| ≥ 1 tick ; par mois ; par instrument ; régime rv24 ; jours CPI/NFP **rapportés à part**
(jamais dans le test) ; jours de publications de **10:00 ET** (JOLTS, ISM ; liste gelée P-G5-5) — elles tombent à 14:00 UTC dans
[h+5 ; h+60] au Gate 1 (15:00 au Gate 2) et sont un **bruit déclaré, non exclu** (quant m9) ; adjacence BRR. Aucune lecture « sur le
mark » pour T05 (le mark n'est pas récupéré hors D_cov, quant M8).

9.6 **Lecture portefeuille** : instants admis, fills/jour, PnL net USD, coupes 3 σ₁₅ déclenchées, sous les deux hypothèses de `posMode`.

9.7 **Communs** : distribution du nombre d'instruments par instant et strate ≥ 3 ; part exclue par instrument (`vol = 0`, éligibilité,
données manquantes) ; taux de fill aux événements et aux contrôles ; sensibilité à blocs de 7 jours.

9.8 **Différés au Gate 2 (strates sur la capture, jamais de test sans nouveau pré-enregistrement gelé avant la lecture du Gate 1)** :
T02 photos 4 h (`premium`, `settState` du canal `funding-rate`, carnet) ; T06 clôture US 20:00/21:00 UTC (réversion, `trades.source`) ;
T18 fixing CME (spécifications CME à consulter) ; T20 imbalance de quart d'heure (flux signé ; hors scalp ; décision 12).

9.9 **Amendement A1 — rapports obligatoires, rapportés, jamais décisifs** (spec `descriptive.reports` et bloc `descriptive.a1`, lus mot
pour mot ; aucun p-value ; scellés avec le type s'il est `INCONCLUSIVE` ou `NOT_INFORMATIVE` ; définitions complètes en A1.3–A1.7) :
- `abc_signal_execution_net` (par type) — **A** (signal) : rendement brut signé du prix de référence sur `return_window_min` à **tous** les
  instruments-instants classés (classifieur ou signe passés, proxy de fill coupé), sans funding ni coût ; **B** (exécution) : le même
  rendement aux seuls instruments-instants de la mesure primaire (remplis par le proxy ; une entrée preneur est remplie par construction,
  B = A pour T05) ; **C** (net) : le net primaire = B + funding − coûts du type, et la lecture portefeuille ; lignes événements, contrôles et
  événements − contrôles, avec n instants, n instruments-instants, jours, taux de remplissage, moyenne, médiane, LB90 / UB90 du bootstrap par
  jours (même graine et mêmes rééchantillons que la statistique décisive ; la ligne C événements − contrôles **est** D) ;
- `T01_filled_vs_unfilled_signal` — A aux remplis contre A aux non-remplis, aux événements et aux contrôles ; écart rempli − non-rempli
  (signature de la sélection adverse) avec LB90 / UB90 ; taux de remplissage événements, contrôles et leur différence ;
- `T01_taker_variant_without_fill_conditioning` — entrée **preneur** à c(h−5) plus le demi-spread de conception (3 pb), sans
  conditionnement au remplissage (population A), même funding, même sortie, coûts du rôle `taker_maker` (15,5 / 23,25 pb) : D, net moyen
  d'événement et son stress ; c(h−5) n'étant conditionné à aucun mouvement du dernier prix, le demi-spread n'y chevauche aucun rebond ;
- `T01_cost_sensitivity_pre_a1` — valeurs de C2 / C3 au total d'avant A1 (13 / 19,5 pb) : le net de chaque instant baisse de 3,5 pb
  (5,25 pb au stress) ; D inchangé ;
- `chronological_stability_month_and_window_halves` (par type) — D et n par **mois calendaire** UTC et par **moitié de la fenêtre du type**
  (jours calendaires : T01 [D_cov ; END_EXCLUSIVE), T05 la fenêtre du spec ; première moitié = les ⌊L/2⌋ premiers jours), pour C et pour A
  (distinct de C4, qui coupe à la médiane des jours d'événement) ;
- `diagnostic_classification_a1` (par type) — la classification de A1.7, **jamais un verdict**.

---

## 10. Gate 2 — confirmation prospective (spec `g5_gate2_spec.json`, gelé avec le Gate 1)

10.1 **Données** : capture publique continue `research_lp capture` (hôte EEE, canaux `trades`, `books5`, `bbo-tbt`, `mark-price`,
`funding-rate`, `instruments` ; D1), statut `prospective`, garde `QUARANTINE_FLOOR = 2026-11-02T00:00:00Z` (`xperp_capture.py` l.87) ;
`source: "capture"` ; prix de référence = derniers prints à la minute (dernier prix, cohérent avec le Gate 1) ; classifieur P̂ = mark de
la capture et **indice** selon la décision P-G5-10 (ii) — une seule grandeur `premium_mark_index` aux deux portes (CTO M2) ; le champ
`premium` du canal (prime officielle) sert à la seule comparaison descriptive ; règlements lus dans la tape, intervalle par instrument.
Univers = celui de G2 §3 (métadonnées + comptages P3, ≤ 40 X-Perps), filtre `listTime + 30 j`. **La règle de liquidité de G2 §3.3**
(instrument-jour éligible si le notionnel du jour UTC précédent atteint 1 M USD) **n'est pas appliquée** au Gate 2 de G5 : l'univers ne
dépend que des métadonnées, des comptages P3 et de `listTime + 30 j`, fixés au gel (choix (5) de l'Annexe B, écrit le 2026-10-22 ; spec
`universe.liquidity_rule_g2_3_3`, le lecteur n'admet que `not applied…`).

**Funding du Gate 2** (relecture review-b quant M3 ; écrit au corps le 2026-10-22) : taux réglé du canal `funding-rate`. Avant le gel,
sa sémantique est vérifiée sur la documentation et sur un **échantillon de schéma** seulement — noms de champs, valeurs de `settState`,
`fundingTime` contre `ts` —, **aucun taux affiché ni persisté hors de la tape** (`GATES` §9.5 ; ligne `selection` annoncée au registre ;
variante admise sous la même restriction : les deux swaps globaux, hors univers G5) ; jamais une lecture des taux de « la première
capture ». Le texte `FROZEN` de `costs.funding.source` est `g5_gate2.FUNDING_SOURCE` (taux réglé attribué au règlement **précédent**,
docs-v5 ; relecture CTO review-b B1), écrit au gel après l'échantillon (§2.4 ligne 10) ; le texte du brouillon reste tant que le
lecteur l'accepte en brouillon.

**D10 (2026-10-21)** — source d'indice de P̂ (P-G5-10 (ii), `LP-OWNER-DECISIONS-2026-10-21.md` D10 : « On essaye des deux un par un
puis on décide ») : **les deux sources**. (a) Canal public `index-tickers` ajouté à la capture continue (extension de D1 au titre du
§9.4 ; `xperp_capture.CHANNELS`, une souscription par `index_id` = `uly` des instruments capturés, même hôte EEE et même chemin
`/ws/v5/public` — docs-v5 « Index tickers channel », copies du 2026-10-20 de `a4-data-desk/txt/` ; layout `index_tick` (`ts_us`,
`recv_us`, `idx_px`) de `tape.py`, même reprise par segments, mode comptage sans valeur, plancher `QUARANTINE_FLOOR`, manifeste) ;
idx_a(g) = dernier `idxPx` reçu dans (g − 60 s ; g]. (b) `history-index-candles` récupéré **une fois après la fin de la fenêtre** ;
idx_b(g) = clôture de la bougie 1 min confirmée finissant en g. **Règle, écrite avant la capture et jamais révisée après une lecture de
T01** : sur les paires (index_id, g) des minutes [T0 ; fin exclusive de T01) (sans prolongation pour trous), la source primaire est (a)
si sa **couverture** est ≥ 98 % des paires **et** si la **médiane** de |idx_a − idx_b| / idx_b × 10⁴ sur les paires communes est
≤ 0,5 pb (aucune paire commune : règle non remplie) ; sinon (b). L'autre source est une **sensibilité rapportée** de T01, jamais
décisive. Le choix est calculé sur ces seules mesures, **haché** (sha256 du JSON canonique de la règle, des mesures et du choix) et
écrit (`<out>.index-selection.json`, puis le résultat) **avant toute évaluation de T01** ; `g5_eval.run_study` refuse d'évaluer un
spec D10 sans choix enregistré et vérifié (hash, seuils, conditions, fenêtre, source en place). Interdit : comparer les résultats de
T01 obtenus avec chaque source pour choisir. Spec : `classifier_index_source.choice = d10_both_primary_by_quality_rule`, bloc
`selection_rule` lu strictement (`g5_gate2._parse_selection_rule`). **Fetcher de (b) (2026-10-21 ; CTO review-b M3 levé)** :
`g5_index_fetch` (commande `research_lp g5-index-fetch`) lit `history-index-candles` (barre 1 min, `instId` = `index_id` = `uly` des
instruments de `universe.analysed`, et eux seuls, liste hachée) **une seule fois, après la fin de la fenêtre** (refus tant que l'heure UTC
courante ne dépasse pas la fin exclusive), sur le seul spec Gate 2 `FROZEN` dont le sha256 est donné (`--expect-spec-sha256`, comparé avant
lecture), dans [premier t_arm scoré − 480 min − 60 min ; fin de la grille du spec) et jamais sous `QUARANTINE_FLOOR` (refus d'un départ
dans la quarantaine 2026-10-14 → 2026-10-31, avant le plancher ou avant le lookback de T0 ; toute ligne servie hors de cet intervalle est
écartée avant le stockage et comptée) ; `--decision-ref` (texte de D10) et `--max-requests` obligatoires, plan calculé et refusé s'il
dépasse le plafond ; même flux, limiteur, boucle de requête, backoff, reprise et verrou que `g5_fetch`, dont la borne `END_EXCLUSIVE` du
Gate 1 n'est pas touchée ; sortie au layout `index_1m` ; reçu haché dont le digest de `index_1m/` est la valeur `--expect-index-tape-sha256`
de `g5-eval` (`tests/test_research_lp_g5_index_fetch.py`, transport factice, garde `socket`). Le lecteur admet donc (b) et D10 en `FROZEN`
(`g5_gate2.INDEX_FROZEN_ALLOWED`) ; (b) est à la fois la comparaison et le repli.

**Plancher de séparation du pilote L2 (P-L2-1, D14 ; §10.6).** La capture commence au plus tôt le 2026-11-02 (plancher de quarantaine),
mais aucune de ses lignes horodatées avant **2026-11-03T12:00Z** n'est lue par le Gate 2 : `g5_eval.load_capture_series` n'ouvre aucun
fichier jour du 2026-11-02, écarte et compte les lignes de [2026-11-02T00:00Z ; 2026-11-03T12:00Z) (`rows_in_pilot_separation_dropped`)
avant tout calcul, décisif ou descriptif ; `g5_index_fetch` refuse un départ dans cet intervalle.

10.2 **Types** : inscrits **automatiquement** selon §8 (`PASS`, `NOT_INFORMATIVE`, `INCONCLUSIVE`) ; **mêmes** classifieur, seuil,
signes, fenêtres, contrôles (minute de l'heure, sessions en heure locale, grille par défaut, macro en heure de New York : CPI/NFP à
13:30 UTC et FOMC à 19:00 UTC en heure d'hiver jusqu'au 2027-03-14 — quant m8), unité et critères (§5–7), sélection adverse **mesurée**
par `lp_sim` au lieu de supposée, validation C6 recalculée avec R du canal `funding-rate` ; exécution simulée §5.5 (`arm_ts_us = h−5`,
jamais h−1). T05 à **14:30 UTC** du 2026-11-02 au 2027-03-12, 13:30 à partir du 2027-03-15 ; tout est défini en heure locale (piège 1).

**Règle de fill du Gate 2 — décision (b) du propriétaire, 2026-10-22** : « Choisir la traversée comme remplissage principal du Gate 2, et garder le simple contact comme comparaison descriptive ». Règle **primaire `through`** pour toute
jambe passive simulée (entrée post-only de T01 ; sorties passives de T01 et de T05) : un ordre au repos n'est rempli que par un print
strictement au-delà de son prix ou par un touch opposé qui le croise (borne **pessimiste**, comme le proxy `through` du Gate 1, §5.4) ;
non rempli ⇒ pas d'observation de T01 (taux de fill compté) ; sortie passive non remplie ⇒ reliquat preneur. `touch` (rempli au premier
print au prix : tête de file, borne optimiste) et `queue` sont rapportées dans le descriptif `fill_rules_touch_and_queue`, **comparaison
descriptive, jamais décisive** : le résultat `touch` **ne peut jamais sauver un `FAIL`, un `NOT_INFORMATIVE` ni un `INCONCLUSIVE`**, ni
motiver une inscription, une prolongation, un amendement ou une lecture « poursuivi » ; il n'entre dans aucun critère C1–C7, ni dans Holm,
ni dans la porte économique. Le lecteur refuse un spec dont `execution_sim.entry_fill_rule` n'est pas `through` ou dont
`descriptive_fill_rules` n'inclut pas `touch` (`g5_gate2.PRIMARY_FILL_RULE`) et exige le texte daté de la décision
(`execution_sim.entry_fill_rule_decision`). Le mot `touch` de `fill_sim.kind` (`lp_sim_post_only_touch`) et de `entry.kind`
(`post_only_touch`) nomme le **placement** de l'ordre (au touch propre), pas la règle de remplissage.

**Choix de rédaction écrits au corps le 2026-10-22** (Annexe B « Choix de rédaction », relecture review-b quant M5 ; marqueurs
`freeze_pending:` retirés du spec `0.1.7-draft`) :
- **(1) Stress des termes mesurés (C3)** : frais et glissement × 1,5 ; les termes mesurés (sélection adverse, demi-spread) sont dans les
  prix simulés et ne peuvent pas y être multipliés : le net stressé retire en plus 0,5 × la valeur de conception du Gate 1 de chaque terme
  mesuré, **1,75 pb par jambe maker remplie** (sélection adverse 3,5 pb) et **1,5 pb par entrée preneuse** (demi-spread 3 pb) (spec
  `costs.stress_rule`, `g5_eval.measure_lp_sim`). Jamais plus faible que le stress du Gate 1 sur les termes supposés. **Écart déclaré**
  (relecture review-b m9, calcul d'audit) : le stress ajoute +5,5 pb à T01 au Gate 2 (frais 4 → 6 pb, aucune jambe preneuse,
  2 × 1,75 pb) contre +4,75 pb au Gate 1 depuis l'amendement A1 (9,5 → 14,25 pb ; +6,5 pb avant A1, 13 → 19,5 pb), le glissement de
  conception de 2 pb d'un aller-retour maker/maker disparaissant quand les deux jambes sont maker ; T05 : +7,75 pb aux deux portes.
- **(2) Holm local au Gate 2** : comme au Gate 1, `G5_GATE2_PASS(type)` exige les critères du type **et** la confirmation Holm locale à
  K = 2, α = 0,10 (`holm_local_confirmed`) ; un type non inscrit, `INCONCLUSIVE` ou `NOT_INFORMATIVE` y compte p = 1 (spec
  `multiplicity.local_holm`). Le `PASS_PROGRAMME` (m_max = 7) en est distinct (§8).
- **(6) Empreintes** : la queue de sortie simulée (T01 : h+15 → h+60 ; T05 : h+60 → h+75) **n'entre pas** dans les empreintes de
  l'exclusion des contrôles, gardées égales à celles du Gate 1 ([−5 ; +15] et [0 ; +60]), dont dépendent les heures de contrôle de §7.1
  (spec `events[*].footprint_note`). Calcul d'audit : la marge de 60 min de §7.1 tient ces queues hors des contrôles (un contrôle de T01
  après l'événement commence au plus tôt à h+75 > h+60, avant lui sa queue finit au plus tard à h−20 ; T05 : h+120 > h+75, et h−45).
- **(7) Repli REST du funding : aucun.** `GATES` §5.1 (lecture REST de `public/funding-rate-history` après T0) n'est pas implémenté
  (`g5_fetch` ne persiste rien à partir d'END_EXCLUSIVE) et n'est pas couvert par D1 (il faudrait une décision de lecture nouvelle) ; un
  taux réglé inconnu exclut l'observation (`costs.funding.unknown_rate`), comptée (`funding_unknown`). Spec : `costs.funding.fallback`
  commence par `none` depuis le 2026-10-22 (le lecteur refuse tout autre texte en `FROZEN`).

10.3 **Fenêtre et puissance (calcul d'audit).** T0 = gel du Gate 2 (≥ 2026-11-02, ≥ P3 ; **≥ 2026-11-04 depuis P-L2-1**, §10.6) ; fin ≤ **2027-03-31** (`GATES` §1.3).
- **T01** : 3 photos/jour sur ≤ 150 jours ⇒ 450 f_inst instants ; ≥ 100 si f_inst ≥ 0,23 (depuis P-L2-1 : T0 ≥ 2026-11-04 ⇒ 443 instants
  bruts au plus, 440 pour le T0 réaliste du 2026-11-05 ; f_inst ≥ 0,226 / 0,227 pour 100 instants, contre 0,224 à T0 = 2026-11-03 (446) ;
  δ₈₀ ∝ 1/√n : +0,7 % au plus) ; sans source d'indice : `INCONCLUSIVE` par
  construction (P-G5-10 ii). **D10 (2026-10-21)** : source choisie par la règle de 10.1 avant T01 ; `INCONCLUSIVE` seulement si la source
  retenue n'a aucune minute (`classifier_index_source_unresolved_or_unavailable`) ou si > 50 % des instrument-jours sont sans P̂.
- **T05** : du 2026-11-02 au 2027-03-31, **102 jours NYSE** (fériés 11-26, 12-25, 01-01, 01-18, 02-15, 03-26), moins **10** dates
  CPI / Employment Situation, toutes un jour NYSE (relevées le 2026-10-22, Annexe C) ⇒ **92 < 100** : `NOT_INFORMATIVE` **par construction** si la fenêtre s'arrête au 2027-03-31. **D7
  (2026-10-21, P-G5-10 i)** : la fenêtre s'arrête au 2027-03-31, aucune prolongation ; le `NOT_INFORMATIVE` est accepté d'avance. Pour un
  T0 atteignable (≥ 2026-11-03) : 101 jours NYSE au plus, moins 10 dates CPI / Employment Situation ⇒ **91** instants au plus (T0 au
  2026-11-05 : 89 ; 11-10 : 87 ; 11-20 : 80 ; calcul d'audit sur les listes du spec `0.1.7-draft`). **Depuis P-L2-1 (D14, spec
  `0.1.8-draft`)** : T0 ≥ 2026-11-04 ⇒ 100 jours NYSE − 10 = **90** au plus (T0 provisoire du brouillon) ; T0 réaliste 2026-11-05 (P3
  d'au moins 24 h à partir du plancher de séparation 2026-11-03T12:00Z, gel après P3) ⇒ **89** ; le `NOT_INFORMATIVE` par construction,
  la fin au 2027-03-31 (D7) et le plancher de 100 sont inchangés. Le
  lecteur calcule cette borne sur les listes gelées (`g5_gate2.power_by_construction` : un instant par jour NYSE de [T0 ; fin) hors listes
  macro, prolongation maximale permise comprise) et l'écrit dans l'audit, les notes et les raisons du verdict de T05
  (`power_floor_unreachable_by_construction`). Ce `NOT_INFORMATIVE` ne dit **rien** de l'effet de T05, ni pour ni contre (§8). (L'option
  2027-04-12, refusée, n'atteignait pas 100 non plus pour un T0 atteignable : ≈ 97–98 au mieux ; « ≈ 100 instants » était faux.)
- Futilité : **aucune** lecture intermédiaire des types d'horloge (une seule exécution à la fin) ; prolongation **pour trous** de données
  seulement, **telle que codée** (relecture review-b quant m3 ; écrit au corps le 2026-10-22 ; spec `window.data_gap_extension`,
  `g5_gate2.extended_end`) : un **jour perdu** est un jour UTC où **tous** les instruments éligibles sont inexploitables (point suivant) ;
  un jour **civil** ajouté par jour perdu (un samedi ajouté n'apporte aucun jour NYSE à T05), au plus 14, jamais sur un résultat ;
  au-delà du 2027-03-31 seulement sous les deux décisions de l'option (b) de P-G5-10 (i), refusée par D7 ⇒ **sous D7, aucune
  prolongation** (les deux fenêtres finissent le 2027-03-31) : les jours inexploitables sont comptés, jamais remplacés.
- **Instrument-jour inexploitable** (choix (4) de l'Annexe B, repris de G2 §4.4 ; écrit au corps le 2026-10-22 ; spec `quality`) :
  couverture `books5` (minutes avec ≥ 1 snapshot) < 90 % ou période `stale` de plus de 10 min qui traverse l'instrument-jour ; retiré
  avant tout calcul et compté ; plus de 20 % d'instrument-jours inexploitables dans la fenêtre ⇒ `G5_GATE2_INCONCLUSIVE` global ; plus de
  50 % d'instrument-jours sans P̂ ⇒ T01 `INCONCLUSIVE`.

10.4 **Ce que le Gate 2 ne fait jamais** : aucun ordre, aucune démo, aucun drapeau (règle 13) ; aucune analyse promue depuis les strates
du Gate 1 ; aucun type ajouté sans nouveau pré-enregistrement gelé **avant** la lecture du Gate 1 ; aucune ouverture des fichiers scellés
avant son propre verdict. **Scellement** (choix (3) de l'Annexe B ; relecture review-b quant m1 ; écrit au corps le 2026-10-22 ; spec
`sealing.gate2_own_verdicts`) : **aucun** scellement des verdicts propres du Gate 2 — aucune porte ultérieure ne dépend de ses
statistiques ; chaque verdict imprime D, LB90, UB90 et p. En contrepartie : un protocole successeur sur une forme testée à ce Gate 2
entre dans la famille m du programme par un amendement de m_max daté **avant** son propre gel, et **aucune** extension ni prolongation de
fenêtre n'est jamais motivée par ces valeurs, ni par les D, LB90, UB90 imprimés d'un `NOT_INFORMATIVE` ou d'un `INCONCLUSIVE`
(`GATES` §2.3 amendé le 2026-10-21).

10.5 **Dates de calendrier changées après le gel — règle déterministe** (§2.4 ligne 15, résolue le 2026-10-22 ; spec `calendars.versioning`,
lu mot pour mot ; capacité `calendars_versioned_as_of_union` ; `research_lp/calendar_versions.py`). Écrite avant toute donnée ; elle ne
touche ni les hypothèses, ni les seuils, ni les horizons, ni la fenêtre, ni les listes gelées.
- **Version 0** : les listes gelées du spec, relues sur les pages officielles au gel (`freeze_fill.calendars_verified_utc`).
- **Versions suivantes** : instantanés `g5-calendar-snapshot/1` (un fichier JSON par version, versions 1..K contiguës, `retrieved_utc`
  strictement croissant et postérieur au gel ; un relevé antérieur au gel appartient au spec). Contenu admis : les cinq listes de dates
  (`nyse_holidays`, `nyse_half_days`, `cpi`, `employment_situation`, `fomc_statement`), chacune avec la plage que couvre sa source
  (`covers` ; les dates hors plage restent celles de la version précédente) ; par source : URL https de la source officielle, date de
  publication écrite par la source (`published`, ou `not_stated_by_source`), date de relevé, sha256 de la page sauvegardée
  (`raw_sha256`). Toute autre clé est refusée : un instantané ne change jamais une hypothèse, un seuil, un horizon, une fin de fenêtre,
  un univers ni un coût, et ne prolonge rien.
- **Sources et priorité** : nyse.com (fériés, demi-séances) ; bls.gov (CPI, Employment Situation ; JOLTS pour la strate descriptive) ;
  federalreserve.gov (FOMC) ; ISM seulement depuis ismworld.org avec un accès officiel, sinon `UNAVAILABLE`. Aucune source secondaire,
  jamais au-dessus d'une source officielle (le lecteur refuse un autre hôte).
- **Classement à la date de décision (aucune rétrospective)** : chaque instant de décision (T01 : t_arm = h−5 ; T05 : h+5 ; un contrôle :
  son propre t_arm) est classé avec la version disponible à cet instant, la dernière dont `retrieved_utc` le précède ou l'égale. Aucune
  version postérieure ne fait d'un instant un événement ou un contrôle admissible : pour chaque jour candidat de T05, le lecteur vérifie
  qu'aucun jour retenu ne l'est contre la version de son instant de décision (refus sinon, audit `calendar_versions.as_of`) ; pour les
  contrôles, l'union des exclusions (point suivant) le garantit par construction.
- **Changements tardifs ou ambigus (règle fixée d'avance, indépendante des résultats)** : un instant n'est retenu que s'il est admissible
  sous **toutes** les versions. Date **déplacée** (même liste, même mois) : l'ancien et le nouveau jour sont exclus ; date **ajoutée**
  (réunion FOMC non programmée, fermeture NYSE exceptionnelle) : exclue ; date **annulée** (publication supprimée ou reportée) : reste
  exclue ; demi-séance listée par une partie des versions : les deux exclusions de clôture (13:00 et 16:00 ET) s'appliquent. Une date
  changée **après** l'instant de décision exclut donc l'instant : il ne prend jamais une classification inconnue à la décision, et
  aucune exclusion n'est choisie sur un résultat. L'union ne fait que retirer des instants ; elle ne dépend que des calendriers officiels.
- **Journal** : chaque version (dates de relevé et de publication, sha256 de la page et du fichier) et chaque changement entre versions
  successives (`added`, `cancelled`, `moved`) sont écrits dans le résultat (`calendar_versions`), avec les jours que l'union retire et la
  version de leur instant de décision ; le digest de l'ensemble des fichiers est inscrit au registre le jour de l'exécution. Une
  correction connue après l'exécution est consignée comme erratum au registre, sans nouvelle exécution.
- **Instantané final** : une exécution `FROZEN` exige un instantané relevé après la fin de la fenêtre scorée (prolongation comprise,
  2027-04-01 sous D7) et avant l'exécution (`g5-eval --calendar-snapshots DIR`) ; sinon `refuse_run`, aucune donnée lue. Le relevé est
  manuel, sur les pages officielles ; aucune donnée de marché.
- **Dates non publiées** : jamais inventées. JOLTS 2027 et ISM restent bloquants (`calendars.incomplete_at_draft.ten_am_et_releases`,
  §2.4 ligne 5) jusqu'à leur relevé et au codage du champ, ou jusqu'à une décision datée qui retire la strate.
- **Gate 1** : dates passées et réalisées (fenêtre close le 2026-10-14) ; aucune version postérieure n'est lue ; un erratum officiel
  d'une date passée publié avant l'exécution du Gate 1 bloque l'exécution jusqu'à un nouveau gel.
- **Effet** : chaque jour retiré par l'union retire au plus un instant de T05 (déjà `NOT_INFORMATIVE` par construction sous D7) ; T01
  n'est touché que par les exclusions macro et de session de ses contrôles (au plus quelques heures de contrôle par date changée).

10.6 **Séparation du pilote de capture L2 (P-L2-1, D14 du 2026-10-22 ; spec `pilot_separation`, égal à `research_lp.separation.BLOCK`,
sha256 du JSON canonique `4b96e2bd3987e123b597b6534a9c464580d560db399401849cf992af004d489e` ; capacité `pilot_separation_floor`).**
- **Intervalles** : fenêtre réservée R = [2026-11-02T00:00Z ; 2026-11-03T00:00Z) (fin dure du pilote, quel que soit son départ) ; marge
  12 h ; plancher de séparation F = 2026-11-03T12:00Z ; intervalle exclu E = [2026-11-02T00:00Z ; F).
- **Règle** : aucune observation horodatée dans E (prints, carnets, bbo, mark, indice, messages de funding, liquidations, open interest,
  comptages de messages) n'entre dans un calcul décisif du programme, ni dans ses empreintes, lectures préalables, rodages, calibrations,
  règles de qualité ou sélections d'instruments ; les données capturées dans E, par le pilote ou par un collecteur LP, sont des données
  de développement.
- **Gate 2 de G5** : T0 ≥ **2026-11-04T00:00Z**, premier 00:00 UTC ≥ F + 540 min (480 min de classifieur avant t_arm, 60 min de marge de
  `g5_index_fetch` ; toute empreinte commence à T0 ou après). Premières observations utilisées (calcul du lecteur,
  `first_observation_by_type_utc`) : T01 2026-11-03T16:00Z (T0 − 480 min), T05 2026-11-03T23:59Z ; lecture de l'indice (b) à partir de
  T0 − 540 min = 2026-11-03T15:00Z ; règlements utiles aux exclusions des contrôles à partir de T0 − 65 min. Les descriptifs (rv24 et sa
  médiane sur 30 jours, σ₁₅ sur 24 h de la lecture portefeuille) ne voient que des observations ≥ F (lignes de E écartées). Code : le
  lecteur refuse un spec `FROZEN` dont le bloc diffère de `separation.BLOCK`, dont `window.start` ou `freeze_fill.t0_utc` précède
  2026-11-04, ou dont la première observation d'un type précède F ; le chargeur écarte et compte les lignes de E ; `g5_index_fetch` refuse
  un départ dans E. Un brouillon peut porter un bloc synthétique (tests), rapporté (`draft_deviations`).
- **Pourquoi 12 h** : P3 ne peut pas compter pendant le pilote (même hôte, même IP, mêmes budgets de connexion), dure au moins 24 h et
  précède le gel ; avec P3 commencé au plancher F, la fin de P3 est ≥ 2026-11-04T12:00Z et T0 ≥ 2026-11-05 — exactement le T0 qu'une
  marge nulle donnerait avec P3 commencé à la fin du pilote. La marge de 12 h ne coûte donc aucun jour ; une marge de 24 h en coûterait
  un (T0 ≥ 2026-11-06 pour G5, ≥ 2026-11-07 pour G2). Les lectures préalables énumérées tiennent dans ce qui reste
  (T0 − 540 min = 2026-11-03T15:00Z > F).
- **Effet sur les confirmations** : T05 90 instants au plus (T0 2026-11-04), 89 au T0 réaliste (2026-11-05), contre 91 (2026-11-03,
  borne d'avant) ; T01 443 / 440 instants bruts contre 446, f_inst ≥ 0,226 / 0,227 pour 100 instants ; fin 2027-03-31 (D7) et planchers
  inchangés. Tableau des recouvrements de P3, G2, G4 et G5 : `LP-PROGRAMME-GATES.md` §7.4.

---

## 11. Risques, biais déclarés, pièges intégrés

11.1 **Biais vers le PASS (déclarés)** : demi-spread d'entrée de T05 fixé à 3 pb sur des carnets minces (C2, C3 seulement) ; (avant le 2026-10-21 :
à l'instant qui ouvre D_cov, la fenêtre de funding de T01 s'ouvrait avant D_cov et le funding y était supposé nul, compté
`funding_unknown`, un instant sur ≈ 211, constat de la passe A1 — **corrigé**, voir la fin de ce paragraphe) ; rejet par
bande de prix non modélisé au Gate 1 ; aucun impact de marché ; prints RPI au Gate 2 tant que `trades.source` n'est pas utilisé (G2 §0.9) ; ~~règle de fill primaire `touch` du
Gate 2~~ (constat du 2026-10-21 : borne **optimiste**, taux de fill et sélection adverse mesurée de T01 flattés) — **retiré du primaire par
la décision (b) du propriétaire du 2026-10-22** (« Choisir la traversée comme remplissage principal du Gate 2, et garder le simple contact comme comparaison descriptive ») : `through` est primaire (§10.2), le biais `touch` n'est plus qu'une
**comparaison descriptive** (`fill_rules_touch_and_queue`, avec `queue`), jamais décisive : elle **ne peut jamais sauver un `FAIL`, un
`NOT_INFORMATIVE` ni un `INCONCLUSIVE`** ; sortie passive supposée remplie à c(h+15) / c(h+60) (construction du Gate 1). **Défaut corrigé le 2026-10-21 (passe pré-gel)** : l'instant T01 qui ouvre D_cov
recevait un funding nul alors que son règlement h (couvert) a un taux connu, parce que sa fenêtre (h−5 ; h+15] s'ouvre 5 min avant D_cov ;
désormais (`g5_eval.measure`, `covered_from_us`) tout règlement T ≥ D_cov traversé est compté (taux connu payé, taux inconnu ⇒
observation exclue, `funding_rate_missing`), et seule la part d'une fenêtre qui peut traverser un règlement **hors couverture** (fenêtre
ouverte avant le prédécesseur servi de D_cov) est supposée nulle et comptée (`funding_unknown`) ; test
`test_the_t01_instant_that_opens_d_cov_pays_its_known_settlement`. Le biais « un instant sur ≈ 211 » est donc retiré pour T01. **Biais vers le FAIL** : (avant A1 : sélection adverse comptée sur l'entrée de T01
alors que le proxy de fill la mesure déjà — supprimé par l'amendement A1) ; au Gate 2, `through` primaire (décision (b) du 2026-10-22) pour
toutes les jambes passives : remplissages d'entrée plus rares, sorties passives plus souvent reliquats preneurs (frais et glissement de
preneur) ; la règle `through` ne garde que les remplissages traversés,
dont la sélection adverse mesurée est probablement **supérieure** à la valeur de conception (C2, C3) ; demi-spread de T05 en partie
contenu dans c(h+5) conditionné au signe, 0 à 3 pb (C2, C3 ; A1.2) ; funding payé par construction à T01 ; Holm à K = 2 avec p = 1 pour un type
`NOT_INFORMATIVE` ; seuil d'incitation qui réduit n ; rebond bid-ask sur le net absolu de T05. **Direction UNKNOWN** : proxy de fill
(le prix limite est c(h−5), pas le bid réel ; une transaction au bid peut satisfaire la règle quand c(h−5) était au ask) ; écart de spread
entre 13:30 et les minutes 30 de contrôle (ampleur du rebond) ; sélection de l'univers par un volume d'octobre (§3.1) ; sessions locales
fériées ignorées (contrôles un peu moins nombreux) ; **filtre « règlement suivant à 8 h » de T01 au Gate 1** (relecture review-b quant
m12, CTO review-b M1) : l'écart au règlement suivant n'est connu qu'après la décision, le filtre sur les deux voisins conditionne donc
l'échantillon sur une information postérieure à t_arm (un règlement suivi d'une escalade 2 h / 1 h, signe d'un taux au plafond, est
retiré) ; compté (`removed_by_next_neighbour_only`) et rapporté par la version filtrée sur le seul règlement précédent
(`T01_previous_settlement_filter_only`, codée le 2026-10-22, §9.4), jamais décisive ; au Gate 2, h+ est le `nextFundingTime` annoncé à
la décision (aucune information postérieure).

11.2 **Dépendance aux UNKNOWN** : couverture de `history-candles` / `history-mark-price-candles` / `history-index-candles` pour les
X-Perps et id de l'indice (sonde `limit = 1` **après** P-G5-1 seulement, hachée, valeur non affichée) ; f_inst et f_obs ; date du fetch
du funding (D_cov) ; application de l'escalade 2 h / 1 h aux X-Perps (lue dans `fundingTime`) ; σ des valeurs par instant ; fenêtre de la
moyenne mobile du mark ; écart P̂ / prime d'impact (mesuré par C6) ; part des X-Perps à 4 h ; `posMode` ; dates FOMC 2027 (« tentative » jusqu'à la réunion qui précède, toutes après T0) et toute date de calendrier changée après le gel (§2.4 ligne 15) ;
publication du BRR le week-end ; source d'indice au Gate 2.

11.3 **Pièges de la red team → où ils sont traités.** 1 DST → §4.6, §5.2, §10.2 ; 2 instants partagés → §1.4 ; 3 fenêtres recouvertes →
§7.1 (empreintes ± 60 min) ; 4 minute 0 → §7.1 (même minute de l'heure), `arm_min = −1` refusé ; 5 clustering par jour → §7.3 ; 6 jours
macro → §5.2, Annexe C ; 7 multiplicité m = 3 + 2K → §1.2, P-G5-7, P-G5-10 ; 8 n < 100 par calendrier → §1.2, §7.4 ; 9 « ≈ 600 instants »
→ §4.4, §7.4, P-G5-7 ; 10 couverture croissante → §3.3, §7.3 ; 11 prime observable → §5.1, §7.2 (classifieur unique, C6) ; 12 trois mois
de funding → §4.4, P-G5-3 ; 13 plancher du fetcher → §4.2 ; 14 bougies → §4.5 (`vol = 0`), §5.3 ; 15 survie → §3.1, P-G5-4 ; 16 liste en
quarantaine → §3.1, P-G5-4 ; 17 contrôles contaminés → §7.1 (sessions, grille par défaut, empreintes) ; 18 licence → §0.5, P-G5-1 ;
19 le fournisseur paie → §5.4 ; 20 version « signe seul » → §0.6, §9.4 ; 21 T05 preneur → §0.7 ; 22 bougies sans carnet → §0.9 (proxy de
fill) ; 23 dérive du marché, n = instants → §7.3, (B) ix ; 24 prior S3 → §0.3.

11.4 **Perte maximale** : nulle (simulation). Lecture portefeuille : PnL simulé borné par 4 × 100 USD × mouvement en 55 min.

---

## 12. Traitement de la revue (2026-10-21)

Relecture : `tradingV3-private/research/20261020T2321Z-g5-periods/review/quant.md` (verdict `NEEDS_CHANGES` ; B1–B5, M1–M11, m1–m13),
objet relu : la première version de ce brouillon (sha256 `24d6e824…a723a5f4`). Convention : **appliqué** = texte et spec modifiés ;
**prérequis** = exigé au gel (code P-G5-11 (B), décision du propriétaire, amendement hors périmètre) ; « **Réponse à la revue** » = refus
ou traitement différent, motivé ; **signalé** = hors du périmètre d'écriture de cette passe.

| Constat | Traitement |
|---|---|
| quant **B1** — T01 : événements classés par R_h (prime d'impact, h−1), contrôles par P̂ (base au prix moyen) ; κ aveugle aux faux positifs ; regard en avant de 4 min | **Appliqué** : §0.8, §1.1, §5.1, §7.2, spec `classifier`, `validation` ; un **seul** classifieur P̂(h−5), bornes ≤ h−5, mêmes seuils aux événements et aux contrôles ; R_h ne sert qu'au coût et à la validation ; matrice 3 × 3 toujours imprimée ; sensibilité ≥ 0,80, faux positifs ≤ 0,20, dénominateurs ≥ 30, sinon `INCONCLUSIVE` (C6) ; version classée par R_h descriptive ; équilibre de \|P̂\| rapporté ; prérequis (B) iv, v |
| quant **B2** — contrôles de T05 à la minute 0 pour un événement à la minute 30 : D biaisé vers le PASS | **Appliqué** : §5.3 (biais corrigé, écrit), §7.1, spec `controls.minute_of_hour` par type (T01 : 0 ; T05 : 30) ; test de non-régression sous H0 avec effet de minute 0 planté, (B) xxii. **Réponse à la revue** (traitement plus strict) : les heures fixes et les tirages aléatoires disparaissent ; les contrôles sont **toutes** les minutes du type admissibles des jours d'événement — règle déterministe, sans graine, sans heure choisie à la main, et plus de contrôles (≈ 3 par jour pour T05, ≈ 8 pour T01) |
| quant **B3** — éligibilité absente du code et de (B) ; T05 = 105, pas 123 ; δ_min 0,236 σ ; marge de 5 | **Appliqué** : §3.2, §5.2, §7.4, Annexe C, spec `eligibility`, `audit_expected` ; recompte refait (calcul d'audit, mêmes listes) : 116 jours NYSE, 105 hors CPI/NFP, 103 à ≥ 3 instruments, premier instant le 2026-04-29 ; fenêtre du spec au 2026-04-28 ; prérequis (B) vii ; l'affirmation « n = 123 ⇒ PASS ou FAIL » est retirée |
| quant **B4** — trois définitions du PASS ; `pass_programme` = Holm local dans le code | **Appliqué** : §7.5 — `G5_GATE1_PASS(type)` ⟺ critères du type **et** Holm local K = 2, α 0,10 ; seul ce verdict ouvre le Gate 2 au titre d'un PASS ; `PASS_PROGRAMME` à m_max = 7 sans effet sur le Gate 2 ; champ du code renommé (`holm_local_confirmed`, `pass_programme_immediate` ; `pass_programme` interdit dans le spec) ; prérequis (B) xvi |
| quant **B5** — `INCONCLUSIVE` puis Gate 2 « sur décision du propriétaire » après lecture | **Appliqué** : §8, spec `gate2_inscription`, `sealing` — `INCONCLUSIVE` et `NOT_INFORMATIVE` ⇒ inscription **automatique** sans futilité ; D, LB90, UB90, p et descriptifs du type **scellés** dans `sealed/<type>.json` (sha256 imprimé), ouverts après le verdict du Gate 2. **Réponse à la revue** (extension) : le scellement s'applique aussi à `NOT_INFORMATIVE`, pour la même raison (aucune information de point avant le Gate 2) ; prérequis (B) xx |
| quant **M1** — zone morte asymétrique, contraire à l'argument d'incitation | **Appliqué, option (b)** : §0.6, §1.1, §5.1 — seuil primaire \|R\| ≥ 4 pb ⟺ P̂ ≥ +0,09 % ou ≤ −0,09 %, symétrique en prime, appliqué à P̂(h−5) ; zone morte et dose descriptives (§9.4). Motif : le test vise l'instant où le payeur nommé a une incitation ; un FAIL dilué fermerait la forme sans l'avoir testée ; la perte de puissance est écrite (§7.4) et un `NOT_INFORMATIVE` envoie T01 au Gate 2 |
| quant **M2** — f par instrument ; minimum d'instruments par instant ; δ_min sans la condition ponctuelle | **Appliqué** : §7.4 — f_obs et f_inst définis, seuils exprimés sur f_inst ; δ₈₀ = max(10 + 0,84 se ; 2,12 se) ; T05 : +15,6 à +28,3 pb écrit ; « T05 ne détecte pas +10 pb » écrit. **Réponse à la revue** sur le minimum : 1 instrument par instant en primaire (n'exclut aucun instant sur une donnée lue), distribution et strate ≥ 3 rapportées ; la pondération égale par instant est la règle fixe |
| quant **M3** — bougies figées : biais sans direction garantie | **Appliqué** : §4.5, §5.4, spec `frozen_candle`, `key_candles_end_min` — exclusion de tout instrument-instant dont une bougie clé a `vol = 0` (champ présent dans `candles_1m`, établi), même règle aux contrôles, part exclue par instrument ; « rendement nul, biais vers le FAIL » retiré ; prérequis (B) viii |
| quant **M4** — composites 00:00 / 08:00 ; contrôles à 20:00 (clôture US) et 07:00 (Londres) ; composition changeante au Gate 2 | **Appliqué** : §1.4, §4.6, §7.1, spec `sessions` — sessions en heure locale (clôture et ouverture US, Londres / Xetra, Hong Kong, Tokyo, BRR, datés du vendredi) exclues à ± 60 min des contrôles ; **C7** (D > 0 à 16:00 seul) ajouté comme critère, avec son interprétation écrite d'avance ; prérequis (B) xii |
| quant **M5** — aucune photo dans l'union hors couverture ; exclusion par instants | **Appliqué** : §4.4, §7.1, spec `controls_common` — grille par défaut 00/08/16 pour T01, **00/04/08/12/16/20 toujours** pour T05 (plus strict que « hors couverture » : composition constante dans et hors D_cov) ; exclusion écrite sur les **empreintes** ; funding de T05 hors D_cov « supposé nul, non observé », compté ; prérequis (B) xi, xiv |
| quant **M6** — sélection adverse sous le défaut de `GATES` ; demi-spread de T05 absent | **Appliqué** : §6, spec `costs` — 3,5 pb par jambe maker, demi-spread d'entrée de T05 à 3 pb ; totaux 13 / 15,5 pb, stress 19,5 / 23,25 pb. **Réponse à la revue** : « ou la table G2 » est sans objet au Gate 1 — G5 est gelé avant la lecture de G2 (P-G5-9), la table ne peut pas exister au gel ; les valeurs de conception sont définitives. **Révisé par l'amendement A1** (2026-10-21, avant lecture) : T01 9,5 / 14,25 pb (jambe d'entrée mesurée par le conditionnement au remplissage, 0 supposé ; sortie 3,5 pb), 13 / 19,5 pb en sensibilité rapportée ; T05 vérifié, inchangé |
| quant **M7** — fetch aveugle trop large ; manifeste révélateur | **Appliqué** : P-G5-3, §4.3, spec `fetch_plan.pre_freeze` — fetch pré-gel limité à `funding_hist`, mode scellé ; bougies, mark, indice après le gel. **Réponse à la revue** (plus strict) : même les totaux **par instrument** sont scellés (un nombre de règlements par instrument révèle encore une escalade) ; seuls les totaux globaux et D_cov sont imprimés ; prérequis (B) xix |
| quant **M8** — mark et indice depuis `listTime` : échelle non nécessaire | **Appliqué** : §0.5, §4.1, §4.3, P-G5-1, spec `fetch_plan` — mark / indice bornés à [D_cov − 1 j ; END) ; descriptifs « sur le mark » hors D_cov supprimés ; facteur 0,4 proposé. **Réponse à la revue** (plus loin) : les 4 instruments sans jour éligible ne sont pas récupérés et les bougies partent de l'éligibilité − 1 j : ≈ 58 300 requêtes au lieu des ≈ 87 000 de la relecture |
| quant **M9** — univers choisi par un volume du 2026-10-14 | **Appliqué, options (b) + (a)** : §3.1, P-G5-4, spec `universe` — règle par métadonnées dans le snapshot post-10-31 ∩ liste D1 (enveloppe de licence ; 25 attendus) **et** dérogation écrite du propriétaire nommant l'exception et ses biais ; biais déclarés (faible sur D, plus fort sur C2/C3) |
| quant **M10** — clôtures trop fortes pour des tests sous-puissants | **Appliqué** : §8 — FAIL = FAIL, annoté « absence non démontrée » (UB90 ≥ +10) / « effet ≥ +10 pb exclu » (UB90 < +10) ; clôture limitée aux formes testées ; « mécanisme compris » retiré |
| quant **M11** — estimand inconditionnel au Gate 1, conditionnel au fill au Gate 2 | **Appliqué** : §0.9, §5.4, spec `fill_proxy` — proxy `through` primaire pour T01, même règle aux contrôles, taux de fill rapporté, version inconditionnelle descriptive. **Réponse à la revue** sur un point : la sélection adverse supposée de l'entrée est **conservée** (3,5 pb) malgré la part mesurée par le proxy ; double compte partiel déclaré, biais vers le FAIL sur C2/C3, sans effet sur D. **Révisé par l'amendement A1** : ce double compte est retiré — la sélection adverse de la jambe d'entrée est mesurée par le conditionnement au remplissage (0 supposé) ; l'ancien total reste rapporté ; la version sans proxy garde 13 pb (non conditionnée) |
| quant **m1** — 1 000 rééchantillons ; blocs d'un jour ; critère H0 | **Appliqué** : §7.3 (10 000 ; sensibilité 7 jours) ; critère H0 en (B) xxii. **Réponse à la revue** : les tests synthétiques de calibration tournent à 1 000 rééchantillons (coût), déclaré ; la calibration de la règle ne dépend pas de ce nombre au premier ordre |
| quant **m2** — C4 « calendaires » contre médiane du code ; C5 imprécis | **Appliqué** : §7.5 — C4 sur la médiane inférieure des jours d'événement (règle du code, 53 / 52 pour T05) ; C5 = net par instant d'événement, contrôles inchangés |
| quant **m3** — 252 jours contre `GATES` §1.1 ; PASS de T05 à +10 « non poursuivi » | **Appliqué** : §7.6 (seuil de rentabilité recalculé à **10,6 pb** nets sur le vrai calendrier ; remarque économique écrite) ; amendement de `GATES` §1.1 **signalé** (P-G5-7) |
| quant **m4** — amender `GATES` §3.1, §3.3 et le plan §2.2 | **Signalé** : P-G5-7 étendu (hors périmètre d'écriture) |
| quant **m5** — erreurs de PERIODS | **Appliqué** : §0.10 (écarts consignés ici ; PERIODS hors périmètre d'écriture) |
| quant **m6** — état du code périmé | **Appliqué** : §2.2 re-constaté le 2026-10-21 (digests, 56 passed / 2 skipped, tests G5 existants, refus du nouveau spec par le parseur actuel) ; à refaire au gel |
| quant **m7** — calibration sous H0 non définie | **Appliqué** : (B) xxii (taux ≤ α + 3 erreurs types, KS, FWER de Holm, non-régression B2, puissance rapportée) |
| quant **m8** — T11 en UTC fixe | **Appliqué** : §1.3, §7.1, §10.2, spec `macro_filter.tz = America/New_York` ; prérequis (B) xii |
| quant **m9** — publications de 10:00 ET dans la fenêtre de T05 | **Appliqué (déclaration)** : §9.5. **Réponse à la revue** : non exclues — il y a une publication à 10:00 ET presque chaque jour ouvré ; les exclure viderait T05 ; bruit déclaré, strate descriptive sur liste gelée (P-G5-5) |
| quant **m10** — mécanisme transversal (funding mondial) | **Appliqué** : §0.6 |
| quant **m11** — expositions D4 et r1–r10 | **Appliqué** : §0.3 |
| quant **m12** — descriptifs réutilisés comme filtres | **Appliqué** : §8, §9 (règle p = 1 en tête) |
| quant **m13** — visibilité plus restrictive que D1 | **Appliqué** : §4.8 aligné sur D1 (agrégats sans prix pour `PASS` / `FAIL`, jamais les valeurs scellées) |
| CTO **B1–B3, M1–M3, m1–m7** (`review/cto.md`) | **Prérequis** : intégrés aux lignes (B) i, ii, iii, iv, vii, viii, xiii, xv, xvii, xviii, xix, xx, xxii ; non codés par cette passe (chemins de code hors périmètre, sauf le spec) |
| **Propriétaire, 2026-10-21 — amendement A1** : une expérience qui distingue « sans avantage », « trop faible pour les coûts », « mal exécuté », « insuffisamment documenté », A / B / C mesurés à part, sans double compte | **Appliqué** (avant tout gel et toute lecture) : section « Amendement A1 », §5.4, §6, §7.5, §7.6, §9.4, §9.9, §11.1, (B) xiii et xxiii, Annexes A et B ; spec Gate 1 `0.3.0-draft` (clés `adverse_selection_entry_leg`, `cost_sensitivity_pre_a1`, bloc `descriptive.a1`, six rapports, sept capacités `a1_*`) ; spec Gate 2 `0.1.3-draft` (même règle d'entrée, piège 7) ; code `g5_eval` / `g5_gate2` ; tests `tests/test_research_lp_g5_a1.py` (16), quatre tests antérieurs adaptés (A1.11) |
| Spec machine (demande de la passe) | **Appliqué** : `python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json` (sha256 du brouillon au 2026-10-21 en Annexe A, informatif ; JSON validé, cohérence des coûts, des comptes et de `END_EXCLUSIVE` vérifiée, refus par le parseur actuel constaté) ; son sha256 de version `FROZEN` entre dans la ligne de gel (§13) |

---

## Amendement A1 (2026-10-21) — signal, exécution, net : trois mesures séparées, aucun double compte

**Statut et date.** Écrit le 2026-10-21, à la demande du propriétaire, **avant tout gel** (le protocole et les deux specs sont
`DRAFT_NOT_FROZEN`) et **avant toute lecture** : aucune donnée de marché n'a été lue ni appelée pour l'écrire (aucun endpoint, aucun
WebSocket, aucun jeu de données ; `g5-fetch` jamais lancé ; rien sous `tradingV3-private/research/20261013T100000Z-forward-okx-r1/`). Il
est déclaré comme amendement, et non fondu dans le texte, parce qu'il **abaisse le coût supposé de T01** (C2 / C3 moins exigeants de 3,5 pb,
5,25 pb au stress) : sa justification est **a priori** (structure de la mesure), l'ancien total reste rapporté, et rien de ce qui décide
entre événements et contrôles (D, C1, C4–C7, Holm) n'en dépend. Règle d'acceptation : celle de §13 point 8, appliquée avant le gel —
aucun seuil de D abaissé, aucune fenêtre allongée, aucune analyse promue.

**A1.0 Pourquoi (justification a priori, aucune donnée lue).** Un FAIL du Gate 1 ne dit pas aujourd'hui *pourquoi* : le signal peut ne
porter aucun avantage, porter un avantage plus petit que les coûts, être exploitable mais perdu par l'exécution passive (sélection adverse
au remplissage), ou n'être pas mesurable avec la puissance disponible. Le propriétaire veut une expérience qui distingue ces quatre cas. Il
faut pour cela mesurer à part **A** (mouvement après le signal, tous instants classés), **B** (mouvement après un remplissage simulé
réaliste, instants remplis) et **C** (net : B + funding − coûts, et la lecture portefeuille), et que chaque terme de coût y soit compté **une
seule fois** : mesuré dans le rendement ou supposé dans le coût, jamais les deux.

**A1.1 Double compte de T01 (correction).** §5.4 mesure T01 depuis le prix limite c(h−5), et seulement aux instants que le proxy `through`
remplit : un long n'est gardé que si le prix a traversé **strictement** c(h−5) − 1 tick pendant [h−5 ; h+1). La sélection des instants
remplis **est** la sélection adverse de l'entrée, et le mouvement défavorable qui suit le remplissage est **déjà dans r**. La valeur de
conception de 3,5 pb (Part A, §0.3) est elle-même une markout de remplissages `through` : l'ajouter en coût la comptait une seconde fois
(le « double compte partiel déclaré » de la version précédente de §6). Règle A1 : la sélection adverse de la jambe d'entrée est **mesurée
par le conditionnement au remplissage** et vaut **0** dans le coût supposé de T01 ; la jambe de sortie (sortie passive **supposée** remplie
à c(h+15), conditionnée à rien) garde **3,5 pb**. Total T01 = 4 (frais maker/maker) + 3,5 (sortie) + 2 (glissement) = **9,5 pb** ; stress
× 1,5 sur chaque terme = **14,25 pb**. L'ancien total **13 / 19,5 pb** devient une **sensibilité rapportée** (`T01_cost_sensitivity_pre_a1` :
valeurs de C2 et C3 à 13 / 19,5 pb, jamais un critère). Corollaire écrit d'avance : une mesure **non conditionnée** au remplissage (la
version sans proxy de §9.4) ne mesure pas la jambe d'entrée — son net garde la valeur de conception (13 pb). Au Gate 2, la mesure
primaire simulée mesure tous ses termes et n'est pas touchée ; la construction descriptive du Gate 1 sur les prints suit la même règle
(spec Gate 2, `adverse_selection_entry_leg`, identité vérifiée : piège 7). **Écart de stress déclaré** : le stress de T01 ajoute +4,75 pb
au Gate 1 depuis A1 (9,5 → 14,25) contre +5,5 pb au Gate 2 (frais × 1,5 plus 0,5 × la valeur de conception par jambe maker remplie).

**A1.2 T05 : vérification du double compte (conclusion : aucun terme ne double un autre ; un chevauchement partiel, déclaré, non
corrigé ; T05 inchangé à 15,5 / 23,25 pb).** (i) Frais 5 + 2 pb : distincts. (ii) Sélection adverse 3,5 pb sur la jambe maker de sortie :
la sortie passive est supposée remplie à c(h+60), aucune mesure n'est conditionnée à son remplissage, rien ne la mesure — gardée. (iii)
Demi-spread d'entrée 3 pb (milieu → touch payé) et glissement 2 pb (touch → prix moyen exécuté : profondeur, latence) : deux étapes
distinctes du même ordre, pas un même terme. (iv) **Demi-spread contre dernier prix** : l'entrée est c(h+5), clôture du dernier prix, et le
côté s est le signe de c(h+5) − c(h) ; conditionnée à s, c(h+5) est plus souvent un échange du côté que le preneur paierait (rebond de Roll :
après une hausse, le dernier échange est plus souvent à l'ask). Une part du demi-spread est alors **déjà** dans c(h+5) : si q est la
probabilité que c(h+5) soit au touch payé, la surcharge est (2q − 1) × 3 pb, entre 0 et 3 pb, q **UNKNOWN**. Effet : vers le FAIL sur C2 / C3,
nul sur D (même règle de signe aux contrôles, à la même minute de l'heure). Non corrigé : q n'est pas mesurable sur bougies, une correction
serait une hypothèse vers le PASS, et le Gate 2 mesure le demi-spread réel. (v) La variante preneur de T01 (A1.5) entre à c(h−5), qu'aucun
mouvement du dernier prix ne conditionne : son demi-spread ne chevauche aucun rebond.

**A1.3 Tableau A/B/C (obligatoire, rapporté, non décisif).** Par type, aux événements, aux contrôles et en différence : **A** = rendement
brut signé du prix de référence sur la fenêtre de rendement pré-déclarée (`return_window_min`) à **tous** les instruments-instants classés
(classifieur ou signe passés, remplis ou non ; c'est le descriptif « sans proxy » de §9.4, formalisé en brut), sans funding ni coût ;
**B** = le même rendement aux seuls instruments-instants de la mesure primaire (remplis ; T05 : remplis par construction, B = A) ; **C** = le
net primaire (B + funding − coûts du type) et la lecture portefeuille (§9.6). Pour chaque ligne : n instants, n instruments-instants,
jours, taux de remplissage, moyenne, médiane, LB90 et UB90 du bootstrap par blocs de jours du bloc `statistics` (même graine, mêmes
rééchantillons ; aucune p-value). La ligne C événements − contrôles **est** D (mêmes tirages). A et B ne portent aucun terme de coût ;
C retire chaque terme supposé une fois.

**A1.4 Analyse des non-remplis (rapportée).** Pour T01, aux événements et aux contrôles : A aux instants remplis contre A aux non-remplis
(moyennes par instant), écart rempli − non-rempli avec LB90 / UB90 (bootstrap par jours, les deux échantillons d'un jour ensemble) ;
taux de remplissage événements, contrôles et leur différence ; fenêtres de fill manquantes comptées. Un écart **négatif** aux événements
est la signature de la sélection adverse : on n'est rempli que quand le prix va contre la position.

**A1.5 Variante preneur de T01 (descriptive).** Entrée preneur à c(h−5) plus le demi-spread de conception (3 pb), **sans conditionnement au
remplissage** (population A), même funding et même sortie, coûts du rôle preneur/maker (15,5 pb, stress 23,25 pb) : D, net moyen
d'événement et son stress. Elle sépare « mal exécuté » (le preneur garde un net positif que le fournisseur perd au remplissage) de
« sans avantage » (le preneur ne gagne rien non plus).

**A1.6 Stabilité chronologique (descriptive).** D et n par mois calendaire UTC, et par moitié de la fenêtre du type (jours calendaires ;
T01 : [D_cov ; END_EXCLUSIVE), T05 : la fenêtre du spec ; première moitié = les ⌊L/2⌋ premiers jours), pour C (net) et pour A (signal).
Distincte de C4 (moitiés à la médiane inférieure des jours d'événement), qui reste le critère.

**A1.7 Classification diagnostique (rapportée, jamais un verdict ; écrite d'avance, mot pour mot dans `descriptive.a1`).** Notations : G =
A_événement − A_contrôle (moyennes par instant), LB90 / UB90 de G par le bootstrap ci-dessus ; C_év = net moyen d'événement (la quantité de
C2) ; gap = A_rempli − A_non-rempli aux événements ; preneur = net moyen d'événement de la variante A1.5. Première règle vraie dans l'ordre :
1. **(d) insuffisamment documenté** — verdict `NOT_INFORMATIVE` ou `INCONCLUSIVE`, ou n sous le plancher (§7.4), ou pas d'intervalle pour
   G, ou **LB90(G) ≤ 0 et UB90(G) ≥ +10 pb** (l'intervalle contient à la fois « aucun effet » et l'effet testé : « absence d'effet non
   démontrée », même langue que l'annotation du FAIL, §8) ;
2. **(a) signal sans avantage** — LB90(G) ≤ 0 (donc UB90(G) < +10 pb : un avantage de la taille testée est exclu) ;
3. **(c) signal exploitable mal exécuté** — LB90(G) > 0, le type a un proxy de fill, UB90(gap) < 0, B_év < A_év, C_év ≤ 0 et preneur > 0 ;
4. **(b) avantage trop faible pour les coûts** — LB90(G) > 0 et C_év ≤ 0 ;
5. **aucun des quatre** — LB90(G) > 0 et C_év > 0 (signal et net positifs ; seuls C1–C7 et Holm disent si c'est un PASS).
Chaque entrée et chaque condition sont imprimées. T05 n'a pas de proxy : jamais (c). Sous H0 et σ de conception, T05 tombera
probablement en (d) : c'est la conclusion de §7.4 (δ₈₀ ≈ +15,6 à +28,3 pb), écrite d'avance. Pour un type scellé, la classification est
scellée avec ses descriptifs (§8).

**A1.8 Frontières (écrit et vérifié par le code et les tests).** Un instrument-instant dont l'**empreinte** (§7.1) finit après la fin de la
fenêtre — END_EXCLUSIVE = 2026-10-14T00:00Z au Gate 1 — est exclu et compté (`crosses_end_exclusive`) ; une empreinte qui finit
exactement à END_EXCLUSIVE lit la bougie qui se ferme à END_EXCLUSIVE (ouverte à 23:59, horodatée avant la borne) : gardée. Une empreinte
qui commence avant `listTime + 30 j` est exclue et comptée (`excluded_ineligible`). Les deux règles valent pour les événements **et** les
contrôles (comptes agrégés dans `exclusions.controls.restriction`). Aucun générateur du spec n'atteint la borne de fin (T01 : h ≤ END_EXCLUSIVE − 8 h,
le règlement suivant devant être servi ; T05 : 14:30 UTC ; contrôles : empreintes finissant au plus tard à 23:30, la candidate de 23:30 de
T05 étant déjà retirée par la grille de 00:00) : le compteur doit valoir 0 et la garde est testée sur des instants synthétiques. Déclaré : la fenêtre de P̂ (480 minutes avant h−5) n'est pas une empreinte ; elle peut commencer avant l'éligibilité
(mark et indice sont récupérés dès l'éligibilité − 1 jour, §4.3), sous la règle de couverture ≥ 90 %.

**A1.9 Ce qui ne change pas.** Hypothèses, signes, fenêtres, classifieur, proxy de fill, contrôles, unité, plancher, seuil +10 pb, α, bootstrap,
C1 et C4–C7, précédence des verdicts, Holm local et programme, scellement, inscriptions au Gate 2, licence et plan de fetch. La porte
économique garde sa règle ; elle lit le net d'événement de T01 aux coûts A1 (sa valeur à 13 pb se déduit de la sensibilité).

**A1.10 Ancien / nouveau.**

| Élément | Avant A1 | Après A1 |
|---|---|---|
| Sélection adverse, jambe d'entrée de T01 | 3,5 pb supposés (double compte partiel déclaré) | mesurée par le conditionnement au remplissage, 0 supposé (`adverse_selection_entry_leg: measured_by_fill_proxy`) |
| Sélection adverse, jambe de sortie de T01 | 3,5 pb | 3,5 pb (inchangé) |
| Coût supposé de T01 (C2) / stress (C3) | 13 / 19,5 pb | **9,5 / 14,25 pb** ; 13 / 19,5 pb rapportés (`cost_sensitivity_pre_a1`) |
| T01 sans proxy (descriptif, net) | 13 pb | 13 pb (non conditionné) |
| T05 (C2 / C3) | 15,5 / 23,25 pb | 15,5 / 23,25 pb (vérifié, A1.2) |
| D, C1, C4–C7, Holm, plancher, seuil +10 pb | — | inchangés (les coûts s'annulent dans D) |
| Seuils économiques nets (2,2 / 8,9 / 10,6 pb) | — | inchangés ; en dérive brute de remplissage, −3,5 pb pour T01 |
| Rapports | — | A/B/C, non-remplis, variante preneur, sensibilité, stabilité, classification (§9.9), obligatoires |
| Frontières | empreinte au-delà de la fin exclue, comptée avec la couverture | compteur dédié `crosses_end_exclusive`, éligibilité, contrôles compris |
| Spec Gate 1 | `0.2.0-draft`, 27 capacités, sha256 `d4189493…f85af80a` | `0.3.0-draft`, 34 capacités, sha256 `2ab0073cf53003d65ff46b3f62700faac46ec2fd00cdef7a529baeca362b15a6` |
| Spec Gate 2 | `0.1.2-draft`, sha256 `e29c4f20…51d390cf` | `0.1.3-draft`, sha256 `f506f157fdf8af6a725899fca42acc3ae0f1be05715712e28d3bbef35934c7a3` (brouillon accepté) |

**A1.11 Implémentation et tests (hors ligne, synthétiques).** Spec Gate 1 : `events[T01].adverse_selection_entry_leg`
(`measured_by_fill_proxy` | `assumed_design_value` ; absente = règle d'avant A1), `events[T01].cost_sensitivity_pre_a1` (obligatoire avec
`measured_by_fill_proxy`, refusée sinon, comparée aux totaux de conception), `expected_cost_bp` 9,5 / `expected_cost_bp_stress` 14,25 vérifiés
contre `cost_terms(rôle, coûts, règle)` (toute incohérence refusée ; `measured_by_fill_proxy` refusé sur une entrée preneur ou sans proxy),
six rapports A1 obligatoires et le bloc `descriptive.a1` (statut, définitions, précédence, règles : lus mot pour mot), sept capacités `a1_*`
dans `requires` (Gate 1 seulement). Code : `g5_eval.cost_terms(…, entry_adverse)`, `type_cost_terms`, `check_type_costs`, `restrict`
(`crosses_end_exclusive`), `measure` (`fill_status`, `fill_flag`), `report_abc`, `report_filled_vs_unfilled`, `report_taker_variant`,
`report_cost_sensitivity`, `report_chronology`, `diagnostic_classification`, rendu Markdown du tableau ; `g5_gate2` (clé et identité).
Tests : `tests/test_research_lp_g5_a1.py` (16 : 9,5 pb avec la clé et 13 sans ; refus des incohérences ; identité Gate 2 ; frontières ;
tableau A/B/C ; non-remplis ; variante preneur ; sensibilité ; stabilité ; classification sur des tapes synthétiques construites pour
tomber en (a), (b), (c), (d) — sous le plancher et intervalle trop large — et « aucun des quatre »). Tests antérieurs adaptés **parce que
A1 les rendait faux** : `test_the_real_spec_is_accepted_and_every_field_is_read` (sha256, 34 capacités, coûts A1),
`test_costs_by_role_funding_signs_and_funding_outside_coverage` (net à 9,5 / 14,25 pb), `test_a_planted_effect_passes_c1_to_c7_and_holm_with_the_programme_field`
(écart de stress 4,75 pb), `test_c7_c4_and_c2_are_criteria_of_their_own` (C2 en échec démontré sur le spec sans la clé à 13 pb ; sous A1,
C3 seul échoue), `test_the_real_gate2_draft_is_accepted_and_every_block_is_read` et l'assistant `frozen` (9,5 pb au Gate 2), et
`test_the_gate2_simulation_maker_taker_exit_grace_and_stress` (construction du Gate 1 à 9,5 pb). Suite `tests/test_research_lp_*.py`
verte hors réseau (résultat exact dans les notes de la passe, `tradingV3-private/research/20261021T0700Z-scalping-diagnostic/g5-a1/NOTES.md`).

---

## 13. Procédure de gel

Deux portes, gelées **ensemble**, avant toute lecture et avant la lecture de G2 (P-G5-9) ; méthode de `PREREGISTRATION-H2-GATE1.md` §15.
Spec Gate 2 (ligne du 2026-10-21, volet `docs-b`) : `python-orchestrator/scripts/research_lp/specs/g5_gate2_spec.json` (brouillon, Annexe B) ; son sha256 de version `FROZEN`, sans aucune valeur `owner_decision_pending` restante, fait partie du gel au même titre que celui du spec Gate 1 (points 3 et 5).

1. P-G5-1 … P-G5-11 satisfaits et consignés (`a1-protocol/NOTES.md`, registre du programme) ; verdict `fwd-okx-r1` rendu ; fetch du
   funding scellé fait et haché (P-G5-3), D_cov inscrit au registre.
2. Snapshot `instruments` relevé (P-G5-4), liste et exclusions écrites en « **Annexe I — instruments figés** » ajoutée **au-dessus** de
   cette section ; sha256 du snapshot inscrit dans le spec (`freeze_fill`) ; Annexe C et listes du spec re-vérifiées
   (`calendars_verified_utc`).
3. Specs JSON : `python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json` (ce brouillon, complété) et
   `python-orchestrator/scripts/research_lp/specs/g5_gate2_spec.json` (Annexe B), `status` passé à `FROZEN`, `freeze_fill` complet ;
   **sha256 de chacun inscrit ici** ; `requires` couvert par le code ; `python -m scripts.research_lp.cli g5-eval --spec … --expect-spec-sha256 …`
   sera la seule forme d'exécution ; sorties dans `tradingV3-private/research/20261020T2321Z-g5-periods/run/`.
4. Code : digest canonique de `cli.code_identity()` (sha256 de chaque module de `research_lp`, dont `g5_fetch.py`, `g5_eval.py`)
   inscrit (et recopié dans `freeze_fill.code_digest_sha256`) ; versions de Python et de numpy ; suite `tests/test_research_lp_*.py` verte,
   hors réseau ; relecture CTO du code (B) faite.
5. Remplacer `DRAFT_NOT_FROZEN` par `FROZEN <horodatage UTC>` ; écrire `FROZEN <UTC> body sha256 <…> spec-gate1 sha256 <…> spec-gate2
   sha256 <…> code sha256 <…> runtime <…> funding-sealed sha256 <…> (D_cov = <…> ; not executed)` sous cette section ; le corps haché est
   le fichier **jusqu'à la ligne « ## 13. Procédure de gel » incluse et la ligne vide suivante** ; écrire `LP-G5-DRAFT-PREREGISTRATION.sha256`.
6. Publier (PR) **avant** l'exécution de l'évaluateur et **avant** le fetch des bougies, du mark et de l'indice ; ce fetch exige
   `--decision-ref` et `--max-requests` (P-G5-1) ; ses manifestes et `fetch.log` sont hachés et joints.
7. **Exécution unique par l'orchestrateur** : `g5-eval` sur la fenêtre déclarée, sortie JSON + Markdown hachée, jointe à
   `LP-G5-GATE1-RESULT.md` avec le journal (date, `spec_sha256`, `code`, `coverage`, `exclusions`, matrice C6, n par type, verdicts,
   Holm local, `pass_programme_immediate`, lecture économique, sha256 des fichiers scellés) ; registre mis à `consumed_equivalent` le
   même jour ; inscriptions au Gate 2 appliquées telles que §8 les fixe.
8. Toute modification après le gel = **amendement** écrit, daté, publié **avant toute lecture**, avec sa règle d'acceptation ; aucun
   seuil abaissé, aucune fenêtre allongée, aucune analyse promue (`GATES` §9.7).

---

## Annexe A — Spec Gate 1 (fichier du dépôt)

**Chemin** : `python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json` (schéma `g5-spec/2`, `status: "DRAFT_NOT_FROZEN"`,
version `0.4.1-draft` depuis la passe calendriers du 2026-10-22 — CPI 2026-10-14 inscrite dans `calendars.cpi`, sources datées,
textes libres seulement —, `0.4.0-draft` depuis les décisions du propriétaire du 2026-10-22, `0.3.0-draft` pour l'amendement A1). sha256
du brouillon `0.4.1-draft` (2026-10-22), **informatif** (le sha256 qui compte est celui de la version `FROZEN`, §13) :
`1d86d3f538da49010f5e70edb2d55d1200c34ac56e4d30cdec5cc96d4cd35a12` ; brouillon `0.4.0-draft` (2026-10-22, décisions (a) et (b)) :
`d2a22f7195ae423b6f5463904d5a3ccf9560f5a635a2ff212c3c0a1812b97f5e` ; brouillon `0.3.0-draft` (2026-10-21, après A1) :
`2ab0073cf53003d65ff46b3f62700faac46ec2fd00cdef7a529baeca362b15a6` (version `0.2.0-draft` d'avant A1 :
`d4189493d896fe508b36f32da6433c03305cd4867fdc5fe94ff67d17f85af80a`, aussi dans `a1-protocol/NOTES.md` §9). Valeurs clés, à lire dans le fichier (en cas d'écart, le texte de ce protocole fait foi au gel et
le spec est corrigé avant le gel, jamais après) :

| Clé | Valeur |
|---|---|
| `end_exclusive_utc` / `window` | `2026-10-14T00:00:00Z` (égal à `g5_fetch.END_EXCLUSIVE`) / 2026-04-28 → 2026-10-14 |
| `universe.analysed` / `excluded_never_eligible` | 25 instruments (§3.1) / PONS, STRK, CT, QUANT (non récupérés) |
| `eligibility.days_after_list_time` | 30, sur le début de l'empreinte, événements et contrôles |
| `price.source` | `last` (`candles_1m.close`) ; mark et indice : classifieur seulement |
| `unit` | instant, pondération égale, ≥ 1 instrument, n = instants uniques |
| `statistics` | `net_bp`, α 0,10 unilatéral, seuil +10 pb, bootstrap blocs d'un jour, 10 000, graine 20261102 ; sensibilité 7 jours |
| `power_floor` | 100 instants, 30 jours |
| `costs` | maker 2, taker 5, sélection adverse 3,5 / jambe maker dont la sélection adverse est supposée, glissement 2, demi-spread preneur 3, stress × 1,5 ; totaux 9,5 (T01, A1) / 15,5 |
| A1 (T01) | `adverse_selection_entry_leg: measured_by_fill_proxy`, `expected_cost_bp` 9,5 / `expected_cost_bp_stress` 14,25, `cost_sensitivity_pre_a1` 13 / 19,5 (rapporté) |
| A1 (`requires`, `descriptive`) | sept capacités `a1_*` (34 au total avant le 2026-10-22, 38 depuis) ; rapports `abc_signal_execution_net`, `T01_filled_vs_unfilled_signal`, `T01_taker_variant_without_fill_conditioning`, `T01_cost_sensitivity_pre_a1`, `chronological_stability_month_and_window_halves`, `diagnostic_classification_a1` ; bloc `descriptive.a1` (statut, définitions, précédence et règles de la classification) |
| T01 | `funding_settlements` (8 h ± 1 min des deux côtés, réalisé requis), D_cov, empreinte [−5 ; +15], `arm_min −5`, `rest_until_min 1`, classifieur `premium_wavg` à t_arm (480 min, ≥ 90 %, ±0,0009), proxy `through_candles`, bougies clés −5 / +15, validation C6 (±0,0004 ; 0,80 / 0,20 ; ≥ 30), contrôles minute 0, grille 00/08/16, C7 à 16:00 |
| T05 | 09:30 `America/New_York`, jours NYSE hors CPI / Employment Situation, empreinte [0 ; 60], signe [0 ; 5], `arm_min 5`, entrée preneur, sortie h+60, bougies clés 0 / 5 / 60, contrôles minute 30, grille 00/04/…/20 |
| `controls_common` | toutes les heures admissibles, aucun tirage, marge 60 min entre empreintes |
| `sessions`, `macro_filter`, `calendars` | listes de §4.6 et de l'Annexe C, heure locale ; relues sur les pages officielles le 2026-10-22 (`calendars.sources` datées) ; aucune demi-séance NYSE dans la fenêtre |
| `verdict`, `multiplicity`, `sealing`, `gate2_inscription` | §7.5, §8 |
| `economic` | S_éco **97,25** (≥ 97,25 exigé par le lecteur, D4 ; 97 avant le 2026-10-22), 4 × 100 USD, 1×, 365 / 252 ; seuils de rentabilité recalculés (10,66 / 2,22 / 8,88 pb) |
| `fetch_plan` | funding scellé avant le gel ; ≈ 58 285 requêtes au total (D_cov supposé au 2026-08-04) |
| `freeze_fill` | snapshot, calendriers vérifiés, digest du code, `decision_ref` : nuls dans le brouillon, obligatoires au gel |
| Décision (a) du 2026-10-22 | capacités `gate2_frozen_together` (Gate 1 seul), `calendars_content_test_at_freeze`, `freeze_pending_marker_refuses_frozen`, `economic_s_eco_at_least_d4` ; bloc `frozen_with` (chemin du spec Gate 2, `spec_id`, règle lue mot pour mot) ; `calendars.nyse_half_days` (vide), `calendars.content_rule`, `calendars.content_exceptions` (vide) ; session `us_cash_close` à 13:00 ET les demi-séances (`on_nyse_half_days`) ; descriptif `T01_previous_settlement_filter_only` ; sur le brouillon `0.4.0-draft`, le test de contenu rapportait l'absence d'une date CPI en octobre 2026 ; 2026-10-14 inscrite le 2026-10-22 (`0.4.1-draft`, Annexe C) : plus aucun problème |

## Annexe B — Spec Gate 2 (fichier du dépôt, brouillon)

**Chemin** : `python-orchestrator/scripts/research_lp/specs/g5_gate2_spec.json` (schéma `g5-spec/2`, `spec_id: "LP-G5-GATE2-2026"`,
`status: "DRAFT_NOT_FROZEN"`, version `0.1.3-draft` depuis l'amendement A1 : `events[T01_funding_8h].adverse_selection_entry_leg:
measured_by_fill_proxy`, identique au Gate 1 et vérifié par `g5_gate2.check_gate1_identity`, pour la seule construction descriptive du
Gate 1 sur les prints ; sha256 informatif `f506f157fdf8af6a725899fca42acc3ae0f1be05715712e28d3bbef35934c7a3`, brouillon accepté), écrit le 2026-10-21 (volet `docs-b` de `20261020T2321Z-g5-periods`, deux passes),
révisé le même jour par la passe de correction qui suit la relecture `tradingV3-private/research/20261020T2321Z-g5-periods/review-b/quant.md`
(verdict `NEEDS_CHANGES` ; **textes libres seulement** : aucun texte que le lecteur strict compare mot pour mot n'a changé). sha256 de la
version `0.1.2-draft` (avant A1), **informatif** : `e29c4f20733e760e5a4c64ac5907059817b06d08e5198340b7da551351d390cf` (versions antérieures : `0.1.1-draft`
`af1f00f0…d22e`, `0.1.0-draft` `f35d820c…fb4e083`). Le sha256 qui compte est celui de la version `FROZEN`, gelée **dans la même ligne
de gel** que le spec Gate 1 (§13, point 5 ; piège 7). En cas d'écart entre ce fichier et le corps du protocole, le corps fait foi et le
spec est corrigé avant le gel, jamais après.

**D10 (2026-10-21) — version `0.1.4-draft`**, sha256 informatif `26b36111a1b76621d2a47113a7e19d3f8f91764b2c8e7b638f96d046f71bfa2c`, brouillon accepté par le lecteur
(`tests/test_research_lp_g5_gate2.py`). La valeur `owner_decision_pending` de P-G5-10 (ii) est remplacée par la décision D10 :
`classifier_index_source.status = owner_decided`, `choice = d10_both_primary_by_quality_rule` (nouveau choix du lecteur ; l'option (a)
seule est refusée : D10 n'y mène que par la règle), bloc `selection_rule` (sources (a) et (b), fenêtre des minutes, couverture ≥ 0,98,
accord ≤ 0,5 pb, règle, sensibilité, enregistrement, interdit ; textes comparés mot pour mot, seuils égaux à ceux de D10 sinon refus) ;
`owner_decisions.pending[P-G5-10 (ii)].status = owner_decided` ; `index-tickers` retiré de `capture.channels_conditional` et ajouté à
`capture.channels_in_code_2026_10_21` (le lecteur exige l'égalité avec `xperp_capture.CHANNELS`) ; `options.a_capture_index_tickers` et
`options.b_history_index_candles_after_window` (`requires`), `why`, `freeze_fill.rule` (`decision_ref_p_g5_10_ii` = texte de D10),
`verdict.pass_effect` et `note` mis à jour (textes libres). Restent en attente : P-G5-10 (i) (`events[T05_us_open].window_end`) et P-S3
(`universe.p_s3_track_s`) — deux chemins, six valeurs `owner_decision_pending`, recopiés au gel. Code : `g5_gate2.select_index_source`
(couverture comptée en fraction exacte, médiane des écarts en pb), `verify_index_selection`, `apply_index_selection`,
`use_index_source` ; `g5_eval.load_capture_series` charge `index_tick` et `index_1m`, `run_study` refuse sans choix enregistré,
`_command_eval_capture` écrit le choix avant l'étude puis rapporte la sensibilité (`index_sensitivity`, T01 seul, jamais décisif). Le
`FROZEN` sous D10 reste refusé tant que `INDEX_FROZEN_ALLOWED` ne contient pas l'option (b) (fetcher à écrire, CTO M3).

**D7 (i), D8 et fetcher de (b) (2026-10-21, soir) — version `0.1.5-draft`**, sha256 informatif
`308cd53485852822654b57dc997aef4eb209548b55ce8ea0b3e13ab331588ede`, brouillon accepté par le lecteur. `events[T05_us_open].window_end` :
`owner_decided`, `choice = a_end_2027_03_31` (D7 (i)) ; `universe.p_s3_track_s` : `owner_decided`, `choice = moot_track_s_closed` (D8 ;
valeur ajoutée à `g5_gate2.P_S3_CHOICES`, ni α ni β, BTC et ETH exigés dans `analysed` en `FROZEN`) ; `owner_decisions.pending[P-G5-10 (i)]`
et `related_not_p_g5_10[P-S3]` : `owner_decided` ; `freeze_fill.decision_ref_p_g5_10_i`, `decision_ref_p_g5_10_ii` et `decision_ref_p_s3` :
textes datés du propriétaire (le lecteur accepte un texte non nul avant le gel ; les autres valeurs restent nulles jusqu'au gel, dont
`t05_end_inclusive_utc`) ; `why` et `options.b_history_index_candles_after_window.requires` mis à jour (fetcher écrit). **Plus aucune
valeur `owner_decision_pending`** : un `FROZEN` n'est plus refusé que par ses nulls, ses listes incomplètes et ses autres contrôles. Code :
`g5_index_fetch` (commande `g5-index-fetch`, §10.1) ; `g5_gate2.INDEX_FROZEN_ALLOWED = (b, D10)` ; `g5_gate2.power_by_construction` (borne
du nombre d'instants de T05, `spec.audit`, `spec.notes`) et raison `power_floor_unreachable_by_construction` ajoutée par `g5_eval.run_study`
au `NOT_INFORMATIVE` de T05. Sur le brouillon (T0 provisoire au 2026-11-02, deux dates CPI seulement) la borne valait 100 = le plancher (92 depuis `0.1.7-draft`, listes relevées le 2026-10-22) :
l'énoncé « par construction » tient pour tout T0 atteignable (99 au 2026-11-03 avec ces seules listes, ≈ 91 avec des listes complètes ;
`test_t05_gate2_is_not_informative_by_construction_under_d7`).

**Décisions du propriétaire du 2026-10-22 — version `0.1.6-draft`**, sha256 informatif
`9ea5bf752d8bdf20fcb093e20b4c546fd0954ae27c0a0f2ec9b87e48e597b664`, brouillon accepté par le lecteur. **(a)** « Finir les contrôles G5 réalisables dès maintenant » :
capacités `calendars_content_test_at_freeze`, `freeze_pending_marker_refuses_frozen` et `economic_s_eco_at_least_d4` ajoutées à
`requires` (40) ; `calendars.content_rule` (texte lu mot pour mot), `calendars.nyse_half_days` et `calendars.content_exceptions` (vides) ;
session `us_cash_close` à 13:00 ET les demi-séances (`on_nyse_half_days`, identique au Gate 1) ; `economic.s_eco_usd_per_year` = 97,25 ;
`calendars.covers_rule` et `frozen_with.rule` mis à jour (textes libres : codé). **(b)** « Choisir la traversée comme remplissage principal du Gate 2, et garder le simple contact comme comparaison descriptive » :
`execution_sim.entry_fill_rule = through`, `descriptive_fill_rules = [touch, queue]`, texte daté `execution_sim.entry_fill_rule_decision`
(exigé par le lecteur), descriptif renommé `fill_rules_touch_and_queue`. Les huit marqueurs `freeze_pending:` restent (ligne 8 de §2.4,
a1) : le lecteur les liste (`notes.freeze_pending_markers`) et refuse `FROZEN` tant qu'il en reste un. Code : commit `4dd1f6e8`.

**Passe calendriers et rédaction (2026-10-22, après-midi) — version `0.1.7-draft`**, sha256 informatif
`4e4be20b63686ecf07f9ae3be84e842db1e4f0f531024fc3e7c83fdb3d81650a`, brouillon accepté par le lecteur. Calendriers relevés sur les pages officielles le
2026-10-22 (Annexe C ; `calendars.sources` datées) : `cpi` + 2027-01-13, 02-11, 03-10, 04-13 ; `employment_situation` 2026-11-06,
12-04, 2027-01-08, 02-05, 03-05, 04-02 ; `fomc_statement` + 2027-04-28 ; `nyse_half_days` 2026-11-27, 2026-12-24 ; fériés inchangés
(confirmés) ; test de contenu sans problème ; `calendars.incomplete_at_draft` réduit à `fomc_statement` et `ten_am_et_releases`,
bloquantes (§2.4 lignes 15 et 5) ; borne de T05 par construction 92 au T0 provisoire (100 avec les deux dates CPI de `0.1.6-draft`).
Huit marqueurs retirés, choix écrits au corps (§10.1 à §10.4) ; `costs.funding.fallback` = `none…` (choix (7)) ; `costs.stress_rule_status`
corrigé (+4,75 pb au Gate 1 depuis A1 ; le texte écrivait +6,5 pb, valeur d'avant A1). Aucun texte que le lecteur compare mot pour mot
n'a changé ; tests adaptés (`tests/test_research_lp_g5_gate2.py`, `_g5_eval.py`, `_g5_a1.py` : versions, marqueurs, problèmes de
contenu, borne de T05, premier jour CPI / Employment Situation des heures de contrôle 2026-11-06, refus du repli REST sur un texte
explicite).

**P-L2-1 et calendriers (2026-10-22, soir) — version `0.1.8-draft`**, sha256 informatif
`fc631ef8d7c0624f6db0b20e2dd212f4895033a6b3b34414c6d0f46aae1b14bc`, brouillon accepté par le lecteur. Bloc `pilot_separation` ajouté
(égal à `research_lp.separation.BLOCK`, sha256 canonique `4b96e2bd3987e123b597b6534a9c464580d560db399401849cf992af004d489e`, §10.6) ;
`window.start` = 2026-11-04 et textes `window.start_rule` / `lookback_before_t0` mis à jour (lus mot pour mot) ; bloc
`calendars.versioning` (égal à `calendar_versions.VERSIONING_BLOCK`, §10.5) ; entrée `calendars.incomplete_at_draft.fomc_statement`
retirée ; capacités `pilot_separation_floor` et `calendars_versioned_as_of_union` ajoutées à `requires` (Gate 2 seul) ; notes de calcul
d'audit de T01 et T05 complétées (443 / 440 ; 90 / 89). Borne de T05 par construction : 90 au T0 provisoire. Le spec Gate 1 n'est pas
touché. Tests : `tests/test_research_lp_separation_calendars.py` (nouveau) ; `tests/test_research_lp_g5_gate2.py`, `_g5_index_fetch.py`,
`_events.py`, `_g5_a1.py` adaptés (T0 des fixtures `FROZEN` au 2026-11-04, fenêtres synthétiques `DRAFT` sous un bloc synthétique hors de
leur fenêtre, jours des tests CLI déplacés au 2026-11-05, instantané final des calendriers dans les exécutions de bout en bout).

**Parseur (constats datés, heure de la machine).** À 07:52 (seconde passe `docs-b`, `g5_eval/3` `148d52eb…6566327`), ce brouillon était
**refusé** (neuf clés de premier niveau inconnues). Depuis 08:18 (`g5_gate2.py` `94b8bec7…`, `g5_eval.py` `38cbe452…`), `g5_eval.load_spec`
le confie à `g5_gate2.parse_capture_spec`, qui l'**accepte** en `DRAFT_NOT_FROZEN` : trois décisions en attente, `freeze_fill` nul, tous
les types `G5_GATE2_INCONCLUSIVE` (`owner_decision_pending_present`) ; un `FROZEN` est refusé tant qu'une valeur `owner_decision_pending`
ou `null` subsiste, que `calendars.incomplete_at_draft` n'est pas vide ou qu'une option non implémentée est choisie (relecture CTO
`review-b/cto.md` m6 et relecture quant m7 : la phrase « ce brouillon est refusé » était périmée). Le code est réécrit pendant la passe
de correction (09:02–09:16, passe de code qui suit la relecture CTO : taux réglé attribué au règlement **précédent**, texte
`g5_gate2.FUNDING_SOURCE`, Gate 1 `FROZEN` exigé face à un Gate 2 `FROZEN`, option (b) de l'indice refusée en `FROZEN`) ; la version
`0.1.2-draft` a été lue par le lecteur de 09:04 (`g5_gate2.py` `f8cbd3e8…`) : acceptée, mêmes trois chemins en attente, mêmes neuf
valeurs `owner_decision_pending`, `tests/test_research_lp_g5_gate2.py` vert. Tout constat de code ci-dessous est à refaire sur le code
gelé (P-G5-11).

**Identique au Gate 1** (vérifié clé par clé par script le 2026-10-21, re-vérifié en seconde passe : 89 valeurs, aucune différence ;
`docs-b/NOTES.md` ; contrôlé à chaque lecture par `g5_gate2.check_gate1_identity`) : les deux types décisifs T01 et T05 et leur rôle
d'exécution ; classifieur `premium_wavg` évalué à t_arm (480 min, ≥ 90 % de bornes présentes, ±0,0009, grandeur unique
`premium_mark_index`) ; signes ; empreintes [−5 ; +15] et [0 ; +60] ; `arm_min` −5 / +5 ; `rest_until_min` 1 ; fenêtres de rendement ;
bougies clés ; filtre d'intervalle 8 h ± 1 min ; générateur de T05 en heure de New York ; contrôles (minute de l'heure 0 / 30, toutes les
heures admissibles, marge 60 min, grilles 00/08/16 et 00/04/…/20) ; sessions ; publications macro ; éligibilité `listTime + 30 j` ;
unité ; α 0,10, seuil +10 pb, bootstrap par blocs d'un jour (10 000, graine 20261102) ; plancher 100 instants et 30 jours ; critères
C1–C7 ; validation C6 (seuils, dénominateurs) ; C7 ; m_max = 7 ; porte économique (S_éco 97,25, 4 × 100 USD, 1×, 365 / 252). **S_éco** :
97,0 était un arrondi inférieur à D4 ; la règle unique de `GATES` §1.2 (amendé le 2026-10-21) exige au moins 97,25 ; **écrit dans les deux
specs ensemble le 2026-10-22** (décision (a) ; le lecteur exige l'égalité des deux portes et refuse moins de 97,25 ; relecture review-b m5).

**Ce qui change** (§10, §5.5) :

| Clé | Valeur du brouillon Gate 2 |
|---|---|
| `source`, `dataset_id` | `capture` ; `okx-xperp-prospective-G5`, fenêtre de scoring de `okx-xperp-capture-2026-11-02` (registre) |
| `window` | début 2026-11-02 ; T0 = premier 00:00 UTC ≥ max(plancher, gel de ce spec, fin de P3), inscrit au gel ; un instant appartient à la fenêtre si toute son empreinte y est ; lecture **avant** T0 permise pour les 480 min du classifieur seulement, jamais sous le plancher, jamais scorée ; fin T01 et T05 : **2027-03-31** inclus (D7 pour T05 ; recopiée au gel) ; **prolongation pour trous telle que codée** (relecture review-b m3 ; `window.end_rule`) : un jour perdu = un jour UTC où **tous** les instruments éligibles sont inexploitables (`quality`) ; jours **civils** ajoutés (un samedi ajouté n'apporte aucun jour NYSE à T05) ; au plus 14 ; au-delà du 2027-03-31 seulement sous les décisions de l'option gelée, **datées avant le gel** ⇒ **aucune prolongation sous D7** : les jours inexploitables sont comptés, jamais remplacés |
| `price` | dernier prix = dernier print de la minute ; une minute clé sans print est l'équivalent de `vol = 0` (exclusion, comptée) |
| `statistics.measure` | `sim_net_bp` (aller-retour simulé) ; `net_bp` (construction du Gate 1 sur derniers prints et coûts de conception) descriptif, **imprimé à côté du primaire** (relecture review-b m8 : mêmes fenêtres de signe et de rendement qu'au Gate 1, la sortie simulée étant une règle d'exécution gelée, §5.5 ; impression à coder, a2) |
| `execution_sim` | `lp_sim`, règle **`through`** (décision (b) du propriétaire du 2026-10-22 ; `touch` / `queue` descriptives, jamais décisives, §10.2), latence 250 ms, carnet périmé > 10 s ⇒ intention non posée, post-only annulé s'il croise, 100 USD, `books5` (`bbo-tbt` s'il est capturé), `funding_fill_grace_min: 1` |
| T01 | post-only au touch du côté s décidé à h−5, vivant jusqu'à h+1 ; un fill dans [h ; h+1) traverse la photo et paie ; sortie passive au touch opposé de h+15 à h+60, reliquat taker |
| T05 | preneur au touch opposé à h+5 (+ latence) plus 2 pb de glissement ; sortie passive de h+60 à h+75, reliquat taker ; 14:30 UTC jusqu'au 2027-03-12, 13:30 dès le 2027-03-15 |
| `costs` | frais 2 / 5 pb ; glissement 2 pb par jambe preneuse ; sélection adverse et demi-spread **mesurés** ; stress : frais et glissement × 1,5 **plus** 0,5 × la valeur de conception de chaque terme mesuré (1,75 pb par jambe maker remplie, 1,5 pb par entrée preneuse) ; **écart déclaré** (relecture review-b m9, calcul d'audit) : le stress ajoute +5,5 pb à T01 au Gate 2 (frais 4 → 6 pb, aucune jambe preneuse, 2 × 1,75 pb) contre +4,75 pb au Gate 1 depuis l'amendement A1 (9,5 → 14,25 pb ; +6,5 pb avant A1, 13 → 19,5 pb), car le glissement de conception de 2 pb d'un aller-retour maker/maker disparaît quand les deux jambes sont maker ; T05 : +7,75 pb aux deux portes ; « jamais plus faible que le stress du Gate 1 » ne vaut que sur les termes supposés |
| `costs.funding` | taux réglé du canal `funding-rate`. Le texte du brouillon (« attributed to fundingTime ; field semantics UNKNOWN, to verify on the documentation and the first capture before freeze ») est **remplacé dans sa portée** : avant le gel, la sémantique est vérifiée sur la documentation et sur un **échantillon de schéma** seulement — noms de champs, valeurs de `settState`, `fundingTime` contre `ts`, **aucun taux affiché ni persisté hors de la tape** (`GATES` §9.5 ; relecture review-b M3 ; ligne `selection` annoncée au registre ; variante admise sous la même restriction : les deux swaps globaux, hors univers G5). Le texte `FROZEN` est `g5_gate2.FUNDING_SOURCE` (taux réglé attribué au règlement **précédent**, docs-v5 ; relecture CTO review-b B1), qui porte déjà cette restriction ; le texte du brouillon reste dans le fichier parce que le lecteur l'accepte en brouillon et que `tests/test_research_lp_g5_gate2.py` le fige. Repli REST : non implémenté, non couvert par D1, écrit `none…` (choix (7) ; marqueur `freeze_pending:` jusqu'au `0.1.6-draft`). Taux inconnu ⇒ observation exclue, comptée. **Depuis `0.1.7-draft`** : `fallback` écrit `none…` (choix (7), §10.2) ; sémantique écrite au corps (§10.1, M3) |
| C6 | même matrice, R lu dans le canal `funding-rate` ; échec ⇒ `G5_GATE2_INCONCLUSIVE` |
| `universe` | règle de G2 §3 au gel (métadonnées, comptages P3 `selection`, ≤ 40), `listTime + 30 j` ; liste `analysed` remplie au gel ; **BTC et ETH inclus** : P-S3 sans objet depuis D8 (recopie au gel, plus bas) ; règle de liquidité de G2 §3.3 non appliquée (choix (5), écrit en §10.1 le 2026-10-22) |
| `calendars` | fériés NYSE 2026-11-26, 12-25, 2027-01-01, 01-18, 02-15, 03-26 ; demi-séances 2026-11-27, 12-24 ; CPI 2026-11-10, 12-10, 2027-01-13, 02-11, 03-10, 04-13 ; Employment Situation 2026-11-06, 12-04, 2027-01-08, 02-05, 03-05, 04-02 ; FOMC 2026-12-09, 2027-01-27, 03-17, 04-28 (2027 « tentative ») — relevés le 2026-10-22 (Annexe C) ; `incomplete_at_draft` (refus du statut `FROZEN` tant que non vide) : `fomc_statement` (règle d'une date changée après le gel, §2.4 ligne 15) et `ten_am_et_releases` (§2.4 ligne 5) ; **couverture de la fenêtre** codée (`calendars_cover_the_window` : T0, fins par type, options et prolongation dans `covers`) ; **test de contenu codé le 2026-10-22** (décision (a) ; relecture review-b M4 ; `calendars.content_rule`, `g5_eval.calendar_content` ; même test au spec Gate 1 sur sa fenêtre ; problèmes rapportés à tout statut, refus en `FROZEN`) : en `FROZEN`, ≥ 1 date CPI et ≥ 1 date Employment Situation par mois civil de [T0 ; dernier jour scoré + prolongation], sauf exceptions datées et sourcées écrites dans le spec (report, fermeture des services fédéraux) ; ≥ 1 FOMC par 8 semaines ; fériés NYSE égaux à ceux d'un générateur par règles (Pâques calculé, Thanksgiving = 4ᵉ jeudi de novembre, jours reportés ; toute exception écrite) ; demi-séances NYSE listées (une clôture à 13:00 ET déplace l'exclusion `us_cash_close`). Sans ce test, une liste vidée par erreur ouvrirait la porte de puissance vers le PASS, comme les 108 instants trouvés par `docs-b` |
| `controls_common.audit_expected_utc` | heures UTC des contrôles par régime d'heure d'été (hiver jusqu'au 2027-03-13 : T01 03–06, 10–13, 18, 19 h ; T05 05:30, 09:30, 17:30 — comme §7.1) |
| `quality` | instrument-jour inexploitable = couverture `books5` < 90 % ou période `stale` > 10 min (G2 §4.4) ; > 20 % ⇒ `INCONCLUSIVE` (choix (4), écrit en §10.3 le 2026-10-22) |
| `verdict` | `G5_GATE2_*` ; `INCONCLUSIVE` global en plus si une valeur `owner_decision_pending` subsiste ou si le sha256 du résultat du Gate 1 diffère du registre ; **`pass_effect`** (relecture review-b M1) : proposition écrite de Phase 4 (démo) **si et seulement si** `G5_GATE2_PASS(type)` **et** `PASS_PROGRAMME` de `G5-Gate2-<type>` (immédiat, p ≤ 0,10/7, ou Holm à la clôture) **et** « PASS, poursuivi » ; sinon attente de la clôture ; un `PASS_PROGRAMME` historique (G5-Gate1-*) n'ouvre aucune Phase 4 ; sous D7, si le refus de (ii) est confirmé, aucun `G5_GATE2_PASS` n'est possible (écrit d'avance) |
| `multiplicity` | Holm local K = 2 (choix (2), écrit en §10.2 le 2026-10-22) ; programme : m_max = 7, tests G5-Gate2-T01 et G5-Gate2-T05 ; **T05 reste dans m** (p = 1 sous D7, jamais retiré après lecture ; relecture review-b m2) |
| `gate2_inscription` | lit le JSON du résultat du Gate 1 (sha256 au registre) ; `PASS`, `NOT_INFORMATIVE`, `INCONCLUSIVE` évalués, `FAIL` non évalué (p = 1) ; aucune discrétion, aucune lecture intermédiaire |
| `sealing` | les fichiers scellés du Gate 1 ne sont ouverts qu'après le verdict du Gate 2 du même type ; l'évaluateur du Gate 2 ne lit jamais `sealed/` ; aucun scellement des verdicts propres du Gate 2 (choix (3), écrit en §10.4 le 2026-10-22) ; **un protocole successeur** sur une forme testée ici entre dans m par un amendement de m_max daté avant son propre gel, et **aucune prolongation** n'est jamais motivée par les D, LB90, UB90 imprimés d'un `NOT_INFORMATIVE` ou d'un `INCONCLUSIVE` (relecture review-b m1 ; `GATES` §2.3 amendé) |
| `frozen_with` | gel dans la même ligne ; **règle réécrite** (relecture review-b B1) : avant toute lecture, l'évaluateur du **Gate 1** lit ce fichier et refuse de tourner s'il n'est pas `FROZEN`, s'il garde une valeur `owner_decision_pending`, un marqueur `freeze_pending:` ou un `null`, ou si son `freeze_fill.gate1_spec_sha256` diffère du sha256 du spec Gate 1 lu (capacité `gate2_frozen_together` du spec Gate 1, ligne (B) xxiv, **codée le 2026-10-22** : `g5_gate2.check_frozen_together`, option `--gate2-spec`) ; le sha256 de ce fichier est imprimé dans la sortie du Gate 1 et inscrit au registre le jour de l'exécution ; côté Gate 2, un `FROZEN` est refusé si son Gate 1 n'est pas `FROZEN` avec ce sha256 (codé à 09:04, relecture CTO review-b M2) ; la clause « refusé s'il est gelé après une lecture du Gate 1 », invérifiable faute d'horodatage, est retirée |

**Décisions du propriétaire (P-G5-10, P-S3) : réponses du 2026-10-21, recopiées dans le spec au gel**
(`LP-OWNER-DECISIONS-2026-10-21.md`, second lot, D7 et D8 ; journal du registre).
- **(i) fin de la fenêtre T05** (`events[T05_us_open].window_end`) — **D7 : option (a)**, fin au **2027-03-31** ;
  `G5_GATE2_NOT_INFORMATIVE` par construction, accepté par écrit. Comptes (calcul d'audit sur les fériés de l'Annexe C ; dates BLS 2027
  UNKNOWN au 2026-10-21, ≈ 2 par mois, **relevées le 2026-10-22** : 10 dates dans la fenêtre ; relecture review-b M2) : « ≈ 92 instants » n'est que la borne de T0 = 2026-11-02, **inatteignable** (T0 ≥ gel ≥
  fin de P3, P3 commençant le 2026-11-02, et le gel précède la lecture de G2, ≈ 2026-11-20) ; pour un T0 atteignable : 2026-11-03 :
  101 jours NYSE, ≈ 91 instants ; 11-05 : 99, ≈ 89 ; 11-10 : 96, ≈ 87 ; 11-20 : 88, ≈ 79 (exactement 91, 89, 87 et **80** sur les listes relevées le 2026-10-22, §10.3). Options refusées, pour mémoire : (b)
  2027-04-12 : ≈ 97–98 instants (T0 11-03) → ≈ 85 (T0 11-20), **jamais 100** pour un T0 atteignable — le corps (§2.1 « ≥ 2027-04-12 ⇒ ≥ 100
  instants », §10.3 « ≈ 100 instants hors macro ») était faux sur ce point, **corrigé le 2026-10-21** (passe avant gel) ; (c) fin fixée par le calendrier (proposée par
  le brouillon `0.1.1`, jamais écrite dans le corps) : seule à atteindre 100, en capturant jusqu'au ≈ 2027-04-15 (T0 11-03) → ≈ 2027-04-30
  (T0 11-20), plus 14 jours de prolongation, donc au-delà de `covers` pour un T0 tardif (le lecteur la refuserait : échec fermé). L'erreur
  du corps allait dans le sens d'une option refusée : D7 n'en est pas affectée. G5-Gate2-T05 reste dans la famille, p = 1 (`GATES` §2.3).
- **(ii) source d'indice à la minute** (`classifier_index_source`) — **remplacé par D10 (2026-10-21)** : les deux sources, primaire par
  la règle de qualité (§10.1, paragraphe D10 ci-dessus) ; le texte qui suit est l'état antérieur, pour mémoire. **D7 : « Nn » lu comme
  un refus du point 3 entier ; confirmation explicite demandée.** Si le refus est confirmé : aucune source, T01 au Gate 2 `G5_GATE2_INCONCLUSIVE` par construction (écrit d'avance) ;
  le lecteur n'a pas de choix « aucune source » (valeur à ajouter, a2) ; `decision_ref_p_g5_10_ii` = le texte de la confirmation.
  Rappel : l'option (a) exige une décision D1 nouvelle et du code de capture ; l'option (b) n'a **pas de fetcher** pour la période du
  Gate 2 (`g5_fetch` s'arrête à END_EXCLUSIVE) et le lecteur réécrit la refuse en `FROZEN` (relecture CTO review-b M3). **Conséquence**,
  avec (i) : aucun `G5_GATE2_PASS` n'est possible ; G5 ne peut ouvrir aucune Phase 4 dans ce programme (`GATES` §2.3 amendé).
- **P-S3** (`universe.p_s3_track_s`) — **D8 : piste S classée sans suite ; P-S3 sans objet ; BTC et ETH restent** dans l'univers de G5,
  aux deux portes. Ni α ni β n'est choisi (aucun CS-FUND à amender) ; valeur `moot_track_s_closed` **ajoutée au lecteur et écrite dans le
  spec le 2026-10-21** (`0.1.5-draft`), avec `decision_ref_p_s3` = texte daté de D8 ; le champ ne reste jamais en attente
  (relecture review-b B2). Le même champ est à créer au spec Gate 1, avec la même valeur et la même référence (a2). T05 au Gate 1 = 105
  (103 sous α, pour mémoire) ; l'ordre « P-S3 avant le fetch scellé du funding » est tenu (registre §4).
- **Recopie au gel, pas avant** (état antérieur, **remplacé le 2026-10-21 par la version `0.1.5-draft`** : les trois blocs sont
  `owner_decided` avant le gel, voir le paragraphe « D7 (i), D8 et fetcher de (b) » ci-dessus). Les trois blocs de décision gardaient `owner_decision_pending` dans le brouillon : le test
  `test_the_real_gate2_draft_is_accepted_and_every_block_is_read` fige cet état (trois chemins en attente, neuf valeurs, `freeze_fill` nul)
  et deux des trois réponses ne sont pas encore représentables par le lecteur. Les réponses sont écrites dans les textes libres du spec
  (`owner_decisions.*.subject`, `window.end_rule`, `freeze_fill.rule`, options de T05, `universe.p_s3_track_s`, `classifier_index_source.why`)
  et au journal du registre ; elles passent dans `status` / `choice` / `freeze_fill` à la passe de gel, avec la mise à jour du code et des
  tests (a2). D'ici là, `FROZEN` reste refusé : échec fermé.

**Choix de rédaction que le corps ne fixe pas (relecture review-b M5).** Chacun porte dans le spec le marqueur `freeze_pending:` :
(1) stress des termes mesurés par un ajout de 0,5 × leur valeur de conception (`costs.stress_rule_status`) ; (2) Holm local K = 2 au
Gate 2, un type non inscrit comptant p = 1 (`multiplicity.local_holm.status`) ; (3) aucun scellement des verdicts propres du Gate 2
(`sealing.gate2_own_verdicts.status`) ; (4) jour inexploitable repris de G2 §4.4 (`quality.status`) ; (5) règle de liquidité de G2 §3.3
**non** appliquée (`universe.liquidity_rule_g2_3_3`) ; (6) la queue de sortie simulée (h+15 → h+60, h+60 → h+75) n'entre pas dans les
empreintes, gardées égales à celles du Gate 1, dont dépendent les heures de contrôle de §7.1 (`events[*].footprint_note`) ; (7) repli REST
du funding non implémenté, écrit `none…` au gel (`costs.funding.fallback`). Sans objet depuis D7 : l'option (c) de (i). Sémantique du
funding : ligne `costs.funding` ci-dessus (M3). **Règle** : avant le gel, a1 écrit chacun de ces choix dans le corps (§10 ; §11.1 pour un
biais) ou le retire du spec, et le marqueur disparaît ; une valeur qui contient `freeze_pending:` interdit `FROZEN` — refus **codé le 2026-10-22** (demande 14,
`g5_eval.refuse_freeze_pending`, aux deux portes : clés et valeurs), comme le demandait la relecture. **Fait le 2026-10-22** (passe
calendriers et rédaction, version `0.1.7-draft`) : (1), (2), (6) et (7) écrits en §10.2, (4) en §10.3, (3) en §10.4, (5) en §10.1 ; les
huit marqueurs sont retirés du spec ; aucun choix n'a changé de valeur.

**Écarts avec le code** (refaits le 2026-10-21 sur `g5_gate2.py` `94b8bec7…` de 08:18, relu par cette passe ; le fichier est réécrit
pendant la passe, constat final sur le code gelé, P-G5-11). Les dix écarts du constat précédent (`g5_eval/3` `148d52eb…`, 07:52) :

| # | Exigence | État au code du 2026-10-21 08:18 | Reste avant le gel |
|---|---|---|---|
| 1 | Calendriers complets et couvrant la fenêtre (P-G5-5, §10.3) | **couverture codée** (`calendars_cover_the_window` : T0, fins par type, options, prolongation dans `covers` ; listes hors `covers` refusées ; `incomplete_at_draft` non vide refuse `FROZEN`) | test de contenu **codé le 2026-10-22** (ligne 13 ci-dessous) ; listes relevées le 2026-10-22 (Annexe C, test sans problème) ; restent §2.4 lignes 5 et 15 |
| 2 | Fin de fenêtre par type (P-G5-10 (i)) | **codée** (`gate2_window_end_per_type` : options a / b / c, règle du calendrier, fin recopiée dans `freeze_fill.t05_end_inclusive_utc`, `beyond_allowed` lié à l'option) | fait (`0.1.5-draft`, 2026-10-21) ; reste `freeze_fill.t05_end_inclusive_utc` au gel |
| 3 | Source d'indice (P-G5-10 (ii)) | option (b) lue sur une tape `index_1m` ; option (a) refusée (aucun canal) ; à 09:04, (b) refusée en `FROZEN` (aucun fetcher, CTO M3) ; **D10 (2026-10-21)** : canal `index-tickers` et layout `index_tick` codés, choix `d10_both_primary_by_quality_rule` lu, règle, hash et refus sans choix codés | — : fetcher de (b) écrit le 2026-10-21 (`g5_index_fetch`), D10 admis en `FROZEN` |
| 4 | Types lus = inscrits par le résultat du Gate 1 (§8, §10.2) | **codé** (`gate2_inscription_from_gate1_result`, `read_gate1_result` : sha256 au registre, spec Gate 1 = `freeze_fill.gate1_spec_sha256`, jamais `sealed/`) | — |
| 5 | `entry` / `exit` de l'exécution simulée (§5.5) | **codé** (textes de capture exacts : post-only au touch, preneur au touch opposé, sortie passive puis preneur) | — |
| 6 | C3 « tous les termes × 1,5 » (§7.5) | **codé** avec l'ajout de 0,5 × la valeur de conception (1,75 / 1,5 pb) | écart de T01 déclaré (m9) ; choix (1) écrit au corps le 2026-10-22 (§10.2) |
| 7 | `INCONCLUSIVE` globaux de capture ; prolongation pour trous (§10.3) | **codés** (`owner_decision_pending_present`, `gate1_result_sha256_mismatch`, `capture_manifest_missing`, part de jours inexploitables ; `extended_end`) | texte du corps aligné sur le code le 2026-10-22 (§10.3, m3) |
| 8 | `freeze_fill` du Gate 2 | **codé** (`FREEZE_FILL_KEYS`, nuls refusés en `FROZEN`, T0 et fin de T05 vérifiés) | champ du reçu de funding scellé côté Gate 1 (CTO M4, a2) |
| 9 | Scellement au Gate 2 (choix (3)) | **codé** : aucun scellement des verdicts du Gate 2 | choix écrit au corps le 2026-10-22 (§10.4) |
| 10 | Strates différées (§9.8 : T02, T06, T18) | **codées** en descriptifs (`DESCRIPTIVE_REPORTS`) | — |

Code demandé par la relecture `review-b/quant.md` (hors du périmètre de la passe du 2026-10-21 ; a2 sauf mention ; demandes 11, 13, 14, 16 et
18 faites le 2026-10-22 sous la décision (a) du propriétaire, commit `4dd1f6e8`) :

| # | Demande | Pièce |
|---|---|---|
| 11 | Capacité `gate2_frozen_together` au spec Gate 1 et dans son évaluateur ; sha256 du Gate 2 imprimé dans la sortie du Gate 1 ; ligne (B) au §2.3 du corps (a1) — **fait le 2026-10-22** (décision (a), commit `4dd1f6e8`) : capacité au spec Gate 1 (`requires`, bloc `frozen_with`) ; `g5_gate2.check_frozen_together` et `g5_eval.command_eval` (refuse_run avant toute donnée ; `gate2_spec` et `gate2_spec_sha256` dans la sortie) ; ligne (B) xxiv au §2.3 | B1 ; `frozen_with.rule` |
| 12 | Champ P-S3 au spec Gate 1 (`moot_track_s_closed`, référence D8, `freeze_fill.decision_ref_p_s3`) ; valeur `moot_track_s_closed` acceptée par `g5_gate2` — **Gate 2 : fait le 2026-10-21** ; spec Gate 1 : bloc absent, non créé (sous D8 rien n'y change ; à trancher par a1 / a2) | B2 ; D8 |
| 13 | Test de contenu des calendriers en `FROZEN`, aux deux portes — **fait le 2026-10-22** (décision (a), commit `4dd1f6e8`) : `g5_eval.calendar_content` / `refuse_calendar_content`, `nyse_holidays_by_rule`, `calendars.content_rule` ; Gate 1 sur sa fenêtre, Gate 2 sur [T0 ; dernier jour scoré + prolongation] ; (B) xxv | M4 ; `calendars.covers_rule` |
| 14 | Refus de `FROZEN` tant qu'une valeur contient `freeze_pending:` — **fait le 2026-10-22** (décision (a), commit `4dd1f6e8`) : `g5_eval.refuse_freeze_pending` (clés et valeurs, deux portes) ; (B) xxv | M5 |
| 15 | Choix « aucune source » de l'indice (T01 `INCONCLUSIVE` par construction, `FROZEN` permis) — **sans objet depuis D10 (2026-10-21)** | D7 (ii) ; D10 |
| 16 | S_éco ≥ 97,25 dans les deux specs ensemble — **fait le 2026-10-22** (décision (a), commit `4dd1f6e8`) : 97,25 dans les deux specs ; `g5_eval.check_s_eco` refuse moins à tout statut ; seuils de rentabilité du Gate 1 recalculés ; (B) xxv | m5 ; `GATES` §1.2 |
| 17 | `net_bp_gate1_construction_on_last_prints` imprimé à côté du primaire | m8 |
| 18 | Version descriptive de T01 filtrée sur le seul règlement précédent ; biais du filtre « règlement suivant à 8 h » déclaré au §11.1 (a1) — **fait le 2026-10-22** (décision (a), commit `4dd1f6e8`) : descriptif `T01_previous_settlement_filter_only` (`g5_eval.report_previous_settlement_only`, `settlement_instants(previous_only=True)`) ; biais déclaré au §11.1 ; (B) xxv | m12 ; CTO review-b M1 |
| 19 | Recopie de D7 et D8 dans les blocs de décision et mise à jour des tests qui figent l'état « en attente » — **fait le 2026-10-21** (`0.1.5-draft`, `tests/test_research_lp_g5_gate2.py`) | D7, D8 |

Écarts de **nom** seulement (même règle des deux côtés, à trancher par la version `FROZEN` sur le texte du protocole) : `requires` ;
`statistics.measure` (`sim_net_bp` ici, `net_bp` dans le code de mesure) ; `slippage_bp_per_taker_leg` ; `frozen_candle`
(`excluded_no_print` ici, `missing_key_price` dans le code) ; `controls_common.rule` ; `classifier.field_name` ; valeurs de conception
dans `costs` et `expected_cost_bp` (nulles ici : les termes sont mesurés).

**Traitement de la relecture `review-b/quant.md` (2026-10-21), portée G5.** Convention de §12 : **appliqué** = texte modifié par cette
passe (spec Gate 2, Annexe B, `GATES`, plan, registre) ; **signalé** = hors de son périmètre d'écriture (corps du protocole : a1 ; code
et spec Gate 1 : a2) ; « **Réponse à la revue** » = refus ou traitement différent, motivé. Les constats de `GATES`, du plan et du registre
sont aussi dans la table de `GATES` §12.

| Constat | Traitement |
|---|---|
| **B1** — co-gel imposé dans un seul sens | **Appliqué** : `frozen_with.rule` réécrite (règle de la relecture ; clause invérifiable retirée) ; ligne `frozen_with` ci-dessus. **Signalé** : capacité `gate2_frozen_together`, spec Gate 1 et code (a2, écart 11) ; ligne (B) du §2.3 (a1) **Code : fait le 2026-10-22** (capacité `gate2_frozen_together`, (B) xxiv). |
| **B2** — P-S3 absent du spec Gate 1 | **Sans objet depuis D8** : textes du spec Gate 2 ; comptes T05 = 105 / 103 (`GATES` §3.3) ; ordre « P-S3 avant le fetch scellé » au registre. **Signalé** : champ P-S3 du spec Gate 1 (a2, écart 12) ; P-G5-3 du corps (a1) |
| **M1** — ce qu'ouvre un `PASS_PROGRAMME` | **Appliqué** : `verdict.pass_effect`, `multiplicity.programme.otherwise` ; `GATES` §2.3 ; plan. **Signalé** : §8 du corps (a1) **Corps : fait le 2026-10-22** (§8). |
| **M2** — §2.1 et §10.3 faux ; T0 impossible ; option (c) | **Appliqué** : textes de T01 et des options de T05 (comptes pour un T0 atteignable ; options b et c refusées par D7) ; `GATES` §3.3, §8 ; ci-dessus. **Signalé** : §2.1, §10.3 du corps (a1). **Réponse à la revue** : la décision P-G5-10 (i) est déjà prise (D7) et n'est pas affectée (l'erreur favorisait une option refusée) ; ni extension de `covers` ni borne de (c) : sans objet sous D7 |
| **M3** — « vérifier sur la première capture » | **Appliqué** : `note` du spec, ligne `costs.funding` ci-dessus, registre (ligne `selection` de schéma annoncée). **Réponse à la revue** : le texte de `costs.funding.source` n'est pas réécrit dans le brouillon : il est comparé mot pour mot par le lecteur, qui n'accepte en brouillon que ce texte ou `g5_gate2.FUNDING_SOURCE`, et un test figé l'exige ; le texte `FROZEN` (`FUNDING_SOURCE`) porte déjà la restriction au schéma. **Signalé** : §10.1 du corps (a1) **Corps : fait le 2026-10-22** (§10.1). |
| **M4** — calendriers déclaratifs | **Appliqué** : `calendars.covers_rule` (test de contenu écrit), ligne `calendars` ci-dessus. **Signalé** : code aux deux portes (a2, écart 13) **Code : fait le 2026-10-22** (test de contenu, (B) xxv). |
| **M5** — choix « à confirmer au gel » | **Appliqué** : marqueur `freeze_pending:` sur les sept choix ; option (c) refusée (D7). **Signalé** : écriture au corps (a1) ; refus de `FROZEN` sur le marqueur (a2, écart 14). **Réponse à la revue** : le marqueur n'est pas la valeur `owner_decision_pending` (qui bloquerait dès aujourd'hui) ; ces choix sont de rédaction, pas du propriétaire, et le test figé compte neuf valeurs `owner_decision_pending` **Code : fait le 2026-10-22** (refus de `FROZEN` sur le marqueur) ; écriture au corps : **faite le 2026-10-22** (§10.1 à §10.4), marqueurs retirés (`0.1.7-draft`). |
| **m1** — pas de scellement au Gate 2 | **Appliqué** : `sealing.gate2_own_verdicts.rule` (protocole successeur, aucune prolongation motivée) ; `GATES` §2.3. **Signalé** : §10.4 du corps (a1) **Corps : fait le 2026-10-22** (§10.4). |
| **m2** — T05 à p = 1 sous l'option a | **Appliqué** : « reste dans m » (`multiplicity.programme.otherwise`, option a ; `GATES` §2.3) |
| **m3** — prolongation pour trous | **Appliqué** : `window.end_rule`, ligne `window` ci-dessus (texte aligné sur le code ; nulle sous D7). **Réponse à la revue** : les deux textes `data_gap_extension` sont comparés mot pour mot par le lecteur et restent ; l'alignement est écrit dans le texte libre voisin. **Signalé** : §10.3 du corps (a1) **Corps : fait le 2026-10-22** (§10.3). |
| **m4** — variance des contrôles | `GATES` §3.2–3.3. **Signalé** : §7.4 du corps (a1) **Corps : fait le 2026-10-22** (§7.4). |
| **m5** — S_éco | `GATES` §1.2 ; paragraphe « Identique au Gate 1 ». **Réponse à la revue** : non recopié dans le spec Gate 2 seul (identité exigée avec le Gate 1, hors périmètre) ; écart 16 **Fait le 2026-10-22** : 97,25 dans les deux specs ensemble, le lecteur refuse moins. |
| **m7** — Annexe B et plan périmés | **Appliqué** : paragraphe « Parseur » ; écarts refaits sur le code de 08:18 ; plan §2.2 (phrase datée) |
| **m8** — « mêmes fenêtres » | **Appliqué** : `statistics.measure_definition` ; `GATES` §8. **Signalé** : impression (a2, écart 17) |
| **m9** — stress C3 de T01 au Gate 2 | **Appliqué** : écart **déclaré** (`costs.stress_rule_status`, ligne `costs`). **Réponse à la revue** : le pb de plus au stress de T01 n'est pas ajouté : la règle de stress est un texte comparé mot pour mot au code et le choix (1) attend le corps (a1) ; la relecture admet l'une ou l'autre voie **Corps : choix (1) et écart écrits le 2026-10-22** (§10.2 ; `stress_rule_status` corrigé : +4,75 pb au Gate 1 depuis A1). |
| **m12** — filtre « règlement suivant à 8 h » | **Signalé** : §11.1 du corps (a1) et version descriptive filtrée sur le seul règlement précédent (a2, écart 18 ; liste des descriptifs fixée par le code) **Fait le 2026-10-22** : §11.1 et descriptif `T01_previous_settlement_filter_only`. |
| **m6, m10, m11** | `GATES` §1.2, §6.3, §11 ; registre ; `GATES` §2.1 (table de `GATES` §12) |

## Annexe C — Calendriers et sessions (dates lues par la red team le 2026-10-21 sur bls.gov, federalreserve.gov, nyse.com ; relevées sur les pages officielles le 2026-10-22 ; à re-vérifier au gel)

**Relevé du 2026-10-22** (passe calendriers avant gel ; pages officielles seulement, aucune donnée de marché ; chaque liste recopiée
dans les specs `0.4.1-draft` / `0.1.7-draft`, `calendars.sources` datées ; re-lecture au gel : `freeze_fill.calendars_verified_utc`) :

| Liste | Source (URL ; lue le 2026-10-22) | Dates relevées |
|---|---|---|
| CPI (08:30 ET) | https://www.bls.gov/schedule/news_release/cpi.htm ; https://www.bls.gov/schedule/2027/home.htm (pages mensuelles 2027 modifiées le 2026-10-09) ; https://www.bls.gov/schedule/2026/10_sched.htm | Gate 1 : + **2026-10-14** (CPI de septembre 2026 ; hors fenêtre, inscrite pour le test de contenu d'octobre) ; Gate 2 : 2026-11-10, 2026-12-10, **2027-01-13, 2027-02-11, 2027-03-10, 2027-04-13** |
| Employment Situation (08:30 ET) | https://www.bls.gov/schedule/news_release/empsit.htm ; https://www.bls.gov/schedule/2027/home.htm | Gate 2 : **2026-11-06, 2026-12-04, 2027-01-08, 2027-02-05, 2027-03-05, 2027-04-02** (jamais une règle « premier vendredi », piège 6) |
| FOMC (communiqué le second jour, 14:00 ET) | https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm | 2026 : 01-28, 03-18, 04-29, 06-17, 07-29, 09-16, 10-28, 12-09 ; 2027 : 01-27, 03-17, **04-28**, 06-09, 07-28, 09-15, 10-27, 12-08 ; la page : « Each meeting date is tentative until confirmed at the meeting immediately preceding it » (§2.4 ligne 15) |
| Fériés NYSE | https://www.nyse.com/markets/hours-calendars | 2026 : 01-01, 01-19, 02-16, 04-03, 05-25, 06-19, 07-03, 09-07, 11-26, 12-25 ; 2027 : 01-01, 01-18, 02-15, 03-26, 05-31, 06-18, 07-05, 09-06, 11-25, 12-24 — égaux au générateur par règles (`nyse_holidays_by_rule`) ; aucun férié du 2027-03-27 au 2027-04-30 |
| Demi-séances NYSE (clôture 13:00 ET) | https://www.nyse.com/markets/hours-calendars | **2026-11-27, 2026-12-24** (Gate 2 ; 18:00 UTC en heure d'hiver) ; aucune dans la fenêtre du Gate 1 ; aucune en 2027 avant le 2027-11-26 |
| JOLTS (10:00 ET ; strate descriptive §9.5) | https://www.bls.gov/schedule/news_release/jolts.htm ; https://www.bls.gov/schedule/2026/home.htm, `06_sched.htm`, `07_sched.htm` ; https://www.bls.gov/schedule/2027/home.htm | Gate 1 (fenêtre) : 2026-05-05, 06-02, 06-30, 08-04, 09-01, 09-29 ; Gate 2 : 2026-11-03, 2026-12-01 ; **2027 : aucune date publiée** de janvier à avril (pages 2027 modifiées le 2026-10-09) — bloquant (§2.4 ligne 5) |
| ISM (10:00 ET ; strate descriptive §9.5 ; éditeur privé) | https://www.ismworld.org/supply-management-news-and-reports/reports/rob-report-calendar/ | **non relevé** : la page renvoie (302) à une page de connexion `ecommerce.ismworld.org` ; aucune date inscrite, aucune règle « premier jour ouvré » supposée — bloquant (§2.4 ligne 5) |

Aucune notice de report sur les pages BLS lues ; les pages mensuelles disent que le calendrier est mis à jour « as needed » (§2.4
ligne 15). Les listes 10:00 ET ne sont dans aucun spec (aucun champ ; strate `T05_ten_am_et_release_days` `UNAVAILABLE`).

- **Fériés NYSE 2026 dans la fenêtre Gate 1** : 04-03 (Good Friday ; Pâques 2026 = 04-05), 05-25, 06-19, 07-03 (observé), 09-07.
  **Gate 2** : 2026-11-26, 2026-12-25, 2027-01-01, 2027-01-18, 2027-02-15, 2027-03-26 (Good Friday ; Pâques 2027 = 03-28) ⇒ 102 jours
  NYSE du 2026-11-02 au 2027-03-31 ; 110ᵉ jour le 2027-04-12.
- **CPI 2026** (08:30 ET = 12:30 UTC en EDT) : 04-10, 05-12, 06-10, 07-14, 08-12, 09-11 ; 10-14 en quarantaine (hors fenêtre) — **inscrite**
  dans `calendars.cpi` du Gate 1 le 2026-10-22 (`0.4.1-draft`) : le test de contenu exige une date CPI en octobre 2026, mois qui touche
  la fenêtre [2026-04-28 ; 2026-10-13] ; sans effet sur les instants (publication après la fin de la fenêtre).
- **Employment Situation 2026** : 04-03 (vendredi, NYSE fermée), 05-08, 06-05, 07-02 (**jeudi**), 08-07, 09-04, 10-02.
- **FOMC 2026** (14:00 ET = 18:00 UTC en EDT) : 04-29, 06-17, 07-29, 09-16 ; 10-27/28 en quarantaine.
- **T05 (calcul d'audit, éligibilité incluse)** : du 2026-04-29 (BTC éligible) au 2026-10-13, 116 jours NYSE avec ≥ 1 instrument éligible ;
  11 dates CPI/NFP y tombent un jour de bourse ⇒ **105** instants ; 103 à ≥ 3 instruments ; moitiés C4 : 53 / 52. Sans éligibilité
  (première version) : 135 jours, 12 dates, 123 instants.
- **Sessions** (spec `sessions`) : `us_cash_open` 09:30 et `us_cash_close` 16:00 `America/New_York` (jours NYSE ; 13:00 les
  demi-séances de `calendars.nyse_half_days`, `on_nyse_half_days`, 2026-10-22 ; Gate 2 : 2026-11-27 et 2026-12-24, relevées le 2026-10-22 ; Gate 1 : aucune) ; `london_xetra_open`
  08:00 `Europe/London` ; `hong_kong_open` 09:30 `Asia/Hong_Kong` ; `tokyo_open` 09:00 `Asia/Tokyo` (lundi–vendredi) ; `cme_cf_brr_window`
  15:00–16:00 `Europe/London` (tous les jours, à vérifier) ; `okx_dated_settlement_average` vendredi 07:30–08:00 UTC. Macro (spec
  `macro_filter`) : CPI et Employment Situation 08:30 NY, empreinte 60 min ; FOMC 14:00 NY, empreinte 90 min.
- DST : US 2026-03-08 → 2026-11-01, 2027 dès 03-14 ; UE / UK 2026-03-29 → 10-25, 2027 dès 03-28.

## Sources

Dépôt (worktree `lp-programme`, lecture seule) : `docs/handbook/research/{LP-PROGRAMME-GATES, LP-PROGRAMME-PLAN-2026-10-20,
LP-G2-DRAFT-PREREGISTRATION, LP-G4-DRAFT-PREREGISTRATION, LP-DATA-DESK-2026-10-20, LP-OWNER-DECISIONS-2026-10-21,
RESEARCH-ELIGIBILITY-GATES, RESEARCH-CAMPAIGN-STATUS (§3–4), H2-CLOSURE-DECISION (§4), PREREGISTRATION-H2-GATE1 (§15),
H1-XPERP-MARKET-ACCESS (§2.8–2.9), TRADABLE-INSTRUMENT-UNIVERSE (§2)}.md` ; `docs/handbook/reports/{research-scalping-theory-gap-2026-10-20
(§3.3 G5, §3.5), research-scalping-2026-10-12 (S3), research-execution-tick-2026-10-12 (Part A), research-regime-r2-2026-10-14 (l.43, D4),
research-midcap-tick-2026-10-13, research-book-families-2026-10-13, research-scalping-120-2026-10-13}.md` ;
`python-orchestrator/scripts/research_lp/{tape, g5_fetch, g5_eval, lp_sim, events, cli, xperp_capture}.py` et
`tests/test_research_lp_*.py` (état du 2026-10-21, §2.2). Privé (lecture seule) : `20261020T2321Z-g5-periods/a3-redteam/{PERIODS,NOTES}.md`,
`20261020T2321Z-g5-periods/review/{quant,cto}.md`, `20261020T2321Z-g5-periods/a2-code/NOTES.md` ;
`20261016T2145Z-positive-pnl-structural/eea-instruments-FUTURES-20261016.json` (métadonnées) ; extraits documentaires hachés par a4
(`20261020T1430Z-lp-programme/a4-data-desk/txt/` : `funding-mechanism.txt` l.225–300 et 354–388, `docs-v5-en.txt` l.29195–29304,
41454, 41624–41655, 41799–41830, 43897, 40115–40174, 41382–41440, `xperps-specs.txt` l.934–944, `okx-mark-last-price.txt` l.238–250,
`api-agreement-eea.txt` §1.8, §9.4). Documentation officielle (via a4 et la red team, pages non re-téléchargées ici) : OKX docs-v5,
page funding EEE (màj 2026-08-27), API Agreement EEE (28 July 2026) ; calendriers BLS, Federal Reserve, NYSE, page d'aide Deribit
(lus 2026-10-21) ; calendriers BLS (CPI, Employment Situation, JOLTS), Federal Reserve (FOMC) et NYSE (fériés, demi-séances) **relus
directement le 2026-10-22** (URL en Annexe C ; le calendrier ISM n'a pas pu être lu : page de connexion). Littérature citée par le rapport théorie et la red team, **non relue ici** : Hansen-Kim-Kimbrough (arXiv 2109.12142),
Baur et al. 2019, Eross et al. 2019, Wang-Liu-Hsu 2020, Bouchaud-Farmer-Lillo 2009, Nagel 2012, Kim & Hansen 2026 (arXiv 2607.09426),
He-Manela-Ross-von Wachter (2212.06888, 2506.08573).

Fin du brouillon. `DRAFT_NOT_FROZEN` — rien n'est gelé, rien n'est appelé, rien n'est lancé.
