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
        $out->writeln("<comment>Listening for messages... (Press Ctrl+C to stop)</comment>");
        
        // Continuer à faire tourner la boucle pour recevoir les messages
        $loop = \React\EventLoop\Loop::get();
        
        // Gérer l'arrêt propre avec Ctrl+C
        if (function_exists('pcntl_signal')) {
            $loop->addSignal(SIGINT, function () use ($loop, $out) {
                $out->writeln("\n<comment>Stopping...</comment>");
                $this->ws->disconnect();
                $loop->stop();
            });
            $loop->addSignal(SIGTERM, function () use ($loop, $out) {
                $out->writeln("\n<comment>Stopping...</comment>");
                $this->ws->disconnect();
                $loop->stop();
            });
        }
        
        // Faire tourner la boucle pour recevoir les messages
        $loop->run();
        
        return Command::SUCCESS;
    }
}






