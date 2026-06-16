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
- schémas Pydantic (`OrchestratorSet`, `RunResponse`, `RunSummary`) ;
- tests pytest.

Hors-scope (PR suivantes) :

- vraie exécution parallèle + appels Symfony réels → **PY-002** ;
- persistance DB des sets et runs → **DB-001** ;
- refresh des contrats, gestion live, idempotence/locks ;
- cockpit front → **UI-001** ; branchement Temporal → **TM-001**.

## Endpoints

| Méthode | Chemin | Description |
| --- | --- | --- |
| `GET` | `/healthcheck` | État de santé du service. |
| `POST` | `/orchestrator/run` | Déclenche un run (stub PY-001). |
| `GET` | `/docs` | Swagger UI (OpenAPI). |

Réponse de `POST /orchestrator/run` :

```json
{
  "ok": true,
  "run_id": "run_20260616_120000_a1b2c3",
  "status": "success",
  "summary": { "total_calls": 2, "success": 2, "failed": 0 }
}
```

`ok=false` n'est pas un succès Temporal : le workflow/activity devra échouer
explicitement (cf. TM-002).

## Lancement local

```bash
cd python-orchestrator
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
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
`python-orchestrator` (port hôte `8099`).

```bash
docker compose build python-orchestrator
docker compose up -d python-orchestrator
curl -s http://localhost:8099/healthcheck
```

## Tests

```bash
cd python-orchestrator
python -m pytest
# ou depuis la racine :
make test-orchestrator
```

## Configuration (variables d'environnement)

| Variable | Défaut | Rôle |
| --- | --- | --- |
| `SYMFONY_BASE_URL` | `http://trading-app-nginx:80` | URL de base Symfony (PY-002+). |
| `ORCHESTRATOR_PORT` | `8099` | Port d'écoute HTTP. |
| `MAX_CONCURRENCY` | `2` | Concurrence globale bornée (PY-002+). |
