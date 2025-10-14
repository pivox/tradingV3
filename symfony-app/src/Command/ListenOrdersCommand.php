<?php

namespace App\Command;

use App\Bitmart\Ws\KlineStream;
use App\Bitmart\Ws\OrderStream;
use App\Bitmart\Ws\PositionStream;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bitmart:listen-orders')]
final class ListenOrdersCommand extends Command
{
    public function __construct(private readonly OrderStream $stream) { parent::__construct(); }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $this->stream->listen(function(array $events) use ($out) {
            foreach ($events as $e) {
                $out->writeln(sprintf(
                    '[Order] %s #%s state=%d action=%d %s %s deal=%s@%s',
                    $e['symbol'],
                    $e['order_id'],
                    $e['state'],
                    $e['action'],
                    $e['type'],
                    $e['side'],
                    $e['deal_size'] ?? '0',
                    $e['deal_avg_price'] ?? '0'
                ));
            }
        });
        return Command::SUCCESS;
    }
}


