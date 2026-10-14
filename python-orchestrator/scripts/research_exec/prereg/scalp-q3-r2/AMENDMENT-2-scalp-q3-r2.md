# Amendement 2 — scalp-q3-r2 : contrôle comparable, sorties passives partielles, gate et validation (2026-10-14)

Rédigé le 2026-10-14 entre 14:00Z et 14:15Z, sur la mission corrective du propriétaire (« correction
méthodologique et validation indépendante »).

**Il est écrit APRÈS la lecture des résultats d'entraînement** :
- PR #498 ;
- `run/results.json` (verdict `RESEARCH_CANDIDATE`, 13:38Z) ;
- le diagnostic A1 (`c2/diag-A1.json`, 14:05Z), qui relit les mêmes neuf fenêtres d'entraînement.

Il ne prétend pas être antérieur à ces résultats. Le pré-enregistrement (sha256 `8454ba53…`), l'amendement 1
(`e2a8ea06…`) et toutes les analyses déjà produites sont conservés tels quels.

**Aucune donnée de validation n'a été consultée.** Novembre 2024 n'est ni téléchargé ni lu : aucune archive
aggTrades ou bookDepth de novembre 2024 n'existe sur la machine (vérifié par les noms de fichiers à 14:08Z). Le
funding et les klines de novembre 2024 présents pour d'autres campagnes ne sont pas utilisés.

## 1. Défauts constatés

### D1 — Le contrôle de portefeuille exécute environ deux fois plus de trades que le candidat

Mesures sur l'entraînement, pour C2-SL et C4-SL :

| Mesure | C2-SL candidat | Contrôle à décalage d'épisodes | C4-SL candidat | Contrôle |
|---|---|---|---|---|
| Secondes de signal | 35 923 | 35 877 | 12 522 | 12 510 |
| Épisodes | 4 178 | 4 166 | 1 603 | 1 599 |
| Épisodes commençant alors que la paire est occupée | 2 679 (64 %) | 1 290 (31 %) | 974 (61 %) | 378 (24 %) |
| Ordres, une position par paire | 1 534 | 2 934 | 636 | 1 239 |
| Trades au portefeuille | 549 | 1 097 | 308 | 636 |
| Exposition notionnelle moyenne | 54,5 USDT | 86,8 USDT | 30,8 USDT | 53,0 USDT |
| Positions ouvertes en moyenne | 0,49 | 0,98 | 0,28 | 0,56 |
| Frais | 27,7 USDT | 44,1 USDT | 15,5 USDT | 27,2 USDT |

**Cause.** Les épisodes du candidat sont groupés : 70 % des épisodes consécutifs d'une même paire commencent moins
de 3 900 s après la fin du précédent. Un ordre ou une position ouverte dans le premier épisode rend donc les
suivants inutilisables.

Le décalage d'épisodes déplace chaque épisode indépendamment. Il casse ce groupement, ainsi que la synchronie entre
paires, et double le nombre d'occasions libres. Le surplus en USDT et la p-value du portefeuille comparent alors des
portefeuilles d'exposition différente. La comparaison par trade (bp) n'est pas faussée par ce défaut, mais son
contrôle n'a pas non plus la même structure.

### D2 — Des sorties passives sont comptées comme remplies alors que le volume traversant ne suffit pas

Pour C2-SL, 6 sorties passives sur 441 au portefeuille ont un volume `through` (transactions au-delà du prix pendant
le TTL) inférieur à la quantité à sortir. Leur PnL comptait une sortie maker intégrale.

## 2. Ancienne et nouvelle définition

### Contrôle principal

**Avant** : chaque épisode est déplacé seul, à la même heure UTC, dans la même série (§7 du pré-enregistrement).

**Après (contrôle à décalage de grappes globales)** :

- **Grappes.** Dans une fenêtre, les épisodes de toutes les paires sont réunis en grappes globales : deux épisodes,
  de la même paire ou non, séparés de moins de G = 3 932 s appartiennent à la même grappe.
- **Choix de G.** G = TTL 30 s + fallback 3 900 s + latence 0,25 s + 1 s d'arrondi de l'occupation. Aucun ordre ni
  aucune position ne vit plus longtemps. Deux grappes distinctes ne peuvent donc pas interagir, ni par la règle
  d'une position par paire, ni par les deux emplacements du portefeuille.
