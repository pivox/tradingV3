# Guide de Migration - Système de Switch PHP/SQL

## 🎯 Objectif

Ce guide vous accompagne dans la migration vers le nouveau système de switch PHP/SQL pour les indicateurs techniques.

## 📋 Prérequis

- ✅ Symfony 6.4+
- ✅ PostgreSQL 14+
- ✅ PHP 8.2+
- ✅ Vues matérialisées configurées
- ✅ Services d'indicateurs existants

## 🔄 Étapes de migration

### 1. Mise à jour de la configuration

#### Avant (ancien système)
```php
// Utilisation directe des services d'indicateurs
$emaService = $this->container->get('App\Indicator\Trend\Ema');
$rsiService = $this->container->get('App\Indicator\Momentum\Rsi');

$ema = $emaService->calculate($prices, 20);
$rsi = $rsiService->calculate($prices, 14);
```

#### Après (nouveau système)
```php
// Utilisation du service hybride
$indicatorService = $this->container->get('App\Service\Indicator\HybridIndicatorService');

$snapshot = $indicatorService->calculateIndicators($symbol, $timeframe, $klines);
$ema20 = $snapshot->ema20;
$rsi = $snapshot->rsi;
```

### 2. Mise à jour des services

#### Ancien service
```php
class TradingService
{
    public function __construct(
        private Ema $emaService,
        private Rsi $rsiService,
        private Macd $macdService
    ) {}
    
    public function calculateIndicators(array $prices): array
    {
        return [
            'ema20' => $this->emaService->calculate($prices, 20),
            'rsi' => $this->rsiService->calculate($prices, 14),
            'macd' => $this->macdService->calculateFull($prices)
        ];
    }
}
```

#### Nouveau service
```php
class TradingService
{
    public function __construct(
        private HybridIndicatorService $indicatorService
    ) {}
    
    public function calculateIndicators(string $symbol, Timeframe $timeframe, array $klines): IndicatorSnapshotDto
    {
        return $this->indicatorService->calculateIndicators($symbol, $timeframe, $klines);
    }
}
```

### 3. Mise à jour des contrôleurs

#### Avant
```php
#[Route('/api/indicators/{symbol}')]
public function getIndicators(string $symbol, Request $request): JsonResponse
{
    $timeframe = $request->query->get('timeframe', '5m');
    $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe);
    
    $prices = array_column($klines, 'close_price');
    
    $indicators = [
        'ema20' => $this->emaService->calculate($prices, 20),
        'rsi' => $this->rsiService->calculate($prices, 14),
        'macd' => $this->macdService->calculateFull($prices)
    ];
    
    return new JsonResponse($indicators);
}
```

#### Après
```php
#[Route('/api/indicators/{symbol}')]
public function getIndicators(string $symbol, Request $request): JsonResponse
{
    $timeframe = Timeframe::from($request->query->get('timeframe', '5m'));
    $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe);
    
    $snapshot = $this->indicatorService->calculateIndicators($symbol, $timeframe, $klines);
    
    return new JsonResponse($snapshot->toArray());
}
```

### 4. Mise à jour des tests

#### Ancien test
```php
public function testEmaCalculation(): void
{
    $prices = [100, 101, 102, 103, 104];
    $ema = $this->emaService->calculate($prices, 3);
    
    $this->assertIsFloat($ema);
    $this->assertGreaterThan(100, $ema);
}
```

#### Nouveau test
```php
public function testIndicatorCalculation(): void
{
    $symbol = 'BTCUSDT';
    $timeframe = Timeframe::from('5m');
    $klines = $this->createMockKlines($symbol, $timeframe);
    
    $snapshot = $this->indicatorService->calculateIndicators($symbol, $timeframe, $klines);
    
    $this->assertInstanceOf(IndicatorSnapshotDto::class, $snapshot);
    $this->assertEquals($symbol, $snapshot->symbol);
    $this->assertEquals($timeframe, $snapshot->timeframe);
    $this->assertNotNull($snapshot->ema20);
    $this->assertNotNull($snapshot->rsi);
}
```

## 🔧 Configuration des services

### 1. Mise à jour de `services.yaml`

```yaml
# config/services.yaml
services:
    # Alias principal pour l'interface
    App\Domain\Ports\Out\IndicatorProviderPort: '@App\Service\Indicator\HybridIndicatorService'
    
    # Services du système de switch
    App\Service\Indicator\IndicatorCalculationModeService: ~
    App\Service\Indicator\SqlIndicatorService: ~
    App\Service\Indicator\PhpIndicatorService: ~
    App\Service\Indicator\HybridIndicatorService: ~
```

### 2. Configuration de `trading.yml`

