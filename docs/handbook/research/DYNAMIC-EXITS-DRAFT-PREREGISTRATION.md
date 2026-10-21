# Pré-enregistrement : sorties dynamiques à entrées identiques

**Statut : version 1.1.0 GELÉE pour le premier rejeu d'exploration** (2026-10-21, ligne `FROZEN` en
bas). Le gel précède toute lecture de performance sur données réelles. L'exploration porte sur des
données Binance 2023-2024 **déjà consommées** : aucun résultat de ce rejeu ne vaut validation
indépendante ni stratégie confirmée. La version 1.0.0 (brouillon) reste dans le dépôt pour l'historique.

- Spécification machine : `python-orchestrator/scripts/research_v2/specs/dynamic_exits_v1_1.json`
  (version 1.1.0, parent 1.0.0). Son sha256 est enregistré dans `dynamic_exits_v1_1.sha256`, et le
  module refuse un fichier qui ne correspond pas.
- Code : `python-orchestrator/scripts/research_v2/exits_dynamic.py`.
- Tests : `python-orchestrator/tests/test_research_v2_exits_dynamic.py`.
- Date du brouillon : 2026-10-21.

## 1. Inventaire de l'existant

Cet inventaire distingue trois niveaux :

- **implémenté** : le code existe ;
- **testé** : des tests unitaires couvrent le mécanisme ;
- **démontré** : une étude a conclu, hors échantillon, que le mécanisme améliore le net.

| Mécanisme | Implémenté | Testé | Démontré |
|---|---|---|---|
| `FakeTp1TrailingPolicy` (`trading-app/src/Exchange/Fake/`). Le mécanisme est dans `FakeExchangeMatchingEngine::ratchetTrailingStops`. | Oui, Fake/Paper seulement. Le stop est armé au TP1, à une distance fixe en prix (pas en ATR). Il est relevé à chaque événement de marché sur le prix médian, et seulement sur la clôture quand la donnée est en bougies. Il ne se desserre jamais : un relâchement lève une exception. L'ordre est modifié en place (même identifiant). | Oui : `FakeTp1TrailingPolicyTest`, `FakeTp1TrailingTest` (environ 30 tests, dont gap, non-desserrage, redémarrage). **Aucun workflow CI ne lance ces tests.** | Non. Aucune étude de performance. |
| Stop suiveur et sortie indicateur de research_v2 (`fills._simulate_by_bar` ; `engine.py`, `trailing_atr` et `indicator_exit` ; `grid.py`, `TRAILING_ATR = 3`). | Oui, en simulation. Le niveau est recalculé à la clôture de la barre de cadence à partir de la meilleure clôture. Il s'applique à partir de la bougie suivante et ne fait que se resserrer. La sortie indicateur se fait au prix de clôture. | Oui, en CI : `test_trailing_stop_ratchets_at_bar_closes`, `test_indicator_exit_closes_at_the_bar_close`. | Non. Le cycle v2 s'est terminé sans candidat. Les variantes suiveuses ont échoué en entraînement, et les sorties indicateur (S4, S6) du screen scalping sont négatives. |
| Chandelier du swing v2 (`scripts/research_swing2/engine.py`, rapport `docs/handbook/reports/research-swing-v2-2026-10-14.md`). | Oui. Le niveau est le plus haut depuis le remplissage moins S × ATR courant, avec S égal au multiple du stop (2 ou 3). Il est relevé à chaque clôture 4 h et ne se desserre jamais. | Oui, en CI (`test_research_swing2.py`). | Non, résultat négatif. D'après le rapport, les sorties Chandelier ne conservent aucun surplus et aucune ne passe la validation. Seules les cibles fixes à 3 R gardaient un surplus relatif, et le swing v2 est clos. |
| Stops et sorties de research_exec : `scalp_engine.py` ; Z3 et SL200 dans `q3r2_engine.py`. | Oui, en rejeu au tick. Z3 est une sortie passive au bout de 60 minutes, sans stop. SL200 est un stop-market statique à −200 bp, déclenché au premier print, exécuté après la latence et jamais déplacé. Rien n'y est suiveur. | Oui, en CI (`test_research_exec_q3r2*.py`). | Non. Le stop −200 bp a coûté de l'espérance en entraînement tout en réduisant la queue gauche. La validation de novembre 2024 a échoué (`VALIDATION_FAIL`) et Q3 V2 est clos. |
| `TpSlTwoTargetsService` (`trading-app/src/TradeEntry/Service/`). | Oui, en production. Il annule le SL et les TP, puis les replace : aucun adaptateur ne sait modifier un ordre (`supportsModifyOrder: false` pour OKX, Hyperliquid et Fake). **Entre l'annulation et le remplacement, la position n'a plus de stop sur la bourse.** Si le remplacement échoue, rien ne restaure l'ancien SL. | Partiellement : `TpSlTwoTargetsAdapterMigrationTest` existe mais ne tourne pas en CI. Aucun test ne couvre la fenêtre entre annulation et remplacement. | Sans objet. |
| Étude levier > 2× (`research-leverage-gt2-2026-10-15.md`), c'est-à-dire des stops serrés. | Oui. | Oui. | Non : verdict `NOT_INFORMATIVE`, effectif insuffisant. On ne peut rien conclure sur des stops plus serrés. |

