# Indicateurs techniques — liste, paramètres et formules

## Où sont calculés les indicateurs ?

Deux chemins principaux existent :

1. **Calcul “core” (PHP / TRADER extension si dispo)**  
   Les classes de calcul sont sous `trading-app/src/Indicator/Core/*` et utilisent :
   - les fonctions `trader_*` si l’extension PHP `trader` est chargée,
   - sinon un fallback PHP.

2. **Assemblage dans une “liste” ou un “contexte”**  
   - `IndicatorProviderService::getListFromKlines()` calcule une liste d’indicateurs + pivots.  
     Source : `trading-app/src/Indicator/Provider/IndicatorProviderService.php`
   - `IndicatorContextBuilder` construit le contexte d’évaluation des conditions (clés normalisées).  
     Source : `trading-app/src/Indicator/Context/IndicatorContextBuilder.php`

## Paramètres configurés dans `indicator.yaml`

Source : `trading-app/config/app/indicator.yaml`

### EMA
- `EMA(20)`, `EMA(50)`, `EMA(200)`
- Pullback : `EMA(9)` et `EMA(21)` (dans la config, “MA21” est explicitement `EMA(21)`).

### MACD
- `fast=12`, `slow=26`, `signal=9`

### VWAP
- “daily” (reset journalier) configurable + timezone

### RSI
- `period=14`, source `close`

### ATR
- `period=14`
- `method=wilder`
- `timeframe='5m'` (utilisé en pratique dans plusieurs endroits de TradeEntry)
- `sl_multiplier=1.5` (conceptuel dans la config ; le code de stop utilise `atr_k`)
- seuils `ATR/close` par TF (min/max) utilisés pour `atr_volatility_ok` (via l’engine indicateur).

## Liste des indicateurs effectivement calculés par le code

### EMA (Exponential Moving Average)
Source : `trading-app/src/Indicator/Core/Trend/Ema.php`

Formule (implémentation fallback) :

- `k = 2 / (period + 1)`
- initialisation : `ema = prices[0]`
- itération : `ema = price * k + ema * (1 - k)`

Note : dans `Macd` le calcul d’EMA série (fallback) seed par SMA des `period` premières valeurs (voir section MACD).

### SMA (Simple Moving Average)
Source : `trading-app/src/Indicator/Core/Trend/Sma.php`

- `SMA_t = (1/period) * Σ price_i` sur les `period` derniers points.

Utilisée notamment pour `SMA(9)` et `SMA(21)` dans :

- `IndicatorProviderService::getListFromKlines()`
- pivots d’entrée (EntryZone)

### RSI (Wilder)
Source : `trading-app/src/Indicator/Core/Momentum/Rsi.php`

Étapes :

1. Gains/pertes sur `period` premières variations :
   - `gain_i = max(close_i - close_{i-1}, 0)`
   - `loss_i = max(close_{i-1} - close_i, 0)`
2. Initialisation :
   - `avgGain = Σgain / period`
   - `avgLoss = Σloss / period`
3. Lissage Wilder :
   - `avgGain_t = ((avgGain_{t-1}*(period-1)) + gain_t) / period`
   - `avgLoss_t = ((avgLoss_{t-1}*(period-1)) + loss_t) / period`
4. `RS = avgGain/avgLoss` (si `avgLoss=0` → `RS=+∞`)
5. `RSI = 100 - 100/(1 + RS)`

### MACD (Appel)
Source : `trading-app/src/Indicator/Core/Momentum/Macd.php`

Définition :

- `MACD = EMA_fast(closes) - EMA_slow(closes)`
- `Signal = EMA(MACD, signalPeriod)`
- `Hist = MACD - Signal`

Fallback PHP `calculateFullPhp()` :

- calcule séries EMA_fast et EMA_slow avec seed SMA sur `period` premières valeurs,
- aligne MACD et Signal en tronquant la tête du MACD (shift),
- hist = macd - signal.

### VWAP
Source : `trading-app/src/Indicator/Core/Volume/Vwap.php`

Version simple :

- `TP_t = (high_t + low_t + close_t) / 3`
- `VWAP_t = Σ(TP_i * V_i) / Σ(V_i)`

Version journalière (si timestamps fournis) :

- réinitialisation des cumuls à chaque changement de date (`timezone`).

### ATR (Average True Range)
Source : `trading-app/src/Indicator/Core/AtrCalculator.php`

True Range :

- `TR_t = max(high_t-low_t, |high_t-close_{t-1}|, |low_t-close_{t-1}|)`

ATR “simple” :

- `ATR = moyenne(TR, period)`

ATR Wilder :

- seed : `SMA(TR, period)` sur les `period` premiers TR
- lissage : `ATR_t = ((ATR_{t-1}*(period-1)) + TR_t) / period`

Variant “computeWithRules” (robuste) :

- si échantillon insuffisant → `0.0`
- plancher optionnel si marché “flat” : `>= tickSize * 2`
- sur timeframe `1m` uniquement : cap outlier
  - si `ATR_latest > 3 * median(ATR_last_50)` → `ATR_latest = 3*median`

### ADX (Wilder)
Source : `trading-app/src/Indicator/Core/Trend/Adx.php`

Étapes :

- calcule TR, +DM, -DM
- lissage Wilder de ATR, +DM, -DM
- `+DI = 100 * Smoothed(+DM)/ATR`, `-DI = 100 * Smoothed(-DM)/ATR`
- `DX = 100 * |+DI - -DI| / (+DI + -DI)`
- `ADX` = lissage Wilder de `DX` (init = 1er DX)

### Bandes de Bollinger
Source : `trading-app/src/Indicator/Core/Volatility/Bollinger.php`

- `middle = SMA(period)`
- `σ = écart-type (population) sur la fenêtre`
- `upper = middle + stdev * σ`
- `lower = middle - stdev * σ`
- `width = upper - lower`

### Stochastic RSI
Source : `trading-app/src/Indicator/Core/Momentum/StochRsi.php`

- calcule une série RSI (Wilder)
- `StochRSI = ((RSI - min(RSI,n)) / (max(RSI,n) - min(RSI,n))) * 100`
- `%K = SMA(StochRSI, kSmoothing)`
- `%D = SMA(%K, dSmoothing)`

## Pivots (pivot_levels)

Source : `trading-app/src/Indicator/Provider/IndicatorProviderService.php`

Les pivots “classiques” sont calculés **à partir du dernier triplet (high, low, close) disponible dans la série fournie** :

- `PP = (H + L + C)/3`
- `R1 = 2*PP - L`
- `R2 = PP + (H-L)`
- `R3 = H + 2*(PP-L)` (et ainsi jusqu’à `R6`)
- `S1 = 2*PP - H`
- `S2 = PP - (H-L)`
- `S3 = L - 2*(H-PP)` (et ainsi jusqu’à `S6`)

Usage :

- `PreTradeChecks` les récupère en `1D` pour obtenir des pivots journaliers.  
  Source : `trading-app/src/TradeEntry/Policy/PreTradeChecks.php`
- `OrderPlanBuilder` peut fallback sur des pivots `15m` si `pivotLevels` absent.  
  Source : `trading-app/src/TradeEntry/OrderPlan/OrderPlanBuilder.php`

