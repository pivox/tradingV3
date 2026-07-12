<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidPollingObservabilityPolicy;
use App\Exchange\Hyperliquid\HyperliquidPollingObservabilityStatus;
use App\Exchange\Hyperliquid\HyperliquidSignedActionClientInterface;
use App\Exchange\Hyperliquid\HyperliquidSignedActionResult;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleStatus;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Provider\Hyperliquid\Dto\HyperliquidInstrumentMetadataDto;
use App\Provider\Hyperliquid\HyperliquidInstrumentMetadataProviderInterface;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessProbeInterface;
use App\Provider\Hyperliquid\HyperliquidNonceManagerInterface;
use App\Provider\Hyperliquid\HyperliquidNonceScope;
use App\Provider\Hyperliquid\HyperliquidPublicReadMapper;
use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationContext;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationReasonCode;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationResult;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionLockInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionLockLeaseInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionState;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionStatePolicy;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionStateProviderInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidLeveragePolicy;
use App\TradingCore\Execution\Hyperliquid\HyperliquidMutationReadinessGate;
use App\TradingCore\Execution\Hyperliquid\HyperliquidTestnetExecutionPort;
use App\TradingCore\Execution\Safety\DemoTradingAuditSinkInterface;
use App\TradingCore\Execution\Safety\DemoTradingKillSwitchService;
use App\TradingCore\Execution\Safety\DemoTradingSafetyPolicyEvaluator;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidTestnetExecutionPort::class)]
final class HyperliquidTestnetExecutionPortTest extends TestCase
{
    private const ACCOUNT = '0x1111111111111111111111111111111111111111';
    private const AGENT = '0x2222222222222222222222222222222222222222';
    private const HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[DataProvider('preNonceGateCases')]
    public function testEveryStaticAndReadinessGateRejectsBeforeNonceOrSignedAction(string $case): void
    {
        $fixture = $this->fixtureForGate($case);

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Rejected, $result->status, $case);
        self::assertSame([], $fixture->nonces->issued, $case);
        self::assertSame([], $fixture->signed->submissions, $case);
    }

    /** @return iterable<string, array{string}> */
    public static function preNonceGateCases(): iterable
    {
        foreach ([
            'dry_run_mode', 'wrong_exchange', 'wrong_market', 'non_limit', 'profile_mismatch',
            'missing_plan_hash', 'hash_mismatch', 'report_blocking_error', 'symbol_allowlist',
            'market_allowlist', 'notional_cap', 'durable_trip', 'durable_trip_unreadable',
            'readiness_gate', 'polling_missing', 'polling_stale', 'metadata_missing',
            'metadata_symbol_mismatch', 'metadata_not_live', 'metadata_incomplete',
            'contract_size', 'asset_id', 'price_precision', 'quantity_precision',
            'stop_precision', 'wire_decimal', 'minimum_size', 'maximum_size', 'leverage_cap',
            'quote_stale', 'quote_invalid', 'observed_leverage_unknown', 'lock_busy',
            'lock_error', 'nonce_not_ready', 'audit_failure',
        ] as $case) {
            yield $case => [$case];
        }
    }

    public function testRequestMetadataCannotForgePollingMetadataAssetLeverageQuoteOrConfigHash(): void
    {
        $report = $this->report(polling: null);
        $fixture = $this->fixture(
            report: $report,
            requestMetadata: [
                'polling_ready' => true,
                'hyperliquid_polling_status' => $this->polling(),
                'asset_id' => 0,
                'observed_leverage' => 5,
                'best_bid' => 99.9,
                'best_ask' => 100.1,
                'config_hash' => self::HASH,
            ],
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame([], $fixture->nonces->issued);
        self::assertSame([], $fixture->signed->submissions);
    }

    public function testMapperMetadataWithoutPublishedMaximumIsCompatibleWithExecutionPort(): void
    {
        $metadata = (new HyperliquidPublicReadMapper())->metadata(
            ['name' => 'BTC', 'szDecimals' => 5, 'maxLeverage' => 10],
            0,
            ['funding' => '0.0001'],
            null,
            [],
        );
        self::assertSame('0', $metadata->maxSize);
        $fixture = $this->fixture(
            metadata: $metadata,
            signedResults: [$this->acceptedGroup(42, 43)],
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Accepted, $result->status, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame([1_000], $fixture->nonces->issued);
        self::assertCount(1, $fixture->signed->submissions);
    }

    #[DataProvider('invalidMaximumSizes')]
    public function testInvalidOrNonCanonicalMaximumSizeRejectsBeforeNonce(string $maxSize): void
    {
        $fixture = $this->fixture(metadata: $this->metadata(maxSize: $maxSize));

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Rejected, $result->status, $maxSize);
        self::assertSame([], $fixture->nonces->issued, $maxSize);
        self::assertSame([], $fixture->signed->submissions, $maxSize);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMaximumSizes(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'malformed' => ['unbounded'];
        yield 'non-canonical decimal zero' => ['0.0'];
        yield 'non-canonical signed zero' => ['+0'];
        yield 'non-canonical negative zero' => ['-0'];
    }

    #[DataProvider('noMaximumSentinelRetainedLimits')]
    public function testNoMaximumSentinelStillEnforcesOtherSizingLimits(string $case): void
    {
        $fixture = match ($case) {
            'minimum_size' => $this->fixture(metadata: $this->metadata(minSize: '0.20', maxSize: '0')),
            'notional_cap' => $this->fixture(
                report: $this->report(maxNotional: 9.99),
                metadata: $this->metadata(maxSize: '0'),
            ),
            default => throw new \LogicException('unknown_retained_limit'),
        };

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Rejected, $result->status, $case);
        self::assertSame([], $fixture->nonces->issued, $case);
        self::assertSame([], $fixture->signed->submissions, $case);
    }

    /** @return iterable<string, array{string}> */
    public static function noMaximumSentinelRetainedLimits(): iterable
    {
        yield 'minimum size' => ['minimum_size'];
        yield 'notional cap' => ['notional_cap'];
    }

    #[DataProvider('unsupportedTimeInForceRepresentations')]
    public function testUnsupportedTimeInForceRejectsBeforeNonceOrSignedAction(string $timeInForce): void
    {
        $fixture = $this->fixture(plan: $this->plan(timeInForce: $timeInForce));

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Rejected, $result->status, $timeInForce);
        self::assertSame([], $fixture->nonces->issued, $timeInForce);
        self::assertSame([], $fixture->signed->submissions, $timeInForce);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedTimeInForceRepresentations(): iterable
    {
        yield 'IOC' => ['ioc'];
        yield 'FOK' => ['fok'];
        yield 'post only' => ['post_only'];
    }

    public function testEqualLeverageUsesOneFreshNonceForExactlyOneGroupedAction(): void
    {
        $fixture = $this->fixture(signedResults: [$this->acceptedGroup(42, 43)]);

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Accepted, $result->status, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('42', $result->exchangeOrderId);
        self::assertTrue($result->metadata['protection_confirmed']);
        self::assertSame([1_000], $fixture->nonces->issued);
        self::assertCount(1, $fixture->signed->submissions);
        self::assertSame('positionTpsl', $fixture->signed->submissions[0]['action']['grouping']);
        self::assertSame(['resting', 'resting'], array_column($this->acceptedGroup(42, 43)->statuses, 'kind'));
        self::assertSame([], $result->raw);
    }

    public function testDifferentLeverageBurnsDedicatedNonceBeforeGroupedNonce(): void
    {
        $fixture = $this->fixture(
            state: $this->state(leverage: 3),
            signedResults: [$this->acceptedLeverage(), $this->acceptedGroup(42, 43)],
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Accepted, $result->status);
        self::assertSame([1_000, 1_001], $fixture->nonces->issued);
        self::assertSame(['updateLeverage', 'order'], array_column(array_column($fixture->signed->submissions, 'action'), 'type'));
    }

    public function testFilledEntryAndFilledStopRowsAreAcceptedInOriginalOrder(): void
    {
        $fixture = $this->fixture(signedResults: [new HyperliquidSignedActionResult('order', 'accepted', [
            ['kind' => 'filled', 'oid' => 42, 'total_size' => '0.1', 'average_price' => '100'],
            ['kind' => 'filled', 'oid' => 43, 'total_size' => '0.1', 'average_price' => '98'],
        ], null, 'corr-1')]);

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Accepted, $result->status);
        self::assertSame('42', $result->exchangeOrderId);
        self::assertTrue($result->metadata['protection_confirmed']);
    }

    #[DataProvider('invalidAcceptedGroupedResponses')]
    public function testInvalidAcceptedGroupedResponseCompensates(string $case): void
    {
        $submission = match ($case) {
            'duplicate_oid' => $this->acceptedGroup(42, 42),
            'non_positive_oid' => $this->unsafeResult('order', 'accepted', [
                ['kind' => 'resting', 'oid' => 0],
                ['kind' => 'resting', 'oid' => 43],
            ], null, 'corr-1'),
            'wrong_action_type' => new HyperliquidSignedActionResult('cancel', 'accepted', [
                ['kind' => 'success'], ['kind' => 'success'],
            ], null, 'corr-1'),
            'correlation_mismatch' => new HyperliquidSignedActionResult('order', 'accepted', [
                ['kind' => 'resting', 'oid' => 42],
                ['kind' => 'resting', 'oid' => 43],
            ], null, 'corr-other'),
            'filled_total_size_missing' => new HyperliquidSignedActionResult('order', 'accepted', [
                ['kind' => 'filled', 'oid' => 42],
                ['kind' => 'resting', 'oid' => 43],
            ], null, 'corr-1'),
            'filled_total_size_malformed' => $this->unsafeResult('order', 'accepted', [
                ['kind' => 'filled', 'oid' => 42, 'total_size' => 'not-a-decimal'],
                ['kind' => 'resting', 'oid' => 43],
            ], null, 'corr-1'),
            'filled_total_size_unrepresentable' => new HyperliquidSignedActionResult('order', 'accepted', [
                ['kind' => 'filled', 'oid' => 42, 'total_size' => '0.101'],
                ['kind' => 'resting', 'oid' => 43],
            ], null, 'corr-1'),
            'filled_total_size_mismatch' => new HyperliquidSignedActionResult('order', 'accepted', [
                ['kind' => 'resting', 'oid' => 42],
                ['kind' => 'filled', 'oid' => 43, 'total_size' => '0.11'],
            ], null, 'corr-1'),
            default => throw new \LogicException('unknown_invalid_accepted_group'),
        };
        $fixture = $this->fixture(signedResults: [$submission], compensationResult: $this->unknown());

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Failed, $result->status, $case);
        self::assertSame('unknown_requires_resync', $result->metadata['compensation_outcome'], $case);
        self::assertCount(1, $fixture->compensation->contexts, $case);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAcceptedGroupedResponses(): iterable
    {
        foreach ([
            'duplicate_oid',
            'non_positive_oid',
            'wrong_action_type',
            'correlation_mismatch',
            'filled_total_size_missing',
            'filled_total_size_malformed',
            'filled_total_size_unrepresentable',
            'filled_total_size_mismatch',
        ] as $case) {
            yield $case => [$case];
        }
    }

    public function testUnknownLeverageCanUpdateOnlyUnderExplicitPolicy(): void
    {
        $fixture = $this->fixture(
            state: $this->state(leverage: null),
            allowUnknownLeverage: true,
            signedResults: [$this->acceptedLeverage(), $this->acceptedGroup(42, 43)],
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Accepted, $result->status);
        self::assertSame([1_000, 1_001], $fixture->nonces->issued);
    }

    public function testAmbiguousLeverageUpdateBurnsNonceTripsAndNeverSubmitsGroup(): void
    {
        $fixture = $this->fixture(
            state: $this->state(leverage: 3),
            signedResults: [$this->ambiguous('updateLeverage')],
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Failed, $result->status);
        self::assertSame('unknown_requires_resync', $result->metadata['outcome']);
        self::assertSame([1_000], $fixture->nonces->issued);
        self::assertCount(1, $fixture->signed->submissions);
        self::assertSame(1, $fixture->trip->tripAttempts);
    }

    public function testRejectedLeverageUpdateBurnsItsNonceWithoutGroupOrTrip(): void
    {
        $fixture = $this->fixture(
            state: $this->state(leverage: 3),
            signedResults: [new HyperliquidSignedActionResult(
                'updateLeverage', 'rejected', [], 'exchange_error', 'corr-1',
            )],
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame([1_000], $fixture->nonces->issued);
        self::assertCount(1, $fixture->signed->submissions);
        self::assertSame(0, $fixture->trip->tripAttempts);
    }

    public function testBothOrderRowsRejectedReturnRejectedWithoutCompensation(): void
    {
        $fixture = $this->fixture(signedResults: [$this->bothRejected()]);

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame([], $fixture->compensation->contexts);
        self::assertSame(0, $fixture->trip->tripAttempts);
    }

    #[DataProvider('invalidRejectedGroupedResponses')]
    public function testRejectedGroupedResponseWithInvalidIdentityCompensates(string $case): void
    {
        $submission = match ($case) {
            'wrong_action_type' => new HyperliquidSignedActionResult('cancel', 'rejected', [
                ['kind' => 'error'], ['kind' => 'error'],
            ], 'exchange_status_error', 'corr-1'),
            'correlation_mismatch' => new HyperliquidSignedActionResult('order', 'rejected', [
                ['kind' => 'error'], ['kind' => 'error'],
            ], 'exchange_status_error', 'corr-other'),
            'missing_row' => new HyperliquidSignedActionResult('order', 'rejected', [
                ['kind' => 'error'],
            ], 'exchange_status_error', 'corr-1'),
            'extra_row' => new HyperliquidSignedActionResult('order', 'rejected', [
                ['kind' => 'error'], ['kind' => 'error'], ['kind' => 'error'],
            ], 'exchange_status_error', 'corr-1'),
            default => throw new \LogicException('unknown_invalid_rejected_group'),
        };
        $fixture = $this->fixture(signedResults: [$submission], compensationResult: $this->unknown());

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Failed, $result->status, $case);
        self::assertSame('unknown_requires_resync', $result->metadata['compensation_outcome'], $case);
        self::assertCount(1, $fixture->compensation->contexts, $case);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRejectedGroupedResponses(): iterable
    {
        yield 'wrong action type' => ['wrong_action_type'];
        yield 'correlation mismatch' => ['correlation_mismatch'];
        yield 'missing row' => ['missing_row'];
        yield 'extra row' => ['extra_row'];
    }

    public function testEntryAcceptedStopRejectedCompensatesWithExactQuantizedContext(): void
    {
        $fixture = $this->fixture(
            signedResults: [$this->mixed([['kind' => 'resting', 'oid' => 42], ['kind' => 'error']])],
            compensationResult: $this->entryCanceled(),
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Failed, $result->status);
        self::assertSame('entry_canceled', $result->metadata['compensation_outcome']);
        self::assertCount(1, $fixture->compensation->contexts);
        $context = $fixture->compensation->contexts[0];
        self::assertSame(0, $context->assetId);
        self::assertSame('42', $context->entryExchangeOrderId);
        self::assertSame('0.10', $context->canonicalQuantity->canonical);
        self::assertSame(2, $context->quantityPrecision);
        self::assertSame('0.01', $context->quantityStep);
        self::assertSame((new HyperliquidActionFactory())->cloid('CID-HL-1'), $context->entryWireCloid);
        self::assertSame(99.4, $context->emergencyCloseSlippageCapPrice);
    }

    #[DataProvider('terminalCompensationOutcomes')]
    public function testTerminalCompensationOutcomeMapping(
        HyperliquidCompensationResult $compensation,
        ExecutionStatus $expectedStatus,
    ): void {
        $fixture = $this->fixture(
            signedResults: [$this->ambiguous('order')],
            compensationResult: $compensation,
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame($expectedStatus, $result->status);
        self::assertSame($compensation->outcome, $result->metadata['compensation_outcome']);
    }

    /** @return iterable<string, array{HyperliquidCompensationResult, ExecutionStatus}> */
    public static function terminalCompensationOutcomes(): iterable
    {
        yield 'entry rejected' => [new HyperliquidCompensationResult(
            'entry_rejected', HyperliquidCompensationReasonCode::ENTRY_REJECTED, HyperliquidLifecycleStatus::REJECTED,
            0.1, 0.0, 0.0, 2, '0.01', '42', null, 'corr-1',
        ), ExecutionStatus::Rejected];
        yield 'exposure closed' => [new HyperliquidCompensationResult(
            'exposure_closed', HyperliquidCompensationReasonCode::EXPOSURE_CLOSED, HyperliquidLifecycleStatus::FILLED,
            0.1, 0.1, 0.1, 2, '0.01', '42', '99', 'corr-1',
        ), ExecutionStatus::Failed];
    }

    #[DataProvider('ambiguousGroupedCases')]
    public function testInverseMissingExtraAndTransportGroupedResultsAlwaysReconcile(string $case): void
    {
        $resultOrException = match ($case) {
            'inverse' => $this->mixed([['kind' => 'error'], ['kind' => 'resting', 'oid' => 43]]),
            'missing' => new HyperliquidSignedActionResult('order', 'accepted', [['kind' => 'resting', 'oid' => 42]], null, 'corr-1'),
            'extra' => new HyperliquidSignedActionResult('order', 'accepted', [
                ['kind' => 'resting', 'oid' => 42], ['kind' => 'resting', 'oid' => 43], ['kind' => 'resting', 'oid' => 44],
            ], null, 'corr-1'),
            'ambiguous' => $this->ambiguous('order'),
            'transport' => new \RuntimeException('authorization=secret transport payload'),
            default => throw new \LogicException('unknown_grouped_case'),
        };
        $fixture = $this->fixture(
            signedResults: [$resultOrException],
            compensationResult: $this->unknown(),
        );

        $result = $fixture->port->execute($fixture->request);

        self::assertSame(ExecutionStatus::Failed, $result->status);
        self::assertSame('unknown_requires_resync', $result->metadata['compensation_outcome']);
        self::assertCount(1, $fixture->compensation->contexts);
        self::assertStringNotContainsString('secret', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame([], $result->raw);
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousGroupedCases(): iterable
    {
        foreach (['inverse', 'missing', 'extra', 'ambiguous', 'transport'] as $case) {
            yield $case => [$case];
        }
    }

    public function testShortMapsSellEntryBuyStopAndEmergencyBuyCapAboveAsk(): void
    {
        $fixture = $this->fixture(
            plan: $this->plan(side: 'short', entry: 100.0, stop: 102.0),
            signedResults: [$this->mixed([['kind' => 'resting', 'oid' => 42], ['kind' => 'error']])],
            compensationResult: $this->entryCanceled(),
        );

        $fixture->port->execute($fixture->request);

        $orders = $fixture->signed->submissions[0]['action']['orders'];
        self::assertFalse($orders[0]['b']);
        self::assertTrue($orders[1]['b']);
        self::assertFalse($orders[0]['r']);
        self::assertTrue($orders[1]['r']);
        self::assertSame('102', $orders[1]['t']['trigger']['triggerPx']);
        self::assertSame(100.7, $fixture->compensation->contexts[0]->emergencyCloseSlippageCapPrice);
    }

    public function testResultMetadataIsRecursivelyRedactedAndContainsNoRawResponse(): void
    {
        $fixture = $this->fixture(
            requestMetadata: ['nested' => ['private_key' => 'request-secret', 'safe' => 'visible']],
            signedResults: [new \RuntimeException('token=sidecar-secret')],
            compensationResult: $this->unknown(),
        );

        $result = $fixture->port->execute($fixture->request);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertSame([], $result->raw);
        self::assertStringNotContainsString('request-secret', $encoded);
        self::assertStringNotContainsString('sidecar-secret', $encoded);
        self::assertStringNotContainsString('statuses', $encoded);
    }

    private function fixtureForGate(string $case): TestnetPortFixture
    {
        $config = $this->config();
        $report = $this->report();
        $plan = $this->plan();
        $metadata = $this->metadata();
        $state = $this->state();
        $mode = ExecutionMode::Live;
        $trip = new TestnetPortTrip();
        $lockAvailable = true;
        $lockThrows = false;
        $nonceReady = true;
        $auditFails = false;
        $metadataMissing = false;

        switch ($case) {
            case 'dry_run_mode': $mode = ExecutionMode::DryRun; break;
            case 'wrong_exchange': $plan = $this->plan(exchange: 'okx'); break;
            case 'wrong_market': $plan = $this->plan(market: 'spot'); break;
            case 'non_limit': $plan = $this->plan(orderType: 'market'); break;
            case 'profile_mismatch': $plan = $this->plan(profile: 'regular'); break;
            case 'missing_plan_hash': $plan = $this->plan(configHash: null); break;
            case 'hash_mismatch': $plan = $this->plan(configHash: str_repeat('b', 64)); break;
            case 'report_blocking_error': $report = $this->report(blockingErrors: ['not_ready']); break;
            case 'symbol_allowlist': $report = $this->report(allowedSymbols: ['ETHUSDT']); break;
            case 'market_allowlist': $report = $this->report(allowedMarkets: ['spot']); break;
            case 'notional_cap': $report = $this->report(maxNotional: 9.99); break;
            case 'durable_trip': $trip->tripped = true; break;
            case 'durable_trip_unreadable': $trip->readFailure = true; break;
            case 'readiness_gate': $config = $this->config(network: 'mainnet'); break;
            case 'polling_missing': $report = $this->report(polling: null); break;
            case 'polling_stale': $report = $this->report(polling: $this->polling('2026-07-12T11:59:57.999Z')); break;
            case 'metadata_missing': $metadataMissing = true; break;
            case 'metadata_symbol_mismatch': $metadata = $this->metadata(symbol: 'ETHUSDT'); break;
            case 'metadata_not_live': $metadata = $this->metadata(status: 'suspend'); break;
            case 'metadata_incomplete': $metadata = $this->metadata(qualityFlags: ['invalid_size_decimals']); break;
            case 'contract_size': $metadata = $this->metadata(contractSize: '0.001'); break;
            case 'asset_id': $metadata = $this->metadata(assetId: -1); break;
            case 'price_precision': $plan = $this->plan(entry: 100.001); break;
            case 'quantity_precision': $plan = $this->plan(quantity: 0.101); break;
            case 'stop_precision': $plan = $this->plan(stop: 98.001); break;
            case 'wire_decimal': $plan = $this->plan(quantity: 0.123456789); break;
            case 'minimum_size': $plan = $this->plan(quantity: 0.01); $metadata = $this->metadata(minSize: '0.02'); break;
            case 'maximum_size': $plan = $this->plan(quantity: 0.11); $metadata = $this->metadata(maxSize: '0.10'); break;
            case 'leverage_cap': $plan = $this->plan(leverage: 11); break;
            case 'quote_stale': $state = $this->state(observedAt: '2026-07-12T11:59:57.999Z'); break;
            case 'quote_invalid': $state = $this->state(bid: 100.1, ask: 99.9); break;
            case 'observed_leverage_unknown': $state = $this->state(leverage: null); break;
            case 'lock_busy': $lockAvailable = false; break;
            case 'lock_error': $lockThrows = true; break;
            case 'nonce_not_ready': $nonceReady = false; break;
            case 'audit_failure': $auditFails = true; break;
        }

        return $this->fixture(
            config: $config,
            report: $report,
            plan: $plan,
            metadata: $metadata,
            state: $state,
            mode: $mode,
            trip: $trip,
            lockAvailable: $lockAvailable,
            lockThrows: $lockThrows,
            nonceReady: $nonceReady,
            auditFails: $auditFails,
            metadataMissing: $metadataMissing,
        );
    }

    /**
     * @param list<HyperliquidSignedActionResult|\Throwable> $signedResults
     * @param array<string,mixed> $requestMetadata
     */
    private function fixture(
        ?HyperliquidConfig $config = null,
        ?ExchangeReadinessReport $report = null,
        ?OrderPlan $plan = null,
        ?HyperliquidInstrumentMetadataDto $metadata = null,
        ?HyperliquidExecutionState $state = null,
        ExecutionMode $mode = ExecutionMode::Live,
        ?TestnetPortTrip $trip = null,
        bool $lockAvailable = true,
        bool $nonceReady = true,
        bool $auditFails = false,
        bool $allowUnknownLeverage = false,
        array $signedResults = [],
        ?HyperliquidCompensationResult $compensationResult = null,
        array $requestMetadata = [],
        bool $metadataMissing = false,
        bool $lockThrows = false,
    ): TestnetPortFixture {
        $config ??= $this->config();
        $report ??= $this->report();
        $plan ??= $this->plan();
        $metadataProvider = new TestnetPortMetadataProvider($metadataMissing ? null : ($metadata ?? $this->metadata()));
        $stateProvider = new TestnetPortExecutionStateProvider($state ?? $this->state());
        $trip ??= new TestnetPortTrip();
        $nonces = new TestnetPortNonceManager($nonceReady);
        $signed = new TestnetPortSignedClient($signedResults);
        $compensation = new TestnetPortCompensation($compensationResult ?? $this->unknown());
        $lock = new TestnetPortExecutionLock($lockAvailable, $lockThrows);
        $clock = new MockClock('2026-07-12T12:00:00.000Z');
        $audit = $auditFails ? new TestnetPortThrowingAuditSink() : new TestnetPortAuditSink();
        $killSwitch = new DemoTradingKillSwitchService(
            evaluator: new DemoTradingSafetyPolicyEvaluator(),
            privateObservabilityPolicy: new ExchangePrivateObservabilityPolicy(),
            auditSink: $audit,
            globalDemoTradingEnabled: true,
            okxDemoTradingEnabled: false,
            hyperliquidTestnetTradingEnabled: true,
            hyperliquidPollingPolicy: new HyperliquidPollingObservabilityPolicy($clock),
        );
        $port = new HyperliquidTestnetExecutionPort(
            config: $config,
            readiness: new TestnetPortReadinessProbe($report),
            readinessGate: new HyperliquidMutationReadinessGate(),
            metadata: $metadataProvider,
            executionState: $stateProvider,
            executionStatePolicy: new HyperliquidExecutionStatePolicy($clock),
            killSwitch: $killSwitch,
            durableTrip: $trip,
            nonces: $nonces,
            actions: new HyperliquidActionFactory(),
            signedActions: $signed,
            compensation: $compensation,
            executionLock: $lock,
            leveragePolicy: new HyperliquidLeveragePolicy($allowUnknownLeverage),
        );
        $request = ExecutionRequest::forPlan($plan, $mode, $requestMetadata + ['correlation_id' => 'corr-1']);

        return new TestnetPortFixture($port, $request, $nonces, $signed, $compensation, $trip, $lock);
    }

    private function config(string $network = 'testnet'): HyperliquidConfig
    {
        return new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: $network,
            mainnetEnabled: false,
            globalDemoTradingEnabled: true,
            testnetTradingEnabled: true,
            testnetAccountAddress: self::ACCOUNT,
            testnetAgentAddress: self::AGENT,
        );
    }

    /**
     * @param list<string> $allowedSymbols
     * @param list<string> $allowedMarkets
     * @param list<string> $blockingErrors
     */
    private function report(
        array $allowedSymbols = ['BTCUSDT'],
        array $allowedMarkets = ['perpetual'],
        float $maxNotional = 25.0,
        array $blockingErrors = [],
        ?HyperliquidPollingObservabilityStatus $polling = null,
    ): ExchangeReadinessReport {
        $polling ??= func_num_args() < 5 ? $this->polling() : null;

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
            signerConfigured: true,
            signerMatchesAccount: true,
            nonceStoreReady: true,
            collateralReadable: true,
            pollingReady: true,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            stopLossCapability: true,
            killSwitch: false,
            allowedSymbols: $allowedSymbols,
            allowedMarkets: $allowedMarkets,
            maxNotional: $maxNotional,
            configHash: self::HASH,
            blockingErrors: $blockingErrors,
            warnings: [],
            configProfile: 'scalper_micro',
            hyperliquidPollingObservabilityStatus: $polling,
        );
    }

    private function polling(string $observedAt = '2026-07-12T11:59:59.000Z'): HyperliquidPollingObservabilityStatus
    {
        return new HyperliquidPollingObservabilityStatus(
            Exchange::HYPERLIQUID,
            'testnet',
            'https://api.hyperliquid-testnet.xyz',
            true,
            true,
            true,
            true,
            false,
            new \DateTimeImmutable($observedAt),
        );
    }

    /** @param list<string> $qualityFlags */
    private function metadata(
        string $symbol = 'BTCUSDT',
        int $assetId = 0,
        string $minSize = '0.01',
        string $maxSize = '10',
        array $qualityFlags = [],
        string $status = 'live',
        string $contractSize = '1',
    ): HyperliquidInstrumentMetadataDto {
        return new HyperliquidInstrumentMetadataDto(
            symbol: $symbol,
            coin: 'BTC',
            assetId: $assetId,
            priceTick: '0.1',
            priceMaxDecimals: 1,
            quantityStep: '0.01',
            minSize: $minSize,
            maxSize: $maxSize,
            maxLeverage: '10',
            fundingRate: '0.0001',
            fundingTime: new \DateTimeImmutable('2026-07-12T11:00:00Z'),
            qualityFlags: $qualityFlags,
            status: $status,
            contractSize: $contractSize,
        );
    }

    private function state(
        float $bid = 99.9,
        float $ask = 100.1,
        ?int $leverage = 5,
        string $observedAt = '2026-07-12T11:59:59.000Z',
    ): HyperliquidExecutionState {
        return new HyperliquidExecutionState('BTCUSDT', $bid, $ask, new \DateTimeImmutable($observedAt), $leverage);
    }

    private function plan(
        string $side = 'long',
        float $entry = 100.0,
        float $stop = 98.0,
        float $quantity = 0.10,
        int $leverage = 5,
        string $exchange = 'hyperliquid',
        string $market = 'perpetual',
        string $orderType = 'limit',
        ?string $timeInForce = null,
        string $profile = 'scalper_micro',
        ?string $configHash = self::HASH,
    ): OrderPlan {
        $liquidation = $side === 'long' ? 80.0 : 120.0;
        $plan = new OrderPlan(
            symbol: 'BTCUSDT',
            profile: $profile,
            exchange: $exchange,
            marketType: $market,
            side: $side,
            orderType: $orderType,
            marginMode: 'isolated',
            timeInForce: $timeInForce ?? ($orderType === 'market' ? 'ioc' : 'gtc'),
            entryPrice: $entry,
            quantity: $quantity,
            leverage: $leverage,
            protectionPlan: new ProtectionPlan(
                stopLoss: new StopLossResult($stop, abs($entry - $stop) / $entry, abs($entry - $stop), 'pivot', true),
                takeProfit: new TakeProfitResult($side === 'long' ? 103.0 : 97.0, null, 1.5, 1.4, 'r_multiple'),
                liquidationCheck: new LiquidationCheckResult(true, $liquidation, 0.20, 2.0),
                isValid: true,
                status: ProtectionPlanStatus::Valid,
            ),
            clientOrderId: 'CID-HL-1',
            idempotencyKey: 'decision:BTCUSDT:' . $side,
            configHash: $configHash,
        );

        return $plan->withValidation((new OrderPlanValidator())->validate($plan));
    }

    private function acceptedGroup(int $entryOid, int $stopOid): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult('order', 'accepted', [
            ['kind' => 'resting', 'oid' => $entryOid],
            ['kind' => 'resting', 'oid' => $stopOid],
        ], null, 'corr-1');
    }

    private function acceptedLeverage(): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult('updateLeverage', 'accepted', [], null, 'corr-1');
    }

    private function bothRejected(): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult('order', 'rejected', [
            ['kind' => 'error'], ['kind' => 'error'],
        ], 'exchange_status_error', 'corr-1');
    }

    /** @param list<array<string,mixed>> $statuses */
    private function mixed(array $statuses): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult('order', 'ambiguous', $statuses, 'mixed_exchange_statuses', 'corr-1');
    }

    private function ambiguous(string $actionType): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult($actionType, 'ambiguous', [], 'exchange_timeout', 'corr-1');
    }

    /**
     * Creates a boundary-corrupt result that could only come from a defective signed-action client.
     *
     * @param list<array<string,mixed>> $statuses
     */
    private function unsafeResult(
        string $actionType,
        string $outcome,
        array $statuses,
        ?string $reason,
        string $correlationId,
    ): HyperliquidSignedActionResult {
        $reflection = new \ReflectionClass(HyperliquidSignedActionResult::class);
        $result = $reflection->newInstanceWithoutConstructor();
        foreach (compact('actionType', 'outcome', 'statuses', 'reason', 'correlationId') as $property => $value) {
            $reflection->getProperty($property)->setValue($result, $value);
        }

        return $result;
    }

    private function entryCanceled(): HyperliquidCompensationResult
    {
        return new HyperliquidCompensationResult(
            'entry_canceled', HyperliquidCompensationReasonCode::ENTRY_CANCELED, HyperliquidLifecycleStatus::CANCELED,
            0.1, 0.0, 0.0, 2, '0.01', '42', null, 'corr-1',
        );
    }

    private function unknown(): HyperliquidCompensationResult
    {
        return new HyperliquidCompensationResult(
            'unknown_requires_resync', HyperliquidCompensationReasonCode::ENTRY_RECONCILIATION_UNCONFIRMED,
            HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC, 0.1, 0.0, 0.0, 2, '0.01', null, null, 'corr-1',
        );
    }
}

