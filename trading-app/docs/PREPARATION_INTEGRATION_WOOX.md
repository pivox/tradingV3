# Document de Préparation - Intégration WOO X

## 📋 Table des matières

1. [Vue d'ensemble](#vue-densemble)
2. [Analyse de l'architecture actuelle](#analyse-de-larchitecture-actuelle)
3. [Changements nécessaires](#changements-nécessaires)
4. [Structure des fichiers](#structure-des-fichiers)
5. [Migrations de base de données](#migrations-de-base-de-données)
6. [Commandes à adapter](#commandes-à-adapter)
7. [Endpoints/URLs à adapter](#endpoints-urls-à-adapter)
8. [Configuration](#configuration)
9. [Mapping des symboles](#mapping-des-symboles)
10. [Tests et validation](#tests-et-validation)
11. [Plan d'exécution](#plan-dexécution)

---

## Vue d'ensemble

### Objectif
Ajouter WOO X comme exchange alternatif à Bitmart, avec support multi-exchange :
- Les entités `Contract` et `Kline` portent l'ID/nom de l'exchange
- Temporal peut spécifier l'exchange à utiliser dans `runMtfCycle`
- Support de deux exécutions séparées (une par exchange)
- Migration des données existantes vers Bitmart

### Décisions prises
1. **Format d'identification** : Nom string ('bitmart', 'woox')
2. **Migration** : Oui, marquer les données existantes comme 'bitmart'
3. **Contraintes uniques** : Inclure `exchange` dans les contraintes uniques
4. **Temporal** : Paramètre `exchange` dans les query parameters
5. **Support simultané** : Deux exécutions séparées (pas de support simultané)
6. **Credentials** : Pas encore disponibles (à configurer plus tard)

---

## Analyse de l'architecture actuelle

### Entités actuelles

#### Contract
- **Table** : `contracts`
- **Contrainte unique** : `ux_contracts_symbol` sur `symbol`
- **Champs clés** : `symbol`, `name`, `status`, `quote_currency`, etc.
- **Utilisé par** : `ContractRepository`, `BitmartContractProvider`, commandes, controllers

#### Kline
- **Table** : `klines`
- **Contrainte unique** : `ux_klines_symbol_tf_open` sur `(symbol, timeframe, open_time)`
- **Champs clés** : `symbol`, `timeframe`, `open_time`, `open_price`, etc.
- **Utilisé par** : `KlineRepository`, `BitmartKlineProvider`, commandes, controllers

### Providers actuels

#### MainProvider
- Implémente `MainProviderInterface`
- Injecte les providers Bitmart via DI
- Pas de sélection dynamique d'exchange

#### Providers Bitmart
- `BitmartOrderProvider` → `OrderProviderInterface`
- `BitmartAccountProvider` → `AccountProviderInterface`
- `BitmartKlineProvider` → `KlineProviderInterface`
- `BitmartContractProvider` → `ContractProviderInterface`
- `SystemProvider` → `SystemProviderInterface`

### Controllers utilisant Contract/Kline

#### API Controllers
- `KlinesApiController` : `/api/klines` (GET)
- `ContractsController` (Web) : `/contracts` (GET)
- `KlinesWebController` : `/klines` (GET)
- `IndicatorApiController` : utilise klines
- `MtfController` : `/mtf/run` (POST/GET) - **À ADAPTER**

#### Web Controllers
- `ContractsController` : liste et détails des contrats
- `KlinesWebController` : liste et détails des klines
- `DashboardController` : peut utiliser contracts/klines
- `GraphController` : utilise klines pour les graphiques

### Commandes utilisant Contract/Kline

1. **`bitmart:fetch-contracts`** : Récupère et sauvegarde les contrats
2. **`bitmart:fetch-all-klines`** : Récupère toutes les klines
3. **`bitmart:fetch-klines`** : Récupère les klines pour un symbole
4. **`bitmart:fetch-recent-klines`** : Récupère les klines récentes
5. **`bitmart:check-klines`** : Vérifie la qualité des klines
6. **`bitmart:klines-summary`** : Résumé des klines
7. **`mtf:run`** : Utilise contracts et klines - **À ADAPTER**

---

## Changements nécessaires

### 1. Entités

#### Contract
```php
// Ajouter
#[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'bitmart'])]
private string $exchange = 'bitmart';

// Modifier contrainte unique
#[ORM\UniqueConstraint(name: 'ux_contracts_exchange_symbol', columns: ['exchange', 'symbol'])]
```

#### Kline
```php
// Ajouter
#[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'bitmart'])]
private string $exchange = 'bitmart';

// Modifier contrainte unique
#[ORM\UniqueConstraint(name: 'ux_klines_exchange_symbol_tf_open', columns: ['exchange', 'symbol', 'timeframe', 'open_time'])]
```

### 2. Repositories

#### ContractRepository
- Ajouter paramètre `?string $exchange = null` à toutes les méthodes de recherche
- Modifier les requêtes SQL pour filtrer par `exchange`
- Adapter `findBySymbol(string $symbol)` → `findBySymbol(string $symbol, ?string $exchange = null)`
- Adapter `upsertContract()` pour inclure `exchange`

#### KlineRepository
- Ajouter paramètre `?string $exchange = null` à toutes les méthodes de recherche
- Modifier les requêtes SQL pour filtrer par `exchange`
- Adapter `findBySymbolAndTimeframe()` pour inclure `exchange`
- Adapter `upsert()` et `upsertKlines()` pour inclure `exchange`

### 3. Providers

#### ExchangeProviderFactory (NOUVEAU)
```php
class ExchangeProviderFactory
{
    public function create(string $exchange): MainProviderInterface
    {
        return match($exchange) {
            'bitmart' => $this->bitmartMainProvider,
            'woox' => $this->wooxMainProvider,
            default => throw new \InvalidArgumentException("Unknown exchange: $exchange")
        };
    }
}
```

#### Providers WOO X (NOUVEAUX)
- `WooxOrderProvider` : Implémente `OrderProviderInterface`
- `WooxAccountProvider` : Implémente `AccountProviderInterface`
- `WooxKlineProvider` : Implémente `KlineProviderInterface`
- `WooxContractProvider` : Implémente `ContractProviderInterface`
- `WooxSystemProvider` : Implémente `SystemProviderInterface`

### 4. Controllers

#### MtfController::runMtfCycle()
```php
// Ajouter extraction du paramètre exchange
$exchange = $data['exchange'] ?? $request->query->get('exchange', 'bitmart');

// Valider l'exchange
if (!in_array($exchange, ['bitmart', 'woox'], true)) {
    return new JsonResponse(['error' => "Invalid exchange: $exchange"], 400);
}

// Utiliser ExchangeProviderFactory
$mainProvider = $this->exchangeProviderFactory->create($exchange);
```

#### KlinesApiController
```php
// Ajouter paramètre exchange optionnel
$exchange = $request->query->get('exchange', 'bitmart');

// Utiliser ExchangeProviderFactory
$mainProvider = $this->exchangeProviderFactory->create($exchange);
$klineProvider = $mainProvider->getKlineProvider();
```

#### ContractsController (Web)
```php
// Ajouter filtre exchange
$exchange = $request->query->get('exchange', 'bitmart');
$contracts = $this->contractRepository->findWithFilters($status, $symbol, $exchange);
```

### 5. Symbol Normalizer (NOUVEAU)

```php
class SymbolNormalizer
{
    public function normalize(string $symbol, string $exchange): string
    {
        return match($exchange) {
            'bitmart' => $this->normalizeBitmart($symbol),
            'woox' => $this->normalizeWoox($symbol),
            default => $symbol
        };
    }
    
    // Bitmart: BTCUSDT → BTCUSDT
    // WOO X: SPOT_BTC_USDT → BTCUSDT (pour stockage interne)
    // WOO X: BTCUSDT → SPOT_BTC_USDT (pour API WOO X)
}
```

---

## Structure des fichiers

### Fichiers à créer

```
trading-app/
├── src/
│   ├── Provider/
│   │   ├── ExchangeProviderFactory.php              [NEW]
│   │   ├── SymbolNormalizer.php                     [NEW]
│   │   └── Woox/                                    [NEW]
│   │       ├── WooxAccountProvider.php              [NEW]
│   │       ├── WooxContractProvider.php            [NEW]
│   │       ├── WooxKlineProvider.php                [NEW]
│   │       ├── WooxOrderProvider.php                [NEW]
│   │       ├── WooxSystemProvider.php               [NEW]
│   │       ├── Dto/                                 [NEW]
│   │       │   ├── KlineDto.php                     [NEW]
│   │       │   ├── ContractDto.php                  [NEW]
│   │       │   ├── ListKlinesDto.php                [NEW]
│   │       │   └── ListContractDto.php              [NEW]
│   │       └── Http/                                 [NEW]
│   │           ├── WooxConfig.php                    [NEW]
│   │           ├── WooxHttpClientPrivate.php        [NEW]
│   │           ├── WooxHttpClientPublic.php         [NEW]
│   │           ├── WooxRequestSigner.php            [NEW]
│   │           └── throttleWooxRequestTrait.php     [NEW]
│   ├── Provider/
│   │   └── Entity/
│   │       ├── Contract.php                         [EDIT]
│   │       └── Kline.php                            [EDIT]
│   ├── Provider/
│   │   └── Repository/
│   │       ├── ContractRepository.php               [EDIT]
│   │       └── KlineRepository.php                  [EDIT]
│   ├── Provider/
│   │   └── Command/
│   │       ├── FetchContractsCommand.php            [EDIT]
│   │       ├── FetchAllKlinesCommand.php            [EDIT]
│   │       ├── FetchKlinesCommand.php               [EDIT]
│   │       ├── FetchRecentKlinesCommand.php         [EDIT]
│   │       ├── CheckKlinesCommand.php               [EDIT]
│   │       └── KlinesSummaryCommand.php             [EDIT]
│   └── MtfValidator/
│       └── Controller/
│           └── MtfController.php                    [EDIT]
├── config/
│   ├── packages/
│   │   └── framework.yaml                           [EDIT]
│   └── services.yaml                                [EDIT]
└── migrations/
    └── VersionYYYYMMDDHHMMSS_AddExchangeToEntities.php  [NEW]
```

### Fichiers à modifier

#### Entités
- `trading-app/src/Provider/Entity/Contract.php`
- `trading-app/src/Provider/Entity/Kline.php`

#### Repositories
- `trading-app/src/Provider/Repository/ContractRepository.php`
- `trading-app/src/Provider/Repository/KlineRepository.php`

#### Controllers
- `trading-app/src/MtfValidator/Controller/MtfController.php`
- `trading-app/src/Controller/Api/KlinesApiController.php`
- `trading-app/src/Controller/Web/ContractsController.php`
- `trading-app/src/Controller/Web/KlinesWebController.php`
- `trading-app/src/Indicator/Controller/IndicatorApiController.php` (si utilise klines)

#### Commandes
- `trading-app/src/Provider/Command/FetchContractsCommand.php`
- `trading-app/src/Provider/Command/FetchAllKlinesCommand.php`
- `trading-app/src/Provider/Command/FetchKlinesCommand.php`
- `trading-app/src/Provider/Command/FetchRecentKlinesCommand.php`
- `trading-app/src/Provider/Command/CheckKlinesCommand.php`
- `trading-app/src/Provider/Command/KlinesSummaryCommand.php`
- `trading-app/src/MtfValidator/Command/MtfRunCommand.php` (si existe)

#### Configuration
- `trading-app/config/packages/framework.yaml`
- `trading-app/config/services.yaml`
- `trading-app/.env` (ajouter variables WOO X)

---

## Migrations de base de données

### Migration 1 : Ajouter colonne exchange

```php
// migrations/VersionYYYYMMDDHHMMSS_AddExchangeToEntities.php

public function up(Schema $schema): void
{
    // Ajouter colonne exchange à contracts
    $this->addSql('ALTER TABLE contracts ADD COLUMN exchange VARCHAR(20) NOT NULL DEFAULT \'bitmart\'');
    
    // Ajouter colonne exchange à klines
    $this->addSql('ALTER TABLE klines ADD COLUMN exchange VARCHAR(20) NOT NULL DEFAULT \'bitmart\'');
    
    // Supprimer anciennes contraintes uniques
    $this->addSql('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS ux_contracts_symbol');
    $this->addSql('ALTER TABLE klines DROP CONSTRAINT IF EXISTS ux_klines_symbol_tf_open');
    
    // Créer nouvelles contraintes uniques avec exchange
    $this->addSql('CREATE UNIQUE INDEX ux_contracts_exchange_symbol ON contracts(exchange, symbol)');
    $this->addSql('CREATE UNIQUE INDEX ux_klines_exchange_symbol_tf_open ON klines(exchange, symbol, timeframe, open_time)');
    
    // Ajouter index pour performance
    $this->addSql('CREATE INDEX idx_contracts_exchange ON contracts(exchange)');
    $this->addSql('CREATE INDEX idx_klines_exchange ON klines(exchange)');
    $this->addSql('CREATE INDEX idx_klines_exchange_symbol_tf ON klines(exchange, symbol, timeframe)');
}
```

### Migration 2 : Migration des données existantes

```php
// migrations/VersionYYYYMMDDHHMMSS_MigrateExistingDataToBitmart.php

public function up(Schema $schema): void
{
    // Les données existantes ont déjà exchange='bitmart' par défaut
    // Vérifier qu'il n'y a pas de doublons
    $this->addSql('
        DELETE FROM contracts c1
        WHERE EXISTS (
            SELECT 1 FROM contracts c2
            WHERE c2.symbol = c1.symbol
            AND c2.exchange = \'bitmart\'
            AND c2.id < c1.id
        )
    ');
    
    // Même chose pour klines
    $this->addSql('
        DELETE FROM klines k1
        WHERE EXISTS (
            SELECT 1 FROM klines k2
            WHERE k2.symbol = k1.symbol
            AND k2.timeframe = k1.timeframe
            AND k2.open_time = k1.open_time
            AND k2.exchange = \'bitmart\'
            AND k2.id < k1.id
        )
    ');
}
```

---

## Commandes à adapter

### 1. bitmart:fetch-contracts

**Changements** :
- Ajouter option `--exchange` (défaut: 'bitmart')
- Utiliser `ExchangeProviderFactory` pour obtenir le provider
- Passer `exchange` à `upsertContract()`

**Exemple** :
```bash
php bin/console bitmart:fetch-contracts --exchange=bitmart
php bin/console bitmart:fetch-contracts --exchange=woox
```

### 2. bitmart:fetch-all-klines

**Changements** :
- Ajouter option `--exchange` (défaut: 'bitmart')
- Utiliser `ExchangeProviderFactory` pour obtenir le provider
- Passer `exchange` lors de la sauvegarde

**Exemple** :
```bash
php bin/console bitmart:fetch-all-klines --exchange=bitmart --timeframes=4h,1h
php bin/console bitmart:fetch-all-klines --exchange=woox --timeframes=4h,1h
```

### 3. bitmart:fetch-klines

**Changements** :
- Ajouter option `--exchange` (défaut: 'bitmart')
- Utiliser `ExchangeProviderFactory` pour obtenir le provider
- Normaliser le symbole selon l'exchange

**Exemple** :
```bash
php bin/console bitmart:fetch-klines --symbol=BTCUSDT --exchange=bitmart
php bin/console bitmart:fetch-klines --symbol=BTCUSDT --exchange=woox
```

### 4. bitmart:fetch-recent-klines

**Changements** :
- Ajouter option `--exchange` (défaut: 'bitmart')
- Filtrer par exchange dans le repository

### 5. bitmart:check-klines

**Changements** :
- Ajouter option `--exchange` (défaut: 'bitmart')
- Filtrer par exchange dans les vérifications

### 6. bitmart:klines-summary

**Changements** :
- Ajouter option `--exchange` (défaut: 'bitmart')
- Grouper les statistiques par exchange

### 7. mtf:run

**Changements** :
- Ajouter option `--exchange` (défaut: 'bitmart')
- Utiliser `ExchangeProviderFactory` pour obtenir le provider
- Passer `exchange` aux services qui en ont besoin

**Exemple** :
```bash
php bin/console mtf:run --exchange=bitmart --symbols=BTCUSDT,ETHUSDT
php bin/console mtf:run --exchange=woox --symbols=BTCUSDT,ETHUSDT
```

---

## Endpoints/URLs à adapter

### API Endpoints

#### 1. GET /api/klines
**Changements** :
- Ajouter paramètre query `exchange` (optionnel, défaut: 'bitmart')
- Utiliser `ExchangeProviderFactory` pour obtenir le provider

**Exemple** :
```
GET /api/klines?symbol=BTCUSDT&interval=5m&exchange=bitmart
GET /api/klines?symbol=BTCUSDT&interval=5m&exchange=woox
```

#### 2. GET /api/contracts (si existe)
**Changements** :
- Ajouter paramètre query `exchange` (optionnel, défaut: 'bitmart')
- Filtrer par exchange dans le repository

#### 3. POST /mtf/run
**Changements** :
- Ajouter paramètre `exchange` dans query parameters ou body
- Utiliser `ExchangeProviderFactory` pour obtenir le provider

**Exemple** :
```
POST /mtf/run?exchange=bitmart&symbols=BTCUSDT,ETHUSDT
POST /mtf/run?exchange=woox&symbols=BTCUSDT,ETHUSDT
```

### Web Endpoints

#### 1. GET /contracts
**Changements** :
- Ajouter paramètre query `exchange` (optionnel, défaut: 'bitmart')
- Filtrer par exchange dans le repository

**Exemple** :
```
GET /contracts?exchange=bitmart
GET /contracts?exchange=woox
```

#### 2. GET /klines
**Changements** :
- Ajouter paramètre query `exchange` (optionnel, défaut: 'bitmart')
- Filtrer par exchange dans le repository

**Exemple** :
```
GET /klines?exchange=bitmart&symbol=BTCUSDT
GET /klines?exchange=woox&symbol=BTCUSDT
```

#### 3. GET /api/indicators/pivots
**Changements** :
- Ajouter paramètre query `exchange` (optionnel, défaut: 'bitmart')
- Utiliser le bon provider pour récupérer les klines

---

## Configuration

### Variables d'environnement (.env)

```bash
# Bitmart (existant)
BITMART_API_KEY=your_bitmart_api_key
BITMART_SECRET_KEY=your_bitmart_secret_key
BITMART_API_MEMO=your_bitmart_memo

# WOO X (nouveau)
WOOX_API_KEY=your_woox_api_key
WOOX_SECRET_KEY=your_woox_secret_key
WOOX_APPLICATION_ID=your_woox_application_id

# Exchange par défaut
DEFAULT_EXCHANGE=bitmart
```

### framework.yaml

```yaml
framework:
    http_client:
        scoped_clients:
            # Bitmart (existant)
            http_client.bitmart_futures_v2:
                base_uri: 'https://api-cloud.bitmart.com'
            http_client.bitmart_futures_v2_private:
                base_uri: 'https://api-cloud.bitmart.com'
            http_client.bitmart_system:
                base_uri: 'https://api-cloud.bitmart.com'
            
            # WOO X (nouveau)
            http_client.woox_public:
                base_uri: 'https://api-pub.woox.io'
            http_client.woox_private:
                base_uri: 'https://api.woox.io'
```

### services.yaml

```yaml
services:
    # Exchange Provider Factory
    App\Provider\ExchangeProviderFactory:
        arguments:
            $bitmartMainProvider: '@app.provider.bitmart.main'
            $wooxMainProvider: '@app.provider.woox.main'
    
    # Symbol Normalizer
    App\Provider\SymbolNormalizer: ~
    
    # WOO X Config
    App\Provider\Woox\Http\WooxConfig:
        arguments:
            $apiKey: '%env(WOOX_API_KEY)%'
            $apiSecret: '%env(WOOX_SECRET_KEY)%'
            $applicationId: '%env(WOOX_APPLICATION_ID)%'
    
    # WOO X HTTP Clients
    App\Provider\Woox\Http\WooxHttpClientPublic:
        arguments:
            $wooxPublic: '@http_client.woox_public'
    
    App\Provider\Woox\Http\WooxHttpClientPrivate:
        arguments:
            $wooxPrivate: '@http_client.woox_private'
            $signer: '@App\Provider\Woox\Http\WooxRequestSigner'
            $config: '@App\Provider\Woox\Http\WooxConfig'
    
    # WOO X Providers
    app.provider.woox.order:
        class: App\Provider\Woox\WooxOrderProvider
        arguments:
            $wooxClient: '@App\Provider\Woox\Http\WooxHttpClientPrivate'
            $wooxClientPublic: '@App\Provider\Woox\Http\WooxHttpClientPublic'
    
    app.provider.woox.account:
        class: App\Provider\Woox\WooxAccountProvider
        arguments:
            $wooxClient: '@App\Provider\Woox\Http\WooxHttpClientPrivate'
    
    app.provider.woox.kline:
        class: App\Provider\Woox\WooxKlineProvider
        arguments:
            $wooxClientPublic: '@App\Provider\Woox\Http\WooxHttpClientPublic'
            $klineRepository: '@App\Provider\Repository\KlineRepository'
    
    app.provider.woox.contract:
        class: App\Provider\Woox\WooxContractProvider
        arguments:
            $wooxClientPublic: '@App\Provider\Woox\Http\WooxHttpClientPublic'
            $contractRepository: '@App\Provider\Repository\ContractRepository'
    
    app.provider.woox.system:
        class: App\Provider\Woox\WooxSystemProvider
        arguments:
            $wooxClientPublic: '@App\Provider\Woox\Http\WooxHttpClientPublic'
    
    # WOO X Main Provider
    app.provider.woox.main:
        class: App\Provider\MainProvider
        arguments:
            $klineProvider: '@app.provider.woox.kline'
            $contractProvider: '@app.provider.woox.contract'
            $orderProvider: '@app.provider.woox.order'
            $accountProvider: '@app.provider.woox.account'
            $systemProvider: '@app.provider.woox.system'
```

---

## Mapping des symboles

### Format Bitmart
- Format : `BTCUSDT` (BASE + QUOTE)
- Exemple : `BTCUSDT`, `ETHUSDT`, `SOLUSDT`

### Format WOO X
- Format : `SPOT_BTC_USDT` (TYPE_BASE_QUOTE)
- Types : `SPOT`, `PERP`, `FUTURES`
- Exemple : `SPOT_BTC_USDT`, `PERP_ETH_USDT`, `FUTURES_SOL_USDT`

### Normalisation

#### Stockage interne
- Toujours stocker en format Bitmart (`BTCUSDT`)
- Convertir lors des appels API WOO X

#### SymbolNormalizer

```php
class SymbolNormalizer
{
    /**
     * Normalise un symbole pour le stockage interne (format Bitmart)
     */
    public function normalizeForStorage(string $symbol, string $exchange): string
    {
        if ($exchange === 'woox') {
            // SPOT_BTC_USDT → BTCUSDT
            return $this->wooxToInternal($symbol);
        }
        return $symbol; // Bitmart déjà au bon format
    }
    
    /**
     * Convertit un symbole interne vers le format de l'exchange
     */
    public function normalizeForExchange(string $symbol, string $exchange): string
    {
        if ($exchange === 'woox') {
            // BTCUSDT → SPOT_BTC_USDT (par défaut SPOT, peut être configuré)
            return $this->internalToWoox($symbol, 'SPOT');
        }
        return $symbol; // Bitmart déjà au bon format
    }
    
    private function wooxToInternal(string $wooxSymbol): string
    {
        // SPOT_BTC_USDT → BTCUSDT
        $parts = explode('_', $wooxSymbol);
        if (count($parts) === 3) {
            return $parts[1] . $parts[2];
        }
        return $wooxSymbol;
    }
    
    private function internalToWoox(string $symbol, string $type = 'SPOT'): string
    {
        // BTCUSDT → SPOT_BTC_USDT
        // Détecter BASE et QUOTE (suppose QUOTE = USDT, USDC, etc.)
        if (str_ends_with($symbol, 'USDT')) {
            $base = substr($symbol, 0, -4);
            return "{$type}_{$base}_USDT";
        }
        // Logique similaire pour autres quotes
        return $symbol;
    }
}
```

---

## Tests et validation

### Tests unitaires

#### 1. SymbolNormalizerTest
- Test conversion Bitmart → Bitmart (identique)
- Test conversion WOO X → interne
- Test conversion interne → WOO X
- Test cas limites (symboles invalides)

#### 2. ExchangeProviderFactoryTest
- Test création provider Bitmart
- Test création provider WOO X
- Test exception pour exchange invalide

#### 3. ContractRepositoryTest
- Test `findBySymbol()` avec exchange
- Test `upsertContract()` avec exchange
- Test contrainte unique (exchange, symbol)

#### 4. KlineRepositoryTest
- Test `findBySymbolAndTimeframe()` avec exchange
- Test `upsertKlines()` avec exchange
- Test contrainte unique (exchange, symbol, timeframe, open_time)

### Tests d'intégration

#### 1. WOO X API Connection
- Test connexion API publique
- Test authentification API privée
- Test récupération contrats
- Test récupération klines

#### 2. Providers WOO X
- Test `WooxOrderProvider::placeOrder()`
- Test `WooxAccountProvider::getAccountBalance()`
- Test `WooxKlineProvider::getKlines()`
- Test `WooxContractProvider::getContracts()`

#### 3. MtfController
- Test `runMtfCycle()` avec exchange=bitmart
- Test `runMtfCycle()` avec exchange=woox
- Test validation exchange invalide

### Tests de migration

#### 1. Migration des données
- Vérifier que les données existantes ont `exchange='bitmart'`
- Vérifier qu'il n'y a pas de doublons après migration
- Vérifier que les contraintes uniques fonctionnent

#### 2. Rétrocompatibilité
- Vérifier que les endpoints sans paramètre `exchange` fonctionnent (défaut: bitmart)
- Vérifier que les commandes sans option `--exchange` fonctionnent

---

## Plan d'exécution

### Phase 1 : Préparation (Jour 1-2)

1. ✅ Créer ce document de préparation
2. Créer la migration pour ajouter `exchange` aux entités
3. Créer `SymbolNormalizer`
4. Créer `ExchangeProviderFactory` (squelette)

### Phase 2 : Entités et Repositories (Jour 3-4)

1. Modifier `Contract` : ajouter champ `exchange`
2. Modifier `Kline` : ajouter champ `exchange`
3. Modifier `ContractRepository` : ajouter filtres par exchange
4. Modifier `KlineRepository` : ajouter filtres par exchange
5. Exécuter les migrations
6. Tester les repositories

### Phase 3 : Providers WOO X (Jour 5-10)

1. Créer `WooxConfig`
2. Créer `WooxRequestSigner` (authentification v3)
3. Créer `WooxHttpClientPublic`
4. Créer `WooxHttpClientPrivate`
5. Créer `WooxKlineProvider`
6. Créer `WooxContractProvider`
7. Créer `WooxOrderProvider`
8. Créer `WooxAccountProvider`
9. Créer `WooxSystemProvider`
10. Configurer `services.yaml`

### Phase 4 : Controllers et Commandes (Jour 11-12)

1. Modifier `MtfController::runMtfCycle()` pour accepter `exchange`
2. Modifier `KlinesApiController` pour accepter `exchange`
3. Modifier `ContractsController` (Web) pour accepter `exchange`
4. Modifier `KlinesWebController` pour accepter `exchange`
5. Modifier toutes les commandes pour accepter `--exchange`
6. Tester les endpoints et commandes

### Phase 5 : Tests et validation (Jour 13-14)

1. Tests unitaires
2. Tests d'intégration
3. Tests de migration
4. Tests de rétrocompatibilité
5. Documentation

### Phase 6 : Déploiement (Jour 15)

1. Review du code
2. Tests en staging
3. Déploiement en production
4. Monitoring

---

## Notes importantes

### Authentification WOO X

WOO X utilise l'authentification v3 qui nécessite :
- `timestamp` : Timestamp en millisecondes
- `request_method` : GET, POST, etc.
- `request_path` : Chemin de la requête (ex: `/v1/order`)
- `request_body` : Corps de la requête (JSON stringifié)

Signature : `HMAC-SHA256(timestamp + request_method + request_path + request_body, secret)`

Headers requis :
- `x-api-key` : API key
- `x-api-timestamp` : Timestamp
- `x-api-signature` : Signature

### Rate Limiting WOO X

- **Public endpoints** : Limite par IP
- **Private endpoints** : Limite par application ID (compte)
- **WebSocket** : 80 connexions max par compte, 50 topics max par connexion

### Différences API WOO X vs Bitmart

| Aspect | Bitmart | WOO X |
|--------|---------|-------|
| Format symboles | `BTCUSDT` | `SPOT_BTC_USDT` |
| Authentification | HMAC avec timestamp + body | HMAC v3 (timestamp + method + path + body) |
| Format ordres | `side` (1/4), `size` (int) | `side` (BUY/SELL), `order_quantity` (string) |
| Endpoints | `/contract/private/...` | `/v1/order`, `/v1/balance` |
| Rate limit | Par endpoint | Par IP (public) / Application ID (private) |

---

## Checklist finale

### Avant de commencer
- [ ] Document de préparation validé
- [ ] Credentials WOO X obtenus (ou planifié)
- [ ] Environnement de test WOO X configuré

### Phase 1 : Préparation
- [ ] Migration créée
- [ ] SymbolNormalizer créé
- [ ] ExchangeProviderFactory créé (squelette)

### Phase 2 : Entités
- [ ] Contract modifié
- [ ] Kline modifié
- [ ] ContractRepository modifié
- [ ] KlineRepository modifié
- [ ] Migrations exécutées

### Phase 3 : Providers WOO X
- [ ] WooxConfig créé
- [ ] WooxRequestSigner créé
- [ ] WooxHttpClientPublic créé
- [ ] WooxHttpClientPrivate créé
- [ ] Tous les providers WOO X créés
- [ ] services.yaml configuré

### Phase 4 : Controllers et Commandes
- [ ] MtfController modifié
- [ ] KlinesApiController modifié
- [ ] ContractsController modifié
- [ ] KlinesWebController modifié
- [ ] Toutes les commandes modifiées

### Phase 5 : Tests
- [ ] Tests unitaires écrits et passent
- [ ] Tests d'intégration écrits et passent
- [ ] Tests de migration exécutés
- [ ] Rétrocompatibilité vérifiée

### Phase 6 : Déploiement
- [ ] Code review effectué
- [ ] Tests en staging réussis
- [ ] Documentation mise à jour
- [ ] Déploiement en production

---

## Références

- [Documentation WOO X REST API](https://docs.woox.io/#restful-api)
- [Documentation WOO X Authentication](https://docs.woox.io/#authentication)
- Architecture actuelle : `trading-app/src/Provider/README.md`
- Exemples Bitmart : `trading-app/src/Provider/Bitmart/`

---

**Document créé le** : 2025-01-XX  
**Dernière mise à jour** : 2025-01-XX  
**Auteur** : Équipe de développement

