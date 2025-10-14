# 📊 Indicateurs Techniques - Trading App

Ce document décrit l'implémentation des indicateurs techniques dans le système de trading.

## 🎯 Vue d'ensemble

Le système implémente 10 indicateurs techniques principaux via des vues matérialisées PostgreSQL :

| Indicateur | Type | Période | Description |
|------------|------|---------|-------------|
| **RSI** | Momentum | 14 | Détection surachat/survente |
| **MACD** | Momentum | 12,26,9 | Croisements de signaux |
| **StochRSI** | Momentum | 14,3 | RSI normalisé, signaux rapides |
| **ADX** | Tendance | 14 | Force de tendance |
| **Bollinger Bands** | Volatilité | 20 | Support/résistance dynamique |
| **Donchian Channels** | Volatilité | 20 | Niveaux de breakout |
| **Ichimoku** | Tendance | 9,26,52 | Système complet de tendance |
| **OBV** | Volume | - | Confirmation de tendance |
| **VWAP** | Volume | - | Prix de référence institutionnel |
| **EMA** | Tendance | 9,21,50,200 | Moyennes exponentielles multiples |

## 🗄️ Structure de la base de données

### Tables principales
- `klines` : Données OHLCV des bougies
- `mv_rsi14_5m` : Vue matérialisée RSI
- `mv_macd_5m` : Vue matérialisée MACD
- `mv_stochrsi_5m` : Vue matérialisée StochRSI
- `mv_adx14_5m` : Vue matérialisée ADX
- `mv_boll20_5m` : Vue matérialisée Bollinger Bands
- `mv_donchian20_5m` : Vue matérialisée Donchian Channels
- `mv_ichimoku_5m` : Vue matérialisée Ichimoku
- `mv_obv_5m` : Vue matérialisée OBV
- `mv_vwap_5m` : Vue matérialisée VWAP
- `mv_ema_5m` : Vue matérialisée EMA (9,21,50,200)

### Fonctions utilitaires
- `safe_div(numerator, denominator)` : Division sécurisée
- `rma(value, alpha)` : Moyenne de Wilder
- `ema(value, alpha)` : Moyenne exponentielle

## 🚀 Utilisation

### Scripts disponibles

#### 1. Test des indicateurs
```bash
./scripts/test_indicators.sh [symbol]
```
Vérifie que tous les indicateurs contiennent des données.

#### 2. Démonstration
```bash
./scripts/demo_indicators.sh [symbol]
```
Affiche les dernières valeurs des indicateurs avec interprétation.

#### 3. Rafraîchissement
```bash
./scripts/refresh_indicators.sh [symbol] [timeframe]
```
Rafraîchit toutes les vues matérialisées.

### Requêtes SQL directes

#### RSI (Relative Strength Index)
```sql
SELECT 
    symbol,
    bucket,
    ROUND(rsi::numeric, 2) as rsi,
    CASE 
        WHEN rsi > 70 THEN 'SURACHAT'
        WHEN rsi < 30 THEN 'SURVENTE'
        ELSE 'NEUTRE'
    END as signal
FROM mv_rsi14_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 10;
```

#### MACD (Moving Average Convergence Divergence)
```sql
SELECT 
    symbol,
    bucket,
    ROUND(macd::numeric, 2) as macd,
    ROUND(signal::numeric, 2) as signal_line,
    ROUND(histogram::numeric, 2) as histogram
FROM mv_macd_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 10;
```

#### Bollinger Bands
```sql
SELECT 
    symbol,
    bucket,
    ROUND(sma::numeric, 2) as sma,
    ROUND(upper::numeric, 2) as upper_band,
    ROUND(lower::numeric, 2) as lower_band
FROM mv_boll20_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 10;
```

#### StochRSI
```sql
SELECT 
    symbol,
    bucket,
    ROUND(stoch_rsi::numeric, 3) as stoch_rsi,
    ROUND(stoch_rsi_d::numeric, 3) as stoch_rsi_d,
    CASE 
        WHEN stoch_rsi > 0.8 THEN 'SURACHAT'
        WHEN stoch_rsi < 0.2 THEN 'SURVENTE'
        ELSE 'NEUTRE'
    END as signal
FROM mv_stochrsi_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 10;
```

