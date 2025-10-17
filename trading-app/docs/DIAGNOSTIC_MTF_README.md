# 🔍 Diagnostic MTF - Guide d'utilisation

## Vue d'ensemble

Le script de diagnostic MTF permet d'analyser les signaux récents et d'identifier pourquoi le système ne génère pas de nouveaux signaux. Il recalcule les indicateurs techniques et applique les règles de validation pour chaque timeframe.

## 🚀 Utilisation rapide

### Via le script bash (recommandé)
```bash
# Diagnostic par défaut (BTCUSDT, 5m, 10 signaux)
./scripts/diagnose_mtf_signals.sh

# Diagnostic personnalisé
./scripts/diagnose_mtf_signals.sh ETHUSDT 5m 5

# Diagnostic complet avec rafraîchissement des vues SQL
./scripts/refresh_indicators_and_diagnose.sh BTCUSDT 5m 10

# Comparaison SQL vs PHP (recommandé pour validation)
./scripts/compare_sql_php_indicators.sh BTCUSDT 5m 5
```

### Via la commande Symfony
```bash
# Diagnostic de base
php bin/console app:diagnose-mtf-signals

# Avec options
php bin/console app:diagnose-mtf-signals \
    --symbol=BTCUSDT \
    --timeframe=5m \
    --limit=10 \
    --output-format=table
```

## 📊 Options disponibles

| Option | Description | Valeur par défaut |
|--------|-------------|-------------------|
| `--symbol` | Symbole à analyser | BTCUSDT |
| `--timeframe` | Timeframe principal | 5m |
| `--limit` | Nombre de signaux à analyser | 10 |
| `--output-format` | Format de sortie (table/json) | table |

## 🔬 Ce que fait le diagnostic

### 🗄️ **Calcul SQL optimisé (priorité)**
Le script utilise en priorité les **vues matérialisées PostgreSQL** pour récupérer les indicateurs pré-calculés :
- **Performance** : Accès direct aux données pré-calculées
- **Cohérence** : Même source que le système de trading
- **Complet** : Tous les indicateurs disponibles (EMA, RSI, MACD, VWAP, Bollinger, etc.)
- **Fallback** : Calcul PHP si les vues SQL ne sont pas disponibles

### 1. Récupération des signaux
- Récupère les N derniers signaux du timeframe spécifié
- Affiche les informations de base (symbole, timeframe, side, score, date)

### 2. Calcul des indicateurs techniques
Pour chaque signal, calcule via **les deux méthodes** pour comparaison :
- **🗄️ SQL** : Vues matérialisées PostgreSQL (performance optimale)
- **⚙️ PHP** : Calcul en temps réel (cohérence de validation)
- **🔍 Comparaison** : Analyse des différences avec tolérance de 0.0001

**Indicateurs comparés :**
- **EMA** (9, 20, 50, 200)
- **RSI** (14 périodes)
- **MACD** (ligne, signal, histogramme)
- **VWAP** (Volume Weighted Average Price)
- **ATR** (Average True Range)
- **Bollinger Bands** (upper, middle, lower)
- **StochRSI, ADX, Ichimoku, OBV, Donchian** (si disponibles)

### 3. Application des règles de validation
Applique les règles configurées dans `trading.yml` :
- Règles LONG pour le timeframe
- Règles SHORT pour le timeframe
- Conditions d'échec et de succès

### 4. Analyse des timeframes parents
Pour chaque signal, analyse les timeframes parents :
- **15m** (si timeframe principal = 5m ou 1m)
- **1h** (si timeframe principal = 15m, 5m ou 1m)
- **4h** (toujours analysé)

## 📈 Exemples de sortie

