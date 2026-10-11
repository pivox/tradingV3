# ADR-309 — Mode `swing` de recherche

Statut : accepté pour la recherche et le backtest ; aucune exécution Paper, démo
ou réelle

Date : 2026-10-11

Issues et PR liées : #309, #462, #463 ; plan
`docs/superpowers/plans/2026-10-11-swing-canonical-campaign.md`

## Contexte

L'[ADR-300](adr-300-modern-mode-contracts.md) limite la première vague à trois
identités, `day_trading`, `scalping` et `micro_scalping`. Elle renvoie le swing
à la recherche #309.

Deux explorations historiques ont depuis criblé des politiques swing sur dix
paires, avec 2023-24 en entraînement et 2025 en validation :

- [Exploration swing historique](../reports/research-swing-exploration-2026-10-11.md) ;
- [Swing à double sens](../reports/research-swing-two-sided-exploration-2026-10-11.md).

Le propriétaire en a retenu un seul candidat falsifiable, `B30_2s × S3.T5.H14d`,
avec une règle de portefeuille d'une position active par paire. Pour rejouer ce
candidat dans la chaîne canonique, il faut des contrats publiés : signaux et
plans PHP, noyau de portefeuille, preuves, puis holdout 2026 gardé.

## Décision

Pour ce seul périmètre, cette ADR remplace la phrase de l'ADR-300 « la première
vague contient exactement trois identités ». Elle publie une quatrième identité
de mode et deux identités de setup.

| Identité | Statut | Contenu |
|---|---|---|
| `swing@1.0.0` | `shadow`, exécutable | Enveloppe du mode : horizon, cadence, risque, concurrence et ordres |
| `swing.breakout.long@1.0.0` | `shadow`, exécutable | Clôture 4h au-dessus de l'EMA200 4h et au-dessus du plus haut des 30 bougies 4h précédentes |
| `swing.breakout.short@1.0.0` | `shadow`, exécutable | Miroir : clôture sous l'EMA200 et sous le plus bas des 30 bougies précédentes |

Le nom exact du mode est `swing`. Il n'existe ni alias `swing_trading` ni
conversion depuis un profil historique. Les trois contrats sont publiés
directement en `1.0.0` exécutable, car aucun profil runtime historique n'existe
à extraire en `draft`. Le candidat sort d'un criblage dont les plans ont été
comparés au calcul PHP.

## Contrat de mode `swing@1.0.0`

- **Horizon.** `PT336H`, soit quatorze jours, avec
  `close_before_boundary: false`. Minuit UTC délimite seulement les journées
  comptables et ne ferme jamais une position. C'est le premier mode moderne sans
  clôture quotidienne. `CanonicalHoldingBoundary` et `CanonicalExecutionPolicy`
  n'acceptent ce couple que pour une fenêtre de 1 209 600 s.
- **Timeframes.** Tous les rôles sont en `4h` : régime, contexte, déclencheur,
  exécution et confirmations. La cadence et la validité valent `PT4H`.
- **Risque.**
  - Budget par trade : 5 % des fonds propres.
  - Concurrence : dix positions au plus et une seule par paire,
    `per_symbol_limit: 1`. Une entrée en attente réserve déjà sa place.
    `CanonicalPortfolioPolicy` lit cette limite. Le moteur d'admission
    canonique refuse toute politique qui la porte
    (`canonical_portfolio_symbol_occupancy_unavailable`), car son snapshot ne
    connaît pas les symboles occupés. La règle est appliquée là où l'occupation
    est connue : le plan builder de recherche et `PortfolioSimulator`.
  - Exposition du mode : 100 % des fonds propres.
  - Levier : 2.
  - Plafond de perte journalière : 100 % ou 100 000 USDT. Ce plafond explicite
    ne peut pas lier à dix plans de 250 USDT de notionnel, car le candidat a été
    criblé sans règle de perte journalière.
- **Ordres.** Marge isolée, ordre limite, sans repli au marché.

## Contrats de setup

- **Catalogue de conditions 1.3.0.** C'est une copie exacte du catalogue 1.2.0
  avec quatre conditions 4h en plus :
  - `close_gt_ema_200` et `close_lt_ema_200` ;
  - `close_gt_prior_high` et `close_lt_prior_low`, paramétrées par
    `lookback_bars` entre 1 et 59.

  Les deux conditions de cassure sont des séries `oldest_to_newest`. Avant de
  les appeler, l'évaluateur strict exige la preuve de chronologie : des bougies
  4h contiguës qui finissent à la bougie courante. Pour la fournir, les trois
  producteurs d'indicateurs émettent `high_series_timestamps` et
  `low_series_timestamps` à côté des séries : le calculateur PHP canonique, le
  contexte MTF et la projection Python. Le niveau de cassure exclut la bougie
  courante.
- **Exécution figée.**
  - Entrée : ordre limite à la clôture 4h, actif 3600 s puis annulé à 3660 s.
    Le spread est plafonné à 6 bps et le slippage à 8 bps.
  - Stop : trois ATR14 4h.
  - Objectif : un seul, à 5R. Le net R minimal vaut 1,3.
  - Time stop : `PT336H`.
  - Financement : calendrier de la plateforme.
- **Provenance.** `source_origin` épingle les lignes 59-69 et 104-131 du rapport
  « Swing à double sens » : sha256 `797484dd…`, commit `50ba5805`. Comme pour les
  preuves des scénarios Fake/Paper, un chemin `docs/…` est relatif à la racine du
  dépôt.
- **Immutabilité.** Le validateur PHP compare l'empreinte sémantique de chaque
  document. Le JSON Schema fige chaque document entier par `const`. Tout
  changement exige une nouvelle version.

## Ce que cette ADR n'autorise pas

- **Exécution réelle ou simulée sur une plateforme.** Seule la couche
  `mode_exchange` `fake` est publiée. Sans couche swing pour OKX ou Hyperliquid,
  la résolution échoue : ni Paper, ni démo, ni testnet, ni mainnet.
- **Certification Paper #132.** `swing` n'entre pas dans sa matrice :
  `PaperCertificationMatrixBuilder` garde ses trois modes.
- **Promesse de rentabilité.** Une promotion exige trois conditions :
  - la campagne canonique ;
  - le holdout 2026 gardé, exécuté une seule fois ;
  - une revue de risque indépendante.

## Conséquences

- `swing` est désormais accepté par :
  - `ModeContractValidator::MODE_IDS` et le schéma du mode ;
  - les identités de lignage et de portefeuille ;
  - le miroir Python `app/modern_trading_contracts.py`.
- Les tests d'inventaire distinguent les huit hypothèses sources de #300 et les
  deux cassures swing.
- Le reste de #309 reste ouvert : spot, timeframe 1d, trailing, sorties
  partielles et règles de week-end.
