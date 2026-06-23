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
                'internal_position_id' => 'ipd-logger-1',
                'correlation_run_id' => 'corr-run',
                'orchestration_run_id' => 'orch-run',
                'orchestration_set_id' => 'set-1',
                'orchestration_dashboard_id' => 'dash-1',
                'origin' => 'replay',
                'replay_of_run_id' => 'source-run',
                'replay_of_correlation_id' => 'source-corr',
                'attempt_number' => 2,
                'config_hash' => 'cfg-logger',
            ],
            marketType: 'perpetual',
        );

        /** @var TradeLifecycleEvent $event */
        $event = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'clientOrderId' => 'CID-1',
        ]);

        self::assertNotNull($event);
        self::assertSame('itd-logger-1', $event->getInternalTradeId());
        self::assertSame('ipd-logger-1', $event->getInternalPositionId());
        self::assertSame('corr-run', $event->getCorrelationRunId());
        self::assertSame('orch-run', $event->getOrchestrationRunId());
        self::assertSame('set-1', $event->getOrchestrationSetId());
        self::assertSame('dash-1', $event->getOrchestrationDashboardId());
        self::assertSame('replay', $event->getOrigin());
        self::assertSame('source-run', $event->getReplayOfRunId());
        self::assertSame('source-corr', $event->getReplayOfCorrelationId());
        self::assertSame(2, $event->getAttemptNumber());
        self::assertSame('cfg-logger', $event->getConfigHash());
        self::assertSame('itd-logger-1', $event->getExtra()['internal_trade_id'] ?? null);
    }

    public function testLineageColumnsAreTruncatedBeforePersistence(): void
    {
        $logger = new TradeLifecycleLogger($this->em, $this->fixedClock());
        $long96 = str_repeat('r', 140);
        $long255 = str_repeat('o', 240);
        $long128 = str_repeat('c', 160);

        $logger->logOrderSubmitted(
            symbol: 'ETHUSDT',
            orderId: 'ex-2',
            clientOrderId: 'cid-2',
            side: 'BUY',
            qty: '1',
            price: '100',
            runId: 'run-2',
            exchange: 'bitmart',
            extra: [
                'internal_trade_id' => str_repeat('i', 140),
                'internal_position_id' => str_repeat('p', 140),
                'correlation_run_id' => $long96,
                'orchestration_run_id' => $long255,
                'orchestration_set_id' => $long96,
                'orchestration_dashboard_id' => $long96,
                'origin' => str_repeat('o', 40),
                'replay_of_run_id' => $long255,
                'replay_of_correlation_id' => $long96,
                'config_hash' => $long128,
            ],
            marketType: 'perpetual',
        );

        /** @var TradeLifecycleEvent $event */
        $event = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'clientOrderId' => 'CID-2',
        ]);

        self::assertNotNull($event);
        self::assertSame(96, strlen($event->getInternalTradeId() ?? ''));
        self::assertSame(96, strlen($event->getInternalPositionId() ?? ''));
        self::assertSame(96, strlen($event->getCorrelationRunId() ?? ''));
        self::assertSame($long255, $event->getOrchestrationRunId());
        self::assertSame(96, strlen($event->getOrchestrationSetId() ?? ''));
        self::assertSame(96, strlen($event->getOrchestrationDashboardId() ?? ''));
        self::assertSame(24, strlen($event->getOrigin()));
        self::assertSame($long255, $event->getReplayOfRunId());
        self::assertSame(96, strlen($event->getReplayOfCorrelationId() ?? ''));
        self::assertSame(128, strlen($event->getConfigHash() ?? ''));
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
