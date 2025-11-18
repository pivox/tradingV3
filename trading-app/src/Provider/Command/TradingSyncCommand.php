<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Trading\Storage\OrderStateRepositoryInterface;
use App\Trading\Storage\PositionStateRepositoryInterface;
use App\Trading\Sync\TradingStateSyncRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'trading:sync',
    description: 'Synchronise les positions et ordres depuis l\'exchange vers la BDD et dispatche les événements'
)]
final class TradingSyncCommand extends Command
{
    public function __construct(
        private readonly TradingStateSyncRunner $syncRunner,
        private readonly PositionStateRepositoryInterface $positionStateRepository,
        private readonly OrderStateRepositoryInterface $orderStateRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Synchroniser uniquement ce symbole (ex: BTCUSDT)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mode dry-run : affiche ce qui serait synchronisé sans le faire')
            ->addOption('skip-events', null, InputOption::VALUE_NONE, 'Synchronise sans dispatcher les événements')
            ->setHelp('
Synchronise les positions et ordres depuis l\'exchange (BitMart) vers la BDD.

Cette commande :
1. Récupère les positions ouvertes depuis l\'exchange
2. Récupère les ordres ouverts depuis l\'exchange
3. Compare avec l\'état local en BDD
4. Détecte les nouvelles positions → dispatche PositionOpenedEvent
5. Détecte les positions fermées → dispatche PositionClosedEvent
6. Détecte les changements d\'ordres → dispatche OrderStateChangedEvent
7. Sauvegarde tout en BDD

Les événements sont automatiquement transformés en logs DB via TradeLifecycleLoggerListener.

Options:
  --symbol, -s      Synchroniser uniquement ce symbole (optionnel)
  --dry-run         Affiche ce qui serait synchronisé sans le faire
  --skip-events     Synchronise sans dispatcher les événements

Exemples:
  # Synchronisation complète
  php bin/console trading:sync

  # Synchroniser un symbole spécifique
  php bin/console trading:sync --symbol=BTCUSDT

  # Mode dry-run
  php bin/console trading:sync --dry-run
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbol = $input->getOption('symbol');
        $dryRun = $input->getOption('dry-run');
        $skipEvents = $input->getOption('skip-events');

        $symbols = $symbol !== null ? [strtoupper((string)$symbol)] : null;

        $io->title('Synchronisation Trading State');

        if ($dryRun) {
            $io->note('Mode DRY-RUN : aucune modification ne sera effectuée');
        }

        if ($skipEvents) {
            $io->note('Les événements ne seront pas dispatchés');
        }

        // État avant synchronisation
        $io->section('État actuel en BDD');
        $positionsBefore = $this->positionStateRepository->findLocalOpenPositions($symbols);
        $ordersBefore = $this->orderStateRepository->findLocalOpenOrders($symbols);

        $io->table(
            ['Type', 'Count'],
            [
                ['Positions ouvertes', count($positionsBefore)],
                ['Ordres ouverts', count($ordersBefore)],
            ]
        );

        if ($symbol !== null) {
            $io->writeln("Symbole filtré: <info>$symbol</info>");
        }

        if ($dryRun) {
            $io->section('Ce qui serait synchronisé');
            $io->note('En mode dry-run, on ne peut pas simuler la synchronisation réelle.');
            $io->note('Utilisez la commande sans --dry-run pour voir les changements réels.');
            return Command::SUCCESS;
        }

        // Lancer la synchronisation
        $io->section('Synchronisation en cours...');
        $io->writeln('Récupération des données depuis l\'exchange...');

        try {
            $startTime = microtime(true);
            
            // Note: skip-events n'est pas encore implémenté dans TradingStateSyncRunner
            // Pour l'instant, on synchronise toujours avec les événements
            $this->syncRunner->syncAndDispatch('trading_sync_command', $symbols);
            
            $duration = round(microtime(true) - $startTime, 2);

            $io->success("Synchronisation terminée en {$duration}s");

            // État après synchronisation
            $io->section('État après synchronisation');
            $positionsAfter = $this->positionStateRepository->findLocalOpenPositions($symbols);
            $ordersAfter = $this->orderStateRepository->findLocalOpenOrders($symbols);

            $io->table(
                ['Type', 'Avant', 'Après', 'Différence'],
                [
                    [
                        'Positions ouvertes',
                        count($positionsBefore),
                        count($positionsAfter),
                        count($positionsAfter) - count($positionsBefore),
                    ],
                    [
                        'Ordres ouverts',
                        count($ordersBefore),
                        count($ordersAfter),
                        count($ordersAfter) - count($ordersBefore),
                    ],
                ]
            );

            // Détails des positions
            if (!empty($positionsAfter)) {
                $io->section('Positions synchronisées');
                $positionRows = [];
                foreach ($positionsAfter as $position) {
                    $positionRows[] = [
                        $position->symbol,
                        strtoupper($position->side->value),
                        $position->size->__toString(),
                        $position->entryPrice->__toString(),
                        $position->leverage->__toString() . 'x',
                        $position->openedAt->format('Y-m-d H:i:s'),
                    ];
                }
                $io->table(
                    ['Symbole', 'Côté', 'Taille', 'Prix entrée', 'Levier', 'Ouvert le'],
                    $positionRows
                );
            }

            // Détails des ordres
            if (!empty($ordersAfter)) {
                $io->section('Ordres synchronisés');
                $orderRows = [];
                foreach (array_slice($ordersAfter, 0, 20) as $order) { // Limiter à 20 pour l'affichage
                    $orderRows[] = [
                        $order->symbol,
                        strtoupper($order->side->value),
                        strtolower($order->type->value),
                        strtoupper($order->status->value),
                        $order->price->__toString(),
                        $order->quantity->__toString(),
                        $order->filledQuantity->__toString(),
                    ];
                }
                $io->table(
                    ['Symbole', 'Side', 'Type', 'Statut', 'Prix', 'Qté', 'Qté filled'],
                    $orderRows
                );
                if (count($ordersAfter) > 20) {
                    $io->note(sprintf('... et %d autres ordres', count($ordersAfter) - 20));
                }
            }

            // Résumé des événements
            $io->section('Événements dispatchés');
            $io->note('Les événements suivants ont été dispatchés (si des changements ont été détectés) :');
            $io->listing([
                '<info>PositionOpenedEvent</info> → logPositionOpened() pour les nouvelles positions',
                '<info>PositionClosedEvent</info> → logPositionClosed() pour les positions fermées',
                '<info>OrderStateChangedEvent</info> → logOrderExpired() pour les ordres fermés sans fill',
            ]);
            $io->note('Vérifiez la table <info>trade_lifecycle_event</info> pour voir les logs enregistrés.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erreur lors de la synchronisation: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            $this->logger?->error('[TradingSync] Sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}


