# Rapport de recette runtime orchestrateur #188

Date UTC : 2026-06-26

Decision : `blocked`

## Resume

La recette finale R1-R16 n'a pas pu etre executee sur une stack representative
depuis cet environnement. La machine locale ne donne pas acces au daemon Docker,
et les services attendus ne repondent pas sur les ports de recette :

| Service | Verification | Resultat |
| --- | --- | --- |
| Docker Compose | `docker compose ps --format json` | `BLOCKED` - socket Docker absent |
| Orchestrateur Python | `curl http://localhost:8099/healthcheck` | `BLOCKED` - connexion refusee |
| Symfony | `curl http://localhost:8082/api/mtf/contracts` | `BLOCKED` - connexion refusee |
| Temporal UI | `curl http://localhost:8233` | `BLOCKED` - connexion refusee |

Le runner reproductible a ete execute en dry-run force contre
`http://localhost:8099`. Il a correctement refuse de declarer les scenarios
comme passes et a produit une matrice `BLOCKED`.

Preuve versionnee : `reports/evidence/orchestrator-runtime-recipe-blocked-2026-06-26.json`.

## Commande executee

```bash
cd python-orchestrator
python3 scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --export-dir var/runtime-recipe/issue-188-final-report \
  --keep-fixtures
```

Sortie :

```json
{
  "ready_for_final_report": false,
  "scenario_counts": {
    "BLOCKED": 10
  }
}
```

## Matrice R1-R16

| Scenario | Statut | Justification |
| --- | --- | --- |
| R1 | `BLOCKED` | Orchestrateur indisponible, aucun run nominal un set possible. |
| R2 | `BLOCKED` | Orchestrateur indisponible, aucun run nominal multi-sets possible. |
| R3 | `BLOCKED` | Non execute : depend de l'application des fixtures sur orchestrateur disponible. |
| R4 | `BLOCKED` | Non execute : depend du dashboard degrade persiste. |
| R5 | `BLOCKED` | Orchestrateur indisponible, aucune erreur fonctionnelle Symfony observable. |
| R6 | `BLOCKED` | Orchestrateur indisponible, aucune panne Symfony injectee/observee. |
| R7 | `BLOCKED` | Non execute : depend d'un mix de sets reussis et en echec. |
| R8 | `BLOCKED` | Orchestrateur indisponible, idempotence non observable. |
| R9 | `BLOCKED` | Non execute : replay explicite impossible sans run termine. |
| R10 | `BLOCKED` | Requiert un crash controle apres claim et une stack dediee. |
| R11 | `BLOCKED` | Orchestrateur indisponible, overlap de ticks non observable. |
| R12 | `BLOCKED` | Non execute : contention inter-profils impossible sans dispatch. |
| R13 | `BLOCKED` | Non execute : requiert un harness fail-closed dedie pour snapshot obsolete. |
| R14 | `BLOCKED` | Orchestrateur indisponible, garde live non exercee a l'API. |
| R15 | `BLOCKED` | Temporal indisponible, schedule non verifiable. |
| R16 | `BLOCKED` | Rollback non execute : aucun schedule cible a pauser/reprendre. |

## Criteres d'acceptation #188

| Critere | Statut | Detail |
| --- | --- | --- |
| Scenarios critiques R1, R2, R5, R6, R8, R10, R11, R14, R15, R16 PASS | `BLOCKED` | Tous les scenarios critiques sont bloques par absence de stack. |
| Aucun scenario ne peut declencher du live | `PARTIAL` | Le runner force `--confirm DRY_RUN_ONLY` et `dry_run=true`, mais la garde API R14 n'a pas ete exercee. |
| Aucun set claime ne reste bloque definitivement apres crash | `BLOCKED` | R10 non execute. |
| Aucun doublon pour une meme cle d'idempotence | `BLOCKED` | R8 non execute. |
| Erreurs Symfony visibles et non transformees en succes | `BLOCKED` | R5/R6 non executes. |
| Cockpit identifie le set fautif sans logs bruts | `BLOCKED` | Cockpit non accessible. |
| Schedule Temporal pause et repris proprement | `BLOCKED` | Temporal non accessible. |
| Rollback execute au moins une fois en dry-run | `BLOCKED` | Aucun schedule cible disponible. |
| Anciens schedules pauses seulement apres observation parallele concluante | `BLOCKED` | Observation parallele non demarree. |
| Rapport relu avant bascule runtime | `PASS` | Ce rapport bloque explicitement toute bascule. |

## Rollback

Le rollback n'a pas ete execute, car Temporal n'etait pas disponible et aucun
schedule de recette n'existait dans cet environnement. La procedure a appliquer
sur la stack de recette reste :

```bash
cd cron_symfony_mtf_workers
python scripts/manage_orchestrator_schedule.py pause \
  --schedule-id recipe-orchestrator-r1-r16

python scripts/manage_orchestrator_schedule.py status \
  --schedule-id recipe-orchestrator-r1-r16
```

Condition de validation attendue :

- le schedule cible `recipe-orchestrator-r1-r16` est `paused` ;
- aucun schedule legacy MTF-run n'est reactive tant que le schedule cible n'est
  pas confirme pause ;
- les schedules actifs non concernes, notamment sync contracts et cleanup,
  restent intacts ;
- le temps de rollback est mesure et consigne.

## Commandes a rejouer sur stack representative

```bash
docker compose --profile orchestrator up -d

cd python-orchestrator
alembic upgrade head

python3 scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --export-dir var/runtime-recipe/final \
  --keep-fixtures
```

Avec Temporal disponible :

```bash
python3 scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --temporal-available \
  --export-dir var/runtime-recipe/final-temporal \
  --keep-fixtures \
  --temporal-dry-run-command \
    python ../cron_symfony_mtf_workers/scripts/manage_orchestrator_schedule.py create \
    --dry-run --dashboard-id '{dashboard_id}' \
    --schedule-id recipe-orchestrator-r1-r16 \
    --workflow-id recipe-orchestrator-r1-r16-runner \
    --cron '*/5 * * * *'
```

## Ecart detecte

Aucun bug applicatif n'a ete demontre par cette execution. L'ecart est
operationnel : l'environnement courant ne fournit pas la stack de recette
necessaire. Il faut relancer cette PR ou une PR de suite depuis une machine ou
Docker, l'orchestrateur, Symfony et Temporal sont disponibles.

## Cloture #188

#188 ne doit pas etre fermee avec ce rapport. Les criteres d'acceptation restent
ouverts tant qu'une recette R1-R16 n'a pas ete executee sur stack representative,
avec rollback dry-run et preuves redacted.
