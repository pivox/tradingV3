<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\HyperliquidSignedActionClientInterface;
use App\Exchange\Hyperliquid\HyperliquidSignedActionResult;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleNormalizer;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleStatus;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidNormalizedOrderLifecycleDto;
use App\Provider\Hyperliquid\HyperliquidIdentifierLifecycleLookupInterface;
use App\Provider\Hyperliquid\HyperliquidNonceManagerInterface;
use App\Provider\Hyperliquid\HyperliquidNonceScopeConflictException;
use App\Provider\Hyperliquid\HyperliquidNonceScope;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationContext;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationReasonCode;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationResult;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationService;
use App\TradingCore\Execution\Hyperliquid\HyperliquidCompensationSleeperInterface;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidCompensationService::class)]
#[CoversClass(HyperliquidCompensationContext::class)]
#[CoversClass(HyperliquidCompensationResult::class)]
#[CoversClass(HyperliquidCompensationSleeperInterface::class)]
final class HyperliquidCompensationServiceTest extends TestCase
{
    private const ACCOUNT = '0x1111111111111111111111111111111111111111';
    private const AGENT = '0x2222222222222222222222222222222222222222';
    private const ENTRY_CLOID = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CLOSE_ID = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testRestingEntryIsCanceledOnlyAfterIdentifierConfirmsTerminalCancel(): void
    {
        $fixture = $this->fixture([$this->lifecycle('open'), $this->lifecycle('canceled')], [$this->acceptedCancel()]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('entry_canceled', $result->outcome);
        self::assertSame([self::ENTRY_CLOID, self::ENTRY_CLOID], $fixture->lookup->identifiers());
        self::assertSame('cancelByCloid', $fixture->signed->actions[0]['action']['type']);
        self::assertSame([1_000], $fixture->nonce->issued);
        self::assertSame([], $fixture->trip->reasons);
    }

    public function testRejectedEntryReturnsWithoutMutation(): void
    {
        $fixture = $this->fixture([$this->lifecycle('rejected')]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('entry_rejected', $result->outcome);
        self::assertSame([], $fixture->signed->actions);
        self::assertSame([], $fixture->nonce->issued);
    }

    public function testFilledEntryClosesProvenQuantityAndConfirmsCloseByIdentifier(): void
    {
        $closeCloid = (new HyperliquidActionFactory())->cloid(self::CLOSE_ID);
        $fixture = $this->fixture(
            [
                $this->lifecycle('filled'),
                null,
                null,
                null,
                $this->lifecycle('filled', oid: 99, cloid: $closeCloid, side: 'A'),
            ],
            [$this->acceptedOrder(99)],
        );

        $result = $fixture->service->compensate($this->context());

        self::assertSame('exposure_closed', $result->outcome);
        self::assertSame(1.0, $result->closedQuantity);
        self::assertSame([self::ENTRY_CLOID, self::CLOSE_ID, self::CLOSE_ID, self::CLOSE_ID, '99'], $fixture->lookup->identifiers());
        $close = $fixture->signed->actions[0]['action'];
        self::assertSame('order', $close['type']);
        self::assertTrue($close['orders'][0]['r']);
        self::assertSame('Ioc', $close['orders'][0]['t']['limit']['tif']);
        self::assertSame('1', $close['orders'][0]['s']);
        self::assertSame('24900', $close['orders'][0]['p']);
    }

    public function testAlreadyTrippedKillSwitchBlocksReconciliationAndMutationOnRerun(): void
    {
        $fixture = $this->fixture([]);
        $fixture->trip->initiallyTripped = true;

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame([], $fixture->lookup->calls);
        self::assertSame([], $fixture->signed->actions);
        self::assertSame([], $fixture->nonce->issued);
        self::assertSame([], $fixture->trip->reasons);
    }

    public function testExistingFilledDeterministicCloseReturnsClosedWithoutResubmission(): void
    {
        $fixture = $this->fixture([
            $this->lifecycle('filled'),
            $this->lifecycle('filled', oid: 99, cloid: self::CLOSE_ID, side: 'A'),
        ]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('exposure_closed', $result->outcome);
        self::assertSame([], $fixture->signed->actions);
        self::assertSame([], $fixture->nonce->issued);
        self::assertSame([self::ENTRY_CLOID, self::CLOSE_ID], $fixture->lookup->identifiers());
    }

    public function testExistingNonterminalDeterministicCloseTripsWithoutResubmission(): void
    {
        $fixture = $this->fixture([
            $this->lifecycle('filled'),
            $this->lifecycle('open', oid: 99, cloid: self::CLOSE_ID, side: 'A'),
        ]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame([], $fixture->signed->actions);
        self::assertSame([], $fixture->nonce->issued);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testAmbiguousCloseSubmissionReconcilesFilledCloseByDeterministicCloid(): void
    {
        $fixture = $this->fixture([
            $this->lifecycle('filled'),
            null,
            null,
            null,
            $this->lifecycle('filled', oid: 99, cloid: self::CLOSE_ID, side: 'A'),
        ], [$this->ambiguous('order')]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('exposure_closed', $result->outcome);
        self::assertCount(1, $fixture->signed->actions);
        self::assertSame([1_000], $fixture->nonce->issued);
        self::assertSame([], $fixture->trip->reasons);
    }

    public function testKillSwitchReadFailureFailsClosedBeforeMutation(): void
    {
        $fixture = $this->fixture([]);
        $fixture->trip->readFailure = new \RuntimeException('state_backend_unavailable');

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame([], $fixture->lookup->calls);
        self::assertSame([], $fixture->signed->actions);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testAlreadyCanceledEntryWithProvenFillClosesOnlyThatQuantity(): void
    {
        $closeCloid = (new HyperliquidActionFactory())->cloid(self::CLOSE_ID);
        $fixture = $this->fixture(
            [
                $this->lifecycle('canceled', remaining: '0.75'),
                null,
                null,
                null,
                $this->lifecycle('filled', oid: 99, cloid: $closeCloid, original: '0.25', side: 'A'),
            ],
            [$this->acceptedOrder(99)],
        );

        $result = $fixture->service->compensate($this->context());

        self::assertSame('exposure_closed', $result->outcome);
        self::assertSame(0.25, $result->closedQuantity);
        self::assertSame('0.25', $fixture->signed->actions[0]['action']['orders'][0]['s']);
    }

    public function testPartialFillCancelsThenClosesExactlyProvenFilledQuantityWithFreshNonces(): void
    {
        $closeCloid = (new HyperliquidActionFactory())->cloid(self::CLOSE_ID);
        $fixture = $this->fixture(
            [
                $this->lifecycle('open', remaining: '0.6'),
                $this->lifecycle('canceled', remaining: '0.6'),
                null,
                null,
                null,
                $this->lifecycle('filled', oid: 99, cloid: $closeCloid, original: '0.4', remaining: '0', side: 'A'),
            ],
            [$this->acceptedCancel(), $this->acceptedOrder(99)],
        );

        $result = $fixture->service->compensate($this->context());

        self::assertSame('exposure_closed', $result->outcome);
        self::assertSame(0.4, $result->closedQuantity);
        self::assertSame([1_000, 1_001], $fixture->nonce->issued);
        self::assertSame('0.4', $fixture->signed->actions[1]['action']['orders'][0]['s']);
    }

    public function testFillRaceDuringCancelClosesNewlyProvenExposure(): void
    {
        $closeCloid = (new HyperliquidActionFactory())->cloid(self::CLOSE_ID);
        $fixture = $this->fixture(
            [
                $this->lifecycle('open'),
                $this->lifecycle('filled'),
                null,
                null,
                null,
                $this->lifecycle('filled', oid: 99, cloid: $closeCloid, side: 'A'),
            ],
            [$this->acceptedCancel(), $this->acceptedOrder(99)],
        );

        $result = $fixture->service->compensate($this->context());

        self::assertSame('exposure_closed', $result->outcome);
        self::assertCount(2, $fixture->signed->actions);
        self::assertSame([1_000, 1_001], $fixture->nonce->issued);
    }

    public function testTriesOidBeforeDistinctCloidFallback(): void
    {
        $fixture = $this->fixture([null, $this->lifecycle('rejected')]);

        $result = $fixture->service->compensate($this->context(oid: '42'));

        self::assertSame('entry_rejected', $result->outcome);
        self::assertSame(['42', self::ENTRY_CLOID], $fixture->lookup->identifiers());
    }

    public function testEntryLifecycleMustBindExpectedOidAndCloidTogether(): void
    {
        $fixture = $this->fixture([
            $this->lifecycleEvidence(
                HyperliquidLifecycleStatus::FILLED,
                1.0,
                1.0,
                oid: 42,
                cloid: '0xcccccccccccccccccccccccccccccccc',
            ),
        ]);

        $result = $fixture->service->compensate($this->context(oid: '42'));

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame([], $fixture->signed->actions);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testUnknownOidExhaustsExactlyThreeCyclesAndSixReads(): void
    {
        $fixture = $this->fixture([null, null, null, null, null, null]);

        $result = $fixture->service->compensate($this->context(oid: '42'));

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(['42', self::ENTRY_CLOID, '42', self::ENTRY_CLOID, '42', self::ENTRY_CLOID], $fixture->lookup->identifiers());
        self::assertSame([250, 250], $fixture->sleeper->milliseconds);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testIdentifierTransportFailuresUseBoundedReconciliationThenTrip(): void
    {
        $failure = new \RuntimeException('transport_failed');
        $fixture = $this->fixture([$failure, $failure, $failure]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertCount(3, $fixture->lookup->calls);
        self::assertSame([250, 250], $fixture->sleeper->milliseconds);
        self::assertCount(1, $fixture->trip->reasons);
        self::assertSame(HyperliquidCompensationReasonCode::PROVIDER_RUNTIME_FAILURE, $result->reasonCode);
    }

    public function testLookupLogicExceptionTripsOnceAndIsRethrown(): void
    {
        $fixture = $this->fixture([new \LogicException('programmer_invariant')]);

        try {
            $fixture->service->compensate($this->context());
            self::fail('Expected LogicException');
        } catch (\LogicException $exception) {
            self::assertSame('programmer_invariant', $exception->getMessage());
        }

        self::assertSame(1, $fixture->trip->tripAttempts);
        self::assertCount(1, $fixture->lookup->calls);
    }

    public function testSleeperFailureTripsWithNormalizedReason(): void
    {
        $fixture = $this->fixture([null]);
        $fixture->sleeper->failure = new \RuntimeException('clock_backend_secret_detail');

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(HyperliquidCompensationReasonCode::SLEEPER_FAILURE, $result->reasonCode);
        self::assertSame(1, $fixture->trip->tripAttempts);
        self::assertStringNotContainsString('secret_detail', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testRejectedMissingOrderCancelReconcilesFillRaceAndClosesProvenExposure(): void
    {
        $closeCloid = (new HyperliquidActionFactory())->cloid(self::CLOSE_ID);
        $fixture = $this->fixture(
            [
                $this->lifecycle('open'),
                $this->lifecycle('filled'),
                null,
                null,
                null,
                $this->lifecycle('filled', oid: 99, cloid: $closeCloid, side: 'A'),
            ],
            [$this->rejectedMissingOrderCancel(), $this->acceptedOrder(99)],
        );

        $result = $fixture->service->compensate($this->context());

        self::assertSame('exposure_closed', $result->outcome);
        self::assertSame(1.0, $result->closedQuantity);
        self::assertCount(2, $fixture->signed->actions);
        self::assertTrue($fixture->signed->actions[1]['action']['orders'][0]['r']);
        self::assertSame([1_000, 1_001], $fixture->nonce->issued);
        self::assertSame([], $fixture->trip->reasons);
    }

    public function testAmbiguousCancelReconcilesBeforeQuarantiningStillUnknownState(): void
    {
        $fixture = $this->fixture(
            [$this->lifecycle('open'), null, null, null],
            [$this->ambiguous('cancelByCloid')],
        );

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(HyperliquidCompensationReasonCode::CANCEL_SUBMISSION_UNCONFIRMED, $result->reasonCode);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
        self::assertCount(4, $fixture->lookup->calls);
        self::assertCount(1, $fixture->signed->actions);
        self::assertSame([250, 250], $fixture->sleeper->milliseconds);
    }

    public function testCancelAcceptedRequiresCancelByCloidActionType(): void
    {
        $fixture = $this->fixture([$this->lifecycle('open')], [
            new HyperliquidSignedActionResult('cancel', 'accepted', [['kind' => 'success']], null, 'corr-1'),
        ]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
        self::assertCount(1, $fixture->lookup->calls);
    }

    public function testCancelAcceptedRequiresExactlyOneSuccessStatus(): void
    {
        $fixture = $this->fixture([$this->lifecycle('open')], [
            new HyperliquidSignedActionResult(
                'cancelByCloid',
                'accepted',
                [['kind' => 'success'], ['kind' => 'success']],
                null,
                'corr-1',
            ),
        ]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testAcceptedCancelWhoseConfirmationNeverBecomesTerminalTripsOnce(): void
    {
        $fixture = $this->fixture([
            $this->lifecycle('open'),
            $this->lifecycle('open'),
            $this->lifecycle('open'),
            $this->lifecycle('open'),
        ], [$this->acceptedCancel()]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
        self::assertSame([250, 250], $fixture->sleeper->milliseconds);
    }

    public function testAmbiguousCloseTripsExactlyOnce(): void
    {
        $fixture = $this->fixture([$this->lifecycle('filled')], [$this->ambiguous('order')]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testCloseAcceptedRequiresOrderActionTypeAndOneStatus(): void
    {
        $wrongAction = $this->fixture(
            [$this->lifecycle('filled'), null, null, null],
            [new HyperliquidSignedActionResult('cancelByCloid', 'accepted', [['kind' => 'success']], null, 'corr-1')],
        );
        $multipleRows = $this->fixture(
            [$this->lifecycle('filled'), null, null, null],
            [new HyperliquidSignedActionResult('order', 'accepted', [
                ['kind' => 'filled', 'oid' => 99],
                ['kind' => 'filled', 'oid' => 100],
            ], null, 'corr-1')],
        );

        self::assertSame('unknown_requires_resync', $wrongAction->service->compensate($this->context())->outcome);
        self::assertSame('unknown_requires_resync', $multipleRows->service->compensate($this->context())->outcome);
        self::assertCount(1, $wrongAction->trip->reasons);
        self::assertCount(1, $multipleRows->trip->reasons);
        self::assertCount(4, $wrongAction->lookup->calls);
        self::assertCount(4, $multipleRows->lookup->calls);
    }

    public function testCloseConfirmationMustBindReturnedOidAndDeterministicCloid(): void
    {
        $fixture = $this->fixture([
            $this->lifecycle('filled'),
            null,
            null,
            null,
            $this->lifecycleEvidence(
                HyperliquidLifecycleStatus::FILLED,
                1.0,
                1.0,
                oid: 99,
                cloid: '0xcccccccccccccccccccccccccccccccc',
            ),
        ], [$this->acceptedOrder(99)]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testCloseTransportFailureConsumesOneFreshNonceAndTripsOnce(): void
    {
        $fixture = $this->fixture([$this->lifecycle('filled')], [new \RuntimeException('transport_failed')]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame([1_000], $fixture->nonce->issued);
        self::assertCount(1, $fixture->trip->reasons);
    }

    public function testNonceConflictTripsWithNormalizedReasonWithoutSubmitting(): void
    {
        $fixture = $this->fixture([$this->lifecycle('filled'), null, null, null]);
        $fixture->nonce->failure = new HyperliquidNonceScopeConflictException();

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(HyperliquidCompensationReasonCode::NONCE_FAILURE, $result->reasonCode);
        self::assertSame([], $fixture->signed->actions);
        self::assertSame(1, $fixture->trip->tripAttempts);
    }

    public function testSignedClientLogicExceptionTripsOnceAndIsRethrown(): void
    {
        $fixture = $this->fixture([$this->lifecycle('open')], [new \LogicException('invalid_client_contract')]);

        try {
            $fixture->service->compensate($this->context());
            self::fail('Expected LogicException');
        } catch (\LogicException $exception) {
            self::assertSame('invalid_client_contract', $exception->getMessage());
        }

        self::assertSame(1, $fixture->trip->tripAttempts);
    }

    public function testSignedClientTypeErrorAfterPotentialMutationTripsOnceAndIsRethrown(): void
    {
        $typeError = new \TypeError('collaborator_return_contract_broken');
        $fixture = $this->fixture([$this->lifecycle('open')], [$typeError]);

        try {
            $fixture->service->compensate($this->context());
            self::fail('Expected TypeError');
        } catch (\TypeError $exception) {
            self::assertSame($typeError, $exception);
        }

        self::assertSame([1_000], $fixture->nonce->issued);
        self::assertCount(1, $fixture->signed->actions);
        self::assertSame(1, $fixture->trip->tripAttempts);
    }

    public function testTripPersistenceFailurePropagatesAfterExactlyOneAttempt(): void
    {
        $fixture = $this->fixture([null, null, null]);
        $fixture->trip->tripFailure = new \RuntimeException('durable_trip_write_failed');

        try {
            $fixture->service->compensate($this->context());
            self::fail('Expected durable trip failure');
        } catch (\RuntimeException $exception) {
            self::assertSame('durable_trip_write_failed', $exception->getMessage());
        }

        self::assertSame(1, $fixture->trip->tripAttempts);
        self::assertSame([], $fixture->trip->reasons);
    }

    public function testIocPartialCloseIsUnresolvedAndTripsOnce(): void
    {
        $closeCloid = (new HyperliquidActionFactory())->cloid(self::CLOSE_ID);
        $fixture = $this->fixture(
            [$this->lifecycle('filled'), $this->lifecycle('open', oid: 99, cloid: $closeCloid, remaining: '0.25', side: 'A')],
            [$this->acceptedOrder(99)],
        );

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    /** @return iterable<string, array{HyperliquidLifecycleStatus, float}> */
    public static function contradictoryEntryLifecycleProvider(): iterable
    {
        yield 'rejected with fill' => [HyperliquidLifecycleStatus::REJECTED, 0.001];
        yield 'canceled over expected' => [HyperliquidLifecycleStatus::CANCELED, 1.001];
        yield 'filled partial' => [HyperliquidLifecycleStatus::FILLED, 0.999];
        yield 'filled over expected' => [HyperliquidLifecycleStatus::FILLED, 1.001];
        yield 'open with fill' => [HyperliquidLifecycleStatus::OPEN, 0.001];
        yield 'accepted with fill' => [HyperliquidLifecycleStatus::ACCEPTED, 0.001];
        yield 'partial with zero' => [HyperliquidLifecycleStatus::PARTIALLY_FILLED, 0.0];
        yield 'partial at expected' => [HyperliquidLifecycleStatus::PARTIALLY_FILLED, 1.0];
    }

    #[DataProvider('contradictoryEntryLifecycleProvider')]
    public function testContradictoryEntryLifecycleTripsWithoutMutation(
        HyperliquidLifecycleStatus $status,
        float $filledQuantity,
    ): void {
        $fixture = $this->fixture([$this->lifecycleEvidence($status, 1.0, $filledQuantity)]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame([], $fixture->signed->actions);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testSmallRepresentableQuantityClosesAtExactAssetPrecision(): void
    {
        $fixture = $this->fixture([
            $this->lifecycleEvidence(HyperliquidLifecycleStatus::FILLED, 0.000001, 0.000001),
            null,
            null,
            null,
            $this->lifecycleEvidence(HyperliquidLifecycleStatus::FILLED, 0.000001, 0.000001, oid: 99, cloid: self::CLOSE_ID),
        ], [$this->acceptedOrder(99)]);

        $result = $fixture->service->compensate($this->contextFrom([
            'quantity' => 0.000001,
            'quantityPrecision' => 6,
            'quantityStep' => '0.000001',
        ]));

        self::assertSame('exposure_closed', $result->outcome);
        self::assertSame('0.000001', $fixture->signed->actions[0]['action']['orders'][0]['s']);
    }

    /** @return iterable<string, array{float}> */
    public static function inexactCloseQuantityProvider(): iterable
    {
        yield 'one lot underfill' => [0.999];
        yield 'one lot overfill' => [1.001];
    }

    #[DataProvider('inexactCloseQuantityProvider')]
    public function testCloseConfirmationRejectsOneLotDifference(float $confirmedQuantity): void
    {
        $fixture = $this->fixture([
            $this->lifecycleEvidence(HyperliquidLifecycleStatus::FILLED, 1.0, 1.0),
            null,
            null,
            null,
            $this->lifecycleEvidence(HyperliquidLifecycleStatus::FILLED, $confirmedQuantity, $confirmedQuantity, oid: 99, cloid: self::CLOSE_ID),
        ], [$this->acceptedOrder(99)]);

        $result = $fixture->service->compensate($this->context());

        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertSame(['hyperliquid_compensation_unconfirmed'], $fixture->trip->reasons);
    }

    public function testCloseConfirmationFailureTripsOnceWithRedactedContextAndNoRawResultPayload(): void
    {
        $secret = 'sk-test_' . str_repeat('z', 32);
        $fixture = $this->fixture([$this->lifecycle('filled'), null, null, null], [$this->acceptedOrder(99)]);

        $result = $fixture->service->compensate($this->context(audit: [
            'correlation_id' => 'corr-1',
            'token' => $secret,
            'message' => 'Authorization: Bearer ' . $secret,
        ]));

        $encodedResult = json_encode($result, JSON_THROW_ON_ERROR);
        $encodedTrip = json_encode($fixture->trip->contexts, JSON_THROW_ON_ERROR);
        self::assertSame('unknown_requires_resync', $result->outcome);
        self::assertCount(1, $fixture->trip->contexts);
        self::assertStringNotContainsString($secret, $encodedResult);
        self::assertStringNotContainsString($secret, $encodedTrip);
        self::assertArrayNotHasKey('token', $fixture->trip->contexts[0]);
        self::assertArrayNotHasKey('rawPayload', get_object_vars($result));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidContextProvider(): iterable
    {
        yield 'account' => [['accountAddress' => 'not-an-address'], 'hyperliquid_compensation_account_invalid'];
        yield 'entry cloid' => [['entryWireCloid' => 'entry-plain'], 'hyperliquid_compensation_entry_cloid_invalid'];
        yield 'oid' => [['entryExchangeOrderId' => 'oid-42'], 'hyperliquid_compensation_entry_oid_invalid'];
        yield 'oid overflow' => [['entryExchangeOrderId' => str_repeat('9', 40)], 'hyperliquid_compensation_entry_oid_invalid'];
        yield 'quantity zero' => [['quantity' => 0.0], 'hyperliquid_compensation_quantity_invalid'];
        yield 'quantity nan' => [['quantity' => NAN], 'hyperliquid_compensation_quantity_invalid'];
        yield 'quantity not representable' => [[
            'quantity' => 1.0001,
            'quantityPrecision' => 3,
            'quantityStep' => '0.001',
        ], 'hyperliquid_compensation_quantity_invalid'];
        yield 'quantity step mismatch' => [[
            'quantityPrecision' => 3,
            'quantityStep' => '0.01',
        ], 'hyperliquid_compensation_quantity_step_invalid'];
        yield 'quantity precision invalid' => [[
            'quantityPrecision' => -1,
            'quantityStep' => '1',
        ], 'hyperliquid_compensation_quantity_step_invalid'];
        yield 'price zero' => [['emergencyCloseSlippageCapPrice' => 0.0], 'hyperliquid_compensation_close_price_invalid'];
        yield 'price infinity' => [['emergencyCloseSlippageCapPrice' => INF], 'hyperliquid_compensation_close_price_invalid'];
        yield 'close id' => [['closeClientOrderId' => ''], 'hyperliquid_compensation_close_client_id_invalid'];
        yield 'close id is not wire cloid' => [['closeClientOrderId' => 'close-plain'], 'hyperliquid_compensation_close_client_id_invalid'];
        yield 'close cloid collides with entry' => [['closeClientOrderId' => self::ENTRY_CLOID], 'hyperliquid_compensation_close_client_id_invalid'];
        yield 'nonce signer address' => [[
            'nonceScope' => new HyperliquidNonceScope('testnet', 'testnet', self::ACCOUNT, 'agent-invalid'),
        ], 'hyperliquid_compensation_nonce_scope_invalid'];
        yield 'correlation whitespace' => [['correlationId' => 'corr unsafe'], 'hyperliquid_compensation_correlation_id_invalid'];
        yield 'correlation control' => [['correlationId' => "corr\nunsafe"], 'hyperliquid_compensation_correlation_id_invalid'];
        yield 'correlation assignment' => [['correlationId' => 'token=hidden'], 'hyperliquid_compensation_correlation_id_invalid'];
        yield 'correlation jwt' => [[
            'correlationId' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature',
        ], 'hyperliquid_compensation_correlation_id_invalid'];
        yield 'correlation private key' => [[
            'correlationId' => '0x' . str_repeat('a', 64),
        ], 'hyperliquid_compensation_correlation_id_invalid'];
        yield 'correlation token shape' => [[
            'correlationId' => 'sk-test_' . str_repeat('z', 32),
        ], 'hyperliquid_compensation_correlation_id_invalid'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidContextProvider')]
    public function testRejectsInvalidCompensationContext(array $overrides, string $message): void
    {
        $this->expectExceptionMessage($message);

        $this->contextFrom($overrides);
    }

    /** @param array<string, mixed> $audit */
    private function context(?string $oid = null, array $audit = ['correlation_id' => 'corr-1']): HyperliquidCompensationContext
    {
        return $this->contextFrom(['entryExchangeOrderId' => $oid, 'redactedAuditContext' => $audit]);
    }

    /** @param array<string, mixed> $overrides */
    private function contextFrom(array $overrides): HyperliquidCompensationContext
    {
        $values = array_replace([
            'accountAddress' => self::ACCOUNT,
            'assetId' => 0,
            'symbol' => 'BTCUSDT',
            'positionSide' => ExchangePositionSide::LONG,
            'entryWireCloid' => self::ENTRY_CLOID,
            'entryExchangeOrderId' => null,
            'quantity' => 1.0,
            'quantityPrecision' => 3,
            'quantityStep' => '0.001',
            'closeClientOrderId' => self::CLOSE_ID,
            'nonceScope' => new HyperliquidNonceScope('testnet', 'testnet', self::ACCOUNT, self::AGENT),
            'correlationId' => 'corr-1',
            'marginMode' => 'cross',
            'leverage' => 3,
            'emergencyCloseSlippageCapPrice' => 24_900.0,
            'redactedAuditContext' => ['correlation_id' => 'corr-1'],
        ], $overrides);

        return new HyperliquidCompensationContext(...$values);
    }

    /** @param list<HyperliquidNormalizedOrderLifecycleDto|null|\Throwable> $lookups
     *  @param list<HyperliquidSignedActionResult|\Throwable> $submissions
     */
    private function fixture(array $lookups, array $submissions = []): CompensationFixture
    {
        $lookup = new SequenceLifecycleLookup($lookups);
        $signed = new RecordingSignedActionClient($submissions);
        $nonce = new RecordingNonceManager();
        $trip = new RecordingKillSwitchTrip();
        $sleeper = new RecordingCompensationSleeper();

        return new CompensationFixture(
            new HyperliquidCompensationService($lookup, $signed, new HyperliquidActionFactory(), $nonce, $trip, $sleeper),
            $lookup,
            $signed,
            $nonce,
            $trip,
            $sleeper,
        );
    }

    private function lifecycle(
        string $status,
        int $oid = 42,
        ?string $cloid = self::ENTRY_CLOID,
        string $original = '1',
        string $remaining = '0',
        string $side = 'B',
    ): HyperliquidNormalizedOrderLifecycleDto {
        if ($status === 'open' && $remaining === '0') {
            $remaining = $original;
        }

        return (new HyperliquidLifecycleNormalizer())->normalizeOrderLifecycle([[
            'coin' => 'BTC',
            'oid' => $oid,
            'cloid' => $cloid,
            'side' => $side,
            'reduceOnly' => $side === 'A',
            'sz' => $remaining,
            'origSz' => $original,
            'limitPx' => '25000',
            'orderType' => 'Limit',
            'status' => $status,
            'timestamp' => 1_767_225_600_000,
            'uTime' => 1_767_225_601_000,
        ]]);
    }

    private function lifecycleEvidence(
        HyperliquidLifecycleStatus $status,
        float $quantity,
        float $filledQuantity,
        int $oid = 42,
        string $cloid = self::ENTRY_CLOID,
    ): HyperliquidNormalizedOrderLifecycleDto {
        return new HyperliquidNormalizedOrderLifecycleDto(
            status: $status,
            symbol: 'BTCUSDT',
            exchangeOrderId: (string) $oid,
            clientOrderId: $cloid,
            side: $cloid === self::CLOSE_ID ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            quantity: $quantity,
            filledQuantity: $filledQuantity,
            remainingQuantity: max(0.0, $quantity - $filledQuantity),
            price: 25_000.0,
            averageFillPrice: $filledQuantity > 0.0 ? 25_000.0 : null,
            createdAt: new \DateTimeImmutable('@1767225600'),
            updatedAt: new \DateTimeImmutable('@1767225601'),
            fills: [],
            requiresResync: false,
            deduplicatedEventCount: 1,
            qualityFlags: [],
            redactedPayload: [],
        );
    }

    private function acceptedCancel(): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult('cancelByCloid', 'accepted', [['kind' => 'success']], null, 'corr-1');
    }

    private function rejectedMissingOrderCancel(): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult(
            'cancelByCloid',
            'rejected',
            [['kind' => 'error']],
            'exchange_status_error',
            'corr-1',
        );
    }

    private function acceptedOrder(int $oid): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult('order', 'accepted', [['kind' => 'filled', 'oid' => $oid]], null, 'corr-1');
    }

    private function ambiguous(string $action): HyperliquidSignedActionResult
    {
        return new HyperliquidSignedActionResult($action, 'ambiguous', [], 'exchange_timeout', 'corr-1');
    }
}

final readonly class CompensationFixture
{
    public function __construct(
        public HyperliquidCompensationService $service,
        public SequenceLifecycleLookup $lookup,
        public RecordingSignedActionClient $signed,
        public RecordingNonceManager $nonce,
        public RecordingKillSwitchTrip $trip,
        public RecordingCompensationSleeper $sleeper,
    ) {
    }
}

final class SequenceLifecycleLookup implements HyperliquidIdentifierLifecycleLookupInterface
{
    /** @var list<array{account: string, identifier: string, expected_oid: ?string, expected_cloid: string}> */
    public array $calls = [];

    /** @param list<HyperliquidNormalizedOrderLifecycleDto|null|\Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function lookup(
        string $accountAddress,
        string $identifier,
        ?string $expectedExchangeOrderId,
        string $expectedWireCloid,
    ): ?HyperliquidNormalizedOrderLifecycleDto {
        $this->calls[] = [
            'account' => $accountAddress,
            'identifier' => $identifier,
            'expected_oid' => $expectedExchangeOrderId,
            'expected_cloid' => $expectedWireCloid,
        ];
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    /** @return list<string> */
    public function identifiers(): array
    {
        return array_column($this->calls, 'identifier');
    }
}

final class RecordingSignedActionClient implements HyperliquidSignedActionClientInterface
{
    /** @var list<array{action: array<string, mixed>, nonce: int, correlation_id: string}> */
    public array $actions = [];

    /** @param list<HyperliquidSignedActionResult|\Throwable> $results */
    public function __construct(private array $results)
    {
    }

    public function submit(array $action, int $nonce, string $correlationId, ?int $expiresAfter = null): HyperliquidSignedActionResult
    {
        $this->actions[] = ['action' => $action, 'nonce' => $nonce, 'correlation_id' => $correlationId];

        $result = array_shift($this->results) ?? throw new \RuntimeException('unexpected_submit');
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    public function health(): bool
    {
        return true;
    }
}

final class RecordingNonceManager implements HyperliquidNonceManagerInterface
{
    /** @var list<int> */
    public array $issued = [];
    private int $next = 1_000;
    public ?\RuntimeException $failure = null;

    public function isReady(HyperliquidNonceScope $scope): bool
    {
        return true;
    }

    public function nextNonce(HyperliquidNonceScope $scope): int
    {
        if ($this->failure instanceof \RuntimeException) {
            throw $this->failure;
        }
        $this->issued[] = $this->next;

        return $this->next++;
    }

    public function recordObservedNonce(HyperliquidNonceScope $scope, int $nonce): void
    {
    }
}

final class RecordingKillSwitchTrip implements HyperliquidKillSwitchTripInterface
{
    /** @var list<string> */
    public array $reasons = [];
    /** @var list<array<string, mixed>> */
    public array $contexts = [];
    public bool $initiallyTripped = false;
    public ?\RuntimeException $readFailure = null;
    public ?\RuntimeException $tripFailure = null;
    public int $tripAttempts = 0;

    public function isTripped(): bool
    {
        if ($this->readFailure instanceof \RuntimeException) {
            throw $this->readFailure;
        }

        return $this->initiallyTripped || $this->reasons !== [];
    }

    public function trip(string $reason, array $auditContext): void
    {
        ++$this->tripAttempts;
        if ($this->tripFailure instanceof \RuntimeException) {
            throw $this->tripFailure;
        }
        $this->reasons[] = $reason;
        $this->contexts[] = $auditContext;
    }
}

final class RecordingCompensationSleeper implements HyperliquidCompensationSleeperInterface
{
    /** @var list<int> */
    public array $milliseconds = [];
    public ?\RuntimeException $failure = null;

    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($this->failure instanceof \RuntimeException) {
            throw $this->failure;
        }
        $this->milliseconds[] = $milliseconds;
    }
}
