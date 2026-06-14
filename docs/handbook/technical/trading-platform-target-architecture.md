# Architecture cible Trading Platform Core

## Statut

Document de travail initial. Cette page sert de base d'itération pour une refonte majeure de TradingV3 vers une plateforme multi-CEX et multi-DEX.

Le but n'est pas de décrire uniquement le code existant. Le but est de définir l'architecture qui correspond le mieux à la trajectoire cible : plusieurs exchanges centralisés, plusieurs protocoles décentralisés, contrôle du risque, exécution robuste et analyse PnL systématique.

## Contexte actuel

TradingV3 possède déjà une base utile :

```text
Runner -> Validator -> Decision -> TradeEntry -> Provider/Exchange
```

Le runner orchestre les runs MTF, le validateur produit des décisions, TradeEntry construit et exécute les plans d'ordre, et les providers abstraient l'accès aux exchanges.

Cette base est cohérente pour un système Bitmart/MTF, mais elle devient limitée pour une plateforme multi-CEX/multi-DEX :

- le Runner porte trop de responsabilités opérationnelles ;
- TradeEntry mélange encore planification d'ordre, exécution et gestion de protections ;
- les CEX et les DEX ne peuvent pas partager naïvement le même modèle d'ordre ;
- le routage d'exécution doit devenir un composant métier à part entière ;
- l'analyse post-trade doit boucler vers la stratégie et la configuration.

## Problème à résoudre

La future architecture doit répondre à ces questions :

```text
Comment produire une intention de trading sans dépendre d'un exchange ?
Comment valider le risque avant toute exécution ?
Comment choisir entre Bitmart, OKX, Hyperliquid, Binance ou un DEX ?
Comment gérer des ordres CEX et des transactions DEX sans les forcer dans le même modèle ?
Comment garantir qu'aucune position live ne reste sans protection ?
Comment mesurer si les changements améliorent réellement l'expectancy nette ?
```

## Options étudiées

### Option A — Modular monolith hexagonal

```text
Symfony App
├── Strategy Engine
├── Risk Engine
├── Order Management
├── Execution Management
├── Position Management
├── Analytics
└── Exchange Adapters
```

Avantages :

- simple à développer ;
- simple à tester ;
- simple à déployer ;
- cohérent avec le code Symfony actuel ;
- bon choix pour une migration progressive.

Limites :

- le monolithe peut grossir si les gateways CEX/DEX deviennent lourdes ;
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
Portfolio Service
Analytics Service
CEX Gateway Services
DEX Gateway Services
Temporal Orchestrator
Ops Front
```

Avantages :

- scalabilité maximale ;
- isolation forte des responsabilités ;
- extraction facile des gateways lourdes ;
- équipes séparées possibles.

Limites :

- complexité très élevée ;
- idempotence distribuée plus difficile ;
- observabilité plus coûteuse ;
- debugging plus difficile ;
- versioning d'événements nécessaire ;
- overhead excessif tant que l'edge trading n'est pas prouvé.

### Option C — Trading Platform Core + OMS/EMS + gateways

```text
Symfony modular monolith
├── Trading Core
├── Risk Engine
├── OMS
├── EMS
├── Position Manager
├── Analytics Engine
├── CEX Gateways
├── DEX Gateways
└── Event Store léger
```

Avantages :

- meilleure séparation métier ;
- compatible avec une migration progressive ;
- suffisamment robuste pour plusieurs CEX/DEX ;
- évite la complexité microservices trop tôt ;
- prépare une extraction future des gateways si nécessaire.

Limites :

- nécessite une vraie discipline de frontières ;
- impose de restructurer les modules existants ;
- demande des contrats métier stables.

## Décision recommandée

L'architecture cible recommandée est l'option C :

```text
Trading Platform Core
+ Strategy Engine
+ Risk Engine
+ OMS
+ EMS
+ CEX Gateway
+ DEX Gateway
+ Position Manager
+ Analytics Engine
+ Event Store léger
+ Temporal pour l'orchestration
+ Symfony pour API/Ops/front
```

Cette cible doit être mise en place comme un modular monolith strict, avec possibilité d'extraire certains modules plus tard.

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
        ┌──────────────────────────┼──────────────────────────┐
        │                          │                          │
┌───────▼────────┐       ┌─────────▼────────┐       ┌─────────▼────────┐
│ Strategy Engine│       │   Risk Engine    │       │ Analytics Engine │
│ MTF / Signals  │       │ exposure / caps  │       │ PnL / winrate    │
└───────┬────────┘       └─────────┬────────┘       └─────────┬────────┘
        │                          │                          │
        └──────────────┬───────────┴──────────────┬───────────┘
                       │                          │
              ┌────────▼────────┐        ┌────────▼────────┐
              │       OMS       │        │ Position Manager│
              │ orders lifecycle│        │ SL/TP/trailing  │
              └────────┬────────┘        └────────┬────────┘
                       │                          │
              ┌────────▼──────────────────────────▼────────┐
              │                    EMS                      │
              │ routing, slippage, maker/taker, gas, MEV    │
              └────────┬──────────────────────────┬────────┘
                       │                          │
          ┌────────────▼────────────┐  ┌──────────▼───────────┐
          │      CEX Gateways       │  │      DEX Gateways     │
          │ Bitmart / OKX / Binance │  │ Uniswap / 0x / 1inch  │
          │ Hyperliquid             │  │ wallets / RPC / MEV   │
          └─────────────────────────┘  └──────────────────────┘
```

