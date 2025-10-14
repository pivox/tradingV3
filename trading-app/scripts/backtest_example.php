<?php

/**
 * Exemple d'utilisation du système de backtesting avec heure fixe
 * 
 * Ce script démontre comment utiliser le service ClockInterface
 * pour faciliter le backtesting en fixant une heure.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Application;
use App\Command\Backtest\SetFixedTimeCommand;
use App\Service\BacktestClockService;
use Symfony\Component\Clock\Clock;

// Exemple d'utilisation du service BacktestClockService
echo "=== Exemple d'utilisation du système de backtesting ===\n\n";

// Créer une instance du service
$clock = new Clock();
$backtestClockService = new BacktestClockService($clock);

// 1. Mode temps réel
echo "1. Mode temps réel:\n";
echo "   Heure actuelle: " . $backtestClockService->now()->format('Y-m-d H:i:s') . " UTC\n";
echo "   Heure fixe activée: " . ($backtestClockService->isFixedTimeEnabled() ? 'Oui' : 'Non') . "\n\n";

// 2. Définir une heure fixe pour le backtesting
echo "2. Définition d'une heure fixe:\n";
$fixedTime = new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('UTC'));
$backtestClockService->setFixedTime($fixedTime);
echo "   Heure fixe définie: " . $backtestClockService->now()->format('Y-m-d H:i:s') . " UTC\n";
echo "   Heure fixe activée: " . ($backtestClockService->isFixedTimeEnabled() ? 'Oui' : 'Non') . "\n\n";

// 3. Avancer l'heure fixe
echo "3. Avancement de l'heure fixe:\n";
$backtestClockService->advanceFixedTimeMinutes(15);
echo "   Heure après +15 minutes: " . $backtestClockService->now()->format('Y-m-d H:i:s') . " UTC\n";

$backtestClockService->advanceFixedTimeHours(1);
echo "   Heure après +1 heure: " . $backtestClockService->now()->format('Y-m-d H:i:s') . " UTC\n\n";

// 4. Retour au temps réel
echo "4. Retour au temps réel:\n";
$backtestClockService->clearFixedTime();
echo "   Heure actuelle: " . $backtestClockService->now()->format('Y-m-d H:i:s') . " UTC\n";
echo "   Heure fixe activée: " . ($backtestClockService->isFixedTimeEnabled() ? 'Oui' : 'Non') . "\n\n";

echo "=== Fin de l'exemple ===\n";

// Exemple d'utilisation avec les commandes Symfony
echo "\n=== Commandes disponibles ===\n";
echo "Pour définir une heure fixe:\n";
echo "  php bin/console app:backtest:set-fixed-time \"2024-01-15 10:30:00\"\n\n";

echo "Pour afficher l'heure fixe actuelle:\n";
echo "  php bin/console app:backtest:set-fixed-time --show\n\n";

echo "Pour avancer l'heure fixe:\n";
echo "  php bin/console app:backtest:set-fixed-time --advance=60\n\n";

echo "Pour retirer l'heure fixe:\n";
echo "  php bin/console app:backtest:set-fixed-time --clear\n\n";

echo "=== Configuration dans services.yaml ===\n";
echo "Pour définir une heure fixe via la configuration:\n";
echo "  parameters:\n";
echo "    app.clock.fixed_time: '2024-01-15 10:30:00'\n\n";
