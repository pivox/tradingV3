# Demo/Testnet Operations Runbook

## Objectif

Ce runbook decrit l'exploitation controlee OKX demo + Hyperliquid testnet pour
les recettes demo/testnet TradingV3.

Il permet a un operateur de preparer, verifier, observer, arreter et rollbacker
l'environnement sans lire le code. Il ne rend pas les ordres mutatifs
disponibles. Tant que les PRs d'activation dediees ne sont pas livrees,
`dry_run=false` doit rester bloque.

## Non-objectifs

- Aucun ordre mainnet.
- Aucun fonds reel.
- Aucun secret mainnet.
- Aucun changement strategie, EntryZone, Risk/Leverage, SL/TP metier.
- Aucun fallback Bitmart pour `exchange=okx` ou `exchange=hyperliquid`.
- Aucun resultat PnL certifie si les donnees restent incompletes.

## Prerequis

Avant toute recette :

1. `main` deployee sur la stack cible.
2. Containers PHP, orchestrateur Python, PostgreSQL et workers demarres.
3. `mainnet_write_enabled=false` dans la config effective.
4. `trading.execution.kill_switch_enabled=true` avant preparation.
5. `DEMO_TRADING_ENABLED=0` avant toute activation mutative.
6. Fixtures `demo-exchanges` appliquees ou applicables.
7. Runtime-check OKX et Hyperliquid disponibles.
8. Journalisation demo/testnet operationnelle et redacted.

Commandes de base :

```bash
docker-compose ps
docker-compose logs --tail=100 trading-app-php
docker-compose logs --tail=100 trading-app-messenger-trading
curl -sS http://localhost:8099/healthcheck
```

## Variables d'environnement

Variables de gate :

| Variable | Valeur preparatoire | Valeur rollback |
|---|---:|---:|
| `DEMO_TRADING_ENABLED` | `0` | `0` |
| `OKX_DEMO_TRADING_ENABLED` | `0` | `0` |
| `HYPERLIQUID_TESTNET_TRADING_ENABLED` | `0` | `0` |

Variables OKX demo attendues lorsque la lecture privee demo est testee :

| Variable | Regle |
|---|---|
| `OKX_ENV` | `demo` uniquement. |
| `OKX_DEMO_API_KEY` | Cle demo dediee, jamais mainnet. |
| `OKX_DEMO_API_SECRET` | Secret demo dedie, jamais logge. |
| `OKX_DEMO_API_PASSPHRASE` | Passphrase demo dediee, jamais loggee. |
| `OKX_SIMULATED_TRADING` | `1`, pour le header demo `x-simulated-trading`. |

Variables Hyperliquid testnet attendues lorsque la lecture privee testnet est
testee :

| Variable | Regle |
|---|---|
| `HYPERLIQUID_ENV` | `testnet` uniquement. |
| `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` | Adresse compte testnet. |
| `HYPERLIQUID_TESTNET_AGENT_ADDRESS` | Agent API testnet dedie. |
| `HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY` | Cle agent testnet dediee, jamais wallet principal. |

Ne jamais afficher ces valeurs dans un ticket, log, screenshot ou rapport.
Verifier seulement la presence des variables :

```bash
docker-compose exec -T trading-app-php php -r '
foreach ([
  "OKX_ENV",
  "OKX_DEMO_API_KEY",
  "OKX_DEMO_API_SECRET",
  "OKX_DEMO_API_PASSPHRASE",
  "OKX_SIMULATED_TRADING",
  "HYPERLIQUID_ENV",
  "HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS",
  "HYPERLIQUID_TESTNET_AGENT_ADDRESS",
  "HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY",
] as $name) {
  $value = getenv($name);
  $status = $value === false || trim((string) $value) === "" ? "missing" : "present";
  echo $name . "=" . $status . PHP_EOL;
}
'
```

## Politique de securite

Les limites minimales pour la recette sont :

| Champ | Valeur attendue |
|---|---|
| Exchanges | `okx/demo/perpetual`, `hyperliquid/testnet/perpetual`. |
| Symboles | `BTCUSDT` uniquement au depart. |
| Marches | `perpetual` uniquement. |
| Max notional | Minimal, documente par fixture ou config effective. |
| Stop loss | Obligatoire avant toute future mutation demo/testnet. |
| Kill switch | Actif par defaut. |
| Mode | `dry_run=true` pour DEMO-003. |

Tout ecart doit bloquer la recette ou etre marque `BLOCKED`, jamais force en
`PASS`.

## Preparation

Appliquer la fixture de preparation :

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

Verifier que les sets sont presents et desactives :

```bash
dashboard_id="$(
  curl -sS http://localhost:8099/dashboards \
    | python3 -c 'import json, sys; print(next((str(d["id"]) for d in json.load(sys.stdin) if d["name"] == "demo-exchanges"), ""))'
)"

test -n "$dashboard_id"
curl -sS "http://localhost:8099/dashboards/${dashboard_id}/sets" \
  | python3 -m json.tool
```

