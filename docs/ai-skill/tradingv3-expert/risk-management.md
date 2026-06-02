# Risk Management - TradingV3 Expert

## Objectif

Reduire la perte attendue avant d'augmenter la frequence de trades.

Toute recommandation risk doit produire une regle mesurable, testable et compatible execution exchange.

## Ordre d'analyse obligatoire

1. Verifier la presence d'un stop-loss automatique.
2. Verifier le risque nominal par trade.
3. Verifier le levier effectif et la distance liquidation.
4. Verifier frais, spread, slippage et funding.
5. Verifier l'exposition cumulee par symbole, profil, direction et exchange.
6. Verifier les caps journaliers et drawdown.
7. Verifier l'effet du changement sur le pire decile de trades.

## Invariants

- Aucun trade sans SL pose ou plan de protection idempotent.
- Le risque fixe doit etre calcule depuis le stop reel, pas depuis un stop theorique.
- Le levier est une consequence du sizing et de la distance stop/liquidation, jamais une entree arbitraire.
- Le risque total multi-position doit etre plafonne avant dispatch order.
- Une entree refusee pour risque ne doit pas etre contournee par un fallback taker.

## Stop-loss

Verifier :

- Stop distance en pourcentage du prix d'entree.
- Stop distance en ATR.
- Stop distance vs liquidation price.
- Stop distance vs support/resistance pivot.
- Impact du tick size et price precision.
- Placement effectif cote exchange apres quantization.

Refuser ou alerter si :

- Stop plus proche que le bruit microstructure observe.
- Stop plus loin que le risk budget autorise.
- Stop calcule sur un prix candidat puis execute sur un prix degrade sans recalcul.
- Stop pivot depasse la distance maximale autorisee sans fallback ATR.

## Levier

Le levier maximal doit etre derive de :

```text
max_leverage = min(
  exchange_max_leverage,
  liquidation_safe_leverage,
  risk_budget_leverage,
  profile_max_leverage
)
```

Exiger une marge de securite liquidation :

```text
distance(entry, liquidation) > distance(entry, stop) * safety_factor
```

Utiliser `safety_factor >= 2` par defaut, sauf justification statistique.

## Position sizing

Formule de base :

```text
risk_amount = equity * risk_pct
position_notional = risk_amount / abs(entry_price - stop_price) * entry_price
```

Ajustements obligatoires :

- Arrondir selon lot size et min order size.
- Recalculer le risque apres quantization.
- Rejeter si le risque final depasse le cap.
- Rejeter si la marge initiale depasse le budget.
- Inclure frais taker dans le pire cas.

## Daily loss cap

Ajouter ou verifier :

- Cap perte journaliere realisee.
- Cap perte journaliere realisee + risque ouvert.
- Cooldown apres N pertes consecutives.
- Reduction progressive du risque apres drawdown intraday.

## Funding, frais, spread, slippage

Avant trade :

- Estimer frais maker et taker.
- Mesurer spread en bps.
- Mesurer slippage attendu par profondeur carnet.
- Verifier funding si position peut traverser un funding event.

Rejeter si :

- L'esperance brute du setup ne couvre pas les couts.
- Le spread consomme une part excessive du stop ou du TP1.
- Le funding extreme invalide le R attendu.

## Artefacts attendus

Toute PR risk doit inclure :

- Tests unitaires du calcul sizing/levier/SL.
- Cas limites de precision exchange.
- Logs/audit fields permettant de reconstruire le risque.
- Exemple de rejection explicite.
- Migration ou documentation si un nouveau champ est ajoute.

## Issue minimale

```markdown
## Problem
Risk weakness observed: ...

## Hypothesis
If we enforce ..., expected loss should decrease because ...

## Acceptance Criteria
- No order can be dispatched without ...
- Risk after quantization is <= ...
- Logs expose ...
- Tests cover ...

## Validation
- Backtest segment:
- Forward sample:
- Metrics:
```
