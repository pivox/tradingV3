# Architecture des Providers

Cette architecture modulaire permet d'utiliser différents providers (Bitmart, Binance, etc.) de manière uniforme à travers une interface standardisée.

## Structure

```
src/
├── Contract/Provider/                 # Contrats (interfaces) - à utiliser dans le code
│   ├── MainProviderInterface.php      # Point d'entrée unique
│   ├── KlineProviderInterface.php
│   ├── ContractProviderInterface.php
│   ├── OrderProviderInterface.php
│   ├── AccountProviderInterface.php
│   ├── SystemProviderInterface.php    # Temps système
│   └── Dto/                           # DTOs standardisés
│       ├── BaseDto.php
│       ├── KlineDto.php
│       ├── ContractDto.php
│       ├── OrderDto.php
│       ├── AccountDto.php
│       ├── PositionDto.php
│       └── SymbolBidAskDto.php
│
└── Provider/                          # Implémentations
    ├── MainProvider.php               # Service central (implémente MainProviderInterface)
    ├── CleanupProvider.php            # Nettoyage de la base de données
    └── Bitmart/                       # Implémentation Bitmart
        ├── BitmartKlineProvider.php
        ├── BitmartContractProvider.php
        ├── BitmartOrderProvider.php
        ├── BitmartAccountProvider.php
        ├── SystemProvider.php         # Temps système Bitmart
        ├── Http/                      # Clients HTTP
        │   ├── BitmartHttpClientPublic.php
        │   ├── BitmartHttpClientPrivate.php
        │   ├── BitmartConfig.php
        │   ├── BitmartRequestSigner.php
        │   └── throttleBitmartRequestTrait.php
        ├── WebSocket/                 # Clients WebSocket
        │   ├── BitmartWebsocketBase.php
        │   ├── BitmartWebsocketPublic.php
        │   └── BitmartWebsocketPrivate.php
        ├── Service/                   # Services utilitaires
        │   ├── KlineFetcher.php       # Récupération et sauvegarde des klines
        │   └── KlineJsonIngestionService.php  # Ingestion performante via SQL JSON
        ├── Dto/                       # DTOs spécifiques Bitmart
        │   ├── KlineDto.php
        │   ├── ContractDto.php
        │   ├── ListKlinesDto.php
        │   └── ListContractDto.php
        └── Example/
            └── BitmartProviderUsageExample.php
```

## Architecture des Contrats

L'architecture suit le principe de **séparation des contrats et des implémentations** :

- **`App\Contract\Provider`** : Contient les interfaces et DTOs standardisés
  - Utilisé dans tout le code métier pour l'injection de dépendances
  - Garantit la compatibilité et la testabilité

- **`App\Provider`** : Contient les implémentations concrètes
  - `MainProvider` est marqué avec `#[AsAlias(id: MainProviderInterface::class)]`
  - Les providers Bitmart implémentent les interfaces correspondantes
  - Auto-configurés via Symfony DI

## Utilisation

### Via MainProviderInterface (recommandé)

```php
use App\Contract\Provider\MainProviderInterface;
use App\Common\Enum\Timeframe;

class MyService
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider
    ) {}

    public function getKlines(string $symbol): array
    {
        return $this->mainProvider
            ->getKlineProvider()
            ->getKlines($symbol, Timeframe::TF_1H, 100);
    }
}
```

### Accès aux différents providers

```php
use App\Contract\Provider\MainProviderInterface;

class MyService
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider
    ) {}

    public function example(): void
    {
        // Provider de klines
        $klineProvider = $this->mainProvider->getKlineProvider();
        $klines = $klineProvider->getKlines('BTCUSDT', Timeframe::TF_1H, 100);
        
        // Provider de contrats
        $contractProvider = $this->mainProvider->getContractProvider();
        $contracts = $contractProvider->getContracts();
        
        // Provider d'ordres
        $orderProvider = $this->mainProvider->getOrderProvider();
        $openOrders = $orderProvider->getOpenOrders('BTCUSDT');
        
        // Provider de compte
        $accountProvider = $this->mainProvider->getAccountProvider();
        $balance = $accountProvider->getAccountBalance();
        
        // Provider système (temps serveur)
        $systemProvider = $this->mainProvider->getSystemProvider();
        $serverTimeMs = $systemProvider->getSystemTimeMs();
    }
}
```

