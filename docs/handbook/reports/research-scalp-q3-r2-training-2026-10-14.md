# Scalping Q3 V2 (scalp-q3-r2) — phase 1 d'entraînement, 2026-10-14

**Verdict pré-enregistré : `RESEARCH_CANDIDATE` pour C2-SL (Q3, volatilité calendaire, stop −200 bp)
et C4-SL (Q3L, volatilité active, stop −200 bp).** Cela signifie seulement : « assez prometteur pour
demander une validation indépendante ». Ce n'est **ni** une validation **ni** un GO de trading. Toutes
les données de cette phase étaient déjà consommées (r1–r8, q3x), et les règles ont été choisies sur
elles : un résultat positif ici était **attendu en partie par construction**.

`fwd-okx-r1` n'est pas modifié : règle, données, services, fenêtres W1/W2 intacts. Aucune donnée de
validation ni de holdout n'a été lue. Aucun ordre n'a été placé. Le portefeuille de 500 USDT est
détaillé dans le [rapport portefeuille](research-scalp-q3-r2-portfolio-2026-10-14.md).

## 1. Cadre

| Élément | Valeur |
|---|---|
| Pré-enregistrement | `PREREGISTRATION-scalp-q3-r2.md`, gelé le 2026-10-14 à 12:05:40Z, sha256 `8454ba53389e78b8628f5fd676abf089428cedfcaa439f505fa7b541859f289e`, commit `f1fa3f6a` |
| Inventaire des données | `DATA-EXPOSURE-scalp-q3-r2.md`, sha256 `d8978dd317024eac72ac82198a02182a3eb87d472bdf0f4cf021b2971376c9eb` (même gel) |
| Amendement 1 | 12:19:13Z, **avant tout résultat**, sha256 `e2a8ea06c68fb2f53eed199313d66faf60f3faf82f6fcf0609346aa5dc960610` : 1 000 graines au lieu de 200 pour le contrôle du portefeuille (raison technique) ; correction de l'heure écrite dans l'en-tête |
| Copies versionnées | `python-orchestrator/scripts/research_exec/prereg/scalp-q3-r2/` |
| Données | les neuf fenêtres d'entraînement r8 de 2025 (02-10..14, 03-10..14, 04-14..18, 05-12..16, 06-16..20, 07-14..18, 08-11..15, 09-15..19, 10-13..17), 20 paires, aggTrades et bookDepth Binance USD-M, soit 180 séries ; funding mensuel 2025-02..10 (180 archives, sha256 vérifiés, aucun trou) |
| Code | modules isolés `q3r2_engine.py`, `q3r2_portfolio.py`, `q3r2_stats.py`, `q3r2_run.py`, `q3r2_report.py` et la famille `S7L` dans `tick.py` ; `scalp_engine.py` et les règles existantes sont inchangés. Préparation au commit `93a00356`, analyse au commit `ebacec1b` |
| Résultats | `research/20261014T1200Z-scalp-q3-r2/run/results.json` (sha256 `75450192…`), tables `run/tables.md` |
| Ressources | nice 15, 2 workers ; préparation 58 min, analyse 20 min ; mémoire disponible jamais sous 7,5 Go |

**Configurations.** Toutes en long seul, entrée E1 (passive au bid, 250 ms de latence, 30 s de TTL,
règle `through`). Paramètres de prix inchangés : EMA 900 s, −1,5 σ, `fi_60 > 0`, `di_1 > 0,1`.

| ID | Signal | Volatilité |
|---|---|---|
| C1 | S7 : bande + flux | calendaire (`sigma_900`) |
| C2 | Q3 : bande + flux + profondeur | calendaire |
| C3 | S7L : bande + flux | active (`sigma_900_live`) |
| C4 | Q3L : bande + flux + profondeur | active |

Chaque configuration est testée avec deux sorties :

- **Z3**, sortie de référence non éligible : passive à l'ask à 3 600 s, taker à 3 900 s ;
- **Z3-SL200**, sortie protégée : Z3 plus un stop-market à −200 bp de l'entrée, armé dès le
  remplissage et exécuté 250 ms après le déclenchement, au plus bas du prix déclencheur, des
  transactions survenues pendant la latence et du bid.

