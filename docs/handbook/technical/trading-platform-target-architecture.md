# Architecture cible Trading Platform Core

## Statut

Document de travail pour la refonte cible de TradingV3.

Cette page ne decrit pas uniquement le code existant. Elle definit la trajectoire cible : un coeur trading modulaire, des frontieres metier explicites, des gateways exchange isolees, un controle du risque strict et une analyse PnL systematique.

## Decision exchange

Bitmart est considere comme un provider historique/legacy a retirer de la cible.

Il peut rester present temporairement dans le code existant tant qu'une migration separee n'a pas ete faite, mais il ne doit plus etre le modele de reference pour :

- les DTOs metier ;
- les exemples d'architecture cible ;
- les gateways futures ;
- les tests de decision trading ;
- les fichiers de config cible.

Exchanges a conserver dans la cible :

```text
OKX
Hyperliquid
Fake / Paper exchange
```

Binance reste une option future a valider. Les DEX restent hors live pour l'instant et ne doivent commencer qu'en quote/simulation.

## Contexte actuel

TradingV3 possede deja une base utile :

```text
Runner -> Validator -> Decision -> TradeEntry -> Provider/Exchange
```

Le runner orchestre les runs MTF, le validateur produit des decisions, TradeEntry construit et execute les plans d'ordre, et les providers/adapters abstraient l'acces aux exchanges.

Cette base est coherente pour un systeme MTF/exchange unique, mais elle devient limitee pour une plateforme multi-exchange :

- le Runner porte trop de responsabilites operationnelles ;
- TradeEntry melange encore planification d'ordre, execution et gestion de protections ;
- le routage d'execution doit devenir un composant metier a part entiere ;
- l'analyse post-trade doit boucler vers la strategie et la configuration ;
- les DTOs metier ne doivent pas etre modeles sur un provider historique a retirer.

## Probleme a resoudre

La future architecture doit repondre a ces questions :

```text
Comment produire une intention de trading sans dependre d'un exchange concret ?
Comment valider le risque avant toute execution ?
Comment choisir entre OKX, Hyperliquid, Fake/Paper ou un futur gateway ?
Comment garantir qu'aucune position live ne reste sans protection ?
Comment mesurer si les changements ameliorent reellement l'expectancy nette ?
Comment retirer progressivement le provider historique sans casser le runtime ?
```

## Options etudiees

### Option A — Modular monolith hexagonal

```text
Symfony App
├── Trading Core
├── Strategy / MTF
├── Risk
├── Order Planning
├── Execution
├── Position Management
├── Analytics
└── Exchange Adapters
```

Avantages :

- simple a developper ;
- simple a tester ;
- simple a deployer ;
- coherent avec le code Symfony actuel ;
- bon choix pour une migration progressive.

Limites :

- le monolithe peut grossir si les gateways deviennent lourdes ;
- les WebSockets, workers et synchronisations peuvent augmenter la pression runtime ;
- une mauvaise discipline peut recréer un gros service central.

### Option B — Microservices complets

```text
Market Data Service
Strategy Service
Risk Service
OMS Service
EMS Service
Position Service
Analytics Service
Exchange Gateway Services
Temporal Orchestrator
Ops Front
```

Avantages :

- scalabilite maximale ;
- isolation forte des responsabilites ;
- extraction facile des gateways lourdes ;
- equipes separees possibles.

Limites :

- complexite tres elevee ;
- idempotence distribuee plus difficile ;
- observabilite plus couteuse ;
- debugging plus difficile ;
- versioning d'evenements necessaire ;
- overhead excessif tant que l'edge trading n'est pas prouve.

### Option C — Trading Core modulaire + OMS/EMS progressifs

```text
Symfony modular monolith
├── TradingCore
├── Risk Engine
├── OMS minimal
├── EMS minimal
├── Position Manager
├── Analytics Engine
├── Exchange Gateways
└── Event Store leger
```

Avantages :

