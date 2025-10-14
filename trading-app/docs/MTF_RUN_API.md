# API MTF Run - Documentation

## Endpoint `/api/mtf/run`

### Description

Cet endpoint exécute manuellement la logique MTF complète en bouclant sur les contrats spécifiés. Il implémente exactement la même logique que le workflow Temporal automatique, mais de manière synchrone et contrôlable.

### Méthode

`POST /api/mtf/run`

### Paramètres

| Paramètre | Type | Requis | Défaut | Description |
|-----------|------|--------|--------|-------------|
| `symbols` | array | Non | `['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT']` | Liste des symboles à traiter |
| `dry_run` | boolean | Non | `true` | Mode dry-run (pas de création d'order plans) |
| `force_run` | boolean | Non | `false` | Forcer l'exécution même si les kill switches sont OFF |

### Exemple de requête

```bash
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT"],
    "dry_run": true,
    "force_run": false
  }'
```

### Réponse

#### Succès (200)

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
          "4h": {
            "status": "valid",
            "timeframe": "4h",
            "kline_time": "2024-01-15T12:00:00+00:00",
            "signal_side": "LONG",
            "validation_state": {
              "symbol": "BTCUSDT",
              "timeframe": "4h",
              "status": "VALID",
              "kline_time": "2024-01-15T12:00:00+00:00",
              "expires_at": "2024-01-15T16:00:00+00:00",
              "details": {
                "signal_side": "LONG",
                "signal_score": 0.85,
                "kline_bullish": true
              }
            }
          },
          "1h": {
            "status": "valid",
            "timeframe": "1h",
            "kline_time": "2024-01-15T14:00:00+00:00",
            "signal_side": "LONG",
            "validation_state": {
              "symbol": "BTCUSDT",
              "timeframe": "1h",
              "status": "VALID",
              "kline_time": "2024-01-15T14:00:00+00:00",
              "expires_at": "2024-01-15T15:00:00+00:00",
              "details": {
                "signal_side": "LONG",
                "signal_score": 0.78,
                "kline_bullish": true
              }
            }
          },
          "15m": {
            "status": "valid",
            "timeframe": "15m",
            "kline_time": "2024-01-15T14:15:00+00:00",
            "signal_side": "LONG",
            "validation_state": {
              "symbol": "BTCUSDT",
              "timeframe": "15m",
              "status": "VALID",
              "kline_time": "2024-01-15T14:15:00+00:00",
              "expires_at": "2024-01-15T14:30:00+00:00",
              "details": {
                "signal_side": "LONG",
                "signal_score": 0.72,
                "kline_bullish": true
              }
            }
          },
          "5m": {
            "status": "valid",
            "timeframe": "5m",
            "kline_time": "2024-01-15T14:25:00+00:00",
            "signal_side": "LONG",
            "validation_state": {
              "symbol": "BTCUSDT",
              "timeframe": "5m",
              "status": "VALID",
              "kline_time": "2024-01-15T14:25:00+00:00",
              "expires_at": "2024-01-15T14:30:00+00:00",
              "details": {
                "signal_side": "LONG",
                "signal_score": 0.68,
                "kline_bullish": true
              }
            }
          },
          "1m": {
            "status": "valid",
            "timeframe": "1m",
            "kline_time": "2024-01-15T14:29:00+00:00",
            "signal_side": "LONG",
            "validation_state": {
              "symbol": "BTCUSDT",
              "timeframe": "1m",
              "status": "VALID",
              "kline_time": "2024-01-15T14:29:00+00:00",
              "expires_at": "2024-01-15T14:30:00+00:00",
              "details": {
                "signal_side": "LONG",
                "signal_score": 0.65,
                "kline_bullish": true
              }
            }
          }
        },
        "consistency": {
          "status": "CONSISTENT",
          "details": {
            "sides": {
              "4h": "LONG",
              "1h": "LONG",
              "15m": "LONG",
              "5m": "LONG",
              "1m": "LONG"
            },
            "issues": []
          }
        },
        "filters": {
          "status": "passed",
          "details": {
            "issues": [],
            "filters_applied": ["rsi", "extension", "pullback"]
          }
        },
        "dry_run": true,
        "timestamp": "2024-01-15 14:30:25"
      },
      "ETHUSDT": {
        "status": "failed",
        "reason": "4H validation failed",
        "step": "4h",
        "details": {
          "status": "invalid",
          "reason": "RSI in extreme zone (4h)",
          "timeframe": "4h",
          "validation_state": {
            "symbol": "ETHUSDT",
            "timeframe": "4h",
            "status": "INVALID",
            "kline_time": "2024-01-15T12:00:00+00:00",
            "expires_at": "2024-01-15T16:00:00+00:00",
            "details": {
              "issues": ["RSI in extreme zone (4h)"],
              "signal_side": "LONG",
              "signal_score": 0.45,
              "kline_bullish": true
            }
          }
        },
        "timestamp": "2024-01-15 14:30:25"
      }
    }
  }
}
```

#### Erreur (403) - Kill Switch OFF

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

#### Erreur (500) - Erreur interne

```json
{
  "status": "error",
  "message": "Database connection failed"
}
```

### Logique d'exécution

Pour chaque symbole, l'endpoint exécute la logique suivante :

1. **Vérification des kill switches**
   - Global kill switch
   - Symbol kill switch
   - Timeframe kill switches

2. **Validation séquentielle des timeframes**
   - 4h → 1h → 15m → 5m → 1m
   - Vérification de la fenêtre de grâce
   - Validation des bougies et indicateurs
   - Application des règles de validation

3. **Vérification de la cohérence MTF**
   - Tous les timeframes doivent avoir le même signal
   - Pas de divergence entre les timeframes

4. **Application des filtres d'exécution**
   - RSI < 70
   - Distance prix-MA21 ≤ 2×ATR
   - Pullback confirmé

5. **Création de l'order plan** (si pas en dry-run)
   - Contexte MTF complet
   - Paramètres de risque
   - Paramètres d'exécution

### Statuts de réponse

| Statut | Description |
|--------|-------------|
| `success` | Symbole traité avec succès, order plan créé |
| `failed` | Échec à une étape de validation |
| `skipped` | Symbole ignoré (kill switch OFF) |
| `error` | Erreur technique |

### Codes d'erreur

| Code | Description |
|------|-------------|
| 200 | Succès |
| 403 | Kill switch global OFF |
| 500 | Erreur interne du serveur |

### Utilisation

#### Test rapide

```bash
# Test avec les symboles par défaut en mode dry-run
curl -X POST http://localhost:8082/api/mtf/run
```

#### Test avec symboles spécifiques

```bash
# Test avec BTCUSDT seulement
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{"symbols": ["BTCUSDT"]}'
```

#### Test en mode production

```bash
# Test avec création d'order plans
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT", "ETHUSDT"],
    "dry_run": false
  }'
```

#### Test avec force run

```bash
# Test en ignorant les kill switches
curl -X POST http://localhost:8082/api/mtf/run \
  -H "Content-Type: application/json" \
  -d '{
    "symbols": ["BTCUSDT"],
    "force_run": true
  }'
```

### Commandes CLI

#### Test de l'endpoint

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
```

### Monitoring

#### Logs

L'endpoint génère des logs détaillés :

```
[MTF Controller] Starting manual MTF run
[MTF Controller] Processing symbol
[MTF Controller] MTF run completed
```

#### Métriques

- Temps d'exécution
- Nombre de symboles traités
- Taux de succès
- Détails par symbole

### Sécurité

- Vérification des kill switches
- Mode dry-run par défaut
- Validation des paramètres
- Gestion des erreurs
- Logs d'audit

### Performance

- Exécution synchrone
- Timeout de 60 secondes
- Gestion des erreurs par symbole
- Pas de blocage sur un symbole défaillant

### Intégration

Cet endpoint peut être utilisé pour :

- Tests manuels
- Déclenchement ponctuel
- Validation de la logique
- Debug et troubleshooting
- Intégration avec d'autres systèmes