### Via les providers spécifiques (si nécessaire)

```php
use App\Provider\Bitmart\BitmartKlineProvider;

class MyService
{
    public function __construct(
        private readonly BitmartKlineProvider $klineProvider
    ) {}

    public function getKlines(string $symbol): array
    {
        return $this->klineProvider->getKlines($symbol, Timeframe::TF_1H, 100);
    }
}
```

## SystemProvider

Le `SystemProvider` permet de récupérer le temps système du serveur de l'exchange, utile pour la synchronisation et la validation des timestamps.

```php
use App\Contract\Provider\MainProviderInterface;

class MyService
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider
    ) {}

    public function getServerTime(): int
    {
        $systemProvider = $this->mainProvider->getSystemProvider();
        return $systemProvider->getSystemTimeMs(); // Retourne le timestamp en millisecondes
    }
}
```

## CleanupProvider

Le `CleanupProvider` gère le nettoyage automatique de la base de données pour éviter l'accumulation de données.

```php
use App\Provider\CleanupProvider;

class MaintenanceService
{
    public function __construct(
        private readonly CleanupProvider $cleanupProvider
    ) {}

    public function cleanup(): array
    {
        // Nettoyage complet (dry-run par défaut)
        $report = $this->cleanupProvider->cleanupAll(
            symbol: null,                    // null = tous les symboles
            klinesKeepLimit: 500,            // Garder 500 klines par (symbol, timeframe)
            auditDaysKeep: 3,                // Garder 3 jours d'audits MTF
            signalDaysKeep: 3,                // Garder 3 jours de signaux
            dryRun: true                      // Mode prévisualisation
        );

        // Nettoyage ciblé
        $klinesReport = $this->cleanupProvider->cleanupKlines(
            symbol: 'BTCUSDT',
            keepLimit: 1000,
            dryRun: false
        );

        return $report;
    }
}
```

## Services Utilitaires Bitmart

### KlineFetcher

Service pour récupérer et sauvegarder automatiquement les klines :

```php
use App\Provider\Bitmart\Service\KlineFetcher;
use App\Common\Enum\Timeframe;

class KlineService
{
    public function __construct(
        private readonly KlineFetcher $klineFetcher
    ) {}

    public function fetchAndSave(string $symbol, Timeframe $timeframe): array
    {
        // Récupère et sauvegarde automatiquement
        return $this->klineFetcher->fetchAndSaveKlines($symbol, $timeframe, 270);
    }

    public function fillGaps(string $symbol, Timeframe $timeframe): int
    {
        // Remplit automatiquement les gaps dans les données
        return $this->klineFetcher->fillGaps($symbol, $timeframe);
    }

    public function isUpToDate(string $symbol, Timeframe $timeframe): bool
    {
        // Vérifie si les données sont à jour
        return $this->klineFetcher->isDataUpToDate($symbol, $timeframe);
    }
}
```

### KlineJsonIngestionService

Service d'ingestion performante utilisant une fonction SQL JSON pour insérer les klines en batch :

```php
use App\Provider\Bitmart\Service\KlineJsonIngestionService;

class BatchKlineService
{
    public function __construct(
        private readonly KlineJsonIngestionService $ingestionService
    ) {}

    public function ingestBatch(array $klines, string $symbol, string $timeframe): void
    {
        $result = $this->ingestionService->ingestKlinesBatch($klines, $symbol, $timeframe);
        
        // $result->count : nombre de klines ingérées
        // $result->durationMs : durée en millisecondes
        // $result->success : statut de succès
    }
}
```

## WebSocket

