# OKX dry-run

## Statut

OKX est `target_dry_run_only`. PR11 prépare OKX en **dry-run / runtime-check uniquement** :
aucune exécution live, aucun branchement runtime, aucun ordre réel. La bascule live d'OKX
reste interdite et exigera une **PR de readiness live dédiée**, séparément relue.

Cette page complète :

- `technical/exchange-runtime-gates.md` (gates obligatoires avant tout live) ;
- `technical/exchange-readiness-matrix.md` (statut par exchange) ;
- `technical/okx-demo-readiness.md` (capability matrix OKX et ADR de readiness demo) ;
- `technical/fake-paper-gateway.md` (le filet de sécurité Fake / Paper).

## OkxDryRunExecutionPort

`App\TradingCore\Execution\Okx\OkxDryRunExecutionPort` est la deuxième implémentation de
`ExecutionPortInterface` (après `FakeExecutionPort`). Elle respecte strictement l'interface
existante : `execute(ExecutionRequest): ExecutionResult`.

C'est une **preview / simulation TradingCore**, pas une exécution exchange réelle :

- **aucun appel HTTP**, jamais ;
- **aucun `privatePost` OKX** : le port ne touche ni `OkxExchangeAdapter::placeOrder()`,
  ni `OkxRestClient::privatePost()`, ni aucune classe `App\Exchange\Okx\*` ;
- aucune dépendance Symfony, Doctrine, Messenger, Temporal ni provider runtime concret ;
- le port est **pur** : même plan ⇒ même `exchange_order_id`, requête jamais mutée.

Comportement :

| Cas | Résultat |
| --- | --- |
| `ExecutionMode::Live` | `ExecutionStatus::Rejected` (`live_not_supported_by_okx_dry_run`). |
| Plan d'un autre exchange (`exchange != okx`) | `Rejected` (`wrong_exchange_for_okx_dry_run`). |
| Market type non supporté (≠ `perpetual`) | `Rejected` (`market_type_not_supported_by_okx_dry_run`). |
| Plan non exécutable (revalidé via `OrderPlanValidator`) | `Rejected` (`order_plan_not_executable` + `invalid_reasons`). |
| Plan OKX perpetual valide en dry-run | `ExecutionStatus::DryRun`, `exchange_order_id = OKX-DRYRUN-{client_order_id}`. |

Metadata produite (success) : `gateway=okx`, `mode=dry_run`, `simulated=true`, `no_http=true`,
`no_private_post=true`, `client_order_id`, `idempotency_key`, `requested_at`, `order_type`,
`side`, `symbol`, `entry_price`, `quantity`, `leverage`, `protection_present`. Les descripteurs
gateway (`gateway`, `simulated`, `no_http`, `no_private_post`) et le `reject_reason` sont
autoritaires : un appelant ne peut pas les usurper via la metadata entrante.

`client_order_id` et `idempotency_key` sont conservés sur tous les chemins.

## Runtime-check : OKX reste dry-run only

`app:exchange:runtime-check okx perpetual` durcit la gate OKX :

- `Live trading: disabled` — OKX ne peut pas passer live en PR11, même si
  `OKX_DEMO_TRADING_ENABLED=1` ou `OKX_LIVE_ENABLED=1` ;
- `Dry-run only: yes` ;
- `Live allowed: no` ;
- `Demo trading enabled: yes/no` — capacité demo OKX, **distincte** de l'autorisation live ;
- `Recommended dry_run: true` (toujours, pour OKX).

La capacité « demo order » d'OKX n'est donc jamais assimilée à « live allowed ». Bitmart legacy
n'est pas affecté : ces lignes sont spécifiques à OKX.

## Schedules Temporal : OKX dry_run=false interdit

`cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py` refuse explicitement la
création d'un schedule OKX live :

- `create --exchange okx --dry-run=false` lève
  `RuntimeError: OKX schedules must stay dry_run=true until a dedicated live-readiness PR.` ;
- la règle est **indépendante du runtime-check** : `--skip-runtime-check` ne la contourne pas ;
- `--dry-run=true` (défaut) pour OKX reste autorisé ;
- Bitmart legacy peut toujours créer un schedule live ; seule OKX est bloquée.

## Hors-scope PR11

- aucune activation live OKX (PR de readiness live dédiée requise) ;
- **aucun bundle provider OKX runtime MTF activé** : `mtf:run`, `POST /api/mtf/run` et le
  Temporal scheduler ne sont pas branchés sur OKX par cette PR ;
- aucun branchement `TradeEntry` runtime ;
- aucun changement de stratégie (`regular` / `scalper` / `scalper_micro`), EntryZone,
  Risk / Leverage / SL-TP ni YAML ;
- aucune suppression Bitmart ; Hyperliquid hors-scope ;
- aucun secret ajouté dans Git (`config_file/*.env` restent des templates de noms de clés).

## Filet de sécurité

`FakeExecutionPort` (Fake / Paper) reste le filet de sécurité : il permet d'exercer toute la
chaîne `OrderPlan → ExecutionPort` sans aucun risque. `OkxDryRunExecutionPort` sert de référence
de preview pour comparer les futurs payloads OKX avant toute PR d'activation live.

## Suite

PR12 : Hyperliquid dry-run. La bascule live OKX restera une PR dédiée, testée et réversible.
