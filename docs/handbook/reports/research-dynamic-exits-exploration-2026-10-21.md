# Sorties dynamiques à entrées identiques : rejeu d'exploration 1 (2026-10-21)

**Statut : candidat S1 avec sorties A/B/C CLOS (2026-10-21).** Verdict pré-déclaré :
`NO_IMPROVEMENT_DEMONSTRATED`, statut `EXPLORATION_ONLY`, sans validation indépendante.

Ni le stop suiveur (B) ni la sortie adaptative (C) n'améliorent la sortie fixe (A). Sur ces données,
les deux font même légèrement moins bien. Les trois méthodes perdent environ un tiers de R par trade.
Avant coûts, chacune des trois est déjà légèrement négative. Les coûts supposés pèsent ensuite environ
0,3 R. La conclusion établie est limitée : **les trois méthodes testées ne rendent pas S1 rentable dans
ce modèle de simulation.** Elle ne dit rien des sorties qui n'ont pas été testées. Ces données
2023-2024 sont déjà consommées : ce rejeu ne vaut ni validation indépendante ni stratégie confirmée.

**Clôture.** Rien ne justifie une confirmation supplémentaire de ce candidat sur des données neuves.
Le simulateur de sorties, le portefeuille réaliste et le verdict fermé restent disponibles pour de
futures hypothèses distinctes. Aucun seuil ni paramètre n'a été modifié après lecture.

## 1. Données réellement utilisées

| Élément | Valeur |
|---|---|
| Autorisation | message du propriétaire du 2026-10-21, développement uniquement |
| Source | cache local Binance USD-M, 10 majors (BTC, ETH, BNB, XRP, ADA, DOGE, SOL, LTC, LINK, AVAX) |
| Fenêtre | 2023-01-01 00:00 → 2025-01-01 00:00 UTC, découpée en mémoire, échauffement compris |
| Bougies 1 min | 1 052 640 par paire, aucune minute manquante |
| Funding | 2 193 événements par paire, grille de 8 h complète, aucun funding inconnu |
| Coûts | `cost-assumptions.json` sha256 `83b0216d…` (hypothèses, pas les frais historiques Binance) |
| Instruments | `instrument-assumptions.json` sha256 `a85a6581…` (métadonnées actuelles, pas celles de 2023-2024) |
| Spécification | `dynamic_exits_v1_1.json` sha256 `e7d2328c…`, gelée et poussée avant le rejeu (commit `c2088f5a`) |
| Code | paquet research_v2 sha256 `4acb57a9…` |
| Résultats bruts | `tradingV3-private/research/20261021T1500Z-dynamic-exits/run1/` (`report.json`, `outcomes.npz`) |

Aucune donnée n'a été téléchargée. Aucune bougie de 2022 ou de 2025 n'a servi, pas même pour
l'échauffement des indicateurs. fwd-okx-r1, les résultats scellés et les données Q3 et G5 n'ont pas
été consultés.

Signaux S1 : 74 322 décisions, dont 71 031 remplies. La table d'entrées communes en garde 42 987
remplies, plus 2 243 ordres expirés. Aucun conflit long/court n'a été trouvé sur une même barre.

**Contrôle de cohérence.** En mode de compatibilité, avec le TP initial au contact, A donne −0,332 R
par trade. Le screen scalping du 2026-10-12 donnait −0,331 R en long et −0,323 R en short pour la même
configuration. Le moteur de sortie reproduit donc le moteur existant.

## 2. Comparaison 1 : mêmes entrées, effet des sorties seul

42 987 trades sur 731 jours, taille de référence de 250 USDT. Le R net vaut le résultat net divisé par
le risque au stop, coûts compris. Ce risque est identique pour les trois méthodes.

