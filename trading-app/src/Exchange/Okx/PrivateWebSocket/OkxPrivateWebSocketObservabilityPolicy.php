<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use DateTimeImmutable;
use DateTimeInterface;

final readonly class OkxPrivateWebSocketObservabilityPolicy
{
    private const MAX_AGE_MICROSECONDS = 10_000_000;

    public function evaluate(
        ?OkxPrivateWebSocketObservabilityStatus $status,
        DateTimeImmutable $now,
    ): ExchangePrivateObservabilityStatus {
        if (null === $status) {
            return ExchangePrivateObservabilityStatus::absent(Exchange::OKX, 'demo');
        }

        $blockingErrors = $status->blockingErrors;
        $freshnessValid = true;

        $observedAtAge = $this->microseconds($now) - $this->microseconds($status->observedAt);
        if ($observedAtAge < 0) {
            $blockingErrors[] = 'okx_private_observability_timestamp_future';
            $freshnessValid = false;
        } elseif ($observedAtAge > self::MAX_AGE_MICROSECONDS) {
            $blockingErrors[] = 'okx_private_observability_observed_at_stale';
            $freshnessValid = false;
        }

        $heartbeatAge = $this->microseconds($now) - $this->microseconds($status->lastHeartbeatAt);
        if ($heartbeatAge < 0) {
            $blockingErrors[] = 'okx_private_observability_timestamp_future';
            $freshnessValid = false;
        } elseif ($heartbeatAge > self::MAX_AGE_MICROSECONDS) {
            $blockingErrors[] = 'okx_private_observability_heartbeat_stale';
            $freshnessValid = false;
        }

        return new ExchangePrivateObservabilityStatus(
            exchange: Exchange::OKX,
            environment: 'demo',
            privateWsSupported: true,
            privateWsConnected: $status->connected,
            privateWsAuthenticated: $status->authenticated,
            ordersStreamReady: $status->ordersStreamReady,
            fillsStreamReady: $status->fillsStreamReady,
            positionsStreamReady: $status->positionsStreamReady,
            initialSnapshotLoaded: $status->initialSnapshotLoaded,
            lastEventAt: $status->lastEventAt,
            reconnecting: $status->reconnecting,
            reconciliationFresh: $freshnessValid && $status->reconciliationFresh,
            blockingErrors: array_values(array_unique($blockingErrors)),
            warnings: $status->warnings,
        );
    }

    private function microseconds(DateTimeInterface $timestamp): int
    {
        return ((int) $timestamp->format('U') * 1_000_000) + (int) $timestamp->format('u');
    }
}
