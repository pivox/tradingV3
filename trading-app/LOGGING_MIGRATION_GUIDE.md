# Guide de Migration - Système de Logging Asynchrone

## 🎯 Objectif

Migrer du système de logging synchrone vers un système asynchrone utilisant un worker Temporal dédié.

## 📋 Prérequis

- ✅ Temporal server en cours d'exécution
- ✅ Docker et Docker Compose installés
- ✅ Accès aux logs existants pour validation

## 🚀 Déploiement

### Étape 1: Sauvegarde
```bash
# Sauvegarder la configuration actuelle
cp config/packages/monolog.yaml config/packages/monolog.yaml.backup
cp docker-compose.yml docker-compose.yml.backup
```

### Étape 2: Déploiement Automatique
```bash
# Exécuter le script de déploiement
./scripts/deploy-async-logging.sh
```

### Étape 3: Déploiement Manuel (Alternative)
```bash
# 1. Arrêter les services
docker-compose stop trading-app-php mtf-worker

# 2. Démarrer le worker de logs
docker-compose up -d log-worker

# 3. Redémarrer l'application
docker-compose up -d trading-app-php mtf-worker

# 4. Vérifier le statut
docker-compose ps
```

## 🧪 Tests de Validation

### Test 1: Vérification des Services
```bash
# Vérifier que tous les services sont UP
docker-compose ps

# Vérifier les logs du worker
docker-compose logs log-worker --tail 20
```

### Test 2: Test de Performance
```bash
# Test avec 100 logs
docker-compose exec trading-app-php php bin/console app:test-logging --count=100

# Test avec 500 logs
docker-compose exec trading-app-php php bin/console app:test-logging --count=500
```

### Test 3: Vérification des Fichiers de Logs
```bash
# Vérifier que les fichiers sont créés
docker-compose exec trading-app-php ls -la /var/log/symfony/

# Vérifier le contenu d'un fichier
docker-compose exec trading-app-php head -10 /var/log/symfony/signals.log
```

### Test 4: Monitoring Temporal
```bash
# Accéder à Temporal UI
open http://localhost:8233

# Vérifier la queue log-processing-queue
# Vérifier les workflows en cours
```

## 📊 Métriques de Validation

### Performance Attendue
- **Latence par log** : < 2ms (vs 25ms avant)
- **Débit** : > 1000 logs/seconde (vs 100 avant)
- **CPU** : < 5% pour logging (vs 15-20% avant)

### Validation des Logs
- ✅ Fichiers de logs créés dans `/var/log/symfony/`
- ✅ Format des logs respecté
- ✅ Rotation des fichiers fonctionnelle
- ✅ Logs visibles dans Grafana/Loki

## 🔄 Rollback

### En Cas de Problème
```bash
# 1. Arrêter le worker de logs
docker-compose stop log-worker

# 2. Restaurer la configuration
cp config/packages/monolog.yaml.backup config/packages/monolog.yaml
cp docker-compose.yml.backup docker-compose.yml

# 3. Redémarrer les services
docker-compose up -d trading-app-php mtf-worker

# 4. Vérifier le retour au système synchrone
docker-compose exec trading-app-php php bin/console app:test-logging --count=10
```

## 🐛 Dépannage

### Problème: Worker de logs ne démarre pas
```bash
# Vérifier les logs
docker-compose logs log-worker

# Vérifier la connexion Temporal
docker-compose exec log-worker php bin/console app:test-temporal

# Redémarrer le worker
docker-compose restart log-worker
```

### Problème: Logs manquants
```bash
# Vérifier la queue Temporal
curl http://localhost:8233/api/v1/namespaces/default/queues/log-processing-queue

# Vérifier les fichiers de logs
docker-compose exec log-worker ls -la /var/log/symfony/

# Forcer un flush
docker-compose exec trading-app-php php bin/console app:test-logging --count=1
```

### Problème: Performance dégradée
```bash
# Vérifier les ressources
docker stats

# Ajuster les paramètres de buffer
# Modifier LOG_BUFFER_SIZE et LOG_FLUSH_INTERVAL dans docker-compose.yml
```

## 📈 Monitoring Post-Déploiement

### Métriques à Surveiller
- **Latence des logs** : < 2ms
- **Taille des queues Temporal** : < 1000 items
- **Erreurs de traitement** : < 1%
- **Utilisation CPU** : < 5% pour logging

### Alertes Recommandées
- Queue Temporal > 5000 items
- Erreurs de traitement > 5%
- Worker de logs down > 1 minute
- Latence des logs > 10ms

## 🔧 Optimisation

### Paramètres Ajustables
```yaml
# docker-compose.yml
environment:
  LOG_BUFFER_SIZE: 20          # Taille du buffer (défaut: 10)
  LOG_FLUSH_INTERVAL: 3        # Intervalle de flush en secondes (défaut: 5)
```

### Scaling
```bash
# Augmenter le nombre de workers de logs
docker-compose up -d --scale log-worker=3
```

## 📚 Documentation

- [Architecture Async Logging](LOGGING_ASYNC_ARCHITECTURE.md)
- [Temporal Documentation](https://docs.temporal.io/)
- [Monolog Documentation](https://github.com/Seldaek/monolog)

## 🆘 Support

En cas de problème :
1. Vérifier les logs : `docker-compose logs log-worker`
2. Vérifier Temporal UI : http://localhost:8233
3. Tester avec : `docker-compose exec trading-app-php php bin/console app:test-logging`
4. Rollback si nécessaire


