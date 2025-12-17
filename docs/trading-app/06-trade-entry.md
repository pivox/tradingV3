# Trade entry — entry zone, entrée, risque, SL, TP, levier

## Config TradeEntry (modes)

Chargement de config :

- `TradeEntryConfigProvider` lit `trading-app/config/app/trade_entry.<mode>.yaml` (fallback `trade_entry.yaml` pour `regular`)
- Résolution du mode actif : `TradeEntryModeContext` (priorité via param `mode` dans `trading-app/config/services.yaml`)

Sources :

- `trading-app/src/Config/TradeEntryConfigProvider.php`
- `trading-app/src/Config/TradeEntryModeContext.php`

## Construction du TradeEntryRequest (MTF → TradeEntry)

Builder : `trading-app/src/TradeEntry/Builder/TradeEntryRequestBuilder.php`

Entrées minimales :

- `symbol`
- `signalSide` (`LONG` / `SHORT`)
- `executionTf` (obligatoire)
- `price` (optionnel)
- `atr` (optionnel mais peut devenir obligatoire selon config)
- `mode` (optionnel → résolu par défaut)

### Calcul du risque (% risk)

Le builder lit `trade_entry.defaults.risk_pct_percent`.

- `riskPct = (risk_pct_percent / 100) * tfMultiplier`
- `tfMultiplier` (multiplicateur “builder”) est lu depuis :
  - `trade_entry.leverage.timeframe_multipliers[executionTf]` sinon `1.0`

Note fonctionnelle :

- `riskPct` est stocké dans `TradeEntryRequest->riskPct` (un ratio 0..1 attendu ensuite).
- Ce multiplicateur impacte directement le **notionnel** car `PositionSizer` dimensionne la taille à partir de `riskUsdt = budget * riskPct`.

### Gonflage TP (R-multiple)

Le builder applique le même `tfMultiplier` au TP “R multiple” :

- `rMultiple_effectif = defaults.r_multiple * tfMultiplier`

Conséquence : les TP (théorique + alignement pivots) sont plus loin / plus proches selon TF.

### Budget (initial_margin_usdt)

- `initialMargin = defaults.initial_margin_usdt`
- Si `initialMargin <= 0` :
  - `initialMargin = fallback_account_balance * riskPct`
- Si `initialMargin <= 0` → builder retourne `null` (signal non exécutable).

### Stop “ATR obligatoire” (condition de rejet)

Si `defaults.stop_from == 'atr'` et `atr` est manquant/<=0 :

- le builder **rejette** la requête (`return null`) et log `atr_required_but_invalid`.

## Preflight (contrat, carnet, balance, pivots)

Service : `PreTradeChecks` (`trading-app/src/TradeEntry/Policy/PreTradeChecks.php`)

Récupère :

- contract specs via `ContractProviderInterface::getContractDetails(symbol)`
- top of book via `OrderProviderInterface::getOrderBookTop(symbol)`
- balance via `AccountProviderInterface::getAccountBalance()`
- pivots journaliers via `IndicatorProviderInterface::getListPivot(symbol, tf='1d')` puis `pivot_levels`

Bloque une entrée market si `spreadPct > marketMaxSpreadPct`.

## Calcul de l’Entry Zone (EntryZoneCalculator)

Service : `trading-app/src/TradeEntry/EntryZone/EntryZoneCalculator.php`

### Sources et fusion de config

Le calcul lit d’abord :

- `trade_entry.<mode>.yaml` → `trade_entry.post_validation.entry_zone`
- puis (prioritaire) `trade_entry.entry.entry_zone`

Fusion :

- `zoneCfg = array_merge(post_validation.entry_zone, entry.entry_zone)`

### Choix des timeframes

- `pivotTf` :
  - `zoneCfg.pivot_tf` ou `post.execution_timeframe.default` ou fallback `'5m'`
- `atrTf` :
  - `zoneCfg.offset_atr_tf` sinon `pivotTf`

### Choix du pivot (VWAP vs SMA21)

Paramètres (issus de `zoneCfg = array_merge(post_validation.entry_zone, entry.entry_zone)`) :

- `from` (string) : “préférence” de pivot, **normalisé** par `trim()` + `lowercase`.
- `vwap_anchor` (bool, défaut `true`) : utilisé **uniquement si** `from` est absent ou non reconnu.

Valeurs **fonctionnelles reconnues** pour `from` (toute autre string est traitée comme “absente”) :

- `from: 'sma21'` (ou alias `from: 'ma21'`) :
  - tente **SMA21** en premier
  - si SMA21 est indisponible/invalide → fallback **VWAP**
- `from: 'vwap'` :
  - tente **VWAP** en premier
  - si VWAP est indisponible/invalide → fallback **SMA21**
- `from` absent / invalide :
  - si `vwap_anchor: true` → tente **VWAP**, puis fallback **SMA21**
  - si `vwap_anchor: false` → tente **SMA21**, puis fallback **VWAP**

