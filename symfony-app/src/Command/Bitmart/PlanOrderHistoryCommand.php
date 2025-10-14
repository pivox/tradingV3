<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Service\Bitmart\Private\TrailOrdersService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:bitmart:plan:history', description: 'Historique des ordres planifiés')]
final class PlanOrderHistoryCommand extends Command
{
    public function __construct(private readonly TrailOrdersService $trail)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::OPTIONAL, 'Symbole');
        $this->addArgument('status', InputArgument::OPTIONAL, 'Statut');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $query = [];
        $symbol = $input->getArgument('symbol');
        $status = $input->getArgument('status');
        if (is_string($symbol) && $symbol !== '') {
            $query['symbol'] = $symbol;
        }
        if (is_string($status) && $status !== '') {
            $query['status'] = $status;
        }
        $resp = $this->trail->history($query);
        $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}