- meilleure separation metier ;
- compatible avec une migration progressive ;
- suffisamment robuste pour OKX, Hyperliquid et Fake/Paper ;
- evite la complexite microservices trop tot ;
- prepare une extraction future des gateways si necessaire.

Limites :

- necessite une vraie discipline de frontieres ;
- impose de restructurer les modules existants ;
- demande des contrats metier stables.

## Decision recommandee

L'architecture cible recommandee est l'option C, mais avec une priorite court/moyen terme plus simple :

```text
TradingCore modulaire
+ Runner mince
+ MTF Validation
+ Entry
+ Risk / Leverage / SLTP
+ ExecutionPort
+ OKX Gateway
+ Hyperliquid Gateway
+ Fake/Paper Gateway
+ Analytics / Backtesting
```

L'objectif n'est pas de construire tout de suite une plateforme multi-CEX/multi-DEX complete. L'objectif est d'abord de stabiliser la decision trading et de mesurer l'expectancy nette.

## Vue cible

```text
                         ┌────────────────────┐
                         │      Ops Front      │
                         └─────────┬──────────┘
                                   │
                         ┌─────────▼──────────┐
                         │    Trading API     │
                         └─────────┬──────────┘
                                   │
                         ┌─────────▼──────────┐
                         │ Application Runner │
                         └─────────┬──────────┘
                                   │
        ┌──────────────────────────┼──────────────────────────┐
        │                          │                          │
┌───────▼────────┐       ┌─────────▼────────┐       ┌─────────▼────────┐
│ MTF / Strategy │       │   Risk Engine    │       │ Analytics Engine │
│ Validation     │       │ exposure / caps  │       │ PnL / winrate    │
└───────┬────────┘       └─────────┬────────┘       └─────────┬────────┘
        │                          │                          │
        └──────────────┬───────────┴──────────────┬───────────┘
                       │                          │
              ┌────────▼────────┐        ┌────────▼────────┐
              │   Order Plan    │        │ Position Manager│
              │ Entry / SL / TP │        │ SL/TP/trailing  │
              └────────┬────────┘        └────────┬────────┘
                       │                          │
              ┌────────▼──────────────────────────▼────────┐
              │                    EMS                      │
              │ routing, slippage, maker/taker, retries     │
              └────────┬──────────────────────────┬────────┘
                       │                          │
          ┌────────────▼────────────┐  ┌──────────▼───────────┐
          │      CEX Gateways       │  │      Future DEX POC   │
          │ OKX / Hyperliquid       │  │ quote / simulation    │
          │ Fake / Paper            │  │ no live initially     │
          └─────────────────────────┘  └──────────────────────┘
```

## Modules cibles

### 1. TradingCore / Strategy

Responsabilite : produire une intention de trading, jamais un ordre exchange.

Entrees :

- market data ;
- indicateurs ;
- contexte MTF ;
- configuration effective ;
- etat de marche.

Sortie :

```text
SignalIntent
- instrument
- side
- timeframe
- profile
- confidence
- entry zone
- invalidation level
- expected R
- metadata conditions
```

Regle : Strategy ne doit pas connaitre les payloads OKX, Hyperliquid, Fake/Paper ou DEX.

### 2. Risk Engine

Responsabilite : accepter, reduire ou refuser une intention de trading.

Sortie :

```text
RiskDecision
- accepted / rejected
- reason
- risk_usdt
- max_notional
- max_leverage
- max_slippage
- daily_loss_remaining
- portfolio_exposure
- exchange_exposure
- correlation_cap
```

Regles non negociables :

- aucun ordre live sans decision de risque ;
- aucune position sans SL automatique immediatement attache ou procedure de reparation critique ;
- aucun levier arbitraire : le levier decoule du risque, du stop, du budget et des caps exchange ;
- aucune bascule live sans runtime-check OK.

### 3. OMS — Order Management System

Responsabilite : gerer le cycle de vie metier des ordres.

Etats cibles :

