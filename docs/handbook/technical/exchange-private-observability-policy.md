# Exchange private observability policy

## Objectif

`COMMON-006` definit la politique commune d'observabilite privee avant toute
execution mutative demo/testnet.

Cette policy ne se connecte a aucun exchange, n'ouvre aucun socket, ne place aucun
ordre et ne lit aucun secret. Elle evalue uniquement un statut deja collecte par le
runtime ou par une fixture de test.

## Statut canonique

`ExchangePrivateObservabilityStatus` expose les champs suivants :

| Champ | Sens |
|---|---|
| `exchange` | Exchange evalue. |
| `environment` | Environnement runtime (`demo`, `testnet`, etc.). |
| `private_ws_supported` | Le connecteur sait exposer un canal prive. |
| `private_ws_connected` | Le canal prive est connecte. |
| `private_ws_authenticated` | Le canal prive est authentifie. |
| `orders_stream_ready` | Les evenements d'ordres sont observables. |
| `fills_stream_ready` | Les fills sont observables. |
| `positions_stream_ready` | Les positions sont observables. |
| `initial_snapshot_loaded` | Le snapshot initial account/orders/positions est charge. |
| `last_event_at` | Dernier evenement prive observe, si connu. |
| `reconnecting` | Le canal est en reconnexion. |
| `reconciliation_fresh` | La reconciliation privee est fraiche. |
| `blocking_errors` | Erreurs explicites deja connues par le collecteur. |
| `warnings` | Warnings non bloquants. |

Les champs `blocking_errors` et `warnings` sont redacted avant serialization si un
message contient un terme sensible (`api_key`, `secret`, `private_key`,
`passphrase`, `token`, `signature`, etc.).

## Regles

Dry-run local :

- autorise sans WebSocket prive ;
- loggable avec `private_observability_absent_for_dry_run` ;
- ne valide jamais une execution mutative.

Demo/testnet mutatif (`dry_run=false`) :

- bloque si le statut prive est absent ;
- bloque si le statut prive ne correspond pas au couple exchange/environnement
  de la tentative ;
- bloque si le WebSocket prive n'est pas supporte, connecte ou authentifie ;
- bloque pendant une reconnexion ;
- bloque si le snapshot initial est absent ;
- bloque si les streams ordres, fills ou positions ne sont pas prets ;
- bloque si la reconciliation n'est pas fraiche ;
- conserve toutes les erreurs explicites du statut.

Erreurs canoniques :

- `private_observability_status_missing`
- `private_observability_exchange_mismatch`
- `private_observability_environment_mismatch`
- `private_ws_not_supported`
- `private_ws_not_connected`
- `private_ws_not_authenticated`
- `private_orders_stream_not_ready`
- `private_fills_stream_not_ready`
- `private_positions_stream_not_ready`
- `private_observability_initial_snapshot_missing`
- `private_observability_reconnecting`
- `private_reconciliation_stale`

## Integration runtime

`ExchangeReadinessEvaluator` peut produire `demo_testnet_candidate` en dry-run
meme sans socket prive, mais ajoute un warning d'observabilite. Il ne peut produire
`demo_testnet_enabled` que si `ExchangePrivateObservabilityPolicy` autorise le
statut fourni.

`DemoTradingKillSwitchService` applique la meme policy sur toute tentative
mutative. Si `DemoTradingMutationAttempt.privateObservabilityStatus` est absent, la
decision est fail-closed et l'audit contient `private_observability.status=missing`.

## OKX et Hyperliquid

OKX reste non mutatif tant que le statut OKX demo ne prouve pas :

- socket prive demo supporte, connecte et authentifie ;
- streams ordres/fills/positions prets ;
- snapshot initial charge ;
- reconciliation fraiche ;
- absence de reconnexion.

Hyperliquid reste non mutatif tant que le statut testnet ne prouve pas les memes
garanties. Un fallback REST polling peut alimenter de la lecture ou de la
reconciliation read-only, mais il ne suffit pas seul pour `demo_testnet_enabled`
sans decision explicite et tests dedies dans une PR ulterieure.

## Rollback

Rollback immediat : garder `DEMO_TRADING_ENABLED=0`,
`OKX_DEMO_TRADING_ENABLED=0`, `HYPERLIQUID_TESTNET_TRADING_ENABLED=0` et
`trading.execution.kill_switch_enabled=true`.

Rollback applicatif : revenir au comportement de readiness/kill switch precedent.
La PR n'ajoute aucun appel reseau ni ordre exchange ; son retrait ne modifie donc
pas les flux dry-run OKX/Hyperliquid existants.
