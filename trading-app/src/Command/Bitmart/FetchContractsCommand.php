<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Infrastructure\Http\BitmartRestClient;
use App\Infrastructure\Persistence\ContractRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:fetch-contracts',
    description: 'Récupère la liste des contrats disponibles sur BitMart Futures'
)]
final class FetchContractsCommand extends Command
{
    public function __construct(
        private readonly BitmartRestClient $bitmartClient,
        private readonly ContractRepository $contractRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbole spécifique à récupérer')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Format de sortie (table|json)', 'table')
            ->addOption('save', null, InputOption::VALUE_NONE, 'Sauvegarder les contrats en base de données')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Afficher les statistiques des contrats en base')
            ->setHelp('
Cette commande récupère la liste des contrats disponibles sur BitMart Futures.

Exemples:
  php bin/console bitmart:fetch-contracts
  php bin/console bitmart:fetch-contracts --symbol=BTCUSDT
  php bin/console bitmart:fetch-contracts --output=json
  php bin/console bitmart:fetch-contracts --save
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getOption('symbol');
        $outputFormat = $input->getOption('output');
        $save = $input->getOption('save');
        $stats = $input->getOption('stats');

        try {
            $io->title('Récupération des contrats BitMart Futures');

            // Si on demande les statistiques, on les affiche et on sort
            if ($stats) {
                $this->displayContractStats($io);
                return Command::SUCCESS;
            }

            if ($symbol) {
                $io->info("Récupération des détails pour le symbole: {$symbol}");
                $contracts = [$this->bitmartClient->fetchContractDetails($symbol)];
            } else {
                $io->info('Récupération de tous les contrats disponibles...');
                $contracts = $this->bitmartClient->fetchContracts();
            }

            if (empty($contracts)) {
                $io->warning('Aucun contrat trouvé');
                return Command::SUCCESS;
            }

            $io->success(sprintf('Récupéré %d contrat(s)', count($contracts)));

            // Sauvegarde optionnelle
            if ($save) {
                $io->info('Sauvegarde des contrats en base de données...');
                $upsertedCount = $this->contractRepository->upsertContracts($contracts);
                $io->success(sprintf('✅ %d contrats sauvegardés/mis à jour en base de données', $upsertedCount));
            }

            // Affichage des résultats
            if ($outputFormat === 'json') {
                $output->writeln(json_encode($contracts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->displayContractsTable($io, $contracts);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de la récupération des contrats: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function displayContractsTable(SymfonyStyle $io, array $contracts): void
    {
        $headers = ['Symbole', 'Nom', 'Type', 'Taille Min', 'Taille Max', 'Tick Size', 'Status'];
        $rows = [];

        foreach ($contracts as $contract) {
            $rows[] = [
                $contract['symbol'] ?? 'N/A',
                $contract['name'] ?? 'N/A',
                $contract['contract_type'] ?? 'N/A',
                $contract['min_size'] ?? 'N/A',
                $contract['max_size'] ?? 'N/A',
                $contract['tick_size'] ?? 'N/A',
                $contract['status'] ?? 'N/A'
            ];
        }

        $io->table($headers, $rows);

        // Affichage des détails supplémentaires
        if (count($contracts) === 1) {
            $contract = $contracts[0];
            $io->section('Détails du contrat');
            
            $details = [
                'Symbole' => $contract['symbol'] ?? 'N/A',
                'Nom' => $contract['name'] ?? 'N/A',
                'Type' => $contract['contract_type'] ?? 'N/A',
                'Devise de base' => $contract['base_currency'] ?? 'N/A',
                'Devise de quote' => $contract['quote_currency'] ?? 'N/A',
                'Taille minimale' => $contract['min_size'] ?? 'N/A',
                'Taille maximale' => $contract['max_size'] ?? 'N/A',
                'Tick size' => $contract['tick_size'] ?? 'N/A',
                'Multiplicateur' => $contract['multiplier'] ?? 'N/A',
                'Statut' => $contract['status'] ?? 'N/A',
                'Date de création' => isset($contract['create_time']) 
                    ? (new \DateTimeImmutable('@' . $contract['create_time'], new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
                    : 'N/A'
            ];

            foreach ($details as $key => $value) {
                $io->writeln(sprintf('<info>%s:</info> %s', $key, $value));
            }
        }
    }

    private function displayContractStats(SymfonyStyle $io): void
    {
        $io->title('Statistiques des contrats en base de données');

        try {
            $stats = $this->contractRepository->getContractStats();
            $activeCount = $this->contractRepository->countActiveContracts();

            if (empty($stats)) {
                $io->warning('Aucune statistique disponible - aucun contrat en base de données');
                return;
            }

            $io->section('Résumé');
            $io->definitionList(
                ['Contrats actifs' => number_format($activeCount)],
                ['Total des contrats' => number_format(array_sum(array_column($stats, 'count')))]
            );

            $io->section('Répartition par devise de quote et statut');
            $headers = ['Devise Quote', 'Statut', 'Nombre', 'Volume Moyen', 'Volume Total'];
            $rows = [];

            foreach ($stats as $stat) {
                $rows[] = [
                    $stat['quoteCurrency'] ?? 'N/A',
                    $stat['status'] ?? 'N/A',
                    number_format($stat['count']),
                    number_format(floatval($stat['avgVolume']), 2),
                    number_format(floatval($stat['totalVolume']), 2)
                ];
            }

            $io->table($headers, $rows);

        } catch (\Exception $e) {
            $io->error('Erreur lors de la récupération des statistiques: ' . $e->getMessage());
        }
    }
}
