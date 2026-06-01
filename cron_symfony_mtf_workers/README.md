# Cron Symfony MTF Workers

Temporal worker qui orchestre les exécutions planifiées de `/api/mtf/run` et des tâches de maintenance (sync contrats, cleanup, profils spécifiques). Ce dépôt fournit :
- les workflows/activities Temporal (Python) ;
- les scripts CLI pour créer/suspendre/supprimer les schedules ;
- un formatteur de réponse pour rendre les logs Temporal lisibles (< 15 lignes par run) ;
- le Dockerfile + scripts de déploiement.

---

## 1. Vue d’ensemble

```
Temporal Schedule ─► CronSymfonyMtfWorkersWorkflow (workflows/mtf_workers.py)
                        │
                        ├─ Normalise la liste de jobs (URL, body JSON, timeout)
                        ├─ Appelle l’activité mtf_api_call (activities/mtf_http.py)
                        ├─ Formate la réponse via utils/response_formatter.py
                        └─ Logge un résumé + retourne la réponse brute pour Temporal

mtf_api_call
    ├─ POST http://trading-app-nginx/api/mtf/run (ou autre endpoint)
    ├─ Timeout configurable par job
    ├─ Retries gérés par Temporal (default: 3, backoff)
    └─ Retourne un objet prêt à être exploité par Temporal UI / alerting
```

Le worker est lancé via `worker.py` (Task Queue `cron_symfony_mtf_workers`). Les schedules sont gérées par les scripts sous `scripts/*.py` qui consomment le SDK Temporal (namespace `default` par défaut).

---

## 2. Démarrage rapide

```bash
# 1. Installer les dépendances
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt

# 2. Définir les variables nécessaires
export TEMPORAL_ADDRESS=temporal:7233
export TEMPORAL_NAMESPACE=default
export TASK_QUEUE_NAME=cron_symfony_mtf_workers

# 3. Lancer le worker local
python worker.py

# 4. Créer un schedule MTF multi-exchange (preview Temporal)
python scripts/manage_exchange_profile_schedule.py create \
  --exchange=okx \
  --profile=scalper \
  --dry-run=true \
  --dry-run-schedule
```

En production, on préfère la cible Docker (`Dockerfile` + `deploy.sh`) décrite dans `DEPLOYMENT.md`.

---

## 3. Variables communes

| Variable | Description | Valeur par défaut |
| --- | --- | --- |
| `TEMPORAL_ADDRESS` | Adresse du frontend Temporal | `temporal:7233` |
| `TEMPORAL_NAMESPACE` | Namespace | `default` |
| `TASK_QUEUE_NAME` | Task queue consommée par `worker.py` | `cron_symfony_mtf_workers` |
| `MTF_WORKERS_URL` | URL par défaut de `/api/mtf/run` | `http://trading-app-nginx:80/api/mtf/run` |
| `MTF_WORKERS_COUNT` | `workers` envoyés à l’API | `4` |
| `MTF_WORKERS_DRY_RUN` | Flag `dry_run` | `true` |
| `REQUEST_TIMEOUT_SECONDS` | Timeout HTTP d’un job (niveau activité) | `900` (15 min, override possible par job) |

Chaque schedule définit ses propres overrides via `job.payload`. Voir `models/mtf_job.py` pour la structure complète (`url`, `payload`, `timeout_minutes`, `headers`).

---

## 4. Schedules disponibles

### 4.1 Runtime Matrix Exchange/Profile

- **Objectif** : gérer les schedules MTF explicites par couple `exchange/market_type/profile`.
- **Fichier CLI recommandé** : `scripts/manage_exchange_profile_schedule.py`.
- **Règle de sécurité** : `dry_run=true` est le défaut pour tous les exchanges, y compris BitMart.
- **Diagnostic live** : avant `dry_run=false`, le script appelle `docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check <exchange> <market_type>`.

Le script envoie toujours un payload explicite à `/api/mtf/run` :

```json
{
  "url": "http://trading-app-nginx:80/api/mtf/run",
  "workers": 4,
  "dry_run": true,
  "mtf_profile": "scalper",
  "exchange": "okx",
  "market_type": "perpetual"
}
```

Commandes principales :

```bash
python scripts/manage_exchange_profile_schedule.py create --exchange=okx --profile=scalper --dry-run=true
python scripts/manage_exchange_profile_schedule.py status --schedule-id=cron-mtf-okx-scalper-1m
python scripts/manage_exchange_profile_schedule.py pause --schedule-id=cron-mtf-okx-scalper-1m
python scripts/manage_exchange_profile_schedule.py resume --schedule-id=cron-mtf-okx-scalper-1m
python scripts/manage_exchange_profile_schedule.py delete --schedule-id=cron-mtf-okx-scalper-1m
```