Le seuil de −200 bp **vient de l'observation exploratoire q3x**. Une seule distance a été testée.

**Contrôles.**

- Contrôle principal par décalage d'épisodes : chaque épisode de signal est déplacé à la même heure
  UTC, dans la même série et avec la même durée ; 1 000 graines.
- Contrôle de l'état de bande sans flux, pour H1 : mêmes états de bande (et de profondeur), mais
  `fi_60 ≤ 0`, stratifiés par heure × déviation normalisée × liquidité ; 1 000 graines.
- Contrôle historique à 10 graines, pour la continuité avec q3x.

Les intervalles de confiance sont des IC 90 % par bootstrap en blocs de jours UTC : les 45 jours, toutes
paires d'un même jour ensemble. Les neuf fenêtres ne sont pas traitées comme neuf régimes
indépendants. On donne aussi, en sensibilité, l'IC par blocs de fenêtres.

## 2. Parité avec Q3 historique (règle d'arrêt §10.1)

| Config. | Trades | Net moyen | Identique à q3x, ligne à ligne | Contrôle historique médian | q3x |
|---|---|---|---|---|---|
| C1 S7 | 1 548 | +12,21 bp | oui | −18,152 | −18,152 |
| **C2 Q3** | **1 130** | **+12,74 bp** | **oui** | −16,095 | −16,095 |
| C3 S7L | 746 | +16,40 bp | (nouvelle règle) | −14,226 | — |
| C4 Q3L | 512 | +17,81 bp | oui | −13,808 | −13,808 |

La table de résultats par seconde, rejouée avec la règle « une position par paire », reproduit
`scalp_engine.run_policy` sans aucun écart, trades et compteurs compris, sur 180 séries × 4
configurations. La parité a été vérifiée avant toute autre mesure (`PARITY-CHECK.md`).

## 3. Les quatre configurations, avec et sans stop (niveau signal)

Niveau signal = rejeu historique avec une position par paire, sans contrainte de capital, en bp par
trade, coûts de base (maker 2 bp, taker 5 bp).

| Configuration | Ordres | Rempl. | Trades | Net moyen | Médiane | PF | Contrôle épisodes | Surplus [IC 90 %] | p | Contrôle bande sans flux | Surplus du flux | p flux |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| C1 S7 cal. · Z3 (réf.) | 2 097 | 74 % | 1 548 | +12,2 | +8,9 | 1,38 | −17,6 | +29,9 [+21,7 ; +38,1] | 0,001 | +10,4 | +1,8 | 0,140 |
| C1 S7 cal. · SL200 | 2 136 | 74 % | 1 581 | +8,2 | +7,8 | 1,22 | −19,6 | +27,9 [+19,1 ; +36,7] | 0,001 | +6,2 | +2,0 | 0,140 |
| C2 Q3 cal. · Z3 (réf.) | 1 515 | 75 % | 1 130 | +12,7 | +8,4 | 1,40 | −17,3 | +30,0 [+20,8 ; +39,4] | 0,001 | +9,4 | +3,3 | 0,041 |
| **C2 Q3 cal. · SL200** | 1 534 | 75 % | 1 148 | **+9,9** | +7,8 | 1,28 | −18,9 | +28,9 [+19,2 ; +38,5] | 0,001 | +4,8 | **+5,1** | **0,005** |
| C3 S7 act. · Z3 (réf.) | 928 | 80 % | 746 | +16,4 | +8,4 | 1,52 | −17,7 | +34,1 [+23,5 ; +44,3] | 0,001 | +15,2 | +1,2 | 0,311 |
| C3 S7 act. · SL200 | 940 | 81 % | 758 | +8,9 | +6,1 | 1,23 | −19,9 | +28,8 [+17,5 ; +40,2] | 0,001 | +7,7 | +1,3 | 0,322 |
| C4 Q3 act. · Z3 (réf.) | 631 | 81 % | 512 | +17,8 | +10,8 | 1,56 | −17,7 | +35,5 [+23,2 ; +47,4] | 0,001 | +14,0 | +3,8 | 0,094 |
| **C4 Q3 act. · SL200** | 636 | 81 % | 517 | **+13,0** | +8,2 | 1,36 | −19,4 | +32,4 [+19,1 ; +45,0] | 0,001 | +7,6 | **+5,5** | **0,049** |

