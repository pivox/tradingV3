# Exchange readiness consolidation

## Objectif

Cette page consolide l'état exchange après :

- PR #129, `feat: add exchange profile runtime schedules` ;
- PR #141, architecture cible TradingCore ;
- PR #142, `EffectiveTradingConfigResolver` et couches `config/trading/*`.

Elle ne branche pas la config effective au runtime et ne rend aucun nouvel exchange prêt au live. Elle sert à distinguer ce qui existe déjà, ce qui est partiel, ce qui manque, ce qui est interdit en live et ce qui doit être validé avant toute activation.

## Sources vérifiées

- `trading-app/src/MtfRunner/Dto/MtfRunnerRequestDto.php`
- `trading-app/src/Command/ExchangeRuntimeCheckCommand.php`
- `cron_symfony_mtf_workers/scripts/manage_exchange_profile_schedule.py`
- `cron_symfony_mtf_workers/README.md`
- `docs/handbook/technical/exchange-readiness-matrix.md`
- `docs/handbook/technical/exchange-runtime-gates.md`
- `docs/handbook/technical/exchange-schedule-policy.md`
- `trading-app/config/trading/exchange/*.yaml`
- `trading-app/config/trading/env/dev.yaml`
- `trading-app/config/trading/env/prod.yaml`
- `config_file/dev.env`
- `config_file/prod.env`
- adapters `FakeExchangeAdapter`, `OkxExchangeAdapter`, `HyperliquidExchangeAdapter`, `BitmartExchangeAdapter`
- registries `ExchangeAdapterRegistry` et `ExchangeProviderRegistry`

## Déjà présent via PR #129

| Élément | Statut |
|---|---|
| `MtfRunnerRequestDto` accepte l'enum `Exchange` | Présent. `Exchange::tryFrom()` accepte `bitmart`, `binance`, `fake`, `hyperliquid`, `okx`. |
| Payload Temporal explicite | Présent. Le script générique envoie `exchange`, `market_type`, `mtf_profile`, `workers`, `dry_run`. |
| Schedule manager exchange/profile | Présent dans `manage_exchange_profile_schedule.py`. |
| Defaults dry-run | Présents. `dry_run=true` est le défaut, y compris Bitmart. |
| Guardrails live | Présents. `dry_run=false` exige `Schedule ready: yes`, credentials `ok` et `Live trading: enabled`, sauf bypass explicite. |
| Commande runtime-check | Présente : `php bin/console app:exchange:runtime-check <exchange> <market_type>`. |
| Docs runtime matrix | Présentes dans le README Temporal et les pages handbook existantes. |

## Présent mais partiel

| Élément | État partiel |
|---|---|
| Adapters OKX/Hyperliquid | Présents, avec REST private/public, order placement/cancel, balance, positions et reconciliation REST. Ils restent dry-run/runtime-check, pas live. |
| Provider bundles OKX/Hyperliquid/Fake | Manquants dans `ExchangeProviderRegistry`. Le registry ne déclare que Bitmart perpetual/spot. |
| WebSocket OKX | Normalizer privé présent, mais pas de client privé réel ; l'adapter n'annonce pas `supportsWebSocketPrivate`. |
| WebSocket Hyperliquid | URI/config présents, mais pas de client WS privé ; l'adapter n'annonce pas `supportsWebSocketPrivate`. |
| Fake/Paper | Adapter, state store, matching engine et WS fake présents. Le provider bundle MTF reste manquant. |
| TradingCore config PR #142 | Couches YAML présentes, mais non branchées au runtime MTF/TradeEntry. |
| Bitmart reconciliation | Runtime legacy présent ; la méthode adapter indique encore `legacy_bitmart_reconciliation_not_wired_yet` pour son résultat direct. |

## Manquant

- Provider bundles `fake::perpetual`, `okx::perpetual` et `hyperliquid::perpetual`.
- Branchement runtime de `EffectiveTradingConfigResolver`.
- Validation réelle `app:exchange:runtime-check` avec credentials et endpoints exchange.
- Clients WebSocket privés OKX/Hyperliquid utilisables en runtime.
- Validation SL/TP attach/reconciliation de bout en bout sur OKX/Hyperliquid.
- Politique de live explicite par PR dédiée.
- Inventaire de retrait Bitmart et migration des dépendances legacy.

## Interdit en live

