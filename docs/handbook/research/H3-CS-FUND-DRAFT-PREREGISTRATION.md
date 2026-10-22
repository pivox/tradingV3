# CS-FUND — Portage de funding couvert (short X-Perp + long spot), OKX Europe : pré-enregistrement brouillon

**Statut : `DRAFT_NOT_FROZEN` (brouillon du 2026-10-15).** Ce texte n'est pas gelé et ne porte aucun sha256. Il
n'autorise ni lecture de données, ni enregistreur, ni campagne, ni ordre. Le gel exige l'autorisation écrite du
propriétaire et les prérequis du §0.5.

**Libellés.** Anciennement H3-A/H3-B/H3-C dans H1-NEXT-DECISION.md §3 ; renommées le 2026-10-15 : la mission H3 (scoring + gestion adaptative, docs H3-ARCHITECTURE.md et suivants, PR séparée) exige un générateur de candidats commun fondé sur une hypothèse économique explicite ; ces dossiers évaluent trois sources possibles, aucune n'est gelée. Ce document est la source **CS-FUND** (ancienne H3-A).

**Aucune donnée de résultat n'a été lue** pour écrire ce brouillon : ni historique de funding, ni bougie, ni ticker,
ni carnet, ni prix des instruments ci-dessous. Synthèse des trois sources candidates : [H3-CANDIDATE-SOURCES-2026-10-15.md](H3-CANDIDATE-SOURCES-2026-10-15.md).

Cette version intègre la revue du 2026-10-15 (verdict `needs_changes`). Le tableau du §13 indique où chaque point de
la revue est traité.

## 0. Déclarations préalables (obligatoires)

