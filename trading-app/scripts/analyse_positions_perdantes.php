<?php

declare(strict_types=1);

/**
 * Script d'analyse des positions fermées pour identifier les causes des pertes
 *
 * Usage: php scripts/analyse_positions_perdantes.php [--days=7] [--symbol=SYMBOL]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

$input = new ArgvInput();
$output = new ConsoleOutput();
$io = new SymfonyStyle($input, $output);

// Arguments CLI simples : php script.php [days] [symbol]
$daysArg = $_SERVER['argv'][1] ?? '7';
$symbolArg = $_SERVER['argv'][2] ?? null;

$days = is_numeric($daysArg) ? (int) $daysArg : 7;
$symbol = $symbolArg ?: null;

$io->title("Analyse des positions fermées (derniers {$days} jours)");

// Utiliser la commande existante pour récupérer les données
// On passe par docker-compose pour utiliser le container trading-app-php
$tradingAppDir = realpath(__DIR__ . '/..');
$projectRoot = $tradingAppDir ? dirname($tradingAppDir) : __DIR__ . '/..' . '/..';

// Détecter docker-compose vs docker compose (fallback)
$dockerComposeCmd = 'docker-compose';
$dockerComposePath = trim((string) shell_exec('command -v docker-compose'));
if ($dockerComposePath === '') {
    $dockerComposeCmd = 'docker compose';
}

$symbolOption = $symbol !== null ? ' --symbol=' . escapeshellarg($symbol) : '';

$command = sprintf(
    'cd %s && %s exec -T trading-app-php php bin/console provider:list-closed-positions --hours=%d --format=json%s',
    escapeshellarg($projectRoot),
    $dockerComposeCmd,
    $days * 24,
    $symbolOption
);

$output = shell_exec($command);

if ($output === null) {
    $io->error("La commande n'a rien retourné. Vérifiez que les containers docker (trading-app-php, BDD, Redis) sont démarrés.");
    $io->writeln("Commande exécutée :");
    $io->writeln('  ' . $command);
    exit(1);
}

// Cas où la commande indique explicitement qu'il n'y a aucun ordre
if (stripos($output, 'Aucun ordre trouvé') !== false) {
    $io->warning("Aucune position fermée trouvée dans la période.");
    exit(0);
}

// La commande Symfony écrit des sections / barres de progression avant le JSON.
// On isole le premier bloc JSON ([...] ou {...}) éventuel en testant les positions candidates.
$trimmed = ltrim($output);

if ($trimmed === '') {
    $io->warning("Aucune position fermée trouvée dans la période.");
    exit(0);
}

// Essayer de trouver un offset où le JSON commence réellement en testant les décodages
$data = null;
for ($i = 0, $len = strlen($trimmed); $i < $len; $i++) {
    $ch = $trimmed[$i];
    if ($ch !== '[' && $ch !== '{') {
        continue;
    }

    $candidate = substr($trimmed, $i);
    $decoded = json_decode($candidate, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $data = $decoded;
        break;
    }
}

if (!is_array($data)) {
    $io->error("Impossible de décoder la sortie JSON de la commande (aucun bloc JSON valide détecté).");
    $io->writeln("Commande exécutée :");
    $io->writeln('  ' . $command);
    $io->writeln("Sortie brute :");
    $io->writeln($output);
    exit(1);
}

// Analyser les positions
$stats = [
    'total' => 0,
    'wins' => 0,
    'losses' => 0,
    'total_pnl' => 0.0,
    'avg_pnl' => 0.0,
    'min_pnl' => 0.0,
    'max_pnl' => 0.0,
    'by_symbol' => [],
    'r_multiple_analysis' => [],
];

foreach ($data as $order) {
    $pnl = (float)($order['pnl'] ?? 0);
    $symbol = $order['symbol'] ?? 'UNKNOWN';

    $stats['total']++;
    $stats['total_pnl'] += $pnl;

    if ($pnl > 0) {
        $stats['wins']++;
    } elseif ($pnl < 0) {
        $stats['losses']++;
    }

    if (!isset($stats['by_symbol'][$symbol])) {
        $stats['by_symbol'][$symbol] = [
            'total' => 0,
            'wins' => 0,
            'losses' => 0,
            'total_pnl' => 0.0,
        ];
    }

    $stats['by_symbol'][$symbol]['total']++;
    $stats['by_symbol'][$symbol]['total_pnl'] += $pnl;
    if ($pnl > 0) {
        $stats['by_symbol'][$symbol]['wins']++;
    } elseif ($pnl < 0) {
        $stats['by_symbol'][$symbol]['losses']++;
    }

    if ($stats['total'] === 1 || $pnl < $stats['min_pnl']) {
        $stats['min_pnl'] = $pnl;
    }
    if ($stats['total'] === 1 || $pnl > $stats['max_pnl']) {
        $stats['max_pnl'] = $pnl;
    }
}

if ($stats['total'] > 0) {
    $stats['avg_pnl'] = $stats['total_pnl'] / $stats['total'];
    $winrate = ($stats['wins'] / $stats['total']) * 100;
} else {
    $io->warning("Aucune position fermée trouvée dans la période.");
    exit(0);
}

// Affichage des statistiques
$io->section("Statistiques Globales");
$io->definitionList(
    ['Total positions' => $stats['total']],
    ['Gagnantes' => $stats['wins'] . ' (' . number_format(($stats['wins'] / $stats['total']) * 100, 2) . '%)'],
    ['Perdantes' => $stats['losses'] . ' (' . number_format(($stats['losses'] / $stats['total']) * 100, 2) . '%)'],
    ['Winrate' => number_format($winrate, 2) . '%'],
    ['PnL total' => number_format($stats['total_pnl'], 2) . ' USDT'],
    ['PnL moyen' => number_format($stats['avg_pnl'], 2) . ' USDT'],
    ['PnL min' => number_format($stats['min_pnl'], 2) . ' USDT'],
    ['PnL max' => number_format($stats['max_pnl'], 2) . ' USDT'],
);

// Analyse par symbole
if (count($stats['by_symbol']) > 0) {
    $io->section("Statistiques par Symbole");
    $rows = [];
    foreach ($stats['by_symbol'] as $sym => $s) {
        $wr = $s['total'] > 0 ? ($s['wins'] / $s['total']) * 100 : 0;
        $rows[] = [
            $sym,
            $s['total'],
            $s['wins'],
            $s['losses'],
            number_format($wr, 1) . '%',
            number_format($s['total_pnl'], 2) . ' USDT',
        ];
    }

    usort($rows, fn($a, $b) => (float)str_replace(' USDT', '', $a[5]) <=> (float)str_replace(' USDT', '', $b[5]));

    $io->table(
        ['Symbole', 'Total', 'Wins', 'Losses', 'Winrate', 'PnL Total'],
        $rows
    );
}

// Analyse R-multiple nécessaire
$io->section("Analyse R-Multiple");
$io->writeln("Pour un R-multiple de 1.5 (config scalper actuelle):");
$io->writeln("  - Winrate minimum requis: " . number_format((1 / (1 + 1.5)) * 100, 2) . "%");
$io->writeln("  - Winrate actuel: " . number_format($winrate, 2) . "%");

if ($winrate < (1 / (1 + 1.5)) * 100) {
    $io->warning("⚠️  Le winrate actuel est INSUFFISANT pour un R-multiple de 1.5 !");
    $io->writeln("   Recommandation: Augmenter R-multiple à 2.0 ou améliorer la sélection des entrées.");
} else {
    $io->success("✓ Winrate suffisant pour R-multiple 1.5");
}

$io->newLine();
$io->writeln("Pour un R-multiple de 2.0:");
$io->writeln("  - Winrate minimum requis: " . number_format((1 / (1 + 2.0)) * 100, 2) . "%");
$io->writeln("  - Winrate actuel: " . number_format($winrate, 2) . "%");

if ($winrate >= (1 / (1 + 2.0)) * 100) {
    $io->success("✓ Winrate suffisant pour R-multiple 2.0");
} else {
    $io->warning("⚠️  Winrate insuffisant pour R-multiple 2.0");
}