p = 0,001 est la plus petite valeur possible avec 1 000 graines : aucune graine de contrôle n'atteint
le candidat.

**Robustesse** (bp par trade) :

| Configuration | Pessimiste | Latence 1 s + pessimiste | Avec funding | Tronquée 5/5 | Sans les 5 % meilleurs | Majors | Mid-caps | Fév.–mai | Juin–oct. | IC surplus (blocs fenêtres) |
|---|---|---|---|---|---|---|---|---|---|---|
| C1 · SL200 | +5,4 | +5,9 | +8,3 | +6,0 | −5,9 | +6,8 | +9,7 | +12,0 | +5,6 | [+20,5 ; +36,1] |
| C2 · SL200 | +7,1 | +8,5 | +9,9 | +7,4 | −4,4 | +7,0 | +12,6 | +16,2 | +6,0 | [+22,8 ; +35,9] |
| C3 · SL200 | +6,1 | +5,2 | +9,0 | +6,0 | −6,0 | +7,3 | +12,9 | +13,3 | +5,9 | [+21,3 ; +37,1] |
| C4 · SL200 | +10,2 | +10,9 | +13,0 | +10,0 | −2,1 | +8,2 | +22,5 | +18,6 | +9,3 | [+25,2 ; +40,7] |
| C2 · Z3 (réf.) | +10,2 | +11,4 | +12,7 | +11,7 | −1,2 | +7,9 | +17,1 | +22,3 | +6,8 | [+24,4 ; +36,6] |
| C4 · Z3 (réf.) | +15,4 | +15,8 | +17,8 | +16,7 | +2,8 | +10,1 | +33,4 | +27,2 | +11,5 | [+27,4 ; +44,6] |

**Par fenêtre** (bp, configurations protégées) :

| Fenêtre | C1-SL | C2-SL | C3-SL | C4-SL |
|---|---|---|---|---|
| février | +3,9 | +21,1 | +16,7 | +32,4 |
| mars | +18,1 | +20,3 | +15,8 | +22,1 |
| avril | +16,8 | +12,7 | +10,5 | +12,0 |
| mai | +9,4 | +11,3 | +11,3 | +6,1 |
| juin | −12,5 | −7,8 | −11,3 | −0,2 |
| juillet | +6,9 | +14,3 | +10,0 | +16,8 |
| août | −4,3 | −0,5 | +2,0 | +6,7 |
| septembre | +8,2 | +6,8 | +1,9 | +4,5 |
| octobre | +35,3 | +23,6 | +31,0 | +23,3 |

**Par paire** (net moyen en bp, avec le nombre de trades) : C2-SL est positif sur 13 paires sur 20,
C4-SL aussi sur 13, C1-SL sur 16, C3-SL sur 14. Les négatifs persistants sont les plus liquides :

| Paire | C1-SL | C2-SL | C3-SL | C4-SL |
|---|---|---|---|---|
| BTC | −7,3 (87) | −8,0 (40) | −8,6 (81) | −8,0 (40) |
| BNB | −13,9 (127) | −9,7 (100) | −12,8 (93) | −8,0 (73) |
| XRP | +1,3 (72) | −9,1 (34) | +1,5 (66) | −7,2 (32) |
| ETH | +2,4 (43) | −7,3 (24) | +3,8 (40) | −7,4 (22) |

Le détail des 20 paires est dans `run/tables.md`. Aucune paire n'est retirée : ce serait une sélection
sur le PnL.

## 4. Stop −200 bp (H3)

| Signal | Stops | Part | Net moyen des stoppés | Mêmes ordres sans stop | Pertes évitées | Gains abandonnés | Gagnants interrompus | Slippage méd. / P90 / max | Coût du stop [IC 90 %] |
|---|---|---|---|---|---|---|---|---|---|
| C1 S7 cal. | 137 | 8,7 % | −215 | −152 | 2 879 bp | 11 485 bp | 16 | 2,6 / 16,7 / 142 bp | −4,0 [−7,2 ; −1,0] |
| C2 Q3 cal. | 88 | 7,7 % | −214 | −170 | 2 113 bp | 6 037 bp | 7 | 2,4 / 9,4 / 142 bp | −2,8 [−5,5 ; −0,4] |
| C3 S7 act. | 80 | 10,6 % | −214 | −141 | 1 240 bp | 7 088 bp | 10 | 2,8 / 16,3 / 142 bp | −7,5 [−12,2 ; −3,0] |
| C4 Q3 act. | 48 | 9,3 % | −214 | −164 | 1 014 bp | 3 412 bp | 5 | 2,3 / 8,4 / 142 bp | −4,8 [−8,6 ; −1,5] |

