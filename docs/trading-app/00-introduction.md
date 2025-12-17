# trading-app — Documentation fonctionnelle (vue d’ensemble)

## Périmètre

Cette documentation décrit **le comportement fonctionnel effectivement implémenté** dans `trading-app` :

- récupération/stockage des données marché (klines, carnet, contrats),
- calcul des indicateurs techniques (formules + paramètres),
- validation MTF (profils, conditions, filtres),
- critères de validation/éligibilité des contrats,
- transformation d’un signal valide en **plan d’ordre** (entry zone, entrée, risque, SL, TP, levier),
- appels BitMart utilisés et politiques de throttling/backoff.

Les sources de vérité sont principalement :

- `trading-app/src/Provider/Bitmart/Http/*` (REST BitMart + throttling)
- `trading-app/src/Provider/Bitmart/BitmartKlineProvider.php` (cache DB → refresh API)
- `trading-app/src/Indicator/*` (indicateurs, contexte, conditions)
- `trading-app/src/MtfValidator/*` (validation MTF, sélection TF)
- `trading-app/src/TradeEntry/*` (entry zone, plan d’ordre, sizing, SL/TP, levier)
- `trading-app/config/app/*.yaml` (trade_entry, indicator, mtf_contracts)
- `trading-app/src/MtfValidator/config/validations.*.yaml` (profils de validation MTF)

## Architecture fonctionnelle (pipeline)

1. **Synchronisation des contrats**  
   Les contrats Futures BitMart sont persistés en base (table `contracts`). Une sélection “actifs tradables” est faite via des filtres (quote, status, turnover, âge, expiration, blacklists).

2. **Récupération des klines**  
   Les klines sont stockées en DB (table `klines`).  
   Le provider klines sert d’abord la DB si le dataset est “fresh” et continu, sinon il fetch BitMart et upsert.

3. **Indicateurs & contexte**  
   À partir des klines, `IndicatorProviderInterface` fournit :
   - des **snapshots** (persistables, re‑utilisés),
   - des **listes** d’indicateurs (EMA/SMA/MACD/RSI/ATR/VWAP/ADX/Bollinger/StochRSI + pivots),
   - un **contexte d’évaluation** homogène pour les conditions.

4. **Validation MTF**  
   `MtfValidatorCoreService` charge un profil (ex: `scalper_micro`) et valide :
   - la partie **contexte** (timeframes de contexte),
   - la partie **exécution** (timeframes d’exécution) + sélection d’un TF et d’un side.

5. **Trade entry**  
   Si le symbole est `READY`, `TradingDecisionHandler` délègue à `TradeEntryService` :
   - preflight (contract specs, spread, balance, pivots, métriques),
   - construction du plan (entry limit/market, stops, sizing, TP, levier),
   - exécution (ou simulation en dry‑run) + hooks (idempotency, anti‑retrade, etc.).

