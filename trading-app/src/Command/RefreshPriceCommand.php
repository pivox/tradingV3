<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Price\PriceRefreshService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:refresh-price',
    description: 'Rafraîchit le prix d\'un symbole depuis les sources alternatives'
)]
class RefreshPriceCommand extends Command
{
    public function __construct(
        private readonly PriceRefreshService $priceRefreshService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à rafraîchir (ex: SUNUSDT)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Rafraîchir tous les symboles actifs')
            ->setHelp('Rafraîchit le prix d\'un symbole depuis les sources alternatives');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        if ($input->getOption('all')) {
            $io->title('Rafraîchissement de tous les prix');
            $io->writeln('Cette fonctionnalité sera implémentée prochainement...');
            return Command::SUCCESS;
        }

        $symbol = $input->getArgument('symbol');
        $io->title("Rafraîchissement du prix pour $symbol");

        $success = $this->priceRefreshService->refreshPrice($symbol);
        
        if ($success) {
            $io->success("Prix rafraîchi avec succès pour $symbol");
        } else {
            $io->error("Échec du rafraîchissement du prix pour $symbol");
        }

        return $success ? Command::SUCCESS : Command::FAILURE;
    }
}
