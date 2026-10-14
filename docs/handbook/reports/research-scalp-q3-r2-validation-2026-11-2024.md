# Scalping Q3 V2 (scalp-q3-r2) — validation unique sur novembre 2024 : `VALIDATION_FAIL`

**Verdict : `VALIDATION_FAIL` pour C4-SL** (Q3L, volatilité active, stop −200 bp), le seul candidat transmis par le
[gate corrigé](research-scalp-q3-r2-correction-2026-10-14.md). Sur 12 critères pré-enregistrés, 10 échouent.

C2-SL n'a pas été testé : le gate lui a donné le statut `STATISTICAL_BLOCKER`. S7, S7L, une autre distance de stop
ou de nouveaux seuils n'ont pas été testés non plus.

**L'échec est définitif pour cette version et ces paramètres.** Aucune paire n'a été remplacée, aucune fenêtre
déplacée, aucun stop modifié. La capture prospective OKX dédiée à Q3 V2 n'est **pas** préparée.

## 1. Cadre

| Élément | Valeur |
|---|---|
| Protocole | pré-enregistrement §9, avec l'amendement 2 (sha256 `3d061fb4…2b49`, gelé à 14:11:01Z, commit `2d987d15`). C'est une **validation sous protocole amendé** : le contrôle principal est redéfini |
| Gate | `c2/GATE.json` (sha256 `f8247013…`, commit `faf2e8f1`) : `GATE_PASS`, transmis = C4 |
| Lecture unique | `run-nov/validation-claim.json` écrit à 15:45:29Z, avant tout téléchargement (sha256 `63dcb629…`). Le code refuse une seconde analyse |
| Données | Binance USD-M, 20 paires ; aggTrades (400 fichiers) et bookDepth (400 fichiers) des fenêtres 2024-11-04..08, 11..15, 18..22, 25..29 ; funding 2024-11 (20 fichiers). Tous les sha256 vérifiés contre les `.CHECKSUM` publiés, 0 échec, aucun jour manquant. Acquis de 15:45 à 16:10Z dans `tape-nov2024/` et `funding-tape-nov/` |
| Calcul | préparation de 80 séries (16:10–16:50Z), puis analyse unique à 16:52:54Z, 1 000 graines. Sortie `run-nov/results-c2.json` (sha256 `77988ec9…`) |
| Contamination partielle | les prix de novembre 2024 avaient déjà été vus en bougies 4h (swing v2 pour les mid-caps, day_trading et swing pour les majors). Les données tick et profondeur n'avaient jamais été lues. La semaine de décembre 2024 voisine avait été lue par r8b |

## 2. Résultats de C4-SL

### Niveau signal (une position par paire)

| Mesure | Novembre 2024 | Entraînement (rejeu corrigé) |
|---|---|---|
| Ordres / trades (remplissage) | 381 / 317 (83 %) | 636 / 517 (81 %) |
| Net moyen | **−20,7 bp** | +13,0 bp |
| Brut moyen / frais | −15,8 / 4,9 bp | +17,6 / 4,6 bp |
| Médiane | −7,8 bp | +8,2 bp |
| Taux de gain | 47 % | 54 % |
| PF | **0,71** | 1,36 |
| Pessimiste / latence 1 s | −24,0 / −22,7 bp | +10,2 / +10,8 bp |
| Moyenne tronquée 5/5 | −27,5 bp | +10,0 bp |
| Sans les 5 % meilleurs / pires | −38,1 / −9,7 bp | −2,1 / +25,3 bp |
| Somme des 5 % meilleurs / pires | +4 908 / −3 623 bp | +7 757 / −5 681 bp |
| Pire / meilleur trade | −290 / +580 bp | −346 bp / — |
| Stops | 79 (25 %) | 48 (9 %) |
| Plus longue série de pertes | 21 | 14 |

### Contre les comparateurs (niveau signal, 1 000 graines, IC 90 % par blocs de 20 jours)

