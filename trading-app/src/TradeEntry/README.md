# TradeEntry – Guide complet

Le module TradeEntry convertit un signal MTF (`SymbolResultDto`) en ordres Bitmart (levier, prix, taille, SL/TP). Tout est orchestré par `App\TradeEntry\Service\TradeEntryService` via quatre workflows:

1. **BuildPreOrder** → collecte la réalité exchange (`Policy\PreTradeChecks`).
2. **BuildOrderPlan** → calcule zone, prix, stops, TP, leverage et taille.
3. **ExecuteOrderPlan** → soumet levier + ordre (limit/market) via `Execution\ExecutionBox`.
4. **AttachTpSl** → fixe ou complète les TP/SL selon la config (via `TpSlAttacher` et `TpSlTwoTargetsService`).

```
TradeEntryService::buildAndExecute()
    ├─ Workflow\BuildPreOrder
    │     └─ Policy\PreTradeChecks (MainProvider + IndicatorProvider)
    ├─ Workflow\BuildOrderPlan
    │     ├─ EntryZoneCalculator / Filters
    │     └─ OrderPlanBox → OrderPlanBuilder
    ├─ Workflow\ExecuteOrderPlan
    │     └─ Execution\ExecutionBox (Idempotency, Maker/Taker policy, watchers)
    └─ Workflow\AttachTpSl (preset ou rattrapage)
```

Le module gère tous les cas : manque d’ATR, pivots éloignés, spreads trop larges, passage automatique en taker quand la zone expire, watchers pour annuler une LIMIT non remplie, recalcul du levier dynamique, etc.

---

## 1. Configuration & `TradeEntryRequestBuilder`

Les profils TradeEntry (`config/app/trade_entry*.yaml`) décrivent les defaults utilisés par le builder :

- `defaults` : risk_pct_percent, initial_margin_usdt, r_multiple, order_type (`limit` par défaut), open_type (`isolated`), order_mode (Bitmart `mode`), `stop_from` (`atr` ou `pivot`), fallback (`atr|risk|none`), `atr_k`, `market_max_spread_pct`, politiques TP, etc.
- `leverage` : floor, exchange_cap, per_symbol_caps (regex), timeframe_multipliers, rounding.
- `decision` : `allowed_execution_timeframes` (guard côté TradeEntry).
- `post_validation.entry_zone.*` : paramètres zone (pivot/vwap, k_atr, w_min/w_max, ttl…).
- `market_entry` : autorise bascule en `order_type=market` (filtré par `allowed_execution_timeframes`, ADX, slippage).

`TradeEntryRequestBuilder::fromMtfSignal()` consomme `SymbolResultDto` (symbol, side, execution_tf, price, ATR) et construit un `TradeEntryRequest`. Points importants :

- **ATR requis** si `stop_from='atr'` : ordre rejeté (`null`) lorsque ATR absent ou ≤0 (log `atr_required_but_invalid`).
- **Multiplicateur de timeframe** : `risk_pct` et `leverageMultiplier` tiennent compte de `defaults.timeframe_multipliers[execution_tf]`.
- **Market entry** : si `market_entry.enabled=true` + TF autorisé + ADX1h ≥ min, alors `order_type` devient `market` (avec log `market_entry.decision`).
- **Guard sur le spread** : pour les market orders, `PreTradeChecks` rejettera si `spreadPct > market_max_spread_pct`.
- **Deviation overrides** : `ZoneDeviationOverrideStore` permet de pousser un `zone_max_deviation_pct` spécifique par mode/symbole (outil d’ops).

---

## 2. Pré‑flight : `Policy\PreTradeChecks`

But : réunir toutes les contraintes exchange avant de dimensionner le plan. Sources :

- `MainProviderInterface` → `ContractProvider::getContractDetails` (precision, contract size, min/max volume, leveg min/max, last price, mark price).
- `OrderProvider::getOrderBookTop` → best bid/ask + spread.
- `AccountProvider::getAccountBalance` → capital réellement disponible.
- `IndicatorProviderInterface` → `fetchPivotLevels()` (déduire S1/S2… R1/R2…).
- `MarketStructureSampler` → metrics `depthTopUsd`, `bookLiquidityScore`, `volatilityPct1m`, `latencyRestMs`, etc.

Résultat : `PreflightReport` (référence pour tout le reste). Gardiens :

- Order book doit être cohérent (bid/ask > 0).
- Spread guard pour market order : rejet si `spreadPct > TradeEntryRequest::marketMaxSpreadPct`.
- Résolution (pricePrecision, volPrecision) sécurisée via `TickQuantizer`.

