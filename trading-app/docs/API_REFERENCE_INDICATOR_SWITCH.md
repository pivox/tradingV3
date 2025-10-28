# API Reference - Système de Switch PHP/SQL

## 📚 Vue d'ensemble

Cette documentation détaille l'API du système de switch PHP/SQL pour les indicateurs techniques.

## 🔧 Services

### `IndicatorCalculationModeService`

Service de gestion du mode de calcul des indicateurs.

#### Méthodes

##### `getCurrentMode(): string`
Retourne le mode de calcul actuel.

**Retour :** `string` - Mode actuel (`php` ou `sql`)

**Exemple :**
```php
$mode = $modeService->getCurrentMode();
// Retourne : 'sql'
```

##### `getMode(): string`
Alias de `getCurrentMode()`.

##### `isSqlMode(): bool`
Vérifie si le mode actuel est SQL.

**Retour :** `bool` - `true` si mode SQL, `false` sinon

**Exemple :**
```php
if ($modeService->isSqlMode()) {
    // Mode SQL actif
}
```

##### `isPhpMode(): bool`
Vérifie si le mode actuel est PHP.

**Retour :** `bool` - `true` si mode PHP, `false` sinon

##### `getPerformanceThreshold(): int`
Retourne le seuil de performance en millisecondes.

**Retour :** `int` - Seuil en millisecondes

**Exemple :**
```php
$threshold = $modeService->getPerformanceThreshold();
// Retourne : 100
```

##### `isFallbackEnabled(): bool`
Vérifie si le fallback vers PHP est activé.

**Retour :** `bool` - `true` si fallback activé

##### `setMode(string $mode): void`
Définit le mode de calcul.

**Paramètres :**
- `$mode` (string) : Mode à définir (`php` ou `sql`)

**Exemple :**
```php
$modeService->setMode('sql');
```

##### `getCalculationMode(string $indicatorName, string $symbol, string $timeframe): string`
Détermine le mode de calcul à utiliser pour un indicateur donné.

**Paramètres :**
- `$indicatorName` (string) : Nom de l'indicateur
- `$symbol` (string) : Symbole de trading
- `$timeframe` (string) : Timeframe

**Retour :** `string` - Mode recommandé

##### `recordPerformance(string $indicatorName, string $symbol, string $timeframe, int $executionTimeMs, bool $success): void`
Enregistre les métriques de performance.

**Paramètres :**
- `$indicatorName` (string) : Nom de l'indicateur
- `$symbol` (string) : Symbole
- `$timeframe` (string) : Timeframe
- `$executionTimeMs` (int) : Temps d'exécution en ms
- `$success` (bool) : Succès de l'opération

##### `getPerformanceMetrics(): array`
Retourne les métriques de performance.

**Retour :** `array` - Métriques détaillées

##### `getPerformanceSummary(): array`
Retourne un résumé des performances.

**Retour :** `array` - Résumé des performances

**Exemple :**
```php
$summary = $modeService->getPerformanceSummary();
/*
Retourne :
[
    'total_indicators' => 5,
    'avg_performance_ms' => 25.5,
    'slowest_indicator' => 'macd:BTCUSDT:5m',
    'fastest_indicator' => 'ema:BTCUSDT:5m',
    'success_rate' => 98.5
]
*/
```

### `HybridIndicatorService`

Service principal implémentant `IndicatorProviderPort`.

#### Méthodes

##### `calculateIndicators(string $symbol, Timeframe $timeframe, array $klines): IndicatorSnapshotDto`
Calcule tous les indicateurs pour un symbole et timeframe donnés.

**Paramètres :**
- `$symbol` (string) : Symbole de trading
- `$timeframe` (Timeframe) : Timeframe
- `$klines` (array) : Données de bougies

**Retour :** `IndicatorSnapshotDto` - Snapshot des indicateurs

**Exemple :**
```php
$klines = [
    [
        'symbol' => 'BTCUSDT',
        'timeframe' => '5m',
        'open_time' => new DateTimeImmutable(),
        'open_price' => 50000,
        'high_price' => 50100,
        'low_price' => 49900,
        'close_price' => 50050,
        'volume' => 1000
    ]
];

$snapshot = $indicatorService->calculateIndicators('BTCUSDT', Timeframe::from('5m'), $klines);
```

##### `getLastIndicatorSnapshot(string $symbol, Timeframe $timeframe): ?IndicatorSnapshotDto`
Récupère le dernier snapshot d'indicateurs.

**Paramètres :**
- `$symbol` (string) : Symbole
- `$timeframe` (Timeframe) : Timeframe

**Retour :** `?IndicatorSnapshotDto` - Dernier snapshot ou `null`

##### `getIndicatorSnapshots(string $symbol, Timeframe $timeframe, int $limit = 100): array`
Récupère les snapshots d'indicateurs pour une période.

**Paramètres :**
- `$symbol` (string) : Symbole
- `$timeframe` (Timeframe) : Timeframe
- `$limit` (int) : Nombre maximum de snapshots

**Retour :** `array` - Tableau de `IndicatorSnapshotDto`

