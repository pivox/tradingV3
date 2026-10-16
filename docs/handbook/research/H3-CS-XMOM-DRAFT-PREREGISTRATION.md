# CS-XMOM — Momentum en coupe transversale, neutre au marché, sur les X-Perps OKX Europe : pré-enregistrement brouillon

**Statut : `DRAFT_NOT_FROZEN` (brouillon du 2026-10-15).** Ce texte n'est pas gelé et ne porte aucun sha256. Il
n'autorise ni lecture de données, ni enregistreur, ni campagne, ni ordre. Le gel exige la décision écrite du
propriétaire sur OD-1 à OD-4 (§12) et les prérequis du §0.

**Libellés.** Anciennement H3-A/H3-B/H3-C dans H1-NEXT-DECISION.md §3 ; renommées le 2026-10-15 : la mission H3 (scoring + gestion adaptative, docs H3-ARCHITECTURE.md et suivants, PR séparée) exige un générateur de candidats commun fondé sur une hypothèse économique explicite ; ces dossiers évaluent trois sources possibles, aucune n'est gelée. Ce document est la source **CS-XMOM** (ancienne H3-C).

**Aucune donnée de résultat n'a été lue** : ni rendement, ni bougie, ni historique de funding, ni classement. Un écart
de procédure (lecture du point d'accès `market/tickers`) est déclaré au §1.6. Synthèse des trois sources candidates :
[H3-CANDIDATE-SOURCES-2026-10-15.md](H3-CANDIDATE-SOURCES-2026-10-15.md).

**Verdict de la revue du 2026-10-15 : `unsound`. Recommandation : abandon.** Ce brouillon corrige néanmoins chaque
point de la revue (tableau du §13), pour que le propriétaire décide sur un texte complet. S'il est conservé, ce n'est
que comme **mesure papier sans capital sur 104 à 156 semaines** (OD-1 (c), OD-2).

## 0. Prérequis bloquants avant le gel

| # | Prérequis | Porte |
|---|---|---|
| P1 | Conditions d'usage des données publiques OKX compatibles avec un enregistrement de recherche personnelle (même exigence que CS-FUND) | M3 |
| P2 | Décisions écrites du propriétaire OD-1 à OD-4 (§12) | M2, M4 |
| P3 | Tests à réponse connue de l'enregistreur et de l'évaluateur sur données synthétiques, plus la période d'essai à blanc du §5.3 | M4 |
| P4 | Pour une exécution réelle seulement (hors de ce protocole) : API X-Perp vérifiée, passeport France nommé d'OKX Europe Markets Ltd | M1 |

## 1. Déclarations préalables (obligatoires)

1.1 **Influence de H2 sur le choix de l'idée.** Les auteurs ont vu les résultats de H2 Gate 1
([H2-CLOSURE-DECISION.md](H2-CLOSURE-DECISION.md)), en particulier la continuation du côté long (−177,3 pb, IC 95 %
[−376,6 ; −4,9]), qui ressemble à la jambe courte d'un momentum. **L'idée a été choisie après avoir vu ce résultat :
cet effet de sélection demeure**, même si aucune sortie de H2 n'est comptée comme preuve et si aucun paramètre de H2
(horizon 3 j, résidu net du marché, seuil k, filtre de marché calme, fenêtres β 60 j) n'est repris.

1.2 **Influence de H1.** H1 S_B (`GATE1_PASS`, dérive négative après cotation) pourrait se retrouver dans la jambe
courte : tout l'univers X-Perp a moins de 7 mois (cotations entre le 2026-03-30 et le 2026-10-01, métadonnée
`listTime`). Le filtre d'âge de 45 jours (§3) ne suffit pas à l'exclure ; d'où le contrôle stratifié par âge (§7) et
le critère D8.

1.3 **Aucune lecture historique**, même en développement : ni l'archive OKX Global (consommée par swing v2 OKX et H2),
ni les données Binance, ni l'historique X-Perp antérieur au gel. Les paramètres (§4) viennent de la littérature.

1.4 **Pas de grille.** Une seule configuration primaire. Toute variante est rapportée, jamais décisive.

1.5 Les fenêtres W0–W2 de `fwd-okx-r1` ne sont ni lues ni réutilisées.

