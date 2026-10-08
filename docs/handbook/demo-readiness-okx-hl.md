# Go / no-go — OKX demo et Hyperliquid testnet

Etat au 2026-10-08, lu dans `.worktrees/paper-replay-scale` (base `d357de7b`).
Aucune valeur d'environnement n'a ete modifiee, aucun flag bascule, aucune
connexion exchange ouverte pour produire ce document. Mainnet hors perimetre.

Verdict court : **no-go sur les deux venues**, pour des raisons de code, pas
seulement de configuration. Les gates de securite existent et sont fermees ;
ce qui manque, c'est le chemin qui place un ordre demo/testnet depuis le flux
MTF, et pour Hyperliquid une source de configuration qui est volontairement
en `failClosed()`.

Runbooks de reference (plus detailles, a lire avant toute tentative) :
`runbooks/demo-testnet-operations.md`, `runbooks/demo-testnet-kill-switch.md`,
`runbooks/hyperliquid-testnet-controlled-trading.md`,
`technical/okx-demo-readiness.md`, `technical/hyperliquid-testnet-readiness.md`,
`docs/roadmap/01-okx-hyperliquid-demo-testnet.md`.

---

## OKX demo

### Variables d'environnement

Lues par `App\Exchange\Okx\OkxConfig` (`config/services.yaml` l.489-501).

| Variable | Valeur requise pour le demo | Etat dans `trading-app/.env` |
|---|---|---|
| `OKX_ENV` | `demo` | `demo` |
| `OKX_DEMO_API_KEY` | cle demo dediee | **vide** — le `.env` definit `OKX_API_KEY` (mauvais nom, non lu) |
| `OKX_DEMO_API_SECRET` | secret demo dedie | **vide** — idem (`OKX_API_SECRET`) |
| `OKX_DEMO_API_PASSPHRASE` | passphrase demo dediee | **vide** — idem (`OKX_API_PASSPHRASE`) |
| `OKX_API_BASE_URI` | vide, ou exactement `https://eea.okx.com` | **`https://www.okx.com`** → `okx_private_rest_endpoint_not_allowed` a la premiere requete privee |
| `OKX_WS_PUBLIC_URI` / `OKX_WS_PRIVATE_URI` / `OKX_WS_BUSINESS_URI` | vides (defauts `wseeapap.okx.com`) | renseignees sur `ws.okx.com` / `wspap.okx.com` (hotes mainnet / legacy) |
| `OKX_SIMULATED_TRADING` | `1` (header `x-simulated-trading`) | **absente** → defaut `0` → requetes privees demo refusees |
| `DEMO_TRADING_ENABLED` | `0` tant que les gates ne sont pas levees | `0` |
| `OKX_DEMO_TRADING_ENABLED` | `0` idem | `0` |
| `OKX_LIVE_ENABLED` | `0`, immuable dans cette vague | `0` |

Le service Compose `trading-app-okx-private-ws` injecte les bons noms et les
bons defauts (`eea.okx.com`, `OKX_SIMULATED_TRADING=1`). Le service
`trading-app-php` ne les injecte pas et depend donc du `.env` : c'est la
qu'il faut corriger les quatre lignes ci-dessus.

Verifier la presence sans afficher les valeurs :

```bash
docker compose exec -T trading-app-php php -r '
foreach (["OKX_ENV","OKX_DEMO_API_KEY","OKX_DEMO_API_SECRET","OKX_DEMO_API_PASSPHRASE","OKX_SIMULATED_TRADING","OKX_API_BASE_URI"] as $n) {
  $v = getenv($n); echo $n, "=", ($v === false || trim((string)$v) === "") ? "missing" : "present", PHP_EOL; }'
```

### Gates et comment les lire

```bash
docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check okx perpetual
```

Sortie attendue aujourd'hui (et tant que OKX-010 n'existe pas) :
`Dry-run only: yes`, `Live allowed: no`, `Live trading: disabled`,
`Credentials: missing`, `Recommended dry_run: true`.