---

## 3. EntryZone

`EntryZoneCalculator` génère une zone [min, max] centrée sur un pivot :

- Pivot : priorité VWAP si `post_validation.entry_zone.vwap_anchor=true`, sinon SMA21.
- Largeur : `clamp(k_atr × ATR, pivot × w_min, pivot × w_max)` avec quantification aux ticks.
- TTL / expiration : `post_validation.entry_zone.ttl_sec` stocké dans `EntryZone` (utilisé pour les fallbacks taker).
- `zone_max_deviation_pct` : tolérance par rapport au `mark`. Si la zone est à >X% du marché, on la considère inexploitée (log `entry_zone.rejected_by_deviation`, fallback maker classic).
- `EntryZoneFilters` (aujourd’hui pass-through) servira à plugger des règles additionnelles (ex: confirmations RSI/MA).

---

## 4. Construction du plan (`OrderPlanBuilder`)

### 4.1 Entry price

1. Base maker price à `bid + tick` (long) ou `ask - tick` (short).
2. Si zone utilisable et proche du mark (déviation ≤ `zone_max_deviation_pct`), clamp dans la zone.
3. Respect `entryLimitHint` (souvent prix actuel) sans sortir de la zone.
4. Fallback maker si zone absente : `bestBid + insideTicks × tick` (long) ou symétrique.
5. Clamp vs `mark` (maxDeviationPct) et contre le carnet `[bid, ask]`.
6. Quantization finale (`TickQuantizer`).
7. `implausible_pct` guard : si entry trop éloigné du mark, fallback sur un prix serré autour du carnet (log `entry_fallback`).

Pour `order_type=market`, on prend simplement `bestAsk` ou `bestBid`.

### 4.2 Stops

- `stop_from='pivot'` :
  - `StopLossCalculator::fromPivot()` choisit S/R selon `pivot_sl_policy` (`nearest`, `strongest`, ou `S1/S2`) + buffer (ex: −0.15% sous S1).
  - Si distance > 2 % (`MAX_PIVOT_STOP_DISTANCE_PCT`) et fallback autorisé :
    - `stop_fallback='atr'` → bascule sur ATR (`atr_value`, `atr_k`), garde min 0.5 %, log `stop_pivot_fallback_atr`.
    - `stop_fallback='risk'` → on laisse le sizing recalculer un stop risk plus large (log `stop_pivot_fallback_risk`).
- `stop_from='atr'` :
  - Calcul direct atr×k, garde min 0.5 %.
- `stop_from='risk'` :
  - Basé sur `risk_usdt`, `contract_size`, `size`.
- Quel que soit le cas : clamp min 1 tick et ≥0.5 % (log `sl_*_candidate`), re-sizer si stop final diffère de la distance initiale.

### 4.3 Position sizing & TP

- `PositionSizer::fromRiskAndDistance()` dimensionne `size` = `risk_usdt / distance`, borné par `minVolume`, `maxVolume`, `marketMaxVolume`.
- `TakeProfitCalculator` applique `tp_policy` (ex : `pivot_conservative`, `r_multiple`, etc.) avec options `tp_buffer_pct`, `tp_buffer_ticks`, `tp_min_keep_ratio`, `tp_max_extra_r`.
- `LiquidationGuard` vérifie que la liquidation n’est pas trop proche (`order_plan_builder.liquidation_guard_ok`).

---

## 5. Levier dynamique

`Service\Leverage\DynamicLeverageService` implémente `LeverageServiceInterface` :

- Base = `risk_pct / stop_pct`.
- Multi TF (`leverage.timeframe_multipliers`), multi-vol (ATR/price → `computeVolatilityMultiplier`), `k_dynamic / stop_pct` pour caper.
- Caps additionnels : `exchange_cap`, regex `per_symbol_caps`, `floor`, min/max exchange, rounding (`ceil|floor|round`).
- Si `stopPct` absent → exception (log `order_plan.leverage.missing_stop_pct`).

`ExecutionBox` soumet ensuite le levier via `OrderProvider::submitLeverage` avant l’ordre.

---

## 6. Execution (limit/market)

`ExecutionBox::execute()` :

1. `IdempotencyPolicy` génère un `client_order_id`.
2. Soumet levier (`submitLeverage`) + log response.
3. **Market orders** :
   - Soumission via `executeMarketOrder()` avec watchers WS/REST (timeouts 3s WS, 10s total) + vérification via `getPlanOrders`.