1.6 **Écart de procédure déclaré (2026-10-15T19:55Z).** Pour mesurer l'univers liquide, le point d'accès public
`market/tickers?instType=FUTURES` a été interrogé. C'est une donnée de marché, pas une métadonnée autorisée par les
règles de la mission. Les champs `open24h`, `high24h`, `low24h`, `sodUtc*`, `bidPx`, `askPx` ont été supprimés en
mémoire sans être lus ; ont été conservés `instId`, `vol24h`, `volCcy24h`, `last` et `ts` (vérifié par la revue).
Aucun rendement ni classement n'a été calculé. Mais, combiné à l'instantané du 2026-10-14
([TRADABLE-INSTRUMENT-UNIVERSE.md](TRADABLE-INSTRUMENT-UNIVERSE.md)), ce fichier permettrait de dériver un rendement
d'un jour. Retirer `last` ne suffirait pas : la valeur échangée en USD et le minimum d'ordre en USD dérivés de ce
fichier contiennent ce prix. Le fichier (`tickers-FUTURES-volume-only.json`, sha256 `930928e7…`) et ses dérivés sont
donc **mis en quarantaine** : ils ne seront plus ouverts, ne serviront à aucun classement et à aucun paramètre ; seuls
les chiffres d'univers et de minimums déjà cités ci-dessous en sont tirés. Le protocole définit l'univers uniquement à
partir de l'enregistreur postérieur au gel (§3, §5).

## 2. Hypothèse

CS-XMOM : sur les X-Perps crypto liquides d'OKX Europe, le rendement relatif sur 14 jours prédit positivement le
rendement relatif des 7 jours suivants. Un panier long des 3 premiers / court des 3 derniers, à notionnel égal, a une
espérance nette de coûts et de funding strictement positive, au-delà de paniers aléatoires neutres en dollars, de même
composition par âge, tirés dans le même univers.

H0 : espérance nette ≤ 0, ou pas de surplus sur le contrôle aléatoire stratifié.

