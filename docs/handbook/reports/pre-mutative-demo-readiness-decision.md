# Pre-mutative demo/testnet readiness decision

Date UTC : 2026-07-11

Decision : `blocked`

## Resume

Cette decision DEMO-005 bloque toute tentative mutative OKX demo ou
Hyperliquid testnet. Le socle demo/testnet est avance, mais les preuves
requises avant un premier ordre fictif ne sont pas completes :

- la recette runtime orchestrateur #188 reste `blocked` sur la preuve
  versionnee disponible ;
- les runtime-checks OKX et Hyperliquid doivent etre rejoues sur la stack
  cible avec credentials demo/testnet reels et sorties redacted ;
- le rollback schedule doit etre exerce sur la stack cible ;
- aucune preuve actuelle ne permet de conclure
  `ready_for_demo_testnet_trading_attempt`.

Cette page n'autorise aucun mainnet et ne declare jamais TradingV3 live-ready.

## Version et configuration

| Element | Valeur |
| --- | --- |
| Code baseline | `0026613fd2859f3fc18fccbbb9f87264189b3fb6` |
| Config/docs content hash | `e2d4f878331d30c9930326f613e4b2fe75c4cec67dec6a3bb469cecc54bed6c9` |
| Methode hash | Checksums `shasum -a 256` des contenus versionnes pertinents, tries puis re-hashes ; ce rapport auto-referentiel est exclu du hash |
| Secrets/env locaux | Non lus, non hashes, non exposes |

Commandes de reproduction du hash :

```bash
git rev-parse HEAD

git ls-files \
  'trading-app/config/*.yaml' \
  'trading-app/config/**/*.yaml' \
  'cron_symfony_mtf_workers/**/*.py' \
  'cron_symfony_mtf_workers/*.md' \
  'docs/handbook/runbooks/*.md' \
  'docs/handbook/technical/*.md' \
  'docs/handbook/reports/*.md' \
  'docs/handbook/reports/evidence/*.json' \
  | rg -v '^docs/handbook/reports/pre-mutative-demo-readiness-decision\.md$' \
  | sort \
  | xargs shasum -a 256 \
  | shasum -a 256
```

## Readiness OKX

| Point | Statut | Detail |
| --- | --- | --- |
| Perimetre autorise | `partial` | OKX reste limite a Demo Trading pour toute mutation future. |
| Mainnet write | `blocked` | `mainnet_write_enabled` doit rester false ; aucune exception dans cette decision. |
| Runtime-check | `not_proven_in_this_report` | A rejouer : `docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check okx perpetual`. |
| Schedule demo/testnet | `available_guarded` | DEMO-004 a ajoute un schedule dedie, paused/dry-run par defaut, activation gardee par runtime-check. |
| Controlled demo write | `not_authorized` | OKX-010 reste bloque tant que cette decision n'est pas `ready_for_demo_testnet_trading_attempt`. |

## Readiness Hyperliquid

| Point | Statut | Detail |
| --- | --- | --- |
| Perimetre autorise | `partial` | Hyperliquid reste limite au testnet pour toute mutation future. |
| Mainnet write | `blocked` | Aucun broadcast mainnet autorise ; aucun secret mainnet ne doit etre charge. |
| Runtime-check | `not_proven_in_this_report` | A rejouer : `docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check hyperliquid perpetual`. |
| Schedule demo/testnet | `available_guarded` | DEMO-004 couvre OKX + Hyperliquid avec validation de definition et activation gardee. |
| Controlled testnet write | `not_authorized` | HL-012 reste bloque tant que cette decision n'est pas `ready_for_demo_testnet_trading_attempt`. |

## Recette #188

