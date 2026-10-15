# CS-UNLOCK — Déblocages programmés de tokens sur les X-Perps OKX Europe : pré-enregistrement brouillon

**Statut : `DRAFT_NOT_FROZEN` (brouillon du 2026-10-15).** Ce texte n'est pas gelé et ne porte aucun sha256. Il
n'autorise ni lecture de données, ni comptage d'événements, ni abonnement, ni campagne, ni ordre.

**Libellés.** Anciennement H3-A/H3-B/H3-C dans H1-NEXT-DECISION.md §3 ; renommées le 2026-10-15 : la mission H3 (scoring + gestion adaptative, docs H3-ARCHITECTURE.md et suivants, PR séparée) exige un générateur de candidats commun fondé sur une hypothèse économique explicite ; ces dossiers évaluent trois sources possibles, aucune n'est gelée. Ce document est la source **CS-UNLOCK** (ancienne H3-B).

**Aucune donnée de résultat n'a été lue** : ni prix, ni bougie, ni historique de ticker ou de funding, ni prix autour
d'une date de déblocage. **Aucun calendrier de déblocage n'a été analysé ni compté** (voir l'incident déclaré au §11.2).
Synthèse des trois sources candidates : [H3-CANDIDATE-SOURCES-2026-10-15.md](H3-CANDIDATE-SOURCES-2026-10-15.md).

Cette version intègre la revue du 2026-10-15 (verdict `needs_changes`). Le tableau du §13 indique où chaque point est
traité. **En l'état, CS-UNLOCK est bloqué à la porte M3** : il ne peut pas être gelé.

## 0. Préalables bloquants (avant tout gel)

| # | Préalable | Porte |
|---|---|---|
| P0.1 | **Accord écrit** d'un fournisseur de calendrier autorisant l'usage personnel de recherche et de trading **et le stockage horodaté** des instantanés. À défaut, CS-UNLOCK n'est pas lancé. | M3 |
| P0.2 | Le fournisseur expose un champ explicite qui distingue un **cliff** d'une **tranche linéaire** (§3) ; le nom exact du champ est inscrit au gel. Sans ce champ, pas de gel. | M3, M4 |
| P0.3 | Le propriétaire décide du budget de données et le déclare comme coût de recherche (Tokenomist Pro : 69 USD/mois, 828 USD/an, `tokenomist.ai/pricing`). | — |
| P0.4 | Conditions d'usage des données publiques OKX compatibles avec un enregistrement de recherche personnelle. | M3 |
| P0.5 | Comptage **E** des événements éligibles par mois, **sur métadonnées de calendrier seulement** (dates, montants, catégories ; aucun prix), autorisé par écrit par le propriétaire et couvert par P0.1. Si l'espérance de trades exécutés est sous le seuil du §9.1, CS-UNLOCK est abandonné avant de démarrer. | M2 |
| P0.6 | Vérification de l'API X-Perp en lecture seule par le propriétaire (`H1-XPERP-ACCOUNT-CHECK.md`). Nécessaire avant une exécution réelle seulement. | M1 |
| P0.7 | Passeport France nommé d'OKX Europe Markets Ltd vérifié sur un registre. | M1 |

## 1. Hypothèse

CS-UNLOCK-1 : sur un X-Perp crypto, un short ouvert à T−7 j et clos à T+2 j autour d'un **cliff** de déblocage des initiés
d'au moins 1 % du circulant rapporte plus que le même short sur un X-Perp témoin apparié (§7), aux mêmes dates.

Mécanisme : couverture puis vente par des détenteurs à coût d'acquisition faible, autour d'un choc d'offre exogène et
daté. Analogue académique : Field & Hanka (2001), *Journal of Finance* 56(2), 471-500 (fin de lock-up d'IPO). Les
paramètres de fenêtre sont tirés de cette étude et de sources de vendeurs non évaluées par les pairs (Keyrock, Kim
SSRN 6632838, Tokenomist), sans calibration sur des données consommées. La fenêtre de 9 jours est un compromis déclaré
entre mécanisme, exposition au funding et capacité (2 créneaux).

## 2. Source et point-in-time

- Un instantané du calendrier est pris chaque lundi à 00:00 UTC, **à partir du début du rodage (§8)**, archivé avec son
  sha256 (si et seulement si P0.1 l'autorise).
- Champs requis : token, date (jour UTC), montant, catégorie de bénéficiaire, type cliff ou linéaire (P0.2),
  pourcentage du circulant selon la même source, précision de la date, niveau de confiance (vesting contract,
  announced, inferred).
- Un événement est éligible seulement s'il figure, avec une date exacte, dans le dernier instantané pris au plus tard à
  T−14 j.
- Une révision postérieure à cet instantané ne retire jamais un événement : analyse en intention de traiter. Les
  révisions (reports, prolongations, cessions de gré à gré) sont rapportées. Exemples publics de révisions :
  DATA Foundation (premier déblocage reporté puis prolongé de 18 mois), zkLink (vesting reporté de 6 mois,
  theblock.co/post/322775), HYPE (tokens d'équipe cédés de gré à gré le 2026-10-06).
- Un historique reconstitué à partir des schedules actuels est biaisé (reports effacés) : il n'est jamais utilisé.

## 3. Définition de l'événement (ex ante)

- **Cliff des initiés** : premier déblocage d'une catégorie initiée (équipe, contributeurs, investisseurs ou vente
  privée, conseillers) après une période de blocage, c'est-à-dire **sans déblocage de la même catégorie dans les 90
  jours précédents** selon l'instantané, et marqué « cliff » par le champ du fournisseur (P0.2).
- Montant ≥ 1,0 % du circulant, le même jour UTC (catégories initiées seulement).
- **Exclus** : tranches linéaires ou mensuelles récurrentes (même ≥ 1 %) ; écosystème, communauté, trésorerie et airdrop
  seuls ; précision de date non exacte ; niveau « inferred » ; déblocage déclaré verrouillé, staké ou cédé de gré à gré
  dans l'instantané.
- Un événement par token par période de 60 jours (le premier).
- Les tranches linéaires ≥ 1 % sont **comptées et rapportées** à part (bras descriptif, non décisif, jamais tradé).

## 4. Univers et exclusions

- X-Perp crypto `ruleType = xperp`, `instCategory = 1`, `state = live`. Pas de pré-marché, pas d'actions.
- Au moins 60 jours depuis `listTime` (métadonnée) à T−7. Justification indépendante de H1 : 30 jours pour estimer
  ADV30 et σ30, et 30 jours de plus pour que ces fenêtres excluent le régime propre aux nouveaux contrats (funding 4 h
  ±1 %, bandes de prix, `H1-XPERP-MARKET-ACCESS.md` §2.8-2.9). Valeur non reprise de H1 (31 j) ; le recouvrement
  thématique avec H1 est déclaré.
- ADV30 médian ≥ 100 k USD (valeur échangée sur l'X-Perp de T−37 à T−8, **enregistrée par notre enregistreur**).
- Au 2026-10-15, 86 bases X-Perp crypto ont ≥ 60 jours d'historique ; 155 au 2026-12-01 ; 160 au 2027-01-01 (calcul
  sur `listTime`, `H3-B/metadata/xperp-crypto-bases-20261015.json`).

## 5. Exécution simulée (papier prospectif, aucun ordre)

- Entrée au taker à T−7 j, 00:00 UTC. Sortie au taker à T+2 j, 00:00 UTC.
- Stop d = clamp(2,5 × σ30 × √9 ; 8 % ; 20 %). Exécution au pire du stop et de l'ouverture horaire suivante, plus le
  glissement.
- Coûts de base : taker 5 pb par côté (frais vérifiés dans l'interface, `H1-XPERP-ACCOUNT-CHECK.md`) et glissement de
  10 pb par côté. Coûts pessimistes : glissement de 30 pb par côté, glissement du stop × 2 (risque de squeeze du short,
  déjà rencontré par H1).
- Funding effectivement enregistré à chaque échéance (4 h ou 8 h selon le contrat).

## 6. Taille et portefeuille

- 500 USDT convertis en USDC, levier 1×, marge isolée.
- Risque 0,5 % (2,50 USD) par trade, 1 % au total : 2 créneaux, une position par instrument.
- Notionnel = 2,50 / (d + coûts), quantité arrondie au lot inférieur. Un plan sous le minimum est sauté et compté
  (ENA, minimum 19,6 USD, est sauté à d = 20 % : 2,50 / 0,21 ≈ 11,9 USD,
  [TRADABLE-INSTRUMENT-UNIVERSE.md](TRADABLE-INSTRUMENT-UNIVERSE.md) §3).
- Créneaux pleins : l'événement est sauté et compté. En cas d'égalité d'entrée, priorité au plus grand pourcentage du
  circulant.

## 7. Contrôle apparié (avec l'âge)

- Pour chaque événement, un témoin X-Perp de l'univers sans déblocage d'initiés ≥ 0,25 % du circulant entre T−45 et
  T+45.
- **Appariement sur trois variables** : âge X-Perp (jours depuis `listTime`), ADV30 et σ30 ; plus proche voisin sur les
  rangs, l'âge en premier critère (écart d'âge ≤ 30 jours, sinon le témoin le plus proche en âge). Si le fournisseur
  publie une date de TGE, le temps depuis le TGE est rapporté pour l'événement et le témoin.
- Raison : les tokens à déblocage sont jeunes, et H1 S_B a trouvé une dérive négative après cotation. Sans appariement
  sur l'âge, l'écart événement − témoin pourrait reprendre la dérive de H1 au lieu d'un effet de déblocage.
- Mêmes horodatages, même règle, non exécuté. Rapporté en complément : rendement ajusté du bêta BTC sur 30 jours, et
  écart d'âge moyen événement − témoin.

## 8. Calendrier prospectif et rodage

- **Rodage d'au moins 45 jours** avant l'ouverture de la fenêtre de test : l'enregistreur (bougies 1 h, valeur
  échangée, funding publiés après son démarrage) et les instantanés hebdomadaires du calendrier commencent au moins 45
  jours avant. Raison : ADV30 et σ30 demandent les données de T−37 à T−8, et l'instantané d'éligibilité doit dater de
  T−14 au plus tard. Sans rodage, aucun événement ne serait éligible pendant environ 6 semaines et la fenêtre utile
  tomberait à ≈ 10,5 mois.
- Pendant le rodage, aucun trade n'est simulé et aucun rendement n'est calculé ; seule la complétude est vérifiée.
- Fenêtre de test : du 1er du mois suivant la fin du rodage, pour une durée fixée par le propriétaire au gel (OD-B2 :
  12 ou 24 mois), jamais prolongée.
- Aucun suivi intermédiaire de la performance. Aucune archive consommée ni aucune fenêtre `fwd-okx-r1` n'est utilisée.

## 9. Taille d'échantillon et puissance (déclarées avant toute donnée)

### 9.1 Effet apparié, pas un échantillon simple

Le test porte sur la différence appariée événement − témoin. Sa variance est 2σ²(1 − ρ), où ρ est la corrélation entre
les rendements de l'événement et du témoin (facteur de marché commun). ρ est INCONNU. L'effet standardisé sur la
différence vaut d_pair = d / √(2(1 − ρ)).

Nombre de trades requis (unilatéral, α = 0,05, puissance 0,8) : n = ((1,645 + 0,842) / d_pair)² = 6,19 / d_pair².

| Effet simple d (hypothèse de conception) | ρ = 0,5 | ρ = 0 |
|---|---:|---:|
| 0,4 | 39 | 78 |
| 0,3 | 69 | 138 |

Effet minimal détectable sur la différence : d_pair = 0,39 à 40 trades, 0,28 à 81 trades.

**d = 0,4 n'a aucune source.** La seule mesure publique proche citée au dossier (rank IC hebdomadaire −0,019, backtest
Medium/Coinmonks lu par résumé, que son auteur juge insuffisant pour trader) suggère un effet beaucoup plus petit. Les
deux grandeurs ne sont pas directement convertibles ; aucun chiffre n'en est tiré.

### 9.2 Capacité et regroupement

- Plafond de capacité : 2 créneaux × 365 / 9 ≈ 81 trades par an (≈ 43 avec une détention de 17 jours).
- Le modèle d'arrivées de Poisson avec perte d'Erlang à 2 serveurs (exécutabilité 0,95) donnait ≈ 40 trades par an
  pour E = 5 événements éligibles par mois (charge offerte 1,48 Erlang, ≈ 31 % bloqués). **Ce modèle ignore le
  regroupement** : les déblocages se concentrent en début de mois et à dates anniversaires, donc davantage
  d'événements seraient bloqués. Les chiffres du modèle sont des **bornes hautes**.
- Le comptage P0.5 doit rapporter E et la distribution des événements par semaine ISO ; l'espérance de trades est
  alors recalculée en simulant les 2 créneaux sur les dates du calendrier (métadonnées seulement).

### 9.3 Conséquence

Même avec E ≥ 5, un résultat `INCONCLUSIVE` est **probable** sur 12 mois. Le propriétaire choisit avant le gel :

- OD-B1 : l'effet minimal qu'il accepte de pouvoir détecter (d_pair) ;
- OD-B2 : la durée (12 ou 24 mois) ;
- règle d'abandon : si l'espérance de trades exécutés (P0.5) est inférieure au n requis pour l'effet choisi en OD-B1,
  CS-UNLOCK n'est pas lancé.

## 10. Critères (tous requis pour PASS, évalués une seule fois à la fin)

| # | Critère |
|---|---|
| C0 | n ≥ max(40, n requis par OD-B1) trades exécutés ; sinon `CS_UNLOCK_INCONCLUSIVE` (clos sans réussite) |
| C1 | écart moyen apparié événement − témoin > 0, p unilatéral ≤ 0,05 par bootstrap groupé par semaine ISO de T (10 000 tirages, graine figée au gel) |
| C2 | R net moyen > 0 en coûts de base et en coûts pessimistes ; rendement absolu net du short > 0 |
| C3 | PF ≥ 1,1 en base, ≥ 1,0 en pessimiste ; PnL du portefeuille > 0 ; drawdown maximal ≤ 15 % du capital ; pire trade ≥ −3 R |
| C4 | toujours > 0 sans les 5 % meilleurs trades ; positif sur au moins 2 des 3 tiers chronologiques de la fenêtre |
| C5 | écart événement − témoin > 0 aussi dans la moitié des événements dont le token est le plus ancien (âge X-Perp au-dessus de la médiane) |

Rapportés aussi : événements sautés (minimum, créneaux, liquidité), révisions de calendrier, tranches linéaires,
funding payé, écart d'âge événement − témoin.

## 11. Déclarations

11.1 Thème proche de H1 (offre de tokens) ; dérive post-cotation de H1 S_B neutralisée par l'appariement sur l'âge
(§7) et le critère C5. Paramètres tirés de sources de vendeurs non évaluées par les pairs. Le coût des données
(828 USD/an au minimum) dépasse le capital de 500 USDT : c'est un coût de recherche pur, que le portefeuille de
référence ne peut pas amortir (828 / (2,50 × 40) = 8,3 R par trade à 40 trades ; 4,1 R à 81 trades ; arithmétique, pas
une prévision).

11.2 **Incident de collecte (2026-10-15).** En sondant DefiLlama, l'hôte public non officiel
`defillama-datasets.llama.fi/emissionsIndex` a répondu sans clé et 22,4 Mo ont été téléchargés. C'est une donnée de
calendrier, pas une donnée de résultat. Elle n'a pas été analysée (seuls les 300 premiers octets se sont affichés :
une ligne BTC sans événement) et le fichier a été supprimé. Cet accès contrevient aux conditions de DefiLlama
(defillama.com/terms, cl. 8, point 9 : pas d'accès programmatique hors API publique officielle). Une recherche du
2026-10-15 dans le répertoire du dossier et dans `/tmp` (profondeur 4) n'a trouvé aucune copie restante ; cette
recherche n'est pas exhaustive pour toute la machine. Une seconde recherche, le 2026-10-15T21:12Z (répertoire du
dossier et `/tmp`, profondeur 7, noms `*emission*` / `*llama*` et fichiers de 20 à 25 Mo), n'a trouvé aucune copie du
jeu de données ; elle n'a trouvé qu'un fichier texte de 351 Ko de **documentation** DefiLlama (méthodologie) dans un
répertoire temporaire de session, qui n'est pas le jeu de données. Aucune donnée DefiLlama ne sera utilisée par
CS-UNLOCK.