## Modules cibles

### 1. Strategy Engine

Responsabilité : produire une intention de trading, jamais un ordre exchange.

Entrées :

- market data ;
- indicateurs ;
- contexte MTF ;
- configuration effective ;
- état de marché.

Sortie :

```text
SignalIntent
- symbol / instrument
- side
- timeframe
- profile
- confidence
- entry zone
- invalidation level
- expected R
- metadata conditions
```

Règle : le Strategy Engine ne doit pas connaître les payloads Bitmart, OKX, Hyperliquid ou DEX.

### 2. Risk Engine

Responsabilité : accepter, réduire ou refuser une intention de trading.

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

Règles non négociables :

- aucun ordre live sans décision de risque ;
- aucune position sans SL automatique immédiatement attaché ou procédure de réparation critique ;
- aucun levier arbitraire : le levier découle du risque, du stop, du budget et des caps exchange ;
- aucune bascule live sans runtime-check OK.

### 3. OMS — Order Management System

Responsabilité : gérer le cycle de vie métier des ordres.

États cibles :

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

L'OMS ne choisit pas l'exchange. Il conserve la vérité métier de l'ordre, l'idempotence, les transitions et les corrélations.

Identifiants à standardiser :

- `intent_id` ;
- `risk_decision_id` ;
- `order_id` interne ;
- `client_order_id` ;
- `exchange_order_id` ;
- `decision_key` ;
- `run_id` ;
- `trade_id`.

### 4. EMS — Execution Management System

Responsabilité : choisir où et comment exécuter.

Décisions EMS :

- CEX ou DEX ;
- exchange/protocole cible ;
- maker ou taker ;
- limit, market, IOC ou swap transaction ;
- split order ou non ;
- fallback exchange ;
- retry ou abandon ;
- slippage maximum ;
- gestion gas/MEV si DEX.

Exemples :

```text
BTCUSDT long perp
-> comparer Bitmart / OKX / Hyperliquid
-> choisir meilleur coût total : spread + frais + profondeur + latence + risque runtime
```

```text
ETH/USDC spot on-chain
-> comparer Uniswap / 0x / 1inch
-> vérifier gas, route, approval, slippage, simulation, MEV
```

### 5. CEX Gateways

Responsabilité : isoler chaque exchange centralisé.

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

CEX couverts ou candidats :

- Bitmart ;
- OKX ;
- Hyperliquid ;
- Binance ;
- Fake / Paper exchange.

Chaque gateway doit gérer :

- mapping DTO interne -> payload exchange ;
- mapping réponse exchange -> événement interne ;
- rate limits ;
- REST public/privé ;
- WebSocket public/privé si disponible ;
- idempotence via `client_order_id` ;
- récupération et réparation d'état.

### 6. DEX Gateways

Responsabilité : isoler les protocoles on-chain.

Un DEX ne doit pas être forcé dans le modèle CEX. Il a un cycle différent : quote, approval, simulation, transaction, confirmation, reorg possible.

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

DEX/protocoles candidats :

- Uniswap ;
- 0x ;
- 1inch ;
- agrégateur interne futur ;
- RPC EVM ;
- private relay / MEV protection si nécessaire.

Aspects obligatoires :

- `chain_id` ;
- wallet ;
- token in/out ;
- allowance ;
- gas balance ;
- nonce ;
- slippage ;
- route ;
- simulation ;
- transaction hash ;
- confirmation block ;
- reorg handling.

### 7. Position Manager

Responsabilité : gérer les positions ouvertes après exécution.

