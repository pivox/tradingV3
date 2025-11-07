# Endpoints des Indicateurs Techniques

Ce document liste tous les endpoints API et web qui calculent ou listent les indicateurs techniques.

## API Endpoints (`/api/indicators`)

### 1. Liste des indicateurs disponibles
- **Route**: `GET /api/indicators/available`
- **Nom**: `api_indicators_available`
- **Description**: Retourne la liste de tous les indicateurs techniques disponibles
- **Paramètres**: Aucun
- **Réponse**: 
  ```json
  {
    "indicators": ["rsi", "ema", "macd", "atr", "bollinger", "sma", "vwap", "adx", ...]
  }
  ```

### 2. Calcul des points pivots
- **Route**: `GET /api/indicators/pivots`
- **Nom**: `api_indicator_pivots`
- **Description**: Calcule les points pivots pour un symbole et un timeframe donnés
- **Paramètres query**:
  - `symbol` (requis): Symbole du contrat (ex: BTCUSDT)
  - `timeframe` (requis): Timeframe (ex: 1h, 4h, 15m, etc.)
  - `with-k` (optionnel): Si égal à `1`, inclut les klines utilisées pour le calcul
- **Réponse** (sans `with-k=1`):
  ```json
  {
    "symbol": "BTCUSDT",
    "timeframe": "1h",
    "pivot_levels": {
      "pivot": 50000,
      "resistance1": 51000,
      "resistance2": 52000,
      "support1": 49000,
      "support2": 48000
    }
  }
  ```
- **Réponse** (avec `with-k=1`):
  ```json
  {
    "symbol": "BTCUSDT",
    "timeframe": "1h",
    "pivot_levels": {
      "pivot": 50000,
      "resistance1": 51000,
      "resistance2": 52000,
      "support1": 49000,
      "support2": 48000
    },
    "klines": [
      {
        "openTime": 1704067200000,
        "open": 50000.0,
        "high": 50100.0,
        "low": 49900.0,
        "close": 50050.0,
        "volume": 1000.0,
        "closeTime": 1704067200000,
        "quoteAssetVolume": 1000.0,
        "numberOfTrades": 0,
        "takerBuyBaseAssetVolume": 1000.0,
        "takerBuyQuoteAssetVolume": 1000.0
      }
      // ... autres klines (200 au total, triées du plus récent au plus ancien)
    ]
  }
  ```
- **Exemples d'utilisation**:
  - `GET /api/indicators/pivots?symbol=BTCUSDT&timeframe=15m` → Retourne uniquement les pivots
  - `GET /api/indicators/pivots?symbol=BTCUSDT&timeframe=15m&with-k=1` → Retourne les pivots + les klines

### 3. Calcul de tous les indicateurs
- **Route**: `GET /api/indicators/values`
- **Nom**: `api_indicator_values`
- **Description**: Calcule et retourne tous les indicateurs techniques pour un symbole et un timeframe
- **Paramètres query**:
  - `symbol` (requis): Symbole du contrat
  - `timeframe` (requis): Timeframe
  - `with-k` (optionnel): Si égal à `1`, inclut les klines utilisées pour le calcul
- **Réponse** (sans `with-k=1`):
  ```json
  {
    "symbol": "BTCUSDT",
    "timeframe": "1h",
    "indicators": {
      "rsi": 65.5,
      "ema": { "20": 50000, "50": 49500, "200": 48000 },
      "macd": { "macd": 100, "signal": 90, "hist": 10 },
      "atr": 500,
      "bollinger": { "upper": 51000, "middle": 50000, "lower": 49000 },
      "sma": { "9": 50100, "21": 49900 },
      "vwap": 50050,
      "adx": 25.5
    },
    "descriptions": {
      "rsi": "Relative Strength Index",
      ...
    }
  }
  ```
- **Réponse** (avec `with-k=1`):
  ```json
  {
    "symbol": "BTCUSDT",
    "timeframe": "1h",
    "indicators": {
      "rsi": 65.5,
      "ema": { "20": 50000, "50": 49500, "200": 48000 },
      "macd": { "macd": 100, "signal": 90, "hist": 10 },
      "atr": 500,
      "bollinger": { "upper": 51000, "middle": 50000, "lower": 49000 },
      "sma": { "9": 50100, "21": 49900 },
      "vwap": 50050,
      "adx": 25.5
    },
    "descriptions": {
      "rsi": "Relative Strength Index",
      ...
    },
    "klines": [
      {
        "openTime": 1704067200000,
        "open": 50000.0,
        "high": 50100.0,
        "low": 49900.0,
        "close": 50050.0,
        "volume": 1000.0,
        "closeTime": 1704067200000,
        "quoteAssetVolume": 1000.0,
        "numberOfTrades": 0,
        "takerBuyBaseAssetVolume": 1000.0,
        "takerBuyQuoteAssetVolume": 1000.0
      }
      // ... autres klines (200 au total, triées du plus récent au plus ancien)
    ]
  }
  ```
