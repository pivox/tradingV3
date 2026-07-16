<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Reconciliation;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\AbstractExchangeOrderEvent;
use App\Exchange\Event\AbstractExchangePositionEvent;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Event\ExchangeProtectionOrderCreated;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeExchangeWsClient;
use App\Exchange\Fake\FakePrivateWsException;
use App\Exchange\Fake\FakePrivateWsScenario;
use App\Exchange\Reconciliation\ExchangeReconciliationService;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use App\Exchange\Event\ExchangeEventNormalizerRegistry;
use App\Exchange\Ws\ExchangeWsIngestionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

#[CoversClass(ExchangeReconciliationService::class)]
#[CoversClass(ExchangeEventBus::class)]
#[CoversClass(FakeExchangeAdapter::class)]
#[CoversClass(ExchangeOrderFilled::class)]
#[CoversClass(ExchangeFillReceived::class)]
#[CoversClass(ExchangePositionUpdated::class)]
final class ExchangeReconciliationServiceTest extends TestCase
{
    public function testSnapshotProofAttestationHasNoStandalonePublicApi(): void
    {
        self::assertFalse(
            interface_exists('App\\Exchange\\Reconciliation\\ExchangeReconciliationSnapshotProofProviderInterface'),
            'The standalone snapshot-proof provider interface exposes attestation outside reconciliation orchestration.',
        );
        $orchestratorMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(
                \App\Exchange\Reconciliation\ExchangeReconciliationSnapshotProofOrchestratorInterface::class,
            ))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        self::assertSame(['reconcileWithSnapshotProof'], $orchestratorMethods);

        $adapterPublicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(FakeExchangeAdapter::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        self::assertNotContains(
            'attestReconciliationSnapshotProof',
            $adapterPublicMethods,
            'The Fake adapter must not expose standalone snapshot-proof attestation.',
        );

        $stateStoreAttestation = new \ReflectionMethod(
            FakeExchangeStateStore::class,
            'attestPrivateWsSnapshotProof',
        );
        self::assertTrue(
            $stateStoreAttestation->isPrivate(),
            'The state-store attestation operation must be private to real reconciliation orchestration.',
        );
    }

    public function testRestReconciliationProjectsMissedFillAndFlagsUnprotectedPosition(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $adapter->placeOrder($this->marketRequest());
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertSame(1, $result->ordersChecked);
        self::assertSame(1, $result->positionsChecked);
        self::assertSame(1, $result->fillsImported);
        self::assertSame(1, $result->unknownOrdersDetected);
        self::assertCount(1, $result->metadata['unprotected_positions'] ?? []);
        self::assertTrue($store->contains(ExchangeOrderFilled::class));
        self::assertTrue($store->contains(ExchangeFillReceived::class));
        self::assertTrue($store->contains(ExchangePositionUpdated::class));
    }

    public function testRestReconciliationClosesLocalPositionMissingFromSnapshot(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $store = new RecordingProjectionStore();
        $store->localOpenPositions = [[
            'symbol' => 'BTCUSDT',
            'side' => ExchangePositionSide::LONG,
            'size' => 10.0,
        ]];
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertSame(0, $result->positionsChecked);
        self::assertTrue($store->contains(ExchangePositionClosed::class));
    }

    public function testRestReconciliationDoesNotFlagPositionCoveredByStopLoss(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $adapter->placeOrder($this->marketRequest(attachedStopLossPrice: 24800.0));
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertSame([], $result->metadata['unprotected_positions'] ?? null);
        self::assertTrue($store->contains(ExchangeProtectionOrderCreated::class));
    }

    public function testRestReconciliationIgnoresTakeProfitWrongSideAndUndersizedProtectionForSlCoverage(): void
    {
        $position = $this->position(size: 2.0);
        $adapter = new SnapshotReconciliationAdapter(
            positions: [$position],
            orders: [
                $this->protectionOrder(
                    orderType: ExchangeOrderType::TAKE_PROFIT,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 2.0,
                    stopPrice: 26000.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::TRIGGER,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 2.0,
                    stopPrice: 26000.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::STOP_LOSS,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 2.0,
                    stopPrice: 26000.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::STOP_LOSS,
                    side: ExchangeOrderSide::BUY,
                    remainingQuantity: 2.0,
                    stopPrice: 24800.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::STOP_LOSS,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 1.0,
                    stopPrice: 24800.0,
                ),
            ],
        );
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertCount(1, $result->metadata['unprotected_positions'] ?? []);
        self::assertSame('BTCUSDT', $result->metadata['unprotected_positions'][0]['symbol'] ?? null);
    }