| Profil de base | A fixe | B suiveur | C adaptative |
|---|---|---|---|
| R net moyen | −0,339 | −0,390 | −0,350 |
| Borne basse à 90 % (blocs par jour) | −0,348 | −0,403 | −0,358 |
| R net total | −14 589 | −16 780 | −15 034 |
| Net moyen par trade | −0,35 USDT | −0,41 USDT | −0,37 USDT |
| Net total | −15 248 USDT | −17 672 USDT | −15 724 USDT |
| Trades gagnants | 35,0 % | 22,7 % | 34,0 % |
| Pire trade | −1,14 R | −1,12 R | −1,12 R |
| Pire jour | −44,6 R | −51,8 R | −45,4 R |
| Drawdown cumulé | 14 589 R | 16 804 R | 15 034 R |

Aucun trade ne perd plus de 1,5 R : les gaps au-delà du stop restent rares sur ces majors.

**Par année.** Le R net moyen est stable d'une année à l'autre. Aucune méthode n'est positive sur
aucune année.

| Année | Trades | A | B | C |
|---|---|---|---|---|
| 2023 | 21 150 | −0,367 | −0,417 | −0,376 |
| 2024 | 21 837 | −0,313 | −0,365 | −0,324 |

**Écarts appariés**, en R par trade. La correction de Holm porte sur les deux comparaisons, avec un
bootstrap par jour à 5 000 tirages.

| Écart | Moyen | Intervalle à 90 % | Holm | Meilleur / pire que A |
|---|---|---|---|---|
| B − A | −0,051 | [−0,060 ; −0,042] | non rejeté, p = 1 | 11,5 % / 24,5 % des trades |
| C − A | −0,010 | [−0,012 ; −0,009] | non rejeté, p = 1 | 6,2 % / 6,7 % des trades |

En USDT à la taille de référence, B perd 2 424 USDT de plus que A sur les deux ans. C en perd 476 de
plus.

**Motifs de sortie.**

- A : 27 186 stops, 14 820 TP, 981 sorties au temps.
- B : 28 064 stops initiaux, 13 200 stops suiveurs, 1 723 sorties au temps.
- C : 25 861 stops, 12 393 TP, 2 169 stops resserrés, 2 085 sorties sur stagnation, 382 sorties sur
  stop déjà franchi, 97 sorties au temps.

C ne sort jamais sur invalidation. La condition combinée, un recul d'au moins 0,5 R avec un ATR
multiplié par 1,5, n'est jamais remplie avant le stop.

**Sensibilités.** Toutes ne font que renforcer le constat.

| Sensibilité | A | B | C | B − A | C − A |
|---|---|---|---|---|---|
| Coûts défavorables | −0,361 | −0,466 | −0,380 | −0,105 | −0,019 |
| Écart doublé | −0,353 | −0,417 | −0,365 | −0,064 | −0,013 |
| Latence d'une barre | −0,339 | −0,396 | −0,344 | −0,056 | −0,005 |
| TP au contact (ancien mode) | −0,332 | −0,390 | −0,343 | −0,059 | −0,011 |

**Pourquoi.** Décomposition descriptive, faite après lecture et non décisive, en R par trade :

| | A | B | C |
|---|---|---|---|
| Brut, avant coûts | −0,060 | −0,053 | −0,060 |
| Commissions | 0,169 | 0,169 | 0,169 |
| Écart et glissement | 0,111 | 0,169 | 0,121 |

- **Avant coûts, le couple S1 + A est négatif.** Son brut vaut −0,060 R, intervalle à 90 % [−0,068 ;
  −0,051], soit environ −2,8 pb par trade. Le brut dépend du couple entrée-sortie, pas de l'entrée
  seule : il vaut −0,053 R avec B et −0,060 R avec C. Le stop médian est à 25 pb de l'entrée, alors que les coûts
  supposés font 12 à 14 pb. Les coûts représentent donc à eux seuls environ 0,3 R.
- **B ne gagne rien en brut.** Il fait +0,007 R par rapport à A, intervalle [−0,005 ; +0,019]. Il
  perd ensuite 0,058 R en coûts : toutes ses sorties paient l'écart et le glissement d'une sortie au
  marché, alors qu'un TP de A sort en limite sans ces frais.
