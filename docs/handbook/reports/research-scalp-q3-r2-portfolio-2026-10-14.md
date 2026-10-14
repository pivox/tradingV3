# Scalping Q3 V2 (scalp-q3-r2) — simulation de portefeuille à 500 USDT, 2026-10-14

Ce rapport accompagne le [rapport d'entraînement](research-scalp-q3-r2-training-2026-10-14.md), qui
donne le cadre, le pré-enregistrement et le verdict. Il porte sur les mêmes neuf fenêtres
d'entraînement de 2025, déjà consommées, pour 20 paires. **Ce sont des résultats d'entraînement, pas
une validation.** Les configurations sans stop (Z3) sont des références de recherche, jamais éligibles.

## 1. Règles du portefeuille (pré-enregistrées, §5–6)

- **Capital et levier.** 500 USDT, levier 1×, pas d'emprunt.
- **Taille d'une position.** Notionnel = 0,5 % de l'equity réalisée ÷ d, avec
  d = (200 bp de stop + frais d'entrée + frais taker de sortie + slippage du scénario + 20 bp de marge)
  ÷ 10 000. Avec les coûts de base, d = 2,27 %, soit environ 110 USDT par position. La quantité est
  arrondie à l'inférieur au pas de l'instrument, et le minimum notionnel est contrôlé.
- **Limites.** 2 ordres ou positions actifs au plus ; une position par symbole ; risque nominal ≤ 1 %
  au total ; exposition brute ≤ 80 % ; marge ≤ equity.
- **Refus.** Un ordre refusé ne bloque pas la paire ; elle est réévaluée après la prochaine libération.
  À égalité de temps, les signaux sont départagés par ordre alphabétique du symbole.
- **Calendrier.** Tous les signaux de toutes les paires sont traités chronologiquement dans un même
  calendrier.
- **Remplissage.** Il est plafonné par le volume qui a traversé le prix de l'ordre (remplissage
  partiel). Un reliquat sous le minimum est traité comme un ordre non rempli.
- **PnL.** PnL = q·(sortie − entrée) − frais maker d'entrée − frais de sortie (maker si passive, taker
  sinon) − slippage additionnel − funding. Le funding est réel, payé à chaque règlement traversé.
- **Drawdown.** Equity marquée à la minute au bid (proxy), avec les frais d'entrée des positions
  ouvertes.
- **Scénarios.**
  - base : 2 / 5 bp ;
  - pessimiste : frais × 1,5 et 3 bp de plus par exécution taker ;
  - stress : pessimiste avec une latence de 1 s.
- **Contrôle.** Les mêmes règles appliquées aux signaux à épisodes décalés, sur 1 000 graines.

## 2. Résultats — coûts de base

| Configuration | Equity finale | PnL | Trades | Ordres | Refus slots / min / risque | Partiels | Frais | Funding | DD max MTM | Pire trade | × risque nominal | Pire jour | Série de pertes | Contrôle : PnL moyen / p / IC surplus |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| C1 S7 cal. · Z3 (réf.) | 509,04 | +9,04 | 614 | 817 | 1 206 / 4 / 7 | 15 | 29,31 | +0,01 | 28,03 | −4,79 | 1,88 | −11,08 | 11 | −172,49 / 0,001 / [+131,5 ; +231,9] |
| C1 S7 cal. · SL200 | 503,16 | +3,16 | 640 | 846 | 1 251 / 4 / 23 | 15 | 31,70 | +0,05 | 30,83 | −2,78 | 1,11 | −13,66 | 11 | −184,66 / 0,001 / [+131,0 ; +243,8] |
| C2 Q3 cal. · Z3 (réf.) | 530,70 | +30,70 | 525 | 702 | 791 / 2 / 6 | 13 | 25,74 | +0,06 | 26,78 | −4,79 | 1,88 | −11,27 | 8 | −152,65 / 0,001 / [+136,9 ; +231,3] |
| **C2 Q3 cal. · SL200** | **521,68** | **+21,68** | 549 | 726 | 787 / 3 / 21 | 13 | 27,72 | +0,09 | **24,32** | −2,59 | 1,01 | −10,42 | 9 | −164,77 / 0,001 / [+137,7 ; +235,1] |
| C3 S7 act. · Z3 (réf.) | 517,01 | +17,01 | 390 | 489 | 417 / 3 / 6 | 1 | 18,61 | −0,25 | 24,57 | −4,70 | 1,83 | −7,47 | 7 | −133,27 / 0,001 / [+109,5 ; +192,5] |
| C3 S7 act. · SL200 | 508,39 | +8,39 | 397 | 493 | 402 / 6 / 11 | 1 | 19,60 | −0,19 | 30,51 | −2,84 | 1,11 | −8,70 | 10 | −144,45 / 0,001 / [+113,2 ; +196,0] |
| C4 Q3 act. · Z3 (réf.) | 529,44 | +29,44 | 298 | 369 | 242 / 2 / 1 | 2 | 14,31 | +0,01 | 21,44 | −4,70 | 1,83 | −7,15 | 7 | −107,66 / 0,001 / [+97,9 ; +177,0] |
| **C4 Q3 act. · SL200** | **526,74** | **+26,74** | 308 | 381 | 239 / 2 / 8 | 2 | 15,50 | +0,03 | **24,26** | −2,52 | 0,97 | −7,58 | 9 | −117,28 / 0,001 / [+104,6 ; +185,4] |

