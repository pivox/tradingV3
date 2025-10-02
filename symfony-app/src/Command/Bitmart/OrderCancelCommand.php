<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Service\Bitmart\Private\OrdersService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:bitmart:order:cancel', description: 'Annule un ordre Futures (privé)')]
final class OrderCancelCommand extends Command
{
    public function __construct(private readonly OrdersService $orders)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::REQUIRED, 'Symbole');
        $this->addArgument('order_id', InputArgument::OPTIONAL, 'Order ID');
        $this->addArgument('client_order_id', InputArgument::OPTIONAL, 'Client Order ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = ['symbol' => (string) $input->getArgument('symbol')];
        $oid = $input->getArgument('order_id');
        $cid = $input->getArgument('client_order_id');
        if (is_string($oid) && $oid !== '') {
            $params['order_id'] = $oid;
        }
        if (is_string($cid) && $cid !== '') {
            $params['client_order_id'] = $cid;
        }
        $resp = $this->orders->cancel($params);
        $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}