- **Exemples d'utilisation**:
  - `GET /api/indicators/values?symbol=BTCUSDT&timeframe=15m` → Retourne uniquement les indicateurs
  - `GET /api/indicators/values?symbol=BTCUSDT&timeframe=15m&with-k=1` → Retourne les indicateurs + les klines

### 4. Calcul de l'ATR (Average True Range)
- **Route**: `GET /api/indicators/atr`
- **Nom**: `api_indicator_atr`
- **Description**: Calcule l'ATR et retourne les klines utilisées pour le calcul
- **Paramètres query**:
  - `symbol` (requis): Symbole du contrat
  - `timeframe` (optionnel): Timeframe(s) demandé(s), séparés par des virgules (ex: `1h,4h,15m`). Par défaut: `1m,5m`
- **Réponse**:
  ```json
  {
    "symbol": "BTCUSDT",
    "atr": {
      "1m": 50.5,
      "5m": 250.3,
      "1h": 1200.8
    },
    "klines": {
      "1m": [
        {
          "openTime": 1704067200000,
          "open": 50000.0,
          "high": 50100.0,
          "low": 49900.0,
          "close": 50050.0,
          "volume": 1000.0,
          "closeTime": 1704067200000,
          "quoteAssetVolume": 1000.0,
          "numberOfTrades": 0,
          "takerBuyBaseAssetVolume": 1000.0,
          "takerBuyQuoteAssetVolume": 1000.0
        }
        // ... autres klines (200 au total, triées du plus récent au plus ancien)
      ],
      "5m": [...],
      "1h": [...]
    },
    "missing_timeframes": [] // Optionnel, si des timeframes n'ont pas de données
  }
  ```
- **Exemples d'utilisation**:
  - `GET /api/indicators/atr?symbol=BTCUSDT` → Retourne ATR pour 1m et 5m (comportement par défaut)
  - `GET /api/indicators/atr?symbol=BTCUSDT&timeframe=1h` → Retourne ATR pour 1h uniquement
  - `GET /api/indicators/atr?symbol=BTCUSDT&timeframe=1h,4h,15m` → Retourne ATR pour 1h, 4h et 15m

## Web Endpoints (`/indicators`)

### 5. Page de test des indicateurs
- **Route**: `GET /indicators/test`
- **Nom**: `indicators_test`
- **Description**: Page web pour tester et visualiser les indicateurs techniques
- **Paramètres**: Aucun
- **Réponse**: Page HTML avec interface de test

### 6. Évaluation des indicateurs
- **Route**: `POST /indicators/evaluate`
- **Nom**: `indicators_evaluate`
- **Description**: Évalue tous les indicateurs et conditions pour un contexte donné
- **Body JSON**:
  ```json
  {
    "symbol": "BTCUSDT",
    "timeframe": "1h",
    "custom_data": { // Optionnel
      "closes": [50000, 50100, ...],
      "highs": [51000, 51100, ...],
      "lows": [49000, 49100, ...],
      "volumes": [1000, 1100, ...]
    },
    "klines_json": [...] // Optionnel, données klines brutes
  }
  ```
- **Réponse**:
  ```json
  {
    "success": true,
    "data": {
      "context": {...},
      "conditions_results": {...},
      "timeframe_validation": {
        "long": {...},
        "short": {...}
      },
      "summary": {
        "total_conditions": 50,
        "passed": 35,
        "failed": 15,
        "errors": 0,
        "success_rate": 70.0
      },
      "trading_config": {...},
      "timestamp": "2024-01-01 12:00:00"
    }
  }
  ```

### 7. Détail d'une condition spécifique
- **Route**: `GET /indicators/condition/{conditionName}`
- **Nom**: `indicators_condition_detail`
- **Description**: Évalue une condition spécifique (ex: RsiGt85Condition)
- **Paramètres URL**:
  - `conditionName`: Nom de la condition (ex: `RsiGt85Condition`)
- **Réponse**:
  ```json
  {
    "success": true,
    "data": {
      "condition_name": "RsiGt85Condition",
      "result": {
        "passed": true,
        "value": 87.5,
        "meta": {...}
      },
      "context": {...},
      "timestamp": "2024-01-01 12:00:00"
    }
  }
  ```

### 8. Liste des conditions disponibles
- **Route**: `GET /indicators/available-conditions`
- **Nom**: `indicators_available_conditions`
- **Description**: Liste toutes les conditions d'indicateurs disponibles
- **Paramètres**: Aucun
- **Réponse**:
  ```json
  {
    "success": true,
    "data": {
      "conditions": [
        "RsiGt85Condition",
        "RsiLt15Condition",
        "MacdHistGt0Condition",
        "Ema20Gt50Condition",
        ...
      ],
      "count": 50,
      "timestamp": "2024-01-01 12:00:00"
    }
  }
  ```

