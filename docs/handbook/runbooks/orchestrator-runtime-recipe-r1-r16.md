# Recette runtime orchestrateur R1-R16

Cette recette documente les fixtures et le runner reproductible de #188. Elle ne
modifie aucun comportement de production et reste en dry-run par defaut.

## Perimetre

La recette valide la chaine cible :

```text
Temporal Schedule
  -> OrchestratorCronWorkflow
  -> POST /orchestrator/run
  -> dashboards + sets persistants
  -> snapshot open-state
  -> POST Symfony /api/mtf/run
  -> runs + run_sets + audit + metriques
  -> cockpit /orchestration
```

Hors perimetre de ce lot :

- execution automatique de toute la matrice ;
- changement strategie, MTF, EntryZone, Risk/Leverage ou SL/TP ;
- activation mainnet ;
- activation live OKX ou Hyperliquid ;
- suppression du chemin legacy.

## Preconditions

Verifier avant toute recette :

- `main` contient les migrations orchestrateur Alembic ;
- `docker compose --profile orchestrator up -d` demarre l'API Python ;
- Symfony expose `GET /api/mtf/contracts`, `GET /api/exchange/open-state` et
  `POST /api/mtf/run` ;
- le worker Temporal cible est disponible dans `cron_symfony_mtf_workers` ;
- le cockpit `/orchestration` est accessible depuis le reseau de confiance ;
- les anciennes taches legacy restent pausables sans etre supprimees ;
- aucune variable live n'est necessaire.

Variables non sensibles utiles :

| Variable | Exemple | Role |
| --- | --- | --- |
| `ORCHESTRATOR_RUN_URL` | `http://python-orchestrator:8099/orchestrator/run` | URL appelee par Temporal. |
| `ORCHESTRATOR_DASHBOARD_ID` | `1` | Dashboard cible du schedule. |
| `ORCHESTRATOR_SCHEDULE_ID` | `recipe-orchestrator-r1-r16` | Identifiant du schedule Temporal de recette. |
| `ORCHESTRATOR_WORKFLOW_ID` | `recipe-orchestrator-r1-r16-runner` | Workflow cible. |
| `ORCHESTRATOR_CRON` | `*/5 * * * *` | Frequence de recette. |
| `TEMPORAL_ADDRESS` | `temporal:7233` | Endpoint Temporal interne. |
| `TEMPORAL_NAMESPACE` | `default` | Namespace Temporal. |
| `TASK_QUEUE_NAME` | `cron_symfony_mtf_workers` | Task queue du worker. |

Ne jamais consigner de cle API, signature, token, header d'authentification ou
payload signe dans les preuves.

## Fixtures

Fixtures versionnees :

- `python-orchestrator/fixtures/runtime-recipe/r1_r16_nominal_fake_dashboard.json`
- `python-orchestrator/fixtures/runtime-recipe/r1_r16_degraded_fake_dashboard.json`
- `python-orchestrator/fixtures/runtime-recipe/r1_r16_okx_dry_run_dashboard.json`
- `python-orchestrator/fixtures/runtime-recipe/r1_r16_hyperliquid_dry_run_dashboard.json`
- `python-orchestrator/fixtures/runtime-recipe/demo_exchanges_dashboard.json`

Les fixtures Fake/Paper utilisent uniquement :

- `exchange=fake` ;
- `market_type=perpetual` ;
- `environment=demo` ;
- `dry_run=true` ;
- `workers=1` ;
- profils `regular`, `scalper`, `scalper_micro`.

La fixture OKX est limitee a la recette dry-run :

- `exchange=okx` ;
- `market_type=perpetual` ;
- `environment=demo` ;
- `dry_run=true` ;
- `workers=1` ;
- symboles internes allow-listes `BTCUSDT` ;
- profils `regular` et `scalper_micro`.

Elle ne cree aucun set Bitmart et ne doit jamais servir a `dry_run=false`.
Avant d'executer R1/R2/R14 avec `--target-exchange okx`, le runner lance
`docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check okx perpetual`.
Si la sortie ne contient pas `Schedule ready: yes`, les scenarios OKX sont
exportes en `BLOCKED` et aucun appel `/orchestrator/run` n'est envoye pour
R1/R2/R14. Les autres scenarios restent sur les fixtures Fake/Paper et peuvent
continuer a produire leur preuve baseline.

