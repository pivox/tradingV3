# 🚀 Endpoint MTF Run - Guide d'utilisation

## Vue d'ensemble

L'endpoint `/api/mtf/run` permet d'exécuter manuellement la logique MTF (Multi-Timeframe) complète en bouclant sur les contrats spécifiés. Il implémente exactement la même logique que le workflow Temporal automatique, mais de manière synchrone et contrôlable.

## 🎯 Fonctionnalités

- ✅ **Exécution manuelle** de la logique MTF
- ✅ **Boucle sur les contrats** spécifiés
- ✅ **Validation séquentielle** des timeframes (4h → 1h → 15m → 5m → 1m)
- ✅ **Gestion des kill switches** (global, symbole, timeframe)
- ✅ **Mode dry-run** par défaut
- ✅ **Force run** pour ignorer les kill switches
- ✅ **Création d'order plans** en mode production
- ✅ **Logs détaillés** et audit complet
- ✅ **Gestion d'erreurs** robuste

## 🚀 Démarrage rapide

### 1. Test basique

```bash
# Test avec les symboles par défaut en mode dry-run
curl -X POST http://localhost:8082/api/mtf/run
```

### 2. Test avec symboles spécifiques

```bash
# Test avec BTCUSDT et ETHUSDT
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT"]
  }'
```

### 3. Test en mode production

```bash
# Test avec création d'order plans
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"],
    "dry_run": false
  }'
```

## 📋 Paramètres

