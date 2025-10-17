# MTF Ping-Pong Workflow

## Description

Ce service implémente un workflow Temporal qui fait un ping-pong avec l'endpoint MTF run. Le workflow :

1. **Appelle l'endpoint MTF run** avec les paramètres configurés
2. **Attend que MTF run se termine** complètement
3. **Notifie Temporal** que l'exécution est terminée
4. **Attend un signal de Temporal** pour continuer le cycle
5. **Répète le processus** indéfiniment

## Configuration

### Timeout d'exécution
- **Maximum d'exécution** : 7 minutes (420 secondes)
- **Intervalle entre les pings** : 30 secondes (configurable)
- **Timeout par activité** : 2 minutes

### Paramètres par défaut
- **URL MTF** : `http://trading-app-nginx:80/api/mtf/run`
- **Symboles** : `["BTCUSDT", "ETHUSDT", "ADAUSDT", "SOLUSDT", "DOTUSDT"]`
- **Mode dry-run** : `true`
- **Force run** : `false`

## Architecture

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   Temporal      │    │  MTF Ping-Pong   │    │   Trading App   │
│   Workflow      │◄──►│     Worker       │◄──►│   MTF Run API   │
└─────────────────┘    └──────────────────┘    └─────────────────┘
```

## Utilisation

### 1. Démarrage du worker

```bash
# Via Docker
docker-compose up mtf-ping-pong

# Ou directement
python worker.py
```

### 2. Démarrage du workflow

```bash
# Démarrer un nouveau workflow
python start_workflow.py start

# Arrêter un workflow
python start_workflow.py stop <workflow_id>

# Vérifier le statut
python start_workflow.py status <workflow_id>
```

### 3. Variables d'environnement

```bash
TEMPORAL_ADDRESS=temporal:7233
TEMPORAL_NAMESPACE=default
TASK_QUEUE_NAME=mtf-ping-pong-queue
WORKER_IDENTITY=mtf-ping-pong-worker
```

## Workflow

### Cycle principal

1. **Appel MTF Run** : Envoie une requête POST à l'endpoint MTF run
2. **Attente de completion** : Vérifie périodiquement que MTF run est terminé
3. **Notification Temporal** : Informe Temporal que l'exécution est terminée
4. **Attente du signal** : Attend un signal "continue" de Temporal
5. **Pause** : Attend l'intervalle configuré avant de recommencer

### Gestion des erreurs

- **Retry policy** : 3 tentatives avec backoff exponentiel
- **Timeout par activité** : 2 minutes maximum
- **Timeout global** : 7 minutes maximum
- **Gestion des exceptions** : Logging détaillé et continuation du cycle

### Signaux

- `continue_signal()` : Continue le cycle ping-pong
- `stop_signal()` : Arrête le workflow
- `get_status()` : Retourne le statut actuel

## Monitoring

### Logs

Le service produit des logs détaillés pour :
- Démarrage/arrêt du worker
- Appels à MTF run
- Notifications à Temporal
- Erreurs et timeouts

### Métriques

- Nombre d'itérations complétées
- Temps d'exécution par cycle
- Taux de succès des appels MTF run
- Statut du workflow en temps réel

## Développement

### Structure des fichiers

```
mtf-ping-pong/
├── workflows/
│   ├── __init__.py
│   └── mtf_ping_pong_workflow.py
├── activities/
│   ├── __init__.py
│   └── mtf_activities.py
├── worker.py
├── start_workflow.py
├── requirements.txt
├── Dockerfile
├── entrypoint.sh
└── README.md
```

### Tests

```bash
# Test de santé
python -c "from activities.mtf_activities import health_check_activity; print('OK')"

# Test de connexion Temporal
python -c "import asyncio; from temporalio.client import Client; asyncio.run(Client.connect('temporal:7233'))"
```

## Intégration

Ce service s'intègre avec :
- **Temporal** : Pour l'orchestration des workflows
- **Trading App** : Pour l'exécution de MTF run
- **Docker Compose** : Pour le déploiement
- **Monitoring** : Pour la surveillance des performances