Les clients WebSocket permettent de recevoir des mises à jour en temps réel.

### WebSocket Public

Pour les données publiques (klines, ticker, depth, trade) :

```php
use App\Provider\Bitmart\WebSocket\BitmartWebsocketPublic;

class WebSocketService
{
    public function __construct(
        private readonly BitmartWebsocketPublic $wsPublic
    ) {}

    public function subscribeKlines(string $symbol, string $timeframe): array
    {
        // Construit le message de souscription
        return $this->wsPublic->buildSubscribeKline($symbol, $timeframe);
        // Retourne: ['action' => 'subscribe', 'args' => ['futures/klineBin1m:BTCUSDT']]
    }

    public function subscribeMultipleKlines(string $symbol, array $timeframes): array
    {
        return $this->wsPublic->buildSubscribeKlines($symbol, $timeframes);
    }
}
```

### WebSocket Private

Pour les données privées (ordres, positions, balance) nécessitant une authentification :

```php
use App\Provider\Bitmart\WebSocket\BitmartWebsocketPrivate;

class PrivateWebSocketService
{
    public function __construct(
        private readonly BitmartWebsocketPrivate $wsPrivate
    ) {}

    public function authenticate(): array
    {
        // Construit le message d'authentification
        return $this->wsPrivate->buildLogin();
    }

    public function subscribeOrders(): array
    {
        return $this->wsPrivate->buildSubscribeOrder();
    }

    public function subscribePositions(): array
    {
        return $this->wsPrivate->buildSubscribePosition();
    }

    public function subscribeAsset(string $currency = 'USDT'): array
    {
        return $this->wsPrivate->buildSubscribeAsset($currency);
    }
}
```

## Processus MTF Run

La commande `mtf:run` utilise les providers pour synchroniser les contrats et exécuter le cycle MTF. Voici comment elle fonctionne :

### 1. Synchronisation des contrats

```php
// Dans MtfRunCommand
$contractProvider = $this->mainProvider->getContractProvider();
$result = $contractProvider->syncContracts($symbols);

// Résultat :
// - total_fetched: nombre de contrats récupérés
// - upserted: nombre de contrats insérés/mis à jour
// - errors: liste des erreurs éventuelles
```

### 2. Utilisation dans le cycle MTF

Le cycle MTF moderne utilise `MtfRunnerService` et `MainProviderInterface` pour accéder aux données nécessaires :

- **Klines** : via `getKlineProvider()` pour récupérer les données OHLCV
- **Contrats** : via `getContractProvider()` pour les informations de marché
- **Système** : via `getSystemProvider()` pour la synchronisation temporelle

### 3. Exécution de la commande

```bash
# Exécution basique (dry-run par défaut)
php bin/console mtf:run

# Avec options
php bin/console mtf:run \
    --symbols=BTCUSDT,ETHUSDT \
    --dry-run=0 \
    --force-run \
    --sync-contracts \
    --workers=4

# Options disponibles :
# --symbols : Liste de symboles (par défaut: tous les actifs)
# --dry-run : Mode simulation (1 par défaut, 0 pour exécution réelle)
# --force-run : Force l'exécution même si les switchs sont OFF
# --tf : Limiter à un timeframe (4h|1h|15m|5m|1m)
# --sync-contracts : Synchroniser les contrats au démarrage
# --force-timeframe-check : Force l'analyse même si kline récente
# --workers : Nombre de workers parallèles (1 = séquentiel)
```

### 4. Flux de données

```
mtf:run
  ├── Synchronisation des contrats (via ContractProvider)
  │   └── syncContracts() → fetch + upsert en base
  │
  ├── Pour chaque symbole :
  │   ├── Récupération des klines (via KlineProvider)
  │   │   └── getKlines() → données OHLCV
  │   │
  │   ├── Validation multi-timeframe
  │   │   └── Utilise les klines pour chaque TF
  │   │
  │   └── Génération des signaux
  │       └── Si valide → création du signal
  │
  └── Rapport final avec statistiques
```

