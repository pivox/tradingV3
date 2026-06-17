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
| `GET` | `/docs` | Swagger UI (OpenAPI). |

### Gestion des dashboards et sets (PY-002)

PY-002 câble la couche DB (DB-001) dans une API REST de **configuration** : on
crée des dashboards regroupant des sets « prêts ». L'exécution parallèle de ces
sets dans `/orchestrator/run` (appels Symfony réels, agrégation) reste l'objet
de **PY-005** — à ce stade `/orchestrator/run` lit toujours les sets simulés.

Garde-fous appliqués dès la création/mise à jour des sets (revalidés sur les
`PATCH` partiels, l'état résultant étant fusionné avec la ligne persistée) :

- `workers` borné à `MAX_WORKERS_PER_SET` (1 au début) → `422` au-delà ;
- **aucun live persistable** : `dry_run=false` est refusé pour tous les
  exchanges/environnements tant que la readiness live n'est pas livrée → `422` ;
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

`status` ∈ `success | partial_failure | failed | no_sets`. **Aucun set actif**
renvoie `status="no_sets"` et `ok=false` : ce n'est pas un succès Temporal
(le workflow/activity devra échouer, cf. TM-002).

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

Une valeur non entière ou hors borne lève une erreur explicite au démarrage
(pas de repli silencieux sur le défaut).

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
| `runs` | Runs déclenchés + **dernier JSON global** (`last_json`). |
| `run_sets` | Résultat par set + **dernier JSON par set** (`response_json`). |

La couche DB (`app/db/`) est **découplée** des routers/services : le câblage
applicatif (CRUD, lecture des sets au run) est l'objet de **PY-002**.

### Migrations

```bash
cd python-orchestrator
export DATABASE_URL=postgresql+psycopg://postgres:password@trading-app-db:5432/trading_app
alembic upgrade head        # crée le schéma `orchestration` + les 4 tables
alembic downgrade base      # supprime les tables (laisse le schéma)
alembic upgrade head --sql  # prévisualise le DDL sans l'appliquer
```

Le schéma `orchestration` (et la table de version Alembic qui y réside) est créé
automatiquement par `alembic/env.py` au premier `upgrade`. L'`entrypoint` du
conteneur n'exécute **pas** les migrations au boot (service expérimental derrière
le profile `orchestrator`) : les appliquer explicitement.