final readonly class TestnetPortFixture
{
    public function __construct(
        public HyperliquidTestnetExecutionPort $port,
        public ExecutionRequest $request,
        public TestnetPortNonceManager $nonces,
        public TestnetPortSignedClient $signed,
        public TestnetPortCompensation $compensation,
        public TestnetPortTrip $trip,
        public TestnetPortExecutionLock $lock,
    ) {}
}

final readonly class TestnetPortReadinessProbe implements HyperliquidMutationReadinessProbeInterface
{
    public function __construct(private ExchangeReadinessReport $report) {}
    public function current(): ExchangeReadinessReport { return $this->report; }
}

final class TestnetPortMetadataProvider implements HyperliquidInstrumentMetadataProviderInterface
{
    public function __construct(public ?HyperliquidInstrumentMetadataDto $metadata) {}
    public function getInstrumentMetadata(string $symbol): ?HyperliquidInstrumentMetadataDto { return $this->metadata; }
}

final readonly class TestnetPortExecutionStateProvider implements HyperliquidExecutionStateProviderInterface
{
    public function __construct(private HyperliquidExecutionState $state) {}
    public function current(string $symbol): HyperliquidExecutionState { return $this->state; }
}

final class TestnetPortNonceManager implements HyperliquidNonceManagerInterface
{
    /** @var list<int> */ public array $issued = [];
    public function __construct(public bool $ready) {}
    public function isReady(HyperliquidNonceScope $scope): bool { return $this->ready; }
    public function nextNonce(HyperliquidNonceScope $scope): int { $nonce = 1_000 + count($this->issued); $this->issued[] = $nonce; return $nonce; }
    public function recordObservedNonce(HyperliquidNonceScope $scope, int $nonce): void {}
}

