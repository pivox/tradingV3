# 🚀 Post-Validation - EntryZone + PositionOpener

## Vue d'ensemble

L'étape **Post-Validation** transforme un signal validé (MTF) en plans d'exécution sûrs, traçables et idempotents. Elle implémente la logique complète pour :

- **EntryZone** : Calcul de zone d'entrée ancrée au contexte de marché (ATR, VWAP, profondeur, spreads)
- **PositionOpener** : Plan d'ordres (maker → taker fallback, TP/SL) conforme aux contraintes d'échange
- **Sélection dynamique** : 1m vs 5m basée sur ATR et liquidité
- **Récupération prix** : WS → REST → K-line avec fallback
- **Garde-fous** : Stale data, slippage, liquidité, risk limits
- **Machine d'états** : Orchestration des séquences E2E

## 🎯 Fonctionnalités

### ✅ EntryZone
- **Ancrage VWAP** : Moyenne pondérée intraday comme repère
- **Microstructure** : Bid/ask, spread, profondeur pour qualité d'entrée
- **Contexte ATR** : Largeur de zone proportionnelle à la volatilité
- **Quantification** : Arrondi aux pas exchange (tick/lot)

### ✅ PositionOpener
- **Sizing intelligent** : Basé sur risque et distance de stop (ATR)
- **Levier dynamique** : Respect des brackets exchange avec multiplicateurs TF
- **Plan d'ordres** : Maker (LIMIT GTC) → Taker (IOC/MARKET) fallback
- **TP/SL attachés** : Via endpoint dédié (submit-tp-sl-order)

### ✅ Sélection Timeframe
- **Base 5m** : Réduit bruit et coûts
- **Upshift 1m** : Si ATR élevé + liquidité OK + alignement MTF
- **Downshift 5m** : Si ATR retombe ou spreads s'écartent

### ✅ Récupération Prix
- **Priorité WS** : futures/ticker (last_price, bid/ask, mark/index)
- **Fallback REST** : market-trade (dernier trade)
- **Fallback K-line** : close_price dernière bougie
- **Garde-fou** : Stale > 2s → pas d'ordres

### ✅ Garde-fous
- **Stale data** : Ticker > 2s → STOP
- **Slippage ex-ante** : (entry_price - mid)/mid ≤ max_slip_bps
- **Liquidité** : depth_top ≥ qty requise
- **Risk limits** : levier ≤ bracket max
- **Funding spike** : Ignorer si funding imminent hors tolérance

## 🚀 Démarrage rapide

### 1. Test basique

```bash
# Test avec BTCUSDT LONG en mode dry-run
curl -X POST http://localhost:8082/api/post-validation/execute \
  -H "Content-Type: application/json" \
  -d '{
    "symbol": "BTCUSDT",
    "side": "LONG",
    "mtf_context": {
      "5m": {"signal_side": "LONG", "status": "valid"},
      "15m": {"signal_side": "LONG", "status": "valid"},
      "candle_close_ts": 1704067200,
      "conviction_flag": false
    },
    "wallet_equity": 1000.0,
    "dry_run": true
  }'
```

### 2. Test avec script automatisé

```bash
# Test complet avec tous les scénarios
./scripts/test-post-validation.sh

# Test en mode production (ATTENTION: vrais ordres!)
./scripts/test-post-validation.sh --production
```

### 3. Test de configuration

```bash
# Vérifier la configuration
curl -X GET http://localhost:8082/api/post-validation/test-config
```

## 📋 API Endpoints

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/post-validation/execute` | POST | Exécute Post-Validation |
| `/api/post-validation/statistics` | GET | Statistiques Post-Validation |
| `/api/post-validation/test-config` | GET | Test de configuration |
| `/api/post-validation/docs` | GET | Documentation API |

## 🔄 Logique d'exécution

### 1. Récupération données marché
- **WS futures/ticker** : last_price, bid/ask, mark/index
- **REST market-trade** : dernier trade si WS indisponible
- **REST kline** : close_price si trades indisponibles
- **Calcul indicateurs** : VWAP, ATR(1m), ATR(5m)

### 2. Sélection timeframe d'exécution
```php
tf_exec = "5m"
if ATR(1m)/last >= 0.12 and spread_bps <= 2.0 and depth_top >= 30000 and dir_align(5m,15m):
    tf_exec = "1m"
elif ATR(1m)/last < 0.07 or spread_bps > 4.0:
    tf_exec = "5m"
```

### 3. Calcul EntryZone
```php
zone_width = clamp(0.35 * ATR(tf_exec), 0.0005, 0.0100)

// LONG
entry_min = max(vwap, bid1) - 0.3 * zone_width
entry_max = min(vwap, ask1) + 0.7 * zone_width