Fonctions :

- attachement SL automatique ;
- attachement TP ;
- trailing stop ;
- partial close ;
- time stop ;
- liquidation guard ;
- synchronisation exchange ;
- réparation si SL/TP absent ;
- fermeture d'urgence ;
- projection lifecycle.

Ce module doit sortir de la responsabilité du Runner.

### 8. Analytics Engine

Responsabilité : mesurer si le système gagne réellement.

Sources :

- `position_trade_analysis` ;
- `trade_lifecycle_event` ;
- order fills ;
- fees ;
- slippage ;
- snapshots indicateurs ;
- configuration effective au moment du trade.

Métriques :

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
Trades exécutés
-> position_trade_analysis
-> expectancy nette
-> identification pertes
-> recommandation config
-> simulation/backtest
-> forward test
-> activation progressive
```

## CEX vs DEX : règle de modélisation

Ne pas créer un seul `OrderRequest` universel pour tout.

Modèle CEX :

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

Modèle DEX :

```text
SwapExecutionPlan
- chain_id
- wallet
- token_in
- token_out
- amount_in/out
- route
- slippage
- gas
- nonce
- approval
- simulation result
- tx hash
```

Modèle supérieur commun :

```text
ExecutionIntent
- target exposure
- asset pair
- side/bias
- risk constraints
- execution constraints
- metadata
```

Le coeur métier parle en intention d'exposition. Les gateways traduisent cette intention vers leurs mécanismes propres.

## Event Store léger

Il faut éviter un event sourcing complet trop tôt, mais créer une piste d'audit structurée.

Événements utiles :

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

Chaque événement doit porter :

- timestamp ;
- correlation id ;
- source module ;
- exchange / market / chain si applicable ;
- payload métier ;
- payload brut externe optionnel ;
- erreur éventuelle.

## Configuration cible

La configuration doit être résolue en une config effective :

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
config/trading/exchanges/bitmart.yaml
config/trading/exchanges/okx.yaml
config/trading/exchanges/hyperliquid.yaml
config/trading/dex/evm.yaml
config/trading/overrides/scalper_micro.bitmart.yaml
config/trading/env/prod.yaml
```

Règle : toute décision de trade doit pouvoir être reliée à la config effective utilisée.

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
│   │   ├── Bitmart/
│   │   ├── Okx/
│   │   ├── Binance/
│   │   └── Hyperliquid/
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

## Migration progressive proposée

### Phase 0 — Cadrage

- Valider ce document.
- Lister les modules existants à déplacer ou encapsuler.
- Créer les frontières de noms sans changer le comportement runtime.

### Phase 1 — Config effective

- Créer `EffectiveTradingConfigResolver`.
- Garder compatibilité avec les YAML actuels.
- Exposer la config effective dans l'Ops front.
- Ajouter tests de résolution.

### Phase 2 — Runner mince

Extraire progressivement :

- `SymbolUniverseResolver` ;
- `ExchangeStateSynchronizer` ;
- `OpenActivityFilter` ;
- `MtfExecutionDispatcher` ;
- `PostRunProjectionDispatcher` ;
- `RunResultAssembler`.

Objectif : le Runner orchestre, mais ne porte plus la logique métier.

### Phase 3 — Strategy/Risk/Order boundaries

- Introduire `SignalIntent`.
- Introduire `RiskDecision`.
- Introduire `OrderIntent` / `OrderPlan` stable.
- Interdire les payloads exchange dans Strategy/Risk.

### Phase 4 — OMS minimal

- Créer cycle de vie ordre interne.
- Standardiser les identifiants de corrélation.
- Centraliser idempotence et transitions.
- Connecter TradeEntry existant au nouvel OMS.

### Phase 5 — EMS minimal

- Créer `ExecutionRoute`.
- Choisir exchange/protocole cible explicitement.
- Centraliser maker/taker, slippage, fallback, retry.
- Préparer split order futur.

### Phase 6 — Gateways CEX strictes

- Transformer Provider/Exchange actuel vers `Exchange/Cex/*` ou l'encapsuler.
- Garder Bitmart comme premier gateway complet.
- Garder OKX/Hyperliquid en dry-run/runtime-check jusqu'à readiness complète.

### Phase 7 — Position Manager autonome

- Sortir TP/SL/trailing/time-stop du Runner.
- Créer réparation de protections manquantes.
- Créer alerte critique si position sans SL.

### Phase 8 — DEX Gateway proof of concept

