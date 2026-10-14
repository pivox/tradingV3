# regime-r2 — correction technique c1 de la couche Q2 (figée avant le rejeu)

Rédigée le 2026-10-14 après l'entraînement run1 (training.json sha256 b715bde0…), sur décision du
propriétaire : audit de Q2 sans toucher aux détecteurs, à leurs paramètres, à Q1 ni aux critères,
sans accès à 2023-2026. Run1 reste archivé tel quel.

## Constats de l'audit
1. Valorisation à notionnel fixe : chaque tranche vaut 1/14 du capital INITIAL, sans capitalisation ;
   l'équité 1 + somme des PnL peut devenir négative et le drawdown dépasser 100 %. Ce n'est pas un
   portefeuille autofinancé.
2. Funding manquant compté à zéro (§3 du protocole l'interdit) : 13 entrées sur 20 300 du panier
   ont, dans leur fenêtre de 14 jours, un jour avec prix de perpétuel (réel ou reporté après retrait)
   sans règlement de funding.

## Correction c1 (unique, fixée ici)
1. Portefeuille autofinancé, sans levier : la tranche ouverte le jour t a un notionnel égal à
   l'équité de clôture de t−1 divisée par 14 (équité de départ 1 au premier jour de la fenêtre),
   répartie en poids égaux sur les actifs du panier du jour (NEUTRE = trésorerie). Équité valorisée
   chaque jour (mêmes incréments de prix, coûts et funding que run1, proportionnels au notionnel de
   la tranche). Si l'équité tombe à 0 ou moins, plus aucune tranche n'est ouverte (ruine, rapportée).
2. Mesures : rendement total = équité finale − 1 ; rendement annualisé = (équité finale)^(1/années)
   − 1 ; drawdown maximal = max sur la fenêtre de (pointe − équité) / pointe ; Calmar = rendement
   annualisé / drawdown maximal ; exposition inchangée.
3. Funding manquant : une position (actif, jour d'entrée) dont la fenêtre [t, t+13] contient un jour
   avec prix (réel ou reporté) et sans règlement de funding n'est pas ouverte : sa part reste en
   trésorerie, le nombre d'actifs du panier (et donc les poids des autres) est inchangé. Aucun taux
   n'est inventé. Le nombre de positions ainsi écartées est rapporté.
4. Les critères Q2 du §8 sont appliqués tels quels avec ces mesures (« rendement net » = rendement
   total). Les comparateurs (toujours LONG, toujours SHORT, tendance simple, 200 tirages aléatoires)
   passent par le même moteur.
5. Q1, les détecteurs, l'univers, les graines et l'ordre des tirages sont inchangés : Q1 doit être
   reproduit à l'identique (contrôle de non-régression).
6. Un seul rejeu (c1). Run1 et c1 sont tous deux rapportés.
