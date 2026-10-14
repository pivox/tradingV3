# Pré-enregistrement regime-r2 — détecteurs de régime LONG / SHORT / NEUTRE

Rédigé le 2026-10-14 dans la session tradingv3-db, sur GO du propriétaire du même jour, avant tout
téléchargement de prix et avant tout calcul de performance. Seules des listes de fichiers
(data.binance.vision, sans contenu) ont été consultées pour fixer les sources (§3) et les annexes.

**Version 2.** Remplace regime-r1 (sha256 72a78d507d5ac761c6fb072262bcbd5f98228fbac7a4a566c9f3fc1b3eca4480,
conservé intact comme trace). Modifications, toutes décidées par le propriétaire avant toute lecture de
prix : (1) §3, suppression de la séparation d'actifs par variation de prix et table d'identité
(annexe B) ; (2) §5, préchauffage propre à chaque détecteur, dont D5 (≈ 395 jours) et premières dates
éligibles ; (3) §3-§4, listes d'exclusion figées en annexe A ; (4) §6-§8, intervalle de confiance du
surplus apparié et critère de sélection Q1 sur sa borne basse. Aucun prix n'a été lu, aucune
performance n'a été calculée pour cette version.

Annexes figées avec ce document (leurs sha256 sont dans `PREREGISTRATION-regime-r2.sha256`) :
`ANNEX-A-exclusions.md` (stablecoins, actifs indexés, actions tokenisées, tokens à levier, symboles
non ASCII) et `ANNEX-B-symbol-identity.md` (table `symbol → asset_id`).

## 0. Question

> Si TradingV3 avait utilisé ce détecteur, avec uniquement les informations connues à chaque date,
> aurait-il évité suffisamment de mauvais trades pour améliorer son rendement net et réduire son
> drawdown ?

Deux résultats distincts sont produits et jugés séparément :

- **Q1 — prédiction** : le détecteur choisit-il mieux que les références ? (couche A, §6)
- **Q2 — trading** : améliore-t-il un portefeuille après coûts ? (couche B, §7)

Un détecteur peut réussir Q1 et échouer Q2 ; seul un détecteur qui réussit les deux est candidat.

Hors périmètre : l'application aux signaux B120 et r8b (campagne séparée, pré-enregistrée à part,
seulement si un détecteur est candidat ; elle ne réhabilite pas ces stratégies), la modification de
la plateforme, tout ordre réel.

## 1. Exposition déclarée

- 2023–2026 a déjà été vu comme marché par le programme (r1–r14, swing, swing v2) ; aucun détecteur
  de ce document n'y a été mesuré. 2025–2026 n'est **pas** un holdout vierge : le pré-enregistrement
  améliore la crédibilité, il n'efface pas cette exposition.
- Les grandes phases 2017–2022 sont connues de l'auteur ; elles servent seulement au découpage du
  rapport (§9), jamais à une décision.
- Les paramètres des détecteurs (§5) sont des valeurs usuelles fixées ici, sans optimisation.

## 2. Phases

| Phase | Fenêtre de décision (UTC) | Usage |
|---|---|---|
| Entraînement | 2018-03-01 → 2022-12-31 | sélection (≤ 2 détecteurs) |
| Validation | 2023-01-01 → 2024-12-31 | lue une fois, détecteurs sélectionnés seulement |
| Holdout | 2025-01-01 → 2026-10-13 | lu une fois, détecteurs validés seulement |

L'entraînement commence le 2018-03-01 : 200 jours de préchauffage après le début des archives spot
(2017-08-17). Un détecteur dont les données démarrent plus tard a sa propre fenêtre (§3, §5).

## 3. Données (archives publiques data.binance.vision, sha256 vérifiés, fail-closed)