Lecture :

- Le stop coûte **de 2,8 à 7,5 bp par trade**, et les quatre IC excluent zéro.
- Les trades stoppés auraient fini en moyenne vers −141 à −170 bp sans stop : le stop vend plus bas
  que la sortie à l'heure.
- En contrepartie, il **coupe la queue gauche** :
  - la pire perte passe de −426 à −346 bp ;
  - le 1er centile passe de −277 à −215 bp (C2) ;
  - au portefeuille, le pire trade passe de 1,9 à 1,0 fois le risque nominal.
- Le slippage est petit en médiane, mais atteint **142 bp** dans le pire cas (un écart de prix).
- Les pertes au-delà de −346 bp ont disparu.

## 5. Queues de distribution

| Configuration | Q1 / Q5 / Q50 / Q95 / Q99 (bp) | Pire | MAE médiane | MAE < −100 / −200 bp | Plus longue série de pertes |
|---|---|---|---|---|---|
| C2 · Z3 (réf.) | −277 / −151 / +8 / +202 / +331 | −426 | −53 | 28 % / 8 % | 20 |
| C2 · SL200 | −215 / −209 / +8 / +204 / +334 | −346 | −53 | 28 % / 8 % | 24 |
| C4 · Z3 (réf.) | −286 / −165 / +11 / +235 / +334 | −415 | −58 | 29 % / 9 % | 12 |
| C4 · SL200 | −215 / −209 / +8 / +237 / +333 | −346 | −58 | 30 % / 9 % | 14 |

**Sans les 5 % meilleurs trades, les quatre configurations protégées sont négatives** (de −2,1 à
−6,0 bp) : le profit repose sur la queue droite. La moyenne tronquée des deux côtés (critère T9) reste
positive (C2-SL +7,4, C4-SL +10,0). Les MAE et MFE sont mesurés à la seconde, depuis les transactions.

## 6. Critères pré-enregistrés et verdict

| Critère | C1-SL | C2-SL | C3-SL | C4-SL |
|---|---|---|---|---|
| T1 ≥ 300 trades | ✔ | ✔ | ✔ | ✔ |
| T2 net ≥ +2 bp | ✔ | ✔ | ✔ | ✔ |
| T3 surplus, p ≤ 0,01 et borne basse de l'IC > 0 | ✔ | ✔ | ✔ | ✔ |
| T4 surplus du flux, p ≤ 0,05 | ✘ (0,14) | ✔ (0,005) | ✘ (0,32) | ✔ (0,049) |
| T5 portefeuille PnL > 0, ≥ 150 trades, p ≤ 0,05 | ✔ | ✔ | ✔ | ✔ |
| T6 pessimiste : signal > 0 et portefeuille > 0 | ✘ | ✔ | ✘ | ✔ |
| T7 latence 1 s : signal > 0 | ✔ | ✔ | ✔ | ✔ |
| T8 ≥ 60 % des paires et ≥ 5/9 fenêtres positives | ✔ | ✔ | ✔ | ✔ |
| T9 moyenne tronquée > 0, sans le meilleur jour > 0 | ✔ | ✔ | ✔ | ✔ |
| T10 DD ≤ 50 USDT, pire trade ≥ −2 × risque, pire jour ≥ −15 USDT | ✔ | ✔ | ✔ | ✔ |
| T11 remplissage ≥ 20 % | ✔ | ✔ | ✔ | ✔ |
| **Passe** | non | **oui** | non | **oui** |

Aucun critère fatal F1–F6 n'est touché. Par la règle figée : **`RESEARCH_CANDIDATE`**, et C2-SL et C4-SL
seraient transmises à la phase 2. Les deux sont du même signal (Q3), sous deux mesures de volatilité.
C4-SL passe T4 de justesse (p = 0,049).

## 7. Réponses aux sept questions

