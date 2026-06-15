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

Cette PR ne branche pas encore le resolver sur `mtf:run`, `POST /api/mtf/run`, TradeEntry, Temporal ou les gateways exchange.

Les fichiers sous `trading-app/config/trading/` sont des references inactives. Ils documentent la cible et gardent les protections suivantes :

- `dry_run: true` ;
- `live_enabled: false` ;
- `runtime_check_required: true` pour OKX et Hyperliquid ;
- Bitmart reste marque legacy tant que le runtime actuel en depend.

## Couches

- `base.yaml` est obligatoire.
- `mode/{mode}.yaml` est optionnel.
- `exchange/{exchange}.yaml` est optionnel.
- `mode_exchange/{mode}.{exchange}.yaml` est optionnel.
- `env/{env}.yaml` est optionnel.

Une couche optionnelle absente est ignoree et exposee dans `missing_optional_layers`. Une couche obligatoire absente leve `TradingConfigException`.

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
