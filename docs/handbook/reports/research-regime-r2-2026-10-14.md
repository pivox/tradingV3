# Détecteurs de régime LONG / SHORT / NEUTRE (regime-r2) — 2026-10-14

**Statut : entraînement terminé, aucun détecteur ne passe Q1 et Q2. Validation (2023-2024) et test
final (2025-2026) non téléchargés, non lus. Clôture suspendue à l'audit technique de la couche Q2
(§6), décidé par le propriétaire.**

## 1. Question

> Si TradingV3 avait utilisé ce détecteur, avec uniquement les informations connues à chaque date,
> aurait-il évité suffisamment de mauvais trades pour améliorer son rendement net et réduire son
> drawdown ?

Deux jugements séparés : **Q1** (le détecteur choisit-il mieux que des références ?) et **Q2**
(améliore-t-il un portefeuille après coûts ?). Seul un détecteur qui passe les deux est candidat.

## 2. Protocole et traçabilité

| Élément | Valeur |
|---|---|
| Pré-enregistrement | `regime-r2`, sha256 `15ccbdb5189a7fa733ec0bb9b1d1ada2d1b8e1a11e75dd2398897097518bdf44`, figé 2026-10-14T09:51:18Z |
| Version précédente conservée | `regime-r1`, sha256 `72a78d507d5ac761c6fb072262bcbd5f98228fbac7a4a566c9f3fc1b3eca4480` |
| Annexes | A (169 symboles exclus), B (identités `symbol → asset_id`) ; sha256 dans `PREREGISTRATION-regime-r2.sha256` |
| Fichiers | `python-orchestrator/scripts/research_regime/prereg/` (V1, V2, annexes, diff V1/V2) |
| Commit des pré-enregistrements | `45873eb4` ; moteur et tests `631655cf` |
| Données | data.binance.vision, spot 1d 2017-08 → 2023-01, perpétuels USD-M 1d et funding 2020-01 → 2023-01 ; 355 séries spot, 160 perpétuels, 159 historiques de funding, 0 erreur sha256 ; aucune donnée postérieure à 2023-01 téléchargée |
| Résultat archivé | `tradingV3-private/research/20261014T120000Z-regime-r2-results/training.json`, sha256 `b715bde0…a414a88` (lecture seule) |
| Tests | 16 tests (anticipation sur 3 dates, préchauffages, segments, annexe B, univers à date, rendements, contrôle de Markov, bootstrap, coûts et funding, pipeline complet synthétique) |

Univers : 355 actifs spot (symbole × segment) ; segments coupés pour LUNAUSDT, NBTUSDT, VIDTUSDT
(interruption > 7 jours) ; jours masqués par l'annexe B : COCOS 1 257, DREP 1 109, SUN 860,
LUNA 245 (après le 2022-05-31), VEN 1.

## 3. Résultats d'entraînement (panier U20)

Q1 : score moyen sur 14 jours des décisions non NEUTRE, en points de base. Surplus = score − médiane
de 1 000 tirages aléatoires appariés (fréquence et persistance). IC à 90 % par blocs de 30 jours.

| Détecteur | Fenêtre | Score | Surplus | p | IC 90 % du surplus | Toujours LONG / tendance simple | Années surplus > 0 (≥ 60 j) | Q1 |
|---|---|---|---|---|---|---|---|---|
| D1 MA200 ± 2 % | 2018-03-05 → 2022 | +78 | +41 | 0,225 | [−160 ; +247] | +140 / +73 | 2 / 5 | ❌ |
| D2 momentum 4/12 sem. | 2018-03-01 → 2022 | +199 | +131 | 0,007 | [−71 ; +346] | +140 / +73 | 2 / 5 | ❌ |
| D3 largeur U50 | 2018-03-05 → 2022 | +224 | +194 | < 0,001 | [−27 ; +423] | +140 / +73 | 3 / 5 | ❌ |
| D4 funding contrarien | 2021-01-08 → 2022 | −1 046 | −802 | 1,000 | [−1 272 ; −279] | +48 / +170 | 0 / 2 | ❌ |
| D5 volatilité + D2 | 2018-09-16 → 2022 | +382 | +310 | < 0,001 | **[+16 ; +613]** | +213 / +123 | 4 / 5 | ✅ |
| D6 MA200 ∧ largeur | 2018-03-05 → 2022 | +237 | +191 | < 0,001 | [−27 ; +416] | +140 / +73 | 4 / 5 | ❌ |

Q2 : 14 tranches, une ouverte chaque jour pour 14 jours, panier U20 en poids égaux, sans levier,
notionnel fixe (voir §5, écart 3) ; fenêtre de décision 2020-02-01 (ou plus tard) → 2022-12-31.
Coûts normaux ; les coûts pessimistes donnent les mêmes verdicts.

