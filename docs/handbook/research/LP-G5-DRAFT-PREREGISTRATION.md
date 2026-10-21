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
proxy de fill en primaire de T01 (M11) ; coûts 13 / 15,5 pb (M6) ; fetch pré-gel limité au funding, scellé ; lecture ramenée à
≈ 58 000 requêtes sur 25 instruments (M7, M8). La **spécification machine** du Gate 1 est désormais un fichier du dépôt :
`python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json` (statut `DRAFT_NOT_FROZEN`, cohérent avec ce texte) ; c'est ce
fichier, et lui seul, que l'évaluateur lira ; **son sha256 fait partie du gel** (§13). La relecture CTO (`review/cto.md`) porte sur
le code : ses constats sont repris comme prérequis de code (P-G5-11 (B)), pas appliqués par cette passe.

**Mandat.** Propriétaire, 2026-10-20 : « Ok go. Mais ta mission reste de trouver comment faire un PnL positif avec le
scalping, même sur des périodes précises ». Décisions du 2026-10-21 (`LP-OWNER-DECISIONS-2026-10-21.md`) : D1 licence §9.4
accordée, **y compris** « la lecture historique unique de G5 Gate 1 : bougies 1 minute dernier prix, mark et indice, et
historique de funding des 29 X-Perps liquides, du listing au 2026-10-13 inclus, fin dure codée au 2026-10-14 00:00 UTC » ;
D4 porte économique S_éco ≈ 97 USD/an. G5 passe en tête de la piste L.

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
(§5.3) et **13 pb** (§6) ; (v) PERIODS propose 10:30 UTC comme contrôle de T05 ; ce protocole prend **toutes** les minutes 30
admissibles des jours d'événement (§7.1). PERIODS n'est pas modifié (hors du périmètre d'écriture) ; ces écarts y font foi.

---

## 1. Hypothèses

### 1.1 Types décisifs (famille Holm, K = 2)

**H-G5-T01 (photo de funding 8 h, prime au-delà du seuil d'incitation).** Sur les X-Perps crypto d'OKX Europe éligibles (§3.2),
aux règlements h à intervalle 8 h (§5.1) de la fenêtre couverte par l'historique de funding (D_cov, §4.4), quand la prime
reconstituée à la décision vérifie **|P̂(h−5)| ≥ 0,09 %** (équivalent, par la formule officielle, d'un taux |R| ≥ 4 pb), un ordre
passif posé à **h−5** au prix c(h−5) du côté s (bid si P̂ ≥ +0,09 % : les longs paieront ; ask si P̂ ≤ −0,09 %), vivant jusqu'à h+1,
**rempli** selon le proxy de bougies (§5.4), tenu à travers la photo et sorti passivement à **h+15**, obtient sur [h−5 ; h+15] une
dérive signée nette du funding réellement payé (−s·R_h) et des coûts supposés (13 pb, §6) **supérieure d'au moins +10 pb** à la même
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
| **P-G5-5** | **Calendriers et sessions gelés en listes explicites** (Annexe C, spec `calendars`, `sessions`, `macro_filter`) : fériés NYSE ; dates CPI et Employment Situation (BLS) ; FOMC (Fed) ; **re-vérifiés** sur bls.gov / federalreserve.gov / nyse.com au gel (`freeze_fill.calendars_verified_utc`) ; à relever en plus : fenêtre CME CF BRR (15:00–16:00 Londres, publication le week-end UNKNOWN), demi-séances NYSE, liste descriptive des publications de 10:00 ET (JOLTS, ISM) pour la strate §9.5 ; calendriers 2026-11 → 2027-04 pour le Gate 2 | a1 | listes dans les specs | T05 et les contrôles indéfinis |
| **P-G5-6** | **Registre** : `tradingV3-private/research/20261020T1430Z-lp-programme/REGISTRY.md` **n'existe pas encore** (constaté le 2026-10-21) ; à créer avec les lignes `okx-xperp-1m-history-2026-03-30..2026-10-13` (`unread` → `consumed_equivalent` au jour de la lecture, D_cov et sha256 du fetch funding scellé inscrits) et `okx-xperp-prospective-G5` (`prospective`) | a1 / a3 | lignes | gel impossible |
| **P-G5-7** | **Amendements de `LP-PROGRAMME-GATES.md` et du plan** (quant m3, m4), hors du périmètre d'écriture de cette passe, **avant** le gel : §2.3 m_max = 3 + 2K = **7** (G2-C2, G4-Gate2, G1, G5-Gate1-T01, G5-Gate1-T05, G5-Gate2-T01, G5-Gate2-T05), `PASS_PROGRAMME` immédiat si p ≤ 0,10/7 ≈ 0,0143 ; §1.1 « × 365 » → « × jours/an du calendrier du type (365 continu, 252 NYSE) » ; §3.1 « blocs de 3 600 s pour les instants » → G5 : blocs d'un jour UTC ; §3.3 et plan §2.2 « ≈ 600 instants par photo » → « ≤ 211 × f_inst (T01, D_cov), 105 (T05) » | a3 | textes amendés | Holm du programme et porte économique faux |
| **P-G5-8** | **Porte économique** : S_éco = 97 USD/an (D4), inscrite ici (§7.6) ; coût fixe déclaré de G5 Gate 1 ≈ nul (code écrit, ≈ 3–6 h de requêtes, stockage de l'ordre de quelques centaines de Mo) ; Gate 2 : part du coût de la capture continue (`GATES` §1.2) | propriétaire | fait (D4) | — |
| **P-G5-9** | **Gel avant la lecture de G2** (P-G2-10) : sinon G2 §7.6 (heure / session) devient le screen de G5 et compte dans m ; conséquence : la table de coût G2 **ne peut pas exister** au gel du Gate 1 (§6) | a1 / propriétaire | horodatages de gel | G5 post-screen |
| **P-G5-10** | **Gate 2** : capture continue en cours (P3, D1) ; spec Gate 2 gelé **en même temps** que le Gate 1 (piège 7) ; décisions du propriétaire au gel : (i) fin de fenêtre T05 Gate 2 (§10.3 : 2027-03-31 ⇒ `NOT_INFORMATIVE` par construction ; ≥ 2027-04-12 ⇒ ≥ 100 instants) ; (ii) **source d'indice à la minute** pour le classifieur P̂ au Gate 2 : la capture D1 n'a pas de canal d'indice (`xperp_capture.CHANNELS` = trades, books5, liquidation-orders, mark-price, funding-rate, établi) ⇒ (a) canal public `index-tickers` ajouté à la capture (docs-v5 l.43897 ; nouveau canal = nouvelle décision D1) ou (b) `history-index-candles` récupéré **après** la fin de la fenêtre (≈ 2 160 pages par sous-jacent pour 150 jours) ; sans (a) ni (b), T01 Gate 2 est `INCONCLUSIVE` par construction (écrit d'avance) | propriétaire | décisions datées | T05 Gate 2 sans puissance ; T01 Gate 2 sans classifieur |
| **P-G5-11** | **Code (B)** ci-dessous, livré et testé hors ligne ; relecture du code (CTO) refaite ; état (A) re-constaté au gel | a2 | digests, suite verte | gel impossible |

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
| xiii | `costs_by_execution_role` : `maker_maker` (T01) / `taker_maker` (T05), sélection adverse 3,5 pb par jambe maker, glissement 2 pb, demi-spread d'entrée preneur 3 pb, stress × 1,5 sur **tous** les termes de coût ; le spec déclare le total attendu (13 / 15,5 pb) et l'évaluateur vérifie l'égalité (CTO B1-vi) | §6, quant M6 |
| xiv | `funding_unknown_outside_coverage_counted` : funding d'une fenêtre hors D_cov supposé nul et compté (`funding_unknown`) ; funding des contrôles calculé (attendu nul ; non nul compté comme anomalie) | §4.4, quant M5 ; CTO M1 |
| xv | `verdict_c1_c7_precedence` : précédence `INCONCLUSIVE` > `NOT_INFORMATIVE` > critères ; C1–C7 (§7.5) ; annotation du FAIL (UB90 ≥ / < +10 pb) ; lecture « poursuivi » (CTO B3) | §7.5, §8 |
| xvi | `holm_local_and_programme_fields` : `holm_local_confirmed` (K = 2) et `pass_programme_immediate` (p ≤ 0,10/7) ; le champ `pass_programme` disparaît (quant B4) | §7.5 |
| xvii | `no_information_after_decision` : refus de tout champ de condition, de classifieur ou de signe observé après `arm_min` (seuls le fill, la sortie et le rendement sont postérieurs par nature) (CTO B2) | §0.8 |
| xviii | `spec_schema_g5_v2_strict_keys`, `status_frozen_required_for_market_data`, `end_exclusive_constant_check`, `universe_from_spec_checked_against_snapshot` : liste blanche de clés, `requires` couvert, statut `FROZEN` et `freeze_fill` non nul exigés pour lire une donnée de marché (sinon tests synthétiques seulement), `end_exclusive_utc` du spec égal à `g5_fetch.END_EXCLUSIVE` | CTO B1-xii |
| xix | **Fetcher** : `--inst-ids` (liste hachée) et `--max-requests` obligatoires pour un vrai run, refus si le plan dépasse ; départ par flux (bougies : éligibilité − 1 j ; mark / indice : max(D_cov − 1 j, éligibilité − 1 j)) ; mode **scellé** pour le funding pré-gel (totaux globaux et D_cov seulement à l'écran et au journal, manifeste par instrument dans un fichier scellé haché) ; `end_reason` et `coverage_start_us` ; `data` non-liste traité comme transitoire puis fatal ; compteurs cumulés ; `--force` réel (CTO M1, M3, m1, m2, m3) | §4.3, P-G5-3, quant M7, M8 |
| xx | `sealed_statistics_for_inconclusive_and_not_informative` : pour un type `INCONCLUSIVE` ou `NOT_INFORMATIVE`, D, LB90, UB90, p, moyennes, critères et descriptifs du type écrits dans `sealed/<type>.json`, sha256 imprimé, rien d'autre ; `economic_reading_days_per_year` (365 / 252) ; `bootstrap_day_blocks` (10 000 rééchantillons ; sensibilité 7 jours descriptive) ; `descriptive_reports_g5_v2` (§9, avertissement en tête du Markdown, CTO m7) | §7.3, §7.6, §8, quant B5, m1 |
| xxi | **Gate 2** : fill dans [h ; h+1) compté comme traversant la photo ; entrée **preneur** de T05 au touch opposé + glissement dans `measure_lp_sim` ; classifieur P̂ sur mark de capture et indice (P-G5-10 ii) ; validation C6 avec R du canal `funding-rate` | §10 |
| xxii | **`tests/test_research_lp_g5_eval.py`** étendu (synthétique, hors ligne, garde `socket` au niveau du module — CTO m6) : calibration sous H0 sur ≥ 1 000 jeux (marche aléatoire avec rebond bid-ask, sursauts de minute 0, chocs de jour partagés ; 1 000 rééchantillons dans les tests, déclaré) : taux de {LB90(D) > 0} ≤ 0,10 + 3 erreurs types Monte-Carlo, uniformité de p (Kolmogorov-Smirnov, non rejetée à 1 %), FWER de Holm ≤ 0,10 + 3 erreurs types ; **non-régression de B2** : sursaut et rebond plantés à la minute 0, événement à la minute 30 ⇒ D sans biais (valeur absolue de la moyenne ≤ 3 erreurs types) avec les contrôles à la minute 30, biais détecté avec des contrôles à la minute 0 ; effet planté de +30 pb : **puissance rapportée** (pas un booléen) ; éligibilité, `vol = 0`, proxy de fill, classifieur à t_arm, refus d'un champ après la décision, clé inconnue refusée, scellement, constante END (quant m7) | gel impossible sans |

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
  net = r + funding_bp − 13 pb (§6). Lecture : le fournisseur du côté s **paie** R_h quand le signe de P̂ et celui de R_h concordent
  (piège 19) ; net = rebond − R − coûts. La version **sans** proxy de fill est descriptive (§9.4).
- **T05** : décision à **h+5**, entrée **preneur** à c(h+5) du côté s, demi-spread de conception payé (§6) ; sortie passive supposée à
  c(h+60) ; r = s × ln(c(h+60) / c(h+5)) × 10⁴ ; funding_bp = −s × Σ taux des règlements dans (h+5 ; h+60] (D_cov ; hors D_cov
  supposé nul, compté) ; net = r + funding_bp − 15,5 pb.
- **Pas de stop** dans l'analyse primaire (règle 12) ; lecture portefeuille : coupe de catastrophe à **3 σ₁₅** (σ₁₅ = écart-type des
  rendements 1 m du dernier prix sur les 24 h précédant h × √15), jamais plus serrée ; taille **100 USD** par ordre.

5.5 **Scalp simulé (Gate 2, carnet réel ; `measure_lp_sim`, `lp_sim`).** T01 : même classifieur P̂(h−5) (mark de la capture, indice
selon P-G5-10 ii) ; intention post-only au touch côté s décidée à `arm_ts_us = h−5`, vivante jusqu'à `end_ts_us = h+1` (règle
`touch`, latence 250 ms), non remplie ⇒ pas d'observation (taux de fill compté) ; sortie passive au touch opposé à partir de h+15
jusqu'à h+60, reliquat **taker** ; fill dans [h ; h+1) traversant la photo ((B) xxi). T05 : entrée preneur à h+5 au touch opposé +
glissement, sortie passive dès h+60, reliquat taker à h+75. Règles de fill `through` / `queue` descriptives. Les intervalles de
funding sont lus dans la tape (`funding_time_us`, `next_funding_time_us`) ; la validation C6 utilise R du canal `funding-rate`.

5.6 **Deux lectures** (`GATES` §4). Sans contrainte : toutes les intentions ; critères scientifiques. **Portefeuille 500 USDT** :
`capital 500`, `leverage 1`, `max_live 4`, `order_notional 100` ; à un instant où plusieurs instruments sont retenus, au plus **4**
sont admis, dans l'ordre des identifiants (règle fixe) ; `posMode` UNKNOWN ⇒ rapportée sous les deux hypothèses (G2 §5.7) ; aucun
impact de marché supposé (déclaré).

---

## 6. Coûts (pessimistes, dans le net ; `GATES` §5 ; quant M6)

| Terme | T01 (maker / maker) | T05 (preneur / maker) | Stress (× 1,5 sur tous les termes) |
|---|---|---|---|
| Frais | 2 + 2 = 4 pb | 5 + 2 = 7 pb | 6 / 10,5 pb |
| Sélection adverse (valeur de conception `GATES` §5.4 : **3,5 pb par jambe maker**, borne haute de Part A, structurellement plus élevée sur X-Perps à cause des RPI) | 3,5 × 2 = 7 pb (l'entrée est en partie déjà mesurée par le proxy de fill : double compte partiel **déclaré**, biais vers le FAIL sur C2 / C3, sans effet sur D) | 3,5 pb sur la jambe maker de sortie | 10,5 / 5,25 pb |
| Demi-spread d'entrée preneur | — | **3 pb** (conception : la moitié du seuil de 6 pb de G2 ; spreads des X-Perps minces UNKNOWN) | 4,5 pb |
| Glissement | 2 pb | 2 pb | 3 pb |
| **Total supposé** | **13 pb** | **15,5 pb** | **19,5 / 23,25 pb** |
| Funding | **réel**, `−s × R_h` (traversé par construction) ; stress au plafond de l'instrument contre la position : descriptif | réel dans D_cov ; hors D_cov supposé nul, compté | idem |
| Marge gelée | 100 USD × 20 min / 365 j × SOFR : négligeable, écrit (`GATES` §5.2) | idem (55 min) | — |
| Liquidation, ADL, bandes | sans objet à 1× sur 20–55 min (mouvement de l'ordre de 99 % requis, calcul d'audit G2 §5.6) ; rejet par bande (`maxPxLmtPct`) non modélisé au Gate 1, compté au Gate 2 (`band_rejected`) | idem | — |

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
  - **C2** : net moyen des instants d'événement **> 0** (point), coûts compris ;
  - **C3** : C2 tient avec **tous** les termes de coût × 1,5 ;
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

7.6 **Porte économique (`GATES` §1 ; D4 : S_éco = 97 USD/an ; quant m3).** Par type : plafond_USD_an = fills admis par jour du
calendrier du type (lecture portefeuille : ≤ 4 par instant, 100 USD, 1×) × 100 × net_bp moyen d'événement (aller-retour **absolu**,
coûts compris, jamais D) / 10⁴ × jours/an (**365** pour T01, jours calendaires de D_cov ; **252** pour T05, jours NYSE avec ≥ 1
instrument éligible, jours macro comptés au dénominateur ; amendement de `GATES` §1.1, P-G5-7), avec LB90 / UB90. « Poursuivi » ⇔
point ≥ S_éco **et** (UB90 ≥ 2 S_éco **ou** LB90 > 0) (`GATES` §1.2). Seuils de rentabilité (calcul d'audit, pas une prévision) :
**T05** à 4 fills par instant, 105 instants pour 116 jours NYSE : net d'événement ≥ **10,6 pb** (la relecture écrivait 9,6 pb en
supposant un instant chaque jour NYSE), soit une continuation brute de l'ordre de **26 pb** après 15,5 pb de coûts — **un PASS de T05 à
D = +10 pb sans net absolu de cet ordre est « PASS, non poursuivi » par construction** (écrit d'avance) ; **T01** : ≥ 2,2 pb nets à
12 fills par jour (3 photos × 4 instruments), ≥ 8,9 pb à 3 fills par jour.

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
- **`G5_GATE2_PASS`** autorise une **proposition écrite** au propriétaire : Phase 4 de l'architecture A1 (`lp-executor-architecture.md`
  §5, **démo seulement**), avec la lecture économique et le budget de multiplicité du programme ; **aucun ordre** par lui-même.
- **`G5_GATE2_FAIL`** : forme close pour cette architecture ; filtre au plus.
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
version **sans proxy de fill** (dérive inconditionnelle) ; forme en V ([h−15 ; h] contre [h ; h+15]) ; dose |R_h| ; par heure de photo
(00:00 « jour UTC / Tokyo », 08:00 « composite HKEX / Deribit / datés le vendredi », 16:00 seul — en plus de C7) ; vendredi contre autres
jours (T08) ; strate T02 (règlements à 4 h, 2 h, 1 h : net et F) ; **sur le mark, dans D_cov seulement** ; équilibre de |P̂| entre
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

---

## 10. Gate 2 — confirmation prospective (spec `g5_gate2_spec.json`, gelé avec le Gate 1)

10.1 **Données** : capture publique continue `research_lp capture` (hôte EEE, canaux `trades`, `books5`, `bbo-tbt`, `mark-price`,
`funding-rate`, `instruments` ; D1), statut `prospective`, garde `QUARANTINE_FLOOR = 2026-11-02T00:00:00Z` (`xperp_capture.py` l.87) ;
`source: "capture"` ; prix de référence = derniers prints à la minute (dernier prix, cohérent avec le Gate 1) ; classifieur P̂ = mark de
la capture et **indice** selon la décision P-G5-10 (ii) — une seule grandeur `premium_mark_index` aux deux portes (CTO M2) ; le champ
`premium` du canal (prime officielle) sert à la seule comparaison descriptive ; règlements lus dans la tape, intervalle par instrument.
Univers = celui de G2 §3 (métadonnées + comptages P3, ≤ 40 X-Perps), filtre `listTime + 30 j`.

10.2 **Types** : inscrits **automatiquement** selon §8 (`PASS`, `NOT_INFORMATIVE`, `INCONCLUSIVE`) ; **mêmes** classifieur, seuil,
signes, fenêtres, contrôles (minute de l'heure, sessions en heure locale, grille par défaut, macro en heure de New York : CPI/NFP à
13:30 UTC et FOMC à 19:00 UTC en heure d'hiver jusqu'au 2027-03-14 — quant m8), unité et critères (§5–7), sélection adverse **mesurée**
par `lp_sim` au lieu de supposée, validation C6 recalculée avec R du canal `funding-rate` ; exécution simulée §5.5 (`arm_ts_us = h−5`,
jamais h−1). T05 à **14:30 UTC** du 2026-11-02 au 2027-03-12, 13:30 à partir du 2027-03-15 ; tout est défini en heure locale (piège 1).

10.3 **Fenêtre et puissance (calcul d'audit).** T0 = gel du Gate 2 (≥ 2026-11-02, ≥ P3) ; fin ≤ **2027-03-31** (`GATES` §1.3).
- **T01** : 3 photos/jour sur ≤ 150 jours ⇒ 450 f_inst instants ; ≥ 100 si f_inst ≥ 0,23 ; sans source d'indice : `INCONCLUSIVE` par
  construction (P-G5-10 ii).
- **T05** : du 2026-11-02 au 2027-03-31, **102 jours NYSE** (fériés 11-26, 12-25, 01-01, 01-18, 02-15, 03-26), moins ≈ 10 dates CPI/NFP
  (BLS, à relever, P-G5-5) ⇒ **≈ 92 < 100** : `NOT_INFORMATIVE` **par construction** si la fenêtre s'arrête au 2027-03-31. Décision du
  propriétaire **au gel** (P-G5-10 i) : fin de T05 Gate 2 au **2027-04-12** (110ᵉ jour NYSE depuis le 2026-11-02, ≈ 100 instants hors
  macro) ou acceptation du `NOT_INFORMATIVE`. Une fin fixée au gel par le calendrier n'est pas une extension sur résultat.
- Futilité : **aucune** lecture intermédiaire des types d'horloge (une seule exécution à la fin) ; prolongation **pour trous** de données
  seulement (jour inexploitable ⇒ un jour ajouté, au plus 14), écrite au gel.

10.4 **Ce que le Gate 2 ne fait jamais** : aucun ordre, aucune démo, aucun drapeau (règle 13) ; aucune analyse promue depuis les strates
du Gate 1 ; aucun type ajouté sans nouveau pré-enregistrement gelé **avant** la lecture du Gate 1 ; aucune ouverture des fichiers scellés
avant son propre verdict.

---

## 11. Risques, biais déclarés, pièges intégrés

11.1 **Biais vers le PASS (déclarés)** : demi-spread d'entrée de T05 fixé à 3 pb sur des carnets minces (C2, C3 seulement) ; rejet par
bande de prix non modélisé au Gate 1 ; aucun impact de marché ; prints RPI au Gate 2 tant que `trades.source` n'est pas utilisé (G2 §0.9) ;
sortie passive supposée remplie à c(h+15) / c(h+60). **Biais vers le FAIL** : sélection adverse comptée sur l'entrée de T01 alors que le
proxy de fill en mesure déjà une partie (C2, C3) ; funding payé par construction à T01 ; Holm à K = 2 avec p = 1 pour un type
`NOT_INFORMATIVE` ; seuil d'incitation qui réduit n ; rebond bid-ask sur le net absolu de T05. **Direction UNKNOWN** : proxy de fill
(le prix limite est c(h−5), pas le bid réel ; une transaction au bid peut satisfaire la règle quand c(h−5) était au ask) ; écart de spread
entre 13:30 et les minutes 30 de contrôle (ampleur du rebond) ; sélection de l'univers par un volume d'octobre (§3.1) ; sessions locales
fériées ignorées (contrôles un peu moins nombreux).

11.2 **Dépendance aux UNKNOWN** : couverture de `history-candles` / `history-mark-price-candles` / `history-index-candles` pour les
X-Perps et id de l'indice (sonde `limit = 1` **après** P-G5-1 seulement, hachée, valeur non affichée) ; f_inst et f_obs ; date du fetch
du funding (D_cov) ; application de l'escalade 2 h / 1 h aux X-Perps (lue dans `fundingTime`) ; σ des valeurs par instant ; fenêtre de la
moyenne mobile du mark ; écart P̂ / prime d'impact (mesuré par C6) ; part des X-Perps à 4 h ; `posMode` ; calendriers BLS/Fed 2027 ;
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
| quant **M6** — sélection adverse sous le défaut de `GATES` ; demi-spread de T05 absent | **Appliqué** : §6, spec `costs` — 3,5 pb par jambe maker, demi-spread d'entrée de T05 à 3 pb ; totaux 13 / 15,5 pb, stress 19,5 / 23,25 pb. **Réponse à la revue** : « ou la table G2 » est sans objet au Gate 1 — G5 est gelé avant la lecture de G2 (P-G5-9), la table ne peut pas exister au gel ; les valeurs de conception sont définitives |
| quant **M7** — fetch aveugle trop large ; manifeste révélateur | **Appliqué** : P-G5-3, §4.3, spec `fetch_plan.pre_freeze` — fetch pré-gel limité à `funding_hist`, mode scellé ; bougies, mark, indice après le gel. **Réponse à la revue** (plus strict) : même les totaux **par instrument** sont scellés (un nombre de règlements par instrument révèle encore une escalade) ; seuls les totaux globaux et D_cov sont imprimés ; prérequis (B) xix |
| quant **M8** — mark et indice depuis `listTime` : échelle non nécessaire | **Appliqué** : §0.5, §4.1, §4.3, P-G5-1, spec `fetch_plan` — mark / indice bornés à [D_cov − 1 j ; END) ; descriptifs « sur le mark » hors D_cov supprimés ; facteur 0,4 proposé. **Réponse à la revue** (plus loin) : les 4 instruments sans jour éligible ne sont pas récupérés et les bougies partent de l'éligibilité − 1 j : ≈ 58 300 requêtes au lieu des ≈ 87 000 de la relecture |
| quant **M9** — univers choisi par un volume du 2026-10-14 | **Appliqué, options (b) + (a)** : §3.1, P-G5-4, spec `universe` — règle par métadonnées dans le snapshot post-10-31 ∩ liste D1 (enveloppe de licence ; 25 attendus) **et** dérogation écrite du propriétaire nommant l'exception et ses biais ; biais déclarés (faible sur D, plus fort sur C2/C3) |
| quant **M10** — clôtures trop fortes pour des tests sous-puissants | **Appliqué** : §8 — FAIL = FAIL, annoté « absence non démontrée » (UB90 ≥ +10) / « effet ≥ +10 pb exclu » (UB90 < +10) ; clôture limitée aux formes testées ; « mécanisme compris » retiré |
| quant **M11** — estimand inconditionnel au Gate 1, conditionnel au fill au Gate 2 | **Appliqué** : §0.9, §5.4, spec `fill_proxy` — proxy `through` primaire pour T01, même règle aux contrôles, taux de fill rapporté, version inconditionnelle descriptive. **Réponse à la revue** sur un point : la sélection adverse supposée de l'entrée est **conservée** (3,5 pb) malgré la part mesurée par le proxy ; double compte partiel déclaré, biais vers le FAIL sur C2/C3, sans effet sur D |
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
| Spec machine (demande de la passe) | **Appliqué** : `python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json` (sha256 du brouillon au 2026-10-21 en Annexe A, informatif ; JSON validé, cohérence des coûts, des comptes et de `END_EXCLUSIVE` vérifiée, refus par le parseur actuel constaté) ; son sha256 de version `FROZEN` entre dans la ligne de gel (§13) |

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
version `0.2.0-draft`). sha256 du brouillon au 2026-10-21, **informatif** (le sha256 qui compte est celui de la version `FROZEN`, §13) :
`d4189493d896fe508b36f32da6433c03305cd4867fdc5fe94ff67d17f85af80a` (aussi dans `a1-protocol/NOTES.md` §9). Valeurs clés, à lire dans le fichier (en cas d'écart, le texte de ce protocole fait foi au gel et
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
| `costs` | maker 2, taker 5, sélection adverse 3,5 / jambe maker, glissement 2, demi-spread preneur 3, stress × 1,5 ; totaux 13 / 15,5 |
| T01 | `funding_settlements` (8 h ± 1 min des deux côtés, réalisé requis), D_cov, empreinte [−5 ; +15], `arm_min −5`, `rest_until_min 1`, classifieur `premium_wavg` à t_arm (480 min, ≥ 90 %, ±0,0009), proxy `through_candles`, bougies clés −5 / +15, validation C6 (±0,0004 ; 0,80 / 0,20 ; ≥ 30), contrôles minute 0, grille 00/08/16, C7 à 16:00 |
| T05 | 09:30 `America/New_York`, jours NYSE hors CPI / Employment Situation, empreinte [0 ; 60], signe [0 ; 5], `arm_min 5`, entrée preneur, sortie h+60, bougies clés 0 / 5 / 60, contrôles minute 30, grille 00/04/…/20 |
| `controls_common` | toutes les heures admissibles, aucun tirage, marge 60 min entre empreintes |
| `sessions`, `macro_filter`, `calendars` | listes de §4.6 et de l'Annexe C, heure locale |
| `verdict`, `multiplicity`, `sealing`, `gate2_inscription` | §7.5, §8 |
| `economic` | S_éco 97, 4 × 100 USD, 1×, 365 / 252 |
| `fetch_plan` | funding scellé avant le gel ; ≈ 58 285 requêtes au total (D_cov supposé au 2026-08-04) |
| `freeze_fill` | snapshot, calendriers vérifiés, digest du code, `decision_ref` : nuls dans le brouillon, obligatoires au gel |

## Annexe B — Spec Gate 2 (fichier du dépôt, brouillon)

**Chemin** : `python-orchestrator/scripts/research_lp/specs/g5_gate2_spec.json` (schéma `g5-spec/2`, `spec_id: "LP-G5-GATE2-2026"`,
`status: "DRAFT_NOT_FROZEN"`, version `0.1.0-draft`), écrit le 2026-10-21 (volet `docs-b` de `20261020T2321Z-g5-periods`). sha256 du
brouillon, **informatif** : `f35d820c9adf61da5235c8dbecb3e7b1972417d2945dbf9c3cd270a91fb4e083` (aussi dans `docs-b/NOTES.md`). Le sha256
qui compte est celui de la version `FROZEN`, gelée **dans la même ligne de gel** que le spec Gate 1 (§13, point 5 ; piège 7). Comme le
spec Gate 1, ce brouillon est **refusé** par le parseur strict actuel `g5_eval/2` (clés inconnues, constaté le 2026-10-21) : échec fermé
jusqu'au code (B), dont (B) xxi. En cas d'écart entre ce fichier et le corps du protocole, le corps fait foi et le spec est corrigé avant
le gel, jamais après.

**Identique au Gate 1** (vérifié clé par clé par script le 2026-10-21, `docs-b/NOTES.md`) : les deux types décisifs T01 et T05 et leur
rôle d'exécution ; classifieur `premium_wavg` évalué à t_arm (480 min, ≥ 90 % de bornes présentes, ±0,0009, grandeur unique
`premium_mark_index`) ; signes ; empreintes [−5 ; +15] et [0 ; +60] ; `arm_min` −5 / +5 ; `rest_until_min` 1 ; fenêtres de rendement ;
bougies clés ; filtre d'intervalle 8 h ± 1 min ; générateur de T05 en heure de New York ; contrôles (minute de l'heure 0 / 30, toutes les
heures admissibles, marge 60 min, grilles 00/08/16 et 00/04/…/20) ; sessions ; publications macro ; éligibilité `listTime + 30 j` ;
unité ; α 0,10, seuil +10 pb, bootstrap par blocs d'un jour (10 000, graine 20261102) ; plancher 100 instants et 30 jours ; critères
C1–C7 ; validation C6 (seuils, dénominateurs) ; C7 ; m_max = 7 ; porte économique (S_éco 97, 4 × 100 USD, 1×, 365 / 252).

**Ce qui change** (§10, §5.5) :

| Clé | Valeur du brouillon Gate 2 |
|---|---|
| `source`, `dataset_id` | `capture` ; `okx-xperp-prospective-G5`, fenêtre de scoring de `okx-xperp-capture-2026-11-02` (registre) |
| `window` | début 2026-11-02 ; T0 = premier 00:00 UTC ≥ max(plancher, gel de ce spec, fin de P3), inscrit au gel ; un instant appartient à la fenêtre si toute son empreinte y est ; lecture **avant** T0 permise pour les 480 min du classifieur seulement, jamais sous le plancher, jamais scorée ; fin T01 2027-03-31 inclus ; fin T05 selon P-G5-10 (i) ; prolongation pour trous ≤ 14 jours, au-delà du 2027-03-31 seulement sous les décisions de (i) b |
| `price` | dernier prix = dernier print de la minute ; une minute clé sans print est l'équivalent de `vol = 0` (exclusion, comptée) |
| `statistics.measure` | `sim_net_bp` (aller-retour simulé) ; `net_bp` (construction du Gate 1 sur derniers prints et coûts de conception) descriptif |
| `execution_sim` | `lp_sim`, règle `touch` (`through` / `queue` descriptives), latence 250 ms, carnet périmé > 10 s ⇒ intention non posée, post-only annulé s'il croise, 100 USD, `books5` (`bbo-tbt` s'il est capturé), `funding_fill_grace_min: 1` |
| T01 | post-only au touch du côté s décidé à h−5, vivant jusqu'à h+1 ; un fill dans [h ; h+1) traverse la photo et paie ; sortie passive au touch opposé de h+15 à h+60, reliquat taker |
| T05 | preneur au touch opposé à h+5 (+ latence) plus 2 pb de glissement ; sortie passive de h+60 à h+75, reliquat taker ; 14:30 UTC jusqu'au 2027-03-12, 13:30 dès le 2027-03-15 |
| `costs` | frais 2 / 5 pb ; glissement 2 pb par jambe preneuse ; sélection adverse et demi-spread **mesurés** ; stress : frais et glissement × 1,5 **plus** 0,5 × la valeur de conception de chaque terme mesuré (1,75 pb par jambe maker remplie, 1,5 pb par entrée preneuse) |
| `costs.funding` | taux réglé du canal `funding-rate` (sémantique `settFundingRate` / `settState` UNKNOWN, à vérifier avant le gel) ; repli REST après T0 (`GATES` §5.1) ; taux inconnu ⇒ observation exclue, comptée |
| C6 | même matrice, R lu dans le canal `funding-rate` ; échec ⇒ `G5_GATE2_INCONCLUSIVE` |
| `universe` | règle de G2 §3 au gel (métadonnées, comptages P3 `selection`, ≤ 40), `listTime + 30 j` ; liste `analysed` remplie au gel ; P-S3 en attente |
| `calendars` | fériés NYSE 2026-11-26, 12-25, 2027-01-01, 01-18, 02-15, 03-26 ; CPI 2026-11-10, 12-10 ; FOMC 2026-12-09, 2027-01-27, 03-17 (2027 « tentative ») ; Employment Situation et CPI 2027 **à relever** (`incomplete_at_draft`, refus du statut `FROZEN` tant que non vide) |
| `controls_common.audit_expected_utc` | heures UTC des contrôles par régime d'heure d'été (hiver jusqu'au 2027-03-13 : T01 03–06, 10–13, 18, 19 h ; T05 05:30, 09:30, 17:30 — comme §7.1) |
| `quality` | instrument-jour inexploitable = couverture `books5` < 90 % ou période `stale` > 10 min (G2 §4.4) ; > 20 % ⇒ `INCONCLUSIVE` |
| `verdict` | `G5_GATE2_*` ; `INCONCLUSIVE` global en plus si une valeur `owner_decision_pending` subsiste ou si le sha256 du résultat du Gate 1 diffère du registre |
| `gate2_inscription` | lit le JSON du résultat du Gate 1 (sha256 au registre) ; `PASS`, `NOT_INFORMATIVE`, `INCONCLUSIVE` évalués, `FAIL` non évalué (p = 1) ; aucune discrétion, aucune lecture intermédiaire |
| `sealing` | les fichiers scellés du Gate 1 ne sont ouverts qu'après le verdict du Gate 2 du même type ; l'évaluateur du Gate 2 ne lit jamais `sealed/` |

**Décisions du propriétaire au gel (P-G5-10), champs `owner_decision_pending`** :
- **(i) fin de la fenêtre T05** (`events[T05_us_open].window_end`) — (a) **2027-03-31** : 102 jours NYSE depuis le 2026-11-02, ≈ 92
  instants hors CPI / Employment Situation ⇒ `G5_GATE2_NOT_INFORMATIVE` par construction, accepté par écrit ; (b) **2027-04-12** : 110
  jours NYSE, mais ≈ **98–99** instants hors macro (11 ou 12 dates BLS ; calendrier 2027 UNKNOWN) — le « ≈ 100 » de §10.3 est
  optimiste, et chaque jour NYSE de T0 après le 2026-11-02 retire un instant de plus (T0 = 2026-11-05 : 107 jours) ; (c) **proposée par
  ce brouillon, absente du corps** : fin = le jour NYSE où le compte des jours NYSE hors CPI / Employment Situation depuis T0 atteint 100,
  calculée au gel sur les listes complètes (≈ 2027-04-13 à 04-15 pour T0 = 2026-11-02) — « une fin fixée au gel par le calendrier n'est
  pas une extension sur résultat » (§10.3). (b) et (c) exigent **deux** décisions écrites que P-G5-10 ne nomme pas : une exception à
  `GATES` §1.3 (scoring après le 2027-03-31) et une extension de D1 (capture au-delà du 2027-03-31 = nouvelle décision).
- **(ii) source d'indice à la minute** (`classifier_index_source`) — (a) canal public `index-tickers` ajouté à la capture avant T0
  (nouvelle décision D1, code de capture et layout à écrire) ; (b) `history-index-candles` récupéré une fois après la fin de la fenêtre
  (nouvelle décision de lecture, D1 ne couvrant que la capture et l'historique du Gate 1 ; ≈ 2 160 pages par indice pour 150 jours) ;
  sans l'une ou l'autre, T01 est `G5_GATE2_INCONCLUSIVE` par construction (écrit d'avance). Le mark vient du canal `mark-price` (dernier
  `markPx` de la minute).
- **Hors P-G5-10, même statut** : `universe.p_s3_track_s` — P-S3 du track S (`LP-S-CSFUND-K1C-AMENDMENT-DRAFT.md` §4.3) vise le Gate 1 ;
  son effet sur les captures de BTC et ETH lues au Gate 2 n'est pas tranché.

**Choix de rédaction du brouillon, que le corps ne fixe pas (à confirmer au gel)** : (1) stress des termes mesurés par un ajout de
0,5 × leur valeur de conception (jamais plus faible que le stress du Gate 1 ; le code du 2026-10-21 ne stresse que frais et glissement) ;
(2) Holm local K = 2 au Gate 2, un type non inscrit comptant p = 1 (miroir du Gate 1) ; (3) aucun scellement des verdicts propres du
Gate 2 (aucune porte n'en dépend) ; (4) jour inexploitable repris de G2 §4.4 ; (5) règle de liquidité de G2 §3.3 **non** appliquée
(§10.1 ne nomme que métadonnées, comptages P3 et `listTime + 30 j`) ; (6) la queue de sortie simulée (h+15 → h+60, h+60 → h+75) n'entre
pas dans les empreintes, gardées égales à celles du Gate 1 (les heures de contrôle de §7.1 en dépendent).

**Code à écrire avant le gel (en plus de (B) xxi)** : clés `requires` propres au Gate 2 (`capture_source_quarantine_floor_check`,
`price_last_print_per_minute`, `key_minute_without_print_exclusion`, `classifier_mark_and_index_from_capture_or_index_history`,
`gate2_sim_*`, `gate2_funding_fill_grace_crossing`, `gate2_classifier_validation_funding_channel`, `gate2_stress_add_on_for_measured_terms`,
`gate2_window_end_per_type`, `gate2_inscription_from_gate1_result`, `gate2_quality_unusable_instrument_day`,
`owner_decisions_resolved_required_for_freeze`) ; au 2026-10-21, `g5_eval` lit `premium_okx` (champ `premium` du canal) sur la capture
et refuse `premium_mark_index` hors historique, alors que §10.1 exige `premium_mark_index` aux deux portes : (B) xxi doit le changer.

## Annexe C — Calendriers et sessions (dates lues par la red team le 2026-10-21 sur bls.gov, federalreserve.gov, nyse.com ; à re-vérifier au gel)

- **Fériés NYSE 2026 dans la fenêtre Gate 1** : 04-03 (Good Friday ; Pâques 2026 = 04-05), 05-25, 06-19, 07-03 (observé), 09-07.
  **Gate 2** : 2026-11-26, 2026-12-25, 2027-01-01, 2027-01-18, 2027-02-15, 2027-03-26 (Good Friday ; Pâques 2027 = 03-28) ⇒ 102 jours
  NYSE du 2026-11-02 au 2027-03-31 ; 110ᵉ jour le 2027-04-12.
- **CPI 2026** (08:30 ET = 12:30 UTC en EDT) : 04-10, 05-12, 06-10, 07-14, 08-12, 09-11 ; 10-14 en quarantaine (hors fenêtre).
- **Employment Situation 2026** : 04-03 (vendredi, NYSE fermée), 05-08, 06-05, 07-02 (**jeudi**), 08-07, 09-04, 10-02.
- **FOMC 2026** (14:00 ET = 18:00 UTC en EDT) : 04-29, 06-17, 07-29, 09-16 ; 10-27/28 en quarantaine.
- **T05 (calcul d'audit, éligibilité incluse)** : du 2026-04-29 (BTC éligible) au 2026-10-13, 116 jours NYSE avec ≥ 1 instrument éligible ;
  11 dates CPI/NFP y tombent un jour de bourse ⇒ **105** instants ; 103 à ≥ 3 instruments ; moitiés C4 : 53 / 52. Sans éligibilité
  (première version) : 135 jours, 12 dates, 123 instants.
- **Sessions** (spec `sessions`) : `us_cash_open` 09:30 et `us_cash_close` 16:00 `America/New_York` (jours NYSE) ; `london_xetra_open`
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
(lus 2026-10-21). Littérature citée par le rapport théorie et la red team, **non relue ici** : Hansen-Kim-Kimbrough (arXiv 2109.12142),
Baur et al. 2019, Eross et al. 2019, Wang-Liu-Hsu 2020, Bouchaud-Farmer-Lillo 2009, Nagel 2012, Kim & Hansen 2026 (arXiv 2607.09426),
He-Manela-Ross-von Wachter (2212.06888, 2506.08573).

Fin du brouillon. `DRAFT_NOT_FROZEN` — rien n'est gelé, rien n'est appelé, rien n'est lancé.
