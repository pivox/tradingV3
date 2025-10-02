<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Service\Bitmart\Private\TradesService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:bitmart:trades', description: 'Liste les fills (exécutions) Futures (privé)')]
final class TradesFillsCommand extends Command
{
    public function __construct(private readonly TradesService $trades)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::OPTIONAL, 'Symbole');
        $this->addArgument('order_id', InputArgument::OPTIONAL, 'Order ID');
        $this->addArgument('client_order_id', InputArgument::OPTIONAL, 'Client Order ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $query = [];
        foreach (['symbol','order_id','client_order_id'] as $arg) {
            $val = $input->getArgument($arg);
            if (is_string($val) && $val !== '') {
                $query[$arg] = $val;
            }
        }
        $resp = $this->trades->fills($query);
        $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}