## Configuration des Services

Les services sont auto‑configurés via Symfony DI :

- `MainProvider` est marqué avec `#[AsAlias(id: MainProviderInterface::class)]`
- Les providers Bitmart implémentent les interfaces avec `#[AsAlias]` ou `Autoconfigure`
- Les services sont auto-wirés via l'autodiscovery

## Vérification de santé

```php
use App\Contract\Provider\MainProviderInterface;

class HealthCheckService
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider
    ) {}

    public function checkHealth(): array
    {
        // Vérification globale
        $isHealthy = $this->mainProvider->healthCheck();
        
        // Vérification détaillée
        $details = $this->mainProvider->getDetailedHealthCheck();
        // Retourne: ['kline' => bool, 'contract' => bool, 'account' => bool]
        
        return $details;
    }
}
```

## Indicateurs Techniques

Le système calcule et utilise de nombreux indicateurs techniques pour l'analyse des marchés. Les indicateurs sont calculés via le module `Indicator` qui fournit une interface unifiée.

### Indicateurs Disponibles

- **RSI (Relative Strength Index)** : Période 14 par défaut
- **MACD** : (12, 26, 9) - Signal, Histogramme
- **EMA** : Moyennes mobiles exponentielles (9, 20, 21, 50, 200)
- **SMA** : Moyennes mobiles simples (9, 21)
- **ATR (Average True Range)** : Période 14 - Mesure de volatilité
- **VWAP** : Volume Weighted Average Price
- **Bollinger Bands** : Bande supérieure, moyenne, inférieure (20 périodes, 2 écarts-types)
- **ADX** : Average Directional Index (14 périodes)
- **Stochastic** : Oscillateur stochastique (14, 3, 3)

### Calcul des Indicateurs

```php
use App\Contract\Indicator\IndicatorMainProviderInterface;

class IndicatorService
{
    public function __construct(
        private readonly IndicatorMainProviderInterface $indicatorMain
    ) {}

    public function getSnapshot(string $symbol, string $timeframe): IndicatorSnapshotDto
    {
        // Récupère un snapshot complet avec tous les indicateurs
        $provider = $this->indicatorMain->getIndicatorProvider();
        return $provider->getSnapshot($symbol, $timeframe);
    }

    public function calculateFromKlines(array $klines): ListIndicatorDto
    {
        // Calcule les indicateurs à partir d'un tableau de klines
        $provider = $this->indicatorMain->getIndicatorProvider();
        return $provider->getListFromKlines($klines);
    }
}
```

### Modes de Calcul

Le système supporte deux modes de calcul :
- **Mode PHP** : Calculs en mémoire avec les classes d'indicateurs
- **Mode SQL** : Calculs via les vues matérialisées PostgreSQL (plus performant)

Le mode est configuré dans `config/trading.yml` :

```yaml
indicator_calculation:
    mode: sql  # 'php' ou 'sql'
```

## Système de Validation des Conditions

Le système évalue des conditions techniques complexes pour déterminer les opportunités de trading. Les conditions sont organisées par timeframe et side (long/short).

### Types de Conditions

Les conditions sont implémentées dans `App\Indicator\Condition\` et marquées avec l'attribut `AsIndicatorCondition` :

```php
#[AsIndicatorCondition(
    timeframes: ['1m', '5m', '15m', '1h', '4h'],
    side: 'long',  // ou 'short' ou null pour les deux
    name: 'RsiGt85Condition',
    priority: 0
)]
```

### Exemples de Conditions

- **RSI** : `RsiGt85Condition`, `RsiLt15Condition`, `RsiCrossUpCondition`, etc.
- **MACD** : `MacdHistGt0Condition`, `MacdLineAboveSignalCondition`, `MacdSignalCrossUpCondition`
- **EMA** : `Ema20Over50WithToleranceCondition`, `Ema20SlopePosCondition`, `CloseAboveEma200Condition`
- **Prix** : `CloseAboveVwapCondition`, `CloseBelowVwapCondition`, `PriceRegimeOkCondition`
- **ATR** : `AtrStopValidCondition`, `AtrVolatilityOkCondition`, `AtrRelInRange5mCondition`
- **Tendances** : `Ema50Gt200Condition`, `Ema200SlopePosCondition`, `PullbackConfirmedCondition`

### Évaluation des Conditions

```php
use App\Contract\Indicator\IndicatorEngineInterface;