| Donnée | Source | Disponible depuis | Usage |
|---|---|---|---|
| Bougies journalières spot USDT | `spot/monthly/klines/<SYM>/1d`, **paires retirées incluses** (ex. BCCUSDT 2017-11→2018-11) | 2017-08 | prix, volumes, univers (couches A et B) |
| Bougies journalières perpétuels USD-M | `futures/um/monthly/klines/<SYM>/1d` | 2020-01 | couche B (prix d'exécution) |
| Funding USD-M | `futures/um/monthly/fundingRate/<SYM>` | 2020-01 | détecteur D4, coûts couche B |
| Métriques USD-M (open interest) | `futures/um/daily/metrics/<SYM>` | 2020-09 | variante D4-OI (secondaire) |

Règles :

- Aucune valeur manquante n'est reconstituée. Un jour sans bougie rend l'actif inéligible ce jour-là
  (aucune décision, aucun rendement futur calculé à travers ce jour).
- **Interruptions et symboles réutilisés.**
  - Une interruption de cotation supérieure à 7 jours sépare deux segments historiques et impose un
    nouveau préchauffage de tous les indicateurs. Une interruption de 1 à 7 jours rend seulement les
    jours manquants inéligibles.
  - Une interruption ne signifie pas que l'actif a changé d'identité.
  - Les réutilisations connues de symboles sont identifiées par la table `symbol → asset_id` de
    l'annexe B, avec dates et sources, figée avant les calculs.
  - **Aucun seuil de variation de prix ne peut déclencher une séparation d'actifs.** Les variations
    extrêmes réelles, y compris les effondrements de plus de 95 %, sont conservées.
  - Si l'identité historique d'un symbole reste ambiguë, il est exclu sur la période concernée et
    l'exclusion est documentée (annexe B), sans consulter son PnL.
- Stablecoins, monnaies fiat, actifs indexés (or, actifs « wrapped » ou de staking), actions et ETF
  tokenisés, tokens à levier et symboles non ASCII sont exclus selon la liste exacte de l'annexe A.
  Le code lit ces listes depuis les annexes figées ; il ne peut pas en ajouter ni en retirer.
- Toutes les décisions utilisent la bougie **fermée** de la veille ; la décision du jour J est
  connue à J 00:00 UTC et appliquée au prix d'ouverture de J.

## 4. Univers à chaque date (sans biais de survivance)

- **U50(t)** : les 50 paires spot USDT éligibles (hors annexe A et périodes exclues par l'annexe B ;
  ≥ 200 jours d'historique continu dans le segment courant) ayant le plus
  fort volume quote médian sur les 30 jours précédant t. Recalculé le 1er de chaque mois, à partir
  des seules données connues à cette date, paires retirées incluses.
- **U20(t)** : les 20 premières de U50(t). Univers de trading de la couche B ; en couche B, une paire
  de U20 n'est tradée que si son perpétuel USD-M existe à la date (sinon elle sort du panier ce
  mois-là, sans substitution).
- **BTC** : BTCUSDT seul, en plus du panier.

## 5. Détecteurs (paramètres fixés, aucune optimisation)

Sortie quotidienne par actif : LONG, SHORT ou NEUTRE.

| Id | Famille | Règle |
|---|---|---|
| D1 | Moyenne mobile 200 j | close > SMA200 × 1,02 → LONG ; close < SMA200 × 0,98 → SHORT ; sinon NEUTRE |
| D2 | Momentum 4/12 semaines | rendements 28 j et 84 j tous deux > 0 → LONG ; tous deux < 0 → SHORT ; sinon NEUTRE |
| D3 | Largeur de marché | part de U50(t) au-dessus de sa SMA50 : > 60 % → LONG (tous actifs) ; < 40 % → SHORT ; sinon NEUTRE |
| D4 | Funding (positionnement, contrarien) | moyenne 7 j du funding de l'actif, z-score sur 365 j : z > +1,5 → SHORT ; z < −1,5 → LONG ; sinon NEUTRE. Fenêtre propre : décisions à partir du 2021-01-01 (365 j de préchauffage) |
| D4-OI | Variante secondaire, non sélectionnable | D4 et variation 14 j de l'open interest de même signe que la foule ; décisions à partir du 2021-09-01 ; rapportée seulement |
| D5 | Volatilité | volatilité réalisée 30 j au-dessus du 80e percentile de ses 365 derniers jours → NEUTRE ; sinon direction de D2 |
| D6 | Combinaison prédéfinie | D1 et D3 d'accord → ce côté ; sinon NEUTRE |

**Préchauffage et premières dates éligibles.** Chaque détecteur n'émet une décision pour un actif
qu'une fois son historique nécessaire disponible dans le segment courant ; avant, l'actif est
inéligible pour ce détecteur (ni décision, ni valeur de remplacement). Le comparateur aléatoire et
les comparateurs déterministes sont évalués sur exactement les mêmes (actif, date) éligibles.

| Détecteur | Historique requis par actif | Première date de décision possible (archives spot depuis 2017-08-17, funding depuis 2020-01-01) |
|---|---|---|
| D1 | 200 jours (SMA200) | 2018-03-05 |
| D2 | 84 jours (rendement 12 semaines) | 2017-11-09 ; entraînement à partir du 2018-03-01 |
| D3 | U50(t) : 200 jours par paire ; SMA50 | 2018-03-05 |
| D4 | 7 jours (moyenne) + 365 jours (z-score) de funding | 2021-01-08 |
| D4-OI | D4 + 14 jours d'open interest (archives depuis 2020-09-01) | 2021-09-01 (rapportée seulement) |
| D5 | 30 jours (volatilité réalisée) + 365 jours (percentile) ≈ 395 jours | 2018-09-16 |
| D6 | max(D1, D3) | 2018-03-05 |

Les fenêtres d'entraînement effectives sont donc : D1, D3, D6 à partir du 2018-03-05 ; D2 à partir
du 2018-03-01 ; D5 à partir du 2018-09-16 ; D4 du 2021-01-08 au 2022-12-31 (puissance plus faible,
déclarée). Les phases de validation et de holdout sont communes.

## 6. Couche A — prédiction (Q1)

- **Horizon principal : 14 jours.** Rendement futur r14(t) = close(t+14) / open(t) − 1 (spot). 7 et
  30 jours : analyses secondaires, jamais utilisées pour une sélection ou un verdict.
- **Score** d'un détecteur sur un univers : moyenne, sur toutes les (actif, date) où il n'est pas
  NEUTRE, de s(t) × r14(t), avec s = +1 (LONG) ou −1 (SHORT), en poids égaux par date (moyenne
  transversale du jour, puis moyenne des jours).
- **Valeur du NEUTRE** : écart de |r14| et de r14 signé par le marché entre les jours NEUTRE et les
  autres, rapporté (pas de gate).
- **Comparateurs** (même univers, mêmes dates, même calcul) :
  1. **aléatoire** : 1 000 signaux tirés par une chaîne de Markov à trois états ayant, par actif,
     la même fréquence de chaque état et la même durée moyenne de séjour que le détecteur ;
  2. **toujours LONG** ; 3. **toujours SHORT** ;
  4. **tendance simple** : signe de close − SMA200, sans zone neutre.
- **Surplus** = score du détecteur − médiane des tirages aléatoires ; p empirique = part des tirages
  ≥ score.
- **Intervalles** (bootstrap circulaire par blocs de 30 jours, 10 000 rééchantillonnages, IC à 90 %) :
  1. **IC du score brut**, sur la série quotidienne du score (moyenne transversale du jour) ;
  2. **IC du surplus**, sur la série quotidienne appariée du surplus : pour chaque jour, score du
     détecteur ce jour-là moins la moyenne, sur les 1 000 tirages, du score aléatoire ce même jour.
     Le rééchantillonnage tire des blocs de jours entiers : toutes les cryptos d'un même jour, le
     détecteur et ses contrôles restent ensemble.

## 7. Couche B — trading (Q2)

- **Mise en œuvre** : capital divisé en 14 tranches ; chaque jour, une tranche est ouverte pour
  14 jours dans le sens du détecteur (rien si NEUTRE), sur chaque actif du panier en poids égaux
  (panier U20) ou sur BTC seul. Pas de levier (exposition brute ≤ 1).
- **Prix** : ouverture du perpétuel USD-M du jour d'entrée et du jour de sortie.
- **Coûts, profil normal** : 5 bp de frais par côté + demi-écart 1 bp (BTC, ETH) ou 3 bp (autres) par
  côté ; funding réel aux instants de règlement Binance (débit/crédit selon le côté).
  **Profil adverse** : frais et écart × 1,5 ; funding réel inchangé.
- **Fenêtre** : décisions à partir du 2020-02-01 (perpétuels et funding) ; la couche B de
  l'entraînement couvre donc 2020-02 → 2022-12 (déclaré).
- **Mesures** : rendement net cumulé, rendement annualisé, drawdown maximal, ratio rendement
  annualisé / drawdown maximal (Calmar), exposition (part des jours non NEUTRE), nombre de tranches,
  par année et par phase.
- **Comparateurs** : les mêmes quatre que la couche A, simulés avec les mêmes coûts (l'aléatoire :
  200 tirages, persistance appariée).

## 8. Critères (identiques en entraînement, validation et holdout)

Un détecteur **passe Q1** sur une phase si, sur le panier U20 :

1. surplus > 0 et p ≤ 0,10 contre l'aléatoire apparié ;
2. borne basse de l'IC à 90 % **du surplus** (§6, intervalle 2) strictement positive ; l'IC du score
   brut est rapporté sans gate ;
3. score > score de toujours LONG, toujours SHORT et tendance simple ;
4. stabilité : surplus > 0 dans au moins 2/3 des années civiles de la phase comptant ≥ 60 jours de
   décision non NEUTRE.

Un détecteur **passe Q2** sur une phase si, sur le panier U20, dans les deux profils de coûts :

1. rendement net > 0 et supérieur à la médiane de l'aléatoire apparié avec p ≤ 0,10 ;
2. Calmar supérieur à celui de toujours LONG, toujours SHORT et tendance simple ;
3. ET (rendement net ≥ celui du meilleur des trois comparateurs déterministes OU drawdown maximal
   réduit d'au moins 25 % par rapport à toujours LONG) ;
4. exposition ≥ 30 % des jours (pas de dégradation excessive du nombre de trades).

BTC seul est rapporté avec les mêmes critères, à titre d'information.

**Sélection après l'entraînement** : parmi les détecteurs qui passent Q1 **et** Q2, au plus deux,
classés par la borne basse de l'IC à 90 % du surplus apparié (§6, intervalle 2). D4-OI n'est jamais sélectionnable. Si aucun ne
passe : arrêt, validation et holdout non lus.

**Validation** : détecteurs sélectionnés seulement, mêmes critères. **Holdout** : détecteurs qui ont
passé la validation seulement, mêmes critères. **Candidat** si et seulement si Q1 et Q2 passent en
validation **et** en holdout.

Interprétation déclarée :

| Résultat | Lecture |
|---|---|
| Q1 échoue | pas de pouvoir prédictif mesurable : piste close |
| Q1 passe, Q2 échoue | pouvoir prédictif sans gain exploitable après coûts : recherche seulement |
| Q1 et Q2 passent en validation et holdout | candidat pour la campagne séparée B120 / r8b, sans réhabilitation automatique |

## 9. Rapport (obligatoire, quel que soit le résultat)

Pour chaque détecteur et chaque phase lue : scores Q1 et Q2, comparateurs, IC du score brut et IC
du surplus apparié, résultats
par année et par grande phase de marché (découpage fixé ici pour le rapport seulement :
2018 baisse ; 2019 reprise ; 2020-03 → 2021-11 hausse ; 2021-12 → 2022-12 baisse ; 2023-2024
reprise ; 2025-2026), exposition, horizons secondaires 7/30 jours, valeur du NEUTRE, liste des
actifs, segments et exclusions effectivement appliqués (§3, annexes A et B), toutes les entorses au
protocole.

## 10. Exécution

- Code : branche `claude/research-regime-r2`, worktree séparé ; tests (anticipation, univers à date,
  segments et identités, chaînes de Markov, bootstrap apparié, coûts) écrits avant toute lecture de
  prix. Le pré-enregistrement V1, la V2 et les annexes sont commités tels quels dans cette branche ;
  le commit est cité dans les résultats.
- Une seule exécution par phase ; empreinte du code et des données enregistrée dans les résultats.
- Ressources : 1 worker, `nice 15`, aucune interférence avec les services systemd `fwd-okx-*` ni
  avec la campagne swing v2 OKX de la session Ubuntu ; arrêt si la RAM disponible passe sous
  1,5 Go ou le disque sous 15 Go.
- Données estimées : environ 600 paires spot × 110 mois de bougies journalières, plus perpétuels et
  funding ; quelques centaines de Mo au plus.