```text
requested
risk_accepted
planned
submitted
acknowledged
partially_filled
filled
cancel_requested
cancelled
expired
rejected
replaced
closed
```

L'OMS ne choisit pas l'exchange. Il conserve la verite metier de l'ordre, l'idempotence, les transitions et les correlations.

Identifiants a standardiser :

- `intent_id` ;
- `risk_decision_id` ;
- `order_id` interne ;
- `client_order_id` ;
- `exchange_order_id` ;
- `decision_key` ;
- `run_id` ;
- `trade_id`.

### 4. EMS — Execution Management System

Responsabilite : choisir ou et comment executer.

Decisions EMS :

- exchange cible ;
- maker ou taker ;
- limit, market ou IOC ;
- split order ou non ;
- fallback exchange ;
- retry ou abandon ;
- slippage maximum.

Exemple :

```text
BTCUSDT long perp
-> comparer OKX / Hyperliquid / Fake-Paper
-> choisir meilleur cout total : spread + frais + profondeur + latence + risque runtime
```

### 5. CEX Gateways

Responsabilite : isoler chaque exchange centralise.

Interface cible indicative :

```php
interface CexGatewayInterface
{
    public function getOrderBook(string $symbol): OrderBook;
    public function getBalance(): BalanceSnapshot;
    public function submitOrder(CexOrderRequest $request): ExchangeOrderAck;
    public function cancelOrder(string $exchangeOrderId): CancelAck;
    public function getOpenOrders(): array;
    public function getOpenPositions(): array;
    public function healthCheck(): GatewayHealth;
}
```

CEX a conserver dans la cible :

- OKX ;
- Hyperliquid ;
- Fake / Paper exchange.

CEX legacy a retirer :

- Bitmart.

CEX candidat futur a valider :

- Binance.

Chaque gateway doit gerer :

- mapping DTO interne -> payload exchange ;
- mapping reponse exchange -> evenement interne ;
- rate limits ;
- REST public/prive ;
- WebSocket public/prive si disponible ;
- idempotence via `client_order_id` ;
- recuperation et reparation d'etat.

### 6. DEX Gateways

Responsabilite : isoler les protocoles on-chain.

Un DEX ne doit pas etre force dans le modele CEX. Il a un cycle different : quote, approval, simulation, transaction, confirmation, reorg possible.

Interface cible indicative :

```php
interface DexGatewayInterface
{
    public function quote(SwapQuoteRequest $request): SwapQuote;
    public function buildTransaction(SwapExecutionPlan $plan): BlockchainTx;
    public function simulate(BlockchainTx $tx): SimulationResult;
    public function submit(BlockchainTx $tx): TxHash;
    public function track(TxHash $hash): TxStatus;
    public function healthCheck(): GatewayHealth;
}
```

DEX/protocoles candidats, uniquement en proof of concept dry-run au depart :

- Uniswap ;
- 0x ;
- 1inch ;
- agrégateur interne futur ;
- RPC EVM ;
- private relay / MEV protection si necessaire.

### 7. Position Manager

Responsabilite : gerer les positions ouvertes apres execution.

Fonctions :

- attachement SL automatique ;
- attachement TP ;
- trailing stop ;
- partial close ;
- time stop ;
- liquidation guard ;
- synchronisation exchange ;
- reparation si SL/TP absent ;
- fermeture d'urgence ;
- projection lifecycle.

Ce module doit sortir de la responsabilite du Runner.

### 8. Analytics Engine

Responsabilite : mesurer si le systeme gagne reellement.

Sources :

- `position_trade_analysis` ;
- `trade_lifecycle_event` ;
- order fills ;
- fees ;
- slippage ;
- snapshots indicateurs ;
- configuration effective au moment du trade.

Metriques :

- winrate ;
- expectancy nette ;
- profit factor ;
- PnL R ;
- MFE ;
- MAE ;
- holding time ;
- frais ;
- slippage ;
- performance par profil/exchange/symbole/timeframe.

