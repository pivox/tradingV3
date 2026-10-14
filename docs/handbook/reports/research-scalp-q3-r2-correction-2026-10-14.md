# Scalping Q3 V2 (scalp-q3-r2) — correction méthodologique et gate, 2026-10-14

Ce rapport fait suite à la [phase d'entraînement](research-scalp-q3-r2-training-2026-10-14.md) (PR #498). Il
corrige deux faiblesses de cette PR et décide si la validation sur novembre 2024 peut être ouverte. Les résultats
de #498 ne sont pas effacés.

**Décision du gate : `GATE_PASS` pour C4-SL seul. C2-SL prend le statut `STATISTICAL_BLOCKER`** : son contrôle
corrigé reste hors des tolérances de comparabilité.

Le rapport de [validation de novembre 2024](research-scalp-q3-r2-validation-2026-11-2024.md) suit.

## 1. Cadre et traçabilité

| Élément | Valeur |
|---|---|
| Amendement 2 | `AMENDMENT-2-scalp-q3-r2.md`, gelé le 2026-10-14 à 14:11:01Z, sha256 `3d061fb478416b2054e2f1366f9ee8b02e2eea138efbcbcc92a85f7933eb2b49`, commit `2d987d15`. Écrit **après** lecture des résultats d'entraînement et du diagnostic A1, ce qu'il déclare. Aucune donnée de validation consultée (aucune archive tick ou profondeur de novembre 2024 n'existait sur la machine) |
| Pré-enregistrement, amendement 1 | inchangés (`8454ba53…`, `e2a8ea06…`) |
| Branche | `claude/research-scalp-q3-r2-c2`, partie de `3224ba6c` (tête de #498) |
| Code | `q3r2_c2.py` (contrôle par grappes, vue corrigée, gate, claim, validation) ; colonnes de reliquat dans `q3r2_engine.py` ; `partial_exit` dans `q3r2_portfolio.py` ; diagnostics `q3r2_diag.py` (A1) et `q3r2_diag_exits.py` (B1/B3) |
| Rejeu | les neuf fenêtres d'entraînement de 2025 seulement, mêmes données et paramètres. Préparation de 14:11 à 15:31Z, analyse de 15:31 à 15:44Z (1 000 graines). Sorties : `run-c2/results-c2.json` (sha256 `db5c38dc…`), `c2/GATE.json` (sha256 `f8247013…`) |
| Tests | 94 tests `research_exec` au vert : les 86 précédents et 8 nouveaux (reliquats, grappes, Holm, comparabilité, claim unique, garde de novembre) |
| Déterminisme | 0 colonne différente entre les anciennes et les nouvelles tables de résultats, sur 180 séries. L'ancienne comptabilité appliquée aux nouvelles tables redonne exactement les PnL de #498 (+21,68 / +6,59 / +6,09 et +26,74 / +17,11 / +10,03) |
| Inchangés | signaux Q3 et Q3L, seuils, stop −200 bp, E1, latence, Z3, 20 paires, 500 USDT, limites de risque, `fwd-okx-r1` (code, services, données) |

## 2. Phase A — le contrôle de portefeuille

### A1. Diagnostic

| Mesure | C2-SL | Décalage d'épisodes (ancien) | C4-SL | Décalage d'épisodes (ancien) |
|---|---|---|---|---|
| Épisodes de signal | 4 178 | 4 166 | 1 603 | 1 599 |
| Épisodes qui commencent pendant que la paire est occupée | **64 %** | **31 %** | **61 %** | **24 %** |
| Ordres (une position par paire) | 1 534 | 2 934 | 636 | 1 239 |
| Trades au portefeuille | 549 | 1 097 | 308 | 636 |
| Ordres au portefeuille | 726 | 1 542 | 381 | 851 |
| Positions ouvertes en moyenne | 0,49 | 0,98 | 0,28 | 0,56 |
| Exposition notionnelle moyenne | 54,5 USDT | 86,8 USDT | 30,8 USDT | 53,0 USDT |
| Durée moyenne d'une position | 3 505 s | 3 490 s | 3 484 s | 3 453 s |
| Frais | 27,7 USDT | 44,1 USDT | 15,5 USDT | 27,2 USDT |
| PnL moyen du contrôle (par trade) | — | −167,7 USDT (−0,153) | — | −115,2 USDT (−0,181) |

**Pourquoi le décalage d'épisodes exécute deux fois plus.** Les épisodes du candidat sont groupés : 70 % des
épisodes consécutifs d'une même paire commencent moins de 3 900 s après la fin du précédent, et la durée de vie
d'une position est d'environ 3 900 s. Deux épisodes sur trois tombent donc pendant une position déjà ouverte.

Déplacer chaque épisode indépendamment disperse ces épisodes : deux fois plus tombent sur une paire libre. La
synchronie entre paires est cassée aussi. Les témoins sont en outre plus souvent en position et paient plus de
frais. Leur PnL total plus bas mesure donc en partie leur exposition, pas le signal.

### A2. Contrôle corrigé (figé dans l'amendement 2 avant le recalcul)

**Décalage de grappes globales.** Les épisodes de toutes les paires d'une fenêtre sont regroupés dès qu'ils sont à
moins de 3 932 s les uns des autres. 3 932 s est la vie maximale d'un ordre et d'une position ; au-delà, deux
grappes ne peuvent pas interagir. Chaque grappe est ensuite déplacée d'un bloc :

- toutes ses paires et ses écarts internes sont conservés ;
- le nouveau départ est tiré parmi les secondes de même heure UTC ;
- 1 000 graines, avec les mêmes règles d'exécution, le même capital, les mêmes coûts et la même latence que le
  candidat ;
- aucune pondération ni sélection selon le PnL, et aucune information future.

Tolérances fixées d'avance : trades à ±15 % (niveau signal et portefeuille), temps de position et exposition
notionnelle à ±20 %.

### A3. Les trois comparateurs (entraînement, Z3-SL200)

Niveau signal, en bp par trade, une position par paire. IC 90 % par blocs de jours, toutes paires d'un jour
ensemble ; 45 jours, mais seulement 9 fenêtres de régime.

| Comparateur | C2-SL : moyenne témoin / trades par graine (écart) | Surplus [IC 90 %] | p | C4-SL : moyenne témoin / trades (écart) | Surplus [IC 90 %] | p |
|---|---|---|---|---|---|---|
| **Principal : grappes** | −17,7 bp / 1 085 (−5,5 %) | **+27,5 [+17,4 ; +37,5]** | 0,001 | −17,5 bp / 490 (−5,3 %) | **+30,5 [+16,8 ; +43,9]** | 0,001 |
| Secondaire : épisodes (ancien) | −19,1 bp / 2 073 (+81 %) | +28,8 [+19,1 ; +38,5] | 0,001 | −19,5 bp / 920 (+78 %) | +32,5 [+19,2 ; +45,1] | 0,001 |
| Informatif : bande sans flux | +4,7 bp / 992 (−14 %) | +5,1 [+1,3 ; +8,9] | 0,007 | +7,5 bp / 472 (−9 %) | +5,5 [−0,5 ; +11,1] | 0,047 |

Le candidat lui-même : C2-SL fait +9,78 bp sur 1 148 trades (PF 1,28) ; C4-SL fait +13,03 bp sur 517 trades
(PF 1,36).

**Portefeuille 500 USDT, coûts de base, 1 000 graines :**

| | C2-SL | Grappes | Épisodes | Bande sans flux | C4-SL | Grappes | Épisodes | Bande sans flux |
|---|---|---|---|---|---|---|---|---|
| Trades | 546 | 460 | 1 095 | 495 | 308 | 271 | 638 | 285 |
| Ordres | 724 | 657 | 1 541 | 658 | 381 | 367 | 852 | 351 |
| Positions moyennes | 0,49 | 0,42 | 0,98 | 0,44 | 0,28 | 0,24 | 0,57 | 0,25 |
| Exposition moyenne (USDT) | 54,2 | 41,3 | 87,2 | 48,2 | 30,8 | 24,7 | 52,9 | 27,5 |
| PnL net (USDT) | **+21,55** | −75,81 | −165,55 | −3,20 | **+26,74** | −48,10 | −117,64 | +8,74 |
| Frais (USDT) | 27,72 | 20,70 | 44,35 | 24,55 | 15,50 | 12,43 | 27,20 | 14,20 |
| PF | 1,10 | 0,64 | 0,65 | 0,98 | 1,21 | 0,63 | 0,60 | 1,07 |
| DD max (USDT) | 24,8 | 84,8 | 169,7 | 30,6 | 24,3 | 53,5 | 120,3 | 21,1 |
| Surplus du candidat (moyenne) | — | +97,4 | +187,1 | +24,8 | — | +74,8 | +144,4 | +18,0 |
| IC 90 % du surplus (blocs de jours) | — | [+49,0 ; +145,7] | [+138,5 ; +236,7] | [+0,6 ; +49,3] | — | [+38,6 ; +113,0] | [+104,9 ; +185,8] | [−1,7 ; +38,0] |
| Surplus P5 / P50 / P95 entre graines | — | +61 / +98 / +132 | +158 / +187 / +216 | +1 / +25 / +48 | — | +48 / +75 / +102 | +116 / +144 / +172 | +1 / +18 / +35 |
| p empirique | — | 0,001 | 0,001 | 0,047 | — | 0,001 | 0,001 | 0,045 |
| Écart trades / temps / exposition | — | −15,7 / −15,6 / **−23,8 %** | +101 / +100 / +61 % | −9 / −10 / −11 % | — | −12,0 / −11,9 / −19,7 % | +107 / +105 / +72 % | −8 / −9 / −11 % |

En scénario pessimiste, le contrôle par grappes perd −85,2 USDT pour C2 (surplus +95,4) et −53,7 USDT pour C4
(surplus +70,8). Le p est de 0,001 dans les deux cas.

**Lecture.**

- **Contrôle principal.** Il réduit l'écart de fréquence de +100 % à environ −12/−16 %. L'écart restant est dans
  l'autre sens : les grappes déplacées se chevauchent parfois et tombent sur des moments où le remplissage passif
  diffère. Contre ce contrôle, le surplus du portefeuille est **à peu près divisé par deux** (C2 : +187 → +97 USDT ;
  C4 : +144 → +75 USDT), mais il reste très positif sur toutes les graines.
- **Bande sans flux.** Contre des entrées après une déviation de prix comparable, sans flux acheteur, la valeur
  ajoutée de Q3 est **petite** :
  - +5 bp par trade au niveau signal ;
  - +18 à +25 USDT au portefeuille, avec une borne basse de l'IC à environ 0 ;
  - le témoin « bande sans flux » est lui-même proche de zéro ou positif.

  Q3 bat nettement le hasard, mais n'apporte que peu par rapport à une simple entrée sur déviation. Ce contrôle est
  publié à titre d'information. Il n'est critère que via T4, déjà figé à l'entraînement.
- **Rareté des régimes indépendants.** 45 jours dans 9 fenêtres de 5 jours. Les IC par blocs de fenêtres sont
  donnés dans `results-c2.json`. Février–mai et juin–octobre se comportent différemment (voir le rapport
  d'entraînement).

## 3. Phase B — sorties passives à volume insuffisant

### B1. Les événements

Six événements touchent C2-SL au portefeuille (base). Ils sont identiques dans #498 et dans le rejeu.

| Paire | Entrée → sortie passive (UTC) | Quantité | Volume traversant | Reliquat | Prix passif / reliquat (taker à +3 900 s) | PnL compté (#498) | PnL conservateur |
|---|---|---|---|---|---|---|---|
| INJ | 2025-02-11 02:32 → 03:32 (reliquat 03:37) | 7,5 | 4,1 | 3,4 | 14,742 / 14,680 | +0,833 | +0,608 |
| ATOM | 2025-05-15 21:25 → 22:25 (22:30) | 23,42 | 4,18 | 19,24 | 4,895 / 4,889 | +2,059 | +1,919 |
| LINK | 2025-06-16 21:37 → 22:37 (22:42) | 8,14 | 4,51 | 3,63 | 13,992 / 13,826 | −0,281 | −0,899 |
| OP | 2025-08-11 09:51 → 10:51 (10:56) | 147,1 | 61,6 | 85,5 | 0,7907 / 0,7835 | +0,511 | −0,123 |
| OP | 2025-08-13 05:24 → 06:25 (06:29) | 145,5 | 37,3 | 108,2 | 0,7816 / 0,7787 | −1,236 | −1,576 |
| OP | 2025-08-15 12:20 → 13:21 (13:25) | 154,1 | 50,4 | 103,7 | 0,7552 / 0,7520 | +1,248 | +0,893 |

Effet direct : **−2,31 USDT** sur ces six trades (+3,13 → +0,82).

Autres configurations, au portefeuille de base :
- C1-SL : 4 événements ;
- C3-SL : aucun ;
- **C4-SL : aucun.**

En stress (latence 1 s), on compte 6 événements pour C2-SL, 6 pour C1-SL, 2 pour C4-SL et 1 pour C3-SL.

La cohérence entre quantité initiale, part passive, reliquat et stop est vérifiée par les tests : quantités,
frais par jambe, stop du reliquat.

### B3. Impact (portefeuille, avant → après)

| Config. | Base | Pessimiste | Latence 1 s |
|---|---|---|---|
| C2-SL | +21,68 → **+21,55** | +6,59 → **+10,19** | +6,09 → **−0,72** |
| C4-SL | +26,74 → +26,74 | +17,11 → +17,11 | +10,03 → **+9,70** |
| C1-SL (réf.) | +3,16 → +2,86 | −18,64 → −18,65 | −17,97 → −18,05 |
| C3-SL (réf.) | +8,39 → +8,39 | −0,91 → −0,91 | −6,15 → −5,83 |

**Observation importante : la dépendance au chemin.** Un reliquat prolonge une position de 5 minutes. Cela change
quels signaux suivants trouvent un emplacement libre, et donc toute la suite de trades. C2-SL perd 2,31 USDT sur les
six trades, mais son PnL total ne bouge que de −0,13 USDT en base. Il gagne +3,6 USDT en pessimiste et perd
6,8 USDT en stress.

**Le PnL du portefeuille à 2 emplacements est donc sensible à de petites perturbations, à hauteur de plusieurs
USDT.** C'est une incertitude de modèle du même ordre que l'avantage de C2-SL en stress. Au niveau signal, la
correction (quantité de référence de 110 USDT) touche 9 trades de C2-SL et aucun de C4-SL.

La correction ne change pas l'éligibilité selon T1–T11 : les deux candidats les passent tous.

## 4. Avant / après (entraînement)

| Mesure | C2-SL avant (#498) | C2-SL après | C4-SL avant (#498) | C4-SL après |
|---|---|---|---|---|
| Trades (signal / portefeuille) | 1 148 / 549 | 1 148 / 546 | 517 / 308 | 517 / 308 |
| Net moyen signal | +9,9 bp | +9,78 bp | +13,0 bp | +13,03 bp |
| PnL base | +21,68 | +21,55 | +26,74 | +26,74 |
| PnL pessimiste | +6,59 | +10,19 | +17,11 | +17,11 |
| PnL latence 1 s | +6,09 | **−0,72** | +10,03 | +9,70 |
| Drawdown max (MTM) | 24,32 | 24,80 | 24,26 | 24,26 |
| Trades du contrôle principal (portefeuille) | 1 097 (épisodes) | 460 (grappes) | 638 | 271 |
| Exposition du contrôle | 86,8 USDT | 41,3 USDT | 53,0 USDT | 24,7 USDT |
| PnL du contrôle (moyenne) | −164,77 | −75,81 | −117,28 | −48,10 |
| Surplus (portefeuille) | +186,5 | +97,4 | +144,0 | +74,8 |
| p (portefeuille) | 0,001 | 0,001 | 0,001 | 0,001 |
| IC 90 % du surplus (portefeuille) | [+137,7 ; +235,1] | [+49,0 ; +145,7] | [+104,6 ; +185,4] | [+38,6 ; +113,0] |
| Surplus signal [IC 90 %] | +28,9 [+19,2 ; +38,5] | +27,5 [+17,4 ; +37,5] | +32,4 [+19,1 ; +45,0] | +30,5 [+16,8 ; +43,9] |

## 5. Gate (§7 de la mission, §4 de l'amendement 2)

| Vérification | C2-SL | C4-SL |
|---|---|---|
| Tests du moteur corrigé (94) | ✔ | ✔ |
| T1–T11 avec moteur et contrôle corrigés | ✔ (11/11) | ✔ (11/11) |
| T4, bande sans flux, p ≤ 0,05 | ✔ (0,007) | ✔ de justesse (0,047) |
| Comparabilité : trades signal ±15 % | ✔ (−5,5 %) | ✔ (−5,3 %) |
| Comparabilité : trades portefeuille ±15 % | ✘ (−15,7 %) | ✔ (−12,0 %) |
| Comparabilité : temps de position ±20 % | ✔ (−15,6 %) | ✔ (−11,9 %) |
| Comparabilité : exposition notionnelle ±20 % | ✘ (**−23,8 %**) | ✔ (−19,7 %, à la limite) |
| Sorties partielles traitées sans surestimer le PnL | ✔ | ✔ |
| Pré-enregistrement et amendements figés | ✔ | ✔ |
| **Statut** | **`STATISTICAL_BLOCKER`**, retiré de la phase 2 | **éligible**, transmis |

Décision : **`GATE_PASS`, C4-SL seul**. Aucun seuil n'a été assoupli.

**Réserve.** C4-SL passe la comparabilité de très peu sur l'exposition (−19,7 % pour une limite de ±20 %) et passe
T4 de justesse (p 0,047). Son témoin principal est un peu moins exposé que lui, ce qui rend la comparaison
légèrement favorable au candidat si l'exposition moyenne perd de l'argent.

La lecture par trade, contre le contrôle par grappes, n'est pas touchée par cet écart : +30,5 bp, IC
[+16,8 ; +43,9].

La validation de novembre se fait donc **sous protocole amendé** (contrôle principal redéfini). Ce n'est pas une
confirmation inchangée du protocole initial.
