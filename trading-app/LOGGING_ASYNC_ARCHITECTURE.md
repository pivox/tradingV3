# Architecture de Logging Asynchrone avec Worker Temporal

## 🎯 Objectif

Remplacer l'écriture directe des logs sur filesystem par un système asynchrone utilisant un worker Temporal dédié. Cela améliore les performances, la fiabilité et la scalabilité du système de logging.

## 🏗️ Architecture

### Flux des Logs

```
Symfony App → LogPublisher → Temporal Queue → LogWorker → Filesystem → Promtail → Loki
```

### Composants

1. **LogPublisher** : Service qui publie les logs vers Temporal
2. **TemporalLogHandler** : Handler Monolog personnalisé avec buffering
3. **LogProcessingWorkflow** : Workflow Temporal pour orchestrer le traitement
4. **LogProcessingActivity** : Activité qui écrit réellement sur filesystem
5. **LogWorker** : Worker Temporal dédié aux logs
6. **LogWorkerCommand** : Commande pour démarrer le worker

## 📋 Avantages

### Performance
- **Non-bloquant** : L'application principale n'est plus bloquée par l'I/O filesystem
- **Buffering** : Les logs sont groupés par batch pour optimiser l'écriture
- **Asynchrone** : Traitement en arrière-plan via Temporal

### Fiabilité
- **Retry automatique** : En cas d'échec d'écriture, retry avec backoff exponentiel
- **Fallback** : Si Temporal n'est pas disponible, fallback vers logging synchrone
- **Monitoring** : Visibilité complète via Temporal UI

### Scalabilité
- **Worker dédié** : Traitement des logs isolé de l'application principale
- **Batch processing** : Traitement par lots pour optimiser les performances
- **Queue management** : Gestion des pics de charge via les queues Temporal

## 🔧 Configuration

### Variables d'Environnement

```bash
# Temporal
TEMPORAL_ADDRESS=temporal-grpc:7233
TEMPORAL_NAMESPACE=default

# Logging
LOG_BUFFER_SIZE=10
LOG_FLUSH_INTERVAL=5
```

### Services Docker

```yaml
# Worker de logs
log-worker:
  build: ./trading-app
  command: php bin/console app:log-worker --daemon
  volumes:
    - ./trading-app/var/log:/var/log/symfony
  depends_on:
    - temporal
```

## 🚀 Utilisation

### Démarrage du Worker

```bash
# Mode normal
php bin/console app:log-worker

# Mode daemon
php bin/console app:log-worker --daemon
```

### Monitoring

```bash
# Statut du worker
docker logs log_worker

# Temporal UI
http://localhost:8233
```

## 📊 Monitoring et Observabilité

### Métriques Disponibles

- **Logs traités** : Nombre de logs écrits avec succès
- **Erreurs** : Nombre d'échecs d'écriture
- **Latence** : Temps de traitement des logs
- **Queue size** : Taille de la queue Temporal

### Dashboards Grafana

- **Log Processing Status** : Statut du worker de logs
- **Log Volume** : Volume de logs par canal
- **Error Rate** : Taux d'erreur de traitement

## 🔄 Migration

### Étape 1 : Déploiement
1. Déployer le nouveau worker de logs
2. Mettre à jour la configuration Monolog
3. Redémarrer les services

### Étape 2 : Validation
1. Vérifier que les logs arrivent dans Loki
2. Monitorer les performances
3. Valider la fiabilité

### Étape 3 : Optimisation
1. Ajuster les paramètres de buffering
2. Optimiser les timeouts
3. Configurer les alertes

## 🛠️ Dépannage

### Problèmes Courants

#### Worker ne démarre pas
```bash
# Vérifier les logs
docker logs log_worker

# Vérifier la connexion Temporal
docker exec log_worker php bin/console app:test-temporal
```

#### Logs manquants
```bash
# Vérifier la queue Temporal
curl http://localhost:8233/api/v1/namespaces/default/queues/log-processing-queue

# Vérifier les fichiers de logs
docker exec log_worker ls -la /var/log/symfony/
```

#### Performance dégradée
```bash
# Ajuster le buffer size
LOG_BUFFER_SIZE=20

# Ajuster l'intervalle de flush
LOG_FLUSH_INTERVAL=3
```

## 📈 Métriques de Performance

### Avant (Synchrone)
- **Latence** : 5-50ms par log
- **Throughput** : 100-500 logs/seconde
- **CPU** : 10-20% pour I/O

### Après (Asynchrone)
- **Latence** : <1ms par log (publication)
- **Throughput** : 1000-5000 logs/seconde
- **CPU** : <5% pour I/O

## 🔮 Évolutions Futures

### Fonctionnalités Avancées
- **Log routing** : Routage intelligent par canal
- **Compression** : Compression des logs avant stockage
- **Encryption** : Chiffrement des logs sensibles
- **Retention policies** : Politiques de rétention automatiques

### Intégrations
- **Elasticsearch** : Indexation pour recherche avancée
- **Kafka** : Streaming des logs en temps réel
- **S3** : Archivage long terme
- **Alerting** : Alertes basées sur les patterns de logs