- **C est neutre en brut**, −0,0005 R, et un peu plus cher en coûts.

Ce résultat est compatible avec Kaminski et Lo (2014), *When do stop-loss rules stop losses?* Dans
leur modèle de marche aléatoire, une règle de stop n'apporte pas d'espérance positive. Ils montrent au
contraire qu'elle peut en apporter quand les rendements ont une dépendance sérielle, comme le momentum.
Nos deux règles n'ont rien capté de tel ici. Cela ne prouve pas qu'aucune règle ne le pourrait. La page OKX « How to use
trailing stop » décrit un stop suiveur déclenché sur le dernier prix, sans garantie de prix
d'exécution. C'est cohérent avec notre hypothèse : sortie au marché, au pire du niveau et de
l'ouverture.

## 3. Diagnostics de trajectoire de A

Ces mesures portent sur l'horizon commun de 120 minutes après le remplissage. Elles utilisent les
meilleurs prix des bougies : ce sont des diagnostics, jamais des gains réalisables.

| Mesure | Valeur |
|---|---|
| Trades qui atteignent +0,5 R | 61,6 %, dont 43,3 % finissent perdants |
| Trades qui atteignent +1 R | 45,6 %, dont 23,5 % finissent perdants |
| Gain rendu, de l'excursion favorable maximale au résultat net | 1,40 R en moyenne, 1,24 R en médiane |
| Excursion favorable maximale sur 120 min | 3,44 R en moyenne |
| Après un TP, prolongement maximal du prix | 3,88 R en moyenne ; ≥ 1 R dans 75 % des cas |
| Un stop serré à 0,5 R aurait été touché | 88,8 % des trades |
| … puis le TP de A atteint quand même | 52,6 % de ces trades |

Ces chiffres semblent promettre beaucoup aux sorties dynamiques. Mais ils ressemblent à ceux d'une
marche aléatoire : sur 120 minutes, le plus haut d'un prix sans tendance s'éloigne naturellement de
plusieurs ATR de 5 minutes. B et C, qui essaient justement de capter ce prolongement avec des règles
causales, n'y gagnent rien en brut.

## 4. Comparaison 2 : portefeuille réaliste

Chaque méthode parcourt les 74 322 candidats S1. Sa propre sortie libère la paire et l'exposition.

**À taille de référence, sans contrainte de capital.** Ce tableau isole l'effet d'admission.

| | A | B | C |
|---|---|---|---|
| Trades pris | 64 985 | 51 100 | 64 836 |
| Dont hors de la table commune | 22 120 | 8 716 | 21 969 |
| R net moyen | −0,334 | −0,385 | −0,342 |
| R net total | −21 708 | −19 664 | −22 146 |

Les sorties précoces ouvrent beaucoup d'entrées supplémentaires, surtout pour A et C. Ces entrées
supplémentaires perdent autant que les autres. B perd moins au total uniquement parce qu'il tient ses
positions plus longtemps et prend 21 % de trades en moins. Par trade, il perd davantage.

**Avec 1 000 USDT de capital simulé.** Le risque vaut 1 % du capital réalisé, et le notionnel ouvert
reste ≤ 2 × capital.

| Profil de base | A | B | C |
|---|---|---|---|
| Trades pris avant la ruine | 2 126 | 1 965 | 2 100 |
| Jours de trading | 66 | 80 | 63 |
| Capital final | 2,49 USDT | 2,48 USDT | 2,50 USDT |
| Drawdown réalisé relatif | 99,75 % | 99,80 % | 99,75 % |
| Risque ouvert maximal | 2,5 % du capital | 2,1 % | 2,5 % |

