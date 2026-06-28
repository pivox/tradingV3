<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Readiness;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Readiness\ExchangeReadinessEvaluator;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Exchange\Readiness\ExchangeRuntimeCheckInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeReadinessEvaluator::class)]
#[CoversClass(ExchangeReadinessInput::class)]
#[CoversClass(ExchangeReadinessLevel::class)]
#[CoversClass(ExchangeReadinessReport::class)]
#[CoversClass(ExchangeRuntimeCheckInterface::class)]
final class ExchangeReadinessEvaluatorTest extends TestCase
{
    public function testReportsNotReadyWhenInstrumentsAreAbsent(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(instrumentsLoaded: false),
        );

        self::assertSame(ExchangeReadinessLevel::NotReady, $report->readyLevel);
        self::assertContains('instruments_not_loaded', $report->blockingErrors);
        self::assertSame('okx', $report->toArray()['exchange']);
        self::assertSame('not_ready', $report->toArray()['ready_level']);
    }

    public function testReportsNotReadyWhenMainnetWriteGuardIsAbsent(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(mainnetWriteGuard: false),
        );

        self::assertSame(ExchangeReadinessLevel::NotReady, $report->readyLevel);
        self::assertContains('mainnet_write_guard_missing', $report->blockingErrors);
    }

    public function testDemoTestnetCandidateIsImpossibleWithoutStopLossCapability(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(stopLossCapability: false),
        );

        self::assertSame(ExchangeReadinessLevel::LocalDryRunReady, $report->readyLevel);
        self::assertContains('stop_loss_capability_required_for_demo_testnet_candidate', $report->warnings);
    }

    public function testDemoTestnetEnabledIsImpossibleWhenKillSwitchIsActive(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(
                dryRun: false,
                demoTestnetWriteEnabled: true,
                killSwitch: true,
            ),
        );

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertContains('kill_switch_enabled_blocks_demo_testnet_enabled', $report->warnings);
    }

    public function testReportsDemoTestnetEnabledOnlyWhenAllGuardsPass(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(
                dryRun: false,
                demoTestnetWriteEnabled: true,
                killSwitch: false,
            ),
        );

        self::assertSame(ExchangeReadinessLevel::DemoTestnetEnabled, $report->readyLevel);
        self::assertSame([], $report->blockingErrors);
        self::assertSame('demo_testnet_enabled', $report->toArray()['ready_level']);
    }

    public function testDryRunTrueNeverReportsDemoTestnetEnabled(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(
                dryRun: true,
                demoTestnetWriteEnabled: true,
                killSwitch: false,
            ),
        );

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
    }

    public function testDemoTestnetEnabledIsRejectedOutsideDemoTestnetEnvironment(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(
                environment: 'mainnet',
                dryRun: false,
                demoTestnetWriteEnabled: true,
                killSwitch: false,
            ),
        );

        self::assertSame(ExchangeReadinessLevel::NotReady, $report->readyLevel);
        self::assertContains('demo_testnet_environment_required', $report->blockingErrors);
    }

    public function testBlankWhitelistEntriesDoNotSatisfyGuardedReadiness(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(
                dryRun: false,
                demoTestnetWriteEnabled: true,
                killSwitch: false,
                allowedSymbols: ['', '   '],
                allowedMarkets: [],
            ),
        );

        self::assertSame(ExchangeReadinessLevel::PrivateReadOnly, $report->readyLevel);
        self::assertContains('local_dry_run_prerequisites_missing', $report->warnings);
        self::assertSame([], $report->allowedSymbols);
        self::assertSame([], $report->toArray()['allowed_symbols']);
    }

    public function testReadinessLevelsNeverExposeMainnetOrLiveReady(): void
    {
        $values = array_map(
            static fn (ExchangeReadinessLevel $level): string => $level->value,
            ExchangeReadinessLevel::cases(),
        );

        self::assertNotContains('mainnet_ready', $values);
        self::assertNotContains('live_ready', $values);
        self::assertSame([
            'not_ready',
            'public_read_only',
            'private_read_only',
            'local_dry_run_ready',
            'demo_testnet_candidate',
            'demo_testnet_enabled',
        ], $values);
    }

    public function testRedactsSensitiveWarningOutput(): void
    {
        $report = (new ExchangeReadinessEvaluator())->evaluate(
            $this->readyInput(warnings: [
                'password=demo-password',
                'BITMART_API_MEMO=demo-memo',
                'OK-ACCESS-SIGN=demo-signature',
                'private_key=wallet-secret',
                'safe_fixture_warning',
            ]),
        );

        $encoded = json_encode($report->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('demo-password', $encoded);
        self::assertStringNotContainsString('demo-memo', $encoded);
        self::assertStringNotContainsString('demo-signature', $encoded);
        self::assertStringNotContainsString('wallet-secret', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
        self::assertStringContainsString('safe_fixture_warning', $encoded);
    }

    /**
     * @param array<mixed> $allowedSymbols
     * @param array<mixed> $allowedMarkets
     * @param list<string> $warnings
     */
    private function readyInput(
        string $environment = 'demo',
        bool $publicConnectivity = true,
        bool $privateReadConnectivity = true,
        bool $privateObservability = false,
        bool $instrumentsLoaded = true,
        bool $metadataValid = true,
        bool $precisionValid = true,
        bool $accountReadable = true,
        bool $permissionsRead = true,
        bool $permissionsTrade = true,
        bool $mainnetWriteGuard = true,
        bool $demoTestnetWriteGuard = true,
        bool $demoTestnetWriteEnabled = false,
        bool $stopLossCapability = true,
        bool $killSwitch = true,
        bool $dryRun = true,
        array $allowedSymbols = ['BTCUSDT'],
        array $allowedMarkets = ['perpetual'],
        array $warnings = [],
    ): ExchangeReadinessInput {
        return new ExchangeReadinessInput(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            environment: $environment,
            publicConnectivity: $publicConnectivity,
            privateReadConnectivity: $privateReadConnectivity,
            privateObservability: $privateObservability,
            instrumentsLoaded: $instrumentsLoaded,
            metadataValid: $metadataValid,
            precisionValid: $precisionValid,
            accountReadable: $accountReadable,
            permissionsRead: $permissionsRead,
            permissionsTrade: $permissionsTrade,
            mainnetWriteGuard: $mainnetWriteGuard,
            demoTestnetWriteGuard: $demoTestnetWriteGuard,
            demoTestnetWriteEnabled: $demoTestnetWriteEnabled,
            stopLossCapability: $stopLossCapability,
            killSwitch: $killSwitch,
            dryRun: $dryRun,
            allowedSymbols: $allowedSymbols,
            allowedMarkets: $allowedMarkets,
            maxNotional: 25.0,
            configHash: str_repeat('a', 64),
            warnings: $warnings,
        );
    }
}
