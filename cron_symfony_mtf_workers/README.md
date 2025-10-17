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
| `TEMPORAL_ADDRESS`       | `temporal:7233`                              |
| `TEMPORAL_NAMESPACE`     | `default`                                    |
| `TASK_QUEUE_NAME`        | `cron_symfony_mtf_workers`                   |
| `MTF_WORKERS_URL`        | `http://trading-app-nginx:80/api/mtf/run`    |
| `MTF_WORKERS_COUNT`      | `5`                                          |

Les scripts présents dans `scripts/new` permettent de créer/suspendre/supprimer
la schedule Temporal associée.
