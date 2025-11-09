# Analyse de Performance MTF Run

## Vue d'ensemble

Ce document décrit le système d'analyse de performance pour l'endpoint `/api/mtf/run` qui traite 90 symboles avec 8 workers en parallèle.

## Métriques collectées

### 1. Niveau Contrôleur (`MtfController`)

- **Temps total API** : Temps total de l'appel HTTP
- **Résolution des symboles** : Temps pour charger la liste des symboles actifs
- **Démarrage des workers** : Temps pour lancer chaque processus PHP worker
- **Complétion des workers** : Temps d'exécution de chaque worker (par symbole)
- **Polling** : Temps total passé à attendre la fin des workers
- **Nombre de cycles de polling** : Nombre d'itérations de la boucle de polling

### 2. Niveau Orchestrateur (`MtfRunOrchestrator`)

- **Temps par symbole** : Temps total de traitement de chaque symbole
- **Filtrage des symboles** : Temps pour filtrer les symboles avec ordres/positions ouverts
- **Vérification des kill switches** : Temps pour vérifier les commutateurs globaux

### 3. Niveau SymbolProcessor

- **Temps de traitement par symbole** : Temps total pour traiter un symbole (appel à `mtfService->runForSymbol`)

### 4. Niveau MtfService

- **Temps par timeframe** : Temps de validation pour chaque timeframe (4h, 1h, 15m, 5m, 1m)
- **Temps de cache** : Temps pour lire le cache de validation
- **Cache hit/miss** : Indicateur si le résultat vient du cache ou a été recalculé

### 5. Niveau Repositories (à venir)

- **Requêtes DB klines** : Temps des requêtes pour récupérer les klines
- **Requêtes DB indicateurs** : Temps des requêtes pour récupérer les snapshots d'indicateurs

## Format des logs

### Logs de performance structurés

Tous les logs de performance utilisent le format suivant :

```json
{
  "level": "info",
  "message": "[MTF Controller] Performance Analysis",
  "context": {
    "total_api_time": 56.163,
    "symbols_count": 90,
    "workers": 8,
    "performance_report": {
      "total_execution_time": 56.163,
      "by_category": {
        "controller": {
          "total": 2.5,
          "count": 90,
          "avg": 0.028
        }
      },
      "by_operation": {
        "controller::worker_start": {
          "total": 0.5,
          "count": 90,
          "avg": 0.006,
          "min": 0.004,
          "max": 0.012
        }
      },
      "by_symbol": {
        "BTCUSDT": {
          "total": 0.8,
          "count": 1,
          "avg": 0.8
        }
      },
      "by_timeframe": {
        "4h": {
          "total": 5.2,
          "count": 90,
          "avg": 0.058
        }
      }
    }
  }
}
```

### Logs par timeframe

Chaque timeframe logge ses métriques :

```json
{
  "level": "info",
  "message": "[MTF] Performance 4h",
  "context": {
    "symbol": "BTCUSDT",
    "timeframe": "4h",
    "duration_seconds": 0.058,
    "cache_duration": 0.002,
    "cache_hit": true
  }
}
```

### Logs par symbole

Chaque symbole logge son temps total :

```json
{
  "level": "info",
  "message": "[Symbol Processor] Performance",
  "context": {
    "symbol": "BTCUSDT",
    "duration_seconds": 0.623,
    "status": "SUCCESS"
  }
}
```

## Analyse des résultats

### Goulots d'étranglement identifiables

1. **Temps de démarrage des workers** : Si élevé, indique un overhead de lancement de processus PHP
2. **Temps de polling** : Si élevé par rapport au temps total, indique une inefficacité dans la gestion des workers
3. **Temps par timeframe** : Permet d'identifier les timeframes les plus lents
4. **Taux de cache hit/miss** : Permet d'évaluer l'efficacité du cache
5. **Temps par symbole** : Permet d'identifier les symboles problématiques

### Métriques clés à surveiller

- **Temps moyen par symbole** : Devrait être < 1s pour un symbole avec cache
- **Temps total pour 90 symboles avec 8 workers** : Devrait être ~12-15s (90/8 * 1s)
- **Taux de cache hit** : Devrait être > 80% après le premier run
- **Temps de polling** : Devrait être < 5% du temps total

## Utilisation

### Consultation des logs

Les logs de performance sont écrits dans les logs Symfony avec le niveau `info`. Pour filtrer :

```bash
# Voir uniquement les logs de performance
grep "Performance" var/log/dev.log

# Voir le rapport final
grep "Performance Analysis" var/log/dev.log | jq

# Voir les métriques par timeframe
grep "Performance 4h\|Performance 1h\|Performance 15m" var/log/dev.log
```

### Consultation via l'API

Le rapport de performance est également inclus dans la réponse JSON de l'endpoint `/api/mtf/run` :

```json
{
  "status": "success",
  "data": {
    "performance": {
      "total_execution_time": 56.163,
      "by_category": {...},
      "by_operation": {...},
      "by_symbol": {...},
      "by_timeframe": {...}
    }
  }
}
```

## Prochaines étapes

1. Ajouter le profiling dans les repositories pour mesurer les requêtes DB
2. Ajouter le profiling dans IndicatorProviderService pour mesurer les calculs d'indicateurs
3. Créer un dashboard Grafana pour visualiser les métriques en temps réel
4. Ajouter des alertes sur les temps d'exécution anormaux

