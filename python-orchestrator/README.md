# Python Orchestrator

API d'orchestration des appels TradingV3 (FastAPI + uvicorn).

Cette première itération correspond à **PY-001** : squelette du service,
endpoint de santé et endpoint `/orchestrator/run` **stub**. La cible
fonctionnelle complète est décrite dans
[`docs/handbook/technical/python-orchestrator.md`](../docs/handbook/technical/python-orchestrator.md).

## Rôle cible

L'API Python devient l'orchestrateur principal :

1. lit des sets de payloads déjà préparés ;
2. lance plusieurs appels Symfony en parallèle (concurrence bornée) ;
3. agrège les résultats ;
4. conserve le dernier JSON retourné ;
5. expose une visualisation fonctionnelle au front.

Temporal reste un cron basique qui appelle `/orchestrator/run`, Symfony reste
le moteur métier MTF (`/api/mtf/run`).

## Périmètre de PY-001

Inclus :

- structure de projet et service démarrable (`uvicorn app.main:app`) ;
- `GET /healthcheck` ;
- `POST /orchestrator/run` (stub) renvoyant le contrat JSON cible à partir de
  sets simulés en mémoire (dry-run, exchange `fake`) ;
- contrat d'entrée `RunRequest` (idempotence) et invariants verrouillés dès le
  squelette : enums exchange/profil/env, borne `workers`, garde-fou live
  OKX/Hyperliquid, état explicite `no_sets` ;
- schémas Pydantic (`OrchestratorSet`, `RunRequest`, `RunResponse`, `RunSummary`) ;
- tests pytest.

Hors-scope (PR suivantes) :

- vraie exécution parallèle + appels Symfony réels → **PY-002** ;
- refresh des contrats, gestion live, idempotence/locks serveur ;
- cockpit front → **UI-001** ; branchement Temporal → **TM-001**.

> **DB-001 (livré)** : le schéma de persistance (dashboards, sets, runs +
> dernier JSON) existe (cf. section *Persistance*).
>
> **PY-002 (livré)** : la **gestion** (CRUD) des dashboards et des sets est
> désormais exposée en REST (cf. *Gestion des dashboards et sets*). La lecture
> des sets persistés **au moment du run** et l'écriture des runs restent l'objet
> de **PY-005** : à ce stade `/orchestrator/run` lit encore `services/sets.py`
> (sets simulés en mémoire).

## Endpoints

| Méthode | Chemin | Description |
| --- | --- | --- |
| `GET` | `/healthcheck` | État de santé du service. |
| `POST` | `/orchestrator/run` | Déclenche un run (stub PY-001). |
| `GET` | `/dashboards` | Liste les dashboards (PY-002). |
| `POST` | `/dashboards` | Crée un dashboard (PY-002). |
| `GET` | `/dashboards/{id}` | Détail d'un dashboard (PY-002). |
| `PATCH` | `/dashboards/{id}` | Mise à jour partielle (PY-002). |
| `DELETE` | `/dashboards/{id}` | Supprime un dashboard et ses sets (PY-002). |
| `GET` | `/dashboards/{id}/sets` | Liste les sets (`?enabled_only=true`) (PY-002). |
| `POST` | `/dashboards/{id}/sets` | Crée un set (PY-002). |
| `GET` | `/dashboards/{id}/sets/{set_id}` | Détail d'un set (PY-002). |
| `PATCH` | `/dashboards/{id}/sets/{set_id}` | Mise à jour partielle d'un set (PY-002). |
| `DELETE` | `/dashboards/{id}/sets/{set_id}` | Supprime un set (PY-002). |
| `GET` | `/dashboards/{id}/runs` | Liste les runs d'un dashboard (`?limit=&offset=`) (PY-006). |
| `GET` | `/dashboards/{id}/runs/latest` | Dernier run d'un dashboard : JSON global + par set (PY-006). |
| `GET` | `/runs` | Liste les runs (`?dashboard_id=&limit=&offset=`) (PY-006). |
| `GET` | `/runs/{run_id}` | Dernier JSON global d'un run + détail par set (PY-006). |
| `GET` | `/runs/{run_id}/sets/{set_id}` | Dernier JSON d'un set (payload + réponse brute) (PY-006). |
| `GET` | `/metrics` | Métriques d'exécution agrégées (compteurs + histogramme de durée), JSON dérivé du registre (OBS-002). |
| `GET` | `/docs` | Swagger UI (OpenAPI). |

