# Sources candidates H3 : CS-FUND, CS-UNLOCK, CS-XMOM (synthèse du 2026-10-15)

**Aucune donnée de résultat lue, aucun protocole gelé, aucune campagne lancée.** Les trois protocoles liés ci-dessous
ont le statut `DRAFT_NOT_FROZEN` et ne portent aucun sha256. Aucun ordre, aucun appel authentifié, aucun enregistreur
démarré. Aucun chiffre de ce document n'est une prévision de performance.

**Libellés.** Anciennement H3-A/H3-B/H3-C dans H1-NEXT-DECISION.md §3 ; renommées le 2026-10-15 : la mission H3 (scoring + gestion adaptative, docs H3-ARCHITECTURE.md et suivants, PR séparée) exige un générateur de candidats commun fondé sur une hypothèse économique explicite ; ces dossiers évaluent trois sources possibles, aucune n'est gelée.

| Nouveau libellé | Ancien libellé | Idée | Protocole brouillon |
|---|---|---|---|
| **CS-FUND** | H3-A | portage de funding couvert : short X-Perp + long spot du même actif, BTC et ETH, 1× | [H3-CS-FUND-DRAFT-PREREGISTRATION.md](H3-CS-FUND-DRAFT-PREREGISTRATION.md) |
| **CS-UNLOCK** | H3-B | short X-Perp de T−7 j à T+2 j autour d'un cliff de déblocage des initiés ≥ 1 % du circulant, contre un témoin apparié | [H3-CS-UNLOCK-DRAFT-PREREGISTRATION.md](H3-CS-UNLOCK-DRAFT-PREREGISTRATION.md) |
| **CS-XMOM** | H3-C | momentum en coupe transversale, 3 longs / 3 courts, formation 14 j, détention 7 j, rebalancement hebdomadaire | [H3-CS-XMOM-DRAFT-PREREGISTRATION.md](H3-CS-XMOM-DRAFT-PREREGISTRATION.md) |

## 1. Ce qui a été lu, et les écarts déclarés

Lu : documents du dépôt ([RESEARCH-ELIGIBILITY-GATES.md](RESEARCH-ELIGIBILITY-GATES.md),
[FRANCE-EXCHANGE-ELIGIBILITY.md](FRANCE-EXCHANGE-ELIGIBILITY.md),
[TRADABLE-INSTRUMENT-UNIVERSE.md](TRADABLE-INSTRUMENT-UNIVERSE.md), [H1-XPERP-MARKET-ACCESS.md](H1-XPERP-MARKET-ACCESS.md),
[H1-XPERP-TECHNICAL-GAP.md](H1-XPERP-TECHNICAL-GAP.md), [H1-XPERP-ACCOUNT-CHECK.md](H1-XPERP-ACCOUNT-CHECK.md),
[H1-NEXT-DECISION.md](H1-NEXT-DECISION.md), [H2-CLOSURE-DECISION.md](H2-CLOSURE-DECISION.md),
[rapport de paysage](../reports/research-strategy-landscape-2026-10.md)) ; métadonnées publiques non authentifiées de
`eea.okx.com` (instruments FUTURES et SPOT, `position-tiers`, champs de règle du funding) ; pages officielles OKX Europe
(frais, mécanisme de funding, marge) ; pages de tarifs et de conditions des fournisseurs de calendrier ; littérature.

Trois écarts sont déclarés dans les protocoles. Aucun n'a produit de résultat :

| Source | Écart | Traitement |
|---|---|---|
| CS-FUND | Le point d'accès `public/funding-rate` (taux courant, pas l'historique) a été interrogé pour relever les règles ; taux, prime, intervalle et horodatages supprimés en mémoire avant écriture | Fichier conservé limité aux champs de règle, vérifié par la revue ; déclaration 0.1 |
| CS-UNLOCK | 22,4 Mo téléchargés sans clé depuis un hôte non officiel de DefiLlama (`defillama-datasets.llama.fi`), contraire à ses conditions (cl. 8, point 9) ; donnée de calendrier, non analysée, supprimée | Déclaration 11.2 ; seconde recherche de copies le 2026-10-15T21:12Z : aucune ; aucune donnée DefiLlama ne sera utilisée |
| CS-XMOM | Lecture de `market/tickers` (donnée de marché) pour mesurer l'univers ; champs open/high/low supprimés, `last` conservé | Déclaration 1.6 ; fichier et dérivés en quarantaine ; univers défini uniquement par l'enregistreur postérieur au gel |

## 2. Comparaison

