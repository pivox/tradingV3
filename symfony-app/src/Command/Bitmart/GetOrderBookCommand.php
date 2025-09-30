<?php
// src/Command/Bitmart/GetOrderBookCommand.php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-order-book',
    description: 'Affiche le carnet d\'ordres',
)]
final class GetOrderBookCommand extends Command
{
    public function __construct(
        private readonly BitmartHttpClientPublic $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole ex: BTCUSDT')
            ->addArgument('limit', InputArgument::OPTIONAL, 'Nb niveaux', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $symbol = (string) $input->getArgument('symbol');
            $limit  = (int) $input->getArgument('limit');

            $book = $this->client->getOrderBook($symbol, $limit);

            $io->section('Bids:');
            foreach ($book['bids'] as $b) {
                $io->writeln(sprintf('%s @ %s', $b[1], $b[0]));
            }
            $io->section('Asks:');
            foreach ($book['asks'] as $a) {
                $io->writeln(sprintf('%s @ %s', $a[1], $a[0]));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
