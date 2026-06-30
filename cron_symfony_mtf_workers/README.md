# Cron Symfony MTF Workers

Temporal worker qui orchestre les exécutions planifiées de `/api/mtf/run` et des tâches de maintenance (sync contrats, cleanup, profils spécifiques). Ce dépôt fournit :
- les workflows/activities Temporal (Python) ;
- les scripts CLI pour créer/suspendre/supprimer les schedules ;
- un formatteur de réponse pour rendre les logs Temporal lisibles (< 15 lignes par run) ;
- le Dockerfile + scripts de déploiement.

> ⚠️ **Dépréciation (CLEAN-001).** Le chemin **legacy multi-jobs**
> (`CronSymfonyMtfWorkersWorkflow` + `mtf_api_call` et les 3 scripts
> `manage_mtf_workers` / `manage_scalper_micro` / `manage_exchange_profile`) est
> **déprécié** au profit du **schedule orchestrateur unique**
> (`scripts/manage_orchestrator_schedule.py` → `POST /orchestrator/run`). Voir
> [§Dépréciation (CLEAN-001)](#0-dépréciation-clean-001). Le legacy reste 100 %
> fonctionnel pendant la transition.

---

## 0. Dépréciation (CLEAN-001)

**Quoi est déprécié.** Le chemin legacy multi-jobs Temporal :

| Composant legacy | Fichier |
| --- | --- |
| Workflow | `workflows/mtf_workers.py` (`CronSymfonyMtfWorkersWorkflow`) |
| Activity HTTP | `activities/mtf_http.py` (`mtf_api_call`) |
| Modèle de job | `models/mtf_job.py` (`MtfJob`) |
| Formatter | `utils/response_formatter.py` (`format_mtf_response`) |
| Script schedule générique | `scripts/manage_mtf_workers_schedule.py` |
| Script schedule `scalper_micro` | `scripts/manage_scalper_micro_schedule.py` |
| Script schedule exchange/profile | `scripts/manage_exchange_profile_schedule.py` |

**Pourquoi.** Ce chemin enchaîne N appels `POST /api/mtf/run` par tick et porte la
logique de sélection côté Temporal. La cible (TM-001/TM-002, PY-005/PY-006)
déporte toute la logique métier (sélection des sets, concurrence, agrégation,
persistance) dans l'API Python : un tick = **un seul** `POST /orchestrator/run`.

**Vers quoi migrer.** Le **schedule orchestrateur unique**
`scripts/manage_orchestrator_schedule.py` (cf. [§4.0](#40-schedule-cible--orchestrateur-python-tm-001--tm-002)).
Ne plus créer de nouveaux schedules via les scripts legacy ci-dessus.

**Ce que CLEAN-001 fait (et ne fait pas).**

- Marque le legacy comme déprécié : notices `DEPRECATED (CLEAN-001)` dans les
  docstrings et dans la description `--help` des 3 scripts.
- Émet un `DeprecationWarning` au lancement de chaque script legacy (helper
  partagé `utils/legacy_deprecation.py`) et un `workflow.logger.warning` au début
  du workflow legacy **uniquement pour les jobs MTF-run** (`/api/mtf/run`).
  `CronSymfonyMtfWorkersWorkflow` est aussi réutilisé par les schedules **actifs**
  contract-sync (`/api/mtf/sync-contracts`) et cleanup (`/api/maintenance/cleanup`),
  qui ne sont **pas** dépréciés et n'émettent donc aucun avertissement.
  **Aucune exception n'est levée** : le legacy continue de fonctionner à
  l'identique, avec en plus l'avertissement.
- **Ne supprime rien**, ne change aucune signature publique, et le `worker.py`
  enregistre toujours les deux chemins. La suppression effective est un **jalon
  ultérieur** (hors CLEAN-001).

**Comment migrer.** Le guide opérationnel pas-à-pas (CLEAN-002) — inventaire des
schedules legacy, correspondance des paramètres `MtfJob` → sets de dashboard,
ordre des opérations (créer/valider la cible, basculer, pauser puis supprimer le
legacy), rollback et échéance de suppression — vit dans le handbook :
[Migration legacy → orchestrateur](../docs/handbook/technical/legacy-migration.md).
- Ne touche **pas** les scripts **actifs** `manage_contract_sync` (§4.3) et
  `manage_cleanup` (§4.5), ni le chemin cible orchestrateur (§4.0).

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

### 4.0 Schedule cible — Orchestrateur Python (TM-001 / TM-002)

- **Objectif** : déclencheur cron minimal vers l'orchestrateur Python. Un schedule unique démarre `OrchestratorCronWorkflow`, qui exécute l'unique activity `orchestrator_run` : un seul `POST /orchestrator/run`. **Aucune logique de sélection de contrats côté Temporal** — la sélection des sets, la concurrence, l'agrégation et la conservation du JSON sont portées par l'API Python (PY-005/PY-006).
- **Fichier CLI** : `scripts/manage_orchestrator_schedule.py`.
- **Statut** : cible. C'est le chemin à privilégier pour les nouveaux déploiements (les schedules MTF multi-jobs ci-dessous restent en transition, CLEAN-001).
- **Composants** : `activities/orchestrator_http.py` (`orchestrator_run`) + `workflows/orchestrator_cron.py` (`OrchestratorCronWorkflow`), enregistrés à côté du legacy dans `worker.py` sur la même task queue `cron_symfony_mtf_workers`.
- **Échec sur `ok=false` (TM-002)** : le workflow journalise `run_id` + `summary` puis, si le `RunResponse` a `ok=false` (`no_sets` / `failed` / `partial_failure` / `error`), lève une `ApplicationError` (`non_retryable=True`) pour que Temporal marque le tick en échec. Sur `ok=true`, le `RunResponse` est propagé inchangé. Le `non_retryable=True` évite un retry en boucle dans le même tick : le prochain tick cron est le « retry » naturel (overlap `BUFFER_ONE`). L'activity reste « return verbatim » (la levée vit uniquement dans le workflow).

L'activity POST le `RunRequest` minimal et retourne le `RunResponse` tel quel :

```json
{
  "ok": true,
  "run_id": "run_20260619_001",
  "status": "success",
  "summary": { "total_calls": 6, "success": 6, "failed": 0 }
}
```

Paramètres via env (surchargés par les options CLI `--url`, `--dashboard-id`, `--cron`, `--schedule-id`, `--workflow-id`) :

| Variable | Défaut |
| --- | --- |
| `ORCHESTRATOR_RUN_URL` | `http://python-orchestrator:8099/orchestrator/run` |
| `ORCHESTRATOR_DASHBOARD_ID` | _(aucun)_ |
| `ORCHESTRATOR_SCHEDULE_ID` | `cron-orchestrator-run-1m` |
| `ORCHESTRATOR_WORKFLOW_ID` | `cron-orchestrator-run-runner` |
| `ORCHESTRATOR_CRON` | `*/1 * * * *` |

Commandes principales (overlap `BUFFER_ONE`) :

```bash
# Prévisualiser le schedule cible sans rien créer
python scripts/manage_orchestrator_schedule.py create --dry-run --dashboard-id 7

python scripts/manage_orchestrator_schedule.py create --dashboard-id 7
python scripts/manage_orchestrator_schedule.py status
python scripts/manage_orchestrator_schedule.py pause
python scripts/manage_orchestrator_schedule.py resume
python scripts/manage_orchestrator_schedule.py delete
```

> `ok=false` n'est pas un succès Temporal. Le workflow propage le `RunResponse` complet sur `ok=true` et lève une `ApplicationError` non-retryable sur `ok=false` (implémenté par **TM-002**), après avoir journalisé `run_id` + `summary`.

### 4.0.1 Schedule demo/testnet gardé (DEMO-004)

- **Objectif** : créer le schedule Temporal dédié OKX demo + Hyperliquid testnet.
- **Fichier CLI** : `scripts/manage_demo_testnet_schedule.py`.
- **Défaut sûr** : le schedule est créé `paused=true` et force le `RunRequest` à `dry_run=true`.
- **Activation** : `resume` et `create --resume-on-create` exécutent les runtime-checks OKX + Hyperliquid et refusent l'activation si `Schedule ready: yes` n'est pas présent pour les deux.
- **Mainnet** : aucune option mainnet n'est supportée ; `--environment` doit rester `demo-testnet`.

Paramètres via env (surchargés par CLI) :

| Variable | Défaut |
| --- | --- |
| `DEMO_TESTNET_ORCHESTRATOR_RUN_URL` | `ORCHESTRATOR_RUN_URL` ou `http://python-orchestrator:8099/orchestrator/run` |
| `DEMO_TESTNET_ORCHESTRATOR_DASHBOARD_ID` | _(aucun)_ |
| `DEMO_TESTNET_ORCHESTRATOR_SCHEDULE_ID` | `cron-orchestrator-demo-testnet-1m` |
| `DEMO_TESTNET_ORCHESTRATOR_WORKFLOW_ID` | `cron-orchestrator-demo-testnet-runner` |
| `DEMO_TESTNET_ORCHESTRATOR_CRON` | `*/1 * * * *` |

Commandes :

```bash
# Preview sans connexion Temporal.
python scripts/manage_demo_testnet_schedule.py create --dry-run --dashboard-id 7

# Création paused par défaut.
python scripts/manage_demo_testnet_schedule.py create --dashboard-id 7

# Activation après runtime-check OKX + Hyperliquid.
python scripts/manage_demo_testnet_schedule.py resume --dashboard-id 7

# Rollback opérateur.
python scripts/manage_demo_testnet_schedule.py pause
python scripts/manage_demo_testnet_schedule.py delete
```

### 4.1 Runtime Matrix Exchange/Profile (legacy, DEPRECATED CLEAN-001)

- **Statut** : **DEPRECATED (CLEAN-001)** — legacy multi-jobs. Conservé pour les déploiements existants ; ne plus créer de nouveaux schedules. Migrer vers le schedule orchestrateur unique (§4.0). Lancer ce script émet un `DeprecationWarning`.
- **Objectif** : gérer les schedules MTF explicites par couple `exchange/market_type/profile`.
- **Fichier CLI** : `scripts/manage_exchange_profile_schedule.py`.
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

### 4.2 MTF Workers (profil standard, legacy DEPRECATED CLEAN-001)

- **Objectif** : relancer `/api/mtf/run` toutes les minutes (profil actuel du runner).
- **Fichier CLI** : `scripts/manage_mtf_workers_schedule.py`.
- **Statut** : **DEPRECATED (CLEAN-001)** — legacy. À conserver pour les déploiements existants, mais ne pas utiliser pour les nouveaux schedules. Migrer vers le schedule orchestrateur unique (§4.0). Lancer ce script émet un `DeprecationWarning`.

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

### 4.4 Scalper Micro (legacy DEPRECATED CLEAN-001)

- **Objectif** : reproduire le run MTF avec le profil `scalper_micro` (4 workers) toutes les minutes.
- **Script** : `scripts/manage_scalper_micro_schedule.py`.
- **Statut** : **DEPRECATED (CLEAN-001)** — legacy. À conserver pour les déploiements existants, mais ne pas utiliser pour les nouveaux schedules. Migrer vers le schedule orchestrateur unique (§4.0). Lancer ce script émet un `DeprecationWarning`.

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

## 6. Tests & couverture (QA-003)

La suite de tests du cron tourne **sans serveur Temporal ni dépendance réseau** :
les primitives Temporal (`workflow.now` / `workflow.execute_activity` /
`workflow.logger`) sont patchées et les appels HTTP utilisent des fakes `httpx`
(pattern `asyncio.run` du repo). Aucun service Temporal/PostgreSQL n'est requis.

```bash
cd cron_symfony_mtf_workers

# 1. Installer les dépendances de test (dev-only, pinnées)
pip install -r requirements-dev.txt

# 2. Lancer la suite avec couverture + rapport des lignes manquantes
pytest --cov --cov-report=term-missing
```

- **Dépendances de test** : `requirements-dev.txt` (jamais embarqué dans
  l'image — le `Dockerfile` n'installe que `requirements.txt`). Il tire
  `requirements.txt` (`temporalio` + `httpx`, requis pour importer les tests)
  puis ajoute `pytest` et `pytest-cov`.
- **Périmètre de couverture** (figé dans `pyproject.toml`, section
  `[tool.coverage.run]`) : **uniquement** les fichiers du cron cible
  orchestrateur — `activities/orchestrator_http.py`,
  `workflows/orchestrator_cron.py`, `scripts/manage_orchestrator_schedule.py`.
  Le code legacy MTF (`mtf_http.py`, `mtf_workers.py`, schedules historiques)
  est hors-scope QA-003 et n'est donc pas mesuré.
- **Gate de couverture** : `--cov-fail-under=99` (baseline mesurée à 99.12 %,
  couverture de branches activée ; seule la ligne `if __name__ == "__main__":
  main()` reste non couverte). Le seuil est figé à l'entier inférieur de la
  baseline (cohérent QA-001) et fait échouer la CI sous ce seuil.
- **CI** : le workflow GitHub Actions dédié `.github/workflows/temporal-cron.yml`
  installe `requirements-dev.txt` et rejoue `pytest --cov --cov-fail-under=99`
  sur ce périmètre, sans aucun service externe.

Branches couvertes au minimum :
- **Activity** : succès `RunResponse` verbatim ; erreur réseau/timeout, corps
  non-JSON et HTTP non-2xx JSON → `ok=false` explicite (jamais d'exception).
- **Workflow** : propagation sur `ok=true` ; levée d'une `ApplicationError`
  non-retryable APRÈS le log pour chaque statut `ok=false`
  (`no_sets`/`failed`/`partial_failure`/`error`, TM-002) ; `tick_timestamp`
  dérivé de `workflow.now()` (déterminisme) ; clés optionnelles / URL custom.
- **Script de schedule** : `build_workflow_config`, garde-fous dashboard,
  preview `--dry-run`, routage `async_main` (create/pause/resume/delete/status),
  import réel des classes Temporal, `main()`.

---

## 7. Déploiement & opérations

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