### Format table (par défaut)
```
🔍 Diagnostic MTF - Analyse des signaux pour BTCUSDT

📊 Récupération des signaux 5m
✅ Trouvé 10 signaux récents

🔬 Analyse du signal #1
Signal: BTCUSDT | Timeframe: 5m | Side: LONG | Score: 0.85 | Date: 2025-01-15 14:30:00

🗄️ Indicateurs SQL (Vues matérialisées):
┌─────────────────┬─────────────────┐
│ Indicateur      │ Valeur          │
├─────────────────┼─────────────────┤
│ EMA 9           │ 43260.123456789 │
│ EMA 20          │ 43250.123456789 │
│ EMA 50          │ 43100.987654321 │
│ EMA 200         │ 42800.000000000 │
│ RSI             │ 65.5            │
│ MACD            │ 12.34           │
│ VWAP            │ 43200.00        │
│ Prix actuel     │ 43250.50        │
└─────────────────┴─────────────────┘
🕐 Bucket time SQL: 2025-01-15 14:30:00

⚙️ Indicateurs PHP (Calcul en temps réel):
┌─────────────────┬─────────────────┐
│ Indicateur      │ Valeur          │
├─────────────────┼─────────────────┤
│ EMA 9           │ 43260.123456789 │
│ EMA 20          │ 43250.123456789 │
│ EMA 50          │ 43100.987654321 │
│ RSI             │ 65.5            │
│ MACD            │ 12.34           │
│ VWAP            │ 43200.00        │
│ Prix actuel     │ 43250.50        │
└─────────────────┴─────────────────┘

🔍 Comparaison SQL vs PHP:
┌─────────────────┬─────────────────┬─────────────────┬──────────────┬─────────┐
│ Indicateur      │ SQL             │ PHP             │ Différence   │ Statut  │
├─────────────────┼─────────────────┼─────────────────┼──────────────┼─────────┤
│ Ema 20          │ 43250.123456789 │ 43250.123456789 │ 0.000000     │ ✅ OK   │
│ Ema 50          │ 43100.987654321 │ 43100.987654321 │ 0.000000     │ ✅ OK   │
│ Rsi             │ 65.5            │ 65.5            │ 0.000000     │ ✅ OK   │
│ Macd            │ 12.34           │ 12.34           │ 0.000000     │ ✅ OK   │
│ Vwap            │ 43200.00        │ 43200.00        │ 0.000000     │ ✅ OK   │
└─────────────────┴─────────────────┴─────────────────┴──────────────┴─────────┘

✅ Excellente cohérence entre SQL et PHP (100%)

✅ Validation des règles:
┌─────────────────┬─────────────────┐
│ Règle           │ Statut          │
├─────────────────┼─────────────────┤
│ Signal généré   │ LONG            │
│ Règles LONG     │ ema_20_gt_50, close_above_vwap, rsi_lt_70 │
│ Règles SHORT    │ ema_20_lt_50, close_below_vwap, rsi_gt_30 │
└─────────────────┴─────────────────┘

🔗 Analyse des timeframes parents:
  15m: Signal LONG
  1h: Signal LONG
  4h: Signal LONG
```

### Format JSON
```bash
php bin/console app:diagnose-mtf-signals --output-format=json > diagnostic.json
```

## 🛠️ Dépannage

### Problèmes courants

1. **Aucun signal trouvé**
   ```
   ❌ Aucun signal trouvé pour BTCUSDT sur le timeframe 5m
   ```
   - Vérifiez que des signaux existent en base
   - Vérifiez le symbole et timeframe

2. **Erreur de calcul des indicateurs**
   ```
   ⚠️ Problèmes identifiés:
     - Erreur calcul indicateurs: Insufficient data
   ```
   - Vérifiez la disponibilité des klines
   - Vérifiez la configuration des indicateurs

3. **Erreur de validation des règles**
   ```
   ⚠️ Problèmes identifiés:
     - Erreur validation règles: Service not found
   ```
   - Vérifiez la configuration des services
   - Vérifiez les règles dans `trading.yml`

### Vérifications préalables

1. **Base de données**
   ```bash
   # Vérifier les signaux existants
   php bin/console doctrine:query:sql "SELECT COUNT(*) FROM signals WHERE symbol = 'BTCUSDT' AND timeframe = '5m'"
   ```

2. **Klines disponibles**
   ```bash
   # Vérifier les klines
   php bin/console doctrine:query:sql "SELECT COUNT(*) FROM klines WHERE symbol = 'BTCUSDT' AND timeframe = '5m'"
   ```

3. **Configuration**
   ```bash
   # Vérifier la configuration
   php bin/console debug:config app trading
   ```

## 📋 Recommandations d'utilisation

### Pour diagnostiquer un problème de signaux
1. Commencez par analyser les 10 derniers signaux 5m
2. Vérifiez les timeframes parents (15m, 1h, 4h)
3. Identifiez les patterns d'échec
4. Ajustez la configuration si nécessaire

### Pour analyser un symbole spécifique
```bash
# Analyse complète d'un symbole
./scripts/diagnose_mtf_signals.sh ETHUSDT 5m 20
./scripts/diagnose_mtf_signals.sh ETHUSDT 1m 50
```

### Pour exporter les résultats
```bash
# Export JSON pour analyse externe
php bin/console app:diagnose-mtf-signals --symbol=BTCUSDT --output-format=json > btc_diagnostic.json
```

## 🔧 Maintenance

### Nettoyage des anciens signaux
```bash
# Nettoyer les signaux anciens (optionnel)
php bin/console doctrine:query:sql "DELETE FROM signals WHERE kline_time < NOW() - INTERVAL '30 days'"
```

### Rafraîchissement des indicateurs
```bash
# Rafraîchir les vues matérialisées
php bin/console app:refresh-indicators
```

## 📞 Support

En cas de problème :
1. Vérifiez les logs : `tail -f var/log/dev.log`
2. Vérifiez la configuration : `php bin/console debug:config`
3. Testez avec un symbole simple : `./scripts/diagnose_mtf_signals.sh BTCUSDT 5m 1`