class ConditionEvaluator
{
    public function __construct(
        private readonly IndicatorEngineInterface $engine
    ) {}

    public function evaluateConditions(string $symbol, string $timeframe): array
    {
        // Récupérer les klines via le provider
        $klines = $this->mainProvider->getKlineProvider()
            ->getKlines($symbol, Timeframe::from($timeframe), 150);

        // Construire le contexte
        $context = $this->engine->buildContext($symbol, $timeframe, $klines);

        // Évaluer toutes les conditions
        $results = $this->engine->evaluateAllConditions($context);
        
        // Ou évaluer des conditions spécifiques
        $conditionNames = ['RsiGt85Condition', 'MacdHistGt0Condition'];
        $subsetResults = $this->engine->evaluateConditions($context, $conditionNames);

        return $results;
    }

    public function evaluateYaml(string $timeframe, array $context): array
    {
        // Évaluation via la configuration YAML (legacy)
        return $this->engine->evaluateYaml($timeframe, $context);
    }
}
```

### Validation MTF (Multi-Timeframe)

Le système valide les conditions sur plusieurs timeframes en cascade :

```
4h → 1h → 15m → 5m → 1m
```

Chaque timeframe doit valider ses conditions avant de passer au suivant. La validation s'arrête au premier timeframe qui échoue.

## Placement des Ordres

Le système place les ordres via un processus structuré qui garantit la gestion des risques et l'optimisation des prix d'entrée.

### Architecture du Placement d'Ordres

```
Signal MTF (READY)
  ↓
TradeEntryRequest (construit depuis le signal)
  ↓
PreflightReport (vérifications pré-trade)
  ↓
OrderPlanBuilder (calcul entry, stop, TP, size, leverage)
  ↓
