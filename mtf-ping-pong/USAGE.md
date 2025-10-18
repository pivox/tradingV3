# Guide d'utilisation - MTF Ping-Pong Workflow

## 🚀 Démarrage rapide

### 1. Déploiement

```bash
# Depuis le répertoire mtf-ping-pong
./deploy.sh
```

### 2. Vérification du déploiement

```bash
# Vérifier que le container est en cours d'exécution
docker ps | grep mtf_ping_pong_worker

# Voir les logs
docker logs -f mtf_ping_pong_worker
```

### 3. Démarrer un workflow

```bash
# Démarrer un nouveau workflow
./scripts/start_workflow.sh
```

## 📊 Surveillance

### Logs en temps réel

```bash
# Logs du worker
docker logs -f mtf_ping_pong_worker

# Logs avec filtrage
docker logs -f mtf_ping_pong_worker 2>&1 | grep "MtfPingPong"
```

### Interface Temporal UI

- **URL**: http://localhost:8233
- **Namespace**: default
- **Task Queue**: mtf-ping-pong-queue

### Vérification du statut

```bash
# Si vous connaissez l'ID du workflow
./scripts/status_workflow.sh <workflow_id>

# Ou directement via le container
docker exec mtf_ping_pong_worker python start_workflow.py status <workflow_id>
```

## 🔧 Configuration

### Variables d'environnement

```bash
TEMPORAL_ADDRESS=temporal-grpc:7233
TEMPORAL_NAMESPACE=default
TASK_QUEUE_NAME=mtf-ping-pong-queue
WORKER_IDENTITY=mtf-ping-pong-worker
```

### Paramètres du workflow

- **Timeout d'exécution**: 7 minutes (420 secondes)
- **Intervalle entre pings**: 30 secondes
- **Symboles par défaut**: BTCUSDT, ETHUSDT, ADAUSDT, SOLUSDT, DOTUSDT
- **Mode dry-run**: true (par défaut)

## 🧪 Tests

### Test de santé

```bash
docker exec mtf_ping_pong_worker python -c "from activities.mtf_activities import health_check_activity; print('OK')"
```

### Test complet

```bash
docker exec mtf_ping_pong_worker python test_workflow.py
```

## 🛠️ Maintenance

### Arrêter un workflow

```bash
./scripts/stop_workflow.sh <workflow_id>
```

### Redémarrer le service

```bash
# Arrêter
docker-compose down mtf-ping-pong-worker

# Redémarrer
docker-compose up -d mtf-ping-pong-worker
```

### Mise à jour du code

```bash
# Reconstruire l'image
docker build -t mtf-ping-pong:latest .

# Redémarrer le service
docker-compose up -d mtf-ping-pong-worker
```

## 🔍 Dépannage

### Problèmes courants

1. **Container ne démarre pas**
   ```bash
   # Vérifier les logs
   docker logs mtf_ping_pong_worker
   
   # Vérifier la connectivité Temporal
   docker exec mtf_ping_pong_worker python -c "import asyncio; from temporalio.client import Client; asyncio.run(Client.connect('temporal-grpc:7233'))"
   ```

2. **Workflow ne démarre pas**
   ```bash
   # Vérifier que le worker est prêt
   docker exec mtf_ping_pong_worker python -c "from activities.mtf_activities import health_check_activity; print('OK')"
   
   # Vérifier la connectivité à l'API MTF
   docker exec mtf_ping_pong_worker python -c "import aiohttp; import asyncio; asyncio.run(aiohttp.ClientSession().get('http://trading-app-nginx:80/api/mtf/run'))"
   ```

3. **Timeout d'exécution**
   - Le workflow s'arrête automatiquement après 7 minutes
   - Vérifier les logs pour identifier la cause du timeout
   - Ajuster les paramètres si nécessaire

### Logs utiles

```bash
# Logs d'erreur uniquement
docker logs mtf_ping_pong_worker 2>&1 | grep -i error

# Logs de démarrage
docker logs mtf_ping_pong_worker 2>&1 | grep -i "démarrage\|start"

# Logs des appels MTF
docker logs mtf_ping_pong_worker 2>&1 | grep -i "mtf"
```

## 📈 Métriques

### Surveillance des performances

- **Temps d'exécution par cycle**: Surveillé dans les logs
- **Taux de succès**: Compteurs dans le statut du workflow
- **Nombre d'itérations**: Affiché dans le statut du workflow

### Alertes recommandées

- Workflow arrêté inattendument
- Taux d'erreur élevé (> 10%)
- Temps d'exécution anormalement long
- Container non disponible

## 🔐 Sécurité

### Bonnes pratiques

- Le service s'exécute avec un utilisateur non-root
- Pas d'exposition de ports sensibles
- Communication interne uniquement via les réseaux Docker
- Logs sans informations sensibles

### Audit

```bash
# Vérifier les permissions
docker exec mtf_ping_pong_worker ls -la

# Vérifier l'utilisateur
docker exec mtf_ping_pong_worker whoami
```