Conséquence pour l'exécution réelle (hors du périmètre de cette étude, mais à garder en tête) :

- un TP ou un SL « déplacé » serait aujourd'hui un **annuler puis replacer**, avec une fenêtre sans stop ;
- la simulation applique donc au TP déplacé une perte de priorité (§5) ;
- toute mise en production d'une méthode B ou C exigerait d'abord de corriger cette fenêtre.

## 2. Question

À entrées identiques, peut-on améliorer le bénéfice net en réévaluant la position pendant son
déroulement et en adaptant le TP et le SL ? Le stop est obligatoire.

Cette étude compare uniquement des **sorties**. Les entrées, la taille et le risque initial sont les
mêmes pour les trois méthodes : une seule table d'entrées sert à A, B et C.

## 3. Setup d'entrée : S1 « momentum burst », 5 min

Le setup est déjà documenté dans `PREREGISTRATION-scalping.md` (explore-v2, gelé le 2026-10-12, sha256
`14aca390…`).

**Signal.** Côté long, la clôture dépasse le plus haut des 12 barres précédentes, le volume de la barre
dépasse 2 fois sa moyenne sur 20 barres, et la clôture 15 min est au-dessus de l'EMA50. Le côté court est
le miroir exact.

**Entrée.**

- Ordre limite au prix de clôture de la décision, en mode « through » : il n'est rempli que si le prix
  traverse la limite d'un tick.
- TTL de 5 minutes.
- Stop initial à 1 × ATR14(5m) de la barre de décision. R = |entrée − stop|.
- Horizon maximal de 60 minutes à partir de la clôture de décision.
- Notionnel de référence : 250 USDT.

**Pourquoi S1.**

1. C'est un setup de momentum. S'il existe une dépendance sérielle positive après l'entrée, c'est le cas
   où, selon Kaminski et Lo (§12), une règle de sortie conditionnelle peut ajouter de la valeur ; sous une
   marche aléatoire, elle ne le peut pas.
2. Son exécution (limite through, ATR, 5m / 1m) est déjà implémentée et testée dans research_v2.
3. Le screen scalping l'a déjà évalué avec 4 sorties fixes par côté (8 configurations sur 40). L'étude
   porte donc sur une dimension nouvelle, la sortie, et non sur un nouveau choix d'entrée.

**Admission (indépendante de la méthode).** Une seule position par paire. Une entrée remplie réserve la
paire jusqu'à la clôture de décision + 60 minutes, quelle que soit la sortie. Une entrée non remplie la
réserve jusqu'à la clôture de décision + TTL.

C'est un écart assumé par rapport au screen, où la paire se libérait à la sortie. Il est nécessaire
pour qu'une sortie précoce de B ou C n'ouvre pas des entrées que A n'aurait pas. L'effet est qu'il y a
un peu moins d'entrées que dans le screen.

## 4. Méthodes (un seul jeu de paramètres chacune)