La fixture Hyperliquid est limitee a la recette dry-run :

- `exchange=hyperliquid` ;
- `market_type=perpetual` ;
- `environment=testnet` ;
- `dry_run=true` ;
- `workers=1` ;
- symboles internes allow-listes `BTCUSDT` ;
- profils `regular` et `scalper_micro`.

Elle ne cree aucun set Bitmart et ne doit jamais servir a `dry_run=false`.
Avant d'executer R1/R2/R14 avec `--target-exchange hyperliquid`, le runner
lance `docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check hyperliquid perpetual`.
Si la sortie ne contient pas `Schedule ready: yes`, les scenarios Hyperliquid
sont exportes en `BLOCKED` et aucun appel `/orchestrator/run` n'est envoye pour
R1/R2/R14. Le probe R14 cree uniquement un set desactive `dry_run=false` pour
verifier le refus de persistance avant dispatch ; aucun broadcast exchange n'est
possible dans ce runner.

La fixture `demo-exchanges` prepare les dashboards/sets dedies a la recette
double exchange OKX demo + Hyperliquid testnet :

- dashboard `demo-exchanges` ;
- sets `okx_scalper_demo`, `okx_regular_demo`, `hyperliquid_scalper_testnet`,
  `hyperliquid_regular_testnet` ;
- `dry_run=true`, `workers=1`, `sync_tables=false` ;
- sets desactives par defaut ;
- symboles allow-listes `BTCUSDT` uniquement ;
- `max_notional_usdt=25` dans la politique de securite documentaire ;
- `require_stop_loss=true`, `kill_switch_enabled=true`,
  `demo_testnet_write_enabled=false` ;
- les sets existants du dashboard qui ne figurent pas dans la fixture sont
  desactives lors de la reapplication ;
- aucun set `mainnet`, aucun set Bitmart et aucun broadcast exchange.

Cette fixture sert a preparer l'environnement, pas a lancer une recette mutative.
Rollback : supprimer le dashboard `demo-exchanges` via l'API orchestrateur, ou le
desactiver si l'on souhaite conserver l'historique des runs associes.

Application idempotente attendue :

1. chercher le dashboard par `dashboard.name` ;
2. le creer s'il est absent, sinon le mettre a jour ;
3. chercher chaque set par `(dashboard_id, set_id)` ;
4. le creer s'il est absent, sinon le mettre a jour ;
5. ne jamais envoyer le champ `payload`, produit cote serveur ;
6. relire les sets et verifier l'unicite de `set_id`.

Exemple d'application manuelle du dashboard nominal :

```bash
export RECIPE_DASHBOARD_NAME=recipe-r1-r16-nominal-fake
RECIPE_DASHBOARD_ID=$(
  curl -sS http://localhost:8099/dashboards \
    | python3 -c 'import json, os, sys; name=os.environ["RECIPE_DASHBOARD_NAME"]; print(next((str(d["id"]) for d in json.load(sys.stdin) if d["name"] == name), ""))'
)

if [ -z "$RECIPE_DASHBOARD_ID" ]; then
  RECIPE_DASHBOARD_ID=$(
    curl -sS -X POST http://localhost:8099/dashboards \
      -H 'Content-Type: application/json' \
      -d '{"name":"recipe-r1-r16-nominal-fake","enabled":true,"description":"Recette runtime R1-R16 - profils regular, scalper et scalper_micro en Fake/Paper dry-run."}' \
      | python3 -c 'import json, sys; print(json.load(sys.stdin)["id"])'
  )
fi

curl -sS -X POST "http://localhost:8099/dashboards/${RECIPE_DASHBOARD_ID}/sets" \
  -H 'Content-Type: application/json' \
  -d '{
        "set_id": "recipe_fake_regular",
        "enabled": true,
        "action": "mtf_run",
        "exchange": "fake",
        "market_type": "perpetual",
        "mtf_profile": "regular",
        "environment": "demo",
        "dry_run": true,
        "workers": 1,
        "sync_tables": false,
        "symbols": ["BTCUSDT", "ETHUSDT"],
        "priority": 30
      }'
```