`--dry-run` contrôle le payload MTF. `--dry-run-schedule` ne crée pas de schedule Temporal et sert uniquement à prévisualiser la configuration.

IDs générés par défaut :

| Entrée | `schedule_id` | `workflow_id` |
| --- | --- | --- |
| `--exchange=okx --profile=scalper --cron="*/1 * * * *"` | `cron-mtf-okx-scalper-1m` | `mtf-okx-scalper-runner` |
| `--exchange=bitmart --profile=regular --cron="*/5 * * * *"` | `cron-mtf-bitmart-regular-5m` | `mtf-bitmart-regular-runner` |
| `--exchange=hyperliquid --profile=scalper_micro --cron="*/1 * * * *"` | `cron-mtf-hyperliquid-scalper-micro-1m` | `mtf-hyperliquid-scalper-micro-runner` |
| `--exchange=okx --market-type=spot --profile=scalper --cron="*/1 * * * *"` | `cron-mtf-okx-spot-scalper-1m` | `mtf-okx-spot-scalper-runner` |

Le `market_type` est omis des IDs pour `perpetual` afin de conserver les noms recommandés historiques, et ajouté pour les autres marchés afin d'éviter les collisions.

Matrice recommandée :

| Schedule | Exchange | Market type | Profile | Cadence | Défaut |
| --- | --- | --- | --- | --- | --- |
| `cron-mtf-bitmart-scalper-1m` | `bitmart` | `perpetual` | `scalper` | `*/1 * * * *` | `dry_run=true` |
| `cron-mtf-bitmart-scalper-micro-1m` | `bitmart` | `perpetual` | `scalper_micro` | `*/1 * * * *` | `dry_run=true` |
| `cron-mtf-bitmart-regular-5m` | `bitmart` | `perpetual` | `regular` | `*/5 * * * *` | `dry_run=true` |
| `cron-mtf-okx-scalper-1m` | `okx` | `perpetual` | `scalper` | `*/1 * * * *` | `dry_run=true` |
| `cron-mtf-okx-scalper-micro-1m` | `okx` | `perpetual` | `scalper_micro` | `*/1 * * * *` | `dry_run=true` |
| `cron-mtf-okx-regular-5m` | `okx` | `perpetual` | `regular` | `*/5 * * * *` | `dry_run=true` |
| `cron-mtf-hyperliquid-scalper-1m` | `hyperliquid` | `perpetual` | `scalper` | `*/1 * * * *` | `dry_run=true` |
| `cron-mtf-hyperliquid-scalper-micro-1m` | `hyperliquid` | `perpetual` | `scalper_micro` | `*/1 * * * *` | `dry_run=true` |
| `cron-mtf-hyperliquid-regular-5m` | `hyperliquid` | `perpetual` | `regular` | `*/5 * * * *` | `dry_run=true` |

En `dry_run=true`, le script autorise la création même si `app:exchange:runtime-check` retourne `Schedule ready: no`, avec un warning explicite. En `dry_run=false`, la création est refusée tant que le diagnostic runtime, les credentials et les flags live ne passent pas.

### 4.2 MTF Workers (profil standard, legacy)

- **Objectif** : relancer `/api/mtf/run` toutes les minutes (profil actuel du runner).
- **Fichier CLI** : `scripts/manage_mtf_workers_schedule.py`.
- **Statut** : legacy. À conserver pour les déploiements existants, mais ne pas utiliser pour les nouveaux schedules multi-exchange.

| Variable | Défaut | Commentaire |
| --- | --- | --- |
| `MTF_WORKERS_SCHEDULE_ID` | `cron-symfony-mtf-workers-1m` | ID unique du schedule |
| `MTF_WORKERS_WORKFLOW_ID` | `cron-symfony-mtf-workers-runner` | Workflow ID retransmis à Temporal |
| `MTF_WORKERS_CRON` | `*/1 * * * *` | Cadence (1 minute) |
| `MTF_WORKERS_URL` | `http://trading-app-nginx:80/api/mtf/run` | Endpoint Symfony |
| `MTF_WORKERS_COUNT` | `4` | Nombre de workers parallèle côté runner |
| `MTF_WORKERS_DRY_RUN` | `true` | On active `dry_run=1` par sécurité en staging |

