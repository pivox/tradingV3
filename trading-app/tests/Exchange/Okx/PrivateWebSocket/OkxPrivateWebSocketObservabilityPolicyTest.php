<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\PrivateWebSocket;

use App\Common\Enum\Exchange;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityPolicy;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityStatus;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPrivateWebSocketObservabilityPolicy::class)]
final class OkxPrivateWebSocketObservabilityPolicyTest extends TestCase
{
    private const string NOW = '2026-07-13T10:00:10.000000Z';

    #[DataProvider('healthyFillsSources')]
    public function testFreshCompleteStatusIsAllowedByCommonPolicy(string $fillsSource): void
    {
        $status = self::evaluate(self::healthyStatus(fillsSource: $fillsSource));
        $decision = self::commonDecision($status);

        self::assertTrue($decision->allowed);
        self::assertSame([], $decision->blockingErrors);
        self::assertSame(Exchange::OKX, $status->exchange);
        self::assertSame('demo', $status->environment);
        self::assertTrue($status->privateWsSupported);
        self::assertTrue($status->privateWsConnected);
        self::assertTrue($status->privateWsAuthenticated);
        self::assertTrue($status->ordersStreamReady);
        self::assertTrue($status->fillsStreamReady);
        self::assertTrue($status->positionsStreamReady);
        self::assertTrue($status->initialSnapshotLoaded);
        self::assertTrue($status->reconciliationFresh);
        self::assertFalse($status->reconnecting);
        self::assertSame('2026-07-13T10:00:08+00:00', $status->lastEventAt?->format(DATE_ATOM));
    }

    /** @return iterable<string, array{string}> */
    public static function healthyFillsSources(): iterable
    {
        yield 'fills channel' => ['fills_channel'];
        yield 'orders plus REST' => ['orders_plus_rest'];
    }

    public function testNullStatusMapsToAbsentAndIsBlockedByCommonPolicy(): void
    {
        $status = self::evaluate(null);
        $decision = self::commonDecision($status);

        self::assertEquals(ExchangePrivateObservabilityStatus::absent(Exchange::OKX, 'demo'), $status);
        self::assertFalse($decision->allowed);
        self::assertContains('private_observability_status_missing', $decision->blockingErrors);
    }

    public function testExactlyTenSecondsOldTimestampsAreAccepted(): void
    {
        $status = self::evaluate(self::healthyStatus(
            observedAt: '2026-07-13T10:00:00.000000Z',
            lastHeartbeatAt: '2026-07-13T10:00:00.000000Z',
        ));

        self::assertTrue(self::commonDecision($status)->allowed);
        self::assertSame([], $status->blockingErrors);
        self::assertTrue($status->reconciliationFresh);
    }

    #[DataProvider('invalidFreshnessStatuses')]
    public function testInvalidFreshnessBlocksCommonPolicy(
        OkxPrivateWebSocketObservabilityStatus $rawStatus,
        string $expectedError,
    ): void {
        $status = self::evaluate($rawStatus);
        $decision = self::commonDecision($status);

        self::assertFalse($decision->allowed);
        self::assertContains($expectedError, $status->blockingErrors);
        self::assertContains($expectedError, $decision->blockingErrors);
        self::assertFalse($status->reconciliationFresh);
    }

    /** @return iterable<string, array{OkxPrivateWebSocketObservabilityStatus, string}> */
    public static function invalidFreshnessStatuses(): iterable
    {
        yield 'observed stale by one microsecond' => [
            self::healthyStatus(observedAt: '2026-07-13T09:59:59.999999Z'),
            'okx_private_observability_observed_at_stale',
        ];
        yield 'heartbeat stale by one microsecond' => [
            self::healthyStatus(lastHeartbeatAt: '2026-07-13T09:59:59.999999Z'),
            'okx_private_observability_heartbeat_stale',
        ];
        yield 'observed in future by one microsecond' => [
            self::healthyStatus(observedAt: '2026-07-13T10:00:10.000001Z'),
            'okx_private_observability_timestamp_future',
        ];
        yield 'heartbeat in future by one microsecond' => [
            self::healthyStatus(lastHeartbeatAt: '2026-07-13T10:00:10.000001Z'),
            'okx_private_observability_timestamp_future',
        ];
    }

    #[DataProvider('unreadyStatuses')]
    public function testRawReadinessFailuresAreMappedAndBlockedByCommonPolicy(
        OkxPrivateWebSocketObservabilityStatus $rawStatus,
        string $expectedCommonError,
    ): void {
        $status = self::evaluate($rawStatus);
        $decision = self::commonDecision($status);

        self::assertFalse($decision->allowed);
        self::assertContains($expectedCommonError, $decision->blockingErrors);
    }