4. **Limit orders** :
   - `TpSlAttacher::presetInSubmitPayload()` encode SL/TP directement dans le `placeOrder`.
   - `orderModePolicy` force MakerOnly (mode=4) ou Taker (mode=3) selon config.
   - Dead-man switch Bitmart désactivé (`cancel_after_timeout=0`), un watcher interne (`LimitFillWatchMessage`) annule l’ordre si non rempli sous 120s.
   - `MakerTakerSwitchPolicy` peut convertir une LIMIT en IOC taker si la zone expire ou si le spread est trop élevé (cf. `applyMakerTakerSwitch`).
   - `applyEndOfZoneFallback()` autorise un passage en taker (market ou limit capée) lorsque la zone va expirer, le spread ≤ `maxSpreadBps` et le prix reste dans la zone.

Si `plan->leverage < 1` → skip direct (`execution.leverage_below_min`). Tous les événements importants sont loggés (`order_journey.*`).

---

## 7. Attachement TP/SL

- `TpSlAttacher` gère la pré‑injection des ordres stop/take dans le payload Bitmart (plan orders).
- En cas d’échec ou de besoin asynchrone (`process_tp_sl` coté runner), `TpSlTwoTargetsService` recalculera les deux TP (ex : target1/target2) et repositionnera SL.

---

## 8. Cas particuliers à connaître

| Cas | Comportement |
| --- | --- |
| **ATR manquant** | Le builder rejette (`null`) si `stop_from='atr'`. Pas de fallback silencieux. |
| **Pivot trop loin (>2 %)** | Fallback ATR si possible, sinon fallback risk. Toujours logué. |
| **Zone éloignée (> zone_max_deviation_pct)** | On ignore la zone et on retombe sur la logique bid/ask. Possibilité de skip complet côté `BuildOrderPlan` si le marché est trop loin (`EntryZoneOutOfBoundsException` → `skipped_out_of_zone`). |
| **Spread marché > market_max_spread_pct** | `PreTradeChecks` annule l’ordre market (log `pretrade.spread_blocked`). |
| **implausible price** | Clamp automatique autour du mark + log `entry_fallback`. |
| **Budget insuffisant / risk_pct = 0** | Exception `order_plan_builder.no_budget` ou `invalid_risk`. |
| **Zone TTL expirant** | `applyEndOfZoneFallback` peut passer en taker (IOC ou market) si `fallback_end_of_zone.enabled=true`. |
| **Levier < min exchange** | Execution skippe l’ordre plutôt que d’envoyer un levier invalide. |
| **Market order vérification** | Après soumission, un watcher REST vérifie que les `plan_order_id` existent dans `getPlanOrders`. |

---

## 9. Extension / personnalisation

- **Nouveaux profils TradeEntry** : ajouter `config/app/trade_entry.<mode>.yaml` + activer via `TradeEntryModeContext`.
- **Entry zone custom** : modifier `EntryZoneCalculator` ou brancher de nouveaux `EntryZoneFilters`.
- **Nouvelles politiques** : implémenter `OrderModePolicyInterface` (ex: forcer IOC/Maker selon symboles) ou étendre `MakerTakerSwitchPolicy`.
- **Watchers** : `ExecutionBox` envoie `LimitFillWatchMessage` sur Messenger. Les handlers peuvent être adaptés (annulation, notification).
- **Tests** : utilisez `TradeEntryBacktestService` / `TradeEntryMetricsService` pour rejouer un plan sur des données historiques.

---

## 10. APIs / services clés

| Classe | Rôle |
| --- | --- |
| `TradeEntryService` | API publique (`buildAndExecute`, `buildPlanOnly`, `executePlan`). |
| `TradeEntryRequestBuilder` | Convertit un signal MTF en `TradeEntryRequest`. |
| `PreTradeChecks` | Snapshot exchange + guards spread/balance. |
| `EntryZoneCalculator` | Calcule la zone pivot/vwap. |
| `OrderPlanBuilder` | Prix, stops, TP, sizing, leverage. |
| `DynamicLeverageService` | Implémentation `LeverageServiceInterface`. |
| `ExecutionBox` | Submit levier + order, watchers, fallback taker. |
| `TpSlAttacher` / `TpSlTwoTargetsService` | SL/TP automatiques. |

Ce README couvre toutes les briques utilisées quotidiennement. Pour ajouter un use case spécifique ou comprendre un log `order_journey.*`, remonter vers les classes citées ci-dessus. Toute nouvelle fonctionnalité doit mettre à jour ce document (risk sizing, TP policies, watchers, etc.) afin que l’équipe garde une vision exhaustive du module. Bonne construction de trades !
