<?php

namespace App\Command;

use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\ContractPersister;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:bitmart:fetch-contracts',
    description: 'Fetch all contracts from BitMart and persist them to the database.'
)]
class FetchContractsCommand extends Command
{
    public function __construct(
        private BitmartFetcher $bitmartFetcher,
        private ContractPersister $contractPersister
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln('<info>Fetching contracts from BitMart...</info>');

            $contracts = $this->bitmartFetcher->fetchContracts(); // retourne un tableau de ContractDto

            foreach ($contracts as $dto) {
                $this->contractPersister->persistFromDto($dto, 'bitmart');
                $output->writeln(sprintf('Persisted contract: <comment>%s</comment>', $dto->symbol));
            }

            $this->contractPersister->flush();

            $output->writeln('<info>All contracts have been persisted successfully.</info>');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