    public function testRestReconciliationAcceptsSplitStopLossCoverage(): void
    {
        $position = $this->position(size: 2.0);
        $adapter = new SnapshotReconciliationAdapter(
            positions: [$position],
            orders: [
                $this->protectionOrder(
                    orderType: ExchangeOrderType::STOP_LOSS,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 1.0,
                    stopPrice: 24800.0,
                ),
                $this->protectionOrder(
                    orderType: ExchangeOrderType::TRIGGER,
                    side: ExchangeOrderSide::SELL,
                    remainingQuantity: 1.0,
                    stopPrice: 24790.0,
                ),
            ],
        );
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertSame([], $result->metadata['unprotected_positions'] ?? null);
    }

    public function testRestReconciliationDoesNotTrustGenericTriggerWhenEntryPriceIsMissing(): void
    {
        $position = $this->position(size: 1.0, side: ExchangePositionSide::SHORT, entryPrice: 0.0);
        $adapter = new SnapshotReconciliationAdapter(
            positions: [$position],
            orders: [
                $this->protectionOrder(
                    orderType: ExchangeOrderType::TRIGGER,
                    side: ExchangeOrderSide::BUY,
                    positionSide: ExchangePositionSide::SHORT,
                    remainingQuantity: 1.0,
                    stopPrice: 20000.0,
                ),
            ],
        );
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );

        $result = $service->reconcile($adapter, 'BTCUSDT');

