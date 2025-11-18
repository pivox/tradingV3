<?php

declare(strict_types=1);

namespace App\Provider\Command;

use App\Trading\Dto\OrderDto;
use App\Trading\Dto\PositionDto;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Event\OrderStateChangedEvent;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Event\SymbolSkippedEvent;
use App\Trading\Sync\TradingStateSyncRunner;
use Brick\Math\BigDecimal;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'trading:test-events',
    description: 'Teste le système d\'événements Trading et les listeners TradeLifecycleLogger'
)]
final class TestTradingEventsCommand extends Command
{
    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher,
        private readonly ?TradingStateSyncRunner $syncRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('event', 'e', InputOption::VALUE_REQUIRED, 'Type d\'événement à tester (position-opened|position-closed|order-changed|symbol-skipped|sync-all)', 'sync-all')
            ->addOption('symbol', 's', InputOption::VALUE_OPTIONAL, 'Symbole pour les tests', 'BTCUSDT')
            ->setHelp('
Teste le système d\'événements Trading :

Options:
  --event, -e    Type d\'événement à tester:
                 - position-opened  : Teste PositionOpenedEvent
                 - position-closed   : Teste PositionClosedEvent
                 - order-changed     : Teste OrderStateChangedEvent
                 - symbol-skipped    : Teste SymbolSkippedEvent
                 - sync-all          : Lance une synchronisation complète (défaut)

Exemples:
  # Tester la synchronisation complète
  php bin/console trading:test-events

  # Tester un événement spécifique
  php bin/console trading:test-events --event=position-opened --symbol=ETHUSDT

  # Tester un symbole skippé
  php bin/console trading:test-events --event=symbol-skipped --symbol=BTCUSDT
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $eventType = $input->getOption('event');
        $symbol = $input->getOption('symbol');

        if ($this->eventDispatcher === null) {
            $io->error('EventDispatcher non disponible');
            return Command::FAILURE;
        }

        $io->title('Test des événements Trading');

        try {
            match ($eventType) {
                'position-opened' => $this->testPositionOpened($io, $symbol),
                'position-closed' => $this->testPositionClosed($io, $symbol),
                'order-changed' => $this->testOrderStateChanged($io, $symbol),
                'symbol-skipped' => $this->testSymbolSkipped($io, $symbol),
                'sync-all' => $this->testSyncAll($io, $symbol),
                default => throw new \InvalidArgumentException("Type d'événement invalide: $eventType"),
            };

            $io->success('Test terminé avec succès !');
            $io->note('Vérifiez la table trade_lifecycle_event pour voir les logs enregistrés.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erreur lors du test: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function testPositionOpened(SymfonyStyle $io, string $symbol): void
    {
        $io->section('Test PositionOpenedEvent');

        $position = new PositionDto(
            symbol: $symbol,
            side: \App\Common\Enum\PositionSide::LONG,
            size: BigDecimal::of('0.1'),
            entryPrice: BigDecimal::of('50000'),
            markPrice: BigDecimal::of('50100'),
            unrealizedPnl: BigDecimal::of('10'),
            leverage: BigDecimal::of('10'),
            openedAt: new \DateTimeImmutable('now'),
            raw: [
                'position_id' => 'test_pos_' . time(),
                'source' => 'test_command',
            ]
        );

        $io->writeln("Dispatching PositionOpenedEvent pour $symbol...");
        $this->eventDispatcher->dispatch(new PositionOpenedEvent(
            position: $position,
            runId: 'test-run-' . time(),
            exchange: 'BITMART',
            accountId: 'test-account',
            extra: ['test' => true]
        ));

        $io->success("PositionOpenedEvent dispatché !");
    }

    private function testPositionClosed(SymfonyStyle $io, string $symbol): void
    {
        $io->section('Test PositionClosedEvent');

        $history = new PositionHistoryEntryDto(
            symbol: $symbol,
            side: \App\Common\Enum\PositionSide::LONG,
            size: BigDecimal::of('0.1'),
            entryPrice: BigDecimal::of('50000'),
            exitPrice: BigDecimal::of('51000'),
            realizedPnl: BigDecimal::of('100'),
            fees: BigDecimal::of('5'),
            openedAt: new \DateTimeImmutable('-1 hour'),
            closedAt: new \DateTimeImmutable('now'),
            raw: [
                'position_id' => 'test_pos_closed_' . time(),
                'source' => 'test_command',
            ]
        );

        $io->writeln("Dispatching PositionClosedEvent pour $symbol...");
        $this->eventDispatcher->dispatch(new PositionClosedEvent(
            positionHistory: $history,
            runId: 'test-run-' . time(),
            exchange: 'BITMART',
            accountId: 'test-account',
            reasonCode: 'profit_or_tp',
            extra: ['test' => true, 'pnl' => '100']
        ));

        $io->success("PositionClosedEvent dispatché !");
    }

    private function testOrderStateChanged(SymfonyStyle $io, string $symbol): void
    {
        $io->section('Test OrderStateChangedEvent');

        $order = new OrderDto(
            orderId: 'test_order_' . time(),
            clientOrderId: 'test_client_' . time(),
            symbol: $symbol,
            side: \App\Common\Enum\OrderSide::BUY,
            type: \App\Common\Enum\OrderType::LIMIT,
            status: \App\Common\Enum\OrderStatus::CANCELLED,
            price: BigDecimal::of('50000'),
            quantity: BigDecimal::of('0.1'),
            filledQuantity: BigDecimal::zero(),
            avgFilledPrice: null,
            createdAt: new \DateTimeImmutable('-10 minutes'),
            updatedAt: new \DateTimeImmutable('now'),
            raw: [
                'source' => 'test_command',
            ]
        );

        $io->writeln("Dispatching OrderStateChangedEvent pour $symbol...");
        $io->writeln("Transition: NEW → CANCELLED (sans fill = expired)");
        $this->eventDispatcher->dispatch(new OrderStateChangedEvent(
            order: $order,
            previousStatus: 'NEW',
            newStatus: 'CANCELLED',
            runId: 'test-run-' . time(),
            exchange: 'BITMART',
            accountId: 'test-account',
            extra: ['test' => true]
        ));

        $io->success("OrderStateChangedEvent dispatché !");
        $io->note("Le listener devrait détecter que c'est un ordre expiré (CANCELLED sans fill) et appeler logOrderExpired()");
    }

    private function testSymbolSkipped(SymfonyStyle $io, string $symbol): void
    {
        $io->section('Test SymbolSkippedEvent');

        $io->writeln("Dispatching SymbolSkippedEvent pour $symbol...");
        $this->eventDispatcher->dispatch(new SymbolSkippedEvent(
            symbol: $symbol,
            reasonCode: 'mtf_conditions_not_met',
            runId: 'test-run-' . time(),
            timeframe: '5m',
            configProfile: 'scalper',
            configVersion: '1.0',
            extra: [
                'test' => true,
                'failed_checks' => ['rsi_too_high', 'volume_too_low'],
            ]
        ));

        $io->success("SymbolSkippedEvent dispatché !");
    }

    private function testSyncAll(SymfonyStyle $io, ?string $symbol): void
    {
        $io->section('Test Synchronisation complète');

        if ($this->syncRunner === null) {
            $io->error('TradingStateSyncRunner non disponible');
            return;
        }

        $symbols = $symbol !== null ? [$symbol] : null;

        $io->writeln("Lancement de la synchronisation...");
        $io->writeln($symbols !== null ? "Symboles: " . implode(', ', $symbols) : "Tous les symboles");

        try {
            $this->syncRunner->syncAndDispatch('test_command', $symbols);
            $io->success("Synchronisation terminée !");
            $io->note("Les événements PositionOpenedEvent, PositionClosedEvent et OrderStateChangedEvent ont été dispatchés automatiquement si des changements ont été détectés.");
        } catch (\Throwable $e) {
            $io->warning("Synchronisation échouée: " . $e->getMessage());
            throw $e;
        }
    }
}


