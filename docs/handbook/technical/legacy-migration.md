# Migration : runner legacy multi-jobs → schedule orchestrateur unique

Ce guide explique, **pas à pas**, comment migrer un déploiement existant du
**chemin legacy multi-jobs Temporal** (N appels `POST /api/mtf/run` par tick) vers
le **schedule orchestrateur unique** (un seul `POST /orchestrator/run` par tick).

C'est de la **documentation opérationnelle** : il ne change aucun code ni aucun
comportement. Le legacy est **déprécié** (CLEAN-001) mais reste 100 % fonctionnel ;
la **suppression effective** des fichiers legacy est un **jalon ultérieur**
explicitement **hors périmètre** de ce guide (voir
[§7. Échéance de suppression](#7-echeance-de-suppression-du-legacy-jalon-futur)).

> **Pré-requis de lecture.** Ce guide **ne duplique pas** la doc existante, il
> s'appuie dessus :
>
> - le rappel de dépréciation : `cron_symfony_mtf_workers/README.md` §0
>   « Dépréciation (CLEAN-001) » et la section
>   [Responsabilités legacy](temporal.md#responsabilites-legacy) de
>   [Temporal workers](temporal.md) ;
> - le chemin cible : [Schedule cible (orchestrateur)](temporal.md#schedule-cible-orchestrateur)
>   et [Python orchestrator](python-orchestrator.md) (dashboards, sets, runs,
>   garde-fous live) ;
> - la matrice des scripts : `README.md` §4 et la table
>   [Scripts de schedules legacy](temporal.md#scripts-de-schedules-legacy).

---

## 1. Principe : de N jobs à un seul POST

| | Legacy multi-jobs (déprécié) | Cible orchestrateur (à privilégier) |
| --- | --- | --- |
| Déclencheur Temporal | `CronSymfonyMtfWorkersWorkflow` + activity `mtf_api_call` | `OrchestratorCronWorkflow` + activity `orchestrator_run` |
| Par tick | **N** `POST /api/mtf/run` (un par `MtfJob`) | **un seul** `POST /orchestrator/run` |
| Sélection des contrats / sets | portée **côté Temporal** (jobs codés dans les scripts) | portée **côté API Python** (dashboards + sets, PY-003/PY-004) |
| Concurrence bornée | côté Symfony (`workers`) | côté orchestrateur (`MAX_CONCURRENCY`, PY-005) |
| Agrégation / persistance JSON | `utils/response_formatter.py` + résultat workflow | API Python (`Run.last_json`, `RunSet`, PY-005/PY-006) |
| Idempotence / locks | aucune (cadence cron + `BUFFER_ONE` seulement) | `SAFE-001` (locks par symbole) + `SAFE-002` (idempotence run/set) |
| Garde-fous live | par script (`assert_exchange_schedule_policy`, runtime-check) | couche unique `SAFE-003` (`assess_live`, live OFF par défaut) |
| Observabilité | logs workflow compactés | audit JSON line `OBS-001` + métriques `GET /metrics` `OBS-002` |

Autrement dit : **toute la logique métier déménage du cron Temporal vers
`python-orchestrator/`**. Temporal redevient un simple cron supervisé qui « tape »
une URL unique et échoue si l'orchestrateur retourne `ok=false` (TM-002). La
configuration des runs (quels exchanges/profils/symboles, dry-run ou live) se fait
désormais via les **dashboards et sets** de l'API Python, plus dans les scripts de
schedule.

Détails de chaque brique cible : voir
[Python orchestrator](python-orchestrator.md) — refresh des contrats
([PY-003](python-orchestrator.md#api-python-refresh-explicite-des-contrats-py-003)),
payload préparé
([PY-004](python-orchestrator.md#payload-apimtfrun-prepare-py-004)),
exécution + persistance (PY-005), lecture de l'historique
([PY-006](python-orchestrator.md#lecture-de-lhistorique-py-006)),
locks/idempotence/live (SAFE-001/002/003), audit/métriques (OBS-001/002).

---

## 2. Périmètre : ce qui migre, ce qui ne migre PAS

### Concerné par la migration (legacy multi-jobs, déprécié CLEAN-001)

| Script de schedule | Rôle legacy |
| --- | --- |
| `scripts/manage_mtf_workers_schedule.py` | Schedule générique vers `/api/mtf/run` (profil `scalper`). |
| `scripts/manage_scalper_micro_schedule.py` | Schedule dédié au profil `scalper_micro`. |
| `scripts/manage_exchange_profile_schedule.py` | Schedule par couple `exchange` / `market_type` / `profile`. |

Ces trois scripts démarrent `CronSymfonyMtfWorkersWorkflow` et émettent un
`DeprecationWarning` au lancement (CLEAN-001). **Ce sont eux que ce guide migre.**

### NON concerné — scripts ACTIFS, à NE PAS migrer

| Script ACTIF | Rôle | Statut |
| --- | --- | --- |
| `scripts/manage_contract_sync_schedule.py` | `POST /api/mtf/sync-contracts`, quotidien `0 9 * * *` (09:00 UTC). | **actif, non déprécié** |
| `scripts/manage_cleanup_schedule.py` | `POST /api/maintenance/cleanup`, hebdomadaire (`0 3 * * 0` par défaut). | **actif, non déprécié** |

> ⚠️ **Piège à éviter.** `contract_sync` et `cleanup` démarrent **eux aussi**
> `CronSymfonyMtfWorkersWorkflow` (même `WORKFLOW_TYPE`, URL différente). Ils
> **n'émettent aucun `DeprecationWarning`** et **ne font PAS partie de la
> migration**. Ne les pausez pas, ne les supprimez pas : ils restent des
> schedules de maintenance à part entière. La dépréciation CLEAN-001 ne vise que
> les **jobs MTF-run** (`/api/mtf/run`), pas la maintenance.

Le chemin cible `manage_orchestrator_schedule.py`, le worker `worker.py` et les
fichiers du cron orchestrateur (`activities/orchestrator_http.py`,
`workflows/orchestrator_cron.py`) ne sont **pas** touchés par cette migration non
plus : ils sont déjà en place.

---

## 3. Tableau de correspondance des paramètres

L'unité de configuration legacy est le `MtfJob` (cf.
[Payload `MtfJob`](temporal.md#payload-mtfjob)). Côté cible, elle est scindée en
deux : la **config d'un set** (persistée via l'API Python, PY-002/PY-004) et le
**contexte de tick** (`RunRequest`, porté par le schedule Temporal).

### 3.1 Champs `MtfJob` → set de dashboard

| `MtfJob` (legacy) | Équivalent cible | Notes |
| --- | --- | --- |
| `url` | _(implicite)_ | L'URL Symfony `/api/mtf/run` est désormais appelée **par l'orchestrateur**, pas par Temporal. Le schedule cible ne connaît que `ORCHESTRATOR_RUN_URL`. |
| `workers` | `set.workers` | Borné (`MAX_WORKERS_PER_SET`), **défaut 1** côté cible (la concurrence est portée par l'orchestrateur, pas par Symfony). |
| `dry_run` | `set.dry_run` (config) + override run-level | Le `dry_run` configuré du set est reflété dans son `payload` (PY-004). Un override ponctuel passe par `RunRequest.dry_run` (cf. §3.2). |
| `force_run` | _(pas d'équivalent set)_ | La cadence est gérée par le cron + `BUFFER_ONE` ; l'anti-rejeu par l'idempotence/locks (SAFE-001/002). Aucun champ « force » n'est exposé en set. |
| `force_timeframe_check` | _(pas d'équivalent set)_ | Non porté par le payload préparé (cœur `build_mtf_payload`, PY-004). |
| `current_tf` | _(pas d'équivalent set)_ | Idem : non exposé côté set. |
| `symbols` | `set.symbols` | Sélection explicite. Alternative : laisser vide et fixer `set.contracts_limit`, puis matérialiser via `POST /dashboards/{id}/refresh-contracts` (PY-003). |
| `exchange` | `set.exchange` | `bitmart`, `okx`, `hyperliquid`, `fake`, … |
| `market_type` | `set.market_type` | `perpetual` (défaut) ou `spot`. |
| `mtf_profile` | `set.mtf_profile` | `regular`, `scalper`, `scalper_micro`. |
| `timeout_minutes` | _(géré par l'orchestrateur)_ | Le timeout Symfony et les TTL de claim/lock sont dérivés côté orchestrateur (SAFE-001/002). Pas de réglage par set. |

> **Plusieurs jobs legacy → plusieurs sets.** Un schedule legacy qui portait
> plusieurs `MtfJob` (ou plusieurs schedules distincts par exchange/profil) se
> traduit par **plusieurs sets** sur un même dashboard. Un tick orchestrateur les
> exécute tous en parallèle borné, puis agrège (`summary.total_calls` ≈ nombre de
> sets dispatchés).

> **Live.** Le legacy `manage_scalper_micro_schedule.py` envoie `dry_run=false`.
> Côté cible, **le live reste désactivé par défaut** (SAFE-003 :
> `ORCHESTRATION_LIVE_ENABLED` OFF, allow-list vide ; OKX/Hyperliquid bannis en
> permanence). Un set `dry_run=false` n'est ni persistable ni exécutable tant que
> l'interrupteur et l'allow-list ne sont pas explicitement activés. La migration
> **ne réactive pas** le live ; reproduisez d'abord la cible en dry-run. Voir
> [Garde-fous live](temporal.md#garde-fous-live) et SAFE-003 dans
> [Python orchestrator](python-orchestrator.md).

### 3.2 Schedule legacy → schedule cible + `RunRequest`

| Réglage legacy | Équivalent cible | Notes |
| --- | --- | --- |
| `cron` (`MTF_WORKERS_CRON`, `--cron`) | `ORCHESTRATOR_CRON` / `--cron` du schedule unique | Même expression cron. |
| overlap `BUFFER_ONE` | `BUFFER_ONE` (inchangé) | Le schedule cible applique la même politique d'overlap. |
| `schedule_id` / `workflow_id` | `ORCHESTRATOR_SCHEDULE_ID` / `ORCHESTRATOR_WORKFLOW_ID` (ou `--schedule-id` / `--workflow-id`) | Défauts : `cron-orchestrator-run-1m` / `cron-orchestrator-run-runner`. |
| _(N/A : sélection codée en script)_ | `ORCHESTRATOR_DASHBOARD_ID` / `--dashboard-id` | **Obligatoire** : le schedule cible refuse un `create` sans `dashboard_id` numérique (sinon `/orchestrator/run` résout `no_sets` en boucle). |

Le schedule cible ne porte **pas** la sélection : il transmet un `RunRequest`
minimal à `/orchestrator/run`. Le contrat `RunRequest` (figé, **non modifié par ce
guide**) :

```json
{
  "dashboard_id": "7",
  "schedule_id": "cron-orchestrator-run-1m",
  "tick_timestamp": "2026-06-22T09:00:00Z",
  "idempotency_key": null,
  "dry_run": null
}
```

- `dashboard_id` / `schedule_id` : injectés par le schedule (`build_workflow_config`) ;
- `tick_timestamp` : dérivé de `workflow.now()` (déterminisme) ;
- `idempotency_key` : optionnel ; à défaut, `dashboard_id` + `tick_timestamp`
  forment un ancrage d'idempotence stable (SAFE-002) ;
- `dry_run` : override run-level optionnel (prééminence sécurité : `true` force le
  dry-run quel que soit l'état de l'interrupteur live).

---

## 4. Procédure pas à pas

> Toutes les commandes se lancent depuis `cron_symfony_mtf_workers/`, avec
> l'environnement Temporal configuré (cf. `README.md` §2/§3) :
>
> ```bash
> export TEMPORAL_ADDRESS=temporal:7233
> export TEMPORAL_NAMESPACE=default
> export TASK_QUEUE_NAME=cron_symfony_mtf_workers
> ```
>
> **Toujours commencer par un `--dry-run`/`status`.** Ne jamais supprimer un
> schedule legacy avant d'avoir validé la cible.

### a) Inventorier les schedules legacy actifs

Listez l'état de chaque schedule legacy susceptible d'exister dans votre
déploiement (adaptez aux schedules réellement créés chez vous) :

```bash
# Schedule générique /api/mtf/run
python scripts/manage_mtf_workers_schedule.py status

# Schedule dédié scalper_micro
python scripts/manage_scalper_micro_schedule.py status

# Schedules par exchange/profile (un status par schedule-id réel)
python scripts/manage_exchange_profile_schedule.py status --schedule-id=cron-mtf-bitmart-scalper-1m
```

Notez pour chacun : `schedule_id`, `cron`, et le `job` (exchange, market_type,
mtf_profile, workers, dry_run). Ces valeurs alimentent les sets de la cible (§3.1).

> Vous pouvez aussi inspecter les schedules directement dans **Temporal UI**
> (vue *Schedules*). Les schedules **actifs** `cron-contract-sync-daily-9am` et
> `cron-db-cleanup-weekly` apparaîtront aussi : **ne pas y toucher** (§2).

### b) Préparer la cible (dashboard + sets côté API Python)

Côté `python-orchestrator/`, créez un dashboard puis un set par job legacy
inventorié. Exemple (un set reproduisant un job `bitmart` / `scalper_micro` /
`workers=1`, dry-run) :

```bash
# 1) Créer un dashboard d'orchestration
curl -X POST http://python-orchestrator:8099/dashboards \
  -H 'Content-Type: application/json' \
  -d '{"name":"prod-migration","enabled":true}'
# → renvoie {"id": 7, ...}

# 2) Créer un set (équivaut à un MtfJob legacy)
curl -X POST http://python-orchestrator:8099/dashboards/7/sets \
  -H 'Content-Type: application/json' \
  -d '{
        "set_id": "bitmart_scalper_micro",
        "enabled": true,
        "action": "mtf_run",
        "exchange": "bitmart",
        "market_type": "perpetual",
        "mtf_profile": "scalper_micro",
        "dry_run": true,
        "workers": 1,
        "contracts_limit": 10
      }'

# 3) Matérialiser la sélection de symboles (refresh PY-003)
#    → renseigne set.symbols depuis GET /api/mtf/contracts et (re)génère payload (PY-004)
curl -X POST http://python-orchestrator:8099/dashboards/7/refresh-contracts
```

- `payload` est **produit côté serveur** (PY-004) — ne le fournissez pas en
  entrée (il est ignoré). Vérifiez-le en lecture via `GET /dashboards/7/sets/bitmart_scalper_micro`.
- Un set valide uniquement par `contracts_limit` (donc `symbols` vide) a un
  `payload` `null` **tant qu'un refresh n'a pas matérialisé de symboles** : PY-005
  ne dispatchera pas un set sans payload. Faites donc bien l'étape 3.
- Détails du contrat des sets et des invariants (live non persistable, sélection
  exploitable obligatoire) : [Python orchestrator](python-orchestrator.md).

Répétez l'étape 2 (+ refresh) pour **chaque** job legacy (un set par
exchange/profil). Plusieurs schedules legacy distincts ⇒ plusieurs sets sur le
même dashboard.

### c) Créer le schedule orchestrateur (dry-run d'abord)

```bash
# Prévisualiser SANS rien créer (aucune connexion Temporal requise)
python scripts/manage_orchestrator_schedule.py create --dry-run --dashboard-id 7

# Création réelle (cadence par défaut */1 * * * *, overlap BUFFER_ONE)
python scripts/manage_orchestrator_schedule.py create --dashboard-id 7

# Vérifier
python scripts/manage_orchestrator_schedule.py status
```

- `--dashboard-id` est **obligatoire** (numérique). Sans lui, `create` échoue vite
  (`refusing to create schedule: no valid dashboard configured`) car
  `/orchestrator/run` résoudrait `no_sets` à chaque tick.
- Pour une cadence ou des IDs personnalisés, ajoutez `--cron`, `--schedule-id`,
  `--workflow-id` (ou les variables `ORCHESTRATOR_*`).
- Référence : [Schedule cible (orchestrateur)](temporal.md#schedule-cible-orchestrateur)
  et `README.md` §4.0.

### d) Valider en parallèle (cible + legacy côté à côté)

Le schedule cible et les schedules legacy tournent sur la **même task queue**
(`cron_symfony_mtf_workers`) : le worker enregistre déjà les deux chemins. Vous
pouvez donc laisser tourner les deux temporairement pour comparer.

- **En dry-run** (recommandé) : aucun ordre réel n'est placé des deux côtés ;
  comparez les résultats.
- Côté cible, inspectez l'historique des runs (PY-006) :

  ```bash
  # Dernier run du dashboard (détail complet : last_json + sets)
  curl http://python-orchestrator:8099/dashboards/7/runs/latest

  # Détail d'un run / d'un set
  curl http://python-orchestrator:8099/runs/<run_id>
  curl http://python-orchestrator:8099/runs/<run_id>/sets/bitmart_scalper_micro
  ```

- Vérifiez aussi les métriques (`GET /metrics`, OBS-002) et l'audit JSON line
  (`orchestrator.audit`, OBS-001) : `set_dispatched` / `set_result` /
  `run_finished` doivent réconcilier avec `summary`.
- Côté Temporal UI, un tick cible `ok=false` apparaît en **échec** (TM-002) : c'est
  le comportement attendu (`no_sets`, `failed`, `partial_failure`, `error`).

Quand la cible reproduit fidèlement le comportement legacy (mêmes symboles, mêmes
décisions), passez au basculement.

### e) Pauser, surveiller, puis supprimer le legacy

```bash
# 1) Pauser chaque schedule legacy (réversible)
python scripts/manage_mtf_workers_schedule.py pause
python scripts/manage_scalper_micro_schedule.py pause
python scripts/manage_exchange_profile_schedule.py pause --schedule-id=cron-mtf-bitmart-scalper-1m

# 2) Surveiller la cible seule pendant une période d'observation
python scripts/manage_orchestrator_schedule.py status
curl http://python-orchestrator:8099/dashboards/7/runs/latest

# 3) Une fois confiant, supprimer les schedules legacy
python scripts/manage_mtf_workers_schedule.py delete
python scripts/manage_scalper_micro_schedule.py delete
python scripts/manage_exchange_profile_schedule.py delete --schedule-id=cron-mtf-bitmart-scalper-1m
```

> **Rappel.** Ne pausez/supprimez **que** les schedules legacy MTF-run.
> `cron-contract-sync-daily-9am` et `cron-db-cleanup-weekly` restent **actifs**
> (§2). `delete` retire le schedule Temporal mais **ne supprime aucun fichier**
> du dépôt : la suppression du code legacy est un jalon futur (§7).

---

## 5. Rollback

Tant que les fichiers legacy sont en place (ce qui est le cas jusqu'au jalon de
suppression, §7), le retour arrière est immédiat :

```bash
# 1) Relancer (resume) un schedule legacy mis en pause…
python scripts/manage_mtf_workers_schedule.py resume
python scripts/manage_scalper_micro_schedule.py resume

# …ou le recréer s'il a déjà été supprimé
python scripts/manage_mtf_workers_schedule.py create

# 2) Pauser (réversible) ou supprimer le schedule cible
python scripts/manage_orchestrator_schedule.py pause
python scripts/manage_orchestrator_schedule.py delete
```

Comme les deux chemins cohabitent sur la même task queue et que le worker
enregistre déjà les deux, **aucun redéploiement n'est nécessaire** pour basculer
dans un sens ou dans l'autre. Privilégiez `pause`/`resume` (réversible) à `delete`
pendant la fenêtre de migration.

---

## 6. Checklist de bascule

- [ ] Schedules legacy inventoriés (`status`) ; `job` de chacun noté.
- [ ] `contract_sync` / `cleanup` identifiés comme **actifs** et **exclus**.
- [ ] Dashboard créé ; un set par job legacy ; `refresh-contracts` exécuté.
- [ ] `payload` de chaque set non `null` (vérifié via `GET .../sets/{id}`).
- [ ] Schedule cible prévisualisé (`create --dry-run`) puis créé (`--dashboard-id`).
- [ ] Validation parallèle en dry-run OK (runs/metrics/audit réconcilient).
- [ ] Schedules legacy MTF-run `pause` → période d'observation → `delete`.
- [ ] Procédure de rollback connue et testée (`resume`/`create`).

---

## 7. Échéance de suppression du legacy (jalon FUTUR)

La **suppression effective** du chemin legacy est un **jalon ultérieur,
explicitement hors CLEAN-002**. Elle consistera (dans une PR de code dédiée, le
moment venu) à :

- **désenregistrer** le legacy dans `worker.py` (retirer
  `CronSymfonyMtfWorkersWorkflow` et `mtf_api_call`) ;
- **supprimer** les fichiers : `workflows/mtf_workers.py`, `activities/mtf_http.py`,
  `models/mtf_job.py`, `utils/response_formatter.py`, et les 3 scripts legacy
  (`manage_mtf_workers_schedule.py`, `manage_scalper_micro_schedule.py`,
  `manage_exchange_profile_schedule.py`) ;
- nettoyer les références / tests associés.

> **Pré-conditions avant ce jalon** (à ne déclencher qu'une fois remplies) :
>
> 1. tous les déploiements ont basculé sur le schedule orchestrateur ;
> 2. plus aucun schedule legacy MTF-run n'existe dans Temporal ;
> 3. les scripts **actifs** `contract_sync` / `cleanup` ont, si besoin, été
>    rebranchés sur un workflow non-legacy **avant** de retirer
>    `CronSymfonyMtfWorkersWorkflow` (ils en dépendent aujourd'hui — cf. §2).

CLEAN-002 (ce guide) ne supprime **rien** : il documente uniquement la bascule
opérationnelle. La ligne de suppression du code reste à planifier dans le
[plan de PR](python-orchestrator.md#plan-court-de-pr-atomiques).

---

## Voir aussi

- [Temporal workers](temporal.md) — flux legacy, payload `MtfJob`, schedule cible,
  garde-fous live.
- [Python orchestrator](python-orchestrator.md) — dashboards, sets, refresh
  (PY-003), payload (PY-004), runs (PY-005/PY-006), SAFE-001/002/003, OBS-001/002.
- [Exchange schedule policy](exchange-schedule-policy.md) — politique de schedules
  par exchange.
- `cron_symfony_mtf_workers/README.md` §0 (Dépréciation CLEAN-001) et §4
  (schedules disponibles).
</content>
</invoke>