| | CS-FUND | CS-UNLOCK | CS-XMOM |
|---|---|---|---|
| **M1** marché | CONDITIONAL : X-Perps vérifiés à l'interface ; spot sur le compte, transfert USDC spot → marge, CGU spot pour résidents français, passeport France nommé de l'entité MiFID non vérifiés | CONDITIONAL : X-Perps, short, marge isolée, frais 2/5 pb vérifiés à l'interface ; API et passeport nommé non vérifiés | CONDITIONAL : idem CS-UNLOCK |
| **M2** instruments | CONDITIONAL : 125 X-Perps crypto ont une paire USDC et EUR (27 des 29 liquides) ; minimums compatibles ; carnet spot EEA non prouvé (l'hôte sert la liste globale) | CONDITIONAL : couverture des tokens à déblocage parmi les X-Perps liquides INCONNUE | CONDITIONAL : 36 X-Perps ≥ 1 M USD/jour (2026-10-15) ; univers instable ; modèle de risque incompatible |
| **M3** données | CONDITIONAL : prospectif seulement (l'historique de funding est le résultat) ; licence des données publiques OKX à vérifier | **FAIL en l'état** : aucune source à la fois gratuite, sous licence et point-in-time ; stockage interdit sans accord écrit (Tokenomist, conditions API cl. 7.3(c)) | CONDITIONAL : prospectif seulement ; licence OKX à vérifier |
| **M4** hypothèse | CONDITIONAL : neutre en delta, absent du rapport de paysage ; un PASS mesurerait surtout un flux contractuel | CONDITIONAL : choc d'offre daté, distinct ; proximité thématique avec H1 déclarée | CONDITIONAL : distinct des familles rejetées ; choisi après avoir vu H2 ; littérature partagée après coûts |
| **M5** exploitabilité | UNKNOWN | UNKNOWN | UNKNOWN |
| **Unité d'observation** | le mois (12 sur 12 mois), autocorrélés | le trade apparié | la semaine (52 par an) |
| **Fréquence** | flux continu : ≈ 91 règlements par mois et par actif à 8 h ; 2 positions ouvertes à T0 | E événements éligibles par mois **INCONNU** ; au plus ≈ 40 trades par an si E = 5 (borne haute, sans regroupement) ; plafond 81 | 52 rebalancements par an ; rotation τ INCONNUE |
| **Puissance sur 12 mois** | pas de p-value revendiquée : verdict de mesure (C1–C6) | 39 à 78 trades requis pour d = 0,4 selon ρ ; d = 0,4 sans source | ne détecte qu'un Sharpe net ≥ 2,49 ; 104–156 semaines pour 1,76–1,44 |
| **Faisabilité 500 USDT** | 2 × (120 + 120) USD à 1× ; arithmétique de la règle : ≈ 26,3 USD par an de funding brut si F = I en permanence, 5,5 % brut et 5,3 % net des frais sur 480 USD | stops 8–20 % compatibles avec les minimums (ENA exclu à 20 %) ; abonnement de données ≥ 828 USD/an, supérieur au capital | 6 jambes de 80 USD compatibles avec les minimums ; granularité déformante ; 6 jambes sans stop hors du cadre 0,5 %/1 %/2 créneaux |
| **Coût des données** | 0 (public OKX, sous réserve de licence) | ≥ 828 USD/an (Tokenomist Pro, `tokenomist.ai/pricing`) | 0 (public OKX, sous réserve de licence) |
| **Délai avant verdict** | ≈ 12,5 mois après le gel | rodage ≥ 45 j + 12 ou 24 mois | rodage 14 j + essai 4 semaines + 104 à 156 semaines |
| **Risques principaux** | funding négatif ; carnet spot proxy ; 2 entités juridiques, un seul groupe ; en réel, les 2 créneaux et 480 USD bloqués 12 mois | licence ; E trop faible ; dates révisées ; squeeze du short ; reprise cachée de la dérive H1 | puissance ; frein des coûts ≈ 26–57 % par an à rotation complète ; krachs ; dépendance à une pièce ; sélection influencée par H2 |
| **Verdict de la revue** | `needs_changes` | `needs_changes` | `unsound` |

Sources des chiffres : protocoles liés (chaque nombre y est sourcé) ; métadonnées `eea.okx.com` du 2026-10-15 ;
[TRADABLE-INSTRUMENT-UNIVERSE.md](TRADABLE-INSTRUMENT-UNIVERSE.md) §2–3.

## 3. Constats de la revue indépendante du 2026-10-15, et corrections

| Source | Constat | Correction dans le brouillon |
|---|---|---|
| CS-FUND | « Méthode = withRate » erroné | `method = current_period`, `formulaType = withRate` (§2) |
| CS-FUND | l'hypothèse est presque contractuelle : un PASS est une mesure de rendement, pas un avantage | §1.2 ; acceptation écrite du propriétaire (P8) ; C2 contre l'€STR est le seul seuil économique |
| CS-FUND | 5,3 % étiqueté « brut » alors que net des frais | 5,5 % brut, 5,3 % net des frais d'aller-retour (§1.2) |
| CS-FUND | test de signe sur des mois supposés indépendants | retiré des critères ; C4 robuste par bloc de 3 mois ; aucune p-value revendiquée (§6) |
| CS-FUND | carnet spot global possiblement différent du carnet EEA | prérequis P5 ; sinon étiquette PROXY et plancher de glissement spot doublé (§5.3) |
| CS-FUND | mark X-Perp mélangé au mid spot | mids des deux côtés ; mark pour la liquidation seulement (§5.4) |
| CS-FUND | créneaux et capital bloqués 12 mois ; coupe-risque à 2,1 % de N | déclarés (§3, §9) |
| CS-UNLOCK | M3 en échec ; coût supérieur au capital | préalables P0.1, P0.3 ; pas de gel sans accord écrit |
| CS-UNLOCK | incident DefiLlama absent des déclarations | §11.2, avec seconde recherche de copies |
| CS-UNLOCK | puissance optimiste ; formule à un échantillon pour un test apparié | §9.1 : n = 6,19 / d_pair², d_pair = d / √(2(1 − ρ)) ; OD-B1, OD-B2 ; C0 |
| CS-UNLOCK | modèle d'Erlang sans regroupement | chiffres présentés comme bornes hautes ; simulation des 2 créneaux sur les dates réelles du comptage (§9.2) |
| CS-UNLOCK | ambiguïté cliff / tranche | §3 : cliff seul, sans déblocage de la même catégorie dans les 90 jours ; champ du fournisseur nommé au gel (P0.2) |
| CS-UNLOCK | démarrage incompatible avec l'interdiction de lecture rétrospective | rodage ≥ 45 jours (§8) |
| CS-UNLOCK | reprise cachée de la dérive H1 | témoin apparié sur l'âge (§7) et critère C5 |
| CS-XMOM | puissance insuffisante sur 12 mois | 52 semaines exclues comme horizon décisif ; OD-2 ; abandon recommandé |
| CS-XMOM | « tout est refait » ambigu ; frein des coûts | rotation différentielle et τ_w enregistré (§4) ; frein chiffré (§6) |
| CS-XMOM | r_w par côté présenté comme par notionnel brut | facteur ½ (§4) |
| CS-XMOM | cadre de risque incompatible | OD-1, option (c) papier sans capital par défaut |
| CS-XMOM | lecture de `market/tickers` | déclarée, quarantaine (§1.6) |
| CS-XMOM | sélection influencée par H2 ; dérive H1 dans la jambe courte | §1.1–1.2 ; C-rand stratifié par âge ; critère D8 |
| CS-XMOM | licence OKX non exigée ; code testé seulement sur synthétique | P1 ; essai à blanc scellé de 4 semaines (§5.3) |
| toutes | licence des données publiques OKX à vérifier avant le gel | prérequis dans les trois protocoles |

## 4. Classement

**Classement de la revue, repris ici : 1) CS-FUND, 2) CS-UNLOCK, 3) CS-XMOM.**