- **Déplacement.** Chaque grappe est déplacée d'un bloc. Toutes ses paires et tous ses écarts internes sont
  conservés. Le nouveau départ est tiré uniformément parmi les secondes de la fenêtre qui ont la même heure UTC que
  le départ d'origine. Si un tel départ garde le bloc entier dans la fenêtre, on le choisit ; sinon le bloc
  s'enroule sur la fenêtre.
- **Ce qui est conservé** : fréquence, persistance, groupement par paire, synchronie entre paires et profil horaire.
  Seul l'alignement avec l'état du marché est perdu.
- **Exécution.** Même règle d'exécution que le candidat : E1, Z3-SL200, même portefeuille et mêmes coûts.
- **Aucune pondération ni sélection** des témoins selon leur PnL ; aucune information future n'entre dans le
  placement.
- **Graines.** 1 000 graines, `rng = default_rng([20261014, 3, indice de configuration, graine, indice de fenêtre])`.
  Elles sont utilisées aux deux niveaux : signal (une position par paire) et portefeuille (base, et pessimiste pour
  ce contrôle).

**Référence secondaire** : le décalage d'épisodes du protocole initial, mêmes graines qu'avant, au niveau signal et
au niveau portefeuille (base).

**Contrôle informatif** : l'état de bande sans flux (définition du §7 inchangée), publié séparément au niveau signal
et au niveau portefeuille. Il garde le seul rôle qu'il avait déjà, le critère T4 de l'entraînement. Il ne devient
pas un critère de validation.

### Comparabilité

Tolérances figées ici, avant le recalcul. Pour chaque candidat, contre le contrôle principal :

| Mesure | Tolérance |
|---|---|
| Trades par graine, niveau signal | ± 15 % de ceux du candidat |
| Trades exécutés au portefeuille | ± 15 % |
| Temps de position cumulé | ± 20 % |
| Exposition notionnelle intégrée dans le temps | ± 20 % |

Un candidat dont le contrôle sort de ces bornes ne peut pas être jugé contre lui ; il prend le statut
`STATISTICAL_BLOCKER`. Les écarts résiduels sont publiés dans tous les cas.

### Sorties passives (B2)

Une sortie passive Z3 (ordre au ask à `fill + 3 600 s + 250 ms`, TTL 300 s) ne remplit au plus que le volume qui a
traversé son prix pendant le TTL, l'ordre étant supposé dernier de la file. Au portefeuille, la quantité remplie
est arrondie à l'inférieur au pas de l'instrument.

Le reliquat suit Z3 :
- il sort au marché au bid live à `fill + 3 900 s + 250 ms`, avec frais taker et le slippage du scénario ;
- pour Z3-SL200, il reste sous le stop −200 bp depuis le premier remplissage passif jusqu'à l'instant du fallback.
  Si le stop se déclenche, le reliquat sort au stop, et le volume traversant ne compte que jusqu'au déclenchement.

Règles de comptabilité :
- frais maker sur la part passive, taker sur le reliquat ;
- funding de chaque part jusqu'à sa propre sortie ;
- la position et l'occupation de la paire durent jusqu'à la sortie du reliquat ;
- la quantité initiale, le stop et le reliquat restent cohérents (testé).

Au niveau signal, qui n'a pas de quantité, on utilise une quantité de référence de 110 USDT / entrée, soit le
notionnel dimensionné à 500 USDT. Les tables de résultats d'ordre sont recalculées avec ces colonnes de reliquat ;
les colonnes existantes doivent rester identiques au bit près (contrôle de déterminisme).

## 3. Rejeu correctif de l'entraînement

- **Données** : les mêmes neuf fenêtres de 2025, 20 paires, mêmes données, variantes et paramètres. Signal, seuils,
  stop, E1, latence, Z3, univers, capital, limites de risque et règles de sélection sont inchangés.
- **Configurations rejouées** : seulement C2-SL et C4-SL.
- **Code** : branche `claude/research-scalp-q3-r2-c2`, module `q3r2_c2.py`, colonnes de reliquat dans
  `q3r2_engine.py`, option `partial_exit` dans `q3r2_portfolio.py`.
- **Sortie** : `run-c2/results-c2.json`. Les résultats de PR #498 restent publiés.

## 4. Gate vers novembre (règles exactes)

