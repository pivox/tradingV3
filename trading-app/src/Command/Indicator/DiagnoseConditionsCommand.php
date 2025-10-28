<?php

declare(strict_types=1);

namespace App\Command\Indicator;

use App\Common\Enum\Timeframe as TimeframeEnum;
use App\Contract\Indicator\IndicatorMainProviderInterface;
use App\Indicator\Registry\ConditionRegistry;
use App\Contract\Provider\MainProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;

#[AsCommand(
    name: 'app:indicator:conditions:diagnose',
    description: 'Liste les conditions disponibles (par timeframe/side) et évalue celles d\'un symbole/timeframe donné.'
)]
final class DiagnoseConditionsCommand extends Command
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly IndicatorMainProviderInterface $indicatorMain,
        private readonly ConditionRegistry $registry,
        private readonly Stopwatch $stopwatch
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole, ex: BTCUSDT')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe, ex: 1m,5m,15m,1h,4h')
            ->addOption('side', null, InputOption::VALUE_OPTIONAL, 'long|short (optionnel)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à charger', 150)
            ->addOption('no-json', null, InputOption::VALUE_NONE, 'Ne pas afficher les klines au format JSON')
            ->addOption('json-results', null, InputOption::VALUE_NONE, 'Afficher les résultats d\'évaluation au format JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = strtoupper((string)$input->getArgument('symbol'));
        $timeframeStr = (string)$input->getArgument('timeframe');
        $side = $input->getOption('side') ? strtolower((string)$input->getOption('side')) : null;
        $limit = (int)$input->getOption('limit');

        try {
            $tfEnum = TimeframeEnum::from($timeframeStr);
        } catch (\Throwable $e) {
            $io->error('Timeframe invalide: ' . $timeframeStr);
            return Command::FAILURE;
        }

        $io->title('Diagnostic conditions');
        $io->text(["Symbole: $symbol", "Timeframe: {$tfEnum->value}", $side ? "Side: $side" : 'Side: (tous)']);

        // Liste des conditions déclarées pour ce timeframe/side
        $namesAll = $this->registry->namesForTimeframe($tfEnum->value, null);
        $io->section('Conditions (timeframe only)');
        $io->listing($namesAll ?: ['<aucune>']);

        if ($side) {
            $namesSide = $this->registry->namesForTimeframe($tfEnum->value, $side);
            $io->section('Conditions (timeframe + side)');
            $io->listing($namesSide ?: ['<aucune>']);
        }

        // Charge des klines et construit le contexte
        $klineProvider = $this->mainProvider->getKlineProvider();
        $klines = $klineProvider->getKlines($symbol, $tfEnum, $limit);
        if (empty($klines)) {
            $io->warning('Aucune kline retournée pour ce couple.');
            return Command::SUCCESS;
        }

        $closes = $highs = $lows = $volumes = [];
        $ohlc = [];
        foreach ($klines as $k) {
            $c = (float) $k->close->toFloat();
            $h = (float) $k->high->toFloat();
            $l = (float) $k->low->toFloat();
            $v = (float) $k->volume->toFloat();
            $closes[] = $c; $highs[] = $h; $lows[] = $l; $volumes[] = $v;
            $ohlc[] = ['high' => $h, 'low' => $l, 'close' => $c];
        }

        $engine = $this->indicatorMain->getEngine();
        $klinesArr = [];
        foreach ($klines as $k) {
            $klinesArr[] = $k; // engine can normalize DTOs
        }
        $context = $engine->buildContext($symbol, $tfEnum->value, $klinesArr);

        // Affichage des klines en JSON
        if (!$input->getOption('no-json')) {
            $io->section('Klines (JSON)');
            $klinesJson = [];
            foreach ($klines as $k) {
                $klinesJson[] = [
                    'open_time' => $k->openTime->format('c'),
                    'open' => (float)$k->open->toFloat(),
                    'high' => (float)$k->high->toFloat(),
                    'low' => (float)$k->low->toFloat(),
                    'close' => (float)$k->close->toFloat(),
                    'volume' => (float)$k->volume->toFloat(),
                ];
            }
            $io->writeln(json_encode($klinesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // Évaluation
        $this->stopwatch->start('evaluate_conditions');
        $results = $engine->evaluateCompiled($tfEnum->value, $context, $side);
        $this->stopwatch->start('evaluate_conditions');
        $event = $this->stopwatch->stop('evaluate_conditions');
        $durationMs = $event->getDuration();   // ms
        $memory    = $event->getMemory();
        if ($input->getOption('json-results')) {
            $io->section('Résultats (JSON)');
            $io->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $io->section('Résultats');
        if (!$results) {
            $io->writeln('<info>Aucune condition à évaluer pour ce scope.</info>');
            return Command::SUCCESS;
        }

        $passed = array_filter($results, fn($r) => ($r['passed'] ?? false) === true);
        $failed = array_filter($results, fn($r) => ($r['passed'] ?? false) !== true);

        $io->writeln(sprintf('Total: %d | Passed: %d | Failed: %d', count($results), count($passed), count($failed)));

        $io->writeln('--- Failed ---');
        foreach ($failed as $name => $r) {
            $io->writeln(sprintf('- %s: passed=%s value=%s threshold=%s', $name, $r['passed'] ? 'true' : 'false', json_encode($r['value']), json_encode($r['threshold'])));
        }

        $io->writeln('--- Passed ---');
        foreach ($passed as $name => $r) {
            $io->writeln(sprintf('- %s: value=%s', $name, json_encode($r['value'])));
        }

        return Command::SUCCESS;
    }
}
