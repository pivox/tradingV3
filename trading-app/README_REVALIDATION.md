# 📊 Endpoint de Revalidation des Contrats

## Vue d'ensemble

L'endpoint de revalidation permet de revalider un ou plusieurs contrats à partir d'une date donnée et d'afficher les résultats détaillés. Cette fonctionnalité est utile pour :

- **Analyse historique** : Vérifier les signaux sur des dates passées
- **Backtesting** : Tester les stratégies sur des données historiques
- **Validation** : Confirmer les résultats des indicateurs à des moments spécifiques
- **Debugging** : Analyser pourquoi certains signaux ont été générés ou non

## 🚀 Endpoint

**URL :** `POST /indicators/revalidate`

**Base URL :** `http://localhost:8082`

## 📋 Paramètres

### Paramètres requis

| Paramètre | Type | Description | Exemple |
|-----------|------|-------------|---------|
| `date` | string | Date de revalidation en UTC (format YYYY-MM-DD) | `"2024-01-15"` |
| `contracts` | string | Liste des contrats séparés par des virgules | `"BTCUSDT,ETHUSDT,ADAUSDT"` |

### Paramètres optionnels

| Paramètre | Type | Description | Valeur par défaut |
|-----------|------|-------------|-------------------|
| `timeframe` | string | Timeframe pour l'analyse | `"1h"` |

## 📝 Exemples d'utilisation

### 1. Revalidation d'un seul contrat

```bash
curl -X POST "http://localhost:8082/indicators/revalidate" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2024-01-15",
    "contracts": "BTCUSDT",
    "timeframe": "1h"
  }'
```

### 2. Revalidation de plusieurs contrats

```bash
curl -X POST "http://localhost:8082/indicators/revalidate" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2024-01-15",
    "contracts": "BTCUSDT,ETHUSDT,ADAUSDT",
    "timeframe": "1h"
  }'
```

### 3. Revalidation avec un timeframe différent

```bash
curl -X POST "http://localhost:8082/indicators/revalidate" \
  -H "Content-Type: application/json" \
  -d '{
    "date": "2024-01-15",
    "contracts": "BTCUSDT,ETHUSDT",
    "timeframe": "5m"
  }'
```

## 📊 Réponse

### Structure de la réponse

```json
{
  "success": true,
  "data": {
    "global_summary": {
      "total_contracts": 3,
      "successful_validations": 1,
      "failed_validations": 2,
      "success_rate": 33.33,
      "date": "2024-01-15",
      "timeframe": "1h"
    },
    "contracts_results": {
      "BTCUSDT": {
        "contract": "BTCUSDT",
        "date": "2024-01-15",
        "timeframe": "1h",
        "status": "valid|invalid|partial|error",
        "summary": {
          "total_conditions": 19,
          "passed": 4,
          "failed": 15,
          "errors": 0,
          "success_rate": 21.05
        },
        "timeframe_validation": {
          "long": {
            "required_conditions": ["close_above_ema_200", "macd_hist_gt_0"],
            "passed_conditions": ["macd_hist_gt_0"],
            "failed_conditions": ["close_above_ema_200"],
            "all_passed": false
          },
          "short": {
            "required_conditions": ["ema_20_lt_50", "macd_hist_lt_0"],
            "passed_conditions": ["ema_20_lt_50"],
            "failed_conditions": ["macd_hist_lt_0"],
            "all_passed": false
          }
        },
        "conditions_results": {
          "condition_name": {
            "name": "condition_name",
            "passed": true|false,
            "value": 123.45,
            "threshold": 100,
            "meta": {
              "symbol": "BTCUSDT",
              "timeframe": "1h",
              "source": "RSI|MACD|EMA|VWAP|ATR"
            }
          }
        },
        "context": {
          "symbol": "BTCUSDT",
          "timeframe": "1h",
          "close": 38679.44,
          "ema": {"20": 39926.85, "50": 40010.47},
          "rsi": 15.49,
          "macd": {"macd": 1203.26, "signal": 737.73, "hist": 465.52},
          "vwap": 39857.98,
          "atr": 1577.32
        },
        "timestamp": "2025-10-13 17:05:41"
      }
    },
    "timestamp": "2025-10-13 17:05:41"
  }
}
```