ExecutionBox (soumission à l'exchange)
  ↓
Résultat (submitted / failed)
```

### Construction du Plan d'Ordre

Le `OrderPlanBuilder` calcule tous les paramètres nécessaires :

```php
// Dans TradingDecisionHandler
$tradeRequest = new TradeEntryRequest(
    symbol: 'BTCUSDT',
    side: Side::Long,
    orderType: 'limit',
    riskPct: 1.0,              // 1% du budget
    initialMarginUsdt: 100.0,  // Marge initiale
    rMultiple: 2.0,            // Take profit = 2x le stop loss
    stopFrom: 'atr',            // Calcul du stop depuis ATR
    atrValue: 500.0,            // Valeur ATR
    atrK: 2.0                   // Multiplicateur ATR
);

// Le builder calcule :
// - Entry price (optimisé selon best bid/ask)
// - Stop loss (depuis ATR, pivot, ou risk)
// - Take profit (depuis R-multiple)
// - Position size (depuis risk USDT et distance stop)
// - Leverage (auto-calculé)
```

### Calcul du Prix d'Entrée

Pour les ordres **LIMIT** :
- **Long** : `min(bestAsk - tick, bestBid + insideTicks * tick)`
- **Short** : `max(bestBid + tick, bestAsk - insideTicks * tick)`

Le prix est quantifié selon la précision du contrat et peut être ajusté selon une zone d'entrée (`EntryZone`).

### Calcul du Stop Loss

Le stop loss peut être calculé depuis plusieurs sources (priorité) :

1. **Pivot Level** : Niveau de support/résistance technique
2. **ATR** : `entry ± (ATR * k)` où k est le multiplicateur
3. **Risk-based** : Calculé depuis le risk USDT et la taille de position

**Protection minimale** : Le stop doit être à au moins 0.5% du prix d'entrée pour éviter des stops trop serrés.

### Calcul du Take Profit

Le take profit est calculé via **R-multiple** :

```php
// Exemple : R-multiple de 2.0
$distance = abs($entry - $stop);
$takeProfit = $entry + ($distance * $rMultiple);  // Pour Long
// ou
$takeProfit = $entry - ($distance * $rMultiple);   // Pour Short
```

Le TP peut être aligné avec des **pivot levels** pour optimiser la sortie.

### Calcul de la Taille de Position

La taille est calculée depuis le **risk USDT** et la **distance stop** :

```php
$riskUsdt = $availableBudget * ($riskPct / 100.0);
$distance = abs($entry - $stop);
$size = $riskUsdt / ($distance * $contractSize);
```

La taille est ensuite quantifiée selon les contraintes du contrat (minVolume, maxVolume, volPrecision).

### Calcul du Leverage

Le leverage est calculé automatiquement pour optimiser l'utilisation de la marge :

```php
$notional = $entry * $contractSize * $size;
$initialMargin = $notional / $leverage;
// Le leverage est ajusté pour respecter la marge initiale souhaitée
```

### Exécution de l'Ordre

L'`ExecutionBox` gère la soumission à l'exchange :

```php
// 1. Vérification des prérequis (OrderModePolicy)
// 2. Génération d'un clientOrderId unique
// 3. Soumission du leverage (si nécessaire)
// 4. Préparation du payload TP/SL (si supporté par l'exchange)
// 5. Soumission de l'ordre principal
// 6. Gestion des erreurs et retry
```

### Exemple Complet

```php
// Signal MTF génère un signal READY
$symbolResult = new SymbolResultDto(
    symbol: 'BTCUSDT',
    status: 'READY',
    executionTf: '1m',
    signalSide: Side::Long,
    currentPrice: 50000.0,
    atr: 500.0
);

// Construction de la requête
$tradeRequest = $this->buildTradeEntryRequest($symbolResult, 50000.0, 500.0);

// Execution
$execution = $this->tradeEntryService->buildAndExecute($tradeRequest, $decisionKey);

// Résultat
// - status: 'submitted' ou 'failed'
// - clientOrderId: identifiant unique
// - exchangeOrderId: ID de l'ordre sur l'exchange
```

### Gestion des TP/SL

Le système supporte deux modes pour les TP/SL :

1. **TP/SL intégrés** : Si l'exchange supporte les ordres avec TP/SL (comme Bitmart Futures V2)
2. **TP/SL séparés** : Sinon, création d'ordres séparés après l'exécution de l'ordre principal

Le `TpSlAttacher` gère l'attachement des TP/SL selon les capacités de l'exchange.

### Logs et Audit

Toutes les opérations sont loggées via plusieurs canaux :

- **`order_journey`** : Parcours complet de l'ordre (de la décision à l'exécution)
- **`positions_flow`** : Flux de positions et exécutions
- **`positions`** : Détails des positions ouvertes
- **Audit Logger** : Actions auditées pour traçabilité

## Avantages

1. **Séparation des contrats** : Interfaces dans `Contract/`, implémentations dans `Provider/`
2. **Modularité** : Chaque provider est indépendant
3. **Testabilité** : Interfaces facilement mockables
4. **Extensibilité** : Ajout facile de nouveaux providers (Binance, etc.)
5. **Uniformité** : API cohérente entre tous les providers
6. **Type Safety** : DTOs typés avec validation
7. **Performance** : Ingestion optimisée via SQL JSON pour les batchs
8. **Temps réel** : Support WebSocket pour les données live
9. **Indicateurs complets** : Calcul de tous les indicateurs techniques majeurs
10. **Validation multi-timeframe** : Système de validation en cascade sur plusieurs timeframes
11. **Gestion des risques** : Calcul automatique de la taille de position basée sur le risque
12. **Optimisation des prix** : Calcul intelligent des prix d'entrée, stop et take profit
