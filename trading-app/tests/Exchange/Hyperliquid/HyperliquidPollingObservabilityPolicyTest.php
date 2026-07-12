<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Exchange\Hyperliquid\HyperliquidPollingObservabilityPolicy;
use App\Exchange\Hyperliquid\HyperliquidPollingObservabilityStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPollingObservabilityPolicy::class)]
#[CoversClass(HyperliquidPollingObservabilityStatus::class)]
final class HyperliquidPollingObservabilityPolicyTest extends TestCase
{
    #[DataProvider('blockingStatuses')]
    public function testRejectsEveryMissingOrSpoofedPollingEvidence(
        HyperliquidPollingObservabilityStatus $status,
        string $reason,
    ): void {
        self::assertSame([$reason], $this->policy()->blockingReasons($status));
    }

    /** @return iterable<string, array{HyperliquidPollingObservabilityStatus, string}> */
    public static function blockingStatuses(): iterable
    {
        yield 'exchange' => [self::pollingStatus(exchange: Exchange::OKX), 'hyperliquid_poll_exchange_required'];
        yield 'environment' => [self::pollingStatus(environment: 'TESTNET'), 'hyperliquid_poll_testnet_environment_required'];
        yield 'endpoint spoof' => [self::pollingStatus(endpoint: 'https://api.hyperliquid-testnet.xyz.attacker.invalid'), 'hyperliquid_poll_testnet_endpoint_required'];
        yield 'initial snapshot' => [self::pollingStatus(initialSnapshotLoaded: false), 'hyperliquid_poll_initial_snapshot_not_loaded'];
        yield 'orders' => [self::pollingStatus(ordersReady: false), 'hyperliquid_orders_poll_not_ready'];
        yield 'fills' => [self::pollingStatus(fillsReady: false), 'hyperliquid_fills_poll_not_ready'];
        yield 'positions' => [self::pollingStatus(positionsReady: false), 'hyperliquid_positions_poll_not_ready'];
        yield 'reconciliation' => [self::pollingStatus(reconciliationInFlight: true), 'hyperliquid_reconciliation_in_flight'];
        yield 'future timestamp' => [self::pollingStatus(observedAt: '2026-07-12T12:00:00.001Z'), 'hyperliquid_poll_observed_at_in_future'];
        yield 'stale timestamp' => [self::pollingStatus(observedAt: '2026-07-12T11:59:57.999Z'), 'hyperliquid_poll_snapshot_stale'];
    }

    #[DataProvider('acceptedAges')]
    public function testAcceptsSnapshotAtOrInsideTwoSecondBoundary(string $observedAt): void
    {
        self::assertSame([], $this->policy()->blockingReasons(self::pollingStatus(observedAt: $observedAt)));
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedAges(): iterable
    {
        yield '1999 milliseconds' => ['2026-07-12T11:59:58.001Z'];
        yield '2000 milliseconds' => ['2026-07-12T11:59:58.000Z'];
    }

    public function testReasonsAreOrderedAndDeduplicated(): void
    {
        $status = self::pollingStatus(
            exchange: Exchange::OKX,
            environment: 'mainnet',
            endpoint: 'https://api.hyperliquid.xyz',
            initialSnapshotLoaded: false,
            ordersReady: false,
            fillsReady: false,
            positionsReady: false,
            reconciliationInFlight: true,
            observedAt: '2026-07-12T11:59:57.999Z',
        );

        self::assertSame([
            'hyperliquid_poll_exchange_required',
            'hyperliquid_poll_testnet_environment_required',
            'hyperliquid_poll_testnet_endpoint_required',
            'hyperliquid_poll_initial_snapshot_not_loaded',
            'hyperliquid_orders_poll_not_ready',
            'hyperliquid_fills_poll_not_ready',
            'hyperliquid_positions_poll_not_ready',
            'hyperliquid_reconciliation_in_flight',
            'hyperliquid_poll_snapshot_stale',
        ], $this->policy()->blockingReasons($status));
    }

    private function policy(): HyperliquidPollingObservabilityPolicy
    {
        return new HyperliquidPollingObservabilityPolicy(new MockClock('2026-07-12T12:00:00.000Z'));
    }

    private static function pollingStatus(
        Exchange $exchange = Exchange::HYPERLIQUID,
        string $environment = 'testnet',
        string $endpoint = 'https://api.hyperliquid-testnet.xyz',
        bool $initialSnapshotLoaded = true,
        bool $ordersReady = true,
        bool $fillsReady = true,
        bool $positionsReady = true,
        bool $reconciliationInFlight = false,
        string $observedAt = '2026-07-12T11:59:59.000Z',
    ): HyperliquidPollingObservabilityStatus {
        return new HyperliquidPollingObservabilityStatus(
            exchange: $exchange,
            environment: $environment,
            endpoint: $endpoint,
            initialSnapshotLoaded: $initialSnapshotLoaded,
            ordersReady: $ordersReady,
            fillsReady: $fillsReady,
            positionsReady: $positionsReady,
            reconciliationInFlight: $reconciliationInFlight,
            observedAt: new \DateTimeImmutable($observedAt),
        );
    }
}
