# Checklists - TradingV3 Expert

## Strategy Checklist

- Quel comportement de marche est exploite ?
- Quel regime doit activer/desactiver le setup ?
- Le signal reste-t-il valide apres fees, spread, slippage, funding ?
- Le filtre reduit-il les pertes ou seulement le nombre de trades ?
- L'effet est-il visible OOS ?
- Le pire decile est-il ameliore ?

## Risk Checklist

- SL automatique garanti ?
- Risque par trade plafonne apres quantization ?
- Levier derive et non arbitraire ?
- Liquidation assez loin du stop ?
- Exposition totale plafonnee ?
- Daily loss cap respecte ?
- Rejection auditee ?

## Execution Checklist

- Entry price recalcule apres carnet ?
- Entry zone TTL respectee ?
- Maker-first compatible avec urgence du setup ?
- Fallback taker borne par slippage max ?
- Partial fill gere ?
- Protection SL/TP posee apres fill ?
- Reconciliation possible ?

## Exchange Checklist

- Symbol mapping teste ?
- Precision price/quantity testee ?
- Min notional/min size applique ?
- Leverage max applique ?
- Margin mode coherent ?
- Rate limit/backoff present ?
- Payloads bruts isoles dans adapter ?

## Architecture Checklist

- Responsabilite du service claire ?
- Strategie separee d'exchange ?
- Domaine sans payload provider ?
- Logs et audit suffisants ?
- Tests au bon niveau ?
- Pas de refactor non lie ?

## Statistical Checklist

- Nombre de trades suffisant ?
- OOS respecte ?
- Wilson CI calcule ?
- PSR ou DSR calcule si pertinent ?
- Multiple testing corrige ?
- Monte Carlo effectue ?
- Regime analysis fournie ?

## Diagnostic Output

Repondre avec cette structure quand un diagnostic est demande :

```markdown
## Category

## Observed Evidence

## Main Risk

## Hypothesis

## Minimal Change

## Validation Plan

## Issue Draft

## PR Scope
```