### Gestion des dashboards et sets (PY-002)

PY-002 câble la couche DB (DB-001) dans une API REST de **configuration** : on
crée des dashboards regroupant des sets « prêts ». L'exécution parallèle de ces
sets dans `/orchestrator/run` (appels Symfony réels, agrégation) reste l'objet
de **PY-005** — à ce stade `/orchestrator/run` lit toujours les sets simulés.

Garde-fous appliqués dès la création/mise à jour des sets (revalidés sur les
`PATCH` partiels, l'état résultant étant fusionné avec la ligne persistée) :

- `workers` borné à `MAX_WORKERS_PER_SET` (1 au début) → `422` au-delà ;
- **aucun live persistable par défaut** : `dry_run=false` est refusé tant que
  l'interrupteur d'activation live est OFF (config livrée) → `422`. La décision
  délègue à la couche unique `app/services/live_guard.py` (`assess_live`), mêmes
  gardes que le runner — une ligne stockée ne peut déclencher que ce que le runner
  exécuterait. Cf. *Garde-fous live (SAFE-003)* ;
- **sélection exploitable obligatoire** : un set doit avoir `symbols` non vide
  **ou** `contracts_limit` renseigné (pas de set ambigu) → `422` ;
- **`payload` non writable** : produit côté serveur (PY-004), exposé en lecture
  seule ; un `payload` envoyé par un client est ignoré ;
- un `null` explicite sur un champ NOT NULL d'un `PATCH` (dashboard ou set) →
  `422` (seules les colonnes nullables `description` / `contracts_limit` sont effaçables) ;
- `set_id` unique par dashboard, `name` de dashboard unique → `409 Conflict` ;
- `set_id` immuable (renommer = supprimer puis recréer).

Exemple :

```bash
# 1. créer un dashboard
curl -s -X POST localhost:8099/dashboards \
  -H 'Content-Type: application/json' \
  -d '{"name":"cockpit","description":"sets de prod"}'

# 2. y attacher un set prêt (dry-run, sélection explicite de symboles)
curl -s -X POST localhost:8099/dashboards/1/sets \
  -H 'Content-Type: application/json' \
  -d '{"set_id":"bitmart_regular_top","exchange":"bitmart","mtf_profile":"regular",
       "symbols":["BTCUSDT","ETHUSDT"],"sync_tables":false,"priority":10}'

# 3. lister les sets actifs
curl -s 'localhost:8099/dashboards/1/sets?enabled_only=true'
```

### `POST /orchestrator/run`

Body optionnel (`RunRequest`) — sert l'idempotence et la traçabilité du tick :

```json
{
  "dashboard_id": "dashA",
  "schedule_id": "cron-orchestrator-1m",
  "tick_timestamp": "2026-06-17T08:30:00Z",
  "idempotency_key": "optional-stable-key",
  "dry_run": true
}
```

Le `run_id` est **dérivé de façon stable** quand un contexte est fourni :

- `idempotency_key` présent → `run_<idempotency_key>` ;
- sinon `dashboard_id` + `tick_timestamp` → `run_<dashboard_id>_<tickUTC>` ;
- sinon (aucun contexte) → identifiant aléatoire non idempotent.

Réponse :

```json
{
  "ok": true,
  "run_id": "run_dashA_20260617T083000Z",
  "status": "success",
  "summary": { "total_calls": 2, "success": 2, "failed": 0 }
}
```

`status` ∈ `success | partial_failure | failed | no_sets | running`. **Aucun set
actif** renvoie `status="no_sets"` et `ok=false` : ce n'est pas un succès Temporal
(le workflow/activity devra échouer, cf. TM-002). `status="running"` est renvoyé
(avec `ok=false`) quand un run idempotent identique est **déjà en vol** (cf.
*Idempotence runs/sets (SAFE-002)*).

### Idempotence runs/sets (SAFE-002)

Quand un **ancrage d'idempotence** existe (`idempotency_key`, ou `dashboard_id` +
`tick_timestamp` → `run_id` stable), `POST /orchestrator/run` est idempotent **à
l'exécution** : un run rejoué ne relance pas les appels Symfony.

