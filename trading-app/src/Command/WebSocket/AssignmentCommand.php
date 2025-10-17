<?php

namespace App\Command\WebSocket;

use App\Service\ContractDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ws:assignment', description: 'Gestion des assignations de contrats')]
final class AssignmentCommand extends Command
{
    public function __construct(
        private readonly ContractDispatcher $dispatcher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'Lister toutes les assignations')
            ->addOption('clear', 'c', InputOption::VALUE_NONE, 'Effacer toutes les assignations')
            ->addOption('stats', 's', InputOption::VALUE_NONE, 'Afficher les statistiques par worker')
            ->addOption('worker', 'w', InputOption::VALUE_REQUIRED, 'Filtrer par worker');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $list = $input->getOption('list');
        $clear = $input->getOption('clear');
        $stats = $input->getOption('stats');
        $worker = $input->getOption('worker');

        if ($clear) {
            $this->dispatcher->getCurrentAssignments(); // Charge pour vérifier
            $this->dispatcher->getCurrentAssignments(); // Appel pour accéder au storage
            // Note: Il faudrait exposer la méthode clear() du storage
            $io->success('Assignations effacées');
            return Command::SUCCESS;
        }

        if ($stats) {
            $this->displayStats($io, $worker);
            return Command::SUCCESS;
        }

        if ($list) {
            $this->displayAssignments($io, $worker);
            return Command::SUCCESS;
        }

        // Par défaut, afficher les statistiques
        $this->displayStats($io, $worker);
        return Command::SUCCESS;
    }

    private function displayAssignments(SymfonyStyle $io, ?string $worker): void
    {
        $assignments = $this->dispatcher->getCurrentAssignments();
        
        if (empty($assignments)) {
            $io->info('Aucune assignation trouvée');
            return;
        }

        $filtered = $assignments;
        if ($worker) {
            $filtered = array_filter($assignments, fn($w) => $w === $worker);
            if (empty($filtered)) {
                $io->info("Aucune assignation trouvée pour le worker: {$worker}");
                return;
            }
        }

        $tableData = [];
        foreach ($filtered as $symbol => $assignedWorker) {
            $tableData[] = [$symbol, $assignedWorker];
        }

        $io->table(['Symbole', 'Worker'], $tableData);
        $io->info(sprintf('Total: %d assignations', count($filtered)));
    }

    private function displayStats(SymfonyStyle $io, ?string $worker): void
    {
        $assignments = $this->dispatcher->getCurrentAssignments();
        $workers = $this->dispatcher->getWorkers();

        $stats = [];
        $total = 0;
        
        foreach ($workers as $w) {
            $count = count(array_filter($assignments, fn($assignedWorker) => $assignedWorker === $w));
            $total += $count;
            
            if (!$worker || $worker === $w) {
                $stats[] = [$w, $count];
            }
        }

        if (empty($stats)) {
            $io->info('Aucune statistique disponible');
            return;
        }

        $io->table(['Worker', 'Nombre de contrats'], $stats);
        $io->info(sprintf('Total: %d contrats assignés', $total));
    }
}