| Paramètre | Type | Requis | Défaut | Description |
|-----------|------|--------|--------|-------------|
| `symbols` | array | Non | `['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT']` | Liste des symboles à traiter |
| `dry_run` | boolean | Non | `true` | Mode dry-run (pas de création d'order plans) |
| `force_run` | boolean | Non | `false` | Forcer l'exécution même si les kill switches sont OFF |

## 🔄 Logique d'exécution

Pour chaque symbole, l'endpoint exécute la logique suivante :

### 1. Vérification des kill switches
- **Global kill switch** : Vérifie si le système est activé
- **Symbol kill switch** : Vérifie si le symbole est activé
- **Timeframe kill switches** : Vérifie si chaque timeframe est activé

### 2. Validation séquentielle des timeframes
- **4h** : Validation de la bougie 4h et des indicateurs
- **1h** : Validation de la bougie 1h et des indicateurs
- **15m** : Validation de la bougie 15m et des indicateurs
- **5m** : Validation de la bougie 5m et des indicateurs
- **1m** : Validation de la bougie 1m et des indicateurs

### 3. Vérification de la cohérence MTF
- Tous les timeframes doivent avoir le même signal
- Pas de divergence entre les timeframes
- Validation des règles de cohérence

### 4. Application des filtres d'exécution
- **RSI < 70** : Pas de survente/surachat
- **Distance prix-MA21 ≤ 2×ATR** : Pas d'extension excessive
- **Pullback confirmé** : Confirmation du retour
- **Pas de divergence bloquante** : Pas de divergence majeure

### 5. Création de l'order plan
- **Contexte MTF** : Informations de tous les timeframes
- **Paramètres de risque** : Gestion du risque
- **Paramètres d'exécution** : Configuration de l'ordre

## 📊 Réponse

### Succès (200)

```json
{
  "status": "success",
  "message": "MTF run completed",
  "data": {
    "summary": {
      "run_id": "550e8400-e29b-41d4-a716-446655440000",
      "execution_time_seconds": 2.456,
      "symbols_requested": 2,
      "symbols_processed": 2,
      "symbols_successful": 1,
      "symbols_failed": 1,
      "symbols_skipped": 0,
      "success_rate": 50.0,
      "dry_run": true,
      "force_run": false,
      "timestamp": "2024-01-15 14:30:25"
    },
    "results": {
      "BTCUSDT": {
        "status": "success",
        "order_plan_id": null,
        "signal_side": "LONG",
        "steps": {
          "4h": { "status": "valid", "timeframe": "4h", "signal_side": "LONG" },
          "1h": { "status": "valid", "timeframe": "1h", "signal_side": "LONG" },
          "15m": { "status": "valid", "timeframe": "15m", "signal_side": "LONG" },
          "5m": { "status": "valid", "timeframe": "5m", "signal_side": "LONG" },
          "1m": { "status": "valid", "timeframe": "1m", "signal_side": "LONG" }
        },
        "consistency": { "status": "CONSISTENT" },
        "filters": { "status": "passed" },
        "dry_run": true,
        "timestamp": "2024-01-15 14:30:25"
      }
    }
  }
}
```

### Erreur (403) - Kill Switch OFF

```json
{
  "status": "error",
  "message": "Global kill switch is OFF. Use force_run=true to override.",
  "data": {
    "run_id": "550e8400-e29b-41d4-a716-446655440000",
    "global_switch": false
  }
}
```

## 🛠️ Outils de test

### 1. Script bash

```bash
# Test basique
./scripts/test-mtf-run.sh

# Test avec symboles spécifiques
./scripts/test-mtf-run.sh http://localhost:8082 BTCUSDT,ETHUSDT

# Test en mode production
./scripts/test-mtf-run.sh http://localhost:8082 BTCUSDT,ETHUSDT false

# Test avec force run
./scripts/test-mtf-run.sh http://localhost:8082 BTCUSDT true true
```

### 2. Commande CLI

```bash
# Test basique
php bin/console app:test-mtf-run

# Test avec symboles spécifiques
php bin/console app:test-mtf-run --symbols=BTCUSDT,ETHUSDT

# Test en mode dry-run
php bin/console app:test-mtf-run --dry-run

# Test avec force run
php bin/console app:test-mtf-run --force-run

# Test avec URL personnalisée
php bin/console app:test-mtf-run --url=http://localhost:8082

# Test en mode verbeux
php bin/console app:test-mtf-run --verbose

# Exécution directe avec parallélisation (4 workers)
# (le throttle Bitmart global garde un délai minimum de 200ms entre chaque requête)
php bin/console mtf:run --workers=4
```

### 3. Tests unitaires

```bash
# Exécuter les tests
php bin/phpunit tests/Controller/MtfControllerTest.php

# Tests avec couverture
php bin/phpunit --coverage-html coverage tests/Controller/MtfControllerTest.php
```

## 🔧 Configuration

### Variables d'environnement

```bash
# Configuration MTF
MTF_SYMBOLS_TO_WATCH=BTCUSDT,ETHUSDT,ADAUSDT,SOLUSDT,DOTUSDT
MTF_GRACE_WINDOW_MINUTES=4
MTF_MAX_CANDLES_PER_REQUEST=500
MTF_MAX_RETRIES=3

# Configuration BitMart
BITMART_API_KEY=your-api-key
BITMART_SECRET_KEY=your-secret-key
BITMART_BASE_URL=https://api-cloud-v2.bitmart.com

# Configuration Temporal
TEMPORAL_ADDRESS=temporal-grpc:7233
TEMPORAL_NAMESPACE=default
```

### Configuration des kill switches

```bash
# Vérifier le statut des kill switches
curl -X GET http://localhost:8082/api/mtf/switches

# Activer/désactiver un kill switch
curl -X POST http://localhost:8082/api/mtf/switches/GLOBAL/toggle
curl -X POST http://localhost:8082/api/mtf/switches/SYMBOL:BTCUSDT/toggle
curl -X POST http://localhost:8082/api/mtf/switches/SYMBOL_TF:BTCUSDT:4h/toggle
```

## 📈 Monitoring

### 1. Logs

```bash
# Logs de l'application
tail -f var/log/dev.log | grep "MTF Controller"

# Logs Nginx
tail -f /var/log/nginx/access.log | grep "/api/mtf/run"
```

### 2. Métriques

```bash
# Statut du système
curl -X GET http://localhost:8082/api/mtf/status

# Métriques de performance
curl -X GET http://localhost:8082/api/mtf/metrics

# Audit des exécutions
curl -X GET http://localhost:8082/api/mtf/audit?limit=100
```

### 3. Base de données

```sql
-- Vérifier les order plans créés
SELECT * FROM order_plan ORDER BY created_at DESC LIMIT 10;

-- Vérifier l'audit MTF
SELECT * FROM mtf_audit ORDER BY created_at DESC LIMIT 10;

-- Vérifier les kill switches
SELECT * FROM mtf_switch;
```

## 🚨 Gestion d'erreurs

### Codes d'erreur

| Code | Description | Solution |
|------|-------------|----------|
| 200 | Succès | - |
| 403 | Kill switch global OFF | Utiliser `force_run=true` ou activer le kill switch |
| 500 | Erreur interne | Vérifier les logs et la configuration |

### Erreurs courantes

1. **"Global kill switch is OFF"**
   - Solution : Utiliser `force_run=true` ou activer le kill switch global

2. **"Symbol kill switch is OFF"**
   - Solution : Activer le kill switch du symbole ou utiliser `force_run=true`

3. **"Database connection failed"**
   - Solution : Vérifier la connexion à la base de données

4. **"BitMart API error"**
   - Solution : Vérifier les clés API et la connectivité

## 🔒 Sécurité

- ✅ **Mode dry-run par défaut** : Pas de création d'order plans par défaut
- ✅ **Vérification des kill switches** : Contrôle granulaire
- ✅ **Validation des paramètres** : Vérification des entrées
- ✅ **Gestion des erreurs** : Pas d'exposition d'informations sensibles
- ✅ **Logs d'audit** : Traçabilité complète

## 🚀 Déploiement

### 1. Développement

```bash
# Démarrer les services
docker-compose up -d

# Tester l'endpoint
curl -X POST http://localhost:8082/api/mtf/run
```

### 2. Production

```bash
# Déployer avec Docker
docker-compose -f docker-compose.prod.yml up -d

# Vérifier la santé
curl -X GET http://localhost:8082/api/mtf/health

# Tester l'endpoint
curl -X POST http://localhost:8082/api/mtf/run
```

## 📚 Documentation

- [Documentation API complète](docs/MTF_RUN_API.md)
- [Exemples d'utilisation](docs/MTF_RUN_EXAMPLES.md)
- [Tests et validation](tests/Controller/MtfControllerTest.php)
- [Configuration](config/services_mtf.yaml)

## 🤝 Support

Pour toute question ou problème :

1. Vérifier les logs : `tail -f var/log/dev.log`
2. Tester la connectivité : `curl -X GET http://localhost:8082/api/mtf/status`
3. Vérifier la configuration : `php bin/console app:test-system`
4. Consulter la documentation : `docs/MTF_RUN_API.md`

---

**Note** : Cet endpoint est conçu pour les tests et le déclenchement manuel. Pour la production, utilisez le workflow Temporal automatique avec `php bin/console mtf:workflow start`.


