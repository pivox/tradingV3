# IndicatorContextBuilder - Corrections et Améliorations

## Résumé des Corrections

Le `IndicatorContextBuilder` a été corrigé pour fournir toutes les clés manquantes nécessaires aux conditions d'indicateurs.

## Clés Ajoutées

### 1. Indicateur ADX
- **Clé**: `adx[14]` - Valeur ADX avec période 14
- **Utilisé par**: `PriceRegimeOkCondition`

### 2. Données de Position
- **Clé**: `entry_price` - Prix d'entrée de la position
- **Clé**: `stop_loss` - Prix de stop loss
- **Utilisé par**: `AtrStopValidCondition`

### 3. Paramètres ATR
- **Clé**: `atr_k` / `k` - Multiplicateur ATR (alias pour compatibilité)
- **Clé**: `min_atr_pct` - Pourcentage minimum ATR
- **Clé**: `max_atr_pct` - Pourcentage maximum ATR
- **Utilisé par**: `AtrStopValidCondition`, `AtrVolatilityOkCondition`

### 4. Paramètres RSI
- **Clé**: `rsi_lt_70_threshold` - Seuil personnalisé pour RSI < 70
- **Clé**: `rsi_cross_up_level` - Niveau de croisement RSI vers le haut
- **Clé**: `rsi_cross_down_level` - Niveau de croisement RSI vers le bas
- **Utilisé par**: `RsiLt70Condition`, `RsiCrossUpCondition`, `RsiCrossDownCondition`

## Nouvelles Méthodes

### Configuration des Paramètres
```php
$context = $builder
    ->entryPrice(51200.0)           // Prix d'entrée
    ->stopLoss(51000.0)             // Stop loss
    ->atrK(1.5)                     // Multiplicateur ATR
    ->minAtrPct(0.001)              // 0.1% minimum ATR
    ->maxAtrPct(0.03)               // 3% maximum ATR
    ->rsiLt70Threshold(70.0)         // Seuil RSI
    ->rsiCrossUpLevel(30.0)          // Niveau croisement up
    ->rsiCrossDownLevel(70.0)        // Niveau croisement down
    ->build();
```

### Configuration par Défaut
```php
$context = $builder
    ->symbol('BTCUSDT')
    ->timeframe('1h')
    ->closes([...])
    ->highs([...])
    ->lows([...])
    ->volumes([...])
    ->withDefaults()  // Configure tous les paramètres par défaut
    ->build();
```

## Valeurs par Défaut

- `atr_k`: 1.5
- `min_atr_pct`: 0.001 (0.1%)
- `max_atr_pct`: 0.03 (3%)
- `rsi_lt_70_threshold`: 70.0
- `rsi_cross_up_level`: 30.0
- `rsi_cross_down_level`: 70.0

## Conditions Supportées

Toutes les conditions suivantes sont maintenant entièrement supportées :

### Conditions EMA
- `ema_20_gt_50` - EMA 20 > EMA 50
- `ema_20_lt_50` - EMA 20 < EMA 50
- `ema_50_gt_200` - EMA 50 > EMA 200
- `ema_50_lt_200` - EMA 50 < EMA 200

### Conditions MACD
- `macd_hist_gt_0` - Histogramme MACD > 0
- `macd_hist_lt_0` - Histogramme MACD < 0
- `macd_signal_cross_up` - Croisement MACD vers le haut
- `macd_signal_cross_down` - Croisement MACD vers le bas

### Conditions RSI
- `rsi_gt_30` - RSI > 30
- `rsi_lt_70` - RSI < 70
- `rsi_cross_up` - Croisement RSI vers le haut
- `rsi_cross_down` - Croisement RSI vers le bas

### Conditions Prix
- `close_above_ema_200` - Prix > EMA 200
- `close_below_ema_200` - Prix < EMA 200
- `close_above_vwap` - Prix > VWAP
- `close_below_vwap` - Prix < VWAP

### Conditions ATR
- `atr_stop_valid` - Validation du stop basé sur ATR
- `atr_volatility_ok` - Validation de la volatilité ATR

### Conditions Avancées
- `price_regime_ok` - Validation du régime de prix avec ADX

## Exemples d'Utilisation

Voir les fichiers :
- `IndicatorContextBuilderExample.php` - Exemples d'utilisation
- `IndicatorContextBuilderTest.php` - Tests des conditions

## Migration

Si vous utilisez déjà `IndicatorContextBuilder`, aucune modification n'est nécessaire. Les nouvelles clés sont optionnelles et n'affectent pas le comportement existant.

Pour bénéficier des nouvelles fonctionnalités, ajoutez simplement les appels aux nouvelles méthodes :

```php
// Avant
$context = $builder->build();

// Après (optionnel)
$context = $builder->withDefaults()->build();

// Ou avec paramètres personnalisés
$context = $builder
    ->entryPrice($price)
    ->stopLoss($stop)
    ->atrK(2.0)
    ->build();
```


