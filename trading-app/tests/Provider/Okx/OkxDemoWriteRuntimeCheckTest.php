<?php

declare(strict_types=1);

namespace App\Tests\Provider\Okx;

use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketEndpointGuard;
use App\Provider\Okx\OkxDemoWriteRuntimeCheck;
use App\Tests\Exchange\Okx\Demo\OkxDemoWriteHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxDemoWriteRuntimeCheck::class)]
final class OkxDemoWriteRuntimeCheckTest extends TestCase
{
    public function testReadyWhenEveryFlagCredentialAndGuardPasses(): void
    {
        $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config());

        $report = $this->check($harness)->check();

        self::assertSame([
            'OKX_ENV' => 'demo',
            'OKX_SIMULATED_TRADING' => true,
            'OKX_DEMO_TRADING_ENABLED' => true,
            'DEMO_TRADING_ENABLED' => true,
            'OKX_LIVE_ENABLED' => false,
        ], $report['flags']);
        self::assertSame(
            ['api_key_present' => true, 'api_secret_present' => true, 'api_passphrase_present' => true, 'all_present' => true],
            $report['credentials'],
        );
        self::assertSame(['clear' => true, 'tripped' => false, 'trip_reason' => null, 'reasons' => []], $report['kill_switch']);
        self::assertSame(['rest_allowed' => true, 'ws_private_allowed' => true], $report['endpoint_guard']);
        self::assertTrue($report['write_ready']);
        self::assertSame([], $report['blocking_reasons']);
    }

    public function testOutputNeverContainsCredentialValues(): void
    {
        $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config());

        $json = json_encode($this->check($harness)->check(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(OkxDemoWriteHarness::SECRET, $json);
        self::assertStringNotContainsString('test-key', $json);
    }

    public function testReportsEachBlockingCause(): void
    {
        $harness = new OkxDemoWriteHarness(
            OkxDemoWriteHarness::config(simulated: false, demoEnabled: false, globalEnabled: false, live: true, apiBaseUri: 'https://www.okx.com'),
        );

        $report = $this->check($harness)->check();

        self::assertFalse($report['write_ready']);
        self::assertFalse($report['kill_switch']['clear']);
        self::assertFalse($report['endpoint_guard']['rest_allowed']);
        foreach ([
            'okx_simulated_trading_disabled',
            'okx_demo_trading_disabled',
            'demo_trading_disabled',
            'okx_live_enabled',
            'okx_private_rest_endpoint_not_allowed',
        ] as $reason) {
            self::assertContains($reason, $report['blocking_reasons']);
        }
    }

    public function testMissingCredentialsAndStaleStreamAreReported(): void
    {
        $harness = new OkxDemoWriteHarness(new \App\Exchange\Okx\OkxConfig(
            environment: 'demo',
            simulatedTrading: true,
            demoTradingEnabled: true,
            globalDemoTradingEnabled: true,
        ));
        $harness->healthyPrivateStream = false;

        $report = $this->check($harness)->check();

        self::assertFalse($report['credentials']['all_present']);
        self::assertFalse($report['kill_switch']['clear']);
        self::assertContains('okx_demo_credentials_missing', $report['blocking_reasons']);
        self::assertContains('private_ws_not_connected', $report['blocking_reasons']);
        self::assertFalse($report['write_ready']);
    }

    public function testTrippedMarkerIsReportedAndBlocks(): void
    {
        $harness = new OkxDemoWriteHarness(OkxDemoWriteHarness::config());
        $harness->tripped = true;

        $report = $this->check($harness)->check();

        self::assertTrue($report['kill_switch']['tripped']);
        self::assertFalse($report['kill_switch']['clear']);
        self::assertFalse($report['write_ready']);
        self::assertContains('okx_demo_tripped', $report['blocking_reasons']);
    }

    private function check(OkxDemoWriteHarness $harness): OkxDemoWriteRuntimeCheck
    {
        return new OkxDemoWriteRuntimeCheck($harness->config, $harness->gate(), new OkxPrivateWebSocketEndpointGuard());
    }
}
