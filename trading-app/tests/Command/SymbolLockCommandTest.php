<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SymbolLockListCommand;
use App\Command\SymbolLockReleaseCommand;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\PositionSide;
use App\Entity\FuturesOrder;
use App\Entity\OrderIntent;
use App\Entity\Position;
use App\Entity\SymbolExecutionLock;
use App\Repository\FuturesOrderRepository;
use App\Repository\PositionRepository;
use App\Repository\SymbolExecutionLockRepository;
use App\Service\SymbolExecutionLockManager;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Listener\SymbolExecutionLockReleaseListener;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversNothing]
final class SymbolLockCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SymbolExecutionLockManager $manager;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');

        $metadata = array_map(
            fn (string $class) => $this->em->getClassMetadata($class),
            [
                FuturesOrder::class,
                OrderIntent::class,
                Position::class,
                SymbolExecutionLock::class,
            ],
        );

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        /** @var SymbolExecutionLockRepository $lockRepository */
        $lockRepository = $this->em->getRepository(SymbolExecutionLock::class);
        /** @var PositionRepository $positionRepository */
        $positionRepository = $this->em->getRepository(Position::class);
        /** @var FuturesOrderRepository $orderRepository */
        $orderRepository = $this->em->getRepository(FuturesOrder::class);
        $this->manager = new SymbolExecutionLockManager(
            $lockRepository,
            $positionRepository,
            $orderRepository,
            $this->em,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $metadata = array_map(
                fn (string $class) => $this->em->getClassMetadata($class),
                [
                    FuturesOrder::class,
                    OrderIntent::class,
                    Position::class,
                    SymbolExecutionLock::class,
                ],
            );
            (new SchemaTool($this->em))->dropSchema($metadata);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testListCommandDisplaysActiveLocks(): void
    {
        $intent = $this->persistIntent('BTCUSDT', 'scalper');
        $this->manager->reserveForIntent($intent);
        $this->em->flush();

        $tester = new CommandTester(new SymbolLockListCommand($this->manager));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('bitmart', $tester->getDisplay());
        self::assertStringContainsString('perpetual', $tester->getDisplay());
        self::assertStringContainsString('BTCUSDT', $tester->getDisplay());
        self::assertStringContainsString('scalper', $tester->getDisplay());
    }

    public function testReleaseCommandRefusesOpenPositionWithoutForce(): void
    {
        $intent = $this->persistIntent('BTCUSDT', 'scalper');
        $this->manager->reserveForIntent($intent);
        $this->em->persist((new Position('BTCUSDT', 'LONG'))->setSize('1'));
        $this->em->flush();

        $tester = new CommandTester(new SymbolLockReleaseCommand($this->manager));
        $exitCode = $tester->execute([
            'symbol' => 'BTCUSDT',
            '--exchange' => 'bitmart',
            '--market-type' => 'perpetual',
            '--reason' => 'manual_investigation',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('open position', $tester->getDisplay());
        self::assertStringContainsString('order exists', $tester->getDisplay());
        self::assertStringContainsString('exists', $tester->getDisplay());
    }

    public function testReleaseCommandAllowsForceReleaseWithOpenPosition(): void
    {
        $intent = $this->persistIntent('BTCUSDT', 'scalper');
        $this->manager->reserveForIntent($intent);
        $this->em->persist((new Position('BTCUSDT', 'LONG'))->setSize('1'));
        $this->em->flush();

        $tester = new CommandTester(new SymbolLockReleaseCommand($this->manager));
        $exitCode = $tester->execute([
            'symbol' => 'BTCUSDT',
            '--exchange' => 'bitmart',
            '--market-type' => 'perpetual',
            '--reason' => 'manual_investigation',
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Released lock for bitmart:perpetual:BTCUSDT', $tester->getDisplay());
        self::assertNull($this->em->getRepository(SymbolExecutionLock::class)->findActive('bitmart', 'perpetual', 'BTCUSDT'));
    }

    public function testPositionClosedEventReleasesSymbolLock(): void
    {
        $intent = $this->persistIntent('BTCUSDT', 'scalper');
        $this->manager->reserveForIntent($intent);
        $this->em->flush();

        (new SymbolExecutionLockReleaseListener($this->manager))->__invoke(new PositionClosedEvent(
            positionHistory: $this->closedPosition('BTCUSDT'),
            exchange: 'bitmart',
            extra: ['market_type' => 'perpetual'],
        ));

        self::assertNull($this->em->getRepository(SymbolExecutionLock::class)->findActive('bitmart', 'perpetual', 'BTCUSDT'));
    }

    public function testPositionClosedEventKeepsLockWhileAnotherSideIsOpen(): void
    {
        $intent = $this->persistIntent('BTCUSDT', 'scalper');
        $this->manager->reserveForIntent($intent);
        $this->em->persist((new Position('BTCUSDT', 'SHORT'))->setSize('1'));
        $this->em->flush();

        (new SymbolExecutionLockReleaseListener($this->manager))->__invoke(new PositionClosedEvent(
            positionHistory: $this->closedPosition('BTCUSDT'),
            exchange: 'bitmart',
            extra: ['market_type' => 'perpetual'],
        ));

        self::assertNotNull($this->em->getRepository(SymbolExecutionLock::class)->findActive('bitmart', 'perpetual', 'BTCUSDT'));
    }

    private function persistIntent(string $symbol, string $profile): OrderIntent
    {
        $intent = (new OrderIntent())
            ->setExchange(Exchange::BITMART)
            ->setMarketType(MarketType::PERPETUAL)
            ->setDecisionKey(sprintf('bitmart:perpetual:%s:1m:1764160800:long:%s:v1', $symbol, $profile))
            ->setStrategyProfile($profile)
            ->setStrategyVersion('v1')
            ->setSymbol($symbol)
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setPrice('100')
            ->setSize(1)
            ->setClientOrderId('cid-' . strtolower($symbol) . '-' . $profile)
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setQuantization([])
            ->setStatus(OrderIntent::STATUS_DRAFT);

        $this->em->persist($intent);

        return $intent;
    }

    private function closedPosition(string $symbol): PositionHistoryEntryDto
    {
        return new PositionHistoryEntryDto(
            symbol: $symbol,
            side: PositionSide::LONG,
            size: BigDecimal::of('1'),
            entryPrice: BigDecimal::of('100'),
            exitPrice: BigDecimal::of('101'),
            realizedPnl: BigDecimal::of('1'),
            fees: BigDecimal::of('0.01'),
            openedAt: new \DateTimeImmutable('2026-05-31 10:00:00', new \DateTimeZone('UTC')),
            closedAt: new \DateTimeImmutable('2026-05-31 10:05:00', new \DateTimeZone('UTC')),
        );
    }
}
