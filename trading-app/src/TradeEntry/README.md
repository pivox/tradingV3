# TradeEntry - Architecture d'Entrée en Position

## Vue d'ensemble

Le système TradeEntry implémente une architecture complète pour l'entrée en position trading, suivant le flux : **PreOrder → EntryZone → RiskSizer → OrderPlan → Execution**.

## Architecture

```
TradeEntryBox (Orchestrateur principal)
├── PreOrderBuilder (Mapping des données d'entrée)
├── EntryZoneBox (Calcul et filtrage de la zone d'entrée)
│   ├── EntryZoneCalculator
│   └── EntryZoneFilters
├── RiskSizerBox (Gestion du risque et levier)
│   └── LeverageCalculator
├── OrderPlanBox (Plan d'ordre)
│   └── OrderPlanBuilder
└── ExecutionBox (Exécution via MainProvider)
```

## Utilisation

### Via le Contrôleur (Recommandé)

```bash
# Test avec données d'exemple
GET /api/trade-entry/test

# Exécution réelle
POST /api/trade-entry/execute
Content-Type: application/json

{
    "symbol": "BTCUSDT",
    "side": "long",
    "entry_price_base": 67250.0,
    "atr_value": 35.0,
    "pivot_price": 67220.0,
    "risk_pct": 2.0,
    "budget_usdt": 100.0,
    "equity_usdt": 1000.0,
    "rsi": 54.0,
    "volume_ratio": 1.8,
    "pullback_confirmed": true
}
```

### Via l'Injection de Dépendance

```php
use App\TradeEntry\TradeEntryBox;
use App\TradeEntry\Types\Side;

class MyService
{
    public function __construct(
        private TradeEntryBox $tradeEntryBox
    ) {}

    public function executeTrade(): void
    {
        $input = [
            'symbol' => 'BTCUSDT',
            'side' => Side::LONG,
            'entry_price_base' => 67250.0,
            'atr_value' => 35.0,
            'pivot_price' => 67220.0,
            'risk_pct' => 2.0,
            'budget_usdt' => 100.0,
            'equity_usdt' => 1000.0,
        ];

        $result = $this->tradeEntryBox->handle($input);
        
        if ($result->status === 'order_opened') {
            echo "Ordre placé: " . $result->data['order_id'];
        } else {
            echo "Annulé: " . $result->data['reason'];
        }
    }
}
```

## Configuration

Les valeurs par défaut sont configurées dans `config/services.yaml` :

```yaml
parameters:
    trade_entry.defaults:
        tick_size: 0.1
        zone_ttl_sec: 240
        k_low: 1.2
        k_high: 0.4
        k_stop_atr: 1.5
        tp1_r: 2.0
        tp1_size_pct: 60
        lev_min: 2.0
        lev_max: 20.0
        k_dynamic: 10.0
        rsi_cap: 70.0
        require_pullback: true
        min_volume_ratio: 1.5
```

## Paramètres d'Entrée

### Obligatoires
- `symbol`: Symbole du contrat (ex: "BTCUSDT")
- `side`: Direction ("long" ou "short")
- `entry_price_base`: Prix de référence d'entrée
- `atr_value`: Valeur ATR calculée
- `pivot_price`: Prix pivot (VWAP, MA21, etc.)
- `risk_pct`: Pourcentage de risque (% du capital)
- `budget_usdt`: Budget alloué en USDT
- `equity_usdt`: Capital total

### Optionnels
- `rsi`: Valeur RSI pour filtrage
- `volume_ratio`: Ratio de volume
- `pullback_confirmed`: Confirmation de pullback
- `tick_size`: Taille de tick pour quantification
- `zone_ttl_sec`: Durée de vie de la zone (défaut: 240s)
- `k_low`, `k_high`: Coefficients de zone
- `k_stop_atr`: Distance SL en ATR
- `tp1_r`: Ratio R pour TP1
- `tp1_size_pct`: Pourcentage à TP1
- `lev_min`, `lev_max`: Bornes de levier
- `k_dynamic`: Coefficient dynamique
- `rsi_cap`: Seuil RSI maximum
- `require_pullback`: Exiger pullback
- `min_volume_ratio`: Ratio volume minimum

## Résultats

### Succès
```json
{
    "status": "order_opened",
    "data": {
        "order_id": "123456789",
        "symbol": "BTCUSDT",
        "side": "long",
        "price": 67250.0,
        "quantity": 0.001,
        "sl_price": 67000.0,
        "tp1_price": 67500.0,
        "tp1_size_pct": 60,
        "status": "new"
    }
}
```

### Échec
```json
{
    "status": "cancelled",
    "data": {
        "reason": "entry_zone_invalid_or_filters_failed"
    }
}
```

## Logging

Le système utilise le logger `trade_entry` pour tracer l'exécution :

```yaml
# Dans services.yaml
monolog.logger.trade_entry:
    parent: monolog.logger
    arguments:
        - trade_entry
    tags:
        - { name: monolog.logger, channel: trade_entry }
```

## Tests

```bash
# Lancer les tests unitaires
./bin/phpunit tests/TradeEntry/TradeEntryBoxTest.php

# Test d'intégration via API
curl -X GET http://localhost/api/trade-entry/test
```

## Intégration Exchange

L'ExecutionBox utilise le MainProvider pour placer les ordres réels via l'interface OrderProviderInterface. L'intégration est transparente et utilise les mêmes interfaces que le reste du système.