- **Claim précoce** : avant le dispatch (transaction courte committée, jamais tenue
  pendant les ~900s), la ligne `Run` est posée en `status="running"` avec
  `started_at` et `expires_at` (**TTL de claim**).
- **Court-circuit** selon l'état du run existant (résolu par `run_id` puis
  `idempotency_key`) :
  - terminal `success` → **replay** : `run_id`/`summary` reconstruits depuis
    `last_json`, aucun ré-appel Symfony ;
  - terminal `failed`/`partial_failure` → **reprise** : seuls les sets sans
    `RunSet.ok=true` sont re-dispatchés ; les RunSet réussis sont conservés ;
  - `running` non périmé → **en vol** : pas de dispatch, réponse `ok=false`,
    `status="running"` ;
  - `running` périmé (TTL, process tué) → **reclaim** + ré-exécution.
- Un `run_id` **aléatoire** (aucun contexte) reste **non idempotent** (inchangé) ;
  `no_sets` reste **non persisté**.
- Le **live reste désactivé** (tout set `dry_run=false` est skippé) et les locks
  SAFE-001 sont inchangés. Le **TTL de claim réutilise** le calcul SAFE-001 (pire
  temps de paroi du run + marge `ORCHESTRATION_LOCK_TTL_SECONDS`) : **pas de nouvelle
  variable d'environnement**.

### Historique des runs en lecture (PY-006)

`POST /orchestrator/run` persiste le « dernier JSON » (un `Run` global +
un `RunSet` par set, cf. *Persistance*). PY-006 l'expose en **lecture seule** :

```bash
# Liste des derniers runs (toutes origines, vue allégée sans last_json)
curl -s 'localhost:8099/runs?limit=20'

# Runs d'un dashboard, du plus récent au plus ancien
curl -s 'localhost:8099/dashboards/1/runs'

# Dernier run d'un dashboard : JSON global + détail par set (404 si aucun run)
curl -s 'localhost:8099/dashboards/1/runs/latest'

# Détail complet d'un run : last_json global + sets[] (payload + réponse brute)
curl -s 'localhost:8099/runs/run_dashA_20260617T083000Z'

# Dernier JSON d'un set précis (payload envoyé + réponse Symfony brute)
curl -s 'localhost:8099/runs/run_dashA_20260617T083000Z/sets/setA'
```

`GET /runs` et `GET /dashboards/{id}/runs` renvoient une vue **allégée**
(`RunSummaryRead`, sans `last_json` ni détail par set), triée du plus récent au
plus ancien et paginée (`limit` borné à 100, `offset`). `GET /runs/{run_id}` et
`/dashboards/{id}/runs/latest` renvoient le détail complet (`RunDetailRead` :
`last_json` global + `sets[]` avec `payload_sent`, `response_json`, `error`,
`duration_ms`). Ces endpoints n'écrivent rien : la persistance est faite par
PY-005.

## Lancement local

```bash
cd python-orchestrator
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt          # runtime
uvicorn app.main:app --host 0.0.0.0 --port 8099
```

Vérifications :

```bash
curl -s http://localhost:8099/healthcheck
curl -s -X POST http://localhost:8099/orchestrator/run
# Swagger : http://localhost:8099/docs
```

## Docker

