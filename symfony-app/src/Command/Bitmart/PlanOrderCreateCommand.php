<?php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Service\Bitmart\Private\TrailOrdersService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:bitmart:plan:create', description: 'Crée un ordre planifié (stop/trailing/trigger)')]
final class PlanOrderCreateCommand extends Command
{
    public function __construct(private readonly TrailOrdersService $trail)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::REQUIRED, 'Symbole ex: BTCUSDT');
        $this->addArgument('plan_type', InputArgument::REQUIRED, 'tp|sl|trailing|trigger ...');
        $this->addArgument('size', InputArgument::REQUIRED, 'Taille (contrats)');
        $this->addArgument('trigger_price', InputArgument::OPTIONAL, 'Prix de déclenchement');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = [
            'symbol' => (string) $input->getArgument('symbol'),
            'plan_type' => (string) $input->getArgument('plan_type'),
            'size' => (string) $input->getArgument('size'),
        ];
        $tp = $input->getArgument('trigger_price');
        if (is_string($tp) && $tp !== '') {
            $params['trigger_price'] = $tp;
        }
        $resp = $this->trail->create($params);
        $output->writeln(json_encode($resp, JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}