- `Schedule ready: yes` exige `Readiness level` ∈ {`local_dry_run_ready`,
  `demo_testnet_candidate`} (`ExchangeRuntimeCheckCommand::scheduleReady`).
- `demo_testnet_candidate` est **rabaisse** a `local_dry_run_ready` tant que
  `OKX_DEMO_TRADING_ENABLED=0` (`OkxRuntimeCheck::capAtLocalDryRun`).
- Le rapport de readiness OKX est construit avec `killSwitch: true`,
  `maxNotional: 25.0`, `allowedMarkets: [perpetual]` en dur dans la commande.
- Observabilite privee : worker `app:okx:private-ws` (service Compose
  `trading-app-okx-private-ws`, profil `okx-observability`), statut dans Redis,
  log `var/log/okx-private-ws.log`.
- Kill switch commun : `DemoTradingKillSwitchService` — refuse avec
  `demo_trading_disabled` si `DEMO_TRADING_ENABLED=0`, `okx_demo_trading_disabled`
  si `OKX_DEMO_TRADING_ENABLED=0`, `client_order_id_required`, etc. Chaque
  tentative, autorisee ou bloquee, est ecrite dans
  `var/log/demo-trading-audit.log` (canal Monolog `demo_trading_audit`).

### Ce qui n'existe pas

- `OkxOrderGateway::placeOrder()` et `cancelOrder()` levent
  `okx_order_write_not_implemented` (`src/Provider/Okx/OkxOrderGateway.php:296`).
- `OkxDryRunExecutionPort` est un apercu pur (`no_http`, `no_private_post`),
  refuse `ExecutionMode::Live`, et **n'est reference par aucun service** : il
  n'est pas branche au flux MTF → `mtf_decision` → `TradeEntryService`.
- Roadmap : `OKX-010 — Demo trading controlled avec SL` est non fait.

Conclusion OKX : aucune variable ne permet de placer un ordre demo. Le demo
OKX aujourd'hui = lecture publique + lecture privee demo + recette dry-run.

### Infra a demarrer

`trading-app-db`, `redis`, `trading-app-php`, `trading-app-nginx`,
`trading-app-messenger-trading` (`mtf_decision`),
`trading-app-messenger-order-timeout`, `trading-app-messenger-projection`,
`python-orchestrator` (port 8099) + `temporal` pour le schedule,
`trading-app-okx-private-ws` (`--profile okx-observability`).

### Sequence de lancement autorisee aujourd'hui (dry-run)

1. Corriger le `.env` (noms `OKX_DEMO_*`, `OKX_API_BASE_URI` vide,
   `OKX_SIMULATED_TRADING=1`), puis
   `docker compose up -d --no-deps --force-recreate trading-app-php`
   (un `restart` ne reinjecte pas une variable Compose).
2. `app:exchange:runtime-check okx perpetual` → conserver la sortie.
3. `docker compose --profile okx-observability up -d trading-app-okx-private-ws`.
4. Schedule DEMO-004, cree **en pause** et forcant `dry_run=true` :
   `cd cron_symfony_mtf_workers && python scripts/manage_demo_testnet_schedule.py create --dashboard-id "$dashboard_id"`.
5. Recette : `cd python-orchestrator && python scripts/runtime_recipe_runner.py --orchestrator-url http://localhost:8099 --confirm DRY_RUN_ONLY --target-exchange demo-exchanges --export-dir var/runtime-recipe/demo-exchanges --keep-fixtures`.
6. Tout appel `/api/mtf/run` doit porter `"exchange":"okx"` : le defaut est
   encore `Exchange::BITMART` (voir bloqueur 1).

### Verifier qu'il place (ou refuse) des ordres

- `var/log/demo-trading-audit.log` : une ligne par tentative avec `outcome`
  (`allowed`/`blocked`) et `reasons`.
