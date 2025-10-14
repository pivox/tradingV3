<?php

declare(strict_types=1);

namespace App\Command\Backtest;

use App\Domain\Strategy\Service\StrategyBacktester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'backtest:strategies',
    description: 'Liste les stratégies disponibles pour le backtesting'
)]
class ListStrategiesCommand extends Command
{
    public function __construct(
        private StrategyBacktester $strategyBacktester
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (table, json)', 'table')
            ->addOption('enabled-only', 'e', InputOption::VALUE_NONE, 'Afficher seulement les stratégies activées');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');
        $enabledOnly = $input->getOption('enabled-only');

        try {
            $strategies = $this->strategyBacktester->getAvailableStrategies();

            if ($enabledOnly) {
                $strategies = array_filter($strategies, fn($strategy) => $strategy->isEnabled());
            }

            if (empty($strategies)) {
                $io->warning('Aucune stratégie disponible');
                return Command::SUCCESS;
            }

            if ($format === 'json') {
                $strategyData = array_map(function ($strategy) {
                    return [
                        'name' => $strategy->getName(),
                        'description' => $strategy->getDescription(),
                        'parameters' => $strategy->getParameters(),
                        'enabled' => $strategy->isEnabled()
                    ];
                }, $strategies);

                $io->writeln(json_encode($strategyData, JSON_PRETTY_PRINT));
                return Command::SUCCESS;
            }

            // Affichage en tableau
            $io->title('Stratégies Disponibles');

            $tableData = [];
            foreach ($strategies as $strategy) {
                $status = $strategy->isEnabled() ? '✅ Activée' : '❌ Désactivée';
                $parameters = $this->formatParameters($strategy->getParameters());
                
                $tableData[] = [
                    $strategy->getName(),
                    $strategy->getDescription(),
                    $status,
                    $parameters
                ];
            }

            $io->table(
                ['Nom', 'Description', 'Statut', 'Paramètres'],
                $tableData
            );

            $io->note(sprintf('Total: %d stratégie(s)', count($strategies)));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de la récupération des stratégies: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function formatParameters(array $parameters): string
    {
        if (empty($parameters)) {
            return 'Aucun';
        }

        $formatted = [];
        foreach ($parameters as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_float($value)) {
                $value = number_format($value, 2);
            }
            $formatted[] = "{$key}: {$value}";
        }

        return implode(', ', $formatted);
    }
}