Les trois comptes sont ruinés dans les premiers mois de 2023. Au-delà, le minimum de notionnel
bloque toute nouvelle entrée. Le profil défavorable donne la même ruine. Le drawdown est calculé sur le
capital réalisé, dans l'ordre des sorties, par rapport au sommet atteint avant chaque baisse. Les
pertes latentes ne sont pas mesurées. Les seuils de 20 R et de 25 % sont des critères de recherche :
le rejeu ne coupe pas les positions quand ils sont franchis. Le compte de 1 000 USDT n'est qu'un
scénario de simulation.

## 5. Verdict, propriété par propriété

| Propriété | B | C |
|---|---|---|
| Amélioration par rapport à A | non : l'écart est négatif | non : l'écart est négatif |
| Rentabilité propre | non : −0,39 R | non : −0,35 R |
| Robustesse aux coûts défavorables | non | non |
| Risque dans les limites | non : drawdown de 16 804 R ; 99,8 % en portefeuille | non : 15 034 R ; 99,75 % |

- **Amélioration.** Le verdict pré-déclaré est « aucune amélioration démontrée ». Ici, les intervalles
  sont entièrement négatifs. Les deux règles dégradent A de façon mesurable sur ces données, de
  0,05 R pour B et de 0,01 R pour C.
- **Rentabilité.** Aucune des trois méthodes n'est rentable, sur aucune année, sous aucun profil de
  coûts.
- **Robustesse.** Toutes les sensibilités vont dans le même sens.
- **Insuffisance de preuve.** Elle n'est pas en cause : 42 987 trades sur 731 jours, au-delà des
  planchers et des besoins ré-estimés.

**Essais.** Ce rejeu est un essai unique : une configuration d'entrée, trois méthodes, un jeu de
paramètres chacune. La même période et les mêmes paires avaient déjà servi au screen scalping. S1
faisait partie de ses 36 configurations 5 min, toutes négatives, avec une médiane de −0,30 R. Le
registre des campagnes compte environ 1 943 variantes pré-enregistrées et 1 497 variantes
exploratoires à horizon court avant celui-ci.

## 6. Puissance : hypothèses contre mesures

Le calcul suit le NIST/SEMATECH e-Handbook §7.2.2.2 : n = ((z₀,₉₅ + z₀,₈₀) · σ / δ)² × effet de plan,
avec δ = 0,05 R.

| Calcul | σ | Effet de plan | Trades requis |
|---|---|---|---|
| R net propre, déclaré | 1,1 R (hypothèse) | 1 | 2 993 |
| R net propre, déclaré | 1,1 R | 1,5 | 4 489 |
| Écart apparié, déclaré | 0,6 R (hypothèse) | 1 / 1,5 | 891 / 1 336 |
| A mesuré | 0,80 R | 2,75 | 4 333 |
| B mesuré | 1,10 R | 3,46 | 10 306 |
| C mesuré | 0,78 R | 2,83 | 4 217 |
| B − A mesuré | 0,83 R | 2,94 | 5 065 |
| C − A mesuré | 0,25 R | 1,07 | 164, porté au plancher de 3 000 |

- **Effet de plan.** C'est le rapport entre la variance de la moyenne par blocs de jours et sa variance
  sous indépendance. Mesuré, il vaut 2,7 à 3,5 pour les R propres. Les trades d'une même journée sont
  donc beaucoup plus liés que le facteur 1,5 supposé.
- **Écarts-types.** Celui de B confirme l'hypothèse de 1,1 R. Celui de l'écart B − A dépasse
  l'hypothèse de 0,6 R.
- **Minimum de confirmation.** Il serait au moins de 10 306 trades pour juger la rentabilité propre de
  B, et de 5 065 pour l'écart B − A.
- **Plancher de 250 jours.** Il reste celui qui contraint, au rythme mesuré de 59 trades par jour sur
  10 paires. Les planchers de 3 000 trades et 250 jours sont conservés.

## 7. Limites

- Les bougies de 1 minute ne montrent ni le carnet d'ordres ni la file d'attente. Le stop l'emporte
  en cas d'égalité, et tout TP exige une traversée d'un tick.