- `var/log/order-journey*.log` (canal `positions`) : `exchange_execution.*`.
- `var/log/mtf-runner.log`, `var/log/okx-private-ws.log`.
- Rapports `var/runtime-recipe/demo-exchanges/**/runtime-recipe-report.json`.

### Arret

`python scripts/manage_demo_testnet_schedule.py pause` ; desactiver les
dashboards (`PATCH /dashboards/{id} {"enabled": false}`) ; remettre
`DEMO_TRADING_ENABLED=0`, `OKX_DEMO_TRADING_ENABLED=0` puis force-recreate
`trading-app-php` ; `docker compose stop trading-app-okx-private-ws`.

---

## Hyperliquid testnet

### Variables d'environnement

Lues par `App\Exchange\Hyperliquid\HyperliquidConfig` (`services.yaml`
l.465-475) et par le sidecar `hyperliquid-signer` (`docker-compose.yml`
l.411-423).

| Variable | Valeur requise | Etat dans `trading-app/.env` |
|---|---|---|
| `HYPERLIQUID_ENV` | `testnet` | absente → defaut `testnet` |
| `HYPERLIQUID_NETWORK` | `testnet` | absente → defaut `testnet` |
| `HYPERLIQUID_API_BASE_URI` | vide ou exactement `https://api.hyperliquid-testnet.xyz` | absente → defaut testnet |
| `HYPERLIQUID_MAINNET_ENABLED` | `0`, immuable | absente → `0` |
| `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` | master account, role `/info` = `user` (pas de subaccount/vault) | **vide** |
| `HYPERLIQUID_TESTNET_AGENT_ADDRESS` | API wallet dedie, distinct du compte | **vide** |
| `HYPERLIQUID_SIGNER_BASE_URI` | `http://hyperliquid-signer:8098` | absente → defaut correct |
| `HYPERLIQUID_SIGNER_AUTH_TOKEN` | token partage PHP ↔ sidecar | **vide** |
| `HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY` | sidecar **uniquement**, jamais PHP | non verifiable depuis PHP (voulu) |
| `HYPERLIQUID_SIGNER_BROADCAST_ENABLED` | `0` jusqu'a la gate | sidecar, defaut `0` |
| `DEMO_TRADING_ENABLED` | `0` jusqu'a la gate | `0` |
| `HYPERLIQUID_TESTNET_TRADING_ENABLED` | `0` jusqu'a la gate | absente → `0` |

Aucune ligne `HYPERLIQUID_*` n'existe dans le `.env` du worktree : tout est au
defaut. Resultat : `Credentials: missing` au runtime-check.

Verifier la presence (snippet du runbook HL-012, sans la cle privee) :

```bash
docker compose exec -T trading-app-php php -r '
foreach (["HYPERLIQUID_ENV","HYPERLIQUID_NETWORK","HYPERLIQUID_API_BASE_URI","HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS","HYPERLIQUID_TESTNET_AGENT_ADDRESS","HYPERLIQUID_SIGNER_AUTH_TOKEN"] as $n) {
  $v = getenv($n); echo $n, "=", ($v === false || trim((string)$v) === "") ? "missing" : "present", PHP_EOL; }'
```

### Gates et comment les lire

```bash
docker compose exec -T trading-app-php php bin/console app:exchange:runtime-check hyperliquid perpetual
```

`Schedule ready: yes` et `Live allowed: yes` n'apparaissent que si
`HyperliquidMutationReadinessGate::blockingReasons()` est vide (28 conditions :
testnet impose de bout en bout, `DEMO_TRADING_ENABLED=1`,
`HYPERLIQUID_TESTNET_TRADING_ENABLED=1`, compte lisible, permission trade de
l'agent prouvee, collateral lisible, polling pret, signer configure et lie au
compte, nonce store pret, kill switch non declenche, profil + hash de config
effective, allow-list de marches, `max_notional > 0`).