### Statuts possibles

| Statut | Description |
|--------|-------------|
| `valid` | Au moins une direction (long ou short) est valide |
| `invalid` | Aucune direction n'est valide |
| `partial` | Taux de succès des conditions ≥ 70% |
| `error` | Erreur lors de l'évaluation |

## 🔍 Contrats supportés

| Contrat | Description |
|---------|-------------|
| `BTCUSDT` | Bitcoin / USDT |
| `ETHUSDT` | Ethereum / USDT |
| `ADAUSDT` | Cardano / USDT |
| `DOTUSDT` | Polkadot / USDT |
| `LINKUSDT` | Chainlink / USDT |
| `SOLUSDT` | Solana / USDT |
| `MATICUSDT` | Polygon / USDT |
| `AVAXUSDT` | Avalanche / USDT |

## ⏰ Timeframes supportés

| Timeframe | Description |
|-----------|-------------|
| `1m` | 1 minute |
| `5m` | 5 minutes |
| `15m` | 15 minutes |
| `30m` | 30 minutes |
| `1h` | 1 heure |
| `4h` | 4 heures |
| `1d` | 1 jour |

## 🚨 Gestion des erreurs

### Erreurs de validation

```json
{
  "success": false,
  "error": "Missing date parameter",
  "message": "Le paramètre \"date\" est requis (format: YYYY-MM-DD)"
}
```

### Types d'erreurs

| Erreur | Description |
|--------|-------------|
| `Missing date parameter` | Paramètre `date` manquant |
| `Missing contracts parameter` | Paramètre `contracts` manquant |
| `Invalid date format` | Format de date invalide |
| `Future date not allowed` | Date dans le futur non autorisée |
| `Invalid contracts` | Contrats non supportés |
| `Invalid timeframe` | Timeframe non configuré |
| `No valid contracts` | Aucun contrat valide fourni |

## 🧪 Tests

### Script de test automatique

```bash
# Exécuter tous les tests
./scripts/test-revalidation-simple.sh

# Tests spécifiques
curl -X POST "http://localhost:8082/indicators/revalidate" \
  -H "Content-Type: application/json" \
  -d '{"date": "2024-01-15", "contracts": "BTCUSDT", "timeframe": "1h"}' | jq .
```

### Exemples de tests

1. **Test de succès** : Un seul contrat
2. **Test de succès** : Plusieurs contrats
3. **Test de succès** : Différents timeframes
4. **Test d'erreur** : Date manquante
5. **Test d'erreur** : Contrats manquants
6. **Test d'erreur** : Format de date invalide
7. **Test d'erreur** : Contrats invalides

## 📈 Indicateurs évalués

### Indicateurs techniques

- **RSI** : Relative Strength Index
- **MACD** : Moving Average Convergence Divergence
- **EMA** : Exponential Moving Average (20, 50, 200)
- **VWAP** : Volume Weighted Average Price
- **ATR** : Average True Range

### Conditions de validation

- **Tendance** : EMA 20 vs EMA 50, EMA 50 vs EMA 200
- **Momentum** : MACD histogram, RSI levels
- **Volatilité** : ATR validation
- **Support/Résistance** : Prix vs VWAP, Prix vs EMA 200
- **Régime de prix** : Validation du contexte de marché

## 🔧 Configuration

### Fichier de configuration

Les règles de validation sont définies dans `config/trading.yml` :

```yaml
timeframes:
  1h:
    long:
      - close_above_ema_200
      - macd_hist_gt_0
      - price_regime_ok
    short:
      - ema_20_lt_50
      - macd_hist_lt_0
      - price_regime_ok
```

### Données historiques

L'endpoint utilise des données historiques simulées basées sur :
- **Prix de base** : Définis par symbole
- **Variation temporelle** : Basée sur la date et heure UTC cible
- **Précision horaire** : L'heure UTC influence les variations de prix
- **Volatilité** : Simulation réaliste des mouvements de prix

## 📊 Métriques de performance

### Résumé global