## Runtime-check

OKX demo :

```bash
docker compose exec -T trading-app-php \
  php bin/console app:exchange:runtime-check okx perpetual
```

Hyperliquid testnet :

```bash
docker compose exec -T trading-app-php \
  php bin/console app:exchange:runtime-check hyperliquid perpetual
```

Le resultat doit etre conserve tel quel dans les preuves redacted. Si
`Schedule ready: yes` n'apparait pas, la section exchange de la recette reste
`BLOCKED`.

## Schedule demo/testnet

Le schedule DEMO-004 utilise `cron_symfony_mtf_workers/scripts/manage_demo_testnet_schedule.py`.
Il cible `OrchestratorCronWorkflow`, force le `RunRequest` a `dry_run=true` et
cree le schedule en pause par defaut. Aucun schedule mainnet n'est fourni par ce
script.

Recuperer l'id du dashboard `demo-exchanges` :

```bash
dashboard_id="$(
  curl -sS http://localhost:8099/dashboards \
    | python3 -c 'import json, sys; print(next((str(d["id"]) for d in json.load(sys.stdin) if d["name"] == "demo-exchanges"), ""))'
)"
test -n "$dashboard_id"
```

Previsualiser sans connexion Temporal :

```bash
cd cron_symfony_mtf_workers
python scripts/manage_demo_testnet_schedule.py create \
  --dry-run \
  --dashboard-id "$dashboard_id"
```

Creer le schedule paused :

```bash
python scripts/manage_demo_testnet_schedule.py create \
  --dashboard-id "$dashboard_id"
python scripts/manage_demo_testnet_schedule.py status
```

Activer seulement apres runtime-check OKX + Hyperliquid `Schedule ready: yes` :

```bash
python scripts/manage_demo_testnet_schedule.py resume \
  --dashboard-id "$dashboard_id"
```

Rollback schedule :

```bash
python scripts/manage_demo_testnet_schedule.py pause
python scripts/manage_demo_testnet_schedule.py delete
```

## Recette dry-run

Commande DEMO-002/DEMO-003 :

```bash
cd python-orchestrator
python scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --target-exchange demo-exchanges \
  --export-dir var/runtime-recipe/demo-exchanges \
  --keep-fixtures
```

Preuves a conserver :

- `var/runtime-recipe/demo-exchanges/runtime-recipe-report.json` ;
- `var/runtime-recipe/demo-exchanges/global/runtime-recipe-report.json` ;
- `var/runtime-recipe/demo-exchanges/okx/runtime-recipe-report.json` ;
- `var/runtime-recipe/demo-exchanges/hyperliquid/runtime-recipe-report.json` ;
- sorties runtime-check redacted ;
- extrait des logs orchestrateur/PHP sans payload sensible.

## Observation

Pendant la recette :

```bash
docker-compose logs -f trading-app-php
docker-compose logs -f trading-app-messenger-trading
docker-compose logs -f trading-app-messenger-order-timeout
```

Signaux attendus :

- aucun appel mainnet ;
- aucun set Bitmart dans `demo-exchanges` ;
- `dry_run=true` dans les appels orchestrateur ;
- R14 refuse les probes `dry_run=false` avant dispatch ;
- erreurs classees `PASS`, `FAIL` ou `BLOCKED`, jamais masquees.

## Arret

Arret non destructif :

```bash
for dashboard_name in \
  demo-exchanges \
  recipe-r1-r16-nominal-fake \
  recipe-r1-r16-okx-dry-run \
  recipe-r1-r16-hyperliquid-dry-run
do
  dashboard_id="$(
    curl -sS http://localhost:8099/dashboards \
      | DASHBOARD_NAME="$dashboard_name" python3 -c 'import json, os, sys; name=os.environ["DASHBOARD_NAME"]; print(next((str(d["id"]) for d in json.load(sys.stdin) if d["name"] == name), ""))'
  )"
  test -z "$dashboard_id" && continue
  curl -sS -X PATCH "http://localhost:8099/dashboards/${dashboard_id}" \
      -H 'Content-Type: application/json' \
      -d '{"enabled": false}'
done
```

Les runs historiques restent consultables.

## Rollback

Rollback immediat :

```bash
# Verifier d'abord la configuration effectivement injectee aux services.
docker-compose config \
  | rg 'DEMO_TRADING_ENABLED|OKX_DEMO_TRADING_ENABLED|HYPERLIQUID_TESTNET_TRADING_ENABLED' \
  || true
```

Si ces variables sont deja injectees par Compose, modifier la source qui les
alimente (`env_file`, secret manager, fichier de deploiement ou pipeline), puis
recreer les services ci-dessous. Ne pas supposer que modifier `.env` suffit :
dans ce repository, `.env` sert a interpoler `docker-compose.yml`, mais une
variable absente du bloc `environment` d'un service n'est pas automatiquement
transmise au container.

Si la source de deploiement n'expose pas encore ces gates, appliquer un override
Compose temporaire pour forcer les trois ecritures demo/testnet a `0` :

