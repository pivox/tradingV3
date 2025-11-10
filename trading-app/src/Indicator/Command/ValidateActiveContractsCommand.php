<?php

declare(strict_types=1);

namespace App\Indicator\Command;

use App\Common\Enum\Timeframe as TimeframeEnum;
use App\Contract\Indicator\IndicatorMainProviderInterface;
use App\Provider\Repository\ContractRepository;
use App\Contract\Provider\MainProviderInterface as LegacyMainProviderInterfaceAlias; // in case
use App\Contract\Provider\MainProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:indicator:contracts:validate',
    description: 'Valide tous les contrats actifs sur un timeframe donné et mesure les temps (fetch/validation).'
)]
final class ValidateActiveContractsCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly MainProviderInterface $mainProvider,
        private readonly IndicatorMainProviderInterface $indicatorMain,
        private readonly Stopwatch $stopwatch,
        #[Autowire(service: 'monolog.logger.indicators')] private readonly ?LoggerInterface $indicatorLogger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (1m,5m,15m,1h,4h)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à charger', 150)
            ->addOption('symbols', 's', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Filtre: symboles spécifiques (répéter l\'option)')
            ->addOption('side', null, InputOption::VALUE_OPTIONAL, 'long|short pour filtrer le “passé”')
            ->addOption('compiled', null, InputOption::VALUE_NONE, 'Utiliser le registre compilé (CompilerPass) au lieu du TimeframeEvaluator');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tfStr = (string) $input->getArgument('timeframe');
        try {
            $tfEnum = TimeframeEnum::from($tfStr);
        } catch (\Throwable) {
            $io->error('Timeframe invalide: ' . $tfStr);
            return Command::FAILURE;
        }
        $limit = (int) $input->getOption('limit');
        $filterSymbols = (array) $input->getOption('symbols');
        $side = $input->getOption('side') ? strtolower((string)$input->getOption('side')) : null;
        if ($side !== null && !in_array($side, ['long','short'], true)) {
            $io->error('Option --side doit être long|short.');
            return Command::FAILURE;
        }

        // Récupération des symboles
        $symbols = $this->contracts->allActiveSymbolNames($filterSymbols);
        if (!$symbols) {
            $io->warning('Aucun symbole actif.');
            return Command::SUCCESS;
        }

        $engine = $input->getOption('compiled') ? 'compiled' : 'evaluator';
        $io->title('Validation des contrats actifs');
        $io->text(['Timeframe: ' . $tfEnum->value, 'Engine: ' . $engine, 'Symbols: ' . implode(', ', $symbols)]);

        $totalFetchMs = 0.0; $totalValidateMs = 0.0;
        $perSymbolValidate = [];
        $passedSymbols = [];

        $klineProvider = $this->mainProvider->getKlineProvider();
        $engine = $this->indicatorMain->getEngine();

        foreach ($symbols as $symbol) {
            // Fetch klines timing
            $eventIdFetch = 'fetch:'.$symbol;
            $this->stopwatch->start($eventIdFetch);
            $klines = $klineProvider->getKlines($symbol, $tfEnum, $limit);
            $eventFetch = $this->stopwatch->stop($eventIdFetch);
            $totalFetchMs += $eventFetch->getDuration();

            if (empty($klines)) {
                $perSymbolValidate[$symbol] = ['ms' => 0.0, 'passed' => false, 'reason' => 'no_klines'];
                continue;
            }

            // Build context via engine provider (hides Core details)
            $context = $engine->buildContext($symbol, $tfEnum->value, $klines);

            // Log klines if EMA9 ~ 0 and close > 0 (data sanity check)
            if (isset($context['ema'][9]) && is_float($context['ema'][9]) && abs($context['ema'][9]) < 1.0e-12 && isset($context['close']) && is_float($context['close']) && $context['close'] > 0.0) {
                if ($this->indicatorLogger) {
                    $sample = [];
                    $n = count($klines);
                    $start = max(0, $n - 20);
                    for ($i = $start; $i < $n; $i++) {
                        $k = $klines[$i];
                        $sample[] = [
                            'open_time' => $k->openTime->format('c'),
                            'open' => (float)$k->open->toFloat(),
                            'high' => (float)$k->high->toFloat(),
                            'low' => (float)$k->low->toFloat(),
                            'close' => (float)$k->close->toFloat(),
                            'volume' => (float)$k->volume->toFloat(),
                        ];
                    }
                    $this->indicatorLogger->warning('EMA9 approx zero with positive close; logging recent klines', [
                        'symbol' => $symbol,
                        'timeframe' => $tfEnum->value,
                        'ema9' => $context['ema'][9],
                        'close' => $context['close'],
                        'klines_tail' => $sample,
                    ]);
                }
            }

            // Validate timing
            $eventIdVal = 'validate:'.$symbol;
            $this->stopwatch->start($eventIdVal);

            if ($input->getOption('compiled')) {
                // CompilerPass-backed registry: evaluate conditions for timeframe (+ optional side)
                $results = $engine->evaluateCompiled($tfEnum->value, $context, $side);
                $eventVal = $this->stopwatch->stop($eventIdVal);
                $ms = $eventVal->getDuration();
                $totalValidateMs += $ms;

                // Consider passed if any condition passes (simple aggregate for comparison)
                $anyPassed = false;
                foreach ($results as $r) {
                    if (($r['passed'] ?? false) === true) { $anyPassed = true; break; }
                }
                $perSymbolValidate[$symbol] = ['ms' => $ms, 'passed' => $anyPassed, 'count' => count($results)];
                $isPassed = $anyPassed;
            } else {
                // YAML evaluator: structured pass per side
                $eval = $engine->evaluateYaml($tfEnum->value, $context);
                $eventVal = $this->stopwatch->stop($eventIdVal);
                $ms = $eventVal->getDuration();
                $totalValidateMs += $ms;
                $perSymbolValidate[$symbol] = ['ms' => $ms, 'passed' => $eval['passed'] ?? ['long'=>false,'short'=>false]];

                $isPassed = false;
                if ($side === null) {
                    $isPassed = (($eval['passed']['long'] ?? false) === true) || (($eval['passed']['short'] ?? false) === true);
                } else {
                    $isPassed = ($eval['passed'][$side] ?? false) === true;
                }
            }
            if ($isPassed) {
                $passedSymbols[] = $symbol;
            }
        }

        // Résumé
        $io->section('Résumé');
        $io->writeln(sprintf('Contrats: %d', count($symbols)));
        $io->writeln(sprintf('Total fetch klines: %.2f ms', $totalFetchMs));
        $io->writeln(sprintf('Total validations: %.2f ms', $totalValidateMs));

        // Détails validation par symbole
        $io->section('Validation par symbole (ms)');
        foreach ($perSymbolValidate as $sym => $info) {
            $ms = sprintf('%.2f', (float)($info['ms'] ?? 0));
            $pass = $info['passed'];
            if (\is_array($pass)) {
                $passStr = sprintf('long=%s short=%s', ($pass['long']??false)?'true':'false', ($pass['short']??false)?'true':'false');
            } else {
                $passStr = $pass ? 'true' : 'false';
            }
            $io->writeln(sprintf('- %s: %s ms | %s', $sym, $ms, $passStr));
        }

        // Contrats qui ont “passé”
        $io->section('Passés');
        if ($passedSymbols) {
            $io->listing($passedSymbols);
        } else {
            $io->writeln('<info>Aucun</info>');
        }

        return Command::SUCCESS;
    }
}