| Comparateur | Moyenne témoin | Trades par graine (écart) | Surplus [IC 90 %] | p | p Holm |
|---|---|---|---|---|---|
| **Principal : grappes** | −8,1 bp | 306 (−3,5 %) | **−12,5 [−29,2 ; +7,7]** | 0,80 | 0,80 |
| Secondaire : épisodes | −9,3 bp | 641 (+102 %) | −11,3 [−29,8 ; +10,3] | 1,00 | — |
| Informatif : bande sans flux | −4,7 bp | 303 (−4,4 %) | −16,0 [−23,5 ; −6,8] | 1,00 | — |

Le signal fait **moins bien que le hasard** et nettement moins bien qu'une simple entrée après une déviation
comparable sans flux. Le contraste avec l'entraînement est net : +30,5 bp contre le contrôle par grappes, +5,5 bp
contre la bande sans flux.

### Portefeuille 500 USDT

| Mesure | Base | Pessimiste | Latence 1 s |
|---|---|---|---|
| PnL net | **−20,22 USDT** | −25,47 | −22,93 |
| Trades / ordres | 174 / 210 | 172 / 205 | 151 / 297 |
| Refus : slots / risque | 190 / 4 | 187 / 4 | 177 / 2 |
| PF | 0,82 | 0,78 | 0,77 |
| Drawdown max (MTM) | **59,18 USDT (11,4 %)** | 62,21 | 49,23 |
| Pire trade (× risque nominal) | −2,39 (1,00) | −2,39 (1,03) | −2,60 (1,07) |
| Pire jour | −10,68 | −10,97 | −9,37 |
| Frais / slippage / funding | 8,60 / 0 / +0,30 | 12,36 / 1,32 / +0,27 | 10,88 / 1,17 / +0,26 |
| Stops au portefeuille (PnL) | 36 (−79,2) | 36 (−79,1) | 31 (−69,1) |
| Somme des 5 % meilleurs / pires trades | +31,6 / −21,3 | | |
| PnL sans les 5 % meilleurs | −51,8 | | |
| Positions ouvertes moyenne / max | 0,33 / 2 | | |
| Capital engagé moyen / max | 33,8 / 227,0 USDT | | |
| Capital disponible minimal | 257,1 USDT | | |
| Jours négatifs | 9 sur 20 (meilleur jour +5,7, pire −10,7) | | |

**Contrôles au portefeuille (base) :**

| Comparateur | PnL moyen | Trades | Surplus moyen | IC 90 % | p | Écart trades / temps / exposition |
|---|---|---|---|---|---|---|
| Grappes | −13,6 | 151 | −6,6 | [−38,1 ; +25,5] | 0,65 | −13 / −11 / −11 % |
| Épisodes | −28,3 | 395 | +8,1 | [−32,5 ; +48,3] | 0,34 | +127 / +132 / +131 % |
| Bande sans flux | −8,1 | 166 | −12,1 | [−34,1 ; +8,8] | 0,89 | −4 / −3 / −4 % |

En scénario pessimiste, le contrôle par grappes perd −17,6 USDT ; le surplus est de −7,9, avec p = 0,67.

### Par semaine (net moyen en bp, trades ; PnL du portefeuille)

| Semaine | Signal | Portefeuille |
|---|---|---|
| 04–08/11 | +37,5 (35) | +15,40 |
| 11–15/11 | **−58,8 (146)** | **−40,99** |
| 18–22/11 | −12,9 (80) | −12,78 |
| 25–29/11 | +31,1 (56) | +18,15 |

La semaine du 11 au 15 novembre 2024 porte à elle seule près de la moitié des trades, et la perte.

### Par paire (net moyen en bp, trades)

**5 paires positives sur 20** : BTC +27,5 (19), ETH +14,0 (19), FIL +29,0 (15), LTC +6,1 (14), SUI +19,6 (22).

Les 15 autres sont négatives :

