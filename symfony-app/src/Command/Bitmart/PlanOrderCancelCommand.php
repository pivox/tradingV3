<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Service\Bitmart\Private\TrailOrdersService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:bitmart:plan:cancel', description: 'Annule un ordre planifié')]
final class PlanOrderCancelCommand extends Command
{
    public function __construct(private readonly TrailOrdersService $trail)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::REQUIRED, 'Symbole');
        $this->addArgument('plan_order_id', InputArgument::OPTIONAL, 'Plan Order ID');
        $this->addArgument('client_order_id', InputArgument::OPTIONAL, 'Client Order ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = ['symbol' => (string) $input->getArgument('symbol')];
        $poid = $input->getArgument('plan_order_id');
        $cid = $input->getArgument('client_order_id');
        if (is_string($poid) && $poid !== '') {
            $params['plan_order_id'] = $poid;
        }
        if (is_string($cid) && $cid !== '') {
            $params['client_order_id'] = $cid;
        }
        $resp = $this->trail->cancel($params);
        $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}