### 9. Liste des contrats disponibles
- **Route**: `GET /indicators/available-contracts`
- **Nom**: `indicators_available_contracts`
- **Description**: Liste tous les contrats actifs disponibles pour les tests
- **Paramètres**: Aucun
- **Réponse**:
  ```json
  {
    "success": true,
    "data": {
      "contracts": ["BTCUSDT", "ETHUSDT", "ADAUSDT", ...],
      "count": 25,
      "timestamp": "2024-01-01 12:00:00"
    }
  }
  ```

### 10. Test de rejeu (replay)
- **Route**: `POST /indicators/replay`
- **Nom**: `indicators_replay`
- **Description**: Exécute plusieurs itérations d'évaluation pour tester la stabilité
- **Body JSON**:
  ```json
  {
    "symbol": "BTCUSDT",
    "timeframe": "1h",
    "iterations": 10
  }
  ```
- **Réponse**:
  ```json
  {
    "success": true,
    "data": {
      "results": [...],
      "stability": {
        "success_rate_avg": 70.5,
        "success_rate_min": 65.0,
        "success_rate_max": 75.0,
        "success_rate_std": 2.5,
        "passed_avg": 35.2,
        "stability_score": 97.5
      },
      "iterations": 10,
      "timestamp": "2024-01-01 12:00:00"
    }
  }
  ```

### 11. Validation en cascade (MTF)
- **Route**: `POST /indicators/validate-cascade`
- **Nom**: `indicators_validate_cascade`
- **Description**: Valide les indicateurs sur plusieurs timeframes en cascade (4h, 1h, 15m, 5m, 1m)
- **Body JSON**:
  ```json
  {
    "date": "2024-01-01",
    "contract": "BTCUSDT"
  }
  ```
- **Réponse**:
  ```json
  {
    "success": true,
    "data": {
      "contract": "BTCUSDT",
      "date": "2024-01-01 00:00:00",
      "overall_status": "valid",
      "timeframes_results": {
        "4h": {
          "status": "VALIDATED",
          "signal": "LONG",
          "validation_result": {...},
          "klines_used": {...},
          "context_summary": {...}
        },
        "1h": {...},
        "15m": {...},
        "5m": {...},
        "1m": {...}
      },
      "summary": {
        "total_timeframes": 5,
        "validated_timeframes": 4,
        "pending_timeframes": 1,
        "failed_timeframes": 0,
        "error_timeframes": 0,
        "validation_rate": 80.0
      }
    }
  }
  ```

### 12. Revalidation de contrats
- **Route**: `POST /indicators/revalidate`
- **Nom**: `indicators_revalidate`
- **Description**: Revalide plusieurs contrats pour une date et un timeframe donnés
- **Body JSON**:
  ```json
  {
    "date": "2024-01-01",
    "contracts": "BTCUSDT,ETHUSDT,ADAUSDT",
    "timeframe": "1h"
  }
  ```
- **Réponse**:
  ```json
  {
    "success": true,
    "data": {
      "global_summary": {
        "total_contracts": 3,
        "successful_validations": 2,
        "failed_validations": 1,
        "success_rate": 66.67,
        "date": "2024-01-01",
        "timeframe": "1h"
      },
      "contracts_results": {
        "BTCUSDT": {
          "contract": "BTCUSDT",
          "date": "2024-01-01",
          "timeframe": "1h",
          "status": "valid",
          "summary": {...},
          "timeframe_validation": {...},
          "conditions_results": {...},
          "context": {...},
          "timestamp": "2024-01-01 12:00:00"
        },
        ...
      },
      "timestamp": "2024-01-01 12:00:00"
    }
  }
  ```

## Indicateurs Techniques Calculés

Les endpoints calculent les indicateurs suivants :

### Momentum
- **RSI** (Relative Strength Index) - 14 périodes
- **MACD** (Moving Average Convergence Divergence) - 12, 26, 9
- **StochRSI** (Stochastic RSI)

### Trend
- **EMA** (Exponential Moving Average) - 20, 50, 200 périodes
- **SMA** (Simple Moving Average) - 9, 21 périodes
- **Ichimoku**
- **ADX** (Average Directional Index) - 14 périodes

### Volatility
- **ATR** (Average True Range) - 14 périodes
- **Bollinger Bands** - 20 périodes, 2 écarts-types
- **Donchian Channels**
- **Choppiness Index**

### Volume
- **VWAP** (Volume Weighted Average Price)
- **OBV** (On-Balance Volume)

### Points Pivots
- Points pivots classiques (Pivot, Résistances R1/R2, Supports S1/S2)

## Notes

- Tous les endpoints API retournent du JSON
- Les timeframes valides sont : `1m`, `5m`, `15m`, `30m`, `1h`, `4h`, `1d`
- Les endpoints web peuvent retourner du HTML (pour `/indicators/test`) ou du JSON
- Les erreurs sont retournées avec les codes HTTP appropriés (400, 404, 500)
- Les endpoints de test (`/indicators/*`) peuvent utiliser des données simulées si les vraies données ne sont pas disponibles