1. **CS-FUND** : la seule source dont le nombre d'observations ne dépend ni d'événements rares ni d'une puissance
   insuffisante sur un an. Un enregistreur public en lecture seule suffit ; pas de coût de données, pas de sauvetage
   de H1 ou H2. Limites acceptées d'avance ou non par le propriétaire : un PASS mesurerait surtout un rendement
   contractuel, de l'ordre d'une trésorerie, et le verdict arrive ≈ 12,5 mois après le gel.
2. **CS-UNLOCK** : mécanisme plausible et distinct, mais bloqué aujourd'hui à M3, avec un E inconnu et une puissance
   probablement insuffisante même si E ≥ 5.
3. **CS-XMOM** : à abandonner sous les contraintes actuelles (puissance, coûts, cadre de risque, sélection après H2).

**Adéquation au générateur commun H3** (contrat `h3.opportunity_candidate/1` proposé sur la branche
`claude/h3-opportunity-architecture`, en revue dans une PR séparée et susceptible de changer : un instrument, un côté,
un stop de prix obligatoire, une durée maximale, 0,5 % par trade, 1 % au total, levier ≤ 1×). L'ordre n'est pas le
même :

| Source | Adéquation | Raison |
|---|---|---|
| CS-UNLOCK | **bonne** | un candidat par événement, à T−7 j : X-Perp, short, stop d, 9 jours ; variables de score disponibles avant la décision (% du circulant, catégorie, confiance, âge, ADV30, σ30, funding) |
| CS-FUND | faible | deux jambes indissociables, pas de stop de prix, 12 mois, 2 candidats en tout : presque rien à classer ni à gérer ; demande un « candidat apparié » ou une mesure hors générateur |
| CS-XMOM | incompatible | 6 jambes sans stop ; 6 × 0,5 % = 3 % > 1 % ; demande un « candidat panier » |

