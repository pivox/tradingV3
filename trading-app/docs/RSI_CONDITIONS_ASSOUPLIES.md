# Conditions RSI Assouplies - Documentation

## Vue d'ensemble

Ce document décrit les nouvelles conditions RSI créées pour assouplir les critères de trading et capturer plus d'opportunités tout en maintenant la sécurité.

## Nouvelles Classes Créées

### 1. Conditions d'Entrée (Assouplies)

#### `RsiLt80Condition` - RSI < 80
- **Usage** : Timeframes 15m et 5m pour les positions long
- **Seuil** : 80.0 (au lieu de 70.0)
- **Justification** : Permet d'entrer en momentum fort sans attendre un pullback

#### `RsiLt85Condition` - RSI < 85  
- **Usage** : Timeframe 1m pour les positions long
- **Seuil** : 85.0 (au lieu de 70.0)
- **Justification** : Ultra-assoupli pour le micro-timing, capture les mouvements rapides

#### `RsiGt20Condition` - RSI > 20
- **Usage** : Timeframes 15m et 5m pour les positions short
- **Seuil** : 20.0 (au lieu de 30.0)
- **Justification** : Permet d'entrer en momentum baissier sans attendre un rebond

#### `RsiGt15Condition` - RSI > 15
- **Usage** : Timeframe 1m pour les positions short
- **Seuil** : 15.0 (au lieu de 30.0)
- **Justification** : Ultra-assoupli pour le micro-timing, capture les mouvements baissiers rapides

### 2. Conditions de Protection (Anti-Retournement)

#### `RsiGt85Condition` - RSI > 85
- **Usage** : Protection contre les longs en surachat extrême
- **Seuil** : 85.0 (au lieu de 70.0)
- **Justification** : Bloque uniquement les zones de surachat extrême

#### `RsiLt15Condition` - RSI < 15
- **Usage** : Protection contre les shorts en survente extrême
- **Seuil** : 15.0 (au lieu de 30.0)
- **Justification** : Bloque uniquement les zones de survente extrême

## Mapping avec la Configuration

### Timeframes d'Exécution

```yaml
# 15m - Assoupli
long: [ema_20_gt_50, macd_hist_gt_0, close_above_vwap, rsi_lt_80]
short: [ema_20_lt_50, macd_hist_lt_0, close_below_vwap, rsi_gt_20]

# 5m - Assoupli (VWAP retiré)
long: [ema_20_gt_50, macd_hist_gt_0, rsi_lt_80]
short: [ema_20_lt_50, macd_hist_lt_0, rsi_gt_20]

# 1m - Ultra-assoupli (VWAP retiré)
long: [ema_20_gt_50, macd_hist_gt_0, rsi_lt_85]
short: [ema_20_lt_50, macd_hist_lt_0, rsi_gt_15]
```

### Protection Anti-Retournement

```yaml
reversal_protection:
    none_of:
        - rsi_gt_85  # Éviter longs en surachat extrême
        - rsi_lt_15  # Éviter shorts en survente extrême
```

## Bénéfices Attendus

1. **Plus d'Opportunités** : Seuils RSI élargis capturent plus de setups
2. **Flexibilité Micro** : Conditions ultra-assouplies sur 1m pour timing réactif
3. **Sécurité Maintenue** : Protection contre les zones extrêmes conservée
4. **Moins de Faux Rejets** : Évite les blocages sur des mouvements légitimes

## Points de Vigilance

- **Surveiller le Win Rate** : Plus d'opportunités = potentiellement plus de faux signaux
- **Ajuster le Risk Management** : Considérer réduire légèrement le levier si nécessaire
- **Monitoring RSI** : Vérifier l'efficacité des nouveaux seuils

## Utilisation dans le Code

```php
// Exemple d'utilisation
$rsiLt80Condition = new RsiLt80Condition();
$result = $rsiLt80Condition->evaluate([
    'rsi' => 75.5,
    'symbol' => 'BTCUSDT',
    'timeframe' => '15m'
]);

if ($result->isPassed()) {
    // Condition RSI < 80 validée
}
```

## Migration depuis les Anciennes Conditions

Les anciennes conditions `RsiLt70Condition` et `RsiGt30Condition` restent disponibles pour compatibilité, mais les nouvelles conditions assouplies sont recommandées pour une meilleure capture d'opportunités.