| Détecteur | Net | Drawdown max | Calmar | Exposition | Aléatoire médian / p | Toujours LONG net / DD / Calmar | Q2 |
|---|---|---|---|---|---|---|---|
| D1 | +54,9 % | 98,1 % | 0,19 | 97 % | +36,4 % / 0,35 | +90,0 % / 157,8 % / 0,19 | ❌ |
| D2 | +69,1 % | 60,1 % | 0,39 | 64 % | +38,1 % / 0,09 | idem | ✅ |
| D3 | +133,9 % | 73,0 % | 0,62 | 91 % | +23,7 % / < 0,01 | idem | ✅ |
| D4 | −19,3 % | 37,1 % | −0,26 | 11 % | −6,1 % / 0,89 | −16,9 % / 167,9 % / −0,05 | ❌ |
| D5 | +31,8 % | 49,2 % | 0,22 | 47 % | +26,7 % / 0,34 | +79,9 % / 165,0 % / 0,16 | ❌ |
| D6 | +80,7 % | 69,3 % | 0,39 | 63 % | +29,7 % / 0,04 | +90,0 % / 157,8 % / 0,19 | ✅ |

Par grande phase (surplus Q1, bp) : la hausse 2020-03 → 2021-11 porte l'essentiel des surplus
(D2 +337, D3 +475, D5 +612, D6 +495) ; 2022 est négative pour D2, D3, D5 et D6 ; 2018 est positive
pour D1 et D5 (et à peine pour D6, +7,5).

Horizons secondaires (information) : D5 surplus +164 bp à 7 jours et +745 bp à 30 jours (p < 0,01) ;
D2, D3, D6 positifs aux deux horizons.

BTC seul (information) : D2 et D5 passent Q1 sur BTC ; D2 passe Q2 sur BTC (+109 %, DD 54 %).

## 4. Verdict pré-enregistré

- **Q1 et Q2 à la fois : aucun.** D5 passe Q1 et échoue Q2 (gain non distinct du hasard, p 0,34) ;
  D2, D3, D6 passent Q2 et échouent Q1 (borne basse de l'IC du surplus négative ; D2 et D3 aussi
  instables par année).
- Règle d'arrêt : aucune sélection ; validation et test final non lus.
- Lecture selon la grille déclarée : D5 = « pouvoir prédictif sans gain exploitable après coûts :
  recherche seulement ». D2, D3, D6 : gains de trading concentrés sur la hausse 2020-2021, pouvoir
  prédictif non démontré une fois la dépendance temporelle prise en compte (le p contre le hasard
  était trompeur, l'IC par blocs inclut zéro).

## 5. Écarts au protocole (déclarés, non masqués)

1. **D4-OI non calculée.** Variante secondaire, non sélectionnable ; les archives quotidiennes
   d'open interest (~25 000 fichiers) n'ont pas été téléchargées.
2. **Funding manquant compté à zéro** dans la couche Q2, contrairement au §3 (« aucune valeur
   manquante n'est reconstituée »). 5 438 jours-actif sans règlement alors qu'un prix de
   perpétuel existe (ou est reporté) : 4 015 sont des jours après le retrait de la paire (prix
   reporté, aucune entrée possible), 1 423 ont un vrai prix (2020 : 12, 2021 : 426, 2022 : 923,
   2023 : 62). **13 entrées sur 20 300 du panier** ont un tel jour dans leur fenêtre de 14 jours.
   Mesure de sensibilité : §6.
3. **Drawdowns supérieurs à 100 %.** La couche Q2 valorise à notionnel fixe (capital 1, tranches de
   1/14, sans capitalisation) : les pertes cumulées peuvent dépasser le capital, ce qu'un
   portefeuille autofinancé ne peut pas faire. Les ratios de Calmar comparent des flux de PnL
   théoriques, pas un portefeuille exécutable. Audit : §6.
4. **Correspondance spot → perpétuel par symbole exact.** Les perpétuels « 1000xxx » (ex.
   1000SHIBUSDT) ne sont pas rattachés à leur spot : ces actifs sont absents de la couche Q2.

## 6. Audit technique de Q2 (décidé par le propriétaire avant clôture)

Sans toucher aux détecteurs ni à leurs paramètres, et sans accès à 2023-2026 :

- sensibilité au funding manquant, sans inventer de taux ;
- audit du sizing, de la trésorerie et de la valorisation (portefeuille autofinancé, drawdown en %
  de la valeur de pointe) ;
- si une correction change un résultat : un seul rejeu correctif de l'entraînement, versionné et
  documenté, le premier résultat restant archivé avec son empreinte.

Résultats de l'audit : à compléter dans ce rapport.