1. **S7 est-il meilleur que Q3 au niveau portefeuille ?** Non.
   - PnL à 500 USDT avec stop : S7 +3,16 USDT contre Q3 +21,68 USDT. La différence quotidienne est de
     −18,5 USDT, IC 90 % [−46,1 ; +8,2] : non résolue.
   - Différence de surplus au niveau signal : −1,0 bp [−4,6 ; +2,6].
   - En scénario pessimiste, S7 perd (−18,64 USDT) quand Q3 gagne (+6,59 USDT).
   - La profondeur n'est donc pas inutile au niveau portefeuille, mais l'écart n'est pas significatif.
2. **Le flux d'achats a-t-il un surplus sur un contrôle apparié au même état de bande ?** Faiblement.
   - À état de bande, déviation, liquidité et heure égaux, retirer la condition de flux ne coûte que
     +1,2 à +2,0 bp pour S7 et S7L, non significatif (p de 0,14 à 0,32).
   - Avec la profondeur, le surplus est de +3,3 à +5,5 bp (p de 0,005 à 0,094).
   - L'écart de « +9,6 bp » attribué au flux par q3x venait surtout de la **composition** des
     occasions comparées (déviation, liquidité, heure), pas du flux lui-même.
   - Le contrôle « bande sans flux » gagne déjà +4,8 à +15,2 bp, quand le hasard perd −17 à −20 bp.
     **L'essentiel de l'avantage tient à l'état de bande apparié**, pas au flux.
3. **La volatilité active améliore-t-elle la robustesse ?** Non : elle **sélectionne seulement moins
   de trades**, verdict de la règle pré-enregistrée dans les quatre comparaisons.
   - Trades −52 à −55 %.
   - Différence de surplus de +1,0 à +5,5 bp, avec des IC qui contiennent zéro.
   - Part des paires positives : 70 % contre 80 % (C3 contre C1), 65 % contre 65 % (C4 contre C2).
4. **Quel est le coût du stop ?**
   - De −2,8 bp (Q3) à −7,5 bp (S7L) par trade, tous les IC sous zéro.
   - Au portefeuille : Q3 passe de +30,70 à +21,68 USDT et Q3L de +29,44 à +26,74 USDT.
   - Gain du stop : la perte maximale par trade est divisée par environ 1,9 (1,0 fois le risque
     nominal au lieu de 1,9).
5. **Les pertes extrêmes rendent-elles la stratégie impraticable avec 500 USDT ?** Non, avec le stop :
   - pire trade −2,59 USDT ;
   - pire jour −10,42 USDT (2,1 %) ;
   - drawdown max 24,3 USDT (4,9 %) ;
   - série de pertes maximale de 9 trades (C2-SL).

   Le vrai risque n'est pas la perte extrême mais la **dépendance aux meilleurs trades** : sans les
   5 % meilleurs, le PnL de C2-SL tombe à −54 USDT.
6. **Reste-t-elle positive avec des hypothèses pessimistes ?**
   - Q3 (C2-SL) : oui, de justesse. Pessimiste : +7,1 bp et +6,59 USDT. Latence 1 s : +8,5 bp et
     +6,09 USDT.
   - Q3L (C4-SL) : oui, +10,2 bp et +17,11 USDT, puis +10,9 bp et +10,03 USDT.
   - S7 et S7L protégés : leur portefeuille passe en perte en pessimiste (−18,64 et −0,91 USDT).
7. **Avons-nous encore des données assez indépendantes ?** Partiellement.
   - Aucune période Binance 2023-2026 n'est vierge à tous les niveaux pour ces 20 paires.
   - Novembre 2024 n'a jamais été lu au niveau tick, mais ses prix ont été vus en bougies 4h (swing v2,
     day_trading et swing) ; il est aussi voisin de la semaine de décembre 2024 lue par r8b.
   - C'est une **validation crédible mais imparfaite**. Le test vraiment indépendant ne peut être que
     **prospectif sur OKX**, après fwd-okx-r1 (§9).

## 8. Ce que ces chiffres ne disent pas

- **Entraînement consommé.** Q3 a été choisie sur ces fenêtres (r8), le stop vient de leurs MAE (q3x),
  Q3L de leurs secondes calmes. Le surplus sur le hasard (+29 à +35 bp) reproduit celui de r3–r8 ; il
  ne prouve rien hors échantillon.
