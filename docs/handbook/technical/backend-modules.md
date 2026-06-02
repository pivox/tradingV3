# Modules Backend

Le backend Symfony contient environ 683 fichiers PHP sous `trading-app/src`.

## Modules applicatifs

| Module | Role | Points d'entree |
| --- | --- | --- |
| `MtfRunner` | Orchestration des runs MTF, filtrage, reporting. | `MtfRunnerService`, `RunnerController` |
| `MtfValidator` | Validation YAML/conditions et selection execution. | `MtfValidatorService`, `MtfValidatorCoreService` |
| `TradeEntry` | Construction et execution de plans d'ordre. | `TradeEntryService`, workflows, handlers |
| `Indicator` | Calculs, snapshots, contexte et conditions. | `IndicatorProviderService`, `ConditionRegistry` |
| `Provider` | Acces donnees marche et Bitmart legacy. | `MainProvider`, providers Bitmart |
| `Exchange` | Abstraction multi-exchange. | `ExchangeAdapterRegistry`, adapters |
| `Runtime` | Locks, switches, audit et cache. | `FeatureSwitch`, `LockManager`, audit logger |
| `Trading` | Stockage et analyse de positions/trades. | repositories, sync |
| `Front` | Interface Ops Twig. | controllers `/app/*` |
| `Signal` | Ancien pipeline signaux, a documenter comme legacy actif si encore appele. | services TF, persistence |

## Exchanges

| Adapter | Emplacement | Statut |
| --- | --- | --- |
| Bitmart | `trading-app/src/Exchange/Adapter`, `trading-app/src/Provider/Bitmart` | Exchange principal historique. |
| OKX | `trading-app/src/Exchange/Okx` | Adapter present et teste. |
| Hyperliquid | `trading-app/src/Exchange/Hyperliquid` | Adapter present et teste. |
| Fake | `trading-app/src/Exchange/Fake` | Simulation et tests d'execution. |

## Conditions MTF

Les conditions sont des classes PHP dans `trading-app/src/Indicator/Condition`.
Elles sont consommees par les profils YAML et couvertes par des tests dans `trading-app/tests/Indicator/Condition`.

Familles principales:

- RSI bullish/bearish et soft floors/caps;
- MACD histogram;
- EMA/SMA/VWAP proximity;
- ATR relatif et bornes en bps;
- ADX tendance;
- volume ratio;
- spread;
- entry zone width;
- expected R multiple.

## Tests existants

Les tests sont organises par domaine:

- `tests/Exchange`;
- `tests/Indicator`;
- `tests/MtfRunner`;
- `tests/MtfValidator`;
- `tests/Runtime`;
- `tests/TradeEntry`;
- `tests/Front`.
