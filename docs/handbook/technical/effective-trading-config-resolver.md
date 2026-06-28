# Effective Trading Config Resolver

## Objectif

`EffectiveTradingConfigResolver` introduit une resolution de configuration en couches pour le futur TradingCore modulaire, sans changer le runtime actuel.

L'ordre de resolution est deterministe :

```text
base
< mode
< exchange
< mode_exchange
< env
= effective config
```

Exemple :

```text
config/trading/base.yaml
config/trading/mode/scalper.yaml
config/trading/exchange/okx.yaml
config/trading/mode_exchange/scalper.okx.yaml
config/trading/env/dev.yaml
```

## Statut runtime

COMMON-002 ne branche pas encore le resolver sur `mtf:run`, `POST /api/mtf/run`,
TradeEntry, Temporal ou les gateways exchange.

Les fichiers sous `trading-app/config/trading/` sont des references inactives. Ils
documentent la cible et gardent les protections suivantes :

- `dry_run: true` ;
- `live_enabled: false` ;
- `mainnet_write_enabled: false` ;
- `demo_testnet_write_enabled: false` par defaut ;
- `kill_switch_enabled: true` ;
- `require_stop_loss: true` ;
- `allowed_symbols` ou `allowed_markets` renseigne pour OKX demo et Hyperliquid testnet ;
- `max_notional` renseigne ;
- `runtime_check_required: true` pour OKX et Hyperliquid ;
- Bitmart reste marque legacy tant que le runtime actuel en depend.

## Couches

- `base.yaml` est obligatoire.
- `mode/{mode}.yaml` est optionnel.
- `exchange/{exchange}.yaml` est optionnel.
- `mode_exchange/{mode}.{exchange}.yaml` est optionnel.
- `env/{env}.yaml` est optionnel.

Une couche optionnelle absente est ignoree et exposee dans `missing_optional_layers`. Une couche obligatoire absente leve `TradingConfigException`.

Les couches demo/testnet ajoutees par COMMON-002 sont :

- `env/demo.yaml` ;
- `env/testnet.yaml` ;
- `exchange/okx.yaml` ;
- `exchange/hyperliquid.yaml` ;
- `mode_exchange/{regular,scalper,scalper_micro}.okx.yaml` ;
- `mode_exchange/{regular,scalper,scalper_micro}.hyperliquid.yaml`.

Les secrets ne sont pas stockes dans ces fichiers. Les credentials demo/testnet
restent des variables d'environnement dediees et ne sont pas serialisees dans la
config effective.

## API read-only

COMMON-002 expose une surface read-only pour inspecter la config effective :

```bash
curl 'http://localhost:8082/api/trading/config/effective?mode=scalper&exchange=okx&env=demo'
```

Exemple Hyperliquid testnet :

```bash
curl 'http://localhost:8082/api/trading/config/effective?mode=scalper&exchange=hyperliquid&env=testnet'
```

La reponse contient :

- `request` : couple `mode`, `exchange`, `env` demande ;
- `config` : configuration effective resolue ;
- `config_hash` : hash SHA-256 stable de la config normalisee ;
- `layers` : couches utilisees dans l'ordre ;
- `missing_optional_layers` : couches optionnelles absentes ;
- `provenance` : provenance par chemin de valeur, par exemple
  `trading.execution.max_notional`.

Erreurs structurees :

- `400 missing_query_parameter` si `mode`, `exchange` ou `env` manque ;
- `400 invalid_config_request` si une couche est invalide, non parseable ou si
  la paire `exchange/env` est interdite.

Paires supportees par COMMON-002 :

- `exchange=okx&env=demo` ;
- `exchange=hyperliquid&env=testnet`.

Les paires croisees comme `exchange=okx&env=testnet` ou
`exchange=hyperliquid&env=demo` sont refusees avant resolution pour eviter une
config effective incoherente. Les exchanges inconnus et Bitmart via cet endpoint
sont egalement refuses : Bitmart reste legacy et n'est pas re-route par l'API
COMMON-002.

L'API ne modifie aucun etat et ne contacte aucun exchange.

Le viewer Effective Config reste un follow-up separe : aucune integration runtime
ou front n'est activee dans COMMON-002.

## Exemples de resolution

OKX demo scalper :

```text
base
< mode/scalper
< exchange/okx
< mode_exchange/scalper.okx
< env/demo
= trading.environment: demo
= trading.execution.mainnet_write_enabled: false
= trading.execution.demo_testnet_write_enabled: false
= trading.execution.kill_switch_enabled: true
```

Hyperliquid testnet scalper :

```text
base
< mode/scalper
< exchange/hyperliquid
< mode_exchange/scalper.hyperliquid
< env/testnet
= trading.environment: testnet
= trading.execution.mainnet_write_enabled: false
= trading.execution.demo_testnet_write_enabled: false
= trading.execution.kill_switch_enabled: true
```

## YAML historiques actuellement utilises

Regular :

- TradeEntry : `trading-app/config/app/trade_entry.regular.yaml`
- Validations MTF : `trading-app/src/MtfValidator/config/validations.regular.yaml`
- Contrats MTF : fallback `trading-app/config/app/mtf_contracts.yaml`

Scalper :

- TradeEntry : `trading-app/config/app/trade_entry.scalper.yaml`
- Validations MTF : `trading-app/src/MtfValidator/config/validations.scalper.yaml`
- Contrats MTF : fallback `trading-app/config/app/mtf_contracts.yaml`

Scalper micro :

- TradeEntry : `trading-app/config/app/trade_entry.scalper_micro.yaml`
- Validations MTF : `trading-app/src/MtfValidator/config/validations.scalper_micro.yaml`
- Contrats MTF : `trading-app/config/app/mtf_contracts.scalper_micro.yaml`

Crash existe aussi comme profil historique, mais il n'est pas dans le scope de la PR 01 :

- TradeEntry : `trading-app/config/app/trade_entry.crash.yaml`
- Validations MTF : `trading-app/src/MtfValidator/config/validations.crash.yaml`

## Chargement actuel

Le runtime actuel charge encore les YAML via les providers historiques :

- `App\Config\TradeEntryConfigProvider`
- `App\Config\MtfValidationConfigProvider`
- `App\Config\MtfContractsConfigProvider`

Le nouveau resolver reste donc une brique preparatoire. Les PR suivantes pourront comparer puis brancher progressivement la config effective sans modifier les strategies dans cette PR.
