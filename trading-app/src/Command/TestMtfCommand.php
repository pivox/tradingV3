<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Mtf\Service\MtfService;
use App\Domain\Mtf\Service\MtfTimeService;
use App\Repository\MtfSwitchRepository;
use App\Repository\MtfStateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-mtf',
    description: 'Teste le système MTF'
)]
class TestMtfCommand extends Command
{
    public function __construct(
        private readonly MtfService $mtfService,
        private readonly MtfTimeService $timeService,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly MtfStateRepository $mtfStateRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbole à tester', 'BTCUSDT')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Mode dry-run (pas d\'exécution réelle)')
            ->setHelp('
Cette commande teste le système MTF en exécutant un cycle complet.

Exemples:
  php bin/console app:test-mtf                    # Test avec BTCUSDT
  php bin/console app:test-mtf --symbol=ETHUSDT  # Test avec ETHUSDT
  php bin/console app:test-mtf --dry-run         # Mode dry-run
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getOption('symbol');
        $dryRun = $input->getOption('dry-run');

        $io->title('Test du système MTF');
        $io->text("Symbole: {$symbol}");
        $io->text("Mode dry-run: " . ($dryRun ? 'OUI' : 'NON'));

        try {
            // Test des kill switches
            $io->section('Test des kill switches');
            $globalSwitch = $this->mtfSwitchRepository->isGlobalSwitchOn();
            $symbolSwitch = $this->mtfSwitchRepository->isSymbolSwitchOn($symbol);
            
            $io->table(
                ['Switch', 'État'],
                [
                    ['Global', $globalSwitch ? 'ON' : 'OFF'],
                    ["Symbol {$symbol}", $symbolSwitch ? 'ON' : 'OFF']
                ]
            );

            if (!$globalSwitch) {
                $io->error('Le kill switch global est OFF');
                return Command::FAILURE;
            }

            if (!$symbolSwitch) {
                $io->error("Le kill switch pour {$symbol} est OFF");
                return Command::FAILURE;
            }

            // Test du service de temps
            $io->section('Test du service de temps');
            $now = $this->timeService->getCurrentAlignedUtc();
            $io->text("Heure UTC courante: {$now->format('Y-m-d H:i:s')}");
            
            $lastClosed4h = $this->timeService->getLastClosedKlineTime($now, \App\Domain\Common\Enum\Timeframe::TF_4H);
            $io->text("Dernière bougie 4h fermée: {$lastClosed4h->format('Y-m-d H:i:s')}");
            
            $lastClosed1h = $this->timeService->getLastClosedKlineTime($now, \App\Domain\Common\Enum\Timeframe::TF_1H);
            $io->text("Dernière bougie 1h fermée: {$lastClosed1h->format('Y-m-d H:i:s')}");
            
            $lastClosed15m = $this->timeService->getLastClosedKlineTime($now, \App\Domain\Common\Enum\Timeframe::TF_15M);
            $io->text("Dernière bougie 15m fermée: {$lastClosed15m->format('Y-m-d H:i:s')}");

            // Test de la fenêtre de grâce
            $io->section('Test de la fenêtre de grâce');
            $inGrace4h = $this->timeService->isInGraceWindow($now, \App\Domain\Common\Enum\Timeframe::TF_4H);
            $inGrace1h = $this->timeService->isInGraceWindow($now, \App\Domain\Common\Enum\Timeframe::TF_1H);
            $inGrace15m = $this->timeService->isInGraceWindow($now, \App\Domain\Common\Enum\Timeframe::TF_15M);
            
            $io->table(
                ['Timeframe', 'Dans la fenêtre de grâce'],
                [
                    ['4h', $inGrace4h ? 'OUI' : 'NON'],
                    ['1h', $inGrace1h ? 'OUI' : 'NON'],
                    ['15m', $inGrace15m ? 'OUI' : 'NON']
                ]
            );

            // Test de l'état MTF
            $io->section('Test de l\'état MTF');
            $state = $this->mtfStateRepository->getOrCreateForSymbol($symbol);
            
            $io->table(
                ['Propriété', 'Valeur'],
                [
                    ['Symbole', $state->getSymbol()],
                    ['4h validé', $state->is4hValidated() ? 'OUI' : 'NON'],
                    ['1h validé', $state->is1hValidated() ? 'OUI' : 'NON'],
                    ['15m validé', $state->is15mValidated() ? 'OUI' : 'NON'],
                    ['Côté 4h', $state->get4hSide() ?? 'N/A'],
                    ['Côté 1h', $state->get1hSide() ?? 'N/A'],
                    ['Côté 15m', $state->get15mSide() ?? 'N/A'],
                    ['Cohérent', $state->hasConsistentSides() ? 'OUI' : 'NON']
                ]
            );

            // Test d'exécution du cycle MTF
            if (!$dryRun) {
                $io->section('Test d\'exécution du cycle MTF');
                $io->text('Exécution du cycle MTF...');
                
                $runId = \Ramsey\Uuid\Uuid::uuid4();
                $result = $this->mtfService->executeMtfCycle($runId);
                
                $io->success('Cycle MTF exécuté avec succès');
                $io->text('Résultat: ' . json_encode($result, JSON_PRETTY_PRINT));
            } else {
                $io->note('Mode dry-run activé, pas d\'exécution réelle');
            }

            $io->success('Tous les tests MTF ont réussi');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors du test: ' . $e->getMessage());
            $this->logger->error('[Test MTF] Test failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}