Exemple (profil micro) : `trading-app/config/app/trade_entry.scalper_micro.yaml` contient `trade_entry.entry.entry_zone.from: 'sma21'` → la zone est **ancrée SMA21 si possible**, sinon ancrée VWAP.

Pivot trouvé en cherchant dans `IndicatorProviderInterface::getListPivot(symbol, tf=pivotTf)` :

- VWAP : `indicators['vwap']`
- SMA21 : `indicators['sma'][21]`

Si pivot absent/invalide :

- la zone devient “ouverte” (`min=-∞`, `max=+∞`) avec rationale `open zone (no pivot)`.

### Largeur de zone (formule)

Paramètres :

- `kAtr` : `zoneCfg.k_atr` ou `zoneCfg.offset_k` ou fallback `0.35`
- `wMin` : `zoneCfg.w_min` ou fallback `0.0005` (0.05%) — **demi-largeur** min relative au pivot
- `wMax` : `zoneCfg.w_max` (ou alias `zoneCfg.max_deviation_pct`) ou fallback `0.0100` (1%) — **demi-largeur** max relative au pivot

Calcul :

- `halfFromAtr = kAtr * atr` (si atr dispo, sinon 0)
- `minHalf = pivot * wMin`
- `maxHalf = pivot * wMax`
- `half = clamp(max(halfFromAtr, minHalf), maxHalf)`

Si `half <= 0` ou non fini :

- zone “ouverte” (`min=-∞`, `max=+∞`) avec rationale `open zone (invalid width)`.

Zone symétrique (ou asymétrique si `asym_bias`) :

- base : `low = pivot - half`, `high = pivot + half`
- asymétrie (si `side` fourni) :
  - Long : `lowDelta = half*(1+bias)`, `highDelta = half*(1-bias)`
  - Short : `lowDelta = half*(1-bias)`, `highDelta = half*(1+bias)`

Quantification :

- si `quantize_to_exchange_step=true` et `pricePrecision` connu :
  - `low` quantize “down”
  - `high` quantize “up”

TTL :

- `ttl_sec` (défaut 240s) stocké dans l’objet `EntryZone`.

## Construction du prix d’entrée (limit/market)

Moteur : `OrderPlanBuilder` (`trading-app/src/TradeEntry/OrderPlan/OrderPlanBuilder.php`)

### LIMIT

1. Prix maker “idéal” :
   - Long : `bestBid + tick`
   - Short : `bestAsk - tick`
2. Utilisation de la zone (si fournie) :
   - calcule `zoneDeviation = max(|zone.min-ref|, |zone.max-ref|) / ref`
   - `ref` = `mark` sinon `mid` sinon bestBid/Ask
   - si `zoneDeviation <= zoneMaxDeviationPct` :
     - clamp `ideal` dans `[zone.min, zone.max]`
   - sinon : zone rejetée (`entry_zone.rejected_by_deviation`)
3. Hint `entryLimitHint` :
   - Long : `ideal = min(ideal, hint)`
   - Short : `ideal = max(ideal, hint)`
4. Fallback si pas de zone :
   - Long : `min(bestAsk - tick, bestBid + insideTicks*tick)`
   - Short : `max(bestBid + tick, bestAsk - insideTicks*tick)`
5. Garde `maxDeviationPct` autour du mark :
   - clamp `ideal` dans `[mark*(1-maxDev), mark*(1+maxDev)]` (intersection avec zone si utilisée)
6. Clamp final dans `[bestBid, bestAsk]`
7. Quantization :
   - Long : quantize down
   - Short : quantize up

### MARKET

- Long : `entry = bestAsk`
- Short : `entry = bestBid`

Garde “implausible” :

- si `abs(entry-mark)/mark > implausiblePct` → fallback carnet puis quantize.

## Stop Loss (SL) — calcul

### 1) Candidats ATR / Pivot / Risk

Sources :

- `StopLossCalculator` (`trading-app/src/TradeEntry/RiskSizer/StopLossCalculator.php`)
- orchestration : `OrderPlanBuilder`

Stop ATR :

- `stopAtr = entry ± atr_k * atr` (± selon side)
- quantize + garde “au moins 1 tick” d’écart avec l’entrée

Stop Pivot :

- pivots venant du preflight (journaliers par défaut)
- Long : pivot choisi parmi `S*` strictement < entry, selon policy
  - `nearest` → max(candidats)
  - `strongest` → priorité `S2,S1,S3,S4,S5,S6`
  - `s1..s6` explicite possible
  - buffer : `stop = pivot * (1 - bufferPct)`
- Short : pivot choisi parmi `R*` strictement > entry, selon policy
  - `nearest` → min(candidats)
  - `strongest` → priorité `R2,R1,R3,R4,R5,R6`
  - `r1..r6` explicite possible
  - buffer : `stop = pivot * (1 + bufferPct)`

Stop Risk :