    /** @return iterable<string, array{OkxPrivateWebSocketObservabilityStatus, string}> */
    public static function unreadyStatuses(): iterable
    {
        yield 'disconnected' => [self::healthyStatus(connected: false), 'private_ws_not_connected'];
        yield 'unauthenticated' => [self::healthyStatus(authenticated: false), 'private_ws_not_authenticated'];
        yield 'orders stream' => [self::healthyStatus(ordersStreamReady: false), 'private_orders_stream_not_ready'];
        yield 'fills stream' => [
            self::healthyStatus(fillsStreamReady: false, fillsSource: null),
            'private_fills_stream_not_ready',
        ];
        yield 'positions stream' => [self::healthyStatus(positionsStreamReady: false), 'private_positions_stream_not_ready'];
        yield 'initial snapshot' => [self::healthyStatus(initialSnapshotLoaded: false), 'private_observability_initial_snapshot_missing'];
        yield 'reconciliation' => [self::healthyStatus(reconciliationFresh: false), 'private_reconciliation_stale'];
        yield 'reconnecting' => [self::healthyStatus(reconnecting: true), 'private_observability_reconnecting'];
    }

    public function testRawBlockingErrorsAndWarningsAreMapped(): void
    {
        $status = self::evaluate(self::healthyStatus(
            blockingErrors: ['okx_private_ws_connection_failed'],
            warnings: ['okx_fills_channel_vip_unavailable'],
        ));
        $decision = self::commonDecision($status);

        self::assertSame(['okx_private_ws_connection_failed'], $status->blockingErrors);
        self::assertSame(['okx_fills_channel_vip_unavailable'], $status->warnings);
        self::assertFalse($decision->allowed);
        self::assertSame(['okx_private_ws_connection_failed'], $decision->blockingErrors);
        self::assertSame(['okx_fills_channel_vip_unavailable'], $decision->warnings);
    }

    public function testGeneratedBlockingErrorsAreDeduplicated(): void
    {
        $status = self::evaluate(self::healthyStatus(
            observedAt: '2026-07-13T10:00:10.000001Z',
            lastHeartbeatAt: '2026-07-13T10:00:10.000001Z',
        ));

        self::assertSame(['okx_private_observability_timestamp_future'], $status->blockingErrors);
        self::assertSame(
            1,
            count(array_filter(
                self::commonDecision($status)->blockingErrors,
                static fn (string $error): bool => 'okx_private_observability_timestamp_future' === $error,
            )),
        );
    }

    private static function evaluate(?OkxPrivateWebSocketObservabilityStatus $status): ExchangePrivateObservabilityStatus
    {
        return (new OkxPrivateWebSocketObservabilityPolicy())->evaluate(
            $status,
            new DateTimeImmutable(self::NOW),
        );
    }

    private static function commonDecision(ExchangePrivateObservabilityStatus $status): \App\Exchange\Readiness\ExchangePrivateObservabilityDecision
    {
        return (new ExchangePrivateObservabilityPolicy())->evaluate(
            $status,
            dryRun: false,
            expectedExchange: Exchange::OKX,
            expectedEnvironment: 'demo',
        );
    }

    /**
     * @param list<string> $blockingErrors
     * @param list<string> $warnings
     */
    private static function healthyStatus(
        bool $connected = true,
        bool $authenticated = true,
        bool $ordersStreamReady = true,
        bool $fillsStreamReady = true,
        ?string $fillsSource = 'fills_channel',
        bool $positionsStreamReady = true,
        bool $initialSnapshotLoaded = true,
        bool $reconciliationFresh = true,
        bool $reconnecting = false,
        string $lastHeartbeatAt = '2026-07-13T10:00:09.000000Z',
        ?string $lastEventAt = '2026-07-13T10:00:08.000000Z',
        string $observedAt = '2026-07-13T10:00:09.000000Z',
        array $blockingErrors = [],
        array $warnings = [],
    ): OkxPrivateWebSocketObservabilityStatus {
        return new OkxPrivateWebSocketObservabilityStatus(
            connected: $connected,
            authenticated: $authenticated,
            ordersStreamReady: $ordersStreamReady,
            fillsStreamReady: $fillsStreamReady,
            fillsSource: $fillsSource,
            positionsStreamReady: $positionsStreamReady,
            initialSnapshotLoaded: $initialSnapshotLoaded,
            reconciliationFresh: $reconciliationFresh,
            reconnecting: $reconnecting,
            connectedAt: new DateTimeImmutable('2026-07-13T09:59:00.000000Z'),
            lastHeartbeatAt: new DateTimeImmutable($lastHeartbeatAt),
            lastEventAt: null === $lastEventAt ? null : new DateTimeImmutable($lastEventAt),
            observedAt: new DateTimeImmutable($observedAt),
            blockingErrors: $blockingErrors,
            warnings: $warnings,
        );
    }
}
