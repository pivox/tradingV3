<?php
// src/Command/Bitmart/GetSystemTimeCommand.php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-system-time',
    description: 'Affiche l\'heure serveur BitMart Futures V2',
)]
final class GetSystemTimeCommand extends Command
{
    public function __construct(
        private readonly BitmartHttpClientPublic $client,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $time = $this->client->getSystemTimeMs();
            $io->success("Heure serveur (ms): $time");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
