# Hyperliquid dry-run

## Statut

Hyperliquid est `target_dry_run_only`. PR12 prépare Hyperliquid en **dry-run / runtime-check
uniquement**, au même niveau de prudence qu'OKX (PR11) : aucune exécution live, aucun branchement
runtime, aucun ordre réel, aucune activation mainnet. La bascule live de Hyperliquid reste
interdite et exigera une **PR de readiness live dédiée**, séparément relue.

Cette page complète :

- `technical/exchange-runtime-gates.md` (gates obligatoires avant tout live) ;
- `technical/exchange-readiness-matrix.md` (statut par exchange) ;
- `technical/okx-dry-run.md` (le même patron appliqué à OKX) ;
- `technical/fake-paper-gateway.md` (le filet de sécurité Fake / Paper).

## HyperliquidDryRunExecutionPort

`App\TradingCore\Execution\Hyperliquid\HyperliquidDryRunExecutionPort` est la troisième
implémentation de `ExecutionPortInterface` (après `FakeExecutionPort` et
`OkxDryRunExecutionPort`). Elle respecte strictement l'interface existante :
`execute(ExecutionRequest): ExecutionResult`.

C'est une **preview / simulation TradingCore**, pas une exécution exchange réelle :

- **aucun appel HTTP**, jamais ;
- **aucun broadcast `/exchange`**, **aucune signature réelle**, **aucune private key** : le port ne
  touche ni `HyperliquidExchangeAdapter`, ni `HyperliquidRestClient` ;
- les actions `/exchange` sont sérialisées localement via `HyperliquidActionFactory`, puis signées
  uniquement avec `FakeHyperliquidSigner` pour produire une preuve redacted non diffusable ;
- aucune dépendance Symfony, Doctrine, Messenger, Temporal ni provider runtime concret ;
- le port est **pur** : même plan ⇒ même `exchange_order_id`, requête jamais mutée.

Comportement :

| Cas | Résultat |
| --- | --- |
| `ExecutionMode::Live` | `ExecutionStatus::Rejected` (`live_not_supported_by_hyperliquid_dry_run`). |
| Plan d'un autre exchange (`exchange != hyperliquid`) | `Rejected` (`wrong_exchange_for_hyperliquid_dry_run`). |
| Market type non supporté (≠ `perpetual`) | `Rejected` (`market_type_not_supported_by_hyperliquid_dry_run`). |
| Environnement mainnet/live/prod dans la metadata | `Rejected` (`mainnet_environment_forbidden_for_hyperliquid_dry_run`). |
| Symbole hors whitelist `allowed_symbols` | `Rejected` (`demo_trading_safety_blocked` + `requested_symbol_or_market_not_allowed`). |
| Notional supérieur à `max_notional` | `Rejected` (`demo_trading_safety_blocked` + `max_notional_exceeded`). |
| Levier supérieur à `max_leverage` | `Rejected` (`leverage_cap_exceeded`). |
| Payload Hyperliquid non encodable (prix/quantité/trigger > 8 décimales wire) | `Rejected` (`hyperliquid_dry_run_payload_unencodable`). |
| Plan non exécutable (revalidé via `OrderPlanValidator`) | `Rejected` (`order_plan_not_executable` + `invalid_reasons`). |
| Plan Hyperliquid perpetual valide en dry-run | `ExecutionStatus::DryRun`, `exchange_order_id = HYPERLIQUID-DRYRUN-{client_order_id}`. |

Metadata produite (success) : `gateway=hyperliquid`, `mode=dry_run`, `simulated=true`,
`environment=local_dry_run|demo|testnet`, `no_http=true`, `no_exchange_call=true`,
`no_broadcast=true`, `client_order_id`, `idempotency_key`, `requested_at`, `order_type`, `side`,
`symbol`, `entry_price`, `quantity`, `leverage`, `notional`, `protection_present`,
`safety_decision`, `private_observability_decision`, `local_dry_run_ready=true` et
`readiness_level=local_dry_run_ready`. Les descripteurs gateway (`gateway`, `simulated`, `no_http`,
`no_exchange_call`, `no_broadcast`) et le `reject_reason` sont autoritaires : un appelant ne peut pas
les usurper via la metadata entrante.