Boucle cible :

```text
Trades executes
-> position_trade_analysis
-> expectancy nette
-> identification pertes
-> recommandation config
-> simulation/backtest
-> forward test
-> activation progressive
```

## CEX vs DEX : regle de modelisation

Ne pas creer un seul `OrderRequest` universel pour tout.

Modele CEX :

```text
CexOrderRequest
- exchange
- market_type
- symbol
- side
- type limit/market/IOC
- price
- size
- leverage
- margin mode
- reduce_only
- client_order_id
- TP/SL exchange-native si disponible
```

Modele DEX :

```text
SwapExecutionPlan
- chain_id
- wallet
- token_in
- token_out
- amount_in/out
- route
- slippage
gas
- nonce
- approval
- simulation result
- tx hash
```

Modele superieur commun :

```text
ExecutionIntent
- target exposure
- asset pair
- side/bias
- risk constraints
- execution constraints
- metadata
```

Le coeur metier parle en intention d'exposition. Les gateways traduisent cette intention vers leurs mecanismes propres.

## Event Store leger

Il faut eviter un event sourcing complet trop tot, mais creer une piste d'audit structuree.

Evenements utiles :

```text
SignalIntentCreated
RiskDecisionAccepted
RiskDecisionRejected
OrderPlanCreated
ExecutionRouteSelected
OrderSubmitted
OrderAcknowledged
OrderPartiallyFilled
OrderFilled
OrderCancelled
ProtectionAttached
ProtectionMissingDetected
PositionOpened
PositionUpdated
PositionClosed
DexQuoteReceived
DexApprovalRequired
DexTransactionBuilt
DexTransactionSimulated
DexTransactionSubmitted
DexTransactionConfirmed
DexTransactionFailed
```

Chaque evenement doit porter :

- timestamp ;
- correlation id ;
- source module ;
- exchange / market / chain si applicable ;
- payload metier ;
- payload brut externe optionnel ;
- erreur eventuelle.

## Configuration cible

La configuration doit etre resolue en une config effective :

```text
base
+ mode
+ exchange
+ mode_exchange
+ env
+ runtime overrides
= EffectiveTradingConfig
```

Exemple de structure cible :

```text
config/trading/base.yaml
config/trading/modes/regular.yaml
config/trading/modes/scalper.yaml
config/trading/modes/scalper_micro.yaml
config/trading/exchanges/okx.yaml
config/trading/exchanges/hyperliquid.yaml
config/trading/exchanges/fake.yaml
config/trading/dex/evm.yaml
config/trading/overrides/scalper_micro.okx.yaml
config/trading/env/prod.yaml
```

Règle : toute decision de trade doit pouvoir etre reliee a la config effective utilisee.

## Structure de code cible

```text
src/
├── TradingCore/
│   ├── Strategy/
│   ├── Risk/
│   ├── Order/
│   ├── Execution/
│   ├── Position/
│   └── Analytics/
│
├── Exchange/
│   ├── Shared/
│   ├── Cex/
│   │   ├── Okx/
│   │   ├── Hyperliquid/
│   │   └── Fake/
│   └── Dex/
│       ├── Evm/
│       ├── Uniswap/
│       ├── ZeroX/
│       └── OneInch/
│
├── MarketData/
│   ├── Ingestion/
│   ├── WebSocket/
│   ├── Kline/
│   └── OrderBook/
│
├── Application/
│   ├── UseCase/
│   ├── Command/
│   └── Query/
│
├── Infrastructure/
│   ├── Doctrine/
│   ├── Messenger/
│   ├── Temporal/
│   ├── Redis/
│   └── Http/
│
└── Ops/
    ├── Controller/
    ├── Dashboard/
    └── RuntimeCheck/
```

## Migration progressive proposee

### Phase 0 — Cadrage