Aucun refus pour exposition ni pour capital : la limite qui agit est le nombre de positions (2). Le
slippage additionnel est nul en scénario de base, puisque le prix du proxy est déjà celui de
l'exécution. Funding : aucun règlement manquant.

**Courbe d'equity** (equity à la fin de chaque fenêtre, en USDT) :

| Fin de fenêtre | C1-SL | C2-SL | C3-SL | C4-SL |
|---|---|---|---|---|
| 15/02 | 510,0 | 510,4 | 505,7 | 506,0 |
| 15/03 | 510,5 | 514,8 | 504,4 | 509,4 |
| 19/04 | 516,0 | 517,6 | 506,6 | 515,6 |
| 17/05 | 514,9 | 517,8 | 501,6 | 517,1 |
| 21/06 | 509,6 | 510,4 | 503,5 | 516,8 |
| 19/07 | 516,5 | 526,4 | 510,8 | 528,5 |
| 16/08 | 509,7 | 522,6 | 516,4 | 530,2 |
| 20/09 | 507,6 | 527,4 | 518,6 | 533,4 |
| 18/10 | 503,2 | 521,7 | 508,4 | 526,7 |

La courbe par heure est dans `results.json` (`equity_hourly`).

## 3. Capital et risque (configurations protégées)

| Mesure | C1-SL | C2-SL | C3-SL | C4-SL |
|---|---|---|---|---|
| Notionnel moyen par trade | 108 USDT | 110 USDT | 109 USDT | 112 USDT |
| Capital engagé moyen / max | 63 / 231 USDT | 55 / 235 USDT | 38 / 230 USDT | 31 / 236 USDT |
| Capital disponible minimal | 274 USDT | 277 USDT | 274 USDT | 278 USDT |
| Positions ouvertes, moyenne / max | 0,57 / 2 | 0,49 / 2 | 0,35 / 2 | 0,27 / 2 |
| DD max MTM (relatif) / DD réalisé | 30,8 (5,8 %) / 28,6 | 24,3 (4,6 %) / 22,1 | 30,5 (5,9 %) / 26,5 | 24,3 (4,7 %) / 20,3 |
| Pertes P95 / P99 par trade | 2,38 / 2,46 USDT | 2,42 / 2,48 | 2,37 / 2,49 | 2,43 / 2,49 |
| Meilleur trade / pire trade | +4,67 / −2,78 | +4,58 / −2,59 | +4,75 / −2,84 | +4,88 / −2,52 |
| Jours (réalisés) négatifs / total ; pire / meilleur jour | 27 / 51 ; −13,66 / +10,7 | 23 / 49 ; −10,42 / +12,0 | 23 / 49 ; −8,70 / +13,1 | 18 / 46 ; −7,58 / +14,0 |
| Somme des 5 % meilleurs / pires trades | +85,5 / −76,9 | +75,7 / −65,5 | +59,3 / −48,5 | +45,8 / −36,6 |
| PnL sans les 5 % meilleurs trades | −82,4 | −54,0 | −50,9 | −19,1 |
| Stops touchés (PnL) | 48 (−112,8) | 42 (−99,8) | 35 (−82,0) | 27 (−64,5) |
| Ordres refusés par manque de capital | 0 | 0 | 0 | 0 |