Pour une execution reproductible, preferer le runner du prochain lot #188. Cette
PR livre seulement les donnees et le contrat d'application.

Application de la fixture DEMO-001 :

```bash
cd python-orchestrator
python3 - <<'PY'
import json
import urllib.error
import urllib.request
from pathlib import Path

BASE_URL = "http://localhost:8099"
FIXTURE = Path("fixtures/runtime-recipe/demo_exchanges_dashboard.json")
SET_FIELDS = {
    "set_id", "enabled", "action", "exchange", "market_type", "mtf_profile",
    "environment", "dry_run", "workers", "sync_tables", "symbols",
    "contracts_limit", "priority",
}

def request(method, path, payload=None):
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        f"{BASE_URL}{path}",
        data=data,
        method=method,
        headers={"Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            body = response.read().decode("utf-8")
            return json.loads(body) if body else None
    except urllib.error.HTTPError as exc:
        raise SystemExit(f"{method} {path} failed: {exc.code} {exc.read().decode('utf-8')}")

fixture = json.loads(FIXTURE.read_text(encoding="utf-8"))
dashboard = fixture["dashboard"]
existing = next(
    (item for item in request("GET", "/dashboards") if item["name"] == dashboard["name"]),
    None,
)
if existing:
    request("PATCH", f"/dashboards/{existing['id']}", dashboard)
    dashboard_id = existing["id"]
else:
    dashboard_id = request("POST", "/dashboards", dashboard)["id"]

existing_sets = {
    item["set_id"]: item
    for item in request("GET", f"/dashboards/{dashboard_id}/sets")
}
expected_set_ids = {item["set_id"] for item in fixture["sets"]}
if fixture["expected_invariants"].get("disable_stale_sets") is True:
    for set_id, existing_set in existing_sets.items():
        if set_id not in expected_set_ids and existing_set.get("enabled") is True:
            request(
                "PATCH",
                f"/dashboards/{dashboard_id}/sets/{set_id}",
                {"enabled": False, "dry_run": True},
            )

for item in fixture["sets"]:
    payload = {key: item[key] for key in SET_FIELDS if key in item}
    if item["set_id"] in existing_sets:
        request("PATCH", f"/dashboards/{dashboard_id}/sets/{item['set_id']}", {
            key: value for key, value in payload.items() if key != "set_id"
        })
    else:
        request("POST", f"/dashboards/{dashboard_id}/sets", payload)

print(f"demo-exchanges dashboard applied with id={dashboard_id}")
PY
```

Rollback DEMO-001 :

```bash
curl -sS http://localhost:8099/dashboards \
  | python3 -c 'import json, sys; print(next((str(d["id"]) for d in json.load(sys.stdin) if d["name"] == "demo-exchanges"), ""))' \
  | xargs -r -I{} curl -sS -X DELETE "http://localhost:8099/dashboards/{}"
```

## Format des resultats

Chaque scenario doit produire une ligne :

| Champ | Valeurs | Description |
| --- | --- | --- |
| `scenario` | `R1` a `R16` | Scenario teste. |
| `status` | `PASS`, `FAIL`, `BLOCKED` | Statut de recette. |
| `run_id` | chaine | Identifiant orchestrateur si applicable. |
| `dashboard` | chaine ou id | Dashboard utilise. |
| `sets` | liste | Sets attendus et observes. |
| `evidence` | liste | Preuves minimales. |
| `notes` | texte court | Ecart ou justification. |

`BLOCKED` est obligatoire lorsqu'une dependance runtime manque. Ne pas convertir
un scenario non execute en `PASS`.

## Matrice R1-R16

