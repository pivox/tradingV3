# Statistical Validation - TradingV3 Expert

## Objectif

Eviter de confondre bruit, overfitting et edge exploitable.

## Regles minimales

- Ne jamais conclure avec un winrate seul.
- Ne jamais ignorer les couts de transaction.
- Ne jamais accepter une optimisation sans OOS.
- Ne jamais accepter un sample trop faible sans incertitude explicite.
- Toujours distinguer performance brute, performance nette et risque de ruine.

## Taille d'echantillon

Par defaut :

- Moins de 100 trades : exploratoire uniquement.
- 100 a 499 trades : signal faible, intervalle obligatoire.
- 500 trades ou plus : acceptable pour decision preliminaire.
- 1000 trades ou plus : preferable pour comparer variantes proches.

Adapter si les trades sont fortement correles.

## Wilson CI

Utiliser Wilson CI pour encadrer le winrate.

Decision :

- Si la borne basse ne couvre pas les couts et le R attendu, refuser.
- Si l'intervalle est trop large, collecter plus de donnees.
- Ne pas comparer deux variantes par point estimate uniquement.

## PSR / DSR

Utiliser :

- PSR pour probabilite que Sharpe depasse un benchmark.
- DSR lorsque plusieurs strategies ou parametres ont ete essayes.

Refuser si :

- PSR faible malgre PnL positif.
- DSR indique une performance compatible avec data mining.

## Multiple testing

Si plusieurs seuils, symboles, regimes ou combinaisons sont testes :

- Compter les essais.
- Appliquer BH-FDR ou correction equivalente.
- Documenter les variantes rejetees.

## Monte Carlo

Utiliser Monte Carlo pour stress :

- Ordre aleatoire des trades.
- Slippage degrade.
- Fees degradees.
- Perte consecutive extreme.
- Suppression des meilleurs trades.

Demander :

- Distribution max drawdown.
- Probabilite de drawdown > cap.
- Pire percentile equity curve.

## Regime analysis

Segmenter par :

- Trend / range.
- Volatilite haute / basse.
- Spread haut / bas.
- Volume haut / bas.
- Funding favorable / defavorable.
- Heure ou session.

Une strategie fragile dans un regime doit avoir un filtre ou rester desactivee dans ce regime.

## Decision template

```markdown
## Statistical Claim

## Sample
- Trades:
- Period:
- Symbols:
- Regimes:

## Uncertainty
- Wilson CI:
- PSR:
- DSR:

## Multiple Testing
- Number of variants:
- Correction:

## Stress
- Monte Carlo:
- Worst percentile:

## Decision
```