- **Historique hors échantillon déjà connu de Q3 long (r8b)** : janvier 2025 +18,6 bp ; décembre 2024
  +6,5 bp mais 9 paires positives sur 20. S7 sans profondeur a échoué en décembre 2025 sur les mid-caps
  (r7).
- **Contrôle principal plus fréquent que le candidat.** Les épisodes déplacés tombent moins souvent dans
  une période déjà occupée : environ 2 070 trades par graine contre 1 148 (C2-SL), et 1 096 trades par
  graine contre 549 au portefeuille.
  - La comparaison par trade (bp) n'en est pas faussée.
  - En revanche, le **p du portefeuille surestime l'écart** : à fréquence égale, la perte moyenne du
    contrôle serait d'environ −0,15 USDT par trade × 549 ≈ −83 USDT au lieu de −165, toujours loin
    sous +21,7 USDT. C'est une estimation post hoc, faite sur les moyennes.
  - Cette propriété n'avait pas été anticipée dans le pré-enregistrement. Elle est déclarée ici et ne
    change pas le verdict figé.
- **Carnet reconstitué.** Sur Binance, le bid/ask est reconstitué depuis les transactions : l'âge de
  cotation aux décisions est toujours d'environ 0 s, puisqu'une décision suit une transaction.
  Remplissages, prix de stop, MAE et MFE sont donc approximatifs. La règle `through` est pessimiste
  pour l'entrée, mais pas un gage d'exécution réelle.
- **Majors négatifs.** BTC, BNB et (pour Q3) ETH et XRP sont négatifs, comme dans q3x. La performance
  vient des mid-caps et de quelques majors (SOL, DOGE, LTC). Ce n'est pas une raison de retirer ces
  paires.
- **Mois négatifs.** Juin et août sont négatifs ou nuls pour C2-SL. La moitié février–mai est nettement
  meilleure que juin–octobre (+16,2 contre +6,0 bp).

## 9. Proposition de validation indépendante (figée avant résultat)

Cette proposition a été figée dans `PREREGISTRATION-scalp-q3-r2.md` §9 et dans
`DATA-EXPOSURE-scalp-q3-r2.md` §3, avant tout résultat.

**Inventaire.** J'ai vérifié chaque lecture : r1–r14, r8b, swing, swing v2 et look2026, regime, q3x,
fwd-okx-r1. Il ne reste **aucune** période Binance 2023-2026 jamais vue à aucun niveau pour ces 20
paires : les bougies 1m→4h ont tout couvert (day_trading et swing pour les majors, swing v2 et look2026
pour les mid-caps). Restent seulement des tranches **jamais lues au niveau tick**, dont la trajectoire
de prix a été vue en bougies par d'autres familles.

Novembre 2024 n'est donc **pas** un holdout vierge. C'est la tranche tick la moins contaminée : aucun
protocole tick ne l'a lue et aucun paramètre de Q3/S7 n'en vient. Trois limites sont déclarées : prix
vus en 4h (swing v2), proximité de la semaine 2024-12-09..13 lue par r8b, carnet reconstitué depuis les
transactions.

| Phase | Données | Lecture | Conditions de passage (toutes requises) |
|---|---|---|---|
| 2 — validation | Binance, novembre 2024, 20 paires, aggTrades + bookDepth, 2024-11-04..08, 11..15, 18..22, 25..29 | une fois, seulement pour C2-SL et C4-SL | ≥ 150 trades ; net > 0 ; PF ≥ 1,05 ; surplus sur le contrôle à décalage d'épisodes (1 000 graines) > 0 avec p ajusté par Holm ≤ 0,05 et borne basse de l'IC 90 % (jours) > 0 ; ≥ 50 % des paires avec trades positives et ≥ 10 paires ; net > 0 en scénario pessimiste ; moyenne tronquée 5/5 > 0 ; portefeuille 500 USDT : PnL > 0, DD ≤ 10 %, pire trade ≥ −2 × risque nominal |
| 3 — test final | OKX, **nouvelle** capture prospective à partir du 2026-11-02 au plus tôt (après fwd-okx-r1), quatre semaines, vraies cotations bid/ask avec tailles | une fois, seulement si la phase 2 passe | mêmes critères, ≥ 100 trades, frais OKX du projet |