- distance max par contrat :
  - `dMax = riskUsdt / (contractSize * size)`
- `stopRisk = entry ± dMax`
- quantize + garde “au moins 1 tick”

### 2) Choix du stop final

Dans `OrderPlanBuilder` :

- si `stopFrom=pivot` :
  - calcule stopPivot
  - si `pivotStopDistancePct > 2%` (MAX_PIVOT_STOP_DISTANCE_PCT) :
    - fallback possible vers ATR (si `stop_fallback=atr`) ou vers risk (si `stop_fallback=risk`)
- si `stopFrom=atr` :
  - ATR obligatoire sinon exception

Sizing initial :

- `size = floor(riskUsdt / (sizingDistance * contractSize))`

Stop final :

- si pivot dispo → `stop = stopPivot`
- sinon si ATR dispo → `stop = conservative(stopAtr, stopRisk)`
  - Long : min(stopAtr, stopRisk) → stop plus loin (plus conservateur pour ne pas dépasser risk)
  - Short : max(stopAtr, stopRisk)
- sinon → `stop = stopRisk`

Garde globale de distance minimale :

- `MIN_STOP_DISTANCE_PCT = 0.005` → **0.5% minimum** entre entry et stop (tous stops confondus)
- si garde appliquée :
  - recalcul size + stopRisk cohérent.

## Take Profit (TP) — calcul

### TP théorique (R-multiple)

`TakeProfitCalculator::fromRMultiple()` :

- `riskUnit = abs(entry - stop)`
- `tp = entry ± rMultiple * riskUnit`
- quantize (down pour long, up pour short)

### Alignement sur pivots (optionnel)

`TakeProfitCalculator::alignTakeProfitWithPivot()` :

- pivots : preflight pivotLevels, sinon pivot_levels 15m (fallback)
- Long : cherche la **résistance la plus proche >= baseTP**
  - applique buffer :
    - `pivot *= (1 - bufferPct)` et/ou `pivot -= bufferTicks*tick`
  - `pick = max(baseTP, pivotAdjusted)`
- Short : cherche le **support le plus proche <= baseTP**
  - buffer :
    - `pivot *= (1 + bufferPct)` et/ou `pivot += bufferTicks*tick`
  - `pick = min(baseTP, pivotAdjusted)`

Gardes en R :

- `rEff = (gain_effectif) / riskUnit`
- si `rEff < minKeepRatio * rMultiple` → revert au `baseTP`
- si `rEff > rMultiple + maxExtraR` → cap à `(rMultiple + maxExtraR)`

## Levier — calcul

### 1) Levier dynamique “risk / stop”

Service : `DynamicLeverageService` (`trading-app/src/TradeEntry/Service/Leverage/DynamicLeverageService.php`)

Entrées clés :

- `stopPct = abs(stop-entry)/entry`
- `riskPct` (depuis config defaults.risk_pct_percent)
- `executionTf`
- `atr15m` (passé comme “atr5mValue” dans la signature)

Formule :

- `leverageBase = riskPct / stopPct`
- `dynCap = min(maxLeverage, k_dynamic / stopPct)` (k_dynamic depuis defaults)
- `volMult` (réduction selon volatilité) :
  - `volPct = atr/price`
- `volMult = clamp(1.25 - 75*volPct, 0.5, 1.25)`
- `tfMult` depuis `defaults.timeframe_multipliers[executionTf]` (défaut 1.0)
- `leveragePreCaps = leverageBase * tfMult * volMult`
- caps :
  - `exchange_cap` (config)
  - caps par symbole (regex)
  - `dynCap`
- clamp final :
  - `[minLeverage, maxLeverage]` puis `>= floor`
- arrondi final selon `leverage.rounding.mode` (ceil/floor/round) puis cast `int`

### 2) Ajustements budget / marge dans OrderPlanBuilder

Après calcul du levier :

- calcule notional `entry * contractSize * size`
- calcule margin `notional / leverage`
- si margin > budget :
  1. augmente levier si possible pour réduire la marge,
  2. sinon réduit la taille (quantize aux steps exchange) pour respecter budget.

Ensuite, ajustement “target margin” :

- calcule `desiredLeverage = notional / targetMargin`
- si une valeur “candidate” rapproche mieux la marge de la cible, le levier est ajusté (arrondi).

### 3) Multiplicateur additionnel (leverageMultiplier)

`TradeEntryRequestBuilder` positionne `leverageMultiplier=1.0` (ne varie pas par TF).  
`OrderPlanBuilder` supporte néanmoins un multiplicateur additionnel si un appelant le fournit explicitement :

- `leverage = leverage * leverageMultiplier` puis clamp `[minLeverage, maxLeverage]`.

Conséquence :

- le scaling TF du **levier** passe par `defaults.timeframe_multipliers` (dans `DynamicLeverageService`)
- le scaling TF du **sizing/risque/TP** passe par `leverage.timeframe_multipliers` (dans `TradeEntryRequestBuilder`)