Source : [Rapport de recette runtime orchestrateur #188](orchestrator-runtime-final-report.md).

| Critere | Statut actuel | Impact decision |
| --- | --- | --- |
| R1-R16 sur stack representative | `blocked` | Bloque la readiness mutative. |
| R14 garde live exercee a l'API | `blocked` | Bloque la readiness mutative. |
| R15 schedule Temporal verifie | `blocked` | Bloque la readiness mutative. |
| R16 rollback execute | `blocked` | Bloque la readiness mutative. |
| Rapport relu avant bascule | `pass` | Le rapport est relu et bloque explicitement la bascule. |

Tant que #188 ne passe pas sur une stack representative avec preuve redacted,
la seule decision acceptable est `blocked`.

## Data availability #132

Source : [Baseline bad trades #132](bad-trades-baseline.md).

| Point | Statut actuel | Detail |
| --- | --- | --- |
| Rapport methodologique | `present` | La methode de baseline existe et exclut les donnees non certifiees. |
| Extraction production locale | `blocked` | L'extraction reelle n'a pas ete executee localement faute de Docker/PostgreSQL disponible dans la preuve existante. |
| Chiffres trading reels | `not_available` | Aucun chiffre n'est invente dans cette decision. |
| Impact readiness mutative | `blocking_context` | L'absence de baseline factuelle renforce le blocage ; elle ne suffit pas seule a autoriser une mutation demo/testnet. |

## Kill switch

| Verification | Statut | Detail |
| --- | --- | --- |
| Runbook kill switch | `present` | [Demo/Testnet kill switch](../runbooks/demo-testnet-kill-switch.md). |
| Kill switch teste sur stack cible | `not_proven_in_this_report` | Doit etre rejoue avec sorties redacted avant toute decision ready. |
| Activation mutative avec kill switch actif | `blocked_by_policy` | Toute PR OKX-010/HL-012 doit refuser l'ordre si le kill switch est actif. |

## Rollback

| Verification | Statut | Detail |
| --- | --- | --- |
| Runbook operations | `present` | [Demo/Testnet operations](../runbooks/demo-testnet-operations.md). |
| Schedule rollback pause/delete | `documented` | DEMO-004 a ajoute les commandes gardees `pause` et `delete`. |
| Rollback teste sur stack cible | `not_proven_in_this_report` | R16 reste bloque dans le rapport #188 ; rollback a rejouer avec Temporal disponible. |

Commandes de rollback a prouver sur la stack cible :

```bash
cd cron_symfony_mtf_workers

python scripts/manage_demo_testnet_schedule.py pause \
  --schedule-id cron-orchestrator-demo-testnet-1m

python scripts/manage_demo_testnet_schedule.py status \
  --schedule-id cron-orchestrator-demo-testnet-1m

python scripts/manage_demo_testnet_schedule.py delete \
  --schedule-id cron-orchestrator-demo-testnet-1m
```

## Incidents et ecarts

| Ecart | Gravite | Suite attendue |
| --- | --- | --- |
| #188 non execute sur stack representative | `blocking` | Rejouer R1-R16 avec Docker, Symfony, orchestrateur et Temporal disponibles. |
| Runtime-check OKX/HL non prouve dans ce rapport | `blocking` | Capturer sorties redacted `Schedule ready: yes` pour les deux exchanges. |
| Rollback R16 non prouve | `blocking` | Mesurer pause/delete/reprise du schedule demo/testnet. |
| Baseline #132 sans extraction reelle locale | `blocking_context` | Executer l'extraction lorsque PostgreSQL est disponible ; ne pas inventer de metriques. |

## Decision finale

Decision : `blocked`.

Raisons bloquantes :

- #188 est `blocked` ;
- R14, R15 et R16 ne sont pas prouves sur stack representative ;
- les runtime-checks OKX et Hyperliquid ne sont pas prouves dans ce rapport ;
- le rollback demo/testnet n'est pas prouve sur la stack cible ;
- aucune preuve ne permet d'autoriser une premiere mutation demo/testnet.

## Conditions pour changer la decision

La decision peut devenir `ready_for_parallel_observation` seulement si :

- #188 est rejoue sur stack representative et ne signale plus de blocage critique ;
- OKX et Hyperliquid sortent `Schedule ready: yes` en runtime-check redacted ;
- le schedule demo/testnet reste paused/dry-run par defaut ;
- le rollback pause/delete est mesure et documente ;
- les incidents ouverts sont listes avec owners.

La decision peut devenir `ready_for_demo_testnet_trading_attempt` seulement si,
en plus des points precedents :

- R14 prouve le refus de toute mutation non autorisee avant dispatch ;
- R15/R16 prouvent schedule et rollback Temporal ;
- kill switch, whitelist, max notional minimal, SL obligatoire et audit redacted
  sont prouves ;
- aucune donnee incomplete n'est presentee comme certifiee ;
- le rapport indique explicitement que le mainnet reste interdit.

## Non-autorisation explicite

Cette decision interdit :

- OKX-010 en mode ordre demo tant que la decision reste `blocked` ;
- HL-012 en mode ordre testnet tant que la decision reste `blocked` ;
- toute activation `dry_run=false` hors demo/testnet ;
- toute formulation `mainnet-ready`, `live-ready` ou equivalente.
