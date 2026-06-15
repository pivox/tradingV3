# Décision architecture Trading Platform Core

## Statut

Décision validée.

TradingV3 adopte l'architecture cible **Option C — Trading Platform Core + OMS/EMS + CEX/DEX Gateways** comme direction officielle pour la prochaine refonte majeure.

Cette décision complète la page [Architecture cible Trading Platform Core](trading-platform-target-architecture.md). La page cible conserve le détail des options étudiées, tandis que ce document fixe la décision retenue.

## Option retenue

```text
Symfony modular monolith
+ Trading Core
+ Strategy Engine
+ Risk Engine
+ OMS
+ EMS
+ CEX Gateways
+ DEX Gateways
+ Position Manager
+ Analytics Engine
+ Event Store léger
+ Temporal pour l'orchestration
+ Symfony pour API / Ops / front
```

## Raisons de la décision

L'option C est retenue parce qu'elle correspond le mieux à la trajectoire TradingV3 :

- plusieurs CEX : Bitmart, OKX, Hyperliquid, Binance, Fake/Paper exchange ;
- plusieurs DEX/protocoles futurs : Uniswap, 0x, 1inch, EVM/RPC ;
- besoin d'une séparation claire entre stratégie, risque, ordre, exécution, position et analytics ;
- besoin d'éviter la complexité prématurée d'une architecture microservices complète ;
- besoin de permettre une migration progressive depuis le code Symfony actuel ;
- besoin de garder une console Ops et des workflows Temporal cohérents ;
- besoin de mesurer l'amélioration réelle via expectancy nette et analyse post-trade.

## Conséquences directes

### 1. Le Runner doit devenir plus mince

Le Runner garde l'orchestration du cycle, mais les responsabilités doivent progressivement sortir vers des services dédiés :

```text
MtfRunnerService
├── SymbolUniverseResolver
├── ExchangeStateSynchronizer
├── OpenActivityFilter
├── MtfExecutionDispatcher
├── PostRunProjectionDispatcher
└── RunResultAssembler
```

### 2. TradeEntry doit être découpé progressivement

Le module actuel reste utile, mais sa responsabilité doit être redistribuée vers :

```text
Order Planning
Execution Management
Position Protection
Order Lifecycle / OMS
```

### 3. CEX et DEX ne partagent pas le même modèle bas niveau

Un CEX manipule des ordres.

Un DEX manipule des quotes, approvals, simulations et transactions.

Le modèle commun doit rester au niveau intention :

```text
ExecutionIntent
```

Les modèles bas niveau restent séparés :

```text
CexOrderRequest
SwapExecutionPlan
```

### 4. Risk-first devient une frontière obligatoire

Aucun ordre live ne doit passer sans `RiskDecision` explicite.

Le risque doit être auditable avant l'exécution :

```text
SignalIntent -> RiskDecision -> OrderPlan -> ExecutionRoute -> Gateway
```

### 5. OMS et EMS deviennent deux modules distincts

L'OMS garde la vérité métier de l'ordre : état, idempotence, transitions, corrélations.

L'EMS choisit le chemin d'exécution : exchange, protocole, maker/taker, slippage, fallback, gas/MEV si DEX.

### 6. Position Manager devient autonome

La gestion des positions ouvertes ne doit plus dépendre du Runner.

Le Position Manager doit gérer :

- SL automatique ;
- TP ;
- trailing ;
- time-stop ;
- réparation des protections manquantes ;
- alerte critique position sans SL ;
- fermeture d'urgence.

### 7. Analytics devient une boucle de décision

Les évolutions futures doivent être mesurées par :

- expectancy nette ;
- winrate ;
- profit factor ;
- PnL R ;
- MFE ;
- MAE ;
- frais ;
- slippage ;
- performance par profil/exchange/symbole/timeframe.

La vue `position_trade_analysis` ou son successeur doit rester la source centrale pour juger les modifications de stratégie et de configuration.

## Invariants validés

- Strategy ne dépend jamais d'un exchange concret.
- Risk ne dépend jamais d'un payload exchange.
- OMS garde la vérité métier des ordres.
- EMS choisit le chemin d'exécution.
- Gateway traduit vers l'exchange ou le protocole.
- Position Manager protège et suit les positions.
- Analytics mesure avant d'augmenter la fréquence de trading.
- Aucune activation live sans runtime-check OK.
- Aucun trade live sans risque validé.
- Aucune position live sans SL automatique visible.
- Toute décision doit être corrélable par `run_id`, `decision_key`, `client_order_id`, `exchange_order_id` ou `trade_id`.

## Plan de migration validé à haut niveau

1. Documentation et cadrage.
2. `EffectiveTradingConfigResolver`.
3. Extraction progressive du Runner.
4. Introduction `SignalIntent` et `RiskDecision`.
5. OMS minimal.
6. EMS minimal.
7. Position Manager autonome.
8. Gateway Bitmart stricte.
9. Gateways OKX/Hyperliquid en dry-run jusqu'à readiness complète.
10. DEX gateway en quote/simulation uniquement avant tout live.
11. Analytics feedback loop.

## Non-décisions

Cette validation ne décide pas encore :

- le nom final des namespaces PHP ;
- le schéma exact des tables OMS ;
- le niveau exact d'event store ;
- le premier DEX à intégrer ;
- le calendrier de migration ;
- les écrans Ops exacts associés à chaque module.

Ces points seront traités par PR atomiques ou ADR dédiées.