Conséquence : la source la mieux classée sur la puissance (CS-FUND) est la moins utile au scoring et à la gestion
adaptative, et la mieux adaptée au générateur (CS-UNLOCK) est bloquée par la licence et la fréquence. Aucune source ne
remplit aujourd'hui les deux rôles.

## 5. Inconnue décisive et vérification unique, par source

| Source | Inconnue décisive | Vérification qui la tranche (aucune n'est lancée) |
|---|---|---|
| CS-FUND | la jambe spot est-elle tenable depuis le compte (achat `BTC-USDC`/`ETH-USDC`, passage de l'USDC à la marge isolée X-Perp) ? Le reste (funding réel, coûts, base) ne se mesure qu'en prospectif | le propriétaire vérifie P1 et P2 dans son interface, sans passer d'ordre |
| CS-UNLOCK | le nombre E d'événements éligibles par mois (et donc la possibilité d'atteindre le n requis) | le comptage E sur métadonnées de calendrier seulement (P0.5), possible uniquement après un **accord écrit** du fournisseur (P0.1) |
| CS-XMOM | l'acceptation d'une mesure papier sans capital de 2 à 3 ans | la réponse écrite du propriétaire à OD-1 et OD-2 ; sans elle, abandon |

## 6. Ce que le propriétaire doit décider

1. Retenir ou non CS-FUND comme **mesure de rendement** (pas comme recherche d'avantage), en acceptant ≈ 12,5 mois
   avant verdict et, en cas d'exécution réelle ultérieure, l'immobilisation des 2 créneaux et de 480 USD pendant 12 mois.
2. Pour CS-FUND, faire les vérifications de compte P1 et P2, et décider s'il veut un « candidat apparié » dans le
   contrat H3 ou une mesure hors générateur.
3. Pour CS-UNLOCK, décider s'il demande un accord de licence écrit (Tokenomist ou équivalent) et s'il accepte un budget
   de données ≥ 828 USD/an déclaré comme coût de recherche ; puis autoriser ou non le comptage E sur métadonnées.
4. Pour CS-XMOM, confirmer l'abandon, ou choisir OD-1 (c) et OD-2 (104 ou 156 semaines).
5. Pour toute source retenue : la vérification de la licence des données publiques OKX avant le gel, et le gel
   lui-même (horodatage, sha256), qui reste une décision écrite du propriétaire.

## 7. Comment chaque source se brancherait sur le générateur commun H3

Le générateur commun produit les mêmes candidats pour toutes les variantes évaluées par la mission H3, sans score ;
le scoring et la gestion adaptative interviennent ensuite. Chaque source devrait fournir un identifiant d'hypothèse
explicite, versionné et lié au gel de son protocole.

- **CS-UNLOCK** : branchement direct. Le protocole fixe déjà le moment de décision (T−7 j, 00:00 UTC), le côté, le stop
  et la durée ; le calendrier entre comme instantané daté (≤ T−14 j), compatible avec la règle
  `observed ≤ available ≤ decision` du contrat. Le contrôle apparié reste celui du protocole. Limite : ≈ 40 trades par
  an au mieux, trop peu pour apprendre un score sans diviser l'échantillon.
- **CS-FUND** : pas de branchement tant que le contrat ne prévoit pas de candidat à deux jambes. En attendant, mesure
  autonome par son propre protocole.
- **CS-XMOM** : pas de branchement sans « candidat panier » et sans cadre de risque de portefeuille. Utiliser le rang
  relatif comme variable de score pour une autre source serait une nouvelle hypothèse, non couverte ici.

Le contrat cité est un prototype en revue : ce paragraphe sera à réaligner sur la version qui sera fusionnée. Il ne
préjuge pas des documents de la mission H3 (PR séparée).

## 8. Fichiers de travail

Notes, instantanés de métadonnées, scripts et manifestes `SHA256SUMS` :
`tradingV3-private/research/20261015T2200Z-h3-dossiers/{H3-A,H3-B,H3-C}/` (répertoires sous les anciens libellés).