### A : référence

SL à 1 R, TP à 1,5 R, tous deux fixes, et sortie au temps à 60 minutes.

### B : stop suiveur évalué à la clôture

- SL initial à 1 R, pas de TP fixe.
- À chaque clôture de barre 5 min, si la meilleure clôture depuis l'entrée atteint au moins +1 R, le stop
  devient SL = max(SL, meilleure clôture − 1 × ATR_entrée). C'est le cas long ; le court est le miroir.
- Le stop ne se desserre jamais.
- Sortie au temps à 60 minutes.

**Différence avec le stop suiveur d'OKX.** D'après la page d'aide OKX « How to use trailing stop » :

- l'utilisateur fixe un écart en pourcentage ou en montant constant ;
- le déclencheur suit le marché dans le sens favorable et ne se déclenche que sur un retournement ;
- le prix d'activation est optionnel : avec lui, l'ordre ne s'active que lorsque le dernier prix échangé
  atteint ce niveau ; sans lui, il s'active immédiatement ;
- une fois déclenché, « le système place un ordre au marché pour clôturer la position ».

La page ne précise ni le prix de référence suivi (dernier prix ou prix mark), ni la fréquence de suivi,
ni le glissement. Le stop d'OKX suit donc le marché **en continu, côté bourse**, puis sort au marché.

Notre règle B diffère sur trois points :

1. elle est évaluée **seulement à la clôture des barres 5 min**, sur la meilleure **clôture** et non sur
   le meilleur prix ;
2. son activation à +1 R est l'équivalent d'un prix d'activation ;
3. le nouveau niveau est envoyé comme un nouveau stop qui ne vaut qu'à partir de la barre suivante.

B n'est donc pas une simulation du stop suiveur d'OKX. C'est un stop recalculé par la stratégie. Le
stop OKX réagirait plus vite, ce qui veut dire qu'il serait plus souvent sorti par le bruit intra-barre.

### C : sortie adaptative

À chaque clôture de barre 5 min, on calcule :

- u = rendement depuis l'entrée en R, ajusté du côté ;
- MFE = meilleur u observé sur les clôtures ;
- k = nombre de clôtures écoulées depuis la barre de remplissage, celle-ci comprise ;
- ρ = ATR14 courant / ATR_entrée.

Les règles sont évaluées dans cet ordre, et la première qui s'applique l'emporte (1 > 2 > 3 > 4) :

1. **Invalidation.** Si u ≤ −0,5 et ρ ≥ 1,5 : fermeture au marché à l'ouverture suivante.
2. **Essoufflement.**
   - **Stagnation** : si k ≥ 6 et |u| < 0,25, fermeture au marché à l'ouverture suivante.
   - **Rendu de gain** : si MFE ≥ +1 R et u ≤ MFE − 0,5, alors SL = max(SL, clôture − 0,5 × ATR courant).
   - Si les deux conditions sont vraies, la sortie l'emporte sur le resserrement.
