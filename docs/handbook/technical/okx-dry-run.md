# OKX dry-run

## Statut

OKX est `target_dry_run_only`. OKX-007 prépare OKX en **dry-run / runtime-check uniquement** :
aucune exécution live, aucun branchement runtime, aucun ordre réel. La bascule live d'OKX
reste interdite et exigera une **PR de readiness live dédiée**, séparément relue.

Cette page complète :

- `technical/exchange-runtime-gates.md` (gates obligatoires avant tout live) ;
- `technical/exchange-readiness-matrix.md` (statut par exchange) ;
- [OKX demo readiness](okx-demo-readiness.md) (capability matrix OKX et ADR de readiness demo) ;
- `technical/fake-paper-gateway.md` (le filet de sécurité Fake / Paper).

## OkxDryRunExecutionPort

`App\TradingCore\Execution\Okx\OkxDryRunExecutionPort` est la deuxième implémentation de
`ExecutionPortInterface` (après `FakeExecutionPort`). Elle respecte strictement l'interface
existante : `execute(ExecutionRequest): ExecutionResult`.

C'est une **sérialisation locale / simulation TradingCore**, pas une exécution exchange réelle :

- **aucun appel HTTP**, jamais ;
- **aucun `privatePost` OKX** : le port ne touche ni `OkxExchangeAdapter::placeOrder()`,
  ni `OkxRestClient::privatePost()` ;
- les bodies sont construits localement via `OkxActionFactory`, sans client REST ;
- aucune dépendance Symfony, Doctrine, Messenger, Temporal ni provider runtime concret ;
- le port est **pur** : même plan ⇒ même `exchange_order_id`, requête jamais mutée.

Comportement :

| Cas | Résultat |
| --- | --- |
| `ExecutionMode::Live` | `ExecutionStatus::Rejected` (`live_not_supported_by_okx_dry_run`). |
| Plan d'un autre exchange (`exchange != okx`) | `Rejected` (`wrong_exchange_for_okx_dry_run`). |
| Market type non supporté (≠ `perpetual`) | `Rejected` (`market_type_not_supported_by_okx_dry_run`). |
| Plan non exécutable (revalidé via `OrderPlanValidator`) | `Rejected` (`order_plan_not_executable` + `invalid_reasons`). |
| `environment=mainnet` ou `live` | `Rejected` (`mainnet_environment_forbidden_for_okx_dry_run`). |
| Symbole hors `allowed_symbols` fourni | `Rejected` (`demo_trading_safety_blocked`). |
| Notional au-dessus de `max_notional` fourni | `Rejected` (`demo_trading_safety_blocked`). |
| Levier au-dessus de `max_leverage` fourni | `Rejected` (`leverage_cap_exceeded`). |
| Plan OKX perpetual valide en dry-run | `ExecutionStatus::DryRun`, `exchange_order_id = OKX-DRYRUN-{client_order_id}`. |

Metadata produite (success) : `gateway=okx`, `mode=dry_run`, `simulated=true`, `no_http=true`,
`no_private_post=true`, `environment`, `client_order_id`, `idempotency_key`, `requested_at`,
`order_type`, `side`, `symbol`, `entry_price`, `quantity`, `leverage`, `notional`,
`protection_present`, `safety_decision`, `private_observability_decision`,
`local_dry_run_ready=true` et `readiness_level=local_dry_run_ready`. Les descripteurs
gateway (`gateway`, `simulated`, `no_http`, `no_private_post`) et le `reject_reason` sont
autoritaires : un appelant ne peut pas les usurper via la metadata entrante.

`client_order_id` et `idempotency_key` sont conservés sur tous les chemins.

## Payloads sérialisés OKX-007

Sur un plan valide, `ExecutionResult::raw['okx_dry_run']` contient uniquement une trace
redacted et non mutative :

```json
{
  "okx_dry_run": {
    "no_http": true,
    "no_private_post": true,
    "redacted": true,
    "requests": [
      {
        "operation": "set_leverage",
        "method": "POST",
        "path": "/api/v5/account/set-leverage",
        "body": {
          "instId": "BTC-USDT-SWAP",
          "lever": "5",
          "mgnMode": "isolated",
          "posSide": "long"
        }
      },
      {
        "operation": "submit_order",
        "method": "POST",
        "path": "/api/v5/trade/order",
        "body": {
          "instId": "BTC-USDT-SWAP",
          "tdMode": "isolated",
          "clOrdId": "CIDOKX1",
          "side": "buy",
          "posSide": "long",
          "ordType": "limit",
          "sz": "12",
          "reduceOnly": "false",
          "px": "100"
        }
      },
      {
        "operation": "stop_loss",
        "method": "POST",
        "path": "/api/v5/trade/order-algo",
        "body": {
          "instId": "BTC-USDT-SWAP",
          "tdMode": "isolated",
          "algoClOrdId": "CIDOKX1SL",
          "side": "sell",
          "posSide": "long",
          "ordType": "conditional",
          "sz": "12",
          "reduceOnly": "true",
          "slTriggerPx": "98",
          "slOrdPx": "-1",
          "slTriggerPxType": "mark"
        }
      }
    ]
  }
}
```