| Scenario | Fixture | Procedure | Preuves attendues |
| --- | --- | --- | --- |
| R1 - Run nominal un set | nominal, `recipe_fake_regular` seul actif | Desactiver temporairement les deux autres sets actifs, puis `POST /orchestrator/run` avec `dashboard_id`. | `status=success`, un `run_set`, appel Symfony capture, cockpit dernier run coherent. |
| R2 - Run nominal multi-sets | nominal | Executer le dashboard nominal complet. | Trois sets actifs dispatches, ordre deterministe par priorite, resume exact. |
| R3 - Set desactive | nominal, `recipe_fake_disabled` | Verifier que le set desactive existe puis lancer R2. | Aucun `run_set` pour `recipe_fake_disabled`, exclusion visible dans la configuration. |
| R4 - Payload non materialise | degraded, `recipe_fake_not_materialized` | Lancer le dashboard degrade sans refresh reussi. | Aucun appel Symfony pour ce set, erreur `not_materialized`, run non marque success. |
| R5 - Erreur fonctionnelle Symfony | degraded, `recipe_fake_error_regular` | Stubber ou provoquer HTTP 400, 409, `ok=false`, JSON invalide. | Set en echec, corps utile redacted, summary coherent. |
| R6 - Symfony indisponible/timeout | degraded | Couper Symfony ou router le set vers un stub timeout/5xx. | Retry/timeout bornes, aucun succes masque, replay possible. |
| R7 - Echec partiel | degraded | Mixer un set nominal et un set en erreur. | `partial_failure`, compteurs success/failed exacts. |
| R8 - Idempotence | nominal | Rejouer le meme `idempotency_key`. | Aucun doublon `runs`/`run_sets`, replay explicite du resultat. |
| R9 - Replay explicite | nominal | Rejouer un run termine via la meme cle ou le meme tick. | Relation lisible avec le run initial, aucun effet de bord duplique. |
| R10 - Crash et reprise | degraded ou nominal | Interrompre l'orchestrateur apres claim, attendre expiration puis relancer. | Claim repris, set non perdu, pas de double execution observee. |
| R11 - Chevauchement schedules | degraded, `recipe_fake_overlap_scalper` | Declencher deux ticks proches avec meme dashboard. | Lock ou `running` in-flight visible, pas de double dispatch incompatible. |
| R12 - Contention entre profils | nominal + degraded | Cibler `BTCUSDT` depuis deux profils Fake dry-run. | Distinction lock orchestration / lock metier, aucun live. |
| R13 - Snapshot obsolete | guided, pas validable par fixture dry-run seule | Avec les fixtures dry-run, fournir un snapshot absent doit rester non bloquant; la branche fail-closed ne concerne que les sets effectivement live. Marquer `BLOCKED` tant qu'un harness local dedie ne cree pas un set `fake/demo` effectivement live sans mainnet ni exchange reel. | Preuve minimale dans ce lot : dry-run sans snapshot ne trade pas en live et ne valide pas R13. Preuve finale attendue plus tard : refus fail-closed live avant dispatch, sans fallback exchange silencieux. |
| R14 - Garde-fous live | mutation negative | Tenter OKX/Hyperliquid `dry_run=false` ou live non allowliste. | Refus avant dispatch, 422/skip audit, aucun appel metier. |
| R15 - Temporal Schedule | nominal | Creer un schedule dry-run vers le dashboard nominal. | Schedule visible, pause/reprise, `ok=false` echoue vraiment dans Temporal. |
| R16 - Rollback | nominal | Pauser le schedule cible puis verifier le chemin legacy documente. | Nouveau schedule pause, legacy non concurrent, temps de rollback mesure. |

## Runner reproductible

Le runner unique est :

```bash
cd python-orchestrator
python scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --export-dir var/runtime-recipe/latest \
  --keep-fixtures
```

Garanties du runner :

- refuse de demarrer sans `--confirm DRY_RUN_ONLY` ;
- applique les fixtures par `dashboard.name` et `(dashboard_id, set_id)` ;
- force `dry_run=true`, `workers=1` et `sync_tables=false` lors des mutations de sets ;
- n'envoie jamais le champ `payload`, produit cote serveur ;
- exporte `var/runtime-recipe/latest/runtime-recipe-report.json` ;
- produit uniquement des statuts `PASS`, `FAIL` ou `BLOCKED` ;
- redige `BLOCKED` lorsque la panne/crash/Temporal reel n'a pas ete injecte ou confirme ;
- peut desactiver les dashboards a la fin avec `--cleanup` si `--keep-fixtures` n'est pas utilise.

Scenarios lances par defaut :

