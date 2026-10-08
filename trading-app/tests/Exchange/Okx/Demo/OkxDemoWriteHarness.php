<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx\Demo;

use App\Exchange\Okx\Demo\OkxDemoWriteGate;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityPolicy;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityStatus;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketStatusStoreInterface;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use App\TradingCore\Execution\Safety\DemoTradingAuditSinkInterface;
use App\TradingCore\Execution\Safety\DemoTradingKillSwitchService;
use App\TradingCore\Execution\Safety\DemoTradingSafetyPolicyEvaluator;
use Psr\Clock\ClockInterface;

final class OkxDemoWriteHarness
{
    public const SECRET = 'super-secret-value-123';

    /** @var list<array<string,mixed>> */
    public array $events = [];

    public bool $failAudit = false;

    public bool $healthyPrivateStream = true;

    public function __construct(
        public readonly OkxConfig $config,
        public readonly bool $killSwitchGlobal = true,
        public readonly bool $killSwitchOkx = true,
        public readonly float $maxNotional = 1000.0,
    ) {
    }

    public static function config(
        string $environment = 'demo',
        bool $simulated = true,
        bool $demoEnabled = true,
        bool $globalEnabled = true,
        bool $live = false,
        string $apiBaseUri = '',
    ): OkxConfig {
        return new OkxConfig(
            environment: $environment,
            apiKey: 'test-key-' . self::SECRET,
            apiSecret: 'test-secret-' . self::SECRET,
            apiPassphrase: 'test-pass-' . self::SECRET,
            apiBaseUri: $apiBaseUri,
            simulatedTrading: $simulated,
            demoTradingEnabled: $demoEnabled,
            liveEnabled: $live,
            globalDemoTradingEnabled: $globalEnabled,
        );
    }

    public function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00', new \DateTimeZone('UTC'));
            }
        };
    }

    public function sink(): DemoTradingAuditSinkInterface
    {
        $harness = $this;

        return new class($harness) implements DemoTradingAuditSinkInterface {
            public function __construct(private readonly OkxDemoWriteHarness $harness)
            {
            }

            public function recordDemoTradingAttempt(array $event): void
            {
                if ($this->harness->failAudit) {
                    throw new \RuntimeException('sink down');
                }
                $this->harness->events[] = $event;
            }
        };
    }

    public function gate(): OkxDemoWriteGate
    {
        $harness = $this;
        $store = new class($harness, $this->clock()) implements OkxPrivateWebSocketStatusStoreInterface {
            public function __construct(private readonly OkxDemoWriteHarness $harness, private readonly ClockInterface $clock)
            {
            }

            public function save(OkxPrivateWebSocketObservabilityStatus $status): void
            {
            }

            public function load(): ?OkxPrivateWebSocketObservabilityStatus
            {
                if (!$this->harness->healthyPrivateStream) {
                    return null;
                }
                $now = $this->clock->now();

                return new OkxPrivateWebSocketObservabilityStatus(
                    connected: true,
                    authenticated: true,
                    ordersStreamReady: true,
                    fillsStreamReady: true,
                    fillsSource: 'fills_channel',
                    positionsStreamReady: true,
                    initialSnapshotLoaded: true,
                    reconciliationFresh: true,
                    reconnecting: false,
                    connectedAt: $now,
                    lastHeartbeatAt: $now,
                    lastEventAt: $now,
                    observedAt: $now,
                    blockingErrors: [],
                    warnings: [],
                );
            }

            public function clear(): void
            {
            }
        };

        return new OkxDemoWriteGate(
            $this->config,
            new DemoTradingKillSwitchService(
                new DemoTradingSafetyPolicyEvaluator(),
                new ExchangePrivateObservabilityPolicy(),
                $this->sink(),
                $this->killSwitchGlobal,
                $this->killSwitchOkx,
            ),
            new OkxPrivateWebSocketObservabilityPolicy(),
            $this->clock(),
            $this->maxNotional,
            $store,
        );
    }
}
