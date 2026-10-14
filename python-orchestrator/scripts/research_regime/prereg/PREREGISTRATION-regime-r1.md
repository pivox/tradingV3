# Pré-enregistrement regime-r1 — détecteurs de régime LONG / SHORT / NEUTRE

Rédigé le 2026-10-14 dans la session tradingv3-db, sur GO du propriétaire du même jour, avant tout
téléchargement de prix et avant tout calcul de performance. Seules des listes de fichiers
(data.binance.vision, sans contenu) ont été consultées pour fixer les sources (§3).

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

- Aucune valeur manquante n'est reconstituée. Un jour sans bougie rend l'actif inéligible ce jour-là ;
  un trou de plus de 3 jours coupe la série (préchauffage refait).
- **Symboles réutilisés** (ex. LUNAUSDT après mai 2022) : une coupure de cotation de plus de 7 jours
  ou un changement de prix > ×20 d'un jour à l'autre ouvre une nouvelle série (nouvel actif).
- Stablecoins, paires indexées et tokens à levier (UP/DOWN/BULL/BEAR) exclus par liste déclarée
  dans le code avant le gel.
- Toutes les décisions utilisent la bougie **fermée** de la veille ; la décision du jour J est
  connue à J 00:00 UTC et appliquée au prix d'ouverture de J.

## 4. Univers à chaque date (sans biais de survivance)

- **U50(t)** : les 50 paires spot USDT éligibles (≥ 200 jours d'historique continu) ayant le plus
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

D4 étant indisponible avant 2021, son entraînement couvre 2021-01-01 → 2022-12-31 seulement
(déclaré ; puissance plus faible).

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
- **Intervalles** : bootstrap circulaire par blocs de 30 jours (10 000 rééchantillonnages) sur la
  série quotidienne du score ; IC à 90 %.

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
2. borne basse de l'IC à 90 % par blocs > 0 ;
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
classés par la borne basse de l'IC du surplus Q1. D4-OI n'est jamais sélectionnable. Si aucun ne
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

Pour chaque détecteur et chaque phase lue : scores Q1 et Q2, comparateurs, IC par blocs, résultats
par année et par grande phase de marché (découpage fixé ici pour le rapport seulement :
2018 baisse ; 2019 reprise ; 2020-03 → 2021-11 hausse ; 2021-12 → 2022-12 baisse ; 2023-2024
reprise ; 2025-2026), exposition, horizons secondaires 7/30 jours, valeur du NEUTRE, liste des
actifs et séries coupées (§3), toutes les entorses au protocole.

## 10. Exécution

- Code : branche `claude/research-regime-r1`, worktree séparé ; tests (anticipation, univers à date,
  chaînes de Markov, bootstrap, coûts) écrits avant toute lecture de prix.
- Une seule exécution par phase ; empreinte du code et des données enregistrée dans les résultats.
- Ressources : 1 worker, `nice 15`, aucune interférence avec les services systemd `fwd-okx-*` ni
  avec la campagne swing v2 OKX de la session Ubuntu ; arrêt si la RAM disponible passe sous
  1,5 Go ou le disque sous 15 Go.
- Données estimées : environ 600 paires spot × 110 mois de bougies journalières, plus perpétuels et
  funding ; quelques centaines de Mo au plus.