Pour `marginMode=isolated`, deux payloads `set_leverage` sont produits (`long` et `short`)
afin de rendre la preview explicite pour le mode hedge OKX. Le take-profit `tp1Price`, s'il
est présent dans le `ProtectionPlan`, est sérialisé en `operation=take_profit` via
`/api/v5/trade/order-algo`.

Les secrets présents par erreur dans la metadata entrante (`OKX_DEMO_API_KEY`,
`OK-ACCESS-SIGN`, `Authorization`, tokens, passphrases, cookies, signatures, credentials)
sont redacted avant exposition dans `metadata` ou `raw`.

## Exemple d'audit dry-run

Metadata d'appel minimale pour exercer les guards sans envoyer d'ordre :

```php
ExecutionRequest::forPlan($orderPlan, ExecutionMode::DryRun, [
    'environment' => 'demo',
    'allowed_symbols' => ['BTCUSDT'],
    'max_notional' => 2000.0,
    'max_leverage' => 10,
]);
```

La décision `DemoTradingSafetyPolicy` reste auditée dans `metadata.safety_decision`.
`ExchangePrivateObservabilityPolicy` est appelée en mode dry-run informatif : une
observabilité privée absente ajoute un warning, mais ne bloque pas la sérialisation locale.
Elle bloquera seulement les futures PRs mutatives `dry_run=false`.

## Runtime-check : OKX reste dry-run only

`app:exchange:runtime-check okx perpetual` durcit la gate OKX :

- `Live trading: disabled` — OKX ne peut pas passer live en PR11, même si
  `OKX_DEMO_TRADING_ENABLED=1` ou `OKX_LIVE_ENABLED=1` ;
- `Dry-run only: yes` ;
- `Live allowed: no` ;
- `Demo trading enabled: yes/no` — capacité demo OKX, **distincte** de l'autorisation live ;
- `Readiness level: ...` — niveau structuré issu de `OkxRuntimeCheck` ;
- `Readiness blocking errors: ...` et `Readiness warnings: ...` — raisons opérables, redacted ;
- `Mainnet write guard: yes/no` ;
- `Demo/testnet write guard: yes/no` ;
- `Stop loss capability: yes/no` ;
- `Kill switch: enabled/disabled` ;
- `Recommended dry_run: true` (toujours, pour OKX).

Depuis OKX-008, `OkxRuntimeCheck` distingue explicitement :

- `local_dry_run_ready` quand les lectures, guards, whitelist/notional et la capacité SL sont
  prêts, mais que l'activation demo/testnet n'est pas explicitement ouverte ;
- `demo_testnet_candidate` quand `OKX_DEMO_TRADING_ENABLED=1` est présent et que le kill switch
  opérateur reste actif.

La commande reste read-only : elle force le runtime-check OKX en dry-run, ne retourne jamais
`demo_testnet_enabled`, `live_ready` ou `mainnet_ready`, et n'appelle aucun endpoint write. La
capacité « demo order » d'OKX n'est donc jamais assimilée à « live allowed ». Bitmart legacy
n'est pas affecté : ces lignes sont spécifiques à OKX.

La readiness publique OKX n'est pas déduite de la simple présence des services : la commande
sonde `ContractProviderInterface::getContracts()` et exige au moins un instrument public validé
avant de marquer `instruments_loaded`, `metadata_valid` et `precision_valid`. `Schedule ready:
yes` est réservé à `local_dry_run_ready` ou `demo_testnet_candidate`.

Exemple :

```text
Exchange: okx
Market type: perpetual
Adapter: found
Provider bundle: found
Credentials: ok
REST: unknown
Private WS: enabled
Live trading: disabled
Dry-run only: yes
Live allowed: no
Demo trading enabled: yes
Readiness level: demo_testnet_candidate
Readiness blocking errors: none
Readiness warnings: private_observability_absent_for_dry_run
Mainnet write guard: yes
Demo/testnet write guard: yes
Stop loss capability: yes
Kill switch: enabled
Recommended dry_run: true
Schedule ready: yes
```

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

OKX-008 : runtime-check `demo_testnet_candidate`. La bascule live OKX restera une PR dédiée,
testée et réversible.