`client_order_id` et `idempotency_key` sont conservés sur tous les chemins.

La réponse `raw.hyperliquid_dry_run` contient une prévisualisation redacted :

- `no_http=true`, `no_exchange_call=true`, `no_broadcast=true`, `redacted=true` ;
- `signer=fake_hyperliquid_signer` ;
- `nonce_policy=deterministic_preview` ;
- `requests[]` avec `method=POST`, `path=/exchange`, `operation=set_leverage|submit_order|stop_loss|take_profit` ;
- chaque `body` est le payload signé par `FakeHyperliquidSigner`, contenant `action`, `nonce`,
  `network=testnet`, adresse fake et signature fake. Ce payload sert d'audit local uniquement.

Le `hyperliquid_asset_id` doit être fourni dans la metadata de requête pour tout symbole autre que la
fixture BTC. Sans asset id explicite, le port rejette le plan avec
`hyperliquid_asset_id_required_for_symbol` au lieu de produire une preview potentiellement liée au
mauvais asset. La fixture BTC peut rester implicite (`asset_id=0`) pour conserver les tests locaux
déterministes ; une PR mutative future devra résoudre et croiser l'asset depuis la metadata exchange
fraîche avant tout broadcast.

## Runtime-check : Hyperliquid reste dry-run only

`app:exchange:runtime-check hyperliquid perpetual` durcit la gate Hyperliquid :

- `Live trading: disabled` — Hyperliquid ne peut pas passer live en PR12, même si
  `HYPERLIQUID_ENV=testnet`, `HYPERLIQUID_MAINNET_ENABLED=1` et les credentials sont présents ;
- `Dry-run only: yes` ;
- `Live allowed: no` ;
- `Network: testnet/mainnet` — réseau configuré, **distinct** de l'autorisation live ;
- `Mainnet enabled: yes/no` — capacité réseau (`HYPERLIQUID_MAINNET_ENABLED`), **distincte** de
  l'autorisation live ;
- `Testnet trading enabled: yes/no` — activation explicite de candidature testnet
  (`HYPERLIQUID_TESTNET_TRADING_ENABLED`), **distincte** de l'autorisation live ;
- `Signer configured`, `Signer/account relation`, `Nonce store`, `Collateral readable`,
  `WS/polling`, `Stop loss capability`, `Kill switch` — raisons operateur qui expliquent
  pourquoi le niveau reste bloque ou peut monter ;
- `Recommended dry_run: true` (toujours, pour Hyperliquid).

Le choix testnet/mainnet et le flag `HYPERLIQUID_MAINNET_ENABLED` ne sont donc jamais assimilés à
« live allowed ». `HYPERLIQUID_TESTNET_TRADING_ENABLED=1` peut seulement permettre
`demo_testnet_candidate` quand toutes les lectures, guards, nonce store, polling et stop-loss
capability sont bonnes et que les URLs REST/WS restent sur `api.hyperliquid-testnet.xyz` ;
la permission trade de l'agent wallet reste signalee comme non prouvee. Cette PR ne produit jamais
`demo_testnet_enabled`. OKX et Bitmart legacy
ne sont pas affectés : ces lignes sont spécifiques à Hyperliquid.

## Schedules Temporal : Hyperliquid dry_run=false interdit

`cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py` refuse explicitement la
création d'un schedule Hyperliquid live :

- `create --exchange hyperliquid --dry-run=false` lève
  `RuntimeError: HYPERLIQUID schedules must stay dry_run=true until a dedicated live-readiness PR.` ;
- la règle est **indépendante du runtime-check** : `--skip-runtime-check` ne la contourne pas ;
- le blocage résiste à la casse et aux espaces (`HYPERLIQUID`, `" Hyperliquid "`) ;
- `--dry-run=true` (défaut) pour Hyperliquid reste autorisé ;
- Bitmart legacy peut toujours créer un schedule live ; OKX et Hyperliquid sont bloqués.

