<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Entity\TradeLifecycleEvent;
use App\Logging\TradeLifecycleLogger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(TradeLifecycleLogger::class)]
final class TradeLifecycleLoggerLineageTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');

        $metadata = [$this->em->getClassMetadata(TradeLifecycleEvent::class)];
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            (new SchemaTool($this->em))->dropSchema([$this->em->getClassMetadata(TradeLifecycleEvent::class)]);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testOrderSubmittedPersistsInternalTradeIdColumn(): void
    {
        $logger = new TradeLifecycleLogger($this->em, $this->fixedClock());

        $logger->logOrderSubmitted(
            symbol: 'BTCUSDT',
            orderId: 'ex-1',
            clientOrderId: 'cid-1',
            side: 'BUY',
            qty: '1',
            price: '100',
            runId: 'run-1',
            exchange: 'bitmart',
            extra: [
                'internal_trade_id' => 'itd-logger-1',
                'trade_id' => 'itd-logger-1',
            ],
            marketType: 'perpetual',
        );

        /** @var TradeLifecycleEvent $event */
        $event = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'clientOrderId' => 'CID-1',
        ]);

        self::assertNotNull($event);
        self::assertSame('itd-logger-1', $event->getInternalTradeId());
        self::assertSame('itd-logger-1', $event->getExtra()['internal_trade_id'] ?? null);
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-06-23 10:00:00 UTC');
            }
        };
    }
}