- **Kill switch durable** : table via `HyperliquidTestnetKillSwitchStateRepository`
  + marqueur de secours `var/hyperliquid-testnet-execution.quarantine`
  (`FilesystemFallbackHyperliquidKillSwitch`). Un trip persiste jusqu'a
  `app:hyperliquid:testnet:quarantine-recover --confirm <phrase>`, qui ne
  transfere la quarantaine que si la table est deja `tripped` — il ne reset
  jamais le switch.
- Migrations requises : `Version20260712120000`, `Version20260712230000`
  (nonces, tentatives, kill switch). Verifier avec `doctrine:migrations:list`.
- Audit : chaque tentative passe par `DemoTradingKillSwitchService` →
  `var/log/demo-trading-audit.log`.

### Ce qui bloque dans le code

1. `EffectiveTradingHyperliquidMutationReadinessConfigSource::current()`
   retourne **toujours** `HyperliquidMutationReadinessConfig::failClosed()`
   (`src/Provider/Hyperliquid/EffectiveTradingHyperliquidMutationReadinessConfigSource.php:14`,
   commentaire : « must remain fail-closed until its caller is migrated with
   #302 lineage »). Consequence : `demo_testnet_write_guard_not_ready`,
   `kill_switch_enabled`, `effective_config_profile_required`,
   `market_allow_list_required`, `positive_max_notional_required` sont
   toujours presents → la gate ne peut pas s'ouvrir, quelles que soient les
   variables d'environnement.
2. Le seul consommateur de `HyperliquidTestnetExecutionPort` est la commande
   operateur `app:hyperliquid:testnet:smoke <plan.json>
   --confirm CONFIRM_HYPERLIQUID_TESTNET_ONLY
   --readiness-decision ready_for_demo_testnet_trading_attempt` : un plan
   JSON (schema v1), une tentative, entree limit GTC + stop loss plein volume
   groupes (`positionTpsl`), position et ordres a plat exiges avant. Il n'y a
   **aucun branchement** depuis MTF → `mtf_decision` → `TradeEntryService`.
   `ExchangeRuntimeCheckCommand::liveTradingStatus()` renvoie en dur
   `disabled` pour Hyperliquid.
3. Roadmap : DEMO-005 (decision pre-mutative) est `blocked` ; le runbook HL-012
   interdit le smoke tant que cette decision n'est pas remplacee par
   `ready_for_demo_testnet_trading_attempt`, de facon versionnee et relue.

### Infra a demarrer

Comme OKX, plus `hyperliquid-signer`
(`docker compose --profile hyperliquid-testnet up -d --no-deps hyperliquid-signer`,
aucun port hote), PostgreSQL pour nonces/tentatives/kill switch, `var/lock`
(FlockStore du verrou d'execution).

### Sequence de lancement autorisee aujourd'hui (readiness + dry-run)

1. Renseigner hors Git : account address, agent address, auth token (PHP et
   sidecar), cle privee de l'agent (sidecar seulement). Compte testnet finance,
   agent approuve, aucun autre processus n'ecrit sur ce compte.
2. Verifier migrations et presence des variables (snippets ci-dessus).
3. `export DEMO_TRADING_ENABLED=0 HYPERLIQUID_TESTNET_TRADING_ENABLED=0 HYPERLIQUID_SIGNER_BROADCAST_ENABLED=0`
   puis demarrer le sidecar ; `docker compose --profile hyperliquid-testnet ps hyperliquid-signer`.
4. `docker compose up -d --no-deps --force-recreate trading-app-php`.
5. `app:exchange:runtime-check hyperliquid perpetual` → attendu aujourd'hui :
   `Live trading: disabled`, `Dry-run only: yes`, `Schedule ready: no`.
6. Recette dry-run : `python scripts/runtime_recipe_runner.py --confirm DRY_RUN_ONLY --target-exchange hyperliquid --scenario R1 --scenario R2 --scenario R14 --export-dir var/runtime-recipe/hyperliquid-dry-run --keep-fixtures`.
   R14 doit refuser `dry_run=false` avant dispatch.
7. S'arreter la. Le smoke mutatif est interdit tant que DEMO-005 est `blocked`.

### Verifier qu'il place (ou refuse) des ordres

- `var/log/demo-trading-audit.log` (raisons de blocage listees).
- Sortie du smoke : `status=accepted|rejected|failed|ambiguous`,
  `client_order_id`, `exchange_order_id` (redacted).
- Reconciliation read-only `/info` avec l'**account** address (runbook HL-012
  §« Reconciliation »).
- Tables `hyperliquid_testnet_execution_attempt*` / kill switch via
  `dbal:run-sql` (requetes dans le runbook, §« Incident »).

### Arret / rollback immediat

Sans deploiement : remettre les trois gates a `0`, force-recreate
`trading-app-php`, `docker compose --profile hyperliquid-testnet stop hyperliquid-signer`,
`docker compose restart trading-app-messenger-trading`, mettre le schedule en
pause. Pour geler durablement : declencher le kill switch (marqueur
`var/hyperliquid-testnet-execution.quarantine` ou trip en base) ; ne lever la
quarantaine que par la commande `quarantine-recover`.

---

## Bloqueurs avant un run demo/testnet (dans l'ordre)

1. **Branche `feat/remove-bitmart` a livrer et a re-defaulter explicitement.**
   Le defaut exchange est encore `Exchange::BITMART` dans
   `MtfRunnerService::createContext()` (l.1108), `ExchangeProviderRegistry`
   (l.34), `ExchangeContextResolver::resolve()` (l.27), `MtfRunCommand`,
   `MtfRunWorkerCommand`, `MtfCoreRunCommand`, `ContractsApiController`,
   `WebSocketController` ; `ExchangeExecutionService` garde des branches
   Bitmart (l.128, 365) ; `ExchangeRuntimeCheckCommand` lit `bitmartEnv`.
   La regle roadmap « aucun fallback silencieux vers Bitmart » impose que le
   nouveau defaut soit une decision relue, pas un effet de bord.
2. **OKX : pas de placement d'ordre demo** (`okx_order_write_not_implemented`,
   port dry-run non branche). C'est OKX-010, du code a ecrire — aucune
   variable ne le debloque.
