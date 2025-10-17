<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Http\BitmartClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-bitmart',
    description: 'Teste la connectivité avec l\'API BitMart'
)]
class TestBitmartCommand extends Command
{
    public function __construct(
        private readonly BitmartClient $bitmartClient,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test de connectivité BitMart');

        try {
            // Test de santé
            $io->section('Test de santé de l\'API');
            $health = $this->bitmartClient->healthCheck();
            
            if ($health['status'] === 'healthy') {
                $io->success('API BitMart accessible');
            } else {
                $io->error('API BitMart inaccessible: ' . ($health['error'] ?? 'Erreur inconnue'));
                return Command::FAILURE;
            }

            // Test des contrats
            $io->section('Test des contrats');
            $contracts = $this->bitmartClient->getContracts();
            
            if (isset($contracts['data']['contracts'])) {
                $contractsCount = count($contracts['data']['contracts']);
                $io->success("Récupération de {$contractsCount} contrats");
            } else {
                $io->warning('Impossible de récupérer les contrats');
            }

            // Test des klines
            $io->section('Test des klines');
            $klines = $this->bitmartClient->getKlines(
                symbol: 'BTCUSDT',
                step: 1,
                limit: 5
            );
            
            if (isset($klines['data']['klines'])) {
                $klinesCount = count($klines['data']['klines']);
                $io->success("Récupération de {$klinesCount} klines BTCUSDT 1m");
            } else {
                $io->warning('Impossible de récupérer les klines');
            }

            // Test du ticker
            $io->section('Test du ticker');
            $ticker = $this->bitmartClient->getTicker('BTCUSDT');
            
            if (isset($ticker['data']['ticker'])) {
                $price = $ticker['data']['ticker']['last_price'] ?? 'N/A';
                $io->success("Prix BTCUSDT: {$price}");
            } else {
                $io->warning('Impossible de récupérer le ticker');
            }

            $io->success('Tous les tests de connectivité ont réussi');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors du test: ' . $e->getMessage());
            $this->logger->error('[Test BitMart] Test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}