- Les coûts sont des hypothèses. Les métadonnées d'instruments sont actuelles, pas historiques.
- Le drawdown du portefeuille est réalisé. Les pertes latentes ne sont pas mesurées : le risque
  ouvert les borne, hors gaps.
- La décomposition entre brut et coûts et ses intervalles ont été calculées après lecture. Elles
  expliquent le verdict sans le décider.
- **Problème ouvert avant toute utilisation réelle.** En production, le service de mise à jour des
  TP/SL annule l'ordre existant avant d'en poser un nouveau. La position reste sans protection entre
  les deux. L'étude ne modélise pas cette fenêtre. Sa correction est hors du périmètre de cette
  recherche et doit faire l'objet d'un changement séparé, avant toute utilisation réelle de sorties
  dynamiques.

**Limites connues du simulateur**, relevées par la revue du code après le rejeu. Aucune ne change les
chiffres ci-dessus. Le code n'a pas été modifié après lecture. Ces points sont à corriger avant toute
réutilisation pour une nouvelle hypothèse.

- **Funding à la borne de fenêtre.** Le funding est découpé strictement avant la fin de la fenêtre,
  alors que le contrôle de complétude inclut l'instant de sortie. Un trade sortant exactement au
  2025-01-01 00:00 UTC, qui est aussi une heure de funding, arrêterait le rejeu au lieu de supposer un
  funding nul. Ce cas ne s'est pas produit : le rejeu est allé au bout.
- **Horodatage des sorties au marché.** Les sorties sur invalidation, stagnation ou stop franchi sont
  horodatées à la fin de la bougie d'exécution, alors que le prix est celui de son ouverture. La paire
  est donc libérée une minute trop tard, et un funding tombant à cette minute peut être imputé. Le
  biais est conservateur.
- **Convention de funding.** Le funding est imputé sur l'intervalle (remplissage ; sortie]. Une sortie
  à l'intérieur d'une bougie qui se termine sur une heure de funding paie ce funding. Le biais est
  conservateur et touche environ une sortie sur 480.
- **Grille de funding.** Le contrôle de complétude ne vérifie que la grille de 8 h. Il suffit pour ces
  10 majors en 2023-2024, où la grille était complète. Un symbole à intervalle de 4 h ou de 1 h
  demanderait de l'étendre.

## 8. Recommandation

**Fermer le candidat S1 avec les sorties A, B et C.** Les trois couples testés ont un brut
légèrement négatif. Avec un stop à environ 25 pb, les coûts supposés absorbent environ 0,3 R par trade.
Les deux règles dynamiques ne récupèrent rien en brut par rapport à A, et B ajoute des coûts de sortie
au marché. Rien ne justifie de dépenser des données de confirmation neuves sur ce candidat.

Ce que ce rejeu n'établit pas :

- il ne montre pas qu'aucune sortie ne pourrait rendre une entrée rentable ; seules trois règles, avec
  un jeu de paramètres chacune, ont été testées ;
- un brut positif avec la sortie fixe n'est pas une condition mathématique pour étudier une autre
  sortie, puisque le brut dépend du couple entrée-sortie ;
- en pratique, une nouvelle hypothèse de sortie devrait toutefois donner une raison a priori de capter
  une dépendance du prix après l'entrée, et être pré-enregistrée comme un nouvel essai.

Le simulateur reste utilisable pour de telles hypothèses distinctes.

## 9. Proposition de confirmation, préparée et non exécutée

Cette proposition ne vaut que si le propriétaire veut quand même une confirmation, ou si une autre
entrée remplace S1. Rien n'a été téléchargé ni capturé.

**Option 1 : historique Binance 2020-2022.**

- **Fenêtre stricte proposée** : 2020-01-01 → 2022-11-01. Novembre et décembre 2022 figurent dans le
  jeu de données `20261009T060000Z-10pairs-20221101` et ont servi d'échauffement aux screens. Ils sont
  donc exclus.
