<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Price\PriceProviderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-price',
    description: 'Teste les sources de prix pour un symbole'
)]
class TestPriceCommand extends Command
{
    public function __construct(
        private readonly PriceProviderService $priceProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à tester (ex: SUNUSDT)')
            ->setHelp('Teste toutes les sources de prix pour un symbole donné');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getArgument('symbol');

        $io->title("Test des sources de prix pour $symbol");

        // Test de toutes les sources
        $prices = $this->priceProvider->getAllPrices($symbol);

        $io->section('Prix récupérés :');
        foreach ($prices as $source => $price) {
            if ($price !== null) {
                $io->writeln("✅ $source: " . number_format($price, 8) . " USDT");
            } else {
                $io->writeln("❌ $source: Non disponible");
            }
        }

        // Test du prix principal
        $mainPrice = $this->priceProvider->getPrice($symbol);
        if ($mainPrice !== null) {
            $io->success("Prix principal récupéré : " . number_format($mainPrice, 8) . " USDT");
        } else {
            $io->error("Impossible de récupérer le prix pour $symbol");
        }

        return Command::SUCCESS;
    }
}