Le service est défini dans le `docker-compose.yml` racine sous le nom
`python-orchestrator`, **derrière le profile `orchestrator`** (il ne démarre
donc pas avec un `docker compose up` standard) et **sans port hôte par défaut**
(`expose` uniquement — bridge interne sans auth pour l'instant).

```bash
docker compose --profile orchestrator build python-orchestrator
docker compose --profile orchestrator up -d python-orchestrator
# Healthcheck Docker intégré (GET /healthcheck via stdlib Python).
```

Pour un accès dev depuis l'hôte, publier le port via un
`docker-compose.override.yml` ou `docker compose run --service-ports python-orchestrator`.

Le conteneur tourne en utilisateur non-root et n'embarque ni `build-essential`,
ni les dépendances de test.

## Tests

```bash
cd python-orchestrator
pip install -r requirements-dev.txt
python -m pytest
```

Les tests DB tournent sur **SQLite in-memory** (aucun Postgres requis) en attachant
le schéma `orchestration`. Un **smoke test PostgreSQL** (`tests/test_db_postgres_smoke.py`)
valide en plus le vrai contrat Alembic (upgrade/downgrade, isolation `public`,
absence de drift ORM↔migration) ; il est **ignoré** tant que
`ORCHESTRATOR_TEST_DATABASE_URL` n'est pas défini :

```bash
export ORCHESTRATOR_TEST_DATABASE_URL=postgresql+psycopg://postgres:postgres@127.0.0.1:5432/orchestrator_test
python -m pytest
```

La CI (`.github/workflows/python-orchestrator.yml`) l'exécute automatiquement avec
un service `postgres:15`.

## Dépendances

- `requirements.txt` : runtime uniquement, versions **épinglées** (reproductibilité).
- `requirements-dev.txt` : runtime + `pytest` (non installé dans l'image).

## Configuration (variables d'environnement)

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `SYMFONY_BASE_URL` | `http://trading-app-nginx:80` | URL de base Symfony (PY-002+). |
| `ORCHESTRATOR_PORT` | `8099` | Port d'écoute HTTP (1..65535). |
| `MAX_CONCURRENCY` | `2` | Concurrence globale bornée, ≥ 1 (PY-002+). |
| `DATABASE_URL` | `postgresql+psycopg://postgres:password@trading-app-db:5432/trading_app` | URL SQLAlchemy de la base orchestration (DB-001). |
| `ORCHESTRATION_DB_SCHEMA` | `orchestration` | Schéma PostgreSQL dédié (DB-001). Identifiant SQL simple validé (`^[A-Za-z_][A-Za-z0-9_]*$`). |
| `ORCHESTRATION_LOCK_TTL_SECONDS` | `1800` | Marge (s) anti-deadlock des locks d'orchestration par `(profil, symbole)` (SAFE-001), ≥ 1. Le TTL effectif = pire temps de paroi du run (vagues `max_concurrency` × timeout Symfony) + cette marge, pour qu'un set en file n'expire pas avant son dispatch. **Borne aussi le TTL de claim de run** (SAFE-002, même calcul) : pas de variable dédiée. |
| `ORCHESTRATION_LIVE_ENABLED` | `false` | **Interrupteur d'activation live** (SAFE-003), défaut **OFF**. OFF ⇒ tout set `dry_run=false` est skippé fail-closed (comportement d'avant SAFE-003). Accepte `true/false`, `1/0`, `yes/no`, `on/off` ; toute autre valeur lève au démarrage. **Ne jamais livrer à `true` sans readiness runtime.** |
| `ORCHESTRATION_LIVE_EXCHANGES` | *(vide)* | Allow-list CSV des exchanges autorisés live **quand l'interrupteur est ON** (SAFE-003). Normalisée en minuscules, dédupliquée ; chaque entrée doit être un exchange connu (sinon lève au démarrage). En pratique au plus `bitmart` (+ `fake` en simulation). OKX/Hyperliquid restent interdits **même listés** (bannissement permanent). |
| `ORCHESTRATION_LOG_LEVEL` | `INFO` | **Niveau du log d'audit des runs** (OBS-001) sur le logger `orchestrator.audit`. Accepte `DEBUG/INFO/WARNING/ERROR/CRITICAL` (insensible à la casse) ; toute autre valeur lève au démarrage (comme `ORCHESTRATION_LOCK_TTL_SECONDS`). Cf. *Observabilité / Audit (OBS-001)*. |
| `ORCHESTRATION_METRICS_ENABLED` | `true` | **Collecte des métriques d'exécution par set** (OBS-002), défaut **ON**. Accepte `true/false`, `1/0`, `yes/no`, `on/off` ; toute autre valeur lève au démarrage. À OFF, les compteurs/histogramme ne sont plus alimentés et `GET /metrics` renvoie un snapshot vide. Cf. *Métriques d'exécution (OBS-002)*. |

Une valeur non entière, non booléenne, hors borne, un niveau de log inconnu ou un
exchange inconnu lève une erreur explicite au démarrage (pas de repli silencieux
sur le défaut).

## Observabilité / Audit (OBS-001)

`POST /orchestrator/run` émet, **au fil du cycle**, une **piste d'audit
structurée, fail-safe et corrélée par `run_id`** sur le logger nommé
`orchestrator.audit`. Sink **logs JSON line sur stdout** (aucune migration,
container/aggregator-friendly) ; le niveau est piloté par `ORCHESTRATION_LOG_LEVEL`
(défaut `INFO`). L'audit **complète** l'historique DB (`runs.last_json` / `run_sets`,
PY-005/PY-006) — il ne le remplace pas et n'en change pas le contenu ; les
`code`/`reason` audités sont **identiques** à ceux versés dans `RunSet.error`
(source unique SAFE-003).

Événements (clé `event`) :

| `event` | Émis quand | Champs notables |
| --- | --- | --- |
| `run_started` | entrée du run | `dashboard_id`, `has_anchor` |
| `run_short_circuit` | court-circuit SAFE-002 | `reason` = `replay` / `in_flight` / `resume` / `reclaim` |
| `snapshot_fetch` | fetch d'état ouvert 1×/(exchange, market_type) | `exchange`, `market_type`, `ok` (+ `code` si indisponible) |
| `set_skipped` | set non dispatché (fail-closed) | `set_id`, `code` = `live_not_enabled` / `live_forbidden_exchange` / `live_exchange_not_allowlisted` / `open_state_unavailable` / `locked` / `conflicting_live` |
| `set_dispatched` | appel Symfony effectif | `set_id`, `dry_run` |
| `set_result` | issue de l'appel Symfony | `set_id`, `ok`, `business_status`, `duration_ms` |
| `run_finished` | clôture (y compris `no_sets`) | `status`, `total_calls`, `success`, `failed` |

Exemple de ligne (stdout) :

```json
{"timestamp": "2026-06-21T08:30:00.123456+00:00", "level": "INFO", "event": "set_result", "run_id": "run_dashA_20260617T083000Z", "set_id": "bitmart_regular_top", "ok": true, "business_status": "success", "duration_ms": 842}
```

**Corrélation Symfony (trace-id)** : le `run_id` réellement persisté est propagé
en en-tête HTTP **`X-Run-Id`** sur `POST /api/mtf/run`, pour relier les logs
Symfony au run d'orchestration.

**Fail-safe** : une erreur d'audit (sérialisation, handler) ne fait jamais
échouer ni ralentir un run — l'émission est encapsulée dans un `try/except`
interne et ne fait aucune I/O bloquante hors `logging` stdlib. **Aucun changement
de comportement métier** : pas de nouvelle décision, locks SAFE-001 / idempotence
SAFE-002 / garde-fous live SAFE-003 intacts, réponses HTTP inchangées.

## Métriques d'exécution (OBS-002)

OBS-001 donne un **flux d'événements** ; OBS-002 ajoute la **couche quantitative**
prête à grapher/alerter. Un **registre de métriques in-process**
(`app/services/run_metrics.py`) est alimenté aux **mêmes points** que l'audit
(`set_dispatched` / `set_result` / `set_skipped` / `snapshot_fetch` /
`run_finished`) et exposé en **JSON dérivé** via `GET /metrics`
(`app/routers/metrics.py`) — aucune migration, aucune écriture DB.

| Métrique | Type | Labels |
| --- | --- | --- |
| `runs` | compteur | `status` (y compris `no_sets`) |
| `sets.dispatched` | compteur | `exchange`, `market_type`, `mtf_profile` |
| `sets.results` | compteur | `exchange`, `market_type`, `mtf_profile`, `ok`, `business_status` |
| `sets.skipped` | compteur | `code` (stable OBS-001/SAFE-003), `exchange`, `market_type`, `mtf_profile` |
| `snapshots` | compteur | `exchange`, `market_type`, `ok` |
| `dispatch_duration_ms` | histogramme | `exchange`, `market_type`, `mtf_profile` (bornes ms cumulées « le » + `+Inf` + somme) |

**Cardinalité bornée** (décision produit OBS-002) : ni `set_id` ni `dashboard_id`
en labels. **Réconciliation** : sur un run nominal (sans skip ni reprise),
`sets.dispatched` = `summary.total_calls`, `sets.results` (ok=true/false) =
`summary.success`/`summary.failed`.

Exemple :

```bash
curl -s http://localhost:8099/metrics | jq .
```

```json
{
  "enabled": true,
  "runs": [{"status": "success", "value": 12}],
  "sets": {
    "dispatched": [{"exchange": "bitmart", "market_type": "perpetual", "mtf_profile": "scalper_micro", "value": 34}],
    "results": [{"exchange": "bitmart", "market_type": "perpetual", "mtf_profile": "scalper_micro", "ok": "true", "business_status": "success", "value": 31}],
    "skipped": [{"code": "locked", "exchange": "bitmart", "market_type": "perpetual", "mtf_profile": "scalper_micro", "value": 2}]
  },
  "snapshots": [{"exchange": "bitmart", "market_type": "perpetual", "ok": "true", "value": 12}],
  "dispatch_duration_ms": {"buckets": [100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000, 120000, 300000, 600000, 900000],
    "series": [{"exchange": "bitmart", "market_type": "perpetual", "mtf_profile": "scalper_micro", "count": 31, "sum_ms": 26102, "buckets": {"100": 0, "250": 4, "500": 18, "...": "...", "+Inf": 31}}]}
}
```

**Fail-safe** : une erreur de métrique (état corrompu, sérialisation) est absorbée
(`try/except` interne) et ne fait jamais échouer ni ralentir un run. Désactivable
via `ORCHESTRATION_METRICS_ENABLED=false`. **Aucun changement de comportement** :
dispatch, décisions live/lock/idempotence, réponses HTTP et forme de
`last_json`/`RunSet` inchangés ; OBS-001 et SAFE-001/002/003 intacts.

## Persistance (DB-001)

L'orchestrateur persiste sa configuration et ses résultats dans PostgreSQL via
**SQLAlchemy 2.0 + Alembic** (driver `psycopg` sync). Pour ne pas interférer
avec les migrations Doctrine de Symfony, tout vit dans un **schéma PostgreSQL
dédié `orchestration`** au sein de la base `trading_app` existante (Symfony
n'introspecte que `public`).

Tables (`app/db/models.py`) :

| Table | Rôle |
| --- | --- |
| `dashboards` | Configurations d'orchestration (nom, statut). |
| `orchestration_sets` | Sets prêts à exécuter (miroir d'`OrchestratorSet` + `payload` préparé). |
| `runs` | Runs déclenchés + **dernier JSON global** (`last_json`). Statut `running` (claim « en vol ») et `expires_at` (TTL de claim) ajoutés par **SAFE-002**. |
| `run_sets` | Résultat par set + **dernier JSON par set** (`response_json`). |
| `orchestration_locks` | Locks par `(mtf_profile, exchange, market_type, symbol)` sérialisant deux runs concurrents (**SAFE-001**). |

La couche DB (`app/db/`) est **découplée** des routers/services : le câblage
applicatif (CRUD, lecture des sets au run) est l'objet de **PY-002**.

### Lock per-symbole/profil (SAFE-001)

`POST /orchestrator/run` acquiert, **avant le dispatch de chaque set**, un lock
persistant (`orchestration_locks`) par symbole — clé canonique
`{profile}|{exchange}|{market_type}|{symbol}`, l'**unicité de `lock_key` réalisant
l'exclusion mutuelle**. Deux runs concurrents (overlap du cron Temporal, ou front +
cron) ne peuvent donc pas traiter le même couple `(profil, symbole)` en même temps.

- **Acquisition tout ou rien par set**, dans une transaction **courte committée
  avant** les appels Symfony (jamais maintenue pendant les ~900s). Un set dont un
  symbole est déjà verrouillé par un run actif est **skippé fail-closed**
  (`ok=false`, `locked: <key> held by run <id>`) ; les autres sets continuent.
- **Reclaim des locks expirés** + purge au démarrage : pas de deadlock si un process
  est tué avant la libération. Le TTL effectif couvre le pire temps de paroi du run
  (vagues `max_concurrency` × timeout Symfony) + la marge `ORCHESTRATION_LOCK_TTL_SECONDS`,
  donc un set resté en file derrière le sémaphore n'expire jamais avant son dispatch.
- **Libération** dans le `finally` de chaque set (succès/échec/exception).
- Appliqué à **tous** les sets `mtf_run` (inoffensif en dry-run) ; **le live reste
  désactivé** (SAFE-001 ne relâche pas le skip « live execution not yet enabled »).
  L'idempotence **à l'exécution** des runs/sets est livrée par **SAFE-002** (cf.
  *Idempotence runs/sets (SAFE-002)* plus haut).

La migration Alembic `0002_orchestration_locks` crée la table ; elle s'applique via
`alembic upgrade head` (cf. *Migrations*). La migration `0003_run_claim_expires_at`
ajoute la colonne `runs.expires_at` (TTL du claim « en vol », **SAFE-002**).

### Garde-fous live (SAFE-003)

Toute la politique « ce set peut-il s'exécuter en live ? » est centralisée dans
**un seul module fail-closed**, `app/services/live_guard.py`, exposant
`assess_live(exchange, market_type, environment, dry_run, settings) -> LiveDecision`.
La persistance (`schemas.assert_set_persistable`), les sets en mémoire
(`schemas.assert_live_allowed`) et le runner (`_dispatch_set`) **délèguent** tous à
cette source unique : plus de logique live dupliquée entre `schemas` et le runner.

Hiérarchie de décision (fail-closed, l'ordre est sécuritaire) :

1. `dry_run` effectif ⇒ autorisé (l'override run-level `{"dry_run": true}` force
   toujours le dry, quelle que soit la suite) ;
2. **bannissement permanent** OKX/Hyperliquid ⇒ refusé, **même interrupteur ON et
   même exchange allow-listé** (normalisation casse/espaces incluse) ;
3. interrupteur `ORCHESTRATION_LIVE_ENABLED` **OFF** (défaut) ⇒ refusé
   (`live_not_enabled`) — comportement identique à avant SAFE-003 ;
4. exchange hors allow-list `ORCHESTRATION_LIVE_EXCHANGES` (défaut vide) ⇒ refusé
   (`live_exchange_not_allowlisted`) ;
5. sinon ⇒ autorisé, **mais** le runner exige encore le snapshot d'état ouvert
   (sinon skip `open_state_unavailable`).

Chaque refus porte un `reason`/`code` stable versé dans `RunSet.error` / `last_json`.
**Le live reste désactivé par défaut** : la config livrée garde l'interrupteur OFF
et l'allow-list vide. SAFE-003 rend l'activation *possible, explicite, auditable et
testée*, sans la réactiver, et **n'ajoute aucune migration**.

### Migrations

```bash
cd python-orchestrator
export DATABASE_URL=postgresql+psycopg://postgres:password@trading-app-db:5432/trading_app
alembic upgrade head        # crée le schéma `orchestration` + les 5 tables
alembic downgrade base      # supprime les tables (laisse le schéma)
alembic upgrade head --sql  # prévisualise le DDL sans l'appliquer
```

Le schéma `orchestration` (et la table de version Alembic qui y réside) est créé
automatiquement par `alembic/env.py` au premier `upgrade`. L'`entrypoint` du
conteneur n'exécute **pas** les migrations au boot (service expérimental derrière
le profile `orchestrator`) : les appliquer explicitement.