```yaml
# config/trading.yml
indicator_calculation:
    mode: sql                            # Mode par défaut
    fallback_to_php: true                # Fallback activé
    performance_threshold_ms: 100        # Seuil de performance
```

## 🧪 Tests de migration

### 1. Test de compatibilité

```bash
# Vérifier que les anciens services fonctionnent encore
docker exec trading_app_php bin/console debug:container | grep Indicator
```

### 2. Test du nouveau système

```bash
# Tester le système de switch
./scripts/test_indicator_modes.sh BTCUSDT 5m
```

### 3. Test de performance

```bash
# Comparer les performances
docker exec trading_app_php bin/console app:test-indicator-calculation BTCUSDT 5m
```

## 🚨 Points d'attention

### 1. Changements de signature

| Ancien | Nouveau |
|--------|---------|
| `calculate(array $prices, int $period): float` | `calculateIndicators(string $symbol, Timeframe $timeframe, array $klines): IndicatorSnapshotDto` |
| Retour de valeurs individuelles | Retour d'un DTO complet |
| Paramètres d'indicateur spécifiques | Paramètres génériques |

### 2. Gestion des erreurs

#### Avant
```php
try {
    $ema = $this->emaService->calculate($prices, 20);
} catch (Exception $e) {
    // Gestion d'erreur spécifique à l'EMA
}
```

#### Après
```php
try {
    $snapshot = $this->indicatorService->calculateIndicators($symbol, $timeframe, $klines);
} catch (Exception $e) {
    // Gestion d'erreur unifiée avec fallback automatique
    $this->logger->error('Indicator calculation failed', ['error' => $e->getMessage()]);
}
```

### 3. Types de données

#### Avant
```php
$indicators = [
    'ema20' => 50123.45,      // float
    'rsi' => 65.2,            // float
    'macd' => -12.34          // float
];
```

#### Après
```php
$snapshot = new IndicatorSnapshotDto(
    symbol: 'BTCUSDT',
    timeframe: Timeframe::from('5m'),
    klineTime: new DateTimeImmutable(),
    ema20: BigDecimal::of('50123.45'),    // BigDecimal
    rsi: 65.2,                           // float
    macd: BigDecimal::of('-12.34')       // BigDecimal
);
```

## 📊 Migration par étapes

### Phase 1 : Préparation (1-2 jours)
- [ ] Mise à jour de la configuration
- [ ] Installation des nouveaux services
- [ ] Tests de base

### Phase 2 : Migration progressive (3-5 jours)
- [ ] Migration des services critiques
- [ ] Tests de régression
- [ ] Validation des performances

### Phase 3 : Finalisation (1-2 jours)
- [ ] Migration des services restants
- [ ] Nettoyage du code legacy
- [ ] Documentation finale

## 🔍 Validation post-migration

### 1. Vérification fonctionnelle

```bash
# Test complet du système
./scripts/test_indicator_modes.sh BTCUSDT 5m

# Vérification des performances
docker exec trading_app_php bin/console app:indicator:performance-report
```

### 2. Vérification des données

```sql
-- Comparer les résultats PHP vs SQL
SELECT 
    'PHP' as mode,
    COUNT(*) as records
FROM indicator_snapshots 
WHERE created_at > NOW() - INTERVAL '1 hour'

UNION ALL

SELECT 
    'SQL' as mode,
    COUNT(*) as records
FROM mv_ema_5m 
WHERE bucket > NOW() - INTERVAL '1 hour';
```

### 3. Monitoring continu

```bash
# Surveillance des performances
watch -n 30 './scripts/monitor_indicator_performance.sh'

# Surveillance des erreurs
tail -f var/log/prod.log | grep "indicator"
```

## 🆘 Support et dépannage

### Problèmes courants

#### 1. Service non trouvé
```bash
# Solution
docker exec trading_app_php bin/console cache:clear
docker exec trading_app_php bin/console debug:container IndicatorCalculationModeService
```

#### 2. Erreurs de configuration
```bash
# Vérification
docker exec trading_app_php bin/console debug:config trading
```

#### 3. Performances dégradées
```bash
# Diagnostic
docker exec trading_app_php bin/console app:indicator:performance-report
./scripts/refresh_indicators.sh
```

### Contacts

- **Équipe Backend** : backend@trading-v3.com
- **Équipe DevOps** : devops@trading-v3.com
- **Documentation** : docs@trading-v3.com

## 📚 Ressources

- [Documentation du système de switch](./INDICATOR_SWITCH_SYSTEM.md)
- [API Reference](./API_REFERENCE.md)
- [Troubleshooting Guide](./TROUBLESHOOTING.md)

---

**Version :** 1.0  
**Dernière mise à jour :** 2025-01-15  
**Auteur :** Équipe Trading V3