3. **Mouvement favorable.** Si u ≥ +1 R et que la clôture est une nouvelle meilleure clôture :
   - SL = max(SL, niveau d'équilibre). Le niveau d'équilibre est l'entrée plus le coût aller-retour en
     prix (profil de base, sortie de type stop), arrondi contre la position. Un stop placé exactement au
     prix d'entrée ne couvrirait pas les frais, ce qui est vérifié par un test.
   - TP = min(max(TP, meilleure clôture + 1 R), entrée + 3 R). Le TP n'est étendu que sur une nouvelle
     meilleure clôture et reste plafonné à 3 R.
4. **Sinon,** on conserve la position.

Valeurs initiales : TP à 1,5 R, SL à 1 R. Horizon de 60 minutes.

## 5. Exécution (commune)

- **Décisions.** Toutes les décisions sont prises à la clôture d'une barre 5 min, à partir de la barre
  de remplissage. Elles s'appliquent à partir de la première bougie 1 min de la barre suivante. En
  sensibilité, on ajoute une barre de latence.
- **Contrôle du SL et du TP.** Les deux sont vérifiés à l'intérieur des barres, sur les bougies 1 min.
  - Le stop est surveillé dès la bougie de remplissage.
  - Le TP initial est surveillé à partir de la bougie suivante. Depuis la v1.1.0, il n'est rempli que
    si le marché le traverse d'un tick (AM2) ; le contact seul est la sensibilité `target_touch_legacy`.
  - Si le SL et le TP sont touchés dans la même minute, le stop l'emporte.
- **Exécution du stop.** Un stop est exécuté au pire du niveau et de l'ouverture de la bougie, ce qui
  traite les gaps. Il n'est jamais garanti à son niveau. Un nouveau niveau déjà franchi à la clôture de
  décision devient une sortie au marché à l'ouverture effective suivante (AM3).
- **TP déplacé.** Un TP déplacé est un nouvel ordre limite et perd sa priorité dans la file. Il n'est
  rempli que par une traversée d'un tick, à partir de la première bougie qui suit la barre de
  modification. L'ancien TP n'est plus actif dès l'entrée en vigueur du nouveau.
- **Fermeture au marché.** Elle s'exécute à l'ouverture de la bougie effective et paie : frais taker,
  `stop_spread_rate` (demi-écart) et `stop_slippage_rate`.
- **Arrondis.** Les stops sont arrondis vers l'extérieur (vers le bas pour un long). Le niveau
  d'équilibre est arrondi contre la position (vers le haut pour un long). Les TP sont arrondis en
  s'éloignant de l'entrée.
- **Contrôle de cohérence.** En mode `target_touch_legacy`, la méthode A du module reproduit exactement `fills.simulate`. Un test le
  vérifie trajectoire par trajectoire.

## 6. Coûts

Les coûts viennent de `research-cost-assumptions.v1` (campaign-assumptions-r1, sha256
`83b0216d…`) :

- **frais** : maker 2 bp à l'entrée, taker 5 bp à la sortie ;
- **profil de base** : écart 2 bp et glissement 5 bp sur les sorties de type stop ou marché ;
- **profil défavorable** : écart 10 bp et glissement 10 bp ;
- **profil « écart doublé »** : le profil de base avec tous les écarts multipliés par 2 ;
- **financement** : financement observé, plus une provision dans le risque.

Le risque (`stop_risk_quote`), la quantité et l'entrée sont identiques entre les méthodes, ce qui est
vérifié par un test et contrôlé avant toute comparaison appariée. Le R net vaut net / risque.

## 7. Diagnostics

Tous les diagnostics portent sur les mêmes entrées, avec un horizon commun de 120 minutes après le
remplissage, donc au-delà de la sortie de A :

- la part des positions dont le MFE a atteint +0,5 R ou +1 R et qui finissent perdantes sous A ;
- le gain temporaire rendu, MFE − résultat de A, en R ;
- le mouvement favorable qui se poursuit après le TP de A ;
- la part des positions qu'un SL à 0,5 R aurait sorties pendant l'horizon de A, et parmi elles, celles
  qui ont ensuite repris jusqu'au TP de A dans les 120 minutes.

**Les meilleurs prix (hauts et bas des bougies 1 min) servent au diagnostic uniquement. Ils ne
représentent jamais un gain réalisable.**

## 8. Statistiques

Pour chaque méthode et chaque profil (base, défavorable, écart doublé), puis en sensibilité de latence
d'une barre, on rapporte :

- le net moyen et le net total, en R et en USDT ;
- le nombre de trades et de jours ;
- la pire perte par trade et la pire perte par jour ;
- le drawdown réalisé (en R et en USDT, dans l'ordre des sorties) ;
- la répartition par motif de sortie.

**Comparaisons appariées B−A et C−A, sur les mêmes entrées.**

- On calcule la différence de R net par trade.
- L'intervalle vient d'un bootstrap par **blocs de jours UTC** (5 000 tirages, graine 20261021).
- La p-valeur est unilatérale : (#{moyennes ≤ 0} + 1) / (B + 1).
- On applique la correction de **Holm** aux deux comparaisons, avec α = 0,10 en unilatéral.
- La borne basse ajustée est le quantile α/2 pour la plus petite p-valeur, puis α pour la seconde. La
  procédure s'arrête dès qu'une comparaison n'est pas rejetée.

**Lecture portefeuille.**

- Elle utilise `budgets.budget_study` avec 1 000 USDT, un risque de 1 % du capital et un plafond
  d'exposition à 2 × le capital.
- Les limites sont **identiques pour les trois méthodes**. Seules les sorties, donc l'exposition
  libérée, diffèrent.

## 9. Critères de décision (fixés d'avance)

On poursuit avec une méthode M (B ou C) seulement si **toutes** les conditions suivantes sont remplies :

1. **Amélioration** : la différence M−A est rejetée par Holm et sa borne basse ajustée est > 0
   (profil de base, comparaison 1).
2. **Rentabilité** : le R net moyen de M est > 0, avec une borne basse à 90 % (bootstrap par jours) > 0
   (profil de base, comparaison 1).
3. **Robustesse aux coûts** : sous le profil défavorable, le R net moyen de M est > 0 et la différence
   moyenne M−A est > 0.
4. **Risque** : le drawdown de M est ≤ 20 R (net cumulé en R, ordre des sorties, comparaison 1). Son
   portefeuille réaliste prend au moins un trade. Son drawdown réalisé relatif à 1 000 USDT est ≤ 25 %
   (comparaison 2, profil de base).

Une mesure absente, non finie ou invalide ne vaut jamais zéro et fait échouer le critère concerné
(amendement AM6). Les limites de 20 R et 25 % sont des critères de recherche, pas des arrêts
automatiques : le rejeu ne coupe pas les positions quand elles sont franchies (AM10). Le compte de
1 000 USDT est un scénario de simulation.

La sensibilité à l'écart doublé et à la latence d'une barre est rapportée et commentée. Elle n'est pas
un critère bloquant, mais un renversement de signe devra figurer dans le verdict.

## 10. Exigences de données et puissance

Le nombre de trades nécessaire se calcule par n = ((z₁₋α + z_puissance) · σ / δ)² × effet de plan,
avec α = 0,05 en unilatéral (le niveau le plus strict sous Holm) et une puissance de 0,80.

| Cible | Hypothèse | Trades requis |
|---|---|---|
| Différence appariée | σ_diff = 0,6 R, δ = 0,05 R, effet de grappe par jour de 1,5 | 1 336 |
| R net propre de la méthode | σ = 1,1 R, δ = 0,05 R | 2 993 |

Minimums déclarés : **3 000 trades et 250 jours distincts**. En dessous, le verdict est
`INSUFFICIENT_DATA`. Ce calcul est implémenté dans `required_trades` et vérifié par un test.

Précision de la v1.1.0 (AM9, d'après NIST/SEMATECH e-Handbook §7.2.2.2) : les 3 000 trades viennent du
calcul sur le R net propre **sans** effet de plan (2 993, arrondi). Le facteur 1,5 de dépendance par
jour doit s'appliquer aux deux calculs. Il donne 4 489 trades pour le R net propre et 1 336 pour la
différence appariée (891 sans facteur). Le plancher de 250 jours est un jugement, pas une formule : il
faut assez de grappes indépendantes (des jours) pour le bootstrap par blocs, et un échantillon qui
couvre plusieurs régimes, soit à peu près une année de jours de cotation. Les planchers sont conservés.
Le rejeu d'exploration ré-estime σ et l'effet de plan par jour. Le minimum de la future confirmation
sera max(plancher, besoin ré-estimé).

Les écarts-types σ sont des hypothèses a priori ; aucune donnée n'a été lue pour les fixer. Si le
propriétaire autorise l'exploration, ils seront ré-estimés sur les données d'exploration **avant** le
gel. Le minimum ne pourra alors qu'augmenter, jamais baisser.

## 11. Découpe des données

- **Exploration** : Binance USD-M, 10 majors, bougies 1 min, du 2023-01-01 au 2024-12-31. Ces données
  sont **déjà consommées** par les screens v2 et scalping. Elles servent au développement uniquement,
  par exemple à vérifier le code de bout en bout et à ré-estimer σ. Elles ne seront lues **qu'avec
  l'autorisation écrite du propriétaire**, donnée le 2026-10-21. `exits_dynamic run` refuse de démarrer
  sans `--owner-authorization`, sans spécification gelée, hors de la fenêtre autorisée ou si le hash
  des coûts ou des instruments diffère de la spécification.
- **Confirmation** : des données réellement nouvelles, **à désigner par le propriétaire**. Deux options :
  - l'intraday Binance 2020-2022, non lu selon le registre (statut `possibly_independent`), à
    télécharger ;
  - une capture prospective des X-Perps OKX à partir du 2026-11-02.

  La confirmation est lue une seule fois, après le gel, et c'est elle qui porte le verdict.

## 12. Essais antérieurs pertinents et références

**Essais antérieurs.**

- **Screen scalping** (explore-v2) : 40 configurations, dont 8 sur S1 (stop 1 ou 1,5 ATR, TP 1,5 ou
  2 R, par côté). Les sorties y étaient toutes fixes.
- **Chandelier du swing v2** : négatif (§1).
- **Stop Q3 V2 (SL200)** : coûteux en entraînement, validation en échec (§1).
- **Étude levier** : non informative (§1).

Ces essais n'ont jamais testé une sortie réévaluée à entrées identiques. En revanche, ils montrent
qu'aucun mécanisme de sortie n'a été démontré à ce jour.

**Kaminski et Lo, « When do stop-loss rules stop losses? »** (Journal of Financial Markets, vol. 18,
2014 ; manuscrit MIT DSpace,
https://dspace.mit.edu/entities/publication/bb69ca4b-0cdc-487f-831d-63b2e84fafee).

- *Ce qu'ils démontrent.* Ils proposent un cadre analytique qui mesure ce qu'une règle de stop-loss
  ajoute ou retire à l'espérance et à la volatilité d'une stratégie quelconque. Cette contribution
  marginale à l'espérance est appelée « stopping premium ».
- *Sous quelles hypothèses.*
  - Sous une marche aléatoire, une règle simple 0/1 (sortir vers l'actif sans risque après une perte
    cumulée, puis revenir) **réduit toujours** l'espérance de rendement.
  - Avec du momentum ou des changements de régime, elle peut ajouter de la valeur.
  - Les données empiriques sont des données de futures quotidiennes (version publiée : stratégie
    buy-and-hold sur futures d'indices), et des données mensuelles actions et obligations américaines
    1950-2004 dans la version de travail.
  - Leur conclusion empirique vaut « aux fréquences d'échantillonnage plus longues ».
- *Limites de transfert ici.*
  - La règle étudiée est une règle d'exposition de portefeuille avec ré-entrée, à fréquence quotidienne
    ou mensuelle. Ce n'est pas une sortie de trade avec TP et SL à l'échelle de 5 minutes.
  - Les coûts de transaction n'y sont pas centraux. Ici, ils représentent une fraction importante de R.
  - Le résultat dépend de la dépendance sérielle de la série, qui ne se transpose pas d'un marché ou
    d'une fréquence à l'autre.
  - Le mécanisme se transpose en revanche : sous une martingale (théorème d'arrêt optionnel, horizon
    borné), **aucune** règle de sortie ne peut augmenter le gain brut espéré. B ou C ne peuvent battre
    A que (a) par une dépendance sérielle conditionnelle à la trajectoire après l'entrée S1, ou (b) par
    des coûts plus faibles, alors qu'ils en ajoutent (sorties taker). C'est l'hypothèse nulle naturelle
    de cette étude. L'exécution synthétique sur marche aléatoire (commande `synthetic`) va dans ce sens :
    B−A et C−A y sont négatifs. Ce n'est qu'un contrôle de cohérence, pas un résultat.
  - Note de lecture : seule la page de résumé a pu être lue directement ; le PDF intégral a renvoyé une
    erreur HTTP 429. Les énoncés sur la marche aléatoire et le momentum viennent du résumé de la version
    de travail, cité par les notices.

**OKX, « How to use trailing stop »** (https://www.okx.com/en-eu/help/how-to-use-trailing-stop) : voir
§4 B.

## 13. Verdicts possibles

Version 1.1.0 (AM6). Chaque méthode reçoit séparément les quatre propriétés du §9, avec la liste des
critères échoués.

- `IMPROVEMENT_PROFITABLE_SUPPORTED` : au moins une méthode remplit les quatre critères.
- `IMPROVEMENT_PROFITABLE_NOT_ROBUST` : amélioration et rentabilité étayées, mais la robustesse aux
  coûts ou le risque échoue. Une telle méthode n'est pas « déficitaire » : elle est trop risquée ou
  trop sensible aux coûts sous les critères fixés.
- `IMPROVEMENT_BUT_NOT_PROFITABLE` : l'amélioration appariée est étayée, mais la rentabilité propre ne
  l'est pas.
- `NO_IMPROVEMENT_DEMONSTRATED` : aucune comparaison n'est rejetée par Holm avec une borne basse > 0.
  Une amélioration non démontrée ne prouve pas l'absence d'effet.
- `INSUFFICIENT_DATA` : moins de 3 000 trades ou moins de 250 jours, ou mesure absente.

Sur les données d'exploration, tout verdict porte le statut `EXPLORATION_ONLY`.

## 14. Code, tests et commandes

- Le module réutilise les briques de research_v2 :
  - `fills.simulate` pour le remplissage de l'entrée et la référence A ;
  - `fills._stop_fill_price` ;
  - `engine.plan_prices_from_distance` et `engine._round_to_tick` ;
  - `costs.settle`, `costs.stop_risk_quote` et `costs.doubled_spread` ;
  - `budgets.budget_study` ;
  - `stats.realized_drawdown`.
- Seule la boucle de sortie est nouvelle.
- Commande synthétique de bout en bout :
  `python -m scripts.research_v2.exits_dynamic synthetic --out /tmp/x.json`.
- Les tests se lancent depuis `python-orchestrator/` avec
  `python -m pytest --noconftest tests/test_research_v2_exits_dynamic.py`. Ils couvrent :
  - la causalité : effet à la barre suivante, latence, permutation des bougies futures avec un ATR
    recalculé ;
  - la symétrie long/court sur une série miroir ;
  - les gaps à travers le stop ;
  - l'ambiguïté entre TP et SL dans une même minute ;
  - les coûts : un SL à l'entrée donne un net négatif, le niveau d'équilibre couvre les frais à un tick
    près ;
  - le non-desserrage du stop (propriété sur trajectoires aléatoires, latences 0 et 1) ;
  - la perte de priorité du TP déplacé ;
  - l'égalité des entrées, de la taille et du risque entre méthodes ;
  - l'horizon commun des diagnostics ;
  - le déterminisme ;
  - le contrôle du hash de la spécification ;
  - le calcul de puissance ;
  - Holm et le bootstrap par blocs.

## 15. Limites connues

- Des bougies 1 min ne montrent ni le carnet d'ordres, ni la file d'attente, ni le chemin à l'intérieur
  de la minute. C'est pourquoi le stop l'emporte en cas d'égalité et que le TP déplacé exige une
  traversée.
- Depuis la v1.1.0, tous les TP (initial et déplacé) exigent une traversée d'un tick (AM2). L'ancienne
  convention (TP initial au contact) reste calculée comme contrôle de compatibilité.
- Le niveau d'équilibre de C est calculé avec le profil de base. Sous le profil défavorable, il ne couvre
  pas tous les frais. C'est voulu : la stratégie ne connaît pas le coût réalisé.
- En exécution réelle, déplacer un SL ou un TP passerait aujourd'hui par un annuler-puis-replacer, avec
  une fenêtre sans stop (§1). L'étude ne modélise pas cette fenêtre.

## Amendements

Version 1.1.0, écrits le 2026-10-21 après la revue du propriétaire et **avant toute lecture de
performance réelle**. Le détail machine est dans `amendments` de la spécification.

- **AM1, admission commune par paire.** Une seule position par paire, quel que soit le sens. Une barre
  portant un signal long et un signal court est un conflit : les deux sont écartés et comptés. La
  v1.0.0 construisait la table par sens et autorisait un long et un court en même temps.
- **AM2, TP harmonisés.** Tout TP, initial ou déplacé, est un ordre limite crédité seulement si le
  marché le traverse d'un tick. L'ancien mode (TP initial au contact) devient la sensibilité
  `target_touch_legacy`.
- **AM3, stop déjà franchi.** Un nouveau stop doit se trouver au moins un tick du bon côté de la clôture
  de décision. Sinon il ne peut pas reposer comme stop : la position sort au marché à l'ouverture
  effective suivante (motif `stop_crossed`, coûts d'une sortie au marché). Un stop valide que la bougie
  effective ouvre au-delà se remplit à l'ouverture. Aucun remplissage n'est crédité à un niveau déjà
  dépassé.
- **AM4, deuxième comparaison : portefeuille réaliste.** Chaque méthode parcourt la liste complète des
  candidats S1. Sa propre sortie libère la paire et l'exposition. Un ordre d'entrée non rempli réserve
  la paire et son notionnel jusqu'à la fin du TTL. Les règles de capital et de risque sont identiques
  pour les trois méthodes : 1 % du capital réalisé en risque, notionnel ouvert ≤ 2 × capital, pas et
  minimums d'échange. Une variante à taille de référence, sans capital, isole l'effet d'admission.
- **AM5, drawdown.** Le drawdown du portefeuille est la plus forte baisse relative du capital réalisé
  par rapport au sommet atteint avant cette baisse. Les règlements suivent l'ordre des sorties. Les
  pertes latentes ne sont pas mesurées. La part de risque ouvert maximale les borne, hors gaps. La
  v1.0.0 divisait la plus forte perte absolue par le sommet final et réglait dans l'ordre des
  décisions.
- **AM6, verdict fermé.** Voir §9 et §13.
- **AM7, funding obligatoire.** Chaque heure de funding comprise dans un trade doit figurer dans les
  données, à une minute près. Sinon le rejeu s'arrête. Aucun funding ni coût inconnu ne devient zéro.
- **AM8, point mort.** Il vaut l'entrée plus le coût aller-retour total supposé du profil de base :
  commission maker 2 pb, commission taker 5 pb, écart 2 pb, glissement 5 pb, soit environ 14 pb. C'est
  une hypothèse de coût, pas la garantie d'une sortie sans perte.
- **AM9, puissance.** Voir §10.
- **AM10, limites.** Les 20 R et 25 % sont des critères de recherche, pas des arrêts automatiques.

Paramètres du premier rejeu d'exploration, fixés avant lecture :

- Fenêtre : décisions et simulations entre le 2023-01-01 00:00 UTC et le 2025-01-01 00:00 UTC, données
  découpées en mémoire à cette fenêtre, échauffement des indicateurs compris. Aucune bougie de 2022 ou
  de 2025 n'est utilisée.
- Paires : les 10 majors du cache `20261012T003000Z-explore-v2`.
- Coûts : `cost-assumptions.json` (sha256 `83b0216d…`), profils base, défavorable et écart doublé.
- Instruments : `instrument-assumptions.json` (sha256 `a85a6581…`). Ce sont des métadonnées
  actuelles, pas les spécifications historiques de 2023-2024.
- Un seul rejeu. Toute modification après lecture compte comme un nouvel essai numéroté.

FROZEN: v1.1.0 pour le rejeu d'exploration 1, 2026-10-21, spécification `dynamic_exits_v1_1.json`
sha256 e7d2328cb62d8ffe7d0fdb42dd0c9745d4a5cb408e83c52ef040c28893f32207 (voir le commit qui introduit cette ligne).

## Exécutions

- Rejeu d'exploration 1, 2026-10-21, spécification v1.1.0, code research_v2 `4acb57a9…`, données
  Binance 2023-2024 autorisées : `NO_IMPROVEMENT_DEMONSTRATED` (statut `EXPLORATION_ONLY`). Rapport :
  `docs/handbook/reports/research-dynamic-exits-exploration-2026-10-21.md`. La spécification n'a pas
  été modifiée après lecture.