        self::assertCount(1, $result->metadata['unprotected_positions'] ?? []);
    }

    public function testPrivateWsGapReconcilesSnapshotBeforeCompletionAndResumesContiguously(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_private_ws_reconcile_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $adapter = $this->adapter($state);
            $adapter->placeOrder($this->marketRequest());
            $events = $state->events();
            $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
                'reconcile-v1',
                [$events[0], $events[2], $events[1]],
            ));

            $store = new RecordingProjectionStore();
            $bus = new ExchangeEventBus($store, new NullLogger());
            $ingestion = new ExchangeWsIngestionService(
                new ExchangeEventNormalizerRegistry([new FakeExchangeEventNormalizer()]),
                $bus,
                new NullLogger(),
            );

            try {
                $ingestion->drain(new FakeExchangeWsClient($state));
                self::fail('The out-of-order fixture must stop on its gap.');
            } catch (FakePrivateWsException $exception) {
                self::assertSame('fake_private_ws_sequence_gap', $exception->errorCode);
            }

            $restored = new FakeExchangeStateStore($stateFile);
            $restoredClient = new FakeExchangeWsClient($restored);
            self::assertTrue($restoredClient->requiresResync());
            self::assertSame('fake_private_ws_sequence_gap', $restoredClient->audit()['resync_reason']);

            $restoredAdapter = $this->adapter($restored);
            $reconciliation = new ExchangeReconciliationService(
                $bus,
                $store,
                $this->fixedClock(),
                new NullLogger(),
            );
            $reconciliationResult = $reconciliation->reconcile($restoredAdapter);

            self::assertSame([], $store->openOrders(Exchange::FAKE, MarketType::PERPETUAL));
            self::assertSame([[
                'symbol' => 'BTCUSDT',
                'side' => ExchangePositionSide::LONG,
                'size' => 10.0,
            ]], $store->openPositions(Exchange::FAKE, MarketType::PERPETUAL, 'BTCUSDT'));
            self::assertTrue($restoredClient->requiresResync());

            $restoredClient->completeSnapshotResync($reconciliationResult);
            self::assertFalse($restoredClient->requiresResync());
            self::assertSame(1, $restoredClient->audit()['resync_total']);
            self::assertSame(3, $restoredClient->audit()['next_delivery_index']);

            $restored->appendEvent(new FakeExchangeEvent(
                'order.created',
                'BTCUSDT',
                new \DateTimeImmutable('2026-01-01T00:00:04+00:00'),
            ));
            $resumed = $ingestion->drain($restoredClient);

            self::assertSame(1, $resumed->rawEventsRead);
            self::assertSame(2, $restoredClient->audit()['acknowledged_total']);
            self::assertSame('4', $restoredClient->audit()['last_acknowledged_sequence']);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
            @unlink($stateFile . '.private-ws-consumer.lock');
        }
    }

    public function testScenarioSnapshotCompletionAcceptsOnlySuccessfulGlobalReconciliation(): void
    {
        $btcOne = new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            ['event_sequence' => 1],
        );
        $ethTwo = new FakeExchangeEvent(
            'order.created',
            'ETHUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:02+00:00'),
            ['event_sequence' => 2],
        );
        $ethThree = new FakeExchangeEvent(
            'order.created',
            'ETHUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:03+00:00'),
            ['event_sequence' => 3],
        );
        $state = new FakeExchangeStateStore();
        foreach ([$btcOne, $ethTwo, $ethThree] as $event) {
            $state->appendEvent($event);
        }
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
            'global-reconciliation-v1',
            [$btcOne, $ethThree, $ethTwo],
        ));
        $first = $state->privateWsCurrentDelivery();
        self::assertNotNull($first);
        $state->acknowledgePrivateWsDelivery($first);
        $gap = $state->privateWsCurrentDelivery();
        self::assertNotNull($gap);
        $state->markPrivateWsGap('2', '3', $gap);

        $client = new FakeExchangeWsClient($state);
        $auditBefore = $client->audit();
        try {
            $client->completeSnapshotResync();
            self::fail('Scenario snapshot completion requires an explicit reconciliation result.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_global_reconciliation_required', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());

        $failedGlobal = new ExchangeReconciliationResult(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: null,
            startedAt: $this->fixedClock()->now(),
            completedAt: $this->fixedClock()->now(),
            errors: ['snapshot_projection_failed'],
        );
        try {
            $client->completeSnapshotResync($failedGlobal);
            self::fail('A failed global reconciliation must not complete scenario resync.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_reconciliation_failed', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());

        $store = new RecordingProjectionStore();
        $reconciliation = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );
        $adapter = $this->adapter($state);
        $btcOnly = $reconciliation->reconcile($adapter, 'BTCUSDT');
        try {
            $client->completeSnapshotResync($btcOnly);
            self::fail('BTC-only reconciliation must not advance pending ETH deliveries.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_global_reconciliation_required', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());
        self::assertSame('ETHUSDT', $state->privateWsCurrentDelivery()?->event->symbol);

        $global = $reconciliation->reconcile($adapter);
        $client->completeSnapshotResync($global);

        self::assertFalse($client->requiresResync());
        self::assertSame(3, $client->audit()['next_delivery_index']);
        self::assertSame(1, $client->audit()['resync_total']);
    }

    public function testScenarioSnapshotCompletionLeavesPostSnapshotEventEligibleForPrivateWsDelivery(): void
    {
        $state = new FakeExchangeStateStore();
        $adapter = $this->adapter($state);
        $adapter->placeOrder($this->marketRequest());
        $events = $state->events();
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
            'snapshot-watermark-v1',
            [$events[0], $events[2], $events[1]],
        ));

        $store = new RecordingProjectionStore();
        $ingestion = new ExchangeWsIngestionService(
            new ExchangeEventNormalizerRegistry([new FakeExchangeEventNormalizer()]),
            new ExchangeEventBus($store, new NullLogger()),
            new NullLogger(),
        );
        $client = new FakeExchangeWsClient($state);
        try {
            $ingestion->drain($client);
            self::fail('The out-of-order fixture must stop on its gap.');
        } catch (FakePrivateWsException $exception) {
            self::assertSame('fake_private_ws_sequence_gap', $exception->errorCode);
        }

        $reconciliation = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );
        $snapshotResult = $reconciliation->reconcile($adapter);

        $postSnapshotPayload = $events[0]->payload;
        unset($postSnapshotPayload['event_sequence']);
        $state->appendEvent(new FakeExchangeEvent(
            $events[0]->type,
            $events[0]->symbol,
            new \DateTimeImmutable('2026-01-01T00:00:04+00:00'),
            $postSnapshotPayload,
        ));

        $client->completeSnapshotResync($snapshotResult);
        self::assertSame(3, $client->audit()['next_delivery_index']);

        $resumed = $ingestion->drain($client);

        self::assertSame(1, $resumed->rawEventsRead);
        self::assertSame(4, $client->audit()['next_delivery_index']);
        self::assertSame('4', $client->audit()['last_acknowledged_sequence']);
    }

    public function testScenarioSnapshotCompletionRejectsMissingMalformedAndStaleProofsWithoutAuditMutation(): void
    {
        [$state, $client] = $this->stateWithPrivateWsGap('proof-validation-v1');
        $auditBefore = $client->audit();
        $now = $this->fixedClock()->now();
        $missingProof = new ExchangeReconciliationResult(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: null,
            startedAt: $now,
            completedAt: $now,
        );

        try {
            $client->completeSnapshotResync($missingProof);
            self::fail('A manually constructed result without a snapshot proof must fail closed.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_proof_required', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());

        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );
        $adapter = $this->adapter($state);
        $firstProof = $service->reconcile($adapter);
        $proof = $firstProof->metadata['fake_private_ws_snapshot_proof'] ?? null;
        self::assertIsArray($proof);
        self::assertSame('completed', $proof['status'] ?? null);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) ($proof['attestation_id'] ?? ''));

        $malformed = $this->reconciliationWithMetadata($firstProof, [
            'fake_private_ws_snapshot_proof' => ['schema_version' => 'malformed'],
        ]);
        try {
            $client->completeSnapshotResync($malformed);
            self::fail('A malformed snapshot proof must fail closed.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_proof_invalid', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());

        $wrongScenarioProof = $proof;
        $wrongScenarioProof['scenario_id'] = 'another-scenario';
        $wrongScenario = $this->reconciliationWithMetadata($firstProof, [
            'fake_private_ws_snapshot_proof' => $wrongScenarioProof,
        ]);
        try {
            $client->completeSnapshotResync($wrongScenario);
            self::fail('A proof for another scenario must fail closed.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_proof_stale', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());

        $wrongCycleProof = $proof;
        $wrongCycleProof['resync_cycle_id'] = str_repeat('f', 64);
        $wrongCycle = $this->reconciliationWithMetadata($firstProof, [
            'fake_private_ws_snapshot_proof' => $wrongCycleProof,
        ]);
        try {
            $client->completeSnapshotResync($wrongCycle);
            self::fail('A proof for another resync cycle must fail closed.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_proof_stale', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());

        $latestProof = $service->reconcile($adapter);
        try {
            $client->completeSnapshotResync($firstProof);
            self::fail('Issuing a newer snapshot proof must invalidate the previous proof.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_proof_stale', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());

        $client->completeSnapshotResync($latestProof);
        self::assertFalse($client->requiresResync());
    }

    public function testCapturedPendingProofCannotCompleteResyncThroughFabricatedResult(): void
    {
        [$state, $client] = $this->stateWithPrivateWsGap('pending-proof-bypass-v1');
        $pendingProof = $state->capturePrivateWsSnapshotProof();
        self::assertIsArray($pendingProof);
        self::assertSame('pending', $pendingProof['status']);
        self::assertNull($pendingProof['attestation_id'] ?? null);
        $now = $this->fixedClock()->now();
        $fabricated = new ExchangeReconciliationResult(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: null,
            startedAt: $now,
            completedAt: $now,
            metadata: ['fake_private_ws_snapshot_proof' => $pendingProof],
        );
        $auditBefore = $client->audit();
        $deliveryBefore = $state->privateWsCurrentDelivery();

        try {
            $client->completeSnapshotResync($fabricated);
            self::fail('A merely captured pending proof must not attest successful reconciliation.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_proof_pending', $exception->getMessage());
        }

        self::assertSame($auditBefore, $client->audit());
        self::assertEquals($deliveryBefore, $state->privateWsCurrentDelivery());
    }

    public function testProjectionFailureLeavesOnlyAnUnusablePendingProof(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_private_ws_failed_reconciliation_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $adapter = $this->adapter($state);
            $adapter->placeOrder($this->marketRequest());
            $events = $state->events();
            $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
                'failed-reconciliation-v1',
                [$events[0], $events[2], $events[1]],
            ));
            $first = $state->privateWsCurrentDelivery();
            self::assertNotNull($first);
            $state->acknowledgePrivateWsDelivery($first);
            $gap = $state->privateWsCurrentDelivery();
            self::assertNotNull($gap);
            $state->markPrivateWsGap('2', '3', $gap);
            $client = new FakeExchangeWsClient($state);
            $auditBefore = $client->audit();

            $store = new RecordingProjectionStore();
            $store->failProjection = true;
            $service = new ExchangeReconciliationService(
                new ExchangeEventBus($store, new NullLogger()),
                $store,
                $this->fixedClock(),
                new NullLogger(),
            );
            try {
                $service->reconcile($adapter);
                self::fail('The reconciliation projection must fail before proof attestation.');
            } catch (\RuntimeException $exception) {
                self::assertSame('forced_reconciliation_projection_failure', $exception->getMessage());
            }

            $envelope = unserialize((string) file_get_contents($stateFile), ['allowed_classes' => true]);
            self::assertIsArray($envelope);
            $pendingProof = $envelope['payload']['privateWs']['snapshot_proof'] ?? null;
            self::assertIsArray($pendingProof);
            self::assertSame('pending', $pendingProof['status'] ?? null);
            self::assertNull($pendingProof['attestation_id'] ?? null);
            $now = $this->fixedClock()->now();
            $fabricated = new ExchangeReconciliationResult(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                symbol: null,
                startedAt: $now,
                completedAt: $now,
                metadata: ['fake_private_ws_snapshot_proof' => $pendingProof],
            );

            try {
                $client->completeSnapshotResync($fabricated);
                self::fail('A failed reconciliation must not leave a completion-capable proof.');
            } catch (\LogicException $exception) {
                self::assertSame('fake_private_ws_snapshot_proof_pending', $exception->getMessage());
            }
            self::assertSame($auditBefore, $client->audit());
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
            @unlink($stateFile . '.private-ws-consumer.lock');
        }
    }

    public function testNewPrivateWsResyncCycleInvalidatesCompletedCycleProof(): void
    {
        [$state, $client] = $this->stateWithPrivateWsGap('proof-cycle-v1');
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );
        $adapter = $this->adapter($state);
        $completedCycleProof = $service->reconcile($adapter);
        $client->completeSnapshotResync($completedCycleProof);

        $state->appendEvent(new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:05+00:00'),
            ['event_sequence' => 5],
        ));
        try {
            iterator_to_array($client->drainPrivateEvents());
            self::fail('Sequence five must open a new resync cycle when four is missing.');
        } catch (FakePrivateWsException $exception) {
            self::assertSame('fake_private_ws_sequence_gap', $exception->errorCode);
        }

        $auditBefore = $client->audit();
        try {
            $client->completeSnapshotResync($completedCycleProof);
            self::fail('A proof from the completed cycle must not complete a new resync cycle.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_proof_stale', $exception->getMessage());
        }
        self::assertSame($auditBefore, $client->audit());

        $client->completeSnapshotResync($service->reconcile($adapter));
        self::assertFalse($client->requiresResync());
    }

    public function testSnapshotBoundProofSurvivesRestartAndRetainsItsCapturedWatermark(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_private_ws_snapshot_proof_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $adapter = $this->adapter($state);
            $adapter->placeOrder($this->marketRequest());
            $events = $state->events();
            $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
                'persisted-snapshot-proof-v1',
                [$events[0], $events[2], $events[1]],
            ));
            $first = $state->privateWsCurrentDelivery();
            self::assertNotNull($first);
            $state->acknowledgePrivateWsDelivery($first);
            $gap = $state->privateWsCurrentDelivery();
            self::assertNotNull($gap);
            $state->markPrivateWsGap('2', '3', $gap);

            $store = new RecordingProjectionStore();
            $service = new ExchangeReconciliationService(
                new ExchangeEventBus($store, new NullLogger()),
                $store,
                $this->fixedClock(),
                new NullLogger(),
            );
            $snapshotResult = $service->reconcile($adapter);

            $restored = new FakeExchangeStateStore($stateFile);
            $postSnapshotPayload = $events[0]->payload;
            unset($postSnapshotPayload['event_sequence']);
            $restored->appendEvent(new FakeExchangeEvent(
                $events[0]->type,
                $events[0]->symbol,
                new \DateTimeImmutable('2026-01-01T00:00:04+00:00'),
                $postSnapshotPayload,
            ));

            $restartedAgain = new FakeExchangeStateStore($stateFile);
            $client = new FakeExchangeWsClient($restartedAgain);
            $client->completeSnapshotResync($snapshotResult);

            self::assertSame(3, $client->audit()['next_delivery_index']);
            self::assertSame('4', $restartedAgain->privateWsCurrentDelivery()?->sequence);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
            @unlink($stateFile . '.private-ws-consumer.lock');
        }
    }

    public function testSnapshotProofDoesNotCoverDeliveryAppendedLaterWithAnOlderSequence(): void
    {
        [$state, $client] = $this->stateWithPrivateWsGap('proof-delivery-boundary-v1');
        $store = new RecordingProjectionStore();
        $service = new ExchangeReconciliationService(
            new ExchangeEventBus($store, new NullLogger()),
            $store,
            $this->fixedClock(),
            new NullLogger(),
        );
        $snapshotResult = $service->reconcile($this->adapter($state));

        $state->appendEvent($state->events()[0]);
        $client->completeSnapshotResync($snapshotResult);

        self::assertSame(3, $client->audit()['next_delivery_index']);
        self::assertSame('1', $state->privateWsCurrentDelivery()?->sequence);

        iterator_to_array($client->drainPrivateEvents());
        self::assertSame(1, $client->audit()['duplicate_total']);
        self::assertSame(4, $client->audit()['next_delivery_index']);
    }

    /**
     * @return array{FakeExchangeStateStore,FakeExchangeWsClient}
     */
    private function stateWithPrivateWsGap(string $scenarioId): array
    {
        $one = new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:01+00:00'),
            ['event_sequence' => 1],
        );
        $two = new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:02+00:00'),
            ['event_sequence' => 2],
        );
        $three = new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:03+00:00'),
            ['event_sequence' => 3],
        );
        $state = new FakeExchangeStateStore();
        foreach ([$one, $two, $three] as $event) {
            $state->appendEvent($event);
        }
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
            $scenarioId,
            [$one, $three, $two],
        ));
        $first = $state->privateWsCurrentDelivery();
        self::assertNotNull($first);
        $state->acknowledgePrivateWsDelivery($first);
        $gap = $state->privateWsCurrentDelivery();
        self::assertNotNull($gap);
        $state->markPrivateWsGap('2', '3', $gap);

        return [$state, new FakeExchangeWsClient($state)];
    }

    /** @param array<string,mixed> $metadata */
    private function reconciliationWithMetadata(
        ExchangeReconciliationResult $result,
        array $metadata,
    ): ExchangeReconciliationResult {
        return new ExchangeReconciliationResult(
            exchange: $result->exchange,
            marketType: $result->marketType,
            symbol: $result->symbol,
            startedAt: $result->startedAt,
            completedAt: $result->completedAt,
            ordersChecked: $result->ordersChecked,
            positionsChecked: $result->positionsChecked,
            fillsImported: $result->fillsImported,
            correctionsApplied: $result->correctionsApplied,
            staleOrdersClosed: $result->staleOrdersClosed,
            unknownOrdersDetected: $result->unknownOrdersDetected,
            errors: $result->errors,
            metadata: $metadata,
        );
    }

    private function adapter(FakeExchangeStateStore $state): FakeExchangeAdapter
    {
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->fixedClock());

        return new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
    }

    private function marketRequest(?float $attachedStopLossPrice = null): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 10.0,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-1',
            attachedStopLossPrice: $attachedStopLossPrice,
        );
    }

    private function position(
        float $size,
        ExchangePositionSide $side = ExchangePositionSide::LONG,
        float $entryPrice = 25000.0,
    ): ExchangePositionDto {
        return new ExchangePositionDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side,
            size: $size,
            entryPrice: $entryPrice,
            markPrice: 25000.0,
            unrealizedPnl: 0.0,
            realizedPnl: 0.0,
            margin: 1000.0,
            leverage: 3.0,
            openedAt: $this->fixedClock()->now(),
            updatedAt: $this->fixedClock()->now(),
        );
    }

    private function protectionOrder(
        ExchangeOrderType $orderType,
        ExchangeOrderSide $side,
        float $remainingQuantity,
        float $stopPrice,
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
    ): ExchangeOrderDto {
        return new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'protection-' . $orderType->value . '-' . $side->value . '-' . (string)$remainingQuantity,
            clientOrderId: null,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            status: ExchangeOrderStatus::OPEN,
            quantity: $remainingQuantity,
            filledQuantity: 0.0,
            remainingQuantity: $remainingQuantity,
            price: null,
            averagePrice: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            timeInForce: null,
            createdAt: $this->fixedClock()->now(),
        );
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        };
    }
}

