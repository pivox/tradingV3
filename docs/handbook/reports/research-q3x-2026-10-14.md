# Étude exploratoire de la règle Q3 (q3x) — 2026-10-14

**Exploratoire : rien n'est sélectionné, aucun paramètre n'est choisi, `fwd-okx-r1` n'est pas
modifié et ses semaines W1/W2 ne sont pas lues.** Objet : comprendre d'où viennent les gains et les
pertes de Q3 (règle du test forward OKX) avant de pré-enregistrer une V2.

## 1. Cadre

| Élément | Valeur |
|---|---|
| Note figée avant tout calcul | `EXPLORATORY-q3x.md`, sha256 `4112da7c067dfffc1f373726b5063ae005099ef347e7f7d6cc5e9c9255fa6167` |
| Données | les neuf fenêtres d'**entraînement** r8 (2025), 20 paires, aggTrades et bookDepth Binance USD-M, re-téléchargées (900 + 900 fichiers, 0 échec sha256) ; janvier 2025 et décembre 2024 non téléchargés |
| Règle | long, entrée E1 (passive au bid, 250 ms, 30 s), sortie Z3 (passive à l'ask à 3 600 s, taker à 3 900 s), frais maker 2 bp / taker 5 bp |
| Contrôle | timing aléatoire apparié au régime, 10 graines (r8) |
| Code | branche `claude/research-q3-explore`, commit `79e62bf3` (familles QB, QD, Q3L ; `q3x_analysis.py`), 34 tests |
| Reproductibilité | Q3 redonne exactement le résultat r8 (1 130 trades, +12,7 bp) |

Limite connue : sur Binance, le carnet au meilleur prix est reconstitué à partir des transactions
(pas de bid/ask réel) ; remplissages, MAE et MFE sont donc des approximations.

## 2. Ablation : d'où vient l'avantage

| Variante | Trades | Remplissage | Net moyen | Médiane | PF | Gain | LB90 | Hasard méd. / p95 | Paires/fenêtres + |
|---|---|---|---|---|---|---|---|---|---|
| QB bande seule | 4 963 | 81 % | +2,6 | +3,0 | 1,07 | 52 % | +0,7 | −14,4 / −11,7 | 54 % |
| QD bande + profondeur | 3 643 | 81 % | +3,2 | +4,2 | 1,09 | 52 % | +0,8 | −13,3 / −12,1 | 54 % |
| S7 bande + flux | 1 548 | 74 % | +12,2 | +8,9 | 1,38 | 55 % | +8,9 | −18,2 / −14,9 | 61 % |
| **Q3** bande + flux + profondeur | 1 130 | 75 % | +12,7 | +8,4 | 1,40 | 54 % | +8,6 | −16,1 / −11,8 | 59 % |
| Q3L Q3, sigma sur secondes avec prints | 512 | 81 % | +17,8 | +10,8 | 1,56 | 55 % | +11,8 | −13,8 / −4,6 | 64 % |

En bp par trade, net de frais. Écarts :

- **flux** : S7 − QB = **+9,6 bp** ; Q3 − QD = +9,5 bp ;
- **profondeur** : QD − QB = +0,6 bp ; Q3 − S7 = **+0,5 bp**.

**L'avantage de Q3 vient du flux acheteur des 60 dernières secondes, pas de la profondeur du
carnet.** La bande seule bat largement le hasard (+17 bp d'écart), mais ne paie pas les coûts
(+2,6 bp net). La profondeur, sur Binance, n'apporte presque rien ; c'est aussi la mesure la plus
fragile côté OKX (instantanés à 30 s, carnet tronqué).

## 3. Pertes extrêmes

| Mesure | Q3 | Bande seule |
|---|---|---|
| Quantiles du net (1 / 5 / 50 / 95 / 99 %) | −277 / −151 / +8 / +202 / +331 bp | −331 / −172 / +3 / +163 / +315 |
| Pire trade | −426 bp | −518 bp |
| Somme des 5 % meilleurs trades | +15 642 bp | +61 100 |
| Somme des 5 % pires trades | −13 125 bp | −65 770 |
| Net moyen sans les 5 % meilleurs | **−1,2 bp** | −10,2 |
| Net moyen sans les 5 % pires | +25,6 bp | +16,7 |
| Plus longue série de pertes | 10 | 10 |
| MAE médiane / 5 % / 1 % | −52 / −242 / −396 bp | −54 / −251 / −438 |
| Trades dont le MAE dépasse −100 bp / −200 bp | 27 % / 7 % | 27 % / 8 % |
| Net moyen des trades au MAE < −100 bp | −75 bp | −90 |
| MFE médiane / 95 % | +61 / +245 bp | +56 / +228 |
| Jours (une unité par position) : négatifs / pire / meilleur | 18 / 49 ; −1 894 ; +3 505 bp | 25 / 52 ; −6 432 ; +6 340 |

Lecture :

- la dispersion par trade est large (écart-type de 108 bp) face à un gain moyen de
  12,7 bp : **le résultat tient à l'équilibre entre les deux queues**. Sans les 5 % meilleurs
  trades, la moyenne devient négative ;
- la moitié des trades passent au moins 52 bp sous leur prix d'entrée, un quart plus de 100 bp ;
  ceux-là finissent en moyenne à −75 bp. **Un stop serré (de l'ordre de −100 bp) couperait donc
  27 % des trades plus bas qu'ils ne finissent en moyenne** : en première approximation, il
  coûterait de l'espérance au lieu d'en protéger. Un stop large (−200 bp ou plus) ne toucherait
  que 7 % des trades. Ce sont des ordres de grandeur, pas un réglage : un stop reste à tester par
  une V2 pré-enregistrée ;
- l'absence de stop n'a pas produit, sur ces fenêtres, de perte au-delà de −426 bp par trade : la
  sortie au temps borne la durée, pas la perte.

## 4. Par paire

Q3 positif sur 15 paires sur 20. Négatifs : BNB (−11,4 bp, 99 trades), XRP (−9,8), BTC (−8,0),
ADA (−5,9), NEAR (−3,3). Majors +7,9 bp, mid-caps +17,1 bp. Les paires les plus liquides
(BTC, ETH, SOL, XRP, BNB) ne portent pas l'avantage, sauf SOL (+44 bp sur 27 trades).

## 5. Secondes sans transaction

- Part des secondes sans aucun print : 1-4 % sur BTC, ETH, SOL, XRP ; 30 à 59 % sur ATOM, SEI,
  FIL, NEAR, ARB, INJ, OP, APT. Médiane de `stale_900` aux décisions Q3 : 25 % (90e centile 58 %).
- Q3 par tranche de `stale_900` : < 5 % : +3,0 bp (177 trades) ; 5-20 % : +26,4 bp (303) ;
  > 20 % : +9,0 bp (650).
- Le sigma de Q3 compte chaque seconde sans print comme un rendement nul, ce qui l'abaisse sur
  les paires peu actives et y multiplie les signaux. Q3L (sigma sur les seules secondes avec
  prints) garde 512 signaux, dont 329 communs avec Q3. Signaux propres à Q3 : 801, +11,1 bp ;
  propres à Q3L : 183, +20,0 bp ; Q3L dans son ensemble : +17,8 bp, PF 1,56.
- Lecture : le biais de sigma ajoute surtout des signaux plus faibles ; corriger la mesure
  concentrerait la règle sur moins de trades, de meilleure qualité **sur ces fenêtres**. Ce n'est
  pas une sélection : Q3L n'est pas retenue, elle est une hypothèse pour une V2.

## 6. Ce qu'une V2 pré-enregistrée devrait tester (hypothèses, rien n'est appliqué)

1. **Simplifier la règle autour du flux** : bande + flux (S7) contre Q3, puisque la profondeur
   n'apporte presque rien et coûte en fiabilité de données sur OKX.
2. **Corriger la mesure de volatilité** (secondes sans transaction) et l'appliquer à toutes les
   variantes.
3. **Stop** : seulement large et déclaré d'avance (zone −200 bp ou multiple d'ATR équivalent), avec
   mesure de l'espérance perdue ; un stop serré est déconseillé par la distribution des MAE.
4. **Univers** : exclure ou traiter à part les paires très liquides où l'avantage est absent (BTC,
   BNB, XRP).
5. Mesures de queue et de portefeuille (pire jour, exposition simultanée) dans les critères.

Données de validation propres pour une V2 : novembre 2024 sur les mid-caps (Binance), ou un test
forward OKX après le 31/10. `fwd-okx-r1` reste inchangé jusqu'à son verdict.