##### `calculateEMA(array $prices, int $period): array`
Calcule l'EMA pour une série de prix.

**Paramètres :**
- `$prices` (array) : Série de prix
- `$period` (int) : Période de l'EMA

**Retour :** `array` - Série d'EMA

##### `calculateRSI(array $prices, int $period = 14): array`
Calcule le RSI pour une série de prix.

**Paramètres :**
- `$prices` (array) : Série de prix
- `$period` (int) : Période du RSI (défaut: 14)

**Retour :** `array` - Série de RSI

##### `calculateMACD(array $prices, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): array`
Calcule le MACD pour une série de prix.

**Paramètres :**
- `$prices` (array) : Série de prix
- `$fastPeriod` (int) : Période rapide (défaut: 12)
- `$slowPeriod` (int) : Période lente (défaut: 26)
- `$signalPeriod` (int) : Période du signal (défaut: 9)

**Retour :** `array` - Données MACD

**Exemple :**
```php
$macd = $indicatorService->calculateMACD($prices);
/*
Retourne :
[
    'macd' => -12.34,
    'signal' => -8.76,
    'histogram' => -3.58
]
*/
```

##### `calculateVWAP(array $klines): array`
Calcule le VWAP pour une série de bougies.

**Paramètres :**
- `$klines` (array) : Série de bougies

**Retour :** `array` - Série de VWAP

##### `calculateBollingerBands(array $prices, int $period = 20, float $stdDev = 2.0): array`
Calcule les Bandes de Bollinger.

**Paramètres :**
- `$prices` (array) : Série de prix
- `$period` (int) : Période (défaut: 20)
- `$stdDev` (float) : Écart-type (défaut: 2.0)

**Retour :** `array` - Données des bandes

##### `getModeService(): IndicatorCalculationModeService`
Retourne le service de gestion du mode.

**Retour :** `IndicatorCalculationModeService`

### `PhpIndicatorService`

Service de calculs d'indicateurs en PHP.

#### Méthodes

Toutes les méthodes de `HybridIndicatorService` sont disponibles avec le même comportement, mais utilisant les calculs PHP.

### `SqlIndicatorService`

Service de calculs d'indicateurs via SQL.

#### Méthodes

Toutes les méthodes de `HybridIndicatorService` sont disponibles avec le même comportement, mais utilisant les vues matérialisées SQL.

## 📊 DTOs

### `IndicatorSnapshotDto`

DTO représentant un snapshot d'indicateurs.

#### Propriétés

| Propriété | Type | Description |
|-----------|------|-------------|
| `symbol` | `string` | Symbole de trading |
| `timeframe` | `Timeframe` | Timeframe |
| `klineTime` | `DateTimeImmutable` | Temps de la bougie |
| `ema20` | `?BigDecimal` | EMA 20 périodes |
| `ema50` | `?BigDecimal` | EMA 50 périodes |
| `macd` | `?BigDecimal` | MACD |
| `macdSignal` | `?BigDecimal` | Signal MACD |
| `macdHistogram` | `?BigDecimal` | Histogramme MACD |
| `atr` | `?BigDecimal` | ATR |
| `rsi` | `?float` | RSI |
| `vwap` | `?BigDecimal` | VWAP |
| `bbUpper` | `?BigDecimal` | Bande de Bollinger supérieure |
| `bbMiddle` | `?BigDecimal` | Bande de Bollinger médiane |
| `bbLower` | `?BigDecimal` | Bande de Bollinger inférieure |
| `ma9` | `?BigDecimal` | Moyenne mobile 9 périodes |
| `ma21` | `?BigDecimal` | Moyenne mobile 21 périodes |
| `meta` | `array` | Métadonnées |

#### Méthodes

##### `toArray(): array`
Convertit le DTO en tableau.

**Retour :** `array` - Représentation en tableau

**Exemple :**
```php
$array = $snapshot->toArray();
/*
Retourne :
[
    'symbol' => 'BTCUSDT',
    'timeframe' => '5m',
    'kline_time' => '2025-01-15 10:30:00',
    'ema20' => '50123.45',
    'ema50' => '49987.65',
    'rsi' => 65.2,
    'macd' => '-12.34',
    // ...
]
*/
```

##### `fromArray(array $data): self`
Crée un DTO à partir d'un tableau.

**Paramètres :**
- `$data` (array) : Données en tableau

**Retour :** `self` - Instance du DTO

##### `isMacdBullish(): bool`
Vérifie si le MACD est haussier.

**Retour :** `bool` - `true` si MACD > Signal

##### `isMacdBearish(): bool`
Vérifie si le MACD est baissier.

**Retour :** `bool` - `true` si MACD < Signal

##### `isRsiOverbought(): bool`
Vérifie si le RSI est en surachat.

**Retour :** `bool` - `true` si RSI > 70

##### `isRsiOversold(): bool`
Vérifie si le RSI est en survente.

**Retour :** `bool` - `true` si RSI < 30

##### `isRsiNeutral(): bool`
Vérifie si le RSI est neutre.

**Retour :** `bool` - `true` si 30 ≤ RSI ≤ 70

