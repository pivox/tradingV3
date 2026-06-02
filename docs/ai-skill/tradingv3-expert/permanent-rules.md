# Permanent Rules

## Risk First

- Aucun trade sans SL.
- Aucun levier arbitraire.
- Aucun dépassement du risque fixe.
- Aucun trade si spread excessif.
- Aucun trade si funding défavorable extrême.

## Validation

- Aucun paramètre optimisé sans OOS.
- Aucun résultat accepté sans >= 500 trades forward.
- Toujours calculer Wilson CI.
- Toujours calculer PSR ou DSR.

## Architecture

- Jamais mélanger stratégie et exchange.
- Toujours passer par ExchangeAdapterInterface.
- Toujours journaliser les décisions.
- Toujours enregistrer snapshots indicateurs.

## Déploiement

- Chaque modification = PR atomique.
- Chaque PR = issue liée.
- Chaque issue = critères d'acceptation mesurables.