- OKX live.
- Hyperliquid live.
- Tout schedule `dry_run=false` OKX/Hyperliquid.
- Toute activation live par simple présence de credentials.
- Suppression de Bitmart dans cette PR.
- Branchement de `EffectiveTradingConfigResolver` au runtime dans cette PR.
- Modification de `mtf:run`, `POST /api/mtf/run`, Temporal runtime, TradeEntry, EntryZone, Risk/Leverage, SL/TP ou YAML stratégie.

## Matrice exchange

| Exchange | Statut cible | Statut runtime actuel | Dry-run autorisé | Live autorisé | Adapter présent | Provider bundle présent | Credentials attendus | Runtime-check disponible | WebSocket public | WebSocket privé | Order placement | Order cancel | Position fetch | Balance fetch | SL/TP attach | Reconciliation | Audit/logging | Temporal schedule | Risques | Prochaines actions |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Fake/Paper | Target test gateway | Partiel : adapter simulation présent, provider MTF manquant | Oui | Non | Oui, `FakeExchangeAdapter` | Non | `FAKE_EXCHANGE_ENABLED`, `FAKE_EXCHANGE_INITIAL_BALANCE_USDT`, `FAKE_EXCHANGE_FEES_ENABLED`, `FAKE_EXCHANGE_SLIPPAGE_BPS` | Oui, mais `Schedule ready` reste bloqué tant que le provider bundle manque | Non requis ; simulation locale | Oui côté fake/capability | Oui, simulé | Oui, simulé | Oui, simulé | Oui, simulé | Oui, simulé via capabilities | Oui, simulée | Partiel via events/projections | Oui avec `manage_exchange_profile_schedule.py`, simulation/dry-run seulement | Confusion possible entre adapter prêt et pipeline MTF prêt | Ajouter le provider bundle fake ou clarifier le bypass simulation ; valider un run dry-run complet sans Bitmart |
| OKX | Target gateway, dry-run only | Partiel : provider MTF public/private read-only présent, writes demo encore gardés | Oui, après runtime-check ou schedule dry-run avec warning | Non | Oui, `OkxExchangeAdapter` | Non | `OKX_ENV`, `OKX_DEMO_API_KEY`, `OKX_DEMO_API_SECRET`, `OKX_DEMO_API_PASSPHRASE`, `OKX_SIMULATED_TRADING`, `OKX_API_BASE_URI`, `OKX_WS_PUBLIC_URI`, `OKX_WS_PRIVATE_URI`, `OKX_DEMO_TRADING_ENABLED`, `OKX_LIVE_ENABLED` | Oui | Config/REST public présents ; client WS public non consolidé | REST privé read-only présent, normalizers présents, client WS privé non consolidé | Implémenté REST, à garder dry-run/demo uniquement | Implémenté REST | Implémenté REST | Implémenté REST | Pas attaché sur entrée ; trigger orders disponibles mais workflow à valider | REST snapshot présent | Partiel via events/reconciliation | Oui, dry-run seulement | `OKX_DEMO_TRADING_ENABLED` ou credentials peuvent donner une fausse impression de readiness | Compléter metadata/funding, client WS privé, validation SL/TP/reconciliation, puis PR live séparée si besoin |
| Hyperliquid | Target gateway, dry-run only | Partiel : adapter REST présent, provider MTF manquant | Oui, après runtime-check ou schedule dry-run avec warning | Non | Oui, `HyperliquidExchangeAdapter` | Non | `HYPERLIQUID_ENV`, `HYPERLIQUID_PRIVATE_KEY`, `HYPERLIQUID_ACCOUNT_ADDRESS`, `HYPERLIQUID_API_BASE_URI`, `HYPERLIQUID_WS_URI`, `HYPERLIQUID_MAINNET_ENABLED` | Oui | Config URI présente ; pas de client WS runtime consolidé | Manquant, capability false | Implémenté au niveau adapter, mais le client par défaut ne signe pas le live | Implémenté au niveau adapter | Implémenté REST info | Implémenté REST info | Pas attaché sur entrée ; trigger/protection à valider selon modèle Hyperliquid | REST snapshot présent | Partiel via events/reconciliation | Oui, dry-run seulement | Client par défaut indique que le signing live n'est pas activé ; modèle USDC/symboles différent | Ajouter provider bundle, client signé non-live/testnet, WS ou équivalent, validation protections/reconciliation |
| Bitmart | Legacy runtime only, to remove later | Présent, historique | Oui si déjà supporté | Legacy uniquement ; pas cible future | Oui, `BitmartExchangeAdapter` | Oui, perpetual et spot legacy | `BITMART_API_KEY`, `BITMART_SECRET_KEY`, `BITMART_API_MEMO`, `BITMART_BASE_URL`, `BITMART_PRIVATE_API_URL`, `BITMART_PUBLIC_API_URL`, `BITMART_WS_PRIVATE_URL` | Oui | Oui, legacy | Oui, legacy | Oui, legacy | Oui, legacy | Oui, legacy | Oui, legacy | Oui, legacy | Partiel/direct adapter legacy ; service reconciliation disponible | Oui, legacy logs existants | Oui, legacy et dry-run par défaut dans le nouveau script | Dépendances runtime encore fortes ; ne pas le supprimer trop tôt | Inventorier les dépendances Bitmart, maintenir compatibilité, retirer seulement par PR dédiée |

