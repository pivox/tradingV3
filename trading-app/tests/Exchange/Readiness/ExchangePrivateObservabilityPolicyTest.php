<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Readiness;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangePrivateObservabilityDecision;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangePrivateObservabilityDecision::class)]
#[CoversClass(ExchangePrivateObservabilityPolicy::class)]
#[CoversClass(ExchangePrivateObservabilityStatus::class)]
final class ExchangePrivateObservabilityPolicyTest extends TestCase
{
    public function testDryRunIsAllowedWithoutPrivateWebSocketButStatusRemainsAuditable(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            ExchangePrivateObservabilityStatus::absent(Exchange::OKX, 'demo'),
            dryRun: true,
        );

        self::assertTrue($decision->allowed);
        self::assertSame([], $decision->blockingErrors);
        self::assertContains('private_observability_absent_for_dry_run', $decision->warnings);
        self::assertSame('okx', $decision->toArray()['status']['exchange']);
        self::assertFalse($decision->toArray()['status']['private_ws_connected']);
    }

    public function testMutativeDemoTestnetIsBlockedWhenPrivateWebSocketIsNotConnected(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            $this->readyStatus(privateWsConnected: false),
            dryRun: false,
        );

        self::assertFalse($decision->allowed);
        self::assertContains('private_ws_not_connected', $decision->blockingErrors);
    }

    public function testMutativeDemoTestnetIsBlockedWhenPrivateWebSocketIsNotAuthenticated(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            $this->readyStatus(privateWsAuthenticated: false),
            dryRun: false,
        );

        self::assertFalse($decision->allowed);
        self::assertContains('private_ws_not_authenticated', $decision->blockingErrors);
    }

    public function testMutativeDemoTestnetIsBlockedWhenInitialSnapshotIsAbsent(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            $this->readyStatus(initialSnapshotLoaded: false),
            dryRun: false,
        );

        self::assertFalse($decision->allowed);
        self::assertContains('private_observability_initial_snapshot_missing', $decision->blockingErrors);
    }

    public function testMutativeDemoTestnetIsBlockedWhileReconnecting(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            $this->readyStatus(reconnecting: true),
            dryRun: false,
        );

        self::assertFalse($decision->allowed);
        self::assertContains('private_observability_reconnecting', $decision->blockingErrors);
    }

    public function testMutativeDemoTestnetIsBlockedWhenReconciliationIsStale(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            $this->readyStatus(reconciliationFresh: false),
            dryRun: false,
        );

        self::assertFalse($decision->allowed);
        self::assertContains('private_reconciliation_stale', $decision->blockingErrors);
    }

    public function testMutativeDemoTestnetIsBlockedWhenFillsOrPositionsAreNotObservable(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            $this->readyStatus(fillsStreamReady: false, positionsStreamReady: false),
            dryRun: false,
        );

        self::assertFalse($decision->allowed);
        self::assertContains('private_fills_stream_not_ready', $decision->blockingErrors);
        self::assertContains('private_positions_stream_not_ready', $decision->blockingErrors);
    }

    public function testMutativeDemoTestnetIsAllowedWhenPrivateObservabilityIsComplete(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            $this->readyStatus(),
            dryRun: false,
        );

        self::assertTrue($decision->allowed);
        self::assertSame([], $decision->blockingErrors);
        self::assertTrue($decision->toArray()['status']['private_ws_supported']);
        self::assertSame('2026-06-29T10:15:00+00:00', $decision->toArray()['status']['last_event_at']);
    }

    public function testStatusOutputRedactsSensitiveErrorsAndWarnings(): void
    {
        $status = new ExchangePrivateObservabilityStatus(
            exchange: Exchange::OKX,
            environment: 'demo',
            privateWsSupported: true,
            privateWsConnected: true,
            privateWsAuthenticated: true,
            ordersStreamReady: true,
            fillsStreamReady: true,
            positionsStreamReady: true,
            initialSnapshotLoaded: true,
            lastEventAt: new \DateTimeImmutable('2026-06-29T10:15:00+00:00'),
            reconnecting: false,
            reconciliationFresh: true,
            blockingErrors: ['private_key=wallet-secret'],
            warnings: ['safe_warning', 'OK-ACCESS-SIGN=raw-signature'],
        );

        $encoded = json_encode($status->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('wallet-secret', $encoded);
        self::assertStringNotContainsString('raw-signature', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
        self::assertStringContainsString('safe_warning', $encoded);
    }

    public function testDecisionOutputRedactsSensitiveCollectorErrors(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            new ExchangePrivateObservabilityStatus(
                exchange: Exchange::OKX,
                environment: 'demo',
                privateWsSupported: true,
                privateWsConnected: true,
                privateWsAuthenticated: true,
                ordersStreamReady: true,
                fillsStreamReady: true,
                positionsStreamReady: true,
                initialSnapshotLoaded: true,
                lastEventAt: new \DateTimeImmutable('2026-06-29T10:15:00+00:00'),
                reconnecting: false,
                reconciliationFresh: false,
                blockingErrors: ['api_key=demo-key'],
            ),
            dryRun: false,
        );

        $encoded = json_encode($decision->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('demo-key', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
        self::assertContains('private_reconciliation_stale', $decision->blockingErrors);
    }

    public function testDecisionOutputRedactsSensitiveCollectorWarnings(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            new ExchangePrivateObservabilityStatus(
                exchange: Exchange::OKX,
                environment: 'demo',
                privateWsSupported: true,
                privateWsConnected: true,
                privateWsAuthenticated: true,
                ordersStreamReady: true,
                fillsStreamReady: true,
                positionsStreamReady: true,
                initialSnapshotLoaded: true,
                lastEventAt: new \DateTimeImmutable('2026-06-29T10:15:00+00:00'),
                reconnecting: false,
                reconciliationFresh: true,
                warnings: ['safe_warning', 'api_key=demo-key'],
            ),
            dryRun: false,
        );

        $encoded = json_encode($decision->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('demo-key', $encoded);
        self::assertSame(['safe_warning', '[redacted]'], $decision->warnings);
    }

    public function testMutativeDemoTestnetIsBlockedWhenStatusDoesNotMatchTarget(): void
    {
        $decision = (new ExchangePrivateObservabilityPolicy())->evaluate(
            new ExchangePrivateObservabilityStatus(
                exchange: Exchange::HYPERLIQUID,
                environment: 'testnet',
                privateWsSupported: true,
                privateWsConnected: true,
                privateWsAuthenticated: true,
                ordersStreamReady: true,
                fillsStreamReady: true,
                positionsStreamReady: true,
                initialSnapshotLoaded: true,
                lastEventAt: new \DateTimeImmutable('2026-06-29T10:15:00+00:00'),
                reconnecting: false,
                reconciliationFresh: true,
            ),
            dryRun: false,
            expectedExchange: Exchange::OKX,
            expectedEnvironment: 'demo',
        );

        self::assertFalse($decision->allowed);
        self::assertContains('private_observability_exchange_mismatch', $decision->blockingErrors);
        self::assertContains('private_observability_environment_mismatch', $decision->blockingErrors);
    }

    private function readyStatus(
        bool $privateWsSupported = true,
        bool $privateWsConnected = true,
        bool $privateWsAuthenticated = true,
        bool $ordersStreamReady = true,
        bool $fillsStreamReady = true,
        bool $positionsStreamReady = true,
        bool $initialSnapshotLoaded = true,
        bool $reconnecting = false,
        bool $reconciliationFresh = true,
    ): ExchangePrivateObservabilityStatus {
        return new ExchangePrivateObservabilityStatus(
            exchange: Exchange::OKX,
            environment: 'demo',
            privateWsSupported: $privateWsSupported,
            privateWsConnected: $privateWsConnected,
            privateWsAuthenticated: $privateWsAuthenticated,
            ordersStreamReady: $ordersStreamReady,
            fillsStreamReady: $fillsStreamReady,
            positionsStreamReady: $positionsStreamReady,
            initialSnapshotLoaded: $initialSnapshotLoaded,
            lastEventAt: new \DateTimeImmutable('2026-06-29T10:15:00+00:00'),
            reconnecting: $reconnecting,
            reconciliationFresh: $reconciliationFresh,
        );
    }
}