```bash
python scripts/manage_mtf_workers_schedule.py create       # crée/overwrite
python scripts/manage_mtf_workers_schedule.py status       # affiche info + prochaine occurrence
python scripts/manage_mtf_workers_schedule.py pause        # suspend
python scripts/manage_mtf_workers_schedule.py resume       # relance
python scripts/manage_mtf_workers_schedule.py delete       # supprime
```

### 4.3 Contract Sync

- **Objectif** : appeler `/api/mtf/sync-contracts` tous les jours à 09:00 UTC.
- **Timeout spécifique** : 10 minutes (voir `timeout_minutes` dans la définition du job).
- **Script** : `scripts/manage_contract_sync_schedule.py`.

| Variable | Défaut |
| --- | --- |
| `CONTRACT_SYNC_SCHEDULE_ID` | `cron-contract-sync-daily-9am` |
| `CONTRACT_SYNC_WORKFLOW_ID` | `contract-sync-runner` |
| `CONTRACT_SYNC_CRON` | `0 9 * * *` |
| `CONTRACT_SYNC_URL` | `http://trading-app-nginx:80/api/mtf/sync-contracts` |

### 4.4 Scalper Micro (legacy)

- **Objectif** : reproduire le run MTF avec le profil `scalper_micro` (4 workers) toutes les minutes.
- **Script** : `scripts/manage_scalper_micro_schedule.py`.
- **Statut** : legacy. À conserver pour les déploiements existants, mais ne pas utiliser pour les nouveaux schedules multi-exchange.

| Variable | Défaut |
| --- | --- |
| `SCALPER_MICRO_SCHEDULE_ID` | `cron-symfony-mtf-workers-scalper-micro-1m` |
| `SCALPER_MICRO_WORKFLOW_ID` | `cron-symfony-mtf-workers-scalper-micro-runner` |
| `SCALPER_MICRO_CRON` | `*/1 * * * *` |
| `SCALPER_MICRO_WORKERS_COUNT` | `4` |
| `SCALPER_MICRO_DRY_RUN` | `true` |

> Le script force automatiquement `{"mtf_profile": "scalper_micro"}` dans la charge utile envoyée à Symfony. Les nouveaux schedules doivent passer par `manage_exchange_profile_schedule.py` afin d'envoyer aussi `exchange` et `market_type`.

### 4.5 Cleanup / jobs personnalisés

Des scripts supplémentaires sont fournis pour gérer les schedules de cleanup (`scripts/manage_cleanup_schedule.py`) ou tout nouveau job. Inspirez-vous des modèles existants : chaque script construit une instance `MtfJob`, configure la cron et invoque la CLI Temporal.

---

## 5. Formatage des réponses & observabilité

- `utils/response_formatter.py` agrège les résultats par timeframe :
  - liste des symboles `SUCCESS` par TF (1m/5m/15m/1h) ;
  - nombre de `INVALID` par TF ;
  - métriques globales (`execution_time_seconds`, `symbols_processed`, `success_rate`).
- Le workflow ne logge que le résumé formaté (emojis compris) pour éviter les logs de 1500 lignes.
- `tests/test_response_formatter.py` garantit que la structure reste stable (utile pour les dashboards/alerting).
- La réponse JSON complète est toujours renvoyée dans `workflow_result["full_response"]` pour pouvoir investiguer dans Temporal UI.

---

## 6. Déploiement & opérations

- **Docker** : `Dockerfile` contient l’image utilisée sur Temporal Cloud / cluster interne. `deploy.sh` déclenche la build/push/tag (cf. `DEPLOYMENT.md` pour les registres acceptés et le process de rotation).
- **Monitoring** : surveiller Temporal UI (Schedule view) + les logs du worker (stdout). Tout échec d’appel HTTP remontera en `ActivityTaskFailed`. Les retries sont gérés par Temporal ; au-delà, l’alerte doit être propagée (PagerDuty ou Slack).
- **Mises à jour** :
  1. modifier les workflows/activities/tests ;
  2. lancer `pytest` (formatteur par défaut) ;
  3. reconstruire l’image ;
  4. redéployer le worker (rolling) puis relancer les schedules si nécessaire.
- **Troubleshooting rapide** :
  - `python scripts/manage_... status --history` pour inspecter les derniers runs ;
  - vérifier que `TEMPORAL_ADDRESS` est accessible depuis l’environnement (docker network) ;
  - utiliser `--dry-run` sur les scripts pour valider la payload avant d’affecter un schedule critique.

Ce README couvre les opérations courantes. Pour une vue détaillée du flux, consultez `docs/ARCHITECTURE.md`. Toute évolution (nouveau profil, paramètre runner) doit être documentée ici et dans le changelog associé. Bonne orchestration MTF !
