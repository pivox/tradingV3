<?php

declare(strict_types=1);

/**
 * Script d'analyse des positions fermées pour identifier les causes des pertes
 * 
 * Usage: php scripts/analyse_positions_perdantes.php [--days=7] [--symbol=SYMBOL]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

$io = new SymfonyStyle(
    new class implements InputInterface {
        public function getFirstArgument(): ?string { return null; }
        public function hasParameterOption(string|array $values, bool $onlyParams = false): bool { return false; }
        public function getParameterOption(string|array $values, string|bool|int|float|array|null $default = false, bool $onlyParams = false): mixed { return $default; }
        public function bind(\Symfony\Component\Console\Input\InputDefinition $definition): void {}
        public function validate(): void {}
        public function getArguments(): array { return []; }
        public function getArgument(string $name): mixed { return null; }
        public function setArgument(string $name, mixed $value): void {}
        public function hasArgument(string $name): bool { return false; }
        public function getOptions(): array { 
            $days = $_SERVER['argv'][1] ?? '7';
            $symbol = $_SERVER['argv'][2] ?? null;
            return [
                'days' => is_numeric($days) ? (int)$days : 7,
                'symbol' => $symbol
            ];
        }
        public function getOption(string $name): mixed { 
            $opts = $this->getOptions();
            return $opts[$name] ?? null;
        }
        public function setOption(string $name, mixed $value): void {}
        public function hasOption(string $name): bool { return isset($this->getOptions()[$name]); }
        public function isInteractive(): bool { return false; }
        public function setInteractive(bool $interactive): void {}
    },
    new class implements OutputInterface {
        public function write(iterable|string $messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void {
            echo is_string($messages) ? $messages : implode('', $messages);
            if ($newline) echo "\n";
        }
        public function writeln(iterable|string $messages, int $options = self::OUTPUT_NORMAL): void {
            $this->write($messages, true, $options);
        }
        public function setVerbosity(int $level): void {}
        public function getVerbosity(): int { return self::VERBOSITY_NORMAL; }
        public function isQuiet(): bool { return false; }
        public function isVerbose(): bool { return false; }
        public function isVeryVerbose(): bool { return false; }
        public function isDebug(): bool { return false; }
        public function setDecorated(bool $decorated): void {}
        public function isDecorated(): bool { return true; }
        public function setFormatter(\Symfony\Component\Console\Formatter\OutputFormatterInterface $formatter): void {}
        public function getFormatter(): \Symfony\Component\Console\Formatter\OutputFormatterInterface {
            return new \Symfony\Component\Console\Formatter\OutputFormatter();
        }
    }
);

$days = (int)($io->getOption('days') ?? 7);
$symbol = $io->getOption('symbol');

$io->title("Analyse des positions fermées (derniers {$days} jours)");

// Utiliser la commande existante pour récupérer les données
$command = sprintf(
    'cd %s && php bin/console provider:list-closed-positions --hours=%d --format=json %s 2>/dev/null',
    escapeshellarg(__DIR__ . '/..'),
    $days * 24,
    $symbol ? '--symbol=' . escapeshellarg($symbol) : ''
);

$output = shell_exec($command);
$data = json_decode($output, true);

if (empty($data) || !is_array($data)) {
    $io->error("Impossible de récupérer les données. Essayez d'exécuter manuellement:");
    $io->writeln("  php bin/console provider:list-closed-positions --hours=" . ($days * 24) . " --format=json");
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


