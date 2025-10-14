<?php

declare(strict_types=1);

namespace App\Command\Backtest;

use App\Domain\Common\Dto\BacktestRequestDto;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Strategy\Service\StrategyBacktester;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'backtest:run',
    description: 'Exécute un backtest avec les paramètres spécifiés'
)]
class RunBacktestCommand extends Command
{
    public function __construct(
        private StrategyBacktester $strategyBacktester
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à backtester (ex: BTCUSDT)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (1h, 4h, 15m, 5m, 1m)')
            ->addArgument('start-date', InputArgument::REQUIRED, 'Date de début (YYYY-MM-DD)')
            ->addArgument('end-date', InputArgument::REQUIRED, 'Date de fin (YYYY-MM-DD)')
            ->addOption('strategies', 's', InputOption::VALUE_REQUIRED, 'Stratégies à utiliser (séparées par des virgules)', 'RSI Strategy,MACD Strategy')
            ->addOption('initial-capital', 'c', InputOption::VALUE_REQUIRED, 'Capital initial', '10000')
            ->addOption('risk-per-trade', 'r', InputOption::VALUE_REQUIRED, 'Risque par trade (en pourcentage)', '2')
            ->addOption('commission-rate', 'f', InputOption::VALUE_REQUIRED, 'Taux de commission (en pourcentage)', '0.1')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom du backtest')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Description du backtest')
            ->addOption('output-format', 'o', InputOption::VALUE_REQUIRED, 'Format de sortie (json, table)', 'table')
            ->addOption('save-results', null, InputOption::VALUE_NONE, 'Sauvegarder les résultats en base de données')
            ->addOption('export-trades', null, InputOption::VALUE_OPTIONAL, 'Exporter les trades vers un fichier CSV', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tz = new DateTimeZone('UTC');

        try {
            // Récupérer les arguments
            $symbol = $input->getArgument('symbol');
            $timeframeStr = $input->getArgument('timeframe');
            $startDateStr = $input->getArgument('start-date');
            $endDateStr = $input->getArgument('end-date');

            // Valider le timeframe
            try {
                $timeframe = Timeframe::from($timeframeStr);
            } catch (\ValueError $e) {
                $io->error("Timeframe invalide: {$timeframeStr}. Valeurs acceptées: 1h, 4h, 15m, 5m, 1m");
                return Command::FAILURE;
            }

            // Valider les dates
            try {
                $startDate = new \DateTimeImmutable($startDateStr . ' 00:00:00', $tz);
                $endDate = new \DateTimeImmutable($endDateStr . ' 23:59:59', $tz);
            } catch (\Exception $e) {
                $io->error("Format de date invalide. Utilisez YYYY-MM-DD");
                return Command::FAILURE;
            }

            if ($startDate >= $endDate) {
                $io->error("La date de début doit être antérieure à la date de fin");
                return Command::FAILURE;
            }

            // Récupérer les options
            $strategiesStr = $input->getOption('strategies');
            $strategies = array_map('trim', explode(',', $strategiesStr));
            $initialCapital = (float) $input->getOption('initial-capital');
            $riskPerTrade = (float) $input->getOption('risk-per-trade') / 100; // Convertir en décimal
            $commissionRate = (float) $input->getOption('commission-rate') / 100; // Convertir en décimal
            $name = $input->getOption('name') ?? "Backtest {$symbol}";
            $description = $input->getOption('description');
            $outputFormat = $input->getOption('output-format');
            $saveResults = $input->getOption('save-results');
            $exportTrades = $input->getOption('export-trades');

            // Afficher les paramètres
            $io->title('Paramètres du Backtest');
            $io->table(
                ['Paramètre', 'Valeur'],
                [
                    ['Symbole', $symbol],
                    ['Timeframe', $timeframe->value],
                    ['Période', $startDate->format('Y-m-d') . ' → ' . $endDate->format('Y-m-d')],
                    ['Stratégies', implode(', ', $strategies)],
                    ['Capital initial', '$' . number_format($initialCapital, 2)],
                    ['Risque par trade', ($riskPerTrade * 100) . '%'],
                    ['Taux de commission', ($commissionRate * 100) . '%'],
                    ['Nom', $name],
                ]
            );

            // Créer la requête de backtest
            $backtestRequest = new BacktestRequestDto(
                symbol: $symbol,
                timeframe: $timeframe,
                startDate: $startDate,
                endDate: $endDate,
                strategies: $strategies,
                initialCapital: $initialCapital,
                riskPerTrade: $riskPerTrade,
                commissionRate: $commissionRate,
                name: $name,
                description: $description
            );

            // Exécuter le backtest
            $io->section('Exécution du Backtest');
            $io->progressStart();
            
            $startTime = microtime(true);
            $result = $this->strategyBacktester->runBacktest($backtestRequest);
            $endTime = microtime(true);
            
            $io->progressFinish();
            $io->success(sprintf('Backtest terminé en %.2f secondes', $endTime - $startTime));

            // Afficher les résultats
            $this->displayResults($io, $result, $outputFormat);

            // Sauvegarder les résultats si demandé
            if ($saveResults) {
                $this->saveResults($result, $io);
            }

            // Exporter les trades si demandé
            if ($exportTrades !== false) {
                $filename = $exportTrades === true ? "trades_{$symbol}_{$timeframe->value}.csv" : $exportTrades;
                $this->exportTrades($result, $filename, $io);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de l\'exécution du backtest: ' . $e->getMessage());
            if ($io->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function displayResults(SymfonyStyle $io, $result, string $format): void
    {
        $io->section('Résultats du Backtest');

        if ($format === 'json') {
            $io->writeln(json_encode($result->toArray(), JSON_PRETTY_PRINT));
            return;
        }

        // Affichage en tableau
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Capital initial', '$' . number_format($result->initialCapital, 2)],
                ['Capital final', '$' . number_format($result->finalCapital, 2)],
                ['Profit/Perte total', '$' . number_format($result->totalReturn, 2)],
                ['Rendement total', number_format($result->totalReturnPercentage, 2) . '%'],
                ['Nombre de trades', $result->totalTrades],
                ['Trades gagnants', $result->winningTrades],
                ['Trades perdants', $result->losingTrades],
                ['Taux de réussite', number_format($result->winRate, 2) . '%'],
                ['Facteur de profit', number_format($result->profitFactor, 2)],
                ['Ratio de Sharpe', number_format($result->sharpeRatio, 2)],
                ['Drawdown maximum', '$' . number_format($result->maxDrawdown, 2)],
                ['Drawdown maximum (%)', number_format($result->maxDrawdownPercentage, 2) . '%'],
                ['Gain moyen', '$' . number_format($result->averageWin, 2)],
                ['Perte moyenne', '$' . number_format($result->averageLoss, 2)],
                ['Plus gros gain', '$' . number_format($result->largestWin, 2)],
                ['Plus grosse perte', '$' . number_format($result->largestLoss, 2)],
            ]
        );

        // Afficher les retours mensuels
        if (!empty($result->monthlyReturns)) {
            $io->section('Retours Mensuels');
            $monthlyData = [];
            foreach ($result->monthlyReturns as $month => $return) {
                $monthlyData[] = [$month, number_format($return, 2) . '%'];
            }
            $io->table(['Mois', 'Rendement'], $monthlyData);
        }

        // Résumé des performances
        $io->section('Résumé des Performances');
        if ($result->isProfitable()) {
            $io->success(sprintf(
                '✅ Backtest profitable ! Rendement de %.2f%% sur %d jours',
                $result->totalReturnPercentage,
                $result->startDate->diff($result->endDate)->days
            ));
        } else {
            $io->error(sprintf(
                '❌ Backtest non profitable. Perte de %.2f%% sur %d jours',
                $result->totalReturnPercentage,
                $result->startDate->diff($result->endDate)->days
            ));
        }
    }

    private function saveResults($result, SymfonyStyle $io): void
    {
        // TODO: Implémenter la sauvegarde en base de données
        $io->info('Sauvegarde des résultats en base de données...');
        $io->note('Fonctionnalité de sauvegarde à implémenter');
    }

    private function exportTrades($result, string $filename, SymfonyStyle $io): void
    {
        if (empty($result->trades)) {
            $io->warning('Aucun trade à exporter');
            return;
        }

        $io->info("Export des trades vers {$filename}...");

        $file = fopen($filename, 'w');
        if (!$file) {
            $io->error("Impossible de créer le fichier {$filename}");
            return;
        }

        // En-têtes CSV
        fputcsv($file, [
            'ID', 'Symbole', 'Side', 'Date d\'entrée', 'Prix d\'entrée', 'Quantité',
            'Stop Loss', 'Take Profit', 'Date de sortie', 'Prix de sortie',
            'PnL', 'PnL (%)', 'Commission', 'Raison de sortie'
        ]);

        // Données des trades
        foreach ($result->trades as $trade) {
            fputcsv($file, [
                $trade['id'],
                $trade['symbol'],
                $trade['side'],
                $trade['entry_time'],
                $trade['entry_price'],
                $trade['quantity'],
                $trade['stop_loss'],
                $trade['take_profit'],
                $trade['exit_time'] ?? '',
                $trade['exit_price'] ?? '',
                $trade['pnl'] ?? '',
                $trade['pnl_percentage'] ?? '',
                $trade['commission'] ?? '',
                $trade['exit_reason'] ?? ''
            ]);
        }

        fclose($file);
        $io->success("Trades exportés vers {$filename}");
    }
}