**Règles :**

- Le pré-enregistrement de la phase 3 est figé **avant** la lecture de W1 de fwd-okx-r1
  (≥ 2026-10-24) et n'utilise aucun résultat de fwd-okx-r1.
- Les fenêtres W0, W1 et W2 ne sont jamais recyclées.
- Aucun seuil n'est assoupli en cas d'échec.
- Un candidat qui passe les trois phases reste une hypothèse de recherche, pas un GO de trading.

**Avis.** Les deux configurations transmises sont la même règle Q3 avec deux mesures de volatilité ;
Q3 long a déjà connu un échec de cohérence entre paires en décembre 2024. La phase 2 est justifiée,
mais un échec y serait l'issue la plus probable si l'avantage suit le régime. Elle n'est **pas lancée**
par cette mission : c'est la décision du propriétaire.

## 10. Écarts, incidents et divulgations

- **Amendement 1**, avant résultat : 1 000 graines au portefeuille au lieu de 200, raison technique. Il
  corrige aussi l'heure de l'en-tête (« ≈ 12:15Z » contre un gel réel à 12:05:40Z).
- **Essai de durée** sur une série (BTCUSDT février) avant l'amendement : seuls des comptages
  (statuts) ont été lus, aucun PnL.
- **Essai de bout en bout sur données synthétiques**, sans lien avec le marché, pour vérifier la chaîne
  d'analyse.
- **Correctif** (commit `d3b2f6b5`) : la comparaison de parité trie les trades dans l'ordre de q3x et
  compare la médiane du contrôle à la précision près de l'ordre des sommes. Aucun effet sur un
  résultat : il a été appliqué avant l'analyse.
- **Vues accidentelles pendant l'audit d'exposition**, déclarées dans `DATA-EXPOSURE` §4 :
  - l'agent d'audit a vu les chiffres de tête de `verdict.json` de r12 ;
  - il a vu le début du `run.log` de look2026 (métadonnées de comptage) ;
  - il a vu quelques volumes quotidiens d'altcoins OKX hors programme (`tape-okx-swing-v2-monthly`).

  Rien ne concerne Q3/S7 ni les périodes réservées.
- **Fréquence du contrôle de portefeuille** supérieure à celle du candidat (§8).
- **Diagnostic des sorties passives.** Sur C2-SL, 6 sorties sur 441 ont eu un volume `through`
  inférieur à la quantité ; non corrigé dans le PnL. Pour l'entrée, 13 remplissages sont partiels.
- **Données manquantes.** Aucun funding manquant, aucun jour de bande manquant.

## 11. Reproduction

```bash
R=/home/haythem/workspace/perso/tradingV3-private/research
P=$R/20261014T1200Z-scalp-q3-r2
V=$R/20261012T003000Z-explore-v2-runtime/bin/python
cd python-orchestrator
nice -n 15 $V -m scripts.research_exec.acquire fundingrate --out $P/funding-tape \
  --symbols BTCUSDT,ARBUSDT,ETHUSDT,OPUSDT,SOLUSDT,APTUSDT,XRPUSDT,SUIUSDT,DOGEUSDT,NEARUSDT,BNBUSDT,TIAUSDT,ADAUSDT,SEIUSDT,LTCUSDT,INJUSDT,LINKUSDT,ATOMUSDT,AVAXUSDT,FILUSDT \
  --dates 2025-02..2025-10 --workers 2
nice -n 15 $V -m scripts.research_exec.q3r2_run prepare --tape-dir $R/20261014T130000Z-tape-binance-q3x \
  --instruments $R/20261012T060000Z-execution-r1/instrument-assumptions-r8.json --funding-dir $P/funding-tape \
  --out $P/run --workers 2 --prereg $P/PREREGISTRATION-scalp-q3-r2.md
nice -n 15 $V -m scripts.research_exec.q3r2_run analyse --out $P/run --q3x-dir $R/20261014T130000Z-q3x --seeds 1000 --portfolio-seeds 1000
$V -m scripts.research_exec.q3r2_report --results $P/run/results.json > $P/run/tables.md
$V -m pytest tests/test_research_exec*.py --noconftest -q -p no:cacheprovider
```