```bash
cat > docker-compose.demo-rollback.override.yml <<'YAML'
services:
  trading-app-php:
    environment:
      DEMO_TRADING_ENABLED: "0"
      OKX_DEMO_TRADING_ENABLED: "0"
      HYPERLIQUID_TESTNET_TRADING_ENABLED: "0"
  trading-app-messenger-trading:
    environment:
      DEMO_TRADING_ENABLED: "0"
      OKX_DEMO_TRADING_ENABLED: "0"
      HYPERLIQUID_TESTNET_TRADING_ENABLED: "0"
  trading-app-messenger-order-timeout:
    environment:
      DEMO_TRADING_ENABLED: "0"
      OKX_DEMO_TRADING_ENABLED: "0"
      HYPERLIQUID_TESTNET_TRADING_ENABLED: "0"
YAML

# Recreer les containers qui lisent ces variables. Un simple restart ne suffit
# pas a reinjecter une variable d'environnement Compose modifiee.
docker-compose \
  -f docker-compose.yml \
  -f docker-compose.demo-rollback.override.yml \
  up -d --force-recreate \
  trading-app-php \
  trading-app-messenger-trading \
  trading-app-messenger-order-timeout

docker-compose \
  -f docker-compose.yml \
  -f docker-compose.demo-rollback.override.yml \
  config \
  | rg 'DEMO_TRADING_ENABLED|OKX_DEMO_TRADING_ENABLED|HYPERLIQUID_TESTNET_TRADING_ENABLED'
```

La sortie finale doit afficher les trois variables avec la valeur `0` pour les
trois services recrees.

Ne pas se contenter de variables `export` dans le shell operateur : elles ne
modifient pas l'environnement des containers deja crees.

Rollback config :

```yaml
trading:
  execution:
    mainnet_write_enabled: false
    demo_testnet_write_enabled: false
    kill_switch_enabled: true
```

Rollback orchestrateur :

```bash
for dashboard_name in \
  demo-exchanges \
  recipe-r1-r16-nominal-fake \
  recipe-r1-r16-okx-dry-run \
  recipe-r1-r16-hyperliquid-dry-run
do
  dashboard_id="$(
    curl -sS http://localhost:8099/dashboards \
      | DASHBOARD_NAME="$dashboard_name" python3 -c 'import json, os, sys; name=os.environ["DASHBOARD_NAME"]; print(next((str(d["id"]) for d in json.load(sys.stdin) if d["name"] == name), ""))'
  )"
  test -z "$dashboard_id" && continue
  curl -sS -X DELETE "http://localhost:8099/dashboards/${dashboard_id}"
done
```

Si l'historique des runs doit rester visible, preferer l'arret non destructif.

## Incidents frequents

| Incident | Action |
|---|---|
| `Schedule ready: no` | Laisser la section exchange `BLOCKED`, verifier readiness et credentials dedies. |
| Credential absent | Ne pas saisir de secret dans le runbook ; corriger le gestionnaire de secrets hors Git. |
| Set stale active | Reappliquer `demo_exchanges_dashboard.json`, qui desactive les sets hors fixture avec `dry_run=true`. |
| R14 accepte un probe live | Stopper la recette, supprimer le set probe, rollback immediat, ouvrir incident. |
| Payload contient un secret | Stopper diffusion du rapport, regenerer preuve redacted, traiter comme incident de securite. |
| Fallback Bitmart observe | Stopper la recette, rollback orchestrateur, ouvrir incident bloquant. |
| Mainnet detecte | Rollback immediat, conserver preuves, bloquer toute suite demo/testnet. |

## Checklist avant activation

- [ ] `mainnet_write_enabled=false`.
- [ ] `DEMO_TRADING_ENABLED=0`.
- [ ] `OKX_DEMO_TRADING_ENABLED=0`.
- [ ] `HYPERLIQUID_TESTNET_TRADING_ENABLED=0`.
- [ ] `kill_switch_enabled=true`.
- [ ] Credentials demo/testnet presents mais non affiches.
- [ ] Aucun secret mainnet disponible dans l'application.
- [ ] `demo-exchanges` applique, sets desactives par defaut.
- [ ] Symboles allow-listes limites a `BTCUSDT`.
- [ ] Max notional minimal documente.
- [ ] Runtime-check OKX execute.
- [ ] Runtime-check Hyperliquid execute.
- [ ] Rollback immediat pret.

## Checklist post-run

- [ ] Rapport racine DEMO-002 conserve.
- [ ] Sous-rapports `global`, `okx`, `hyperliquid` conserves.
- [ ] Aucun secret dans les preuves.
- [ ] Aucun mainnet dans les preuves.
- [ ] Aucun fallback Bitmart dans les preuves.
- [ ] R14 refuse les probes live.
- [ ] Les scenarios non exerces restent `BLOCKED`.
- [ ] Dashboard `demo-exchanges` desactive ou supprime.
- [ ] Switches demo/testnet revenus a `0`.
- [ ] Incidents documentes avec cause et action corrective.
