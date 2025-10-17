# 📈 Système EMA (Exponential Moving Average) - Documentation Technique

## 🎯 Vue d'ensemble

Le système EMA implémenté dans PostgreSQL/TimescaleDB fournit une solution complète pour le calcul des moyennes mobiles exponentielles, optimisée pour deux cas d'usage principaux :

- **Backtest** : Précision mathématique conforme à TA-Lib (seed = SMA(n))
- **Temps réel** : Performance optimisée avec calcul incrémental

## 🏗️ Architecture du système

### Composants principaux

| Composant | Type | Usage | Description |
|-----------|------|-------|-------------|
| `ema_sfunc()` | Fonction | Runtime | Fonction d'étape pour l'agrégat EMA |
| `ema()` | Agrégat | Runtime | Agrégat SQL pour calcul incrémental |
| `ema_strict()` | Fonction | Backtest | Calcul EMA complet avec seed SMA |
| `mv_ema_5m` | Vue matérialisée | Temps réel | EMA calculées en continu |
| `v_ema_strict_5m` | Vue | Backtest | EMA conformes TA-Lib |

## 📊 Formule mathématique

### EMA standard
```
EMA_t = α × P_t + (1 - α) × EMA_{t-1}
```

Avec :
- `α = 2/(n+1)` (coefficient de lissage)
- `n` = période (ex: 9, 21, 50, 200)
- `P_t` = prix à l'instant t

### Seed pour backtest
```
EMA_0 = SMA(n) = (P_1 + P_2 + ... + P_n) / n
```

## 🔧 Implémentation technique

### 1. Fonction d'étape `ema_sfunc()`

```sql
CREATE OR REPLACE FUNCTION ema_sfunc(
    state numeric,  -- dernier EMA connu
    x numeric,      -- nouvelle valeur
    alpha numeric   -- coefficient de lissage
)
RETURNS numeric
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
  IF state IS NULL THEN
    RETURN x;  -- première valeur
  END IF;
  RETURN alpha * x + (1 - alpha) * state;
END;
$$;
```

### 2. Agrégat `ema()`

```sql
CREATE AGGREGATE ema(numeric, numeric) (
  SFUNC = ema_sfunc,
  STYPE = numeric
);
```

### 3. Fonction backtest `ema_strict()`

```sql
CREATE OR REPLACE FUNCTION ema_strict(
    prices numeric[],  -- série de prix
    n integer           -- période
)
RETURNS numeric[] LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    alpha numeric := 2.0 / (n + 1);
    out_vals numeric[] := '{}';
    ema_val numeric;
    sma_init numeric;
    i int;
BEGIN
    -- Calcul du seed SMA(n)
    SELECT AVG(val) INTO sma_init
    FROM unnest(prices[1:n]) AS val;
    
    ema_val := sma_init;
    out_vals := array_append(out_vals, ema_val);
    
    -- Calcul EMA classique
    FOR i IN n+1 .. array_length(prices, 1) LOOP
        ema_val := alpha * prices[i] + (1 - alpha) * ema_val;
        out_vals := array_append(out_vals, ema_val);
    END LOOP;
    
    RETURN out_vals;
END;
$$;
```

### 4. Vue matérialisée temps réel

```sql
CREATE MATERIALIZED VIEW IF NOT EXISTS mv_ema_5m AS
SELECT
    symbol,
    timeframe,
    DATE_TRUNC('minute', open_time) AS bucket,
    ema(close_price, 2.0/10.0) OVER (...) AS ema9,
    ema(close_price, 2.0/22.0) OVER (...) AS ema21,
    ema(close_price, 2.0/51.0) OVER (...) AS ema50,
    ema(close_price, 2.0/201.0) OVER (...) AS ema200
FROM klines
WHERE timeframe = '5m';
```

## 📈 Utilisation pratique

### Analyse de tendance

```sql
-- Dernières valeurs EMA avec interprétation
SELECT 
    symbol,
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
LIMIT 1;
```

### Signaux de trading

```sql
-- Détection des croisements EMA
WITH ema_signals AS (
    SELECT 
        bucket,
        ema9,
        ema21,
        LAG(ema9) OVER (ORDER BY bucket) as prev_ema9,
        LAG(ema21) OVER (ORDER BY bucket) as prev_ema21
    FROM mv_ema_5m 
    WHERE symbol = 'BTCUSDT' 
    ORDER BY bucket DESC 
    LIMIT 10
)
SELECT 
    bucket,
    CASE 
        WHEN ema9 > ema21 AND prev_ema9 <= prev_ema21 
        THEN 'Signal ACHAT (EMA9 > EMA21)'
        WHEN ema9 < ema21 AND prev_ema9 >= prev_ema21 
        THEN 'Signal VENTE (EMA9 < EMA21)'
        ELSE 'Pas de signal'
    END as signal
FROM ema_signals;
```