| Paire | Net moyen (trades) |
|---|---|
| OP | −162,0 (9) |
| NEAR | −82,5 (11) |
| ADA | −76,0 (12) |
| TIA | −56,4 (14) |
| XRP | −42,5 (11) |
| SOL | −31,7 (21) |
| DOGE | −30,7 (7) |
| LINK | −30,3 (18) |
| AVAX | −28,4 (20) |
| ARB | −26,7 (22) |
| SEI | −24,3 (12) |
| ATOM | −23,2 (9) |
| BNB | −15,7 (36) |
| INJ | −7,3 (9) |
| APT | −2,8 (17) |

C'est l'inverse de l'entraînement, où les mid-caps portaient le résultat et BTC/BNB perdaient.

### Stop

- 79 stops (25 % des trades, contre 9 % à l'entraînement), tous au niveau signal.
- Les mêmes ordres sans stop auraient fini en moyenne à −207 bp. Le stop a donc évité 4 827 bp de pertes et
  abandonné 5 295 bp de gains : il est presque neutre ce mois-ci.
- Au portefeuille, les stops coûtent −79,2 USDT.

## 3. Critères pré-enregistrés

| # | Critère | Valeur | Résultat |
|---|---|---|---|
| V1 | ≥ 150 trades (niveau signal) | 317 (174 au portefeuille) | ✔ |
| V2 | net moyen > 0 | −20,7 bp | ✘ |
| V3 | PF ≥ 1,05 | 0,71 | ✘ |
| V4 | surplus sur le contrôle principal > 0 | −12,5 bp | ✘ |
| V5 | p Holm ≤ 0,05 | 0,80 | ✘ |
| V6 | borne basse de l'IC 90 % > 0 | −29,2 bp | ✘ |
| V7 | ≥ 50 % des paires positives et ≥ 10 paires | 5 / 20 | ✘ |
| V8 | net pessimiste > 0 | −24,0 bp | ✘ |
| V9 | moyenne tronquée > 0 | −27,5 bp | ✘ |
| V10 | PnL du portefeuille > 0 | −20,22 USDT | ✘ |
| V11 | DD ≤ 10 % (50 USDT) | 59,18 USDT | ✘ |
| V12 | pire trade ≥ −2 × risque nominal | 1,00 × | ✔ |

**`VALIDATION_FAIL`.** Le résultat ne dépend pas du choix du contrôle : il échoue sur des critères absolus (net,
PF, portefeuille, DD, paires) autant que relatifs. Il échouerait aussi avec l'ancien contrôle.

## 4. Lecture

- **La validation ne confirme pas l'entraînement.** Sur l'entraînement, Q3L protégé gagnait +13 bp par trade et
  battait le hasard de +30 bp. En novembre 2024, il perd −21 bp et fait moins bien que le hasard (−12 bp) et
  qu'une entrée sur simple déviation (−16 bp).
- **Historique hors entraînement de la famille Q3 long** :
  - janvier 2025, r8b (C2 sans stop) : +18,6 bp ;
  - décembre 2024, r8b : +6,5 bp, 9 paires positives sur 20 ;
  - novembre 2024 (C4-SL) : −20,7 bp, 5 paires sur 20.

  Le signe suit le régime. Novembre 2024 est un mois de forte tendance haussière, et la semaine du 11 au 15 domine
  la perte. Ce n'est pas une explication causale démontrée.
- **Robustesse aux coûts.** Ce n'est pas la question ici : le résultat est négatif **avant** les frais (brut
  −15,8 bp).
- **Concentration.** Même dans l'échec, les 5 % meilleurs trades valent +4 908 bp. Sans eux, la moyenne tombe à
  −38 bp.

## 5. Conséquences

- Q3 V2, dans ses deux formes protégées (C2-SL, C4-SL), est **clos** pour ces paramètres.
- Aucune nouvelle capture OKX n'est lancée ni préparée pour Q3 V2 : la phase F (§9) ne s'applique qu'à une
  validation réussie.
- `fwd-okx-r1` continue sans changement (règle Q3 sans stop, protocole propre). Son verdict reste à lire selon son
  propre pré-enregistrement. Aucun résultat de cette validation ne doit servir à le modifier.
- Novembre 2024 est désormais **consommé** au niveau tick et profondeur, pour ces 20 paires. Toute campagne
  future doit le déclarer.
