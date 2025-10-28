# Architecture des Providers

Cette architecture modulaire permet d'utiliser différents providers (Bitmart, Binance, etc.) de manière uniforme.

## Structure

```
Provider/
├── ProviderInterface.php              # Interface de base
├── KlineProviderInterface.php         # Interface pour les klines
├── ContractProviderInterface.php      # Interface pour les contrats
├── OrderProviderInterface.php         # Interface pour les ordres
├── AccountProviderInterface.php       # Interface pour les comptes
├── ProviderService.php               # Service central
├── Dto/                              # DTOs pour chaque contexte
│   ├── BaseDto.php
│   ├── KlineDto.php
│   ├── ContractDto.php
│   ├── OrderDto.php
│   ├── AccountDto.php
│   └── PositionDto.php
└── Bitmart/                          # Implémentation Bitmart
    ├── BitmartKlineProvider.php
    ├── BitmartContractProvider.php
    ├── BitmartOrderProvider.php
    ├── BitmartAccountProvider.php
    ├── Service/
    │   ├── BitmartProviderService.php
    │   └── BitmartMigrationService.php
    └── Example/
        └── BitmartProviderUsageExample.php
```

## Utilisation

### Via le ProviderService (recommandé)

```php
use App\Provider\MainProvider;

class MyService
{
    public function __construct(
        private readonly MainProvider $providerService
    ) {}

    public function getKlines(string $symbol): array
    {
        return $this->providerService
            ->getKlineProvider()
            ->getKlines($symbol, Timeframe::TF_1H, 100);
    }
}
```

### Via les providers spécifiques

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

## Configuration des Services

Les services sont auto‑découverts via `services.yaml` (autowire/autoconfigure). Vous pouvez utiliser des attributs PHP 8 pour affiner, par exemple un alias :

```php
#[AsAlias(id: 'app.provider.kline')]
public function createKlineProvider(): BitmartKlineProvider
{
    // ...
}
```

## Migration

Pour faciliter la migration, utilisez le `BitmartMigrationService` :

```php
use App\Provider\Bitmart\Service\BitmartMigrationMain;

class MyService
{
    public function __construct(
        private readonly BitmartMigrationMain $migrationService
    ) {}

    public function getKlines(string $symbol): array
    {
        return $this->migrationService
            ->getKlineProvider()
            ->getKlines($symbol, Timeframe::TF_1H, 100);
    }
}
```

## Avantages

1. **Modularité** : Chaque provider est indépendant
2. **Testabilité** : Interfaces facilement mockables
3. **Extensibilité** : Ajout facile de nouveaux providers
4. **Uniformité** : API cohérente entre tous les providers
5. **Type Safety** : DTOs typés avec validation

