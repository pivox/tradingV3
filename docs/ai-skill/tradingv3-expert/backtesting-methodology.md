# Backtesting Methodology - TradingV3 Expert

## Objectif

Evaluer une modification de strategie comme une hypothese falsifiable, pas comme une optimisation opportuniste.

## Donnees minimales

Inclure :

- OHLCV par timeframe utilisee.
- Frais maker/taker.
- Spread ou approximation conservatrice.
- Slippage modele par liquidite.
- Funding si futures perpetuels.
- Precision exchange et min order size.
- Latence ou delai entre signal et execution.

Refuser un backtest si :

- Les bougies higher timeframe utilisent le futur.
- Les indicateurs sont recalcules avec lookahead.
- Les frais ou slippage sont absents.
- Les symboles delistes ou survivorship bias sont ignores.

## Split temporel

Structure recommandee :

```text
train        : calibrage initial
validation   : selection limitee des variantes
test OOS     : evaluation finale non touchee
forward/live : confirmation post-merge
```

Interdire :

- Re-selectionner des seuils apres lecture du test OOS.
- Declarer un edge depuis un seul regime marche.
- Comparer beaucoup de variantes sans correction multiple.

## Walk-forward

Utiliser walk-forward lorsque les parametres peuvent dependre du regime.

Pour chaque fenetre :

1. Calibrer sur train.
2. Geler les parametres.
3. Tester sur la fenetre suivante.
4. Agreger les resultats sans re-optimiser.

Rapporter :

- Nombre de fenetres gagnantes/perdantes.
- Distribution du profit factor.
- Max drawdown par fenetre.
- Stabilite des parametres retenus.

## Simulation execution

Le moteur doit simuler :

- Signal time vs kline close time.
- Entry zone TTL.
- Maker-first et fallback taker.
- Rejet pour spread, precision, min size, leverage, marge.
- Stop-loss pose immediatement apres entree.
- Partial fills si le systeme les supporte.
- Cancel/replace idempotent.

## Metrics minimales

Toujours fournir :

- Nombre de trades.
- Winrate avec Wilson CI.
- Expectancy par trade.
- Profit factor.
- Average win / average loss.
- Median R.
- Max drawdown.
- Pire jour.
- Pire serie de pertes.
- Exposure time.
- Cout total fees + slippage + funding.

## Decision gate

Une variante peut avancer seulement si :

- Elle reduit les pertes ou drawdown sans degradation cachee.
- Elle reste robuste apres couts.
- Elle ameliore le pire decile ou ne le degrade pas.
- Elle passe OOS.
- Elle a un plan de forward validation.

## Rapport attendu

```markdown
## Hypothesis

## Dataset
- Period:
- Symbols:
- Timeframes:
- Cost model:

## Baseline

## Variant

## OOS Results

## Risk Impact

## Failure Modes

## Decision
Ship / reject / collect more data
```