- **Total des contrats** : Nombre de contrats analysés
- **Validations réussies** : Nombre de contrats avec statut `valid`
- **Validations échouées** : Nombre de contrats avec statut `invalid`
- **Taux de succès** : Pourcentage de validations réussies

### Résumé par contrat

- **Conditions totales** : Nombre total de conditions évaluées
- **Conditions réussies** : Nombre de conditions qui ont réussi
- **Conditions échouées** : Nombre de conditions qui ont échoué
- **Taux de succès** : Pourcentage de conditions réussies

## 🚀 Utilisation avancée

### Intégration dans des scripts

```bash
#!/bin/bash

# Revalidation de plusieurs contrats
CONTRACTS="BTCUSDT,ETHUSDT,ADAUSDT"
DATE="2024-01-15"
TIMEFRAME="1h"

RESPONSE=$(curl -s -X POST "http://localhost:8082/indicators/revalidate" \
  -H "Content-Type: application/json" \
  -d "{\"date\": \"$DATE\", \"contracts\": \"$CONTRACTS\", \"timeframe\": \"$TIMEFRAME\"}")

# Extraire le taux de succès global
SUCCESS_RATE=$(echo "$RESPONSE" | jq -r '.data.global_summary.success_rate')
echo "Taux de succès global: $SUCCESS_RATE%"

# Extraire les statuts par contrat
echo "$RESPONSE" | jq -r '.data.contracts_results | to_entries[] | "\(.key): \(.value.status)"'
```

### Analyse des résultats

```bash
# Filtrer les contrats valides
echo "$RESPONSE" | jq -r '.data.contracts_results | to_entries[] | select(.value.status == "valid") | .key'

# Obtenir les conditions qui ont échoué
echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.timeframe_validation.long.failed_conditions[]'

# Analyser les valeurs des indicateurs
echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.context | {rsi, macd, ema}'
```

## 🔍 Debugging

### Logs détaillés

Les logs détaillés sont disponibles dans :
- `var/log/dev.log` : Logs généraux
- `var/log/positions.log` : Logs spécifiques aux positions
- `var/log/post_validation.log` : Logs de post-validation

### Vérification des données

```bash
# Vérifier les données de contexte
echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.context'

# Vérifier les métadonnées des conditions
echo "$RESPONSE" | jq -r '.data.contracts_results.BTCUSDT.conditions_results | to_entries[] | select(.value.meta.missing_data == true) | .key'
```

## 🌐 Interface Web

L'endpoint est accessible via l'interface web des indicateurs :

- **URL** : `http://localhost:8082/indicators/test`
- **Méthode** : Interface graphique avec bouton de revalidation
- **Fonctionnalités** :
  - **Champ datetime UTC** : Sélection de date et heure avec conversion automatique
  - **Recherche de contrats** : Autocomplétion avec 388+ contrats actifs
  - **Sélection multiple** : Possibilité de sélectionner plusieurs contrats
  - **Choix du timeframe** : 1m, 5m, 15m, 1h, 4h
  - **Validation** : Vérification des dates futures et paramètres
  - **Affichage des résultats** : Résumé global et détails par contrat
  - **Gestion d'erreurs** : Messages d'erreur explicites

### Utilisation de l'interface

1. **Accédez à** : `http://localhost:8082/indicators/test`
2. **Sélectionnez une date et heure** dans le champ datetime (UTC)
3. **Recherchez des contrats** en tapant dans le champ de recherche
4. **Sélectionnez plusieurs contrats** en cliquant sur les options
5. **Choisissez un timeframe** dans la liste déroulante
6. **Cliquez sur "Revalidation des Contrats"**
7. **Consultez les résultats** détaillés avec indicateur UTC

## 📚 Ressources

- [Documentation des indicateurs](INDICATOR_VALIDATION.md)
- [Interface web de test](INTERFACE_WEB_SUMMARY.md)
- [Configuration trading](config/trading.yml)
- [Scripts de test](scripts/)

## 🤝 Support

Pour toute question ou problème :

1. Vérifiez les logs dans `var/log/`
2. Consultez la documentation des indicateurs
3. Testez avec le script de test automatique
4. Vérifiez la configuration dans `trading.yml`
