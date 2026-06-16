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
- **aucun appel `/exchange`**, **aucun signing**, **aucune private key** : le port ne touche ni
  `HyperliquidExchangeAdapter`, ni `HyperliquidRestClient`, ni `HyperliquidActionFactory`, ni
  aucune classe `App\Exchange\Hyperliquid\*` ;
- aucune dépendance Symfony, Doctrine, Messenger, Temporal ni provider runtime concret ;
- le port est **pur** : même plan ⇒ même `exchange_order_id`, requête jamais mutée.

Comportement :

| Cas | Résultat |
| --- | --- |
| `ExecutionMode::Live` | `ExecutionStatus::Rejected` (`live_not_supported_by_hyperliquid_dry_run`). |
| Plan d'un autre exchange (`exchange != hyperliquid`) | `Rejected` (`wrong_exchange_for_hyperliquid_dry_run`). |
| Market type non supporté (≠ `perpetual`) | `Rejected` (`market_type_not_supported_by_hyperliquid_dry_run`). |
| Plan non exécutable (revalidé via `OrderPlanValidator`) | `Rejected` (`order_plan_not_executable` + `invalid_reasons`). |
| Plan Hyperliquid perpetual valide en dry-run | `ExecutionStatus::DryRun`, `exchange_order_id = HYPERLIQUID-DRYRUN-{client_order_id}`. |

Metadata produite (success) : `gateway=hyperliquid`, `mode=dry_run`, `simulated=true`,
`no_http=true`, `no_exchange_call=true`, `client_order_id`, `idempotency_key`, `requested_at`,
`order_type`, `side`, `symbol`, `entry_price`, `quantity`, `leverage`, `protection_present`. Les
descripteurs gateway (`gateway`, `simulated`, `no_http`, `no_exchange_call`) et le `reject_reason`
sont autoritaires : un appelant ne peut pas les usurper via la metadata entrante.

`client_order_id` et `idempotency_key` sont conservés sur tous les chemins.

## Runtime-check : Hyperliquid reste dry-run only

`app:exchange:runtime-check hyperliquid perpetual` durcit la gate Hyperliquid :

- `Live trading: disabled` — Hyperliquid ne peut pas passer live en PR12, même si
  `HYPERLIQUID_ENV=testnet`, `HYPERLIQUID_MAINNET_ENABLED=1` et les credentials sont présents ;
- `Dry-run only: yes` ;
- `Live allowed: no` ;
- `Network: testnet/mainnet` — réseau configuré, **distinct** de l'autorisation live ;
- `Mainnet enabled: yes/no` — capacité réseau (`HYPERLIQUID_MAINNET_ENABLED`), **distincte** de
  l'autorisation live ;
- `Recommended dry_run: true` (toujours, pour Hyperliquid).

Le choix testnet/mainnet et le flag `HYPERLIQUID_MAINNET_ENABLED` ne sont donc jamais assimilés à
« live allowed ». OKX et Bitmart legacy ne sont pas affectés : ces lignes sont spécifiques à
Hyperliquid.

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

## Hors-scope PR12

- aucune activation live Hyperliquid, aucun mainnet trading (PR de readiness live dédiée requise) ;
- aucun signing, aucun appel HTTP, aucun appel `/exchange` ;
- **aucun bundle provider Hyperliquid runtime MTF activé** : `mtf:run`, `POST /api/mtf/run` et le
  Temporal scheduler ne sont pas branchés sur Hyperliquid par cette PR ;
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