final class TestnetPortSignedClient implements HyperliquidSignedActionClientInterface
{
    /** @var list<array{action: array<string,mixed>, nonce: int, correlation_id: string}> */ public array $submissions = [];
    /** @param list<HyperliquidSignedActionResult|\Throwable> $results */
    public function __construct(private array $results) {}
    public function submit(array $action, int $nonce, string $correlationId, ?int $expiresAfter = null): HyperliquidSignedActionResult
    {
        $this->submissions[] = compact('action', 'nonce') + ['correlation_id' => $correlationId];
        $result = array_shift($this->results) ?? throw new \RuntimeException('missing_fake_signed_result');
        if ($result instanceof \Throwable) { throw $result; }
        return $result;
    }
    public function health(): bool { return true; }
}

final class TestnetPortCompensation implements HyperliquidCompensationInterface
{
    /** @var list<HyperliquidCompensationContext> */ public array $contexts = [];
    public function __construct(private HyperliquidCompensationResult $result) {}
    public function compensate(HyperliquidCompensationContext $context): HyperliquidCompensationResult
    {
        $this->contexts[] = $context;
        return $this->result;
    }
}

final class TestnetPortTrip implements HyperliquidKillSwitchTripInterface
{
    public bool $tripped = false;
    public bool $readFailure = false;
    public int $tripAttempts = 0;
    public function isTripped(): bool { if ($this->readFailure) { throw new \RuntimeException('trip_unreadable'); } return $this->tripped; }
    public function trip(string $reason, array $redactedAuditContext = []): void { ++$this->tripAttempts; $this->tripped = true; }
}

final class TestnetPortExecutionLock implements HyperliquidExecutionLockInterface
{
    public function __construct(public bool $available, public bool $throws = false) {}
    public function acquire(): ?HyperliquidExecutionLockLeaseInterface
    {
        if ($this->throws) { throw new \RuntimeException('lock_backend_failed'); }
        return $this->available ? new TestnetPortExecutionLockLease() : null;
    }
    public function isInFlight(): bool { return !$this->available; }
}

final class TestnetPortExecutionLockLease implements HyperliquidExecutionLockLeaseInterface
{
    public bool $released = false;
    public function release(): void { $this->released = true; }
}

final class TestnetPortAuditSink implements DemoTradingAuditSinkInterface
{
    public function recordDemoTradingAttempt(array $event): void {}
}

final class TestnetPortThrowingAuditSink implements DemoTradingAuditSinkInterface
{
    public function recordDemoTradingAttempt(array $event): void { throw new \RuntimeException('audit_failed'); }
}