Pour C2-SL et C4-SL, on applique les critères T1–T11 figés au §8 du pré-enregistrement, avec le moteur et le
contrôle corrigés :
- T3 : contrôle principal corrigé au niveau signal, p ≤ 0,01 et borne basse de l'IC 90 % (blocs de jours) > 0 ;
- T4 : contrôle d'état de bande, inchangé ;
- T5 : PnL > 0, au moins 150 trades exécutés, p du portefeuille contre le contrôle principal corrigé ≤ 0,05 ;
- les autres critères sont inchangés.

On y ajoute les tolérances de comparabilité du §2.

Issue par candidat :
- tous les critères et la comparabilité passent : **éligible** ;
- un critère échoue : **retiré** de la phase 2 ;
- les critères passent mais pas la comparabilité : **`STATISTICAL_BLOCKER`**.

Décision :
- au moins un candidat éligible : `GATE_PASS` ;
- sinon, au moins un `STATISTICAL_BLOCKER` : `STATISTICAL_BLOCKER` ;
- sinon : `NO_GO_AFTER_CORRECTION`.

Aucun seuil n'est assoupli.

## 5. Validation unique sur novembre 2024 (seulement si `GATE_PASS`)

**Données.** Binance USD-M, les 20 paires, aggTrades et bookDepth des quatre fenêtres pré-enregistrées
(2024-11-04..08, 11..15, 18..22, 25..29), plus le funding de 2024-11. Rien d'autre.

**Acquisition.** Elle a lieu après le gel et le commit de cet amendement, et après un `GATE_PASS`. Elle se fait dans
un dossier dédié, avec sha256 vérifiés contre les `.CHECKSUM` publiés. Un fichier manquant ou un échec de sha256
donne `INVALID_DATA`, sans substitution.

**Lecture unique.** Un `validation-claim.json` enregistre le sha256 de cet amendement et celui du gate. Il est écrit
une fois, et le code refuse toute seconde analyse.

**Configurations.** Seulement les candidats éligibles au gate (C2-SL et/ou C4-SL). Ni S7, ni autre stop, ni nouveau
seuil.

**Critères**, tous requis, par candidat ; ce sont ceux du §9 du pré-enregistrement, avec le contrôle principal
corrigé :

| # | Critère |
|---|---|
| V1 | au moins 150 trades, compte du niveau signal comme au §9 et à T1 ; le nombre de trades exécutés au portefeuille est publié à côté |
| V2 | gain net moyen > 0 |
| V3 | PF ≥ 1,05 |
| V4 | surplus > 0 sur le contrôle principal corrigé (niveau signal, 1 000 graines) |
| V5 | p ajusté par Holm sur les candidats transmis ≤ 0,05 |
| V6 | borne basse de l'IC 90 % du surplus (blocs de jours, 20 jours, toutes paires d'un jour ensemble) > 0 |
| V7 | au moins 50 % des paires avec trades positives, et au moins 10 paires avec trades |
| V8 | gain net moyen > 0 en scénario pessimiste |
| V9 | moyenne tronquée 5 % / 5 % > 0 |
| V10 | portefeuille 500 USDT : PnL > 0 |
| V11 | drawdown max (equity marquée à la minute) ≤ 10 % du capital initial (50 USDT) |
| V12 | pire trade ≥ −2 × son risque nominal |

**Verdict.**
- Il est rendu par candidat.
- `VALIDATION_PASS` si au moins un candidat transmis passe les douze critères ; seuls ceux-là continuent.
- `VALIDATION_FAIL` sinon.
- `INVALID_DATA` si les données sont incomplètes.
- Un échec est définitif pour cette version et ces paramètres.

**Statut du protocole.** Le contrôle principal ayant changé de définition, un `VALIDATION_PASS` serait une
validation **sous protocole amendé**, et non la confirmation inchangée du protocole initial. Les résultats contre
l'ancien contrôle (décalage d'épisodes) sont publiés à côté.

## 6. Ce qui ne change pas

- Le pré-enregistrement et l'amendement 1.
- Les résultats et le verdict de la PR #498.
- `fwd-okx-r1` : règle, données, services, W0/W1/W2.
- Les signaux Q3 et Q3L et leurs seuils (EMA900, −1,5 σ, `fi_60 > 0`, `di_1 > 0,1`), le stop −200 bp, E1, la
  latence, Z3, l'univers de 20 paires, le capital de 500 USDT et les limites de risque.

Aucun filtre n'est ajouté sur les paires ni sur les mois.