La politique dry-run-only est désormais généralisée (`DRY_RUN_ONLY_EXCHANGES = {"okx",
"hyperliquid"}`) avec un message d'erreur spécifique à l'exchange.

## Adapter existant ≠ live-ready

`HyperliquidExchangeAdapter` / `HyperliquidRestClient` existent déjà dans `App\Exchange\*`, mais leur
présence **ne rend pas Hyperliquid prêt au live** dans TradingCore. Le port dry-run est volontairement
découplé de ces classes. Toute activation live future est une PR dédiée qui devra prouver :

- credentials présents et valides ;
- runtime-check OK ;
- WebSocket privé / équivalent validé ;
- SL/TP attach et liquidation guard adaptés au modèle Hyperliquid ;
- reconciliation testée ;
- audit minimal complet ;
- schedule Temporal explicitement autorisé.

Rollback HL-010 : remettre `HYPERLIQUID_TESTNET_TRADING_ENABLED=0` et/ou revert le branchement
runtime-check Hyperliquid. Le port dry-run local reste no-broadcast et les schedules Hyperliquid
restent `dry_run=true`.

## Recette orchestrateur Hyperliquid dry-run

HL-011 ajoute une fixture orchestrateur reproductible :

```text
python-orchestrator/fixtures/runtime-recipe/r1_r16_hyperliquid_dry_run_dashboard.json
```

Elle cible uniquement `exchange=hyperliquid`, `market_type=perpetual`,
`environment=testnet`, `dry_run=true`, `workers=1` et les scenarios `R1`, `R2`,
`R14`. Le runner `python-orchestrator/scripts/runtime_recipe_runner.py` execute
d'abord `app:exchange:runtime-check hyperliquid perpetual` via Docker Compose. Si
la sortie ne contient pas `Schedule ready: yes`, les scenarios Hyperliquid sont
exportes en `BLOCKED` et aucun appel `/orchestrator/run` n'est envoye pour ces
scenarios.

Commande de recette :

```bash
cd python-orchestrator
python3 scripts/runtime_recipe_runner.py \
  --orchestrator-url http://localhost:8099 \
  --confirm DRY_RUN_ONLY \
  --target-exchange hyperliquid \
  --scenario R1 \
  --scenario R2 \
  --scenario R14 \
  --export-dir var/runtime-recipe/hyperliquid-dry-run \
  --keep-fixtures
```

Preuves attendues : dashboard `recipe-r1-r16-hyperliquid-dry-run`, sets
`recipe_hyperliquid_regular` et `recipe_hyperliquid_scalper_micro`, aucun set ou
payload Bitmart, et refus R14 du probe `dry_run=false` avant dispatch. Cette
recette ne change pas les strategies et ne diffuse aucun ordre.

## Hors-scope activation live

- aucune activation live Hyperliquid, aucun mainnet trading (PR de readiness live dédiée requise) ;
- aucun signing réel, aucun appel HTTP, aucun broadcast `/exchange` ;
- aucune activation Temporal live Hyperliquid ; la recette orchestrateur HL-011 reste
  `dry_run=true` et fail-closed avant tout dispatch si le runtime-check n'est pas schedule-ready ;
- aucun branchement `TradeEntry` runtime ;
- aucun changement de stratégie (`regular` / `scalper` / `scalper_micro`), EntryZone,
  Risk / Leverage / SL-TP ni YAML ;
- aucune suppression Bitmart ; OKX inchangé (sauf factorisation neutre testée) ;
- aucun secret ajouté dans Git (`config_file/*.env` restent des templates de noms de clés).

## Filet de sécurité

`FakeExecutionPort` (Fake / Paper) reste le filet de sécurité : il permet d'exercer toute la
chaîne `OrderPlan → ExecutionPort` sans aucun risque. `HyperliquidDryRunExecutionPort` sert de
référence de preview pour comparer les futurs payloads Hyperliquid avant toute PR d'activation live.

## Suite

La bascule live Hyperliquid (et OKX) restera une PR dédiée, testée et réversible, conditionnée par
toutes les gates de `technical/exchange-runtime-gates.md`.