0.1 **Point d'accès funding interrogé une fois, champs de résultat supprimés.** Le point d'accès public
`public/funding-rate` (taux courant, pas l'historique) a été interrogé le 2026-10-15 vers 19:45Z pour 125 X-Perps par
le script `H3-A/scripts/funding_rules_blind.py`. Sa réponse contenait le taux courant, le taux suivant, la prime,
l'intervalle et des horodatages. Ces champs ont été supprimés en mémoire avant toute écriture ou affichage ; seuls les
noms de clés ont été affichés. Le fichier conservé (`H3-A/raw/xperp-funding-rules-blinded.json`) ne contient que
`instId`, `instType`, `method`, `formulaType`, `interestRate`, `maxFundingRate`, `minFundingRate` et `code` ; la revue
l'a vérifié. L'intervalle courant n'a volontairement pas été relevé, car il révèle si le plafond est touché.

0.2 **Exposition antérieure au funding d'autres produits**, déclarée comme influence sur l'a priori : funding Binance
(swing, Q3 V2, H1 Gate 2), funding OKX USDT-SWAP (swing v2 OKX #500, H1 Gate 2), détecteur D4 « funding contrarian »
(regime-r2). Ces lectures servaient de coûts ou de signaux directionnels, jamais à mesurer un portage couvert. Elles ne
comptent pas comme preuve et ne contaminent pas une validation prospective.

0.3 **Littérature connue** : BIS Working Paper 1087, *Crypto carry* (bis.org/publ/work1087.htm), utilisé comme cadre de
mécanisme seulement. Aucun chiffre de cette littérature n'entre dans les critères.

0.4 **Interdiction de lecture rétrospective.** Toute lecture de l'historique de funding, des bougies, des carnets ou
des prix des instruments du §2 antérieurs à T0 est interdite, avant et après le gel (pas de « contexte », pas de
rétro-remplissage). L'historique X-Perp servi depuis le 2026-03-31 (`H1-XPERP-MARKET-ACCESS.md`) est le résultat
lui-même : il n'est jamais lu.

0.5 **Prérequis bloquants avant le gel** (aucun n'est rempli au 2026-10-15) :

| # | Prérequis | Porte | Qui |
|---|---|---|---|
| P1 | Dans le compte : `BTC-USDC` et `ETH-USDC` achetables en spot | M1 | propriétaire |
| P2 | Dans le compte : l'USDC passe du spot (ou du Funding Account) à la marge isolée X-Perp | M1 | propriétaire |
| P3 | Les conditions d'OKX Europe Ltd acceptent les résidents français pour le spot | M1 | lecture des CGU |
| P4 | Passeport France nommé d'OKX Europe Markets Ltd (registre ESMA ou AMF) | M1 | lecture des registres |
| P5 | Le carnet spot servi par `eea.okx.com` est celui de l'entité EEA du compte (voir §5.3) ; sinon les coûts spot sont déclarés PROXY | M2 | propriétaire et lecture de la documentation |
| P6 | Conditions d'usage des données publiques OKX compatibles avec un enregistrement à des fins de recherche personnelle | M3 | lecture des conditions |
| P7 | InstrumentSpec rédigée pour les 4 instruments du bras A (identifiants, `ctVal`, `lotSz`, `minSz`, `tickSz`, devises) à partir des métadonnées publiques | M2 | auteur |
| P8 | Le propriétaire accepte par écrit la nature de la mesure (§1.2) et la durée (§6.4) | M4 | propriétaire |

L'API (spot et X-Perp) n'est pas un prérequis de la mesure papier. Elle le devient avant toute exécution réelle.

## 1. Hypothèse et nature de la mesure

### 1.1 Hypothèse

CS-FUND : sur OKX Europe, une position couverte « short X-Perp + long spot du même actif », à 1×, sur BTC et ETH, encaisse
sur 12 mois prospectifs un funding qui dépasse, net des coûts des exécutions et de la variation de base, (a) zéro et
(b) le taux sans risque de la période (€STR).

Mécanisme : composante d'intérêt de 0,01 % par 8 h incluse dans la formule de funding, et demande de levier acheteur.
Ce n'est pas une prédiction de prix.

### 1.2 Ce qu'un PASS mesurerait (à accepter avant le gel)

Formule officielle (`okx.com/en-eu/help/perps-funding-fee-mechanism`, mise à jour 2026-08-27) :
F = clamp[(P̄ + clamp(I − P̄, ±0,05 %)) / (8/N), plafond, plancher], avec I = 0,01 % et N l'intervalle en heures.

Conséquence mécanique : F = I dès que la prime moyenne P̄ reste dans [−0,04 % ; +0,06 %]. Le flux encaissé par le
short est donc **en grande partie contractuel**. Un PASS serait **une mesure de rendement**, pas la découverte d'un
avantage (edge). Les vraies inconnues sont :

- la fréquence et la durée des épisodes de prime négative (le short paie) ;
- les coûts réels des 4 exécutions ;
- la variation de base entre la jambe X-Perp et la jambe spot.

Le seul seuil qui a un sens économique est **C2** (battre l'€STR). La valeur moyenne de l'€STR sur la période est
INCONNUE au gel.

**Ordre de grandeur, arithmétique de la règle seulement (pas une prévision) :**

| Grandeur | Calcul | Valeur |
|---|---|---:|
| Funding brut annuel si F = I en permanence | 2 shorts × 120 USD × 0,03 %/j × 365 | 26,3 USD |
| Coûts d'aller-retour, tout taker, frais seuls | 2 × 120 USD × (2 × 0,10 % + 2 × 0,05 %) | 0,72 USD |
| Funding net des frais d'aller-retour | 26,3 − 0,72 | 25,6 USD |
| Rapporté au capital engagé (4 × 120 = 480 USD) | brut 26,3/480 ; net 25,6/480 | **5,5 % brut ; 5,3 % net des frais** |

Le dossier initial qualifiait 5,3 % de « brut » : c'est le chiffre **net des frais d'aller-retour** (correction de la
revue). Ni le glissement ni la variation de base ne sont déduits. Le funding peut être négatif.

## 2. Instruments (figés au gel, identifiants relevés le jour du gel par l'API publique `instruments`)

- **Bras A (décisif)** : `BTC-USD_UM_XPERP-310404` / `BTC-USDC` ; `ETH-USD_UM_XPERP-310404` / `ETH-USDC`. Identifiants
  relevés le 2026-10-15 (`H3-A/raw/eea-instruments-FUTURES.json`). Choix a priori par la liquidité X-Perp du 2026-10-14
  (353,1 et 67,8 M USD/jour, [TRADABLE-INSTRUMENT-UNIVERSE.md](TRADABLE-INSTRUMENT-UNIVERSE.md) §2), sans lecture de
  funding. Le pied de page officiel `okx.com/en-eu` renvoie vers « Trade BTC USDC / ETH USDC » : c'est la seule preuve
  EEA directe de disponibilité spot.
- **Bras B (descriptif, jamais décisif)** : les 27 X-Perps crypto ≥ 1 M USD/jour du 2026-10-14 qui ont une paire
  `<BASE>-USDC`, liste figée dans `H3-A/notes/xperp-spot-intersection.json`. Sert à juger la généralité, jamais à
  sélectionner après coup.

**Règles de funding relevées (2026-10-15, champs de règle seulement, fichier masqué) :** `method = current_period` et
`formulaType = withRate` pour les 125 X-Perps (le dossier initial écrivait « Méthode = withRate » : corrigé) ; taux
d'intérêt 0,01 % pour 124, 0 pour SAND ; plafond ±1 % pour 117 (116 + SAND), ±0,75 % pour ADA, BNB, DOGE, ETH,
LINK, SOL, XRP, ±0,375 % pour BTC.

## 3. Construction de la position papier (aucun ordre)

- Capital de référence : 500 USDC (500 USDT convertis ; coût de conversion exclu de la mesure, rapporté à part).
- 2 positions (une par actif). Notionnel par jambe N = 120 USD ; marge isolée du short = N (1×). Réserve 20 USD.
- Quantité : q contrats X-Perp = arrondi_inférieur(N / (ctVal × prix d'entrée)) ; jambe spot = q × ctVal /
  (1 − frais spot), pour que la quantité reçue nette des frais (prélevés dans la crypto reçue,
  `trading-fee-rules-faq-eea`) égale exactement q × ctVal (`lotSz` spot 1e-8).
- Entrée : au premier règlement de funding 00:00 UTC ≥ T0. Sortie : à T0 + 12 mois calendaires, au règlement
  00:00 UTC. Aucune sortie conditionnelle au funding (bras statique).
- **Rééquilibrage de marge (seul rééquilibrage prévu)** : si |mid X-Perp / prix_ref − 1| ≥ 50 %, on ferme les deux
  jambes et on rouvre à N au même instant (4 exécutions, coûts comptés). prix_ref = mid X-Perp de la dernière
  ouverture. Justification a priori : liquidation théorique du short 1× isolé vers +98,7 % (P0 × 2 / (1 + mmr),
  mmr = 0,65 % au palier 1, `position-tiers` BTC et ETH du 2026-10-15) ; à +50 %, la distance à la liquidation reste
  ≥ 32 %. Le nombre attendu de rééquilibrages est INCONNU (il faudrait des prix).
- **Coupe-risque** : si le PnL net cumulé d'une position atteint −2,50 USD (0,5 % du capital), elle est fermée et
  reste en cash jusqu'à la fin. Les deux positions ensemble ne peuvent pas perdre plus de 5 USD (1 %).
  - Déclaré : −2,50 USD = 2,1 % de N. Un seul épisode de funding au plancher peut le déclencher. Exemple de règle
    (pas une prévision) : pour ETH, plancher −0,75 % par règlement × 120 USD = −0,90 USD ; trois règlements au
    plancher suffisent, et l'intervalle peut raccourcir jusqu'à 1 h quand le plafond est touché
    (`H1-XPERP-MARKET-ACCESS.md` §2.8). C'est accepté comme protection.

## 4. Données (toutes postérieures à T0, collectées en direct)

- Nouvel enregistreur en lecture seule, non authentifié, sur `eea.okx.com`, **distinct de tout service `fwd-okx-*` et
  hors Docker trading** :
  - `funding-rate-history` des identifiants figés, interrogé chaque jour ; tout règlement avec `fundingTime < T0` est
    rejeté par le code avant écriture ;
  - mark price et index X-Perp, carnet 5 niveaux des paires spot et X-Perp, à chaque règlement et à chaque exécution
    papier ; intervalle de funding courant à chaque règlement (à partir de T0 seulement).
- T0 = premier 00:00 UTC ≥ gel + 7 jours ET après ≥ 7 jours de rodage sans trou de l'enregistreur. Pendant le rodage,
  seule la complétude est vérifiée ; les champs de résultat ne sont ni écrits ni affichés.
- Le hash du code de l'enregistreur et de l'évaluateur est inscrit au gel.
- Pas de gel sans P6 (licence des données publiques OKX).

## 5. Coûts et évaluation (pessimistes, une seule base de prix)

### 5.1 Frais

Barème officiel du jour du gel (`okx.com/en-eu/fees`, relevé du 2026-10-15) : compte « Spot & derivatives », niveau
Regular, spot 0,08 % maker / 0,10 % taker, X-Perp 0,02 % / 0,05 %. **Toutes les exécutions sont comptées taker.** Le
rattachement du compte du propriétaire au barème « Spot & derivatives » est NON VÉRIFIÉ pour le spot ; si le compte
relève du barème « Spot-only » (0,10 % / 0,20 %), c'est ce barème qui s'applique.

### 5.2 Glissement

Demi-écart + impact mesurés sur le carnet capturé pour N ; plancher déclaré 2 pb par exécution (hypothèse de
conception, non mesurée).

### 5.3 Carnet spot : même entité ou proxy

L'hôte `eea.okx.com` sert la **liste globale** des paires spot (on y trouve des paires TRY, BRL, AED). Le carnet spot
qu'il sert n'est donc pas prouvé être celui de l'entité EEA du compte. Avant le gel (P5), le propriétaire vérifie dans
son interface que l'identifiant et les règles (`minSz`, `tickSz`) de `BTC-USDC` et `ETH-USDC` sont ceux de l'API, et
la documentation est cherchée pour un carnet EEA distinct. Si l'identité des carnets n'est pas établie, les coûts spot
sont étiquetés **PROXY** dans tous les rapports et le plancher de glissement spot est doublé (4 pb).

### 5.4 Une seule base de prix

Pour le PnL mensuel, la variation de base et le coupe-risque, **les deux jambes sont évaluées au mid de leur propre
carnet** (mid spot, mid X-Perp). Le mark price X-Perp (indexé) ne sert qu'au contrôle de la distance à la liquidation
et de la marge. Cela supprime la base artificielle « mark contre mid » signalée par la revue.

### 5.5 Funding

Montant = valeur de position × taux de chaque règlement enregistré, signe réel (négatif = payé).

## 6. Unité d'observation, dépendance et durée

6.1 Unité de rapport : le mois calendaire. y_m = PnL net du bras A pendant le mois m, coûts d'entrée imputés au mois 1,
coûts de sortie au mois 12, coûts de rééquilibrage au mois où ils surviennent, évalué aux mids de fin de mois.

6.2 **Les mois ne sont pas indépendants.** Les régimes de funding persistent d'un mois à l'autre, et BTC et ETH sont
corrélés puis agrégés. Le test de signe « ≥ 10 mois positifs sur 12, P = 79/4096 = 0,019 » du dossier initial
supposait des mois indépendants : son niveau nominal est surestimé. Il est **retiré des critères décisifs** et ne
figure plus qu'au rapport descriptif.

6.3 **Aucune p-value n'est revendiquée.** Avec 12 points autocorrélés, aucune inférence n'est fiable. Le verdict est
une **mesure** assortie de contrôles de robustesse contre la concentration dans un seul régime (critères C3, C4).

6.4 Durée : 12 mois après T0, jamais prolongée. Verdict au plus tôt **≈ 12,5 mois après le gel** (7 jours minimum
avant T0, rodage, 12 mois, évaluation).

## 7. Critères (bras A, tous requis pour PASS, évalués une seule fois après le mois 12)

| # | Critère |
|---|---|
| C1 | Σ y_m > 0 (USD, après tous les coûts) |
| C2 | rendement net annualisé sur le capital engagé (480 USD) ≥ moyenne de l'€STR (BCE) sur la période |
| C3 | Σ y_m > 0 sans le meilleur mois |
| C4 | Σ y_m > 0 sans le meilleur bloc de 3 mois consécutifs (critère robuste aux régimes persistants) |
| C5 | PnL net > 0 séparément pour BTC et pour ETH |
| C6 | aucune liquidation ; au plus un coupe-risque déclenché |

Verdicts : `CS_FUND_PASS` (C1–C6) ; `CS_FUND_FAIL` sinon.

`CS_FUND_INCONCLUSIVE` si > 5 % des règlements d'un actif manquent à l'enregistreur, si OKX change le taux d'intérêt de la
formule, retire un instrument ou change de façon permanente l'accès du compte. Aucun redémarrage sans nouveau
pré-enregistrement.

Futilité (arrêt anticipé autorisé, jamais de PASS anticipé) : après le mois 6, si Σ y_1..6 ≤ 0, verdict
`CS_FUND_FAIL_FUTILITY`. Ce relevé intermédiaire ne porte que sur ce seul nombre.

## 8. Rapports obligatoires (non décisifs)

- Référence « intérêt seul » : 0,01 % par 8 h du notionnel, comparée au funding réel.
- Part des règlements à exactement l'intérêt, au plafond, au plancher, négatifs ; nombre de raccourcissements
  d'intervalle.
- Nombre de mois positifs sur 12 (l'ancien C3, descriptif).
- Bras B : même mesure sur les 27 X-Perps liquides.
- Coûts réels contre modélisés ; étiquette PROXY le cas échéant ; nombre de rééquilibrages et de coupe-risques.

## 9. Ressources et arbitrage du propriétaire

- **En papier**, rien n'est immobilisé.
- **En réel** (décision séparée, après un PASS seulement), la position bloquerait les **deux créneaux de risque** et
  480 des 500 USDT pendant 12 mois : aucune autre campagne ne pourrait être exécutée en parallèle. C'est un arbitrage
  que le propriétaire déclare avant le gel.
- Effort technique : un enregistreur public en lecture seule (S) suffit pour la mesure. L'exécution (bundle spot OKX,
  chemin X-Perp, orchestration de deux jambes, moniteur de marge, PnL USDC ; M à L,
  [H1-XPERP-TECHNICAL-GAP.md](H1-XPERP-TECHNICAL-GAP.md) §3) n'est construite qu'après un PASS.

## 10. Portes M1 à M5 au 2026-10-15

| Porte | Statut | Manques |
|---|---|---|
| M1 | CONDITIONAL | P1–P4 ; API non vérifiée (nécessaire seulement pour une exécution réelle) |
| M2 | CONDITIONAL | P5 (carnet spot EEA), P7 (InstrumentSpec) ; disponibilité spot EEA hors BTC/ETH/SOL/XRP/LTC non vérifiée (bras B seulement) |
| M3 | CONDITIONAL | P6 (licence) ; validation prospective seulement ; rétention de `funding-rate-history` INCONNUE, d'où le relevé quotidien |
| M4 | CONDITIONAL | mécanisme distinct (neutre en delta, absent du [rapport de paysage](../reports/research-strategy-landscape-2026-10.md)) ; pas de sauvetage caché de H1 ou H2 ; P8 ; non gelé |
| M5 | UNKNOWN | aucune donnée, par construction ; gain au taux d'intérêt seul du même ordre qu'une trésorerie |

## 11. Ce que le verdict autorise

- PASS : une proposition au propriétaire pour M5 (petite exécution démo puis réelle, construction des chemins spot et
  X-Perp). Le PASS n'autorise aucun trading réel par lui-même ; les drapeaux restent à 0.
- FAIL : CS-FUND est clos ; aucune variante (autres actifs, sélection par funding, levier, autre durée) sur ces 12 mois.

## 12. Gel (non effectué)

Le gel consistera à remplacer `DRAFT_NOT_FROZEN` par `FROZEN <horodatage UTC>`, enregistrer le sha256 du corps et des
codes, et publier avant T0. Toute modification après le gel passe par un amendement écrit, daté, antérieur à toute
lecture.

## 13. Traçabilité de la revue du 2026-10-15

| Point de la revue | Traitement |
|---|---|
| Lecture du point d'accès funding courant, champs supprimés | §0.1 conservé et précisé |
| « Méthode = withRate » erroné | §2 : `method = current_period`, `formulaType = withRate` |
| L'hypothèse est presque contractuelle ; PASS = mesure de rendement | §1.2, prérequis P8 |
| 5,3 % étiqueté « brut » alors que net des frais | §1.2 : 5,5 % brut, 5,3 % net des frais |
| Test de signe sur mois supposés indépendants | §6.2–6.3 : retiré des critères ; C4 par bloc de 3 mois |
| Carnet spot global possiblement différent du carnet EEA | §5.3, prérequis P5, étiquette PROXY |
| Mélange mark X-Perp et mid spot | §5.4 : mids des deux côtés ; mark pour la liquidation seulement |
| Créneaux et capital bloqués 12 mois ; coupe-risque à 2,1 % de N | §3 et §9 |
| M1 : CGU résidents français, passeport nommé, API ; M2 : InstrumentSpec ; M3 : licence | §0.5 (P3, P4, P6, P7), §10 |
| Durée ≈ 12,5 mois | §6.4 |
| (Renommage H3-A → CS-FUND, mission H3) | En-tête, §14 |

## 14. Branchement sur le générateur commun H3 (indicatif, non décisif)

Le contrat de candidat proposé par la mission H3 (`h3.opportunity_candidate/1`, module
`python-orchestrator/scripts/research_h3/contracts.py` de la branche `claude/h3-opportunity-architecture`, **en revue
dans une PR séparée, susceptible de changer**) décrit un candidat comme **un instrument, un côté, un stop de prix
protecteur obligatoire, une durée maximale de détention**, avec les limites H3 : 0,5 % par trade, 1 % au total, levier
≤ 1×.

CS-FUND n'entre pas tel quel dans ce contrat :

- deux jambes (X-Perp et spot) qui n'ont de sens qu'ensemble, alors que le contrat ne porte qu'un instrument ;
- pas de stop de prix : la protection est le coupe-risque sur le PnL net de la paire (§3) et la règle de marge à ±50 % ;
- une détention de 12 mois et un flux continu : le générateur n'émettrait que deux candidats (BTC, ETH) à T0. Il n'y a
  presque rien à classer (scoring) ni à gérer (gestion adaptative), la règle de rééquilibrage étant fixée a priori.

Deux voies, à trancher par la PR d'architecture, pas ici : (a) mesurer CS-FUND **hors du générateur**, comme mesure de
rendement autonome (ce protocole) ; (b) étendre le contrat à un **candidat apparié** (deux jambes, un risque commun).
Tant que ce choix n'est pas fait, CS-FUND n'alimente pas le générateur commun.

Sources : `eea.okx.com/api/v5/public/{instruments, funding-rate (champs de règle), position-tiers}` (2026-10-15) ;
`okx.com/en-eu/fees` ; `okx.com/en-eu/help/trading-fee-rules-faq-eea` ;
`okx.com/en-eu/help/perps-funding-fee-mechanism` ; `okx.com/en-gb/help/whats-selected-margin-eea` ;
`okx.com/en-us/help/okx-x-perps-eea-what-are-expiry-perps` ; BIS WP 1087 ;
[FRANCE-EXCHANGE-ELIGIBILITY.md](FRANCE-EXCHANGE-ELIGIBILITY.md) ;
[H1-XPERP-MARKET-ACCESS.md](H1-XPERP-MARKET-ACCESS.md) ; [H1-XPERP-ACCOUNT-CHECK.md](H1-XPERP-ACCOUNT-CHECK.md) ;
[RESEARCH-ELIGIBILITY-GATES.md](RESEARCH-ELIGIBILITY-GATES.md). Notes, instantanés de métadonnées, scripts et
`SHA256SUMS` : `tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-A/` (le répertoire garde l'ancien libellé ; les chemins `H3-A/…` cités plus haut y renvoient).