## 🎮 Commandes CLI

### `app:test-indicator-calculation`

Teste le système de calcul d'indicateurs.

**Usage :**
```bash
bin/console app:test-indicator-calculation <symbol> <timeframe>
```

**Paramètres :**
- `symbol` : Symbole de trading (ex: BTCUSDT)
- `timeframe` : Timeframe (ex: 5m)

**Exemple :**
```bash
bin/console app:test-indicator-calculation BTCUSDT 5m
```

**Sortie :**
```
Testing Indicator Calculation System
====================================

 Symbol: BTCUSDT
 Timeframe: 5m

Testing Indicator Calculation
-----------------------------

 [OK] Indicator calculation completed successfully!                             

 Duration: 2.91ms

Results
-------

 ----------- ------------ 
  Indicator   Value       
 ----------- ------------ 
  EMA20       50193.5655  
  EMA50       49966.6354  
  RSI         83.38       
  MACD        N/A         
  VWAP        50058.218   
 ----------- ------------ 

PHP calculation took 3ms
```

## 🔧 Configuration

### Variables d'environnement

| Variable | Défaut | Description |
|----------|--------|-------------|
| `INDICATOR_MODE` | `sql` | Mode de calcul par défaut |
| `INDICATOR_FALLBACK` | `true` | Activation du fallback |
| `INDICATOR_THRESHOLD` | `100` | Seuil de performance en ms |

### Fichier de configuration

```yaml
# config/trading.yml
indicator_calculation:
    mode: sql                            # Mode par défaut
    fallback_to_php: true                # Fallback activé
    performance_threshold_ms: 100        # Seuil de performance
```

## 🚨 Gestion d'erreurs

### Exceptions

#### `InvalidArgumentException`
Levée lors de paramètres invalides.

**Exemple :**
```php
try {
    $modeService->setMode('invalid');
} catch (InvalidArgumentException $e) {
    // Mode invalide
}
```

#### `RuntimeException`
Levée lors d'erreurs de calcul.

**Exemple :**
```php
try {
    $snapshot = $indicatorService->calculateIndicators($symbol, $timeframe, $klines);
} catch (RuntimeException $e) {
    // Erreur de calcul
}
```

### Codes d'erreur

| Code | Description |
|------|-------------|
| `INDICATOR_INVALID_MODE` | Mode de calcul invalide |
| `INDICATOR_CALCULATION_FAILED` | Échec du calcul |
| `INDICATOR_DATA_NOT_FOUND` | Données non trouvées |
| `INDICATOR_PERFORMANCE_DEGRADED` | Performance dégradée |

## 📈 Exemples d'utilisation

### Exemple complet

```php
<?php

use App\Common\Enum\Timeframe;use App\Indicator\Loader\HybridIndicatorService;

class TradingAnalyzer
{
    public function __construct(
        private readonly HybridIndicatorService $indicatorService
    ) {}
    
    public function analyzeMarket(string $symbol, string $timeframe, array $klines): array
    {
        $timeframeEnum = Timeframe::from($timeframe);
        
        // Calcul des indicateurs
        $snapshot = $this->indicatorService->calculateIndicators($symbol, $timeframeEnum, $klines);
        
        // Analyse des signaux
        $signals = [];
        
        if ($snapshot->isMacdBullish()) {
            $signals[] = 'MACD_BULLISH';
        }
        
        if ($snapshot->isRsiOverbought()) {
            $signals[] = 'RSI_OVERBOUGHT';
        }
        
        if ($snapshot->isRsiOversold()) {
            $signals[] = 'RSI_OVERSOLD';
        }
        
        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'indicators' => $snapshot->toArray(),
            'signals' => $signals,
            'timestamp' => $snapshot->klineTime->format('Y-m-d H:i:s')
        ];
    }
}
```

### Exemple avec gestion d'erreurs

```php
<?php

use App\Common\Enum\Timeframe;use App\Indicator\Loader\HybridIndicatorService;use Psr\Log\LoggerInterface;

class RobustTradingAnalyzer
{
    public function __construct(
        private readonly HybridIndicatorService $indicatorService,
        private readonly LoggerInterface $logger
    ) {}
    
    public function analyzeMarket(string $symbol, string $timeframe, array $klines): ?array
    {
        try {
            $timeframeEnum = Timeframe::from($timeframe);
            $snapshot = $this->indicatorService->calculateIndicators($symbol, $timeframeEnum, $klines);
            
            return $this->processSnapshot($snapshot);
            
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Invalid parameters for indicator calculation', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage()
            ]);
            return null;
            
        } catch (RuntimeException $e) {
            $this->logger->error('Indicator calculation failed', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage()
            ]);
            return null;
            
        } catch (Exception $e) {
            $this->logger->critical('Unexpected error in indicator calculation', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    private function processSnapshot(IndicatorSnapshotDto $snapshot): array
    {
        // Traitement du snapshot
        return $snapshot->toArray();
    }
}
```

---

**Version :** 1.0  
**Dernière mise à jour :** 2025-01-15  
**Auteur :** Équipe Trading V3