```text
R1, R2, R5, R6, R8, R10, R11, R14, R15, R16
```

La cible par defaut reste Fake/Paper. Pour exercer OKX en dry-run uniquement sur
R1, R2 et R14 :

```bash
cd python-orchestrator
python scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --target-exchange okx \
  --scenario R1 \
  --scenario R2 \
  --scenario R14 \
  --export-dir var/runtime-recipe/okx-dry-run \
  --keep-fixtures
```

Preuves attendues dans `runtime-recipe-report.json` :

- `metadata.target_exchange=okx` ;
- `metadata.runtime_check.status=PASS` et `schedule_ready=yes` ;
- dashboard `recipe-r1-r16-okx-dry-run` applique ;
- sets `recipe_okx_regular` et `recipe_okx_scalper_micro` dispatches en R2 ;
- aucun set ou payload `exchange=bitmart` ;
- R14 refuse le probe `dry_run=false` avant dispatch.

Pour exercer Hyperliquid en dry-run uniquement sur R1, R2 et R14 :

```bash
cd python-orchestrator
python scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --target-exchange hyperliquid \
  --scenario R1 \
  --scenario R2 \
  --scenario R14 \
  --export-dir var/runtime-recipe/hyperliquid-dry-run \
  --keep-fixtures
```

Preuves attendues dans `runtime-recipe-report.json` :

- `metadata.target_exchange=hyperliquid` ;
- `metadata.runtime_check.status=PASS` et `schedule_ready=yes` ;
- dashboard `recipe-r1-r16-hyperliquid-dry-run` applique ;
- sets `recipe_hyperliquid_regular` et `recipe_hyperliquid_scalper_micro` dispatches en R2 ;
- aucun set ou payload `exchange=bitmart` ;
- R14 refuse le probe `dry_run=false` avant dispatch.

Pour produire le rapport DEMO-002 double exchange :

```bash
cd python-orchestrator
python scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --target-exchange demo-exchanges \
  --export-dir var/runtime-recipe/demo-exchanges \
  --keep-fixtures
```

La procedure operateur complete avant/apres cette commande est decrite dans
[Demo/Testnet Operations](demo-testnet-operations.md).

Le rapport racine `var/runtime-recipe/demo-exchanges/runtime-recipe-report.json`
contient :

- `metadata.target_exchange=demo-exchanges` ;
- `exchange_results.global` pour la baseline R1-R16 Fake/Paper ;
- `exchange_results.okx` pour OKX demo ;
- `exchange_results.hyperliquid` pour Hyperliquid testnet ;
- `exchange_summaries.*.scenario_counts` pour chaque section ;
- `metadata.runtime_checks.okx` et `metadata.runtime_checks.hyperliquid`
  avec le resultat des commandes `app:exchange:runtime-check`.

Les sections OKX et Hyperliquid automatisent uniquement les scenarios
exchange-specifiques `R1`, `R2` et `R14`. Les autres scenarios restent marques
`BLOCKED` dans ces sections et sont couverts par `exchange_results.global`.
Cette classification evite de presenter comme valide un scenario non exerce sur
l'exchange cible.

Le runner exporte aussi les sous-rapports suivants pour faciliter l'audit :

- `var/runtime-recipe/demo-exchanges/global/runtime-recipe-report.json` ;
- `var/runtime-recipe/demo-exchanges/okx/runtime-recipe-report.json` ;
- `var/runtime-recipe/demo-exchanges/hyperliquid/runtime-recipe-report.json`.

Pour cibler un sous-ensemble :

```bash
python scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --export-dir var/runtime-recipe/r1-r2 \
  --scenario R1 \
  --scenario R2 \
  --keep-fixtures
```

R15 peut executer la previsualisation Temporal si Temporal est disponible :

```bash
python scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --temporal-available \
  --temporal-dry-run-command \
    python ../cron_symfony_mtf_workers/scripts/manage_orchestrator_schedule.py create \
    --dry-run --dashboard-id '{dashboard_id}' \
    --schedule-id recipe-orchestrator-r1-r16 \
    --workflow-id recipe-orchestrator-r1-r16-runner \
    --cron '*/5 * * * *'
```