// SHORT (symétrique)
entry_min = max(vwap, ask1) - 0.3 * zone_width
entry_max = min(vwap, bid1) + 0.7 * zone_width
```

### 4. Sizing et levier
```php
risk_unit = 0.005 * wallet_equity  // 0.5% du capital
distance_stop = 1.5 * ATR(tf_exec)  // SL = 1.5x ATR
quantity = floor(risk_unit / distance_stop / lot_size) * lot_size

leverage = min(requested_leverage * tf_multiplier * conviction_multiplier, bracket_max)
```

### 5. Plan d'ordres
- **Maker** : LIMIT GTC dans EntryZone (timeout 5s)
- **Fallback** : IOC/MARKET si non rempli (max 5 bps slippage)
- **TP/SL** : submit-tp-sl-order (plan_category=2)

## 📊 Réponse API

### Succès (200)

```json
{
  "status": "success",
  "message": "Post-validation completed",
  "data": {
    "decision": "OPEN",
    "reason": "Position opened successfully",
    "symbol": "BTCUSDT",
    "side": "LONG",
    "execution_timeframe": "5m",
    "entry_zone": {
      "symbol": "BTCUSDT",
      "side": "LONG",
      "entry_min": 43250.0,
      "entry_max": 43280.0,
      "mid_price": 43265.0,
      "zone_width_bps": 6.9,
      "vwap_anchor": 43260.0,
      "atr_value": 120.5,
      "spread_bps": 1.2,
      "depth_top_usd": 45000.0,
      "quality_passed": true
    },
    "order_plan": {
      "symbol": "BTCUSDT",
      "side": "LONG",
      "execution_timeframe": "5m",
      "quantity": 0.001,
      "leverage": 12.5,
      "total_notional": 540.8,
      "risk_amount": 0.18,
      "stop_loss_price": 43144.5,
      "take_profit_price": 43385.5,
      "maker_orders": [...],
      "fallback_orders": [...],
      "tp_sl_orders": [...]
    },
    "market_data": {...},
    "guards": {"all_passed": true},
    "evidence": {...},
    "decision_key": "BTCUSDT:5m:1704067200",
    "timestamp": 1704067200
  }
}
```

### Skip (200)

```json
{
  "status": "success",
  "message": "Post-validation completed",
  "data": {
    "decision": "SKIP",
    "reason": "Entry zone quality filters failed",
    "symbol": "BTCUSDT",
    "side": "LONG",
    "entry_zone": null,
    "order_plan": null,
    "market_data": {...},
    "guards": {"all_passed": false},
    "evidence": {...},
    "decision_key": "BTCUSDT:5m:1704067200",
    "timestamp": 1704067200
  }
}
```

## 🔧 Configuration

### Variables d'environnement

```bash
# Configuration Post-Validation
POST_VALIDATION_DRY_RUN=true
POST_VALIDATION_MAX_ATTEMPTS=3
POST_VALIDATION_TIMEOUT_SEC=30

# Configuration BitMart
BITMART_API_KEY=your-api-key
BITMART_SECRET_KEY=your-secret-key
BITMART_BASE_URL=https://api-cloud-v2.bitmart.com
```

### Configuration trading.yml

```yaml
post_validation:
  entry_zone:
    k_atr: 0.35                    # largeur relative à ATR
    w_min: 0.0005                  # largeur minimale (0.05%)
    w_max: 0.0100                  # largeur maximale (1.00%)
    spread_bps_max: 2.0            # spread max en bps
    depth_min_usd: 20000           # profondeur minimale en USD
  
  execution_timeframe:
    default: "5m"                  # timeframe par défaut
    upshift_to_1m:
      atr_pct_hi: 0.12             # ATR >= 0.12% pour upshift
      spread_bps_max: 2.0          # spread max pour upshift
      depth_min_usd: 30000         # profondeur min pour upshift
      require_mtf_alignment: true   # alignement 5m/15m requis
  
  sizing:
    risk_pct: 0.005                # 0.5% du capital risqué
    sl_mult_atr: 1.5               # SL = 1.5x ATR
    tp_r_multiple: 2.0             # TP = 2R
  
  leverage:
    use_submit_leverage: true      # utiliser submit-leverage
    respect_bracket: true          # respecter les brackets exchange
    cap_pct_of_exchange: 0.60      # 60% du levier max exchange
    timeframe_multipliers:
      '1m': 0.5                    # réduction pour 1m
      '5m': 0.75                   # réduction pour 5m
      '15m': 1.0                   # normal pour 15m
  
  order_plan:
    prefer_maker: true             # préférer les ordres maker
    maker:
      mode: "GTC"                  # Good Till Cancelled
      maker_only: true             # ordres maker uniquement
      timeout_sec: 5               # timeout en secondes
    fallback_taker:
      enable: true                 # activer le fallback taker
      type: "IOC"                  # Immediate Or Cancel
      max_slip_bps: 5              # slippage max en bps
    tp_sl:
      use_position_tp_sl: true     # utiliser submit-tp-sl-order
      price_type: last_price       # type de prix pour TP/SL
  
  guards:
    stale_ticker_sec: 2            # données obsolètes après 2s
    max_slip_bps: 5                # slippage max en bps
    min_liquidity_usd: 10000       # liquidité minimale en USD
    funding_cutoff_min: 5          # cutoff funding 5min avant
    max_funding_rate: 0.01         # funding rate max (1%)
    mark_index_gap_bps_max: 15.0   # écart Mark/Index max