## Config TradingCore PR #142

| Fichier | Décision de consolidation |
|---|---|
| `config/trading/exchange/fake.yaml` | Confirme `paper_only`, `dry_run: true`, `live_enabled: false`. Fake/Paper est la gateway de sécurité. |
| `config/trading/exchange/okx.yaml` | Confirme `dry_run_runtime_check_only`, `runtime_check_required: true`, `live_enabled: false`. OKX n'est pas live-ready. |
| `config/trading/exchange/hyperliquid.yaml` | Confirme `dry_run_runtime_check_only`, `runtime_check_required: true`, `live_enabled: false`. Hyperliquid n'est pas live-ready. |
| `config/trading/exchange/bitmart.yaml` | Confirme `legacy_runtime_only` et `legacy_runtime_dependency: true`. Bitmart reste à conserver. |
| `config/trading/env/dev.yaml` | Dev reste `dry_run: true`, `live_enabled: false`. |
| `config/trading/env/prod.yaml` | Prod reste `dry_run: true`, `live_enabled: false`, `runtime_check_required: true`. |
| `config_file/dev.env` et `config_file/prod.env` | Listent les clés attendues sans secrets. Les clés OKX/Hyperliquid/Fake existent, Bitmart reste listé en legacy. |

Ces configs ne changent pas le runtime aujourd'hui. Elles documentent la cible de résolution effective et les clés d'environnement attendues.

## À valider avant live

Avant toute PR live OKX ou Hyperliquid :

1. Fake/Paper doit exécuter un dry-run complet et auditable.
2. Le provider bundle de l'exchange cible doit être déclaré et testé.
3. `app:exchange:runtime-check <exchange> perpetual` doit sortir `Schedule ready: yes`.
4. Les credentials doivent être présents hors Git et validés.
5. Les flags live doivent rester désactivés jusqu'à PR dédiée.
6. Le WebSocket privé, ou un mécanisme équivalent, doit prouver les ordres, fills et positions.
7. `balance fetch` et `position fetch` doivent être validés sur environnement non-live.
8. `order placement` et `order cancel` doivent être validés en dry-run/demo/testnet.
9. SL/TP attach et reconciliation doivent être prouvés sur traces.
10. L'audit minimal doit couvrir décisions, rejets, ordres, fills, protections et reconciliation.
11. Les schedules Temporal doivent rester explicites : `exchange`, `market_type`, `mtf_profile`, `dry_run`.
12. Une procédure de rollback vers Fake/Paper doit être documentée.

## Décision PR 02

PR 02 est une consolidation documentaire. Elle ne doit pas :

- modifier `mtf:run` ;
- modifier `POST /api/mtf/run` ;
- modifier le runtime Temporal ;
- activer OKX live ;
- activer Hyperliquid live ;
- supprimer Bitmart ;
- brancher `EffectiveTradingConfigResolver` au runtime ;
- modifier TradeEntry, EntryZone, Risk/Leverage, SL/TP ;
- changer les YAML stratégie `regular`, `scalper` ou `scalper_micro`.

La prochaine étape technique sûre est de faire passer Fake/Paper au statut de gateway de sécurité réellement runnable par le pipeline MTF, puis seulement ensuite de traiter OKX/Hyperliquid en dry-run/runtime-check.
