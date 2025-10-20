# Guide de Migration – Logging Asynchrone (Temporal ➜ Messenger/Redis)

## 🎯 Objectif

Remplacer la chaîne de logging basée sur Temporal par une architecture Symfony Messenger utilisant Redis comme file d'attente.

## ✅ Pré-requis

- Docker / Docker Compose opérationnels
- Accès au dépôt mis à jour (include `symfony/messenger`)
- Redis accessible (via `docker-compose` ou service managé)

## 1. Sauvegarde

```bash
cp trading-app/config/packages/monolog.yaml trading-app/config/packages/monolog.yaml.backup
cp docker-compose.yml docker-compose.yml.backup
```

## 2. Nouvelle Architecture

```
Monolog → MessengerLogHandler → Redis (async_logging) → LogMessageHandler → var/log/<channel>-YYYY-MM-DD.log
```

### Services clés
- `trading-app-php` : application principale avec Monolog/Messenger.
- `redis` : transport de messages.
- `logging-consumer` : `messenger:consume async_logging` en continu.

## 3. Déploiement rapide

```bash
docker-compose up -d redis logging-consumer trading-app-php
```

*(Optionnel) Redémarrer le worker MTF si nécessaire : `docker-compose up -d mtf-worker`*

## 4. Validation

1. **Tester l’application**
   ```bash
   docker-compose exec trading-app-php php bin/console app:test-logging --count=20
   ```

2. **Vérifier les fichiers générés**
   ```bash
   DATE=$(date +%Y-%m-%d)
   docker-compose exec trading-app-php ls -la /var/www/html/var/log/*-$DATE.log
   ```

3. **Consulter la file Redis**
   ```bash
   docker-compose exec redis redis-cli LLEN log-messages
   ```

4. **Surveiller le consumer**
   ```bash
   docker-compose logs logging-consumer -f
   ```

## 5. Rollback (si nécessaire)

```bash
docker-compose stop logging-consumer trading-app-php redis
cp trading-app/config/packages/monolog.yaml.backup trading-app/config/packages/monolog.yaml
cp docker-compose.yml.backup docker-compose.yml
docker-compose up -d trading-app-php
```

## 6. Dépannage rapide

| Symptôme | Diagnostic | Action |
|----------|------------|--------|
| `Log published failed` + `__messenger_error` | Redis inaccessible | Vérifier `MESSENGER_TRANSPORT_DSN`, relancer Redis. |
| Pas de fichiers dans `var/log` | Consumer arrêté | `docker-compose logs logging-consumer`, relancer le service. |
| File Redis qui grossit | Débit consumer insuffisant | Scaler `logging-consumer` (`docker-compose up -d --scale logging-consumer=3`). |

## 7. Monitoring recommandé

- **File Redis (`log-messages`)** : longueur < 1000 messages.
- **Consumer vivant** : `docker-compose ps logging-consumer`.
- **Fichiers journaliers** : rotation automatique par date (`var/log/<channel>-YYYY-MM-DD.log`).
- **Promtail/Loki** : pointer sur `var/log` (volume partagé avec le consumer).

## 8. Scripts utiles

- `./scripts/deploy-async-logging.sh` : redémarre Redis + consumer + application.
- `./scripts/validate-async-logging.sh` : série de checks automatisés.
- `./trading-app/start-logging-consumer.sh` : lance un consumer (mode interactif ou daemon).

## 9. Points d’attention

- Installer les dépendances : `composer install` (ajout de `symfony/messenger`).
- Ouvrir le port Redis si nécessaire (`6379`).
- Partager `var/log` entre `trading-app-php`, `logging-consumer` et Promtail.