- **Audit à faire avant tout téléchargement :**
  - comparer les manifestes de tous les jeux privés pour vérifier qu'aucune archive 1 minute
    2020-2022 de ces paires n'a été lue ;
  - noter que regime-r2 a lu le journalier des perpétuels 2020-01 → 2023-01. Ce chevauchement est
    faible pour une mécanique de sortie à 5 minutes, mais il est réel et doit être déclaré ;
  - vérifier la couverture de SOL, AVAX et DOGE en 2020, dont les cotations perpétuelles sont plus
    récentes ;
  - vérifier la grille de funding de 2020-2021.
- **Volume** : environ 1 Go d'archives mensuelles.
- **Portée** : Binance n'est pas une plateforme d'exécution autorisée en France. Cette option ne
  confirmerait qu'un mécanisme, pas une exécution sur OKX Europe.

**Option 2 : capture prospective OKX X-Perps.**

- **Démarrage** : à partir du 2026-11-02, après la quarantaine du 2026-10-14 au 2026-10-31.
- **Données** : bougies 1 minute, funding et métadonnées d'instruments des X-Perps éligibles, capturées
  à la date.
- **Durée** : au moins 250 jours pour respecter le plancher. Avec 10 paires au rythme mesuré, les
  10 306 trades nécessaires à B demanderaient environ 175 jours, et les 5 065 de B − A environ 86
  jours. Avec moins de paires ou moins de signaux, la durée s'allonge d'autant.
- **Avant la première lecture** : relever les coûts d'OKX Europe et geler une spécification v1.2.0
  avec un minimum de max(3 000 ; besoin ré-estimé) trades.

Une confirmation n'aurait de sens qu'après un gel versionné et pour une hypothèse distincte de celle
qui vient d'être close.

## 10. Portée des corrections partagées

Cette campagne a corrigé `research_v2/budgets.py`, partagé avec les screens research_v2. La portée
ci-dessous vient du code et des rapports existants. Aucune ancienne campagne n'a été relancée.

**Ce qui change.**

- **Drawdown relatif.** `max_drawdown_share` vaut désormais la plus forte baisse rapportée au sommet
  atteint avant elle. L'ancien calcul divisait la plus forte baisse absolue par le sommet final. Il ne
  pouvait donc que sous-estimer : 1 000, puis 700, puis 2 000 donnait 15 % au lieu de 30 %.
- **Ordre des règlements.** Les positions se règlent dans l'ordre de leurs sorties, et non plus dans
  l'ordre des décisions. Seul le chemin du capital entre deux décisions change, donc seuls les deux
  champs de drawdown peuvent bouger.
- **Champs ajoutés.** `drawdown_basis` et `max_open_risk_share` sont nouveaux.

**Ce qui ne change pas.** La somme des règlements avant chaque décision est la même quel que soit leur
ordre. Le dimensionnement, les trades pris ou écartés, le ROI et le capital final sont donc identiques.
Le critère G7 des screens, un drawdown ≤ 6 000 USDT, utilise `stats.realized_drawdown` à taille de
référence, pas `budgets.py` : il est inchangé. Aucun module hors de research_v2 n'importe
`budgets.py`.

**Rapports concernés.**

| Rapport | Usage de `budgets.py` | Effet |
|---|---|---|
| `research-v2-selection-2026-10-12.md`, section Budgets | colonne « max DD » des quatre configurations validées, 10 à 33 % | ces valeurs sont des bornes basses, surtout sur l'entraînement où le capital a fortement monté ; le verdict `no_eligible_candidate` n'en dépendait pas |
| `research-scalping-2026-10-12.md`, section Budgets | part de trades réalisables | inchangée ; aucun drawdown relatif n'y est cité |
| documents `screen-*.json` privés produits avant le 2026-10-21 | champ `budgets.*.max_drawdown_share` | ancienne définition ; à ne pas comparer directement aux nouveaux |

Les autres campagnes, tick, LP, H1, H2 et G5, utilisent leurs propres moteurs et ne sont pas
touchées.