**Dépassement au stop.** Le pire trade protégé vaut 0,97 à 1,11 fois son risque nominal ; le risque
nominal inclut 20 bp de marge et les frais. Sans stop, on atteint 1,83 à 1,88 fois. Avec 500 USDT, la
pire perte observée avec stop est d'environ 2,6 à 2,8 USDT.

## 4. Scénarios pessimiste et stress

| Configuration | PnL pessimiste | DD | PnL stress (latence 1 s) | DD | Trades stress |
|---|---|---|---|---|---|
| C1 · SL200 | **−18,64** | 38,03 | **−17,97** | 36,77 | 602 |
| C2 · SL200 | +6,59 | 24,83 | +6,09 | 21,73 | 508 |
| C3 · SL200 | **−0,91** | 29,71 | **−6,15** | 22,69 | 349 |
| C4 · SL200 | +17,11 | 23,46 | +10,03 | 19,36 | 268 |
| C2 · Z3 (réf.) | +16,50 | 29,66 | +11,85 | 26,84 | 491 |
| C4 · Z3 (réf.) | +22,80 | 20,94 | +23,68 | 18,85 | 264 |

En scénario pessimiste, les frais montent de 27,7 à 39,7 USDT (C2-SL) et le slippage additionnel vaut
3,4 USDT.

## 5. Lecture

- **Portefeuille exécutable.** Les minimums notionnels sont toujours respectés (2 à 6 refus `min`
  seulement, après des pertes). Aucun ordre n'est refusé pour exposition ni pour capital. 549 trades
  sont exécutés pour C2-SL, sur 1 148 au niveau signal : la moitié des occasions est perdue par la
  limite de 2 positions, et le capital reste à 89 % inemployé en moyenne.
- **Rendement faible.**
  - C2-SL : +21,68 USDT en 45 jours de bourse (+4,3 %), pour un DD de 24,3 USDT. Le rapport
    gain / DD est inférieur à 1 sur 9 semaines.
  - C4-SL : +26,74 USDT pour 24,3 USDT de DD.
  - Pessimiste : +6,59 et +17,11 USDT.
- **Q3 meilleure que S7, sans que l'écart soit établi.** Différence quotidienne C1-SL − C2-SL :
  −18,5 USDT, IC 90 % [−46,1 ; +8,2]. S7 protégé perd en pessimiste.
- **Ce que coûte le stop au portefeuille.** C2 passe de +30,70 à +21,68 USDT, C4 de +29,44 à
  +26,74 USDT. En contrepartie, la perte maximale par trade est divisée par environ 1,9 et le DD de C2
  baisse (26,8 → 24,3).
- **Contrôle.** Les portefeuilles aléatoires appariés perdent de −117 à −185 USDT en moyenne, et aucune
  des 1 000 graines n'égale le candidat (p = 0,001). Ils prennent toutefois environ **deux fois plus
  de trades** que le candidat (§8 du rapport d'entraînement). À fréquence égale, l'écart serait
  d'environ +105 USDT au lieu de +187 USDT pour C2-SL. Cette estimation post hoc, faite sur les
  moyennes, est donnée à titre indicatif.
- **Fragilité.** Sans les 5 % meilleurs trades, les quatre portefeuilles protégés sont en perte : le
  résultat dépend de la queue droite.

## 6. Conclusion pour la décision

Avec 500 USDT, un levier de 1× et un stop, Q3 et Q3L restent **exécutables et contenues en risque**
sur l'entraînement : pire trade d'environ 0,5 % de l'equity, DD inférieur à 5 %, pire jour à −2,1 %.
Le gain absolu reste **modeste, en partie attendu par construction, et fragile** : il dépend des
meilleurs trades et du régime de février–mai.

C'est cohérent avec le verdict `RESEARCH_CANDIDATE` : la seule suite admissible est la validation
pré-enregistrée de novembre 2024 (phase 2). Elle est lue une fois, sans réglage, et seulement si le
propriétaire la décide.
