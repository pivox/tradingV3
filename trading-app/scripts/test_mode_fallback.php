<?php

/**
 * Script de test pour vérifier le système de fallback multi-modes
 * 
 * Usage:
 *   php scripts/test_mode_fallback.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

$kernel = new \App\Kernel($_ENV['APP_ENV'] ?? 'dev', (bool)($_ENV['APP_DEBUG'] ?? true));
$kernel->boot();
$container = $kernel->getContainer();

echo "=== Test du système de fallback multi-modes ===\n\n";

// 1. Vérifier la configuration des modes
echo "1. Configuration des modes dans services.yaml:\n";
$servicesPath = __DIR__ . '/../config/services.yaml';
$services = Yaml::parseFile($servicesPath);
$modes = $services['parameters']['mode'] ?? [];

if (empty($modes)) {
    echo "   ⚠️  Aucun mode trouvé dans la configuration\n";
} else {
    foreach ($modes as $index => $mode) {
        // Le format YAML [name: 'x', enabled: true, priority: 1] est parsé comme:
        // [[['name' => 'x']], [['enabled' => true]], [['priority' => 1]]]
        if (is_array($mode) && count($mode) >= 3) {
            $name = 'unknown';
            $enabled = false;
            $priority = 999;
            
            // Extraire name, enabled, priority depuis le format spécial Symfony
            foreach ($mode as $item) {
                if (is_array($item)) {
                    if (isset($item['name'])) {
                        $name = $item['name'];
                    } elseif (isset($item['enabled'])) {
                        $enabled = (bool)$item['enabled'];
                    } elseif (isset($item['priority'])) {
                        $priority = (int)$item['priority'];
                    }
                }
            }
            
            $status = $enabled ? '✅ ACTIVÉ' : '❌ DÉSACTIVÉ';
            echo "   - Mode: {$name} | Priority: {$priority} | {$status}\n";
        } else {
            echo "   - Mode #{$index}: Format inattendu\n";
        }
    }
}

echo "\n";

// 2. Vérifier les fichiers de config
echo "2. Vérification des fichiers de config:\n";

$configDir = __DIR__ . '/../src/MtfValidator/config';
$tradeEntryDir = __DIR__ . '/../config/app';

$configFiles = [
    'regular' => [
        'mtf' => $configDir . '/validations.regular.yaml',
        'te' => $tradeEntryDir . '/trade_entry.regular.yaml',
    ],
    'scalping' => [
        'mtf' => $configDir . '/validations.scalper.yaml',
        'te' => $tradeEntryDir . '/trade_entry.scalper.yaml',
    ],
];

foreach ($configFiles as $mode => $files) {
    echo "   Mode: {$mode}\n";
    
    // Vérifier validations
    if (file_exists($files['mtf'])) {
        try {
            $yaml = Yaml::parseFile($files['mtf']);
            $version = $yaml['version'] ?? 'unknown';
            echo "     ✅ validations.{$mode}.yaml (version: {$version})\n";
        } catch (\Throwable $e) {
            echo "     ❌ validations.{$mode}.yaml (erreur: {$e->getMessage()})\n";
        }
    } else {
        echo "     ⚠️  validations.{$mode}.yaml (fichier non trouvé)\n";
    }
    
    // Vérifier trade_entry
    if (file_exists($files['te'])) {
        try {
            $yaml = Yaml::parseFile($files['te']);
            $version = $yaml['version'] ?? 'unknown';
            echo "     ✅ trade_entry.{$mode}.yaml (version: {$version})\n";
        } catch (\Throwable $e) {
            echo "     ❌ trade_entry.{$mode}.yaml (erreur: {$e->getMessage()})\n";
        }
    } else {
        echo "     ⚠️  trade_entry.{$mode}.yaml (fichier non trouvé)\n";
    }
}

echo "\n";

// 3. Instructions pour tester
echo "3. Instructions pour tester:\n";
echo "   Pour tester le système avec les modes activés/désactivés:\n";
echo "   1. Modifiez config/services.yaml (lignes 21-23)\n";
echo "   2. Changez 'enabled: true/false' pour chaque mode\n";
echo "   3. Videz le cache: php bin/console cache:clear\n";
echo "   4. Lancez un test MTF: php bin/console app:test-mtf --symbol=BTCUSDT\n";
echo "   5. Vérifiez les logs pour voir quel mode est utilisé\n";

echo "\n";

// 4. Résumé
echo "=== Résumé ===\n";
$enabledCount = 0;
$disabledCount = 0;

foreach ($modes as $mode) {
    if (is_array($mode) && count($mode) >= 3) {
        $enabled = false;
        foreach ($mode as $item) {
            if (is_array($item) && isset($item['enabled'])) {
                $enabled = (bool)$item['enabled'];
                break;
            }
        }
        if ($enabled) {
            $enabledCount++;
        } else {
            $disabledCount++;
        }
    }
}

echo "Modes activés: {$enabledCount}\n";
echo "Modes désactivés: {$disabledCount}\n";

if ($enabledCount === 0) {
    echo "\n⚠️  ATTENTION: Aucun mode n'est activé ! Le système utilisera le config par défaut.\n";
} elseif ($enabledCount === 1) {
    echo "\n✅ Un seul mode activé - le système utilisera uniquement ce mode.\n";
} else {
    echo "\n✅ Plusieurs modes activés - le système testera chaque mode dans l'ordre de priorité.\n";
}

echo "\n";