- Commencer par quote/simulation uniquement.
- Pas d'exécution live tant que wallet, gas, allowance, simulation, slippage et MEV ne sont pas maîtrisés.
- Modèle séparé de CEX.

### Phase 9 — Analytics feedback loop

- Faire de `position_trade_analysis` la source standard.
- Ajouter expectancy nette par profil/exchange/setup.
- Utiliser les résultats pour proposer des changements YAML/config.

## Invariants d'architecture

- Strategy ne dépend jamais d'un exchange concret.
- Risk ne dépend jamais d'un payload exchange.
- OMS garde la vérité métier des ordres.
- EMS choisit le chemin d'exécution.
- Gateway traduit vers l'exchange/protocole.
- Position Manager protège et suit les positions.
- Analytics mesure avant d'élargir la fréquence de trading.
- Aucune activation live sans runtime-check OK.
- Aucun trade live sans risque validé.
- Aucune position live sans SL automatique visible.
- Toute décision doit être corrélable par `run_id`, `decision_key`, `client_order_id`, `exchange_order_id` ou `trade_id`.

## Non-objectifs immédiats

- Ne pas migrer en microservices complets maintenant.
- Ne pas ajouter DEX live immédiatement.
- Ne pas casser les workflows Temporal existants sans migration.
- Ne pas réécrire simultanément Strategy, Risk, OMS, EMS et Gateways dans une seule PR.
- Ne pas chercher plus de trades avant d'avoir mesuré l'expectancy nette.

## Critères d'acceptation pour une future implémentation

- Les modules ont des frontières testables.
- Les payloads exchange ne fuient pas dans Strategy/Risk.
- Les décisions de risque sont persistées ou auditables.
- Les routes d'exécution sont explicites.
- Les positions sans SL sont détectées et remontées comme anomalies critiques.
- Les CEX et DEX ont des modèles séparés.
- La config effective est observable.
- Les décisions sont analysables via `position_trade_analysis` ou successeur.

## Découpage de PR proposé

### PR 1 — Documentation architecture cible

- Ajouter ce document.
- Ajouter le lien dans le handbook.
- Aligner le vocabulaire : Strategy, Risk, OMS, EMS, Gateway, Position Manager, Analytics.

### PR 2 — EffectiveTradingConfigResolver

- Introduire le resolver.
- Garder compatibilité avec les fichiers existants.
- Ajouter tests de résolution.

### PR 3 — Runner extraction 1

- Extraire `SymbolUniverseResolver`.
- Extraire `OpenActivityFilter`.
- Aucun changement fonctionnel attendu.

### PR 4 — Runner extraction 2

- Extraire `ExchangeStateSynchronizer`.
- Extraire `RunResultAssembler`.
- Ajouter tests unitaires.

### PR 5 — SignalIntent + RiskDecision

- Introduire DTOs métier.
- Adapter le flux MTF sans changer le comportement externe.

### PR 6 — OMS minimal

- Introduire état d'ordre interne.
- Standardiser transitions et idempotence.

### PR 7 — EMS minimal

- Introduire `ExecutionRoute`.
- Préparer routing multi-CEX.

### PR 8 — Position Manager autonome

- Sortir recalcul TP/SL du Runner.
- Ajouter contrôle position sans SL.

### PR 9 — Gateway Bitmart encapsulée

- Aligner Bitmart sur les contrats CEX cibles.
- Conserver compatibilité runtime.

### PR 10 — DEX Gateway dry-run

- Quote/simulation uniquement.
- Aucun live.

## Questions ouvertes

- Faut-il garder `TradeEntry` comme nom de module ou le découper en `OrderPlanning`, `Execution`, `PositionProtection` ?
- Quelle table doit devenir la source de vérité des ordres internes ?
- Faut-il introduire un `trade_id` dès la décision ou seulement après fill ?
- Quel niveau d'event store est suffisant sans basculer en event sourcing complet ?
- Comment représenter proprement les instruments CEX perps vs DEX spot/swaps ?
- Quel composant doit décider du fallback entre CEX ?
- Quelle politique adopter pour les DEX : quote-only, simulation-only, puis live très restreint ?

## Vision finale

TradingV3 doit évoluer d'un bot MTF orienté exchange vers une plateforme de décision et d'exécution :

```text
Strategy produit une intention.
Risk décide si elle est autorisée.
OMS suit le cycle de vie.
EMS choisit le meilleur chemin.
Gateway exécute.
Position Manager protège.
Analytics vérifie si l'ensemble gagne vraiment.
```