3. **Hyperliquid : source de readiness en `failClosed()` dur** (#302) et port
   testnet joignable uniquement par le smoke operateur, pas par le flux MTF.
   Deux chantiers de code avant un « demo qui tourne ».
4. **DEMO-005 `blocked`** : decision humaine, versionnee, a produire avant
   toute tentative mutative sur l'une ou l'autre venue.
5. **Credentials manquants** (tous hors Git) :
   - OKX : `OKX_DEMO_API_KEY/SECRET/PASSPHRASE` (les cles presentes sont sous
     les mauvais noms), `OKX_API_BASE_URI` a vider, `OKX_SIMULATED_TRADING=1`.
   - Hyperliquid : `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS`,
     `HYPERLIQUID_TESTNET_AGENT_ADDRESS`, `HYPERLIQUID_SIGNER_AUTH_TOKEN`,
     `HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY` (sidecar), collateral testnet,
     agent approuve et dedie.
6. **Infra** : migrations `Version20260712120000`/`Version20260712230000`
   appliquees ; image `hyperliquid-signer` construite ; Temporal + orchestrateur
   (8099) up pour le schedule ; workers Messenger `trading` et `order_timeout`
   actifs.
7. **Strategie** : les specs versionnees ont produit 0 trade/cellule sur 24 h
   de replay OKX et HL (Paper #132). Meme un flux demo branche ne placerait
   probablement rien tant que l'etape 3 de la feuille de route (iteration des
   profils par backtest) n'a pas ete faite.

Les flags `OKX_LIVE_ENABLED`, `HYPERLIQUID_MAINNET_ENABLED`,
`DEMO_TRADING_ENABLED`, `OKX_DEMO_TRADING_ENABLED`,
`HYPERLIQUID_TESTNET_TRADING_ENABLED` restent a `0` jusqu'a ce que les points
1 a 6 soient leves et que l'operateur le decide explicitement.
