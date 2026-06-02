# Configuration

## Fichiers applicatifs

| Fichier | Role |
| --- | --- |
| `trading-app/config/app/indicator.yaml` | Parametres indicateurs et calcul. |
| `trading-app/config/app/signal.yaml` | Parametres signaux legacy. |
| `trading-app/config/app/mtf_contracts.yaml` | Contrats MTF par defaut. |
| `trading-app/config/app/mtf_contracts.scalper_micro.yaml` | Contrats profil micro. |
| `trading-app/config/app/trade_entry.yaml` | Defaults TradeEntry. |
| `trading-app/config/app/trade_entry.regular.yaml` | Profil TradeEntry regular. |
| `trading-app/config/app/trade_entry.scalper.yaml` | Profil TradeEntry scalper. |
| `trading-app/config/app/trade_entry.scalper_micro.yaml` | Profil TradeEntry scalper micro. |
| `trading-app/config/app/trade_entry.crash.yaml` | Profil crash. |
| `trading-app/src/MtfValidator/config/validations.*.yaml` | Profils de validation MTF. |
| `trading-app/config/packages/messenger.yaml` | Transports et routage Messenger. |
| `trading-app/config/packages/monolog.yaml` | Logs par canal. |

## Selection de profil

Le profil est transmis:

- en HTTP et via les schedules Temporal avec la cle payload `mtf_profile`;
- en CLI avec l'option `--trade-profile`, par exemple `bin/console mtf:run --trade-profile=scalper_micro`.

Les providers de config resolvent ensuite:

- validation MTF: `MtfValidationConfigProvider`;
- trade entry: `TradeEntryConfigProvider`;
- contrats: `MtfContractsConfigProvider`.

## Variables d'environnement

| Variable | Usage |
| --- | --- |
| `DATABASE_URL` | PostgreSQL applicatif. |
| `REDIS_URL` | Redis locks/cache si active. |
| `MESSENGER_TRANSPORT_DSN` | Transport par defaut, complete par `messenger.yaml`. |
| `BITMART_API_KEY`, `BITMART_SECRET_KEY`, `BITMART_API_MEMO` | Acces Bitmart prive. |
| `BITMART_PUBLIC_API_URL`, `BITMART_PRIVATE_API_URL` | URLs REST. |
| `BITMART_WS_PRIVATE_URL`, `BITMART_WS_DEVICE` | WebSocket prive. |
| `TEMPORAL_ADDRESS`, `TEMPORAL_NAMESPACE`, `TASK_QUEUE_NAME` | Workers Temporal. |

## Points obsoletes a ne plus documenter comme actuels

- L'ancien fichier monolithique de trading n'existe plus dans le projet courant.
- L'ancien fichier unique de validations MTF n'existe plus; les validations sont dans `trading-app/src/MtfValidator/config/validations.*.yaml`.
- L'ancien service MTF monolithique n'existe plus; le flux courant passe par `MtfRunnerService` et `MtfValidatorCoreService`.
