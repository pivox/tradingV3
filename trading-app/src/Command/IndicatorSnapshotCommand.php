<?php

namespace App\Command;

use App\Common\Enum\Timeframe;
use App\Entity\IndicatorSnapshot;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Volume\Vwap;
use App\Indicator\Registry\ConditionRegistry;
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
        private readonly IndicatorSnapshotRepository $snapshotRepository
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
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Fichier de sortie pour les résultats')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Mode verbeux');
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
        $verbose = $input->getOption('verbose');

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

        // Créer le contexte avec des données réalistes
        $context = $this->createRealisticContext($symbol, $timeframe);

        // Évaluer les conditions
        $conditionRegistry = $this->createConditionRegistry();
        $conditionsResults = $conditionRegistry->evaluate($context);

        // Créer le snapshot
        $snapshot = new IndicatorSnapshot();
        $snapshot
            ->setSymbol($symbol)
            ->setTimeframe($timeframe)
            ->setKlineTime(new \DateTimeImmutable($klineTime, new \DateTimeZone('UTC')))
            ->setValues([
                'ema20' => $context['ema'][20] ?? null,
                'ema50' => $context['ema'][50] ?? null,
                'ema200' => $context['ema'][200] ?? null,
                'rsi' => $context['rsi'] ?? null,
                'macd' => $context['macd']['macd'] ?? null,
                'macd_signal' => $context['macd']['signal'] ?? null,
                'macd_histogram' => $context['macd']['hist'] ?? null,
                'vwap' => $context['vwap'] ?? null,
                'atr' => $context['atr'] ?? null,
                'adx' => $context['adx'][14] ?? null,
                'close' => $context['close'] ?? null,
                'conditions_results' => $conditionsResults,
                'context_hash' => md5(serialize($context)),
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
            ]);

        // Sauvegarder
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        $io->success("Snapshot créé avec l'ID: " . $snapshot->getId());

        if ($verbose) {
            $this->displaySnapshotDetails($io, $snapshot);
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

    private function createRealisticContext(string $symbol, Timeframe $timeframe): array
    {
        $contextBuilder = $this->createContextBuilder();

        return $contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe->value)
            ->closes([
                50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900,
                51000, 51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800, 51900,
                52000, 52100, 52200, 52300, 52400, 52500, 52600, 52700, 52800, 52900,
                53000, 53100, 53200, 53300, 53400, 53500, 53600, 53700, 53800, 53900,
                54000, 54100, 54200, 54300, 54400, 54500, 54600, 54700, 54800, 54900,
                55000, 55100, 55200, 55300, 55400, 55500, 55600, 55700, 55800, 55900,
                56000, 56100, 56200, 56300, 56400, 56500, 56600, 56700, 56800, 56900,
                57000, 57100, 57200, 57300, 57400, 57500, 57600, 57700, 57800, 57900,
                58000, 58100, 58200, 58300, 58400, 58500, 58600, 58700, 58800, 58900,
                59000, 59100, 59200, 59300, 59400, 59500, 59600, 59700, 59800, 59900
            ])
            ->highs([
                50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800, 50900, 51000,
                51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800, 51900, 52000,
                52100, 52200, 52300, 52400, 52500, 52600, 52700, 52800, 52900, 53000,
                53100, 53200, 53300, 53400, 53500, 53600, 53700, 53800, 53900, 54000,
                54100, 54200, 54300, 54400, 54500, 54600, 54700, 54800, 54900, 55000,
                55100, 55200, 55300, 55400, 55500, 55600, 55700, 55800, 55900, 56000,
                56100, 56200, 56300, 56400, 56500, 56600, 56700, 56800, 56900, 57000,
                57100, 57200, 57300, 57400, 57500, 57600, 57700, 57800, 57900, 58000,
                58100, 58200, 58300, 58400, 58500, 58600, 58700, 58800, 58900, 59000,
                59100, 59200, 59300, 59400, 59500, 59600, 59700, 59800, 59900, 60000
            ])
            ->lows([
                49900, 50000, 50100, 50200, 50300, 50400, 50500, 50600, 50700, 50800,
                50900, 51000, 51100, 51200, 51300, 51400, 51500, 51600, 51700, 51800,
                51900, 52000, 52100, 52200, 52300, 52400, 52500, 52600, 52700, 52800,
                52900, 53000, 53100, 53200, 53300, 53400, 53500, 53600, 53700, 53800,
                53900, 54000, 54100, 54200, 54300, 54400, 54500, 54600, 54700, 54800,
                54900, 55000, 55100, 55200, 55300, 55400, 55500, 55600, 55700, 55800,
                55900, 56000, 56100, 56200, 56300, 56400, 56500, 56600, 56700, 56800,
                56900, 57000, 57100, 57200, 57300, 57400, 57500, 57600, 57700, 57800,
                57900, 58000, 58100, 58200, 58300, 58400, 58500, 58600, 58700, 58800,
                58900, 59000, 59100, 59200, 59300, 59400, 59500, 59600, 59700, 59800
            ])
            ->volumes([
                1000, 1100, 1200, 1300, 1400, 1500, 1600, 1700, 1800, 1900,
                2000, 2100, 2200, 2300, 2400, 2500, 2600, 2700, 2800, 2900,
                3000, 3100, 3200, 3300, 3400, 3500, 3600, 3700, 3800, 3900,
                4000, 4100, 4200, 4300, 4400, 4500, 4600, 4700, 4800, 4900,
                5000, 5100, 5200, 5300, 5400, 5500, 5600, 5700, 5800, 5900,
                6000, 6100, 6200, 6300, 6400, 6500, 6600, 6700, 6800, 6900,
                7000, 7100, 7200, 7300, 7400, 7500, 7600, 7700, 7800, 7900,
                8000, 8100, 8200, 8300, 8400, 8500, 8600, 8700, 8800, 8900,
                9000, 9100, 9200, 9300, 9400, 9500, 9600, 9700, 9800, 9900,
                10000, 10100, 10200, 10300, 10400, 10500, 10600, 10700, 10800, 10900
            ])
            ->withDefaults()
            ->build();
    }

    private function createContextBuilder(): IndicatorContextBuilder
    {
        $rsi = new Rsi();
        $macd = new Macd();
        $ema = new Ema();
        $adx = new Adx();
        $vwap = new Vwap();
        $atrCalc = new AtrCalculator();

        return new IndicatorContextBuilder($rsi, $macd, $ema, $adx, $vwap, $atrCalc);
    }

    private function createConditionRegistry(): ConditionRegistry
    {
        return new ConditionRegistry();
    }

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

