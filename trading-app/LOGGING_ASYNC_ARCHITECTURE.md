# Architecture de Logging Asynchrone avec Symfony Messenger & Redis

## 🎯 Objectif

Acheminer les logs métier sans bloquer le cycle d'exécution Symfony en les déposant dans Redis via Symfony Messenger, puis en les traitant par un consumer dédié. Cette approche supprime la dépendance à Temporal pour le logging tout en conservant un fallback local en cas de panne de la file.

## 🏗️ Architecture

```
Symfony App → MessengerLogHandler → Redis (stream) → Consumer Messenger → Filesystem → Promtail → Loki
```

### Composants

1. **MessengerLogHandler** : handler Monolog qui expédie chaque LogRecord sur le bus Messenger.
2. **Redis (async_logging)** : transport configuré pour stocker les messages `LogMessage`.
3. **LogMessage** : DTO immuable contenant canal, niveau, message, contexte et timestamp.
4. **LogMessageHandler** : consumer Messenger qui écrit les logs sur disque (`var/log/<canal>-YYYY-MM-DD.log`).
5. **messenger:consume** : commande à lancer dans un conteneur séparé pour traiter la file en continu.

## ✅ Avantages

- **Non-bloquant** : dispatch rapide sur Redis, pas d'attente d'I/O côté application.
- **Tolérance aux pannes** : fallback fichier intégré si Redis est indisponible lors du dispatch.
- **Scalable** : plusieurs consumers peuvent être lancés pour absorber les pics.
- **Intégration Symfony native** : Messenger gère retries, DLQ, monitoring via `messenger:failed`.

## 🔧 Configuration

### Variables d'environnement

```bash
# .env / .env.local
MESSENGER_TRANSPORT_DSN=redis://redis:6379/log-messages
ASYNC_LOGGING_ENABLED=1
```

### Monolog (`config/packages/monolog.yaml`)

Le handler `async_messenger` pointe maintenant vers `App\Logging\MessengerLogHandler`. Les handlers rotatifs existants servent de fallback et d'archivage local.

### Messenger (`config/packages/messenger.yaml`)

```yaml
framework:
    messenger:
        transports:
            async_logging: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            App\Logging\Message\LogMessage: async_logging
```

## 🚀 Consommation

Lancer un worker dédié (dans un conteneur `consumer-logs`, par exemple) :

```bash
php bin/console messenger:consume async_logging --time-limit=3600 --sleep=1
```

Monter `var/log` en volume si vous souhaitez exposer les fichiers aux autres services/Promtail.

## 🗂️ Fichiers générés

- Les fichiers sont nommés `var/log/<channel>-YYYY-MM-DD.log`.
- Les messages incluent contexte sérialisé clé=valeur.
- En cas d'échec du dispatch Messenger, le handler écrit localement avec `__messenger_error=<message>` dans le contexte.

## 🪛 Dépannage rapide

| Problème | Vérification | Résolution |
|----------|--------------|------------|
| Pas de logs côté consumer | `redis-cli LRANGE log-messages 0 -1` | Vérifier que l'app envoie bien (`ASYNC_LOGGING_ENABLED=1`) et que Redis est accessible. |
| Erreurs de dispatch | Chercher `__messenger_error` dans les fichiers `var/log/*` | Corriger DSN/Redis, relancer consumer. |
| Messages bloqués | `php bin/console messenger:failed:show` | Relancer `messenger:consume` ou purger la file. |

## 🔄 Migration depuis Temporal

1. Supprimer les composants Temporal (`LogPublisher`, `TemporalLogHandler`, workers et workflows associés`).
2. Ajouter Redis (ou reconfigurer un cluster existant) et déployer le consumer Messenger.
3. Vérifier `php bin/console app:test-logging --count=10` pour générer des messages et confirmer la présence des fichiers `var/log/<channel>-<date>.log`.

Cette architecture reste compatible avec Loki/Promtail : il suffit de pointer Promtail sur `var/log` ou sur tout volume partagé par le consumer.