final readonly class SnapshotReconciliationAdapter implements ExchangeAdapterInterface, ExchangeRestSnapshotProviderInterface
{
    /**
     * @param ExchangePositionDto[] $positions
     * @param ExchangeOrderDto[] $orders
     * @param ExchangeFillDto[] $fills
     */
    public function __construct(
        private array $positions,
        private array $orders,
        private array $fills = [],
    ) {
    }

    public function exchange(): Exchange
    {
        return Exchange::FAKE;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(
            supportsClientOrderId: true,
            supportsReduceOnly: true,
            supportsTriggerOrders: true,
        );
    }

    public function getBalances(): array
    {
        return [
            new ExchangeBalanceDto(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                currency: 'USDT',
                available: 1000.0,
            ),
        ];
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->positions,
            static fn (ExchangePositionDto $position): bool => $normalizedSymbol === null || $position->symbol === $normalizedSymbol,
        ));
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->orders,
            static fn (ExchangeOrderDto $order): bool => $normalizedSymbol === null || $order->symbol === $normalizedSymbol,
        ));
    }

    public function getOrdersSnapshot(?string $symbol = null): array
    {
        return $this->getOpenOrders($symbol);
    }

    public function getFillsSnapshot(?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->fills,
            static fn (ExchangeFillDto $fill): bool => $normalizedSymbol === null || $fill->symbol === $normalizedSymbol,
        ));
    }

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool
    {
        return true;
    }

    public function placeOrder(PlaceOrderRequest $request): \App\Exchange\Dto\PlaceOrderResult
    {
        throw new \BadMethodCallException('Snapshot adapter is read-only.');
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        throw new \BadMethodCallException('Snapshot adapter is read-only.');
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        foreach ($this->getOpenOrders($symbol) as $order) {
            if ($order->exchangeOrderId === $exchangeOrderId) {
                return $order;
            }
        }

        return null;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return new SymbolBidAskDto(
            symbol: strtoupper($symbol),
            bid: 24999.5,
            ask: 25000.5,
            timestamp: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        );
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return true;
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');

        return new ExchangeReconciliationResult(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol !== null ? strtoupper($symbol) : null,
            startedAt: $now,
            completedAt: $now,
        );
    }
}

