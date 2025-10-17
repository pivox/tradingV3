# Système de Backtesting avec Heure Fixe

Ce document explique comment utiliser le système de backtesting avec heure fixe dans l'application trading-app.

## Vue d'ensemble

Le système utilise le composant `symfony/clock` pour permettre de fixer une heure spécifique lors du backtesting, facilitant ainsi la reproduction et la validation des stratégies de trading.

## Installation

Le package `symfony/clock` a été installé via Composer :

```bash
composer require symfony/clock
```

## Services modifiés

Les services suivants ont été modifiés pour utiliser `ClockInterface` :

- `MtfTimeService` - Service de gestion du temps pour MTF
- `TokenBucketRateLimiter` - Rate limiter pour les API
- `TradingDecisionService` - Service de décision de trading
- `MtfService` - Service principal MTF
- `MtfController` - Contrôleur MTF

## Utilisation

### 1. Service BacktestClockService

Le service `BacktestClockService` fournit une interface simple pour gérer l'heure fixe :

```php
use App\Service\BacktestClockService;

// Définir une heure fixe
$backtestClockService->setFixedTime(new \DateTimeImmutable('2024-01-15 10:30:00'));

// Obtenir l'heure actuelle (fixe ou réelle)
$currentTime = $backtestClockService->now();

// Avancer l'heure fixe
$backtestClockService->advanceFixedTimeMinutes(15);

// Retirer l'heure fixe
$backtestClockService->clearFixedTime();
```

### 2. Commande Symfony

Une commande est disponible pour gérer l'heure fixe :

```bash
# Afficher l'heure fixe actuelle
php bin/console app:backtest:set-fixed-time --show

# Définir une heure fixe
php bin/console app:backtest:set-fixed-time "2024-01-15 10:30:00"

# Avancer l'heure fixe de 60 minutes
php bin/console app:backtest:set-fixed-time --advance=60

# Retirer l'heure fixe
php bin/console app:backtest:set-fixed-time --clear
```

### 3. Configuration

Vous pouvez définir une heure fixe via la configuration dans `services.yaml` :

```yaml
parameters:
    app.clock.fixed_time: '2024-01-15 10:30:00'
```

## Exemples d'utilisation

### Exemple 1 : Backtesting simple

```php
use App\Service\BacktestClockService;

$backtestClockService = new BacktestClockService($clock);

// Définir l'heure de début du backtesting
$backtestClockService->setFixedTime(new \DateTimeImmutable('2024-01-15 10:30:00'));

// Simuler l'exécution d'une stratégie
$currentTime = $backtestClockService->now();
echo "Exécution à : " . $currentTime->format('Y-m-d H:i:s') . "\n";

// Avancer le temps
$backtestClockService->advanceFixedTimeMinutes(15);
$newTime = $backtestClockService->now();
echo "Nouvelle heure : " . $newTime->format('Y-m-d H:i:s') . "\n";
```

### Exemple 2 : Boucle de backtesting

```php
// Définir la période de backtesting
$startTime = new \DateTimeImmutable('2024-01-15 10:30:00');
$endTime = new \DateTimeImmutable('2024-01-15 18:00:00');

$backtestClockService->setFixedTime($startTime);

while ($backtestClockService->now() < $endTime) {
    // Exécuter la stratégie
    $result = executeTradingStrategy($backtestClockService->now());
    
    // Avancer le temps
    $backtestClockService->advanceFixedTimeMinutes(15);
}
```

## Avantages

1. **Reproducibilité** : Mêmes conditions à chaque exécution
2. **Contrôle du temps** : Simulation de différents moments
3. **Tests cohérents** : Validation des stratégies
4. **Débogage facilité** : Conditions prévisibles
5. **Performance** : Pas d'attente réelle du temps

## Scripts de démonstration

Deux scripts sont disponibles pour tester le système :

- `scripts/backtest_example.php` - Exemple basique d'utilisation
- `scripts/backtest_demo.php` - Démonstration complète avec simulation

## Intégration avec Docker

Le système fonctionne parfaitement dans un environnement Docker. L'heure fixe est gérée au niveau de l'application, indépendamment de l'heure système du conteneur.

## Notes importantes

- L'heure fixe est définie en UTC
- Tous les services utilisent automatiquement l'heure fixe quand elle est définie
- Le retour au temps réel se fait en supprimant l'heure fixe
- Les timestamps dans la base de données utilisent l'heure fixe lors du backtesting


