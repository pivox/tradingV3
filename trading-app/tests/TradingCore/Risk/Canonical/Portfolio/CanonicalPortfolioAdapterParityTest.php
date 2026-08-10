<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterInterface;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\RuntimeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioFill;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Risk\Canonical\Portfolio\InMemoryCanonicalPortfolioReservationStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(RuntimeCanonicalPortfolioAdapter::class)]
#[CoversClass(FakeCanonicalPortfolioAdapter::class)]
#[CoversClass(PaperCanonicalPortfolioAdapter::class)]
#[CoversClass(BacktestCanonicalPortfolioAdapter::class)]
final class CanonicalPortfolioAdapterParityTest extends TestCase
{
    public function testRuntimeFakePaperAndBacktestProduceByteStableAdmissionAndFillStates(): void
    {
        $request = $this->request();
        $admissionHashes = [];
        $fillStateHashes = [];
        foreach ($this->adapters() as $adapter) {
            $decision = $adapter->admit($request);
            $reservation = $adapter->reserve($request);
            $fill = new CanonicalPortfolioFill(
                $reservation->scope,
                $reservation->decisionKey,
                $reservation->planHash,
                $reservation->admissionHash,
                'fill-1',
                1.0,
                $request->plan->entryPrice,
                $request->plan->entryFee / $request->plan->quantity,
                1.0,
                round($request->plan->quantity - 1.0, 3),
                new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
                'sha256:' . str_repeat('9', 64),
            );
            $filled = $adapter->applyFill($reservation, $fill);
            $admissionHashes[] = $decision->reservationHash;
            $fillStateHashes[] = $filled->stateHash;
        }

        self::assertCount(1, array_unique($admissionHashes));
        self::assertCount(1, array_unique($fillStateHashes));
    }

    public function testAllAdaptersReturnTheSameFailClosedReason(): void
    {
        $request = $this->request(openPositions: 4);
        $reasonCodes = [];
        foreach ($this->adapters() as $adapter) {
            try {
                $adapter->admit($request);
                self::fail('Over-concurrent portfolio was accepted.');
            } catch (CanonicalPortfolioException $exception) {
                $reasonCodes[] = $exception->reasonCode;
            }
        }

        self::assertSame(['canonical_portfolio_concurrency_exceeded'], array_values(array_unique($reasonCodes)));
    }

    public function testAllAdaptersPersistCancelAndCloseWithIdenticalStates(): void
    {
        $request = $this->request();
        $cancelHashes = [];
        $closeHashes = [];
        foreach ($this->adapters() as $adapter) {
            $adapter->admit($request);
            $reservation = $adapter->reserve($request);
            $cancelled = $adapter->cancelResidual(
                $reservation,
                new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
                'sha256:' . str_repeat('a', 64),
            );
            self::assertSame($cancelled, $adapter->cancelResidual(
                $cancelled,
                new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
                'sha256:' . str_repeat('a', 64),
            ));
            $closed = $adapter->close(
                $cancelled,
                new \DateTimeImmutable('2026-08-10T12:00:02+00:00'),
                'sha256:' . str_repeat('b', 64),
            );
            self::assertSame($closed, $adapter->close(
                $closed,
                new \DateTimeImmutable('2026-08-10T12:00:02+00:00'),
                'sha256:' . str_repeat('b', 64),
            ));
            $cancelHashes[] = $cancelled->stateHash;
            $closeHashes[] = $closed->stateHash;
        }

        self::assertCount(1, array_unique($cancelHashes));
        self::assertCount(1, array_unique($closeHashes));
    }

    public function testThinAdaptersCannotImportLegacyOrPrivateExecutionLayers(): void
    {
        foreach ([
            RuntimeCanonicalPortfolioAdapter::class,
            FakeCanonicalPortfolioAdapter::class,
            PaperCanonicalPortfolioAdapter::class,
            BacktestCanonicalPortfolioAdapter::class,
        ] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $source = file_get_contents($file);
            self::assertIsString($source);
            foreach (['TradeEntryConfig', 'ExecutionBox', 'ExecutionPort', 'Doctrine', 'Messenger', 'Provider'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source);
            }
        }
    }

    /** @return list<CanonicalPortfolioAdapterInterface> */
    private function adapters(): array
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return [
            new RuntimeCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), new InMemoryCanonicalPortfolioReservationStore()),
            new FakeCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), new InMemoryCanonicalPortfolioReservationStore()),
            new PaperCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), new InMemoryCanonicalPortfolioReservationStore()),
            new BacktestCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), new InMemoryCanonicalPortfolioReservationStore()),
        ];
    }

    private function request(int $openPositions = 0): CanonicalPortfolioAdmissionRequest
    {
        $policy = CanonicalPortfolioFixture::policy();
        $plan = CanonicalPortfolioFixture::plan();
        $scope = new CanonicalPortfolioScope('paper-mainnet', 'fake', 'test', 'account-1', 'day_trading', 'USDT');
        $snapshot = new CanonicalPortfolioSnapshot(
            $scope,
            'golden_fixture',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            0.0,
            0.0,
            $openPositions,
            0,
            0.0,
            0.0,
            0.0,
            [],
            1,
            'sha256:' . str_repeat('8', 64),
        );

        return new CanonicalPortfolioAdmissionRequest($policy, $plan, $scope, $snapshot, 'decision-1');
    }
}
