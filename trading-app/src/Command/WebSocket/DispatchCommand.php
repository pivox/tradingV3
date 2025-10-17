<?php

namespace App\Command\WebSocket;

use App\Service\ContractDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ws:dispatch', description: 'Dispatch des contrats vers les workers WebSocket')]
final class DispatchCommand extends Command
{
    public function __construct(
        private readonly ContractDispatcher $dispatcher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('contracts', InputArgument::REQUIRED, 'Fichier CSV des contrats ou liste de symboles séparés par des virgules')
            ->addOption('strategy', 's', InputOption::VALUE_REQUIRED, 'Stratégie de dispatch (hash|least)', 'hash')
            ->addOption('capacity', 'c', InputOption::VALUE_REQUIRED, 'Capacité par worker pour la stratégie least-loaded', 20)
            ->addOption('live', 'l', InputOption::VALUE_NONE, 'Exécuter en mode live (envoie les requêtes HTTP)')
            ->addOption('worker', 'w', InputOption::VALUE_REQUIRED, 'Worker spécifique pour le dispatch')
            ->addOption('timeframes', 't', InputOption::VALUE_REQUIRED, 'Timeframes séparés par des virgules', '1m,5m,15m,1h,4h')
            ->addOption('rebalance', 'r', InputOption::VALUE_NONE, 'Rebalancer les assignations existantes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $contractsInput = $input->getArgument('contracts');
        $strategy = $input->getOption('strategy');
        $capacity = (int) $input->getOption('capacity');
        $live = $input->getOption('live');
        $worker = $input->getOption('worker');
        $timeframes = explode(',', $input->getOption('timeframes'));
        $rebalance = $input->getOption('rebalance');

        // Charger les symboles
        $symbols = $this->loadSymbols($contractsInput);
        if (empty($symbols)) {
            $io->error('Aucun symbole trouvé');
            return Command::FAILURE;
        }

        $io->info(sprintf('Traitement de %d symboles avec la stratégie "%s"', count($symbols), $strategy));

        try {
            if ($rebalance) {
                $currentAssignments = $this->dispatcher->getCurrentAssignments();
                $moves = $this->dispatcher->rebalance($symbols, $currentAssignments, $live, $timeframes);
                
                $io->success(sprintf('Rebalancement terminé. %d symboles déplacés', count($moves)));
                if (!empty($moves)) {
                    $io->table(['Symbole', 'Ancien Worker', 'Nouveau Worker'], 
                        array_map(fn($s, $move) => [$s, $move[0], $move[1]], array_keys($moves), $moves));
                }
            } elseif ($worker) {
                $assignments = $this->dispatcher->dispatchToWorker($symbols, $worker, $live, $timeframes);
                $io->success(sprintf('Dispatch vers %s terminé', $worker));
            } else {
                switch ($strategy) {
                    case 'hash':
                        $assignments = $this->dispatcher->dispatchByHash($symbols, $live, $timeframes);
                        break;
                    case 'least':
                        $assignments = $this->dispatcher->dispatchLeastLoaded($symbols, $capacity, $live, $timeframes);
                        break;
                    default:
                        $io->error("Stratégie inconnue: {$strategy}");
                        return Command::FAILURE;
                }
                
                $io->success('Dispatch terminé');
            }

            // Afficher les statistiques
            $this->displayStats($io, $symbols, $live);

        } catch (\Exception $e) {
            $io->error('Erreur lors du dispatch: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function loadSymbols(string $input): array
    {
        // Si c'est un fichier CSV
        if (file_exists($input)) {
            $handle = fopen($input, 'r');
            if (!$handle) {
                throw new \RuntimeException("Impossible de lire le fichier: {$input}");
            }

            $symbols = [];
            while (($row = fgetcsv($handle)) !== false) {
                if (!empty($row[0])) {
                    $symbols[] = trim($row[0]);
                }
            }
            fclose($handle);
            return $symbols;
        }

        // Sinon, traiter comme une liste de symboles séparés par des virgules
        return array_filter(array_map('trim', explode(',', $input)));
    }

    private function displayStats(SymfonyStyle $io, array $symbols, bool $live): void
    {
        $assignments = $this->dispatcher->getCurrentAssignments();
        $workers = $this->dispatcher->getWorkers();

        $stats = [];
        foreach ($workers as $worker) {
            $count = count(array_filter($assignments, fn($w) => $w === $worker));
            $stats[] = [$worker, $count];
        }

        $io->table(['Worker', 'Nombre de contrats'], $stats);
        
        if ($live) {
            $io->note('Mode LIVE activé - Les requêtes HTTP ont été envoyées');
        } else {
            $io->note('Mode DRY-RUN - Aucune requête HTTP envoyée');
        }
    }
}
