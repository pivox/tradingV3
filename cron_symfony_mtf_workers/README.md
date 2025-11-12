# Cron Symfony MTF Workers

Temporal worker dédié à l'appel périodique de `/api/mtf/run` avec support du
paramètre `workers`.

## Objectif

- Déclencher l'exécution MTF toutes les minutes via Temporal.
- Utiliser l'endpoint Symfony en mode JSON (`POST`) pour transmettre les
  options (nombre de workers, dry-run, etc.).

## Workflow

`CronSymfonyMtfWorkersWorkflow` accepte une liste de jobs (URL + options) et
appelle l'activité `mtf_api_call` qui poste la charge utile sur Symfony.

## Configuration principale

| Variable                 | Valeur par défaut                            |
|--------------------------|----------------------------------------------|
| `TEMPORAL_ADDRESS`       | `temporal:7233`                                   |
| `TEMPORAL_NAMESPACE`     | `default`                                    |
| `TASK_QUEUE_NAME`        | `cron_symfony_mtf_workers`                   |
| `MTF_WORKERS_URL`        | `http://trading-app-nginx:80/api/mtf/run`    |
| `MTF_WORKERS_COUNT`      | `5`                                          |

Les scripts présents dans `scripts` permettent de créer/suspendre/supprimer
les schedules Temporal associées.

## Schedule MTF Workers

Exécution périodique du workflow MTF (toutes les minutes par défaut).

**Variables d'environnement :**

| Variable                    | Valeur par défaut                            | Description |
|-----------------------------|----------------------------------------------|-------------|
| `MTF_WORKERS_SCHEDULE_ID`   | `cron-symfony-mtf-workers-1m`                | ID du schedule Temporal |
| `MTF_WORKERS_WORKFLOW_ID`   | `cron-symfony-mtf-workers-runner`            | ID du workflow |
| `MTF_WORKERS_CRON`          | `*/1 * * * *`                                | Expression cron (chaque minute) |
| `MTF_WORKERS_URL`           | `http://trading-app-nginx:80/api/mtf/run`    | URL de l'endpoint MTF |
| `MTF_WORKERS_COUNT`         | `5`                                          | Nombre de workers parallèles |
| `MTF_WORKERS_DRY_RUN`       | `true`                                       | Mode dry-run par défaut |

**Commandes :**

```bash
# Créer le schedule (avec preview)
python scripts/manage_mtf_workers_schedule.py create --dry-run

# Créer le schedule
python scripts/manage_mtf_workers_schedule.py create

# Voir le statut
python scripts/manage_mtf_workers_schedule.py status

# Mettre en pause
python scripts/manage_mtf_workers_schedule.py pause

# Reprendre
python scripts/manage_mtf_workers_schedule.py resume

# Supprimer
python scripts/manage_mtf_workers_schedule.py delete
```

## Schedule Contract Sync

Exécution quotidienne de la synchronisation des contrats (tous les jours à 09:00 UTC par défaut).

**Variables d'environnement :**

| Variable                    | Valeur par défaut                                   | Description |
|-----------------------------|-----------------------------------------------------|-------------|
| `CONTRACT_SYNC_SCHEDULE_ID` | `cron-contract-sync-daily-9am`                      | ID du schedule Temporal |
| `CONTRACT_SYNC_WORKFLOW_ID` | `contract-sync-runner`                              | ID du workflow |
| `CONTRACT_SYNC_CRON`        | `0 9 * * *`                                         | Expression cron (09:00 UTC) |
| `CONTRACT_SYNC_URL`         | `http://trading-app-nginx:80/api/mtf/sync-contracts` | URL de l'endpoint de synchro |

**Commandes :**

```bash
# Créer le schedule (avec preview)
python scripts/manage_contract_sync_schedule.py create --dry-run

# Créer le schedule
python scripts/manage_contract_sync_schedule.py create

# Voir le statut
python scripts/manage_contract_sync_schedule.py status

# Mettre en pause
python scripts/manage_contract_sync_schedule.py pause

# Reprendre
python scripts/manage_contract_sync_schedule.py resume

# Supprimer
python scripts/manage_contract_sync_schedule.py delete
```

**Timeout personnalisé :**

Le workflow de synchronisation des contrats utilise un timeout de 10 minutes par défaut (au lieu de 15 minutes pour MTF). Ce timeout est configurable via le champ `timeout_minutes` dans le job.