Mécanisme proposé (non démontré) : sous-réaction et diffusion lente de l'information, comportement moutonnier sur
quelques semaines. Littérature favorable : Jegadeesh & Titman (1993) pour les actions ; Liu, Tsyvinski & Wu (2022),
*J. Finance* 77(2), 1133–1177, rebalancement hebdomadaire, rendements bruts de coûts. Littérature contraire ou
réservée : Grobys & Sapkota (2019), *Economics Letters* 180, 6–10 (pas de momentum mensuel significatif) ; Han, Kang &
Ryu (document de travail : momentum transversal faible après coûts) ; Grobys et al. (2025), *Financial Markets and
Portfolio Management* (krachs sévères, une seule pièce peut annuler la significativité) ; Dobrynskaya (momentum
jusqu'à 2 semaines, retournement au-delà de 4 semaines ; revue non confirmée). **La littérature après coûts est
partagée, voire défavorable.**

## 3. Univers (évalué à chaque rebalancement, uniquement avec des données postérieures au gel)

Éligible au rebalancement t si toutes les conditions sont vraies :

- `instType = FUTURES`, `ruleType = xperp`, `instCategory = 1` (crypto), `state = live`, `settleCcy = USD` ;
- âge ≥ 45 jours depuis `listTime` (exclut la fenêtre t0 → t0 + 31 j de H1 ; l'emprunt de ce repère à H1 est déclaré) ;
- médiane, sur les 7 relevés quotidiens précédents (00:00 UTC) **de l'enregistreur**, de la valeur échangée sur 24 h
  (`vol24h × ctVal × last`) ≥ 1 000 000 USD. La médiane glissante est obligatoire : entre les instantanés du 2026-10-14
  (29 noms) et du 2026-10-15 (36 noms), environ un quart des membres a changé ;
- 14 jours de prix horaires enregistrés sans trou > 6 h ;
- base qui n'est pas un stablecoin ni un actif adossé (liste figée au gel, OD-3).

Semaine valide si ≥ 12 instruments éligibles ; sinon elle est sautée et comptée « invalide ». Un instrument retiré de
la cote en cours de détention est clôturé au dernier prix enregistré, avec coût pessimiste (§6).

## 4. Signal, portefeuille et rotation (configuration primaire unique)

- Rebalancement : chaque mercredi 14:00 UTC (T ; OD-3). Formation : log-rendement du dernier prix entre T − 14 j − 1 h
  et T − 1 h.
- Classement décroissant ; égalités départagées par `instId` alphabétique.
- Long des 3 premiers, short des 3 derniers ; 80 USD de notionnel visé par jambe ; neutre en dollars ; 480 USD de
  notionnel brut.
- **Rotation différentielle (précisée à la demande de la revue).** À T + 7 j, on ne traite que les noms qui changent :
  une jambe qui sort du panier est fermée, une jambe qui entre est ouverte à 80 USD ; une jambe qui reste n'est ni
  fermée ni redimensionnée (pas de petits ordres d'ajustement). Le taux de rotation τ_w = (nombre de jambes changées)
  / 6 est enregistré chaque semaine.
- Détention sans stop ni objectif dans la statistique primaire.
- **Statistique primaire, par unité de notionnel brut (corrigée).** r_w = ½ × [moyenne(rendements longs) −
  moyenne(rendements courts)], en pb, nette de coûts et de funding. Le facteur ½ ramène l'écart, exprimé par unité de
  notionnel d'un côté, au notionnel brut (deux côtés). En USD : PnL_w = r_w × 480.
- Exécution simulée (aucun ordre) : prix d'entrée et de sortie = mid du carnet relevé à T + 5 min, plus la moitié de
  l'écart affiché, plus le glissement (§6).

## 5. Données et essai à blanc (prospectifs seulement)

5.1 Enregistreur public non authentifié sur `eea.okx.com`, **hors des services `fwd-okx-*` et hors Docker trading**
(OD-4), démarré après le gel : instruments (quotidien), tickers (horaire : `last`, `vol24h`), carnet 5 niveaux à
T + 5 min, historique de funding publié après le gel.

5.2 Rodage de 14 jours (données de formation du premier rebalancement), sans résultat. Chaque fichier est horodaté et
haché (sha256) ; le manifeste est figé chaque semaine.

5.3 **Essai à blanc de 4 semaines (correction de la revue : pas de données de développement).** Après le rodage, le
pipeline complet tourne 4 semaines. Seules des sorties **structurelles** sont affichées : taille de l'univers, nombre
de jambes, τ_w, complétude, coûts comptés sur des prix synthétiques. Les rendements calculés sont écrits dans un fichier
scellé dont seul le sha256 est publié ; il n'est ouvert qu'à la lecture finale, et ces 4 semaines sont **exclues du
verdict**. Un défaut trouvé pendant l'essai est corrigé par un amendement daté avant la première semaine comptée.
Après la première semaine comptée, aucun changement de code sans amendement, et un amendement ne vaut que pour les
semaines suivantes.

## 6. Coûts et funding

- Frais : taker 5 pb par côté (barème constaté sur le compte, [H1-XPERP-ACCOUNT-CHECK.md](H1-XPERP-ACCOUNT-CHECK.md)) ;
  maker non supposé.
- Glissement par côté : base 20 pb ; pessimiste 50 pb ([TRADABLE-INSTRUMENT-UNIVERSE.md](TRADABLE-INSTRUMENT-UNIVERSE.md)
  §3 ; hypothèse du dépôt, non vérifiée sur X-Perps).
- Funding : réellement publié pendant la détention ; payé ou reçu selon le côté. Plafonds et intervalles par contrat
  ([H1-XPERP-MARKET-ACCESS.md](H1-XPERP-MARKET-ACCESS.md) §2.8) : risque de squeeze sur la jambe courte.
- **Ordre de grandeur du frein des coûts (arithmétique, pas une prévision).** Aller-retour d'une jambe : base
  2 × (5 + 20) = 50 pb ; pessimiste 2 × (5 + 50) = 110 pb. Avec rotation complète chaque semaine (τ = 1) : 50 pb par
  semaine sur le notionnel brut, soit **≈ 26 % par an** (2,40 USD par semaine sur 480 USD) ; pessimiste **≈ 57 % par
  an**. Avec la rotation différentielle, le frein vaut τ̄ × ces chiffres ; τ̄ est INCONNU. Le seuil de rentabilité brut
  hebdomadaire est donc τ̄ × 50 pb en base.
- Granularité : un calcul secondaire en contrats entiers, avec une jambe de 80 USD, est rapporté (un contrat ENA fait
  environ 25 % d'une jambe, un contrat BTC environ 10 %).

## 7. Contrôles

- **C-rand (stratifié par âge)** : chaque semaine, 1 000 paniers aléatoires de 3 longs / 3 courts dans le même univers,
  avec **la même composition par tercile d'âge X-Perp** que le panier réel, mêmes coûts, même funding, même règle de
  rotation. Surplus = r_w − médiane des tirages ; graine figée au gel.
- **C-beta** : régression de r_w sur le rendement hebdomadaire équipondéré de l'univers ; on retient l'ordonnée à
  l'origine (alpha) et la pente (bêta réalisé). Le bêta négatif dans les rebonds est la cause classique des krachs du
  momentum (Daniel & Moskowitz 2016).
- Rapportés, non décisifs : r_w en semaines de hausse et de baisse ; jambes longue et courte séparées ; âge moyen de
  chaque jambe ; r_w par tercile d'âge.

## 8. Critères de décision (tous requis ; horizon fixé par OD-2)

| # | Critère |
|---|---|
| D1 | ≥ 90 % de semaines valides |
| D2 | moyenne de r_w nette (coûts de base) > 0, et borne basse de l'IC 90 % > 0 (bootstrap par blocs de 4 semaines, 10 000 tirages, graine figée) |
| D3 | surplus moyen sur C-rand > 0, avec p unilatéral ≤ 0,05 (rang dans la distribution des moyennes aléatoires) |
| D4 | alpha C-beta > 0 |
| D5 | moyenne > 0 sans les ⌈0,05 n⌉ meilleures semaines, **et** sans l'instrument dont la contribution cumulée est la plus forte |
| D6 | moyenne > 0 sur chaque moitié chronologique |
| D7 | moyenne > 0 avec les coûts pessimistes (glissement 50 pb) |
| D8 | jambe courte seule : rendement net du short > 0 dans le tercile d'âge le plus ancien (contre le sauvetage caché de H1) |

Verdict : `CS_XMOM_PASS` si D1–D8 passent ; `CS_XMOM_FAIL` sinon ; `CS_XMOM_INVALID` si D1 échoue. Un PASS n'autorise
ni la démo ni le trading réel : ce sont des décisions séparées du propriétaire, avec les drapeaux à 0.

## 9. Puissance (déclarée avant toute donnée)

Test unilatéral à 5 %, puissance 80 % : n ≥ (1,645 + 0,842)² / SR_w² = 6,18 / SR_w² semaines (rendements hebdomadaires
i.i.d. ; l'autocorrélation, les queues épaisses et la dépendance à une seule pièce augmentent n).

| Sharpe annualisé net | Semaines nécessaires |
|---|---:|
| 1,0 | 321 |
| 1,5 | 143 |
| 2,0 | 80 |
| 2,5 | 51 |

52 semaines ne détectent qu'un Sharpe ≥ 2,49 ; 104 semaines, ≥ 1,76 ; 156 semaines, ≥ 1,44. **Un FAIL à 52 semaines
ne réfute pas un effet modéré.** Un horizon de 52 semaines n'est donc **pas proposé** comme horizon décisif (OD-2).

## 10. Différence avec les familles rejetées (M4)

- Swing v1/v2, swing v2 OKX, v2 selection : signaux temporels par paire (breakout, tendance), exposition longue, stops
  ATR. CS-XMOM n'a ni breakout ni stop, il est neutre en dollars et classe les instruments entre eux.
- regime-r2 D2 (momentum 4/12 semaines) : filtre de régime sur l'exposition au marché. CS-XMOM a une exposition nette
  nulle ; son horizon (14 j) vient de la littérature, pas de D2.
- H2 : retour d'un résidu sur 3 jours, déclenché par un choc, en marché calme. CS-XMOM est une continuation du rang sur
  14 jours, inconditionnelle. Le recouvrement avec la jambe courte et l'effet de sélection restent déclarés (§1.1).
- H1 : dérive après cotation ; filtre d'âge, contrôle stratifié par âge et D8.

## 11. Arrêt et intégrité

- Une seule lecture finale à l'horizon OD-2. Aucun point intermédiaire n'est décisif ; un relevé de santé de
  l'enregistreur (complétude seulement, sans rendement) est autorisé chaque semaine.
- Aucun paramètre n'est modifié après le gel. Tout amendement est écrit, haché, et ne vaut que pour les semaines
  postérieures.
- Panne de l'enregistreur > 24 h pendant une détention : la semaine est invalide (pas de remplissage rétroactif).
- Un FAIL ou un INVALID clôt CS-XMOM ; aucune variante (autre horizon, autre taille de panier, pondération) n'est
  testée sur les mêmes semaines.

## 12. Décisions du propriétaire requises avant le gel

- **OD-1 Modèle de risque.** Le cadre H3 (0,5 % par trade, 1 % au total, 2 créneaux, stop obligatoire) ne s'applique
  pas à 6 jambes sans stop. Options : (a) le panier compte pour 1 % avec un stop de panier à −5 USDT, soit environ
  1 % du notionnel brut, ce qui réintroduit des stops serrés dominés par les coûts (leçon de l'étude levier > 2×) ;
  (b) notionnel brut réduit pour qu'une perte extrême déclarée tienne dans 1 % ; **(c) papier seulement, sans capital,
  jusqu'au verdict : option par défaut de ce brouillon.**
- **OD-2 Horizon.** 104 ou 156 semaines (52 n'est pas décisif, §9). Au mieux un verdict vers fin 2028 ou fin 2029.
- **OD-3 Exclusions** (stablecoins, actifs adossés) et jour et heure de rebalancement.
- **OD-4 Enregistreur** : hors des services `fwd-okx-*` et hors Docker trading, en lecture publique seulement.

## 13. Portes et traçabilité de la revue

| Porte | Statut au 2026-10-15 |
|---|---|
| M1 | CONDITIONAL : entreprise MiFID au registre ESMA ; compte et X-Perps vérifiés dans l'interface ; API et passeport France nommé non vérifiés. La mesure papier n'en dépend pas. |
| M2 | CONDITIONAL : 36 instruments ≥ 1 M USD/jour le 2026-10-15, vente à découvert possible, minimums ≤ 20,4 USD ; univers instable ; granularité ; modèle de risque incompatible (OD-1) ; pas d'InstrumentSpec dans le code |
| M3 | CONDITIONAL : historique X-Perp trop court, archives consommées, validation prospective seulement ; P1 (licence OKX) |
| M4 | CONDITIONAL : mécanisme distinct, littérature partagée ; sélection influencée par H2 ; non gelé |
| M5 | UNKNOWN : puissance insuffisante sous un Sharpe net de 2,5 à 52 semaines ; frein des coûts ≈ 26–57 % par an à rotation complète |

| Point de la revue | Traitement |
|---|---|
| Puissance insuffisante à 12 mois ; 2–3 ans hors de proportion | §9, OD-2 ; recommandation d'abandon en tête |
| Frein des coûts 26–57 %/an ; « tout est refait » ambigu | §4 (rotation différentielle, τ_w), §6 |
| r_w défini par unité de notionnel brut mais calculé par côté | §4 : facteur ½ |
| Cadre de risque incompatible ; granularité | OD-1 (option (c) par défaut), §6 |
| Lecture de `market/tickers` | §1.6 : déclaré, fichier en quarantaine |
| Sélection influencée par H2 ; dérive de H1 dans la jambe courte | §1.1, §1.2, C-rand stratifié par âge, D8 |
| Licence des données OKX absente | P1 |
| Code testé seulement sur synthétique | P3, §5.3 (essai à blanc scellé) |
| Littérature partagée ; rotation de l'univers ≈ 25 % en un jour | §2, §3 (médiane glissante) |
| (Renommage H3-C → CS-XMOM, mission H3) | En-tête, §14 |

## 14. Branchement sur le générateur commun H3 (indicatif, non décisif)

Le contrat de candidat proposé par la mission H3 (`h3.opportunity_candidate/1`, module
`python-orchestrator/scripts/research_h3/contracts.py` de la branche `claude/h3-opportunity-architecture`, **en revue
dans une PR séparée, susceptible de changer**) décrit un candidat comme un instrument, un côté, un stop de prix
obligatoire et une durée maximale, sous les limites 0,5 % par trade et 1 % au total.

CS-XMOM n'y entre pas : 6 jambes sans stop, tenues ensemble. Traitées comme 6 candidats séparés, elles demanderaient
6 stops et 6 × 0,5 % = 3 % de risque, au-delà du plafond de 1 %. Il faudrait un **candidat panier**, que le contrat
actuel ne prévoit pas. Un usage différent, comme variable de score (rang relatif sur 14 jours) pour une autre source,
serait une **nouvelle hypothèse**, non couverte par ce brouillon.

Sources : `eea.okx.com/api/v5/public/instruments?instType=FUTURES` (2026-10-15T19:55:12Z) ; Liu, Tsyvinski & Wu (2022),
doi 10.1111/jofi.13119, NBER w25882 ; Grobys & Sapkota (2019), osuva.uwasa.fi/handle/11111/2278 ; Grobys, Kolari,
Sandretto, Shahzad & Äijö (2025), doi 10.1007/s11408-025-00474-9 ; Han, Kang & Ryu (document de travail, acfr.aut.ac.nz) ;
Dobrynskaya (hse.ru/en/news/research/559533243.html) ; Fičura (2023), FFA Working Papers 5:003 ; Fieberg, Liedtke, Metko
& Zaremba (2023), *Quantitative Finance* 23(12), doi 10.1080/14697688.2023.2269999 ; Daniel & Moskowitz (2016), *JFE*
122(2) ; Jegadeesh & Titman (1993), *JF* 48(1) ; [H2-CLOSURE-DECISION.md](H2-CLOSURE-DECISION.md) ;
[RESEARCH-ELIGIBILITY-GATES.md](RESEARCH-ELIGIBILITY-GATES.md). Notes, instantanés et `SHA256SUMS` :
`tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-C/` (le répertoire garde l'ancien libellé).