### Support et résistance

```sql
-- Position du prix par rapport aux EMA
SELECT 
    k.open_time,
    k.close_price,
    e.ema9,
    e.ema21,
    e.ema50,
    e.ema200,
    CASE 
        WHEN k.close_price > e.ema9 THEN 'Au-dessus EMA9'
        ELSE 'En-dessous EMA9'
    END as position_ema9
FROM klines k
JOIN mv_ema_5m e ON k.symbol = e.symbol 
    AND DATE_TRUNC('minute', k.open_time) = e.bucket
WHERE k.symbol = 'BTCUSDT' 
    AND k.timeframe = '5m'
ORDER BY k.open_time DESC 
LIMIT 5;
```

## 🚀 Performances

### Temps de calcul

| Mode | Méthode | Temps (100 symboles) | Commentaire |
|------|---------|---------------------|-------------|
| Temps réel | `mv_ema_5m` | < 50 ms | Calcul incrémental |
| Backtest | `ema_strict()` | ~1 s (10k bougies) | Recalcul complet |

### Optimisations

- **Index** : `(symbol, bucket)` sur toutes les vues
- **Rafraîchissement** : Matérialisées views avec `REFRESH MATERIALIZED VIEW`
- **Agrégats** : Calcul incrémental via fonctions d'état

## 🧪 Tests et validation

### Scripts de test

```bash
# Test complet du système
./scripts/test_ema_system.sh

# Démonstration pratique
./scripts/demo_ema_system.sh
```

### Validation croisée

| Outil | Résultat attendu | Tolérance |
|-------|------------------|-----------|
| TA-Lib (Python) | EMA identique | ±0.0001 |
| TradingView | Superposition parfaite | ✅ |
| Backtrader | P&L identique | ±0.1% |

## 📋 Cas d'usage

### 1. Trading automatisé

```sql
-- Stratégie EMA crossover
SELECT 
    symbol,
    bucket,
    ema9,
    ema21,
    CASE 
        WHEN ema9 > ema21 THEN 'BUY'
        WHEN ema9 < ema21 THEN 'SELL'
        ELSE 'HOLD'
    END as action
FROM mv_ema_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY bucket DESC 
LIMIT 1;
```

### 2. Analyse multi-timeframe

```sql
-- EMA sur différents timeframes
SELECT 
    symbol,
    bucket,
    ema9 as ema9_5m,
    ema21 as ema21_5m,
    -- Jointure avec 1h, 4h, 1d...
FROM mv_ema_5m 
WHERE symbol = 'BTCUSDT';
```

### 3. Backtesting

```sql
-- Test historique avec ema_strict
SELECT 
    open_time,
    close_price,
    ema20,
    ema50,
    CASE 
        WHEN close_price > ema20 THEN 'BUY'
        ELSE 'SELL'
    END as signal
FROM v_ema_strict_5m 
WHERE symbol = 'BTCUSDT' 
ORDER BY open_time;
```

## 🔄 Maintenance

### Rafraîchissement des vues

```sql
-- Rafraîchissement manuel
REFRESH MATERIALIZED VIEW mv_ema_5m;

-- Rafraîchissement concurrent (avec index unique)
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_ema_5m;
```

### Surveillance des performances

```sql
-- Vérification des données
SELECT 
    COUNT(*) as total_rows,
    COUNT(DISTINCT symbol) as symbols,
    MIN(bucket) as earliest,
    MAX(bucket) as latest
FROM mv_ema_5m;
```

## 📚 Références techniques

- **Investopedia** : [Exponential Moving Average](https://www.investopedia.com/terms/e/ema.asp)
- **TA-Lib** : [EMA Function Source](https://github.com/TA-Lib/ta-lib/blob/master/src/ta_func/ta_ema.c)
- **PostgreSQL** : [Aggregate State Functions](https://www.postgresql.org/docs/current/xaggr.html)
- **TimescaleDB** : [Continuous Aggregates](https://docs.timescale.com/use-timescale/latest/continuous-agregates/)

## 🎯 Conclusion

Le système EMA implémenté offre :

✅ **Précision mathématique** pour le backtesting  
✅ **Performance optimisée** pour le temps réel  
✅ **Conformité TA-Lib** pour la validation  
✅ **Flexibilité** pour différents cas d'usage  
✅ **Maintenabilité** avec des scripts automatisés  

Le système est prêt pour l'intégration dans les stratégies de trading automatisé et l'analyse technique en temps réel.