11.3 E (événements par mois) est INCONNU au moment de la rédaction.

## 12. Règles d'arrêt

- Un FAIL ou un INCONCLUSIVE clôt CS-UNLOCK. Aucun paramètre n'est modifié et aucune donnée n'est relue.
- Une réussite n'autorise qu'une discussion Paper/démo, jamais de trading réel ; les drapeaux restent à 0.

## 13. Portes et traçabilité de la revue

| Porte | Statut au 2026-10-15 |
|---|---|
| M1 | CONDITIONAL : X-Perps, short, marge isolée et frais 2/5 pb vérifiés dans l'interface ; API et passeport nommé non vérifiés |
| M2 | CONDITIONAL : couverture des tokens à déblocage parmi les X-Perps liquides INCONNUE ; pas de chemin X-Perp dans TradingV3 ([H1-XPERP-TECHNICAL-GAP.md](H1-XPERP-TECHNICAL-GAP.md)) |
| M3 | **FAIL en l'état** ; CONDITIONAL à P0.1, P0.2, P0.4 |
| M4 | CONDITIONAL : mécanisme distinct (aucun événement de calendrier dans le [rapport de paysage](../reports/research-strategy-landscape-2026-10.md)) ; non gelé |
| M5 | UNKNOWN |

| Point de la revue | Traitement |
|---|---|
| M3 échoue (licence, stockage, coût > capital) | §0 P0.1, P0.3 ; §13 |
| Incident DefiLlama absent des déclarations ; copie restante ? | §11.2 |
| Puissance optimiste (d = 0,4 sans source ; formule à un échantillon pour un test apparié) | §9.1, §9.3, C0 |
| E inconnu ; modèle d'Erlang sans regroupement | §9.2, P0.5 |
| Ambiguïté cliff / tranche ; champ source non nommé | §3, P0.2 |
| Démarrage incompatible avec l'interdiction de lecture rétrospective | §8 (rodage ≥ 45 jours) |
| Sauvetage caché de H1 par des tokens jeunes | §7 (appariement sur l'âge), C5, §11.1 |
| Queue de squeeze du short ; ENA sous le minimum à 20 % | §5 (glissement du stop × 2), §6 |
| Licence des données publiques OKX | P0.4 |
| (Renommage H3-B → CS-UNLOCK, mission H3) | En-tête, §14 |

## 14. Branchement sur le générateur commun H3 (indicatif, non décisif)

Le contrat de candidat proposé par la mission H3 (`h3.opportunity_candidate/1`, module
`python-orchestrator/scripts/research_h3/contracts.py` de la branche `claude/h3-opportunity-architecture`, **en revue
dans une PR séparée, susceptible de changer**) demande : un instrument, un côté, un stop de prix protecteur
obligatoire, une durée maximale de détention, des horodatages `observed ≤ available ≤ decision`, et les limites H3
(0,5 % par trade, 1 % au total, levier ≤ 1×).

CS-UNLOCK s'y range sans adaptation :

| Champ du contrat | Valeur CS-UNLOCK |
|---|---|
| instrument, côté | X-Perp crypto de l'événement, `short` |
| moment de décision | T−7 j, 00:00 UTC |
| prix de référence, stop | prix d'entrée simulé ; stop = entrée × (1 + d), d du §5 |
| durée maximale | 9 jours (sortie à T+2 j) |
| hypothèse | identifiant proposé `cs-unlock.insider-cliff`, version liée au gel |
| variables disponibles pour un score | % du circulant, catégorie, niveau de confiance, âge X-Perp, ADV30, σ30, funding ; toutes antérieures à la décision, le calendrier venant de l'instantané ≤ T−14 j |

Limite : à au plus ≈ 40 trades par an (§9.2, borne haute), il n'y a presque pas de marge pour apprendre un score ou
une règle de gestion adaptative sans diviser un échantillon déjà sous-dimensionné. Le contrôle apparié (§7) reste celui
de ce protocole, quelle que soit la variante H3 évaluée.

Sources : `eea.okx.com/api/v5/public/instruments` (2026-10-15T19:43:47Z) ; docs.tokenomist.ai/api-documents ;
tokenomist.ai/pricing ; tokenomist.ai/terms-api (cl. 7.3(c)) ; docs.tokenomist.ai/updates ; docs.llama.fi/pro-api ;
defillama.com/terms ; cryptorank.io/public-api/pricing ; docs.messari.io/api-reference/permissions.md ;
[RESEARCH-ELIGIBILITY-GATES.md](RESEARCH-ELIGIBILITY-GATES.md). Notes, sources et `SHA256SUMS` :
`tradingV3-private/research/20261015T2200Z-h3-dossiers/H3-B/` (le répertoire garde l'ancien libellé ; les chemins `H3-B/…` cités plus haut y renvoient).