- Valider ce document.
- Lister les modules existants a deplacer ou encapsuler.
- Creer les frontieres de noms sans changer le comportement runtime.
- Marquer Bitmart comme legacy dans les documents et configs cibles.

### Phase 1 — Config effective

- Creer `EffectiveTradingConfigResolver`.
- Garder compatibilite avec les YAML actuels.
- Exposer la config effective dans l'Ops front.
- Ajouter tests de resolution.
- Prevoir une config cible sans Bitmart.

### Phase 2 — Runner mince

Extraire progressivement :

- `SymbolUniverseResolver` ;
- `ExchangeStateSynchronizer` ;
- `OpenActivityFilter` ;
- `MtfExecutionDispatcher` ;
- `PostRunProjectionDispatcher` ;
- `RunResultAssembler`.

Objectif : le Runner orchestre, mais ne porte plus la logique metier.

### Phase 3 — Strategy/Risk/Order boundaries

- Introduire `SignalIntent`.
- Introduire `RiskDecision`.
- Introduire `OrderIntent` / `OrderPlan` stable.
- Interdire les payloads exchange dans Strategy/Risk.

### Phase 4 — OMS minimal

- Creer cycle de vie ordre interne.
- Standardiser les identifiants de correlation.
- Centraliser idempotence et transitions.
- Connecter TradeEntry existant au nouvel OMS.

### Phase 5 — EMS minimal

- Creer `ExecutionRoute`.
- Choisir exchange/protocole cible explicitement.
- Centraliser maker/taker, slippage, fallback, retry.
- Preparer split order futur.

### Phase 6 — Gateways CEX strictes

- Garder OKX, Hyperliquid et Fake/Paper comme gateways cibles.
- Garder OKX/Hyperliquid en dry-run/runtime-check jusqu'a readiness complete.
- Utiliser Fake/Paper comme gateway de test et simulation.
- Planifier le retrait Bitmart dans une PR separee.

### Phase 7 — Retrait progressif Bitmart

- Inventorier les usages Bitmart restants.
- Retirer Bitmart des exemples et configs cibles.
- Remplacer les tests de gateway cible par OKX, Hyperliquid ou Fake.
- Supprimer le code Bitmart seulement quand aucune execution, aucun test critique et aucun schedule n'en dependent.

### Phase 8 — Position Manager autonome

- Sortir TP/SL/trailing/time-stop du Runner.
- Creer reparation de protections manquantes.
- Creer alerte critique si position sans SL.

### Phase 9 — DEX Gateway proof of concept

- Commencer par quote/simulation uniquement.
- Pas d'execution live tant que wallet, gas, allowance, simulation, slippage et MEV ne sont pas maitrises.
- Modele separe de CEX.

### Phase 10 — Analytics feedback loop

- Faire de `position_trade_analysis` la source standard.
- Ajouter expectancy nette par profil/exchange/setup.
- Utiliser les resultats pour proposer des changements YAML/config.

## Invariants d'architecture

- Strategy ne depend jamais d'un exchange concret.
- Risk ne depend jamais d'un payload exchange.
- OMS garde la verite metier des ordres.
- EMS choisit le chemin d'execution.
- Gateway traduit vers l'exchange/protocole.
- Position Manager protege et suit les positions.
- Analytics mesure avant d'elargir la frequence de trading.
- Aucune activation live sans runtime-check OK.
- Aucun trade live sans risque valide.
- Aucune position live sans SL automatique visible.
- Toute decision doit etre correlable par `run_id`, `decision_key`, `client_order_id`, `exchange_order_id` ou `trade_id`.
- Bitmart n'est pas une cible de la nouvelle architecture.

## Non-objectifs immediats

- Ne pas migrer en microservices complets maintenant.
- Ne pas ajouter DEX live immediatement.
- Ne pas casser les workflows Temporal existants sans migration.
- Ne pas reecrire simultanement Strategy, Risk, OMS, EMS et Gateways dans une seule PR.
- Ne pas chercher plus de trades avant d'avoir mesure l'expectancy nette.
- Ne pas supprimer brutalement Bitmart dans une PR documentaire.