final class RecordingProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    /** @var array<string,ExchangeOrderDto> */
    private array $orders = [];

    public function openOrders(Exchange $exchange, MarketType $marketType): array
    {
        return array_values(array_filter(
            $this->orders,
            static fn (ExchangeOrderDto $order): bool => \in_array($order->status, [
                ExchangeOrderStatus::PENDING,
                ExchangeOrderStatus::OPEN,
                ExchangeOrderStatus::PARTIALLY_FILLED,
            ], true),
        ));
    }

    /** @var ExchangeEventInterface[] */
    public array $events = [];

    public bool $failProjection = false;

    /** @var array<int,array{symbol: string, side: ExchangePositionSide, size: float}> */
    public array $localOpenPositions = [];

    public function hasOrder(ExchangeOrderDto $order): bool
    {
        return isset($this->orders[$order->exchangeOrderId]);
    }

    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;

        return array_values(array_filter(
            $this->localOpenPositions,
            static fn (array $position): bool => $normalizedSymbol === null || $position['symbol'] === $normalizedSymbol,
        ));
    }

    public function project(ExchangeEventInterface $event): void
    {
        if ($this->failProjection) {
            throw new \RuntimeException('forced_reconciliation_projection_failure');
        }
        $this->events[] = $event;
        if ($event instanceof AbstractExchangeOrderEvent) {
            $this->orders[$event->order()->exchangeOrderId] = $event->order();
        }
        if ($event instanceof AbstractExchangePositionEvent) {
            $key = $event->symbol() . ':' . $event->side()->value;
            $this->localOpenPositions = array_values(array_filter(
                $this->localOpenPositions,
                static fn (array $position): bool => $position['symbol'] . ':' . $position['side']->value !== $key,
            ));
            if (!$event instanceof ExchangePositionClosed) {
                $this->localOpenPositions[] = [
                    'symbol' => $event->symbol(),
                    'side' => $event->side(),
                    'size' => $event->size(),
                ];
            }
        }
    }

    public function projectAtomically(array $events): void
    {
        foreach ($events as $event) {
            $this->project($event);
        }
    }

    /**
     * @param class-string $class
     */
    public function contains(string $class): bool
    {
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
