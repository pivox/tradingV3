<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Service\Bitmart\Private\PositionsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:bitmart:positions', description: 'Liste les positions futures (privé)')]
final class PositionsListCommand extends Command
{
    public function __construct(private readonly PositionsService $positionsService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::OPTIONAL, 'Symbole (ex: BTCUSDT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symbol = $input->getArgument('symbol');
        $query = [];
        if (is_string($symbol) && $symbol !== '') {
            $query['symbol'] = $symbol;
        }
        $resp = $this->positionsService->list($query);
        $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}