## Criteres d'acceptation pour une future implementation

- Les modules ont des frontieres testables.
- Les payloads exchange ne fuient pas dans Strategy/Risk.
- Les decisions de risque sont persistees ou auditables.
- Les routes d'execution sont explicites.
- Les positions sans SL sont detectees et remontees comme anomalies critiques.
- La config effective est observable.
- Les decisions sont analysables via `position_trade_analysis` ou successeur.
- OKX, Hyperliquid et Fake/Paper sont les gateways cible conservees.
- Bitmart est retire des documents, configs et exemples cible avant retrait de code.

## Decoupage de PR propose

### PR 1 — Documentation architecture cible

- Ajouter ce document.
- Ajouter le lien dans le handbook.
- Aligner le vocabulaire : TradingCore, Strategy, Risk, OMS, EMS, Gateway, Position Manager, Analytics.
- Marquer Bitmart comme legacy a retirer.

### PR 2 — EffectiveTradingConfigResolver

- Introduire le resolver.
- Garder compatibilite avec les fichiers existants.
- Ajouter tests de resolution.
- Ajouter tests de config cible OKX/Hyperliquid/Fake.

### PR 3 — Runner extraction 1

- Extraire `SymbolUniverseResolver`.
- Extraire `OpenActivityFilter`.
- Aucun changement fonctionnel attendu.

### PR 4 — Runner extraction 2

- Extraire `ExchangeStateSynchronizer`.
- Extraire `RunResultAssembler`.
- Ajouter tests unitaires.

### PR 5 — SignalIntent + RiskDecision

- Introduire DTOs metier.
- Adapter le flux MTF sans changer le comportement externe.

### PR 6 — OMS minimal

- Introduire etat d'ordre interne.
- Standardiser transitions et idempotence.

### PR 7 — EMS minimal

- Introduire `ExecutionRoute`.
- Preparer routing OKX/Hyperliquid/Fake.

### PR 8 — Position Manager autonome

- Sortir recalcul TP/SL du Runner.
- Ajouter controle position sans SL.

### PR 9 — Gateways OKX/Hyperliquid/Fake cibles

- Stabiliser les contrats de gateway.
- Garder OKX/Hyperliquid en dry-run/runtime-check tant que le runtime n'est pas complet.
- Utiliser Fake/Paper pour tests et simulation.

### PR 10 — Retrait Bitmart

- Supprimer Bitmart des configs cible.
- Remplacer les usages restants.
- Supprimer le code uniquement apres verification qu'aucun flux critique n'en depend.

### PR 11 — DEX Gateway dry-run

- Quote/simulation uniquement.
- Aucun live.

## Questions ouvertes

- Faut-il garder `TradeEntry` comme nom de module ou le decouper en `OrderPlanning`, `Execution`, `PositionProtection` ?
- Quelle table doit devenir la source de verite des ordres internes ?
- Faut-il introduire un `trade_id` des la decision ou seulement apres fill ?
- Quel niveau d'event store est suffisant sans basculer en event sourcing complet ?
- Comment representer proprement les instruments CEX perps vs DEX spot/swaps ?
- Quel composant doit decider du fallback entre CEX ?
- Quelle politique adopter pour les DEX : quote-only, simulation-only, puis live tres restreint ?
- Quelle est la strategie exacte de retrait de Bitmart : suppression directe apres remplacement ou maintien temporaire en legacy disabled ?

## Vision finale

TradingV3 doit evoluer d'un bot MTF oriente exchange historique vers une plateforme de decision et d'execution :

```text
Strategy produit une intention.
Risk decide si elle est autorisee.
OMS suit le cycle de vie.
EMS choisit le meilleur chemin.
Gateway execute via OKX, Hyperliquid ou Fake/Paper.
Position Manager protege.
Analytics verifie si l'ensemble gagne vraiment.
```
