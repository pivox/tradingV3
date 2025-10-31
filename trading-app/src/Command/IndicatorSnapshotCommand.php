<?php

namespace App\Command;

use App\Common\Enum\Timeframe;
use App\Entity\IndicatorSnapshot;
use App\Contract\Indicator\IndicatorMainProviderInterface;
use App\Repository\IndicatorSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'indicator:snapshot',
    description: 'Génère et compare les snapshots d\'indicateurs pour la régression'
)]
class IndicatorSnapshotCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IndicatorSnapshotRepository $snapshotRepository,
        private readonly IndicatorMainProviderInterface $indicatorMain,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action à effectuer: create, compare, list')
            ->addOption('symbol', 's', InputOption::VALUE_REQUIRED, 'Symbole à traiter', 'BTCUSDT')
            ->addOption('timeframe', 't', InputOption::VALUE_REQUIRED, 'Timeframe', '1h')
            ->addOption('kline-time', 'k', InputOption::VALUE_REQUIRED, 'Heure de la kline (Y-m-d H:i:s)')
            ->addOption('tolerance', null, InputOption::VALUE_REQUIRED, 'Tolérance pour la comparaison', '0.001')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Fichier de sortie pour les résultats');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $symbol = $input->getOption('symbol');
        $timeframe = Timeframe::from($input->getOption('timeframe'));
        $klineTime = $input->getOption('kline-time') ?: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $tolerance = (float) $input->getOption('tolerance');
        $outputFile = $input->getOption('output');
        $verbose = $io->isVerbose();

        try {
            switch ($action) {
                case 'create':
                    return $this->createSnapshot($io, $symbol, $timeframe, $klineTime, $verbose);
                case 'compare':
                    return $this->compareSnapshots($io, $symbol, $timeframe, $tolerance, $outputFile, $verbose);
                case 'list':
                    return $this->listSnapshots($io, $symbol, $timeframe, $verbose);
                default:
                    $io->error("Action inconnue: $action. Actions disponibles: create, compare, list");
                    return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Erreur: " . $e->getMessage());
            if ($verbose) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function createSnapshot(SymfonyStyle $io, string $symbol, Timeframe $timeframe, string $klineTime, bool $verbose): int
    {
        $io->title("Création d'un snapshot d'indicateurs");

        // Calculer et persister via le provider (isolation des Core)
        $provider = $this->indicatorMain->getIndicatorProvider();
        $snapshotDto = $provider->getSnapshot($symbol, $timeframe->value);
        $provider->saveIndicatorSnapshot($snapshotDto);

        $io->success(sprintf(
            "Snapshot calculé et persisté pour %s %s (%s)",
            $symbol,
            $timeframe->value,
            $snapshotDto->klineTime->format('Y-m-d H:i:s')
        ));

        if ($verbose) {
            $persisted = $this->snapshotRepository->findOneBy([
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'klineTime' => $snapshotDto->klineTime,
            ]);
            if ($persisted instanceof IndicatorSnapshot) {
                $this->displaySnapshotDetails($io, $persisted);
            }
        }

        return Command::SUCCESS;
    }

    private function compareSnapshots(SymfonyStyle $io, string $symbol, Timeframe $timeframe, float $tolerance, ?string $outputFile, bool $verbose): int
    {
        $io->title("Comparaison des snapshots d'indicateurs");

        // Récupérer les deux derniers snapshots
        $snapshots = $this->snapshotRepository->findRecentForIndicators($symbol, $timeframe, 2);

        if (count($snapshots) < 2) {
            $io->error("Il faut au moins 2 snapshots pour faire une comparaison");
            return Command::FAILURE;
        }

        $reference = $snapshots[1]; // Plus ancien
        $current = $snapshots[0];   // Plus récent

        $io->writeln("Comparaison entre:");
        $io->writeln("  Référence: ID {$reference->getId()} - {$reference->getKlineTime()->format('Y-m-d H:i:s')}");
        $io->writeln("  Actuel:    ID {$current->getId()} - {$current->getKlineTime()->format('Y-m-d H:i:s')}");

        // Comparer les snapshots
        $comparison = $this->compareSnapshotValues($reference, $current, $tolerance);

        // Afficher les résultats
        $this->displayComparisonResults($io, $comparison, $tolerance);

        // Sauvegarder dans un fichier si demandé
        if ($outputFile) {
            $this->saveComparisonToFile($outputFile, $comparison, $reference, $current);
            $io->success("Résultats sauvegardés dans: $outputFile");
        }

        return $comparison['has_differences'] ? Command::FAILURE : Command::SUCCESS;
    }

    private function listSnapshots(SymfonyStyle $io, string $symbol, Timeframe $timeframe, bool $verbose): int
    {
        $io->title("Liste des snapshots d'indicateurs");

        $snapshots = $this->snapshotRepository->findRecentForIndicators($symbol, $timeframe, 10);

        if (empty($snapshots)) {
            $io->warning("Aucun snapshot trouvé pour $symbol {$timeframe->value}");
            return Command::SUCCESS;
        }

        $table = $io->createTable();
        $table->setHeaders(['ID', 'Kline Time', 'EMA20', 'EMA50', 'RSI', 'MACD', 'Créé le']);

        foreach ($snapshots as $snapshot) {
            $table->addRow([
                $snapshot->getId(),
                $snapshot->getKlineTime()->format('Y-m-d H:i:s'),
                $snapshot->getEma20() ?? 'N/A',
                $snapshot->getEma50() ?? 'N/A',
                $snapshot->getRsi() ?? 'N/A',
                $snapshot->getMacd() ?? 'N/A',
                $snapshot->getInsertedAt()->format('Y-m-d H:i:s')
            ]);
        }

        $table->render();

        if ($verbose) {
            $io->section("Détails du dernier snapshot");
            $this->displaySnapshotDetails($io, $snapshots[0]);
        }

        return Command::SUCCESS;
    }

    // Helpers removed: Core remains encapsulated inside Indicator providers

    private function displaySnapshotDetails(SymfonyStyle $io, IndicatorSnapshot $snapshot): void
    {
        $values = $snapshot->getValues();

        $io->writeln("Symbol: {$snapshot->getSymbol()}");
        $io->writeln("Timeframe: {$snapshot->getTimeframe()->value}");
        $io->writeln("Kline Time: {$snapshot->getKlineTime()->format('Y-m-d H:i:s')}");
        $io->writeln("EMA20: " . ($values['ema20'] ?? 'N/A'));
        $io->writeln("EMA50: " . ($values['ema50'] ?? 'N/A'));
        $io->writeln("EMA200: " . ($values['ema200'] ?? 'N/A'));
        $io->writeln("RSI: " . ($values['rsi'] ?? 'N/A'));
        $io->writeln("MACD: " . ($values['macd'] ?? 'N/A'));
        $io->writeln("MACD Signal: " . ($values['macd_signal'] ?? 'N/A'));
        $io->writeln("MACD Histogram: " . ($values['macd_histogram'] ?? 'N/A'));
        $io->writeln("VWAP: " . ($values['vwap'] ?? 'N/A'));
        $io->writeln("ATR: " . ($values['atr'] ?? 'N/A'));
        $io->writeln("ADX: " . ($values['adx'] ?? 'N/A'));
        $io->writeln("Close: " . ($values['close'] ?? 'N/A'));
    }

    private function compareSnapshotValues(IndicatorSnapshot $reference, IndicatorSnapshot $current, float $tolerance): array
    {
        $referenceValues = $reference->getValues();
        $currentValues = $current->getValues();

        $comparison = [
            'has_differences' => false,
            'differences' => [],
            'identical' => [],
            'tolerance' => $tolerance
        ];

        $keysToCompare = ['ema20', 'ema50', 'ema200', 'rsi', 'macd', 'macd_signal', 'macd_histogram', 'vwap', 'atr', 'adx', 'close'];

        foreach ($keysToCompare as $key) {
            $refValue = $referenceValues[$key] ?? null;
            $curValue = $currentValues[$key] ?? null;

            if ($refValue === null && $curValue === null) {
                $comparison['identical'][] = $key;
                continue;
            }

            if ($refValue === null || $curValue === null) {
                $comparison['has_differences'] = true;
                $comparison['differences'][] = [
                    'key' => $key,
                    'reference' => $refValue,
                    'current' => $curValue,
                    'type' => 'null_mismatch'
                ];
                continue;
            }

            if (is_numeric($refValue) && is_numeric($curValue)) {
                $diff = abs((float)$refValue - (float)$curValue);
                if ($diff > $tolerance) {
                    $comparison['has_differences'] = true;
                    $comparison['differences'][] = [
                        'key' => $key,
                        'reference' => $refValue,
                        'current' => $curValue,
                        'difference' => $diff,
                        'type' => 'numeric_difference'
                    ];
                } else {
                    $comparison['identical'][] = $key;
                }
            } else {
                if ($refValue !== $curValue) {
                    $comparison['has_differences'] = true;
                    $comparison['differences'][] = [
                        'key' => $key,
                        'reference' => $refValue,
                        'current' => $curValue,
                        'type' => 'value_mismatch'
                    ];
                } else {
                    $comparison['identical'][] = $key;
                }
            }
        }

        return $comparison;
    }

    private function displayComparisonResults(SymfonyStyle $io, array $comparison, float $tolerance): void
    {
        if (!$comparison['has_differences']) {
            $io->success("Aucune différence détectée (tolérance: $tolerance)");
            $io->writeln("Valeurs identiques: " . implode(', ', $comparison['identical']));
            return;
        }

        $io->error("Différences détectées (tolérance: $tolerance)");

        $table = $io->createTable();
        $table->setHeaders(['Clé', 'Référence', 'Actuel', 'Différence', 'Type']);

        foreach ($comparison['differences'] as $diff) {
            $table->addRow([
                $diff['key'],
                $diff['reference'],
                $diff['current'],
                $diff['difference'] ?? 'N/A',
                $diff['type']
            ]);
        }

        $table->render();

        if (!empty($comparison['identical'])) {
            $io->writeln("Valeurs identiques: " . implode(', ', $comparison['identical']));
        }
    }

    private function saveComparisonToFile(string $filename, array $comparison, IndicatorSnapshot $reference, IndicatorSnapshot $current): void
    {
        $data = [
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'reference' => [
                'id' => $reference->getId(),
                'symbol' => $reference->getSymbol(),
                'timeframe' => $reference->getTimeframe()->value,
                'kline_time' => $reference->getKlineTime()->format('Y-m-d H:i:s'),
                'values' => $reference->getValues()
            ],
            'current' => [
                'id' => $current->getId(),
                'symbol' => $current->getSymbol(),
                'timeframe' => $current->getTimeframe()->value,
                'kline_time' => $current->getKlineTime()->format('Y-m-d H:i:s'),
                'values' => $current->getValues()
            ],
            'comparison' => $comparison
        ];

        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
