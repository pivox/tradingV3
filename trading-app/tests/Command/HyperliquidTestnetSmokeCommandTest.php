<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HyperliquidTestnetOrderPlanFileDecoder;
use App\Command\HyperliquidTestnetSmokeCommand;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessProbeInterface;
use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Hyperliquid\HyperliquidMutationReadinessGate;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidTestnetExecutionPortInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(HyperliquidTestnetSmokeCommand::class)]
#[CoversClass(HyperliquidTestnetOrderPlanFileDecoder::class)]
final class HyperliquidTestnetSmokeCommandTest extends TestCase
{
    private const CONFIRMATION = 'CONFIRM_HYPERLIQUID_TESTNET_ONLY';
    private const DECISION = 'ready_for_demo_testnet_trading_attempt';
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }

    #[DataProvider('invalidConfirmations')]
    public function testRequiresExactConfirmation(mixed $confirmation): void
    {
        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);

        $exitCode = $tester->execute($this->input($this->planFile(), $confirmation, self::DECISION));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("Smoke execution refused: confirmation rejected.\n", $tester->getDisplay());
        self::assertSame(0, $port->calls);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidConfirmations(): iterable
    {
        yield 'missing' => [null];
        yield 'generic yes' => ['yes'];
        yield 'trailing whitespace' => [self::CONFIRMATION . ' '];
        yield 'wrong case' => [strtolower(self::CONFIRMATION)];
    }

    #[DataProvider('invalidDecisions')]
    public function testRequiresExactReadinessDecision(mixed $decision): void
    {
        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);

        $exitCode = $tester->execute($this->input($this->planFile(), self::CONFIRMATION, $decision));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("Smoke execution refused: readiness decision rejected.\n", $tester->getDisplay());
        self::assertSame(0, $port->calls);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDecisions(): iterable
    {
        yield 'missing' => [null];
        yield 'blocked' => ['blocked'];
        yield 'wrong case' => ['READY_FOR_DEMO_TESTNET_TRADING_ATTEMPT'];
        yield 'trailing whitespace' => [self::DECISION . ' '];
    }

    public function testRefusesWhenReadinessGateBlocksImmediatelyBeforeExecution(): void
    {
        $port = new SmokeTestPort($this->accepted());
        $probe = new SmokeTestReadinessProbe($this->report(signerReady: false));
        $tester = $this->tester($port, $probe);

        $exitCode = $tester->execute($this->input($this->planFile()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("Smoke execution refused: mutation readiness blocked.\n", $tester->getDisplay());
        self::assertSame(1, $probe->calls);
        self::assertSame(0, $port->calls);
    }

    public function testRefusesReportedBlockingErrorBeforeExecution(): void
    {
        $port = new SmokeTestPort($this->accepted());
        $probe = new SmokeTestReadinessProbe($this->report(blockingErrors: ['runtime_config_unavailable']));
        $tester = $this->tester($port, $probe);

        $exitCode = $tester->execute($this->input($this->planFile()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("Smoke execution refused: mutation readiness blocked.\n", $tester->getDisplay());
        self::assertSame(1, $probe->calls);
        self::assertSame(0, $port->calls);
    }

    public function testRefusesWhenReadinessProbeThrowsWithoutLeakingException(): void
    {
        $port = new SmokeTestPort($this->accepted());
        $probe = new SmokeTestReadinessProbe(exception: new \RuntimeException('secret-readiness-detail'));
        $tester = $this->tester($port, $probe);

        $exitCode = $tester->execute($this->input($this->planFile()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("Smoke execution refused: mutation readiness unavailable.\n", $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay());
        self::assertSame(1, $probe->calls);
        self::assertSame(0, $port->calls);
    }

    #[DataProvider('invalidFileContents')]
    public function testRejectsMalformedOversizedAndInvalidVersionedJson(string $contents): void
    {
        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);

        $exitCode = $tester->execute($this->input($this->file($contents)));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("Smoke execution refused: order-plan file invalid.\n", $tester->getDisplay());
        self::assertSame(0, $port->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFileContents(): iterable
    {
        yield 'malformed json' => ['{"schema_version":'];
        yield 'oversized' => [str_repeat('x', 65_537)];
        yield 'missing version' => [json_encode(['order_plan' => []], \JSON_THROW_ON_ERROR)];
        yield 'unknown version' => [json_encode(['schema_version' => 2, 'order_plan' => []], \JSON_THROW_ON_ERROR)];
        yield 'string version' => [json_encode(['schema_version' => '1', 'order_plan' => []], \JSON_THROW_ON_ERROR)];
        yield 'unknown envelope field' => [json_encode(['schema_version' => 1, 'order_plan' => [], 'secret' => 'no'], \JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('duplicateJsonFiles')]
    public function testRejectsDuplicateJsonBeforePortExecution(string $needle, string $replacement): void
    {
        $json = json_encode($this->validEnvelope(), \JSON_THROW_ON_ERROR);
        $json = str_replace($needle, $replacement, $json, $replacements);
        self::assertSame(1, $replacements);
        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);

        self::assertSame(Command::FAILURE, $tester->execute($this->input($this->file($json))));
        self::assertSame("Smoke execution refused: order-plan file invalid.\n", $tester->getDisplay());
        self::assertSame(0, $port->calls);
    }

    /** @return iterable<string, array{string,string}> */
    public static function duplicateJsonFiles(): iterable
    {
        yield 'schema version' => ['"schema_version":1', '"schema_version":1,"schema_version":1'];
        yield 'exchange' => ['"exchange":"hyperliquid"', '"exchange":"hyperliquid","exchange":"hyperliquid"'];
        yield 'nested stop' => ['"stop_price":"98"', '"stop_price":"98","stop_price":"97"'];
        yield 'escaped-equivalent exchange' => ['"exchange":"hyperliquid"', '"exchange":"hyperliquid","exch\\u0061nge":"hyperliquid"'];
    }

    public function testRejectsNonFileAndSymlinkInputs(): void
    {
        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);
        $directory = $this->directory();

        self::assertSame(Command::FAILURE, $tester->execute($this->input($directory)));

        $target = $this->planFile();
        $link = $target . '.link';
        symlink($target, $link);
        $this->paths[] = $link;
        self::assertSame(Command::FAILURE, $tester->execute($this->input($link)));

        self::assertSame("Smoke execution refused: order-plan file invalid.\n", $tester->getDisplay());
        self::assertSame(0, $port->calls);
    }

    #[DataProvider('invalidPlanMutations')]
    public function testRejectsInvalidPlanShapeAndScalarValues(string $path, mixed $value): void
    {
        $plan = $this->validEnvelope();
        $cursor = &$plan;
        $parts = explode('.', $path);
        foreach ($parts as $part) {
            $cursor = &$cursor[$part];
        }
        $cursor = $value;

        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);
        $exitCode = $tester->execute($this->input($this->planFile($plan)));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("Smoke execution refused: order-plan file invalid.\n", $tester->getDisplay());
        self::assertSame(0, $port->calls);
    }

    /** @return iterable<string, array{string,mixed}> */
    public static function invalidPlanMutations(): iterable
    {
        yield 'unknown plan field' => ['order_plan.unknown', true];
        yield 'non Hyperliquid exchange' => ['order_plan.exchange', 'okx'];
        yield 'non perpetual market' => ['order_plan.market_type', 'spot'];
        yield 'non limit order' => ['order_plan.order_type', 'market'];
        yield 'non GTC order' => ['order_plan.time_in_force', 'ioc'];
        yield 'missing client id' => ['order_plan.client_order_id', ''];
        yield 'missing profile' => ['order_plan.profile', ''];
        yield 'missing config hash' => ['order_plan.config_hash', ''];
        yield 'invalid config hash' => ['order_plan.config_hash', str_repeat('z', 64)];
        yield 'numeric entry' => ['order_plan.entry_price', 100];
        yield 'numeric quantity' => ['order_plan.quantity', 1];
        yield 'negative entry' => ['order_plan.entry_price', '-1'];
        yield 'zero quantity' => ['order_plan.quantity', '0'];
        yield 'exponent entry' => ['order_plan.entry_price', '1e2'];
        yield 'noncanonical entry' => ['order_plan.entry_price', '100.0'];
        yield 'entry scale too large' => ['order_plan.entry_price', '100.000000001'];
        yield 'entry loses downstream float precision' => ['order_plan.entry_price', '999999999999.99999999'];
        yield 'excess leverage' => ['order_plan.leverage', 101];
        yield 'invalid side' => ['order_plan.side', 'buy'];
        yield 'invalid margin mode' => ['order_plan.margin_mode', 'portfolio'];
        yield 'numeric stop' => ['order_plan.protection_plan.stop_loss.stop_price', 98];
        yield 'missing full-size stop' => ['order_plan.protection_plan.stop_loss.is_full_size', false];
        yield 'stop on wrong side' => ['order_plan.protection_plan.stop_loss.stop_price', '101'];
    }

    public function testRejectsCallerSuppliedDerivedProtectionMetrics(): void
    {
        foreach (['stop_pct', 'stop_distance'] as $field) {
            $envelope = $this->validEnvelope();
            $envelope['order_plan']['protection_plan']['stop_loss'][$field] = '1';
            $port = new SmokeTestPort($this->accepted());
            $tester = $this->tester($port);

            self::assertSame(Command::FAILURE, $tester->execute($this->input($this->planFile($envelope))));
            self::assertSame(0, $port->calls);
        }

        $envelope = $this->validEnvelope();
        $envelope['order_plan']['protection_plan']['liquidation_check'] = [
            'is_safe' => true,
            'liquidation_distance_pct' => '0.2',
            'stop_to_liquidation_ratio' => '10',
        ];
        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);

        self::assertSame(Command::FAILURE, $tester->execute($this->input($this->planFile($envelope))));
        self::assertSame(0, $port->calls);
    }

    public function testDerivesStopAndLiquidationMetricsFromAuthoritativePrimitives(): void
    {
        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);

        self::assertSame(Command::SUCCESS, $tester->execute($this->input($this->planFile())));
        self::assertNotNull($port->request);
        $protection = $port->request->orderPlan->protectionPlan;
        self::assertNotNull($protection);
        self::assertNotNull($protection->stopLoss);
        self::assertNotNull($protection->liquidationCheck);
        self::assertSame(2.0, $protection->stopLoss->stopDistance);
        self::assertSame(0.02, $protection->stopLoss->stopPct);
        self::assertTrue($protection->liquidationCheck->isSafe);
        self::assertSame(80.0, $protection->liquidationCheck->liquidationPrice);
        self::assertSame(0.2, $protection->liquidationCheck->liquidationDistancePct);
        self::assertSame(10.0, $protection->liquidationCheck->stopToLiquidationRatio);
        self::assertSame(0.0, $protection->liquidationCheck->metadata['maintenance_margin_rate'] ?? null);
        self::assertSame(3.0, $protection->liquidationCheck->metadata['min_distance_ratio'] ?? null);
    }

    public function testRejectsPlanWhenDerivedLiquidationGuardIsUnsafe(): void
    {
        $envelope = $this->validEnvelope();
        $envelope['order_plan']['leverage'] = 100;
        $port = new SmokeTestPort($this->accepted());
        $tester = $this->tester($port);

        self::assertSame(Command::FAILURE, $tester->execute($this->input($this->planFile($envelope))));
        self::assertSame("Smoke execution refused: order-plan file invalid.\n", $tester->getDisplay());
        self::assertSame(0, $port->calls);
    }

    public function testBuildsCanonicalLiveRequestAndPrintsOnlySafeAcceptedIdentifiers(): void
    {
        $result = new ExecutionResult(
            ExecutionStatus::Accepted,
            'CID-HL-1',
            '123456789',
            ['secret' => 'raw-secret'],
            ['protection_confirmed' => true, 'token' => 'metadata-secret'],
        );
        $port = new SmokeTestPort($result);
        $trip = new SmokeTestTrip();
        $tester = $this->tester($port, trip: $trip);

        $exitCode = $tester->execute($this->input($this->planFile()));

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("status=accepted\nclient_order_id=CID-HL-1\nexchange_order_id=123456789\n", $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay());
        self::assertSame(1, $port->calls);
        self::assertNotNull($port->request);
        self::assertSame(ExecutionMode::Live, $port->request->mode);
        self::assertSame('hyperliquid', $port->request->orderPlan->exchange);
        self::assertSame('perpetual', $port->request->orderPlan->marketType);
        self::assertSame('limit', $port->request->orderPlan->orderType);
        self::assertSame('gtc', $port->request->orderPlan->timeInForce);
        self::assertTrue($port->request->orderPlan->protectionPlan?->stopLoss?->isFullSize ?? false);
        self::assertSame(self::HASH, $port->request->orderPlan->configHash);
        self::assertSame(0, $trip->attempts);
    }

    /** @param array<string,mixed> $metadata */
    #[DataProvider('ambiguousAcceptedResults')]
    public function testAcceptedResultWithoutProvenIdentityAndProtectionTripsQuarantine(
        ?string $clientOrderId,
        ?string $exchangeOrderId,
        array $metadata,
    ): void
    {
        $port = new SmokeTestPort(new ExecutionResult(
            ExecutionStatus::Accepted,
            $clientOrderId,
            $exchangeOrderId,
            metadata: $metadata,
        ));
        $trip = new SmokeTestTrip();
        $tester = $this->tester($port, trip: $trip);

        $exitCode = $tester->execute($this->input($this->planFile()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("status=ambiguous\n", $tester->getDisplay());
        self::assertSame(1, $trip->attempts);
        self::assertSame('hyperliquid_smoke_accepted_result_ambiguous', $trip->reason);
        self::assertSame(['command' => 'app:hyperliquid:testnet:smoke'], $trip->context);
    }

    /** @return iterable<string, array{?string,?string,array<string,mixed>}> */
    public static function ambiguousAcceptedResults(): iterable
    {
        yield 'missing client id' => [null, '123', ['protection_confirmed' => true]];
        yield 'unsafe client id' => ["CID\nleak=1", '123', ['protection_confirmed' => true]];
        yield 'mismatched client id' => ['CID-HL-OTHER', '123', ['protection_confirmed' => true]];
        yield 'missing exchange id' => ['CID-HL-1', null, ['protection_confirmed' => true]];
        yield 'unsafe exchange id' => ['CID-HL-1', '<secret>', ['protection_confirmed' => true]];
        yield 'absent protection proof' => ['CID-HL-1', '123', []];
        yield 'false protection proof' => ['CID-HL-1', '123', ['protection_confirmed' => false]];
    }

    public function testAmbiguousAcceptedResultStillFailsWhenDurableTripThrows(): void
    {
        $port = new SmokeTestPort(new ExecutionResult(
            ExecutionStatus::Accepted,
            'CID-HL-OTHER',
            '123',
            metadata: ['protection_confirmed' => true, 'secret' => 'not-output'],
        ));
        $trip = new SmokeTestTrip(throwOnTrip: true);
        $tester = $this->tester($port, trip: $trip);

        $exitCode = $tester->execute($this->input($this->planFile()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("status=ambiguous\n", $tester->getDisplay());
        self::assertSame(1, $trip->attempts);
    }

    #[DataProvider('unsuccessfulStatuses')]
    public function testEveryNonAcceptedOutcomeFailsWithOnlyFixedStatus(ExecutionStatus $status): void
    {
        $port = new SmokeTestPort(new ExecutionResult(
            $status,
            'CID-HL-1',
            '123',
            ['error' => 'private failure detail'],
            ['secret' => 'private metadata'],
        ));
        $tester = $this->tester($port);

        $exitCode = $tester->execute($this->input($this->planFile()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('status=' . $status->value . "\n", $tester->getDisplay());
    }

    /** @return iterable<string, array{ExecutionStatus}> */
    public static function unsuccessfulStatuses(): iterable
    {
        yield 'rejected' => [ExecutionStatus::Rejected];
        yield 'failed' => [ExecutionStatus::Failed];
        yield 'unknown skipped' => [ExecutionStatus::Skipped];
        yield 'dry run' => [ExecutionStatus::DryRun];
    }

    public function testPortExceptionUsesFixedFailureWithoutLeakingDetails(): void
    {
        $port = new SmokeTestPort(exception: new \RuntimeException('secret-port-failure'));
        $tester = $this->tester($port);

        $exitCode = $tester->execute($this->input($this->planFile()));

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame("Smoke execution failed.\n", $tester->getDisplay());
        self::assertSame(1, $port->calls);
    }

    private function tester(
        SmokeTestPort $port,
        ?SmokeTestReadinessProbe $probe = null,
        ?HyperliquidConfig $config = null,
        ?SmokeTestTrip $trip = null,
    ): CommandTester {
        return new CommandTester(new HyperliquidTestnetSmokeCommand(
            new HyperliquidTestnetOrderPlanFileDecoder(),
            $port,
            $probe ?? new SmokeTestReadinessProbe($this->report()),
            new HyperliquidMutationReadinessGate(),
            $config ?? $this->config(),
            $trip ?? new SmokeTestTrip(),
        ));
    }

    /** @return array<string,mixed> */
    private function input(string $path, mixed $confirmation = self::CONFIRMATION, mixed $decision = self::DECISION): array
    {
        $input = ['plan-file' => $path];
        if ($confirmation !== null) {
            $input['--confirm'] = $confirmation;
        }
        if ($decision !== null) {
            $input['--readiness-decision'] = $decision;
        }

        return $input;
    }

    /** @param array<string,mixed>|null $envelope */
    private function planFile(?array $envelope = null): string
    {
        return $this->file(json_encode($envelope ?? $this->validEnvelope(), \JSON_THROW_ON_ERROR));
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hl-smoke-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }

    private function directory(): string
    {
        $path = sys_get_temp_dir() . '/hl-smoke-dir-' . bin2hex(random_bytes(6));
        mkdir($path);
        $this->paths[] = $path;

        return $path;
    }

    /** @return array<string,mixed> */
    private function validEnvelope(): array
    {
        return [
            'schema_version' => 1,
            'order_plan' => [
                'symbol' => 'BTCUSDT',
                'profile' => 'scalper_micro',
                'config_hash' => self::HASH,
                'exchange' => 'hyperliquid',
                'market_type' => 'perpetual',
                'side' => 'long',
                'order_type' => 'limit',
                'margin_mode' => 'isolated',
                'time_in_force' => 'gtc',
                'entry_price' => '100',
                'quantity' => '0.1',
                'leverage' => 5,
                'client_order_id' => 'CID-HL-1',
                'idempotency_key' => 'decision:BTCUSDT:long',
                'protection_plan' => [
                    'stop_loss' => [
                        'stop_price' => '98',
                        'stop_source' => 'pivot',
                        'is_full_size' => true,
                    ],
                ],
            ],
        ];
    }

    private function accepted(): ExecutionResult
    {
        return new ExecutionResult(
            ExecutionStatus::Accepted,
            'CID-HL-1',
            '123',
            metadata: ['protection_confirmed' => true],
        );
    }

    private function config(): HyperliquidConfig
    {
        return new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: 'testnet',
            mainnetEnabled: false,
            globalDemoTradingEnabled: true,
            testnetTradingEnabled: true,
            testnetAccountAddress: '0x1111111111111111111111111111111111111111',
            testnetAgentAddress: '0x2222222222222222222222222222222222222222',
        );
    }

    /** @param list<string> $blockingErrors */
    private function report(bool $signerReady = true, array $blockingErrors = []): ExchangeReadinessReport
    {
        return new ExchangeReadinessReport(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            environment: 'testnet',
            readyLevel: ExchangeReadinessLevel::DemoTestnetCandidate,
            publicConnectivity: true,
            privateReadConnectivity: true,
            privateObservability: true,
            privateObservabilityStatus: null,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: true,
            permissionsRead: true,
            permissionsTrade: true,
            signerConfigured: $signerReady,
            signerMatchesAccount: true,
            nonceStoreReady: true,
            collateralReadable: true,
            pollingReady: true,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            stopLossCapability: true,
            killSwitch: false,
            allowedSymbols: ['BTCUSDT'],
            allowedMarkets: ['perpetual'],
            maxNotional: 25.0,
            configHash: self::HASH,
            blockingErrors: $blockingErrors,
            warnings: [],
            configProfile: 'scalper_micro',
        );
    }
}

final class SmokeTestPort implements HyperliquidTestnetExecutionPortInterface
{
    public int $calls = 0;
    public ?ExecutionRequest $request = null;

    public function __construct(
        private readonly ?ExecutionResult $result = null,
        private readonly ?\Throwable $exception = null,
    ) {
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        ++$this->calls;
        $this->request = $request;
        if ($this->exception instanceof \Throwable) {
            throw $this->exception;
        }

        return $this->result ?? new ExecutionResult(ExecutionStatus::Failed);
    }
}

final class SmokeTestReadinessProbe implements HyperliquidMutationReadinessProbeInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ?ExchangeReadinessReport $report = null,
        private readonly ?\Throwable $exception = null,
    ) {
    }

    public function current(): ExchangeReadinessReport
    {
        ++$this->calls;
        if ($this->exception instanceof \Throwable) {
            throw $this->exception;
        }

        return $this->report ?? throw new \RuntimeException('missing report');
    }
}

final class SmokeTestTrip implements HyperliquidKillSwitchTripInterface
{
    public int $attempts = 0;
    public ?string $reason = null;
    /** @var array<string,mixed>|null */
    public ?array $context = null;

    public function __construct(private readonly bool $throwOnTrip = false)
    {
    }

    public function isTripped(): bool
    {
        return false;
    }

    public function trip(string $reason, array $auditContext): void
    {
        ++$this->attempts;
        $this->reason = $reason;
        $this->context = $auditContext;
        if ($this->throwOnTrip) {
            throw new \RuntimeException('secret-trip-failure');
        }
    }
}
