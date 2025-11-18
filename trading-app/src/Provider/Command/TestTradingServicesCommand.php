<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Trading\Storage\OrderStateRepositoryInterface;
use App\Trading\Storage\PositionStateRepositoryInterface;
use App\Trading\Sync\TradingStateSyncRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'trading:test-services',
    description: 'Teste que tous les services Trading sont correctement configurés'
)]
final class TestTradingServicesCommand extends Command
{
    public function __construct(
        private readonly ?TradingStateSyncRunner $syncRunner,
        private readonly ?PositionStateRepositoryInterface $positionStateRepository,
        private readonly ?OrderStateRepositoryInterface $orderStateRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Test des services Trading');

        $errors = [];

        // Test TradingStateSyncRunner
        if ($this->syncRunner === null) {
            $errors[] = 'TradingStateSyncRunner non disponible';
        } else {
            $io->success('✅ TradingStateSyncRunner disponible');
        }

        // Test PositionStateRepositoryInterface
        if ($this->positionStateRepository === null) {
            $errors[] = 'PositionStateRepositoryInterface non disponible';
        } else {
            $io->success('✅ PositionStateRepositoryInterface disponible');
            try {
                $openPositions = $this->positionStateRepository->findLocalOpenPositions();
                $io->info(sprintf('   → %d position(s) ouverte(s) en BDD', count($openPositions)));
            } catch (\Throwable $e) {
                $io->warning('   → Erreur lors de la lecture: ' . $e->getMessage());
            }
        }

        // Test OrderStateRepositoryInterface
        if ($this->orderStateRepository === null) {
            $errors[] = 'OrderStateRepositoryInterface non disponible';
        } else {
            $io->success('✅ OrderStateRepositoryInterface disponible');
            try {
                $openOrders = $this->orderStateRepository->findLocalOpenOrders();
                $io->info(sprintf('   → %d ordre(s) ouvert(s) en BDD', count($openOrders)));
            } catch (\Throwable $e) {
                $io->warning('   → Erreur lors de la lecture: ' . $e->getMessage());
            }
        }

        if (!empty($errors)) {
            $io->error('Erreurs détectées:');
            foreach ($errors as $error) {
                $io->writeln("  - $error");
            }
            return Command::FAILURE;
        }

        $io->success('Tous les services sont correctement configurés !');
        return Command::SUCCESS;
    }
}