#### ADX (Average Directional Index)
```sql
SELECT 
    symbol,
    bucket,
    ROUND(plus_di::numeric, 2) as plus_di,
    ROUND(minus_di::numeric, 2) as minus_di,
    ROUND(adx::numeric, 2) as adx,
    CASE 
        WHEN adx > 25 THEN 'TENDANCE FORTE'
        WHEN adx > 20 THEN 'TENDANCE MODÉRÉE'
        ELSE 'TENDANCE FAIBLE'
    END as trend_strength
FROM mv_adx14_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 10;
```

#### Ichimoku Kinko Hyo
```sql
SELECT 
    symbol,
    bucket,
    ROUND(tenkan::numeric, 2) as tenkan,
    ROUND(kijun::numeric, 2) as kijun,
    ROUND(senkou_a::numeric, 2) as senkou_a,
    ROUND(chikou::numeric, 2) as chikou,
    CASE 
        WHEN tenkan > kijun THEN 'HAUSSIER'
        WHEN tenkan < kijun THEN 'BAISSIER'
        ELSE 'NEUTRE'
    END as trend
FROM mv_ichimoku_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 10;
```

## 📈 Interprétation des signaux

### RSI (Relative Strength Index)
- **> 70** : Surachat (signal de vente)
- **< 30** : Survente (signal d'achat)
- **30-70** : Zone neutre

### MACD
- **MACD > Signal** : Tendance haussière
- **MACD < Signal** : Tendance baissière
- **Histogramme > 0** : Momentum haussier
- **Histogramme < 0** : Momentum baissier

### Bollinger Bands
- **Prix > Bande supérieure** : Surachat possible
- **Prix < Bande inférieure** : Survente possible
- **Rétrécissement des bandes** : Volatilité faible (consolidation)
- **Élargissement des bandes** : Volatilité élevée (mouvement)

### Donchian Channels
- **Prix > Canal supérieur** : Breakout haussier
- **Prix < Canal inférieur** : Breakout baissier
- **Largeur du canal** : Volatilité du marché

### OBV (On-Balance Volume)
- **OBV croissant** : Accumulation (tendance haussière)
- **OBV décroissant** : Distribution (tendance baissière)
- **Divergence prix/OBV** : Signal de retournement

### VWAP
- **Prix > VWAP** : Force d'achat
- **Prix < VWAP** : Force de vente
- **VWAP comme support/résistance** : Niveau psychologique

### StochRSI
- **> 0.8** : Surachat (signal de vente)
- **< 0.2** : Survente (signal d'achat)
- **0.2-0.8** : Zone neutre
- **Signaux plus rapides** que RSI classique

### ADX (Average Directional Index)
- **> 25** : Tendance forte
- **20-25** : Tendance modérée
- **< 20** : Tendance faible ou marché plat
- **+DI > -DI** : Tendance haussière
- **+DI < -DI** : Tendance baissière

### Ichimoku Kinko Hyo
- **Tenkan > Kijun** : Tendance haussière
- **Tenkan < Kijun** : Tendance baissière
- **Prix > Senkou Span A** : Support haussier
- **Prix < Senkou Span A** : Résistance baissière
- **Chikou Span** : Confirmation de tendance

## 🔧 Maintenance

### Rafraîchissement des vues
Les vues matérialisées doivent être rafraîchies régulièrement :

```sql
REFRESH MATERIALIZED VIEW mv_rsi14_5m;
REFRESH MATERIALIZED VIEW mv_macd_5m;
REFRESH MATERIALIZED VIEW mv_boll20_5m;
REFRESH MATERIALIZED VIEW mv_donchian20_5m;
REFRESH MATERIALIZED VIEW mv_obv_5m;
REFRESH MATERIALIZED VIEW mv_vwap_5m;
```

### Surveillance des performances
```sql
-- Vérifier la taille des vues
SELECT 
    schemaname,
    matviewname,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||matviewname)) as size
FROM pg_matviews 
WHERE matviewname LIKE 'mv_%';
```

## 📊 Exemple d'analyse combinée

```sql
WITH latest_signals AS (
    SELECT 
        r.bucket,
        r.rsi,
        m.macd,
        m.signal as macd_signal,
        b.upper as bb_upper,
        b.lower as bb_lower,
        b.sma as bb_sma,
        v.vwap
    FROM mv_rsi14_5m r
    LEFT JOIN mv_macd_5m m ON r.symbol = m.symbol AND r.bucket = m.bucket
    LEFT JOIN mv_boll20_5m b ON r.symbol = b.symbol AND r.bucket = b.bucket
    LEFT JOIN mv_vwap_5m v ON r.symbol = v.symbol AND r.bucket = v.bucket
    WHERE r.symbol = 'BTCUSDT'
    ORDER BY r.bucket DESC
    LIMIT 1
)
SELECT 
    CASE 
        WHEN rsi > 70 AND macd < macd_signal THEN '🔴 FORT SIGNAL VENTE'
        WHEN rsi < 30 AND macd > macd_signal THEN '🟢 FORT SIGNAL ACHAT'
        WHEN rsi > 70 THEN '🟡 SIGNAL VENTE (RSI)'
        WHEN rsi < 30 THEN '🟡 SIGNAL ACHAT (RSI)'
        ELSE '⚪ NEUTRE'
    END as signal_global
FROM latest_signals;
```

## 📈 EMA (Exponential Moving Average)

### Description
Les moyennes mobiles exponentielles (EMA) sont des indicateurs de tendance qui donnent plus de poids aux prix récents. Le système implémente 4 EMA : 9, 21, 50 et 200 périodes.

### Vue matérialisée : `mv_ema_5m`

```sql
-- Structure de la vue
SELECT 
    symbol,
    timeframe,
    bucket,
    ema9,    -- EMA 9 périodes
    ema21,   -- EMA 21 périodes  
    ema50,   -- EMA 50 périodes
    ema200   -- EMA 200 périodes
FROM mv_ema_5m;
```

### Interprétation

#### Tendance générale
- **EMA9 > EMA21 > EMA50 > EMA200** : Tendance haussière forte
- **EMA9 < EMA21 < EMA50 < EMA200** : Tendance baissière forte
- **EMA mixtes** : Consolidation ou changement de tendance

#### Signaux de trading
- **Croisement EMA9/EMA21** : Signal court terme
- **Croisement EMA21/EMA50** : Signal moyen terme
- **Position prix/EMA** : Support/résistance dynamique

### Exemple d'utilisation

```sql
-- Analyse de tendance EMA
SELECT 
    bucket,
    ema9,
    ema21,
    ema50,
    ema200,
    CASE 
        WHEN ema9 > ema21 AND ema21 > ema50 AND ema50 > ema200 
        THEN 'Tendance haussière forte'
        WHEN ema9 < ema21 AND ema21 < ema50 AND ema50 < ema200 
        THEN 'Tendance baissière forte'
        ELSE 'Tendance mixte'
    END as tendance
FROM mv_ema_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 5;
```

### Fonctions spécialisées

#### `ema_strict()` - Pour backtesting
```sql
-- Calcul EMA conforme TA-Lib (seed = SMA)
SELECT ema_strict(ARRAY[100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110], 20);
```

#### `ema()` - Agrégat temps réel
```sql
-- Calcul incrémental pour trading live
SELECT ema(close_price, 2.0/21.0) FROM klines WHERE symbol = 'BTCUSDT';
```

## 🚨 Limitations actuelles

1. **Timeframe unique** : Actuellement configuré pour 5m uniquement
2. **Pas de TimescaleDB** : Utilise PostgreSQL standard
3. **Calculs simplifiés** : Certains indicateurs utilisent des approximations
4. **Pas de rafraîchissement automatique** : Manuel via scripts

## 🔮 Améliorations futures

1. **Support multi-timeframes** : 1m, 15m, 1h, 4h
2. **Intégration TimescaleDB** : Pour de meilleures performances
3. **Rafraîchissement automatique** : Via triggers ou cron
4. **Indicateurs supplémentaires** : ADX, Ichimoku, StochRSI, etc.
5. **API REST** : Endpoints pour accéder aux indicateurs
6. **Alertes** : Notifications sur signaux importants

## 📚 Ressources

- [Investopedia - Technical Analysis](https://www.investopedia.com/technical-analysis-4689657)
- [TA-Lib Documentation](https://ta-lib.org/)
- [PostgreSQL Materialized Views](https://www.postgresql.org/docs/current/rules-materializedviews.html)