```

## 🧪 Tests E2E

### Scénarios de test

| Test | Description | Critères |
|------|-------------|----------|
| T-01 | Maker Fill | BTCUSDT LONG, spread 0.5 bps, depth OK, ATR(5m) normal → LIMIT filled, TP/SL posés |
| T-02 | Maker Timeout → IOC | Pas de fill 5s → cancel + IOC; glissement ≤ 5 bps |
| T-03 | Upshift vers 1m | Pic ATR(1m) + depth OK + alignement 5m/15m → tf_exec=1m |
| T-04 | Bracket levier | Demande 50× mais bracket max 25× → clamp à 25×; submit-leverage OK |
| T-05 | Stale Ticker | WS muet >2s → pas d'ordres; fallback REST tenté |
| T-06 | Reconcile | Après incident réseau, get-open-orders + position-v2 reconstituent l'état |

### Exécution des tests

```bash
# Tests unitaires
php bin/phpunit tests/PostValidation/PostValidationE2ETest.php

# Tests E2E avec script
./scripts/test-post-validation.sh

# Tests avec couverture
php bin/phpunit --coverage-html coverage tests/PostValidation/
```

## 📈 Monitoring

### 1. Logs

```bash
# Logs Post-Validation
tail -f var/log/dev.log | grep "PostValidation"

# Logs spécifiques
tail -f var/log/dev.log | grep "EntryZone"
tail -f var/log/dev.log | grep "PositionOpener"
tail -f var/log/dev.log | grep "StateMachine"
```

### 2. Métriques

```bash
# Statistiques Post-Validation
curl -X GET http://localhost:8082/api/post-validation/statistics

# Test de configuration
curl -X GET http://localhost:8082/api/post-validation/test-config
```

### 3. Base de données

```sql
-- Vérifier les décisions Post-Validation
SELECT * FROM post_validation_decisions ORDER BY created_at DESC LIMIT 10;

-- Vérifier les zones d'entrée
SELECT * FROM entry_zones ORDER BY created_at DESC LIMIT 10;

-- Vérifier les plans d'ordres
SELECT * FROM order_plans ORDER BY created_at DESC LIMIT 10;
```

## 🚨 Gestion d'erreurs

### Codes d'erreur

| Code | Description | Solution |
|------|-------------|----------|
| 200 | Succès | - |
| 400 | Paramètres invalides | Vérifier symbol, side, mtf_context |
| 500 | Erreur interne | Vérifier les logs et la configuration |

### Erreurs courantes

1. **"Market data is stale"**
   - Solution : Vérifier la connectivité WebSocket et REST

2. **"Entry zone quality filters failed"**
   - Solution : Vérifier spread, depth, mark/index gap

3. **"Guards failed"**
   - Solution : Vérifier liquidité, slippage, risk limits

4. **"Leverage exceeds bracket limits"**
   - Solution : Réduire le levier demandé ou vérifier les brackets

## 🔒 Sécurité

- ✅ **Mode dry-run par défaut** : Pas d'ordres réels par défaut
- ✅ **Garde-fous multiples** : Stale data, slippage, liquidité, risk limits
- ✅ **Idempotence** : Clé de décision unique par bougie
- ✅ **Traçabilité** : Logs structurés et preuves conservées
- ✅ **Validation** : Vérification des paramètres et contraintes

## 🚀 Déploiement

### 1. Développement

```bash
# Démarrer les services
docker-compose up -d

# Tester l'API
curl -X GET http://localhost:8082/api/post-validation/docs
```

### 2. Production

```bash
# Déployer avec Docker
docker-compose -f docker-compose.prod.yml up -d

# Vérifier la santé
curl -X GET http://localhost:8082/api/post-validation/statistics

# Tester en mode production
./scripts/test-post-validation.sh --production
```

## 📚 Documentation

- [Configuration Post-Validation](config/trading.yml#post-validation)
- [Tests E2E](tests/PostValidation/PostValidationE2ETest.php)
- [Script de test](scripts/test-post-validation.sh)
- [API Documentation](src/Controller/PostValidationController.php)

## 🤝 Support

Pour toute question ou problème :

1. Vérifier les logs : `tail -f var/log/dev.log | grep "PostValidation"`
2. Tester la configuration : `curl -X GET http://localhost:8082/api/post-validation/test-config`
3. Exécuter les tests : `./scripts/test-post-validation.sh`
4. Consulter la documentation : `curl -X GET http://localhost:8082/api/post-validation/docs`

---

**Note** : Post-Validation est conçu pour être sûr, traçable et idempotent. Toujours tester en mode dry-run avant la production.