Le runner ne tue pas de conteneur et ne pause pas un schedule reel sans action
operateur. Les scenarios qui exigent ce type de controle, notamment R10 et le
rollback effectif R16, restent `BLOCKED` jusqu'a execution sur une stack de
recette dediee.

## Preuve locale Fake/Paper COMMON-005

Avant toute recette demo/testnet mutative OKX ou Hyperliquid, exercer le minimum
Fake/Paper local :

```bash
cd trading-app
php vendor/bin/phpunit tests/TradingCore/Execution/FakeExecutionPortTest.php
```

La fixture de scenarios est versionnee ici :

```text
trading-app/tests/fixtures/fake-paper/demo-recipe-scenarios.json
```

Les scenarios requis pour la protection sont :

- fill complet + stop attache avec succes ;
- fill complet + echec d'attache stop, avec fail-safe explicite ;
- fill partiel + stop partiel refuse explicitement ;
- duplicate `client_order_id`, sans creation d'un second fill ;
- restart/resync simule via snapshot memoire ;
- cancel et rejet d'ordre structures.

Cette preuve reste locale : elle ne demarre pas OKX, Hyperliquid, Temporal ou un
adapter exchange reel. Un resultat incomplet ou non protege doit rester `failed`
ou `rejected`, jamais `PASS` pour une recette mutative.

## Commandes de recette guidee

Run manuel :

```bash
curl -sS -X POST http://localhost:8099/orchestrator/run \
  -H 'Content-Type: application/json' \
  -d '{
        "dashboard_id": "'"${RECIPE_DASHBOARD_ID}"'",
        "schedule_id": "manual-r1-r16",
        "tick_timestamp": "2026-06-26T00:00:00Z",
        "idempotency_key": "recipe-r1-r16-manual-001",
        "dry_run": true
      }'
```

Schedule Temporal en previsualisation :

```bash
cd cron_symfony_mtf_workers
python scripts/manage_orchestrator_schedule.py create \
  --dry-run \
  --dashboard-id "${RECIPE_DASHBOARD_ID}" \
  --schedule-id recipe-orchestrator-r1-r16 \
  --workflow-id recipe-orchestrator-r1-r16-runner \
  --cron '*/5 * * * *'
```

Creation reelle uniquement apres validation des preconditions :

```bash
python scripts/manage_orchestrator_schedule.py create \
  --dashboard-id "${RECIPE_DASHBOARD_ID}" \
  --schedule-id recipe-orchestrator-r1-r16 \
  --workflow-id recipe-orchestrator-r1-r16-runner \
  --cron '*/5 * * * *'
```

Rollback de recette :

```bash
python scripts/manage_orchestrator_schedule.py pause \
  --schedule-id recipe-orchestrator-r1-r16

python scripts/manage_orchestrator_schedule.py status \
  --schedule-id recipe-orchestrator-r1-r16
```

Ne reprendre un schedule legacy MTF que si le schedule cible est pause et que le
runbook [migration legacy vers orchestrateur](../technical/legacy-migration.md)
est suivi.

## Nettoyage

Nettoyage logique recommande :

1. pauser le schedule de recette ;
2. exporter les preuves strictement redacted ;
3. desactiver les dashboards `recipe-r1-r16-*` ;
4. conserver les lignes `runs` et `run_sets` utiles au rapport final ;
5. supprimer uniquement les fixtures de base de recette locale si elles ne sont
   plus necessaires.

Exemple :

```bash
curl -sS -X PATCH "http://localhost:8099/dashboards/${RECIPE_DASHBOARD_ID}" \
  -H 'Content-Type: application/json' \
  -d '{"enabled":false}'
```

## Preuves minimales

Pour chaque scenario execute :

- `run_id` ;
- dashboard et sets attendus ;
- statut global ;
- compteurs `total_calls`, `success`, `failed` ;
- extrait redacted de `Run.last_json` ;
- detail `run_sets` du set fautif si applicable ;
- evenement Temporal ou statut schedule pour R15/R16 ;
- capture cockpit si utile ;
- liste explicite des scenarios `BLOCKED`.

Diagramme source : `docs/handbook/graphs/orchestrator-runtime-recipe-r1-r16.puml`.
