<?php
namespace App\WebSocket\Command;

use App\WebSocket\Service\WsDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ws:subscribe', description: 'Abonne un symbole aux TF')]
final class WsSubscribeCommand extends Command
{
    public function __construct(private WsDispatcher $ws) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('symbol', InputArgument::REQUIRED, 'ex: BTCUSDT')
            ->addArgument('tfs', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'TF list', ['1m','5m','15m','1h','4h']);
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $symbol = (string)$in->getArgument('symbol');
        $tfs = $in->getArgument('tfs');
        $this->ws->subscribe($symbol, $tfs);
        $out->writeln("<info>OK</info> $symbol → ".implode(',', $tfs));
        return Command::SUCCESS;
    }
}






