<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateCorruptedException;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeExchangeWsClient;
use App\Exchange\Fake\FakePrivateWsDelivery;
use App\Exchange\Fake\FakePrivateWsScenario;
use App\Exchange\Reconciliation\ExchangeReconciliationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

#[CoversClass(FakeExchangeStateStore::class)]
final class FakePrivateWsStatePersistenceTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_private_ws_state_');
        self::assertIsString($stateFile);
        @unlink($stateFile);
        $this->stateFile = $stateFile;
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        @unlink($this->stateFile . '.lock');
        @unlink($this->stateFile . '.private-ws-consumer.lock');
        foreach (glob($this->stateFile . '.tmp.*') ?: [] as $temporaryFile) {
            @unlink($temporaryFile);
        }
    }

    public function testPrivateWsRuntimeAndAuditSurviveRestart(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->configurePrivateWsScenario($this->scenario([1, 3]));

        $first = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $first);
        $state->acknowledgePrivateWsDelivery($first);

        $gap = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $gap);
        $state->markPrivateWsGap('2', '3', $gap);

        $audit = $state->privateWsAudit();
        self::assertSame('resync_required', $audit['connection_state']);
        self::assertSame('fake_private_ws_sequence_gap', $audit['resync_reason']);
        self::assertSame(1, $audit['acknowledged_total']);
        self::assertSame(1, $audit['gap_total']);
        self::assertSame('1', $audit['last_acknowledged_sequence']);
        self::assertSame(1, $audit['last_observed_numeric_sequence']);
        self::assertSame(1, $audit['next_delivery_index']);
        self::assertSame([[
            'kind' => 'gap',
            'sequence' => '3',
            'expected_sequence' => '2',
            'actual_sequence' => '3',
            'fixture_entry_id' => 'restart-v1-0002',
            'fingerprint_prefix' => substr($gap->fingerprint, 0, 12),
        ]], $audit['records']);

        $restored = new FakeExchangeStateStore($this->stateFile);
        $restoredClient = new FakeExchangeWsClient($restored);

        self::assertTrue($restored->hasPrivateWsScenario());
        self::assertTrue($restoredClient->requiresResync());
        self::assertSame('resync_required', $restoredClient->connectionState());
        self::assertSame('fake_private_ws_sequence_gap', $restoredClient->audit()['resync_reason']);
        self::assertEquals($gap, $restored->privateWsCurrentDelivery());
        self::assertSame($first->fingerprint, $restored->privateWsAcknowledgedFingerprint('1'));
        self::assertSame($audit, $restored->privateWsAudit());
        self::assertSame('1', $restored->privateWsLastAcknowledgedSequence());
        self::assertSame(2, $restored->privateWsExpectedNumericSequence());
    }

    public function testScenarioReconfigurationCannotClearGapResyncState(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->configurePrivateWsScenario($this->scenario([1, 3]));

        $first = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $first);
        $state->acknowledgePrivateWsDelivery($first);
        $gap = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $gap);
        $state->markPrivateWsGap('2', '3', $gap);
        $auditBefore = $state->privateWsAudit();

        try {
            $state->configurePrivateWsScenario($this->scenario([1, 2]));
            self::fail('A new scenario must not clear a gap without snapshot reconciliation.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_resync_required', $exception->getMessage());
        }

        self::assertSame($auditBefore, $state->privateWsAudit());
        self::assertSame($auditBefore, (new FakeExchangeStateStore($this->stateFile))->privateWsAudit());
    }

    public function testScenarioReconfigurationCannotClearConflictResyncState(): void
    {
        $firstEvent = $this->event(1, ['status' => 'open']);
        $conflictingEvent = $this->event(1, ['status' => 'filled']);
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
            'conflict-reconfiguration-v1',
            [$firstEvent, $conflictingEvent],
        ));

        $first = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $first);
        $state->acknowledgePrivateWsDelivery($first);
        $conflict = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $conflict);
        $state->markPrivateWsConflict($conflict);
        $auditBefore = $state->privateWsAudit();

        try {
            $state->configurePrivateWsScenario($this->scenario([1, 2]));
            self::fail('A new scenario must not clear a conflict without snapshot reconciliation.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_resync_required', $exception->getMessage());
        }

        self::assertSame($auditBefore, $state->privateWsAudit());
        self::assertSame($auditBefore, (new FakeExchangeStateStore($this->stateFile))->privateWsAudit());
    }

    public function testSnapshotCompletionAdvancesCoveredDeliveriesWithoutSortingFixture(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        foreach ([1, 2, 3] as $sequence) {
            $state->appendEvent($this->event($sequence));
        }
        $state->configurePrivateWsScenario($this->scenario([1, 3, 2, 4]));

        $first = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $first);
        $state->acknowledgePrivateWsDelivery($first);
        $gap = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $gap);
        $state->markPrivateWsGap('2', '3', $gap);

        $restored = new FakeExchangeStateStore($this->stateFile);
        $restored->completePrivateWsSnapshotResync($this->successfulGlobalReconciliation($restored));
        $audit = $restored->privateWsAudit();

        self::assertSame('connected', $audit['connection_state']);
        self::assertNull($audit['resync_reason']);
        self::assertSame(1, $audit['resync_total']);
        self::assertSame(3, $audit['next_delivery_index']);
        self::assertSame('3', $audit['last_acknowledged_sequence']);
        self::assertSame(3, $audit['last_observed_numeric_sequence']);
        self::assertSame($gap->fingerprint, $restored->privateWsAcknowledgedFingerprint('3'));
        self::assertNotNull($restored->privateWsAcknowledgedFingerprint('2'));
        self::assertSame('4', $restored->privateWsCurrentDelivery()?->sequence);
        self::assertSame('resync_completed', $audit['records'][1]['kind'] ?? null);
    }

    public function testExactDuplicateAndConflictAuditAreRedactedAndPersistent(): void
    {
        $first = $this->event(1, ['secret' => 'must-not-be-audited']);
        $duplicate = $this->event(1, ['secret' => 'must-not-be-audited']);
        $conflict = $this->event(1, ['secret' => 'different-secret']);
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->configurePrivateWsScenario(FakePrivateWsScenario::fromEvents(
            'identity-v1',
            [$first, $duplicate, $conflict],
        ));

        $delivery = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $delivery);
        $state->acknowledgePrivateWsDelivery($delivery);

        $delivery = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $delivery);
        $state->skipExactPrivateWsDuplicate($delivery);

        $delivery = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $delivery);
        $state->markPrivateWsConflict($delivery);

        $audit = $state->privateWsAudit();
        self::assertSame(1, $audit['duplicate_total']);
        self::assertSame(1, $audit['conflict_total']);
        self::assertSame('resync_required', $audit['connection_state']);
        self::assertSame('fake_private_ws_sequence_conflict', $audit['resync_reason']);
        self::assertCount(2, $audit['records']);

        $encodedAudit = json_encode($audit, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('must-not-be-audited', $encodedAudit);
        self::assertStringNotContainsString('different-secret', $encodedAudit);
        self::assertSame($audit, (new FakeExchangeStateStore($this->stateFile))->privateWsAudit());
    }

    public function testEnvelopeWithoutPrivateWsHydratesAsInactiveConnectedState(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->reset();

        $envelope = unserialize((string) file_get_contents($this->stateFile), ['allowed_classes' => true]);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        unset($envelope['payload']['privateWs']);
        $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
        file_put_contents($this->stateFile, serialize($envelope));

        $restored = new FakeExchangeStateStore($this->stateFile);
        $audit = $restored->privateWsAudit();

        self::assertFalse($restored->hasPrivateWsScenario());
        self::assertNull($restored->privateWsCurrentDelivery());
        self::assertSame('connected', $audit['connection_state']);
        self::assertNull($audit['resync_reason']);
        self::assertSame(0, $audit['acknowledged_total']);
        self::assertSame([], $audit['records']);
    }

    public function testLegacyPrivateWsStateWithoutSnapshotProofFieldsStillHydrates(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->configurePrivateWsScenario($this->scenario([1, 3]));
        $first = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $first);
        $state->acknowledgePrivateWsDelivery($first);
        $gap = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $gap);
        $state->markPrivateWsGap('2', '3', $gap);

        $envelope = unserialize((string) file_get_contents($this->stateFile), ['allowed_classes' => true]);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload']['privateWs'] ?? null);
        unset(
            $envelope['payload']['privateWs']['resync_cycle_id'],
            $envelope['payload']['privateWs']['snapshot_proof'],
        );
        $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
        file_put_contents($this->stateFile, serialize($envelope));

        $restored = new FakeExchangeStateStore($this->stateFile);

        self::assertTrue((new FakeExchangeWsClient($restored))->requiresResync());
        self::assertSame($state->privateWsAudit(), $restored->privateWsAudit());
    }

    public function testLegacyCapturedProofHydratesAsPendingAndCannotCompleteResync(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        foreach ([1, 2, 3] as $sequence) {
            $state->appendEvent($this->event($sequence));
        }
        $state->configurePrivateWsScenario($this->scenario([1, 3, 2]));
        $first = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $first);
        $state->acknowledgePrivateWsDelivery($first);
        $gap = $state->privateWsCurrentDelivery();
        self::assertInstanceOf(FakePrivateWsDelivery::class, $gap);
        $state->markPrivateWsGap('2', '3', $gap);
        $pendingProof = $state->capturePrivateWsSnapshotProof();
        self::assertIsArray($pendingProof);

        $envelope = unserialize((string) file_get_contents($this->stateFile), ['allowed_classes' => true]);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload']['privateWs']['snapshot_proof'] ?? null);
        unset(
            $envelope['payload']['privateWs']['snapshot_proof']['status'],
            $envelope['payload']['privateWs']['snapshot_proof']['attestation_id'],
        );
        $legacyProof = $envelope['payload']['privateWs']['snapshot_proof'];
        $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
        file_put_contents($this->stateFile, serialize($envelope));

        $restored = new FakeExchangeStateStore($this->stateFile);
        $client = new FakeExchangeWsClient($restored);
        $auditBefore = $client->audit();
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $fabricated = new ExchangeReconciliationResult(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: null,
            startedAt: $now,
            completedAt: $now,
            metadata: ['fake_private_ws_snapshot_proof' => $legacyProof],
        );

        try {
            $client->completeSnapshotResync($fabricated);
            self::fail('A legacy capture-only proof must not become a completed attestation.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_private_ws_snapshot_proof_invalid', $exception->getMessage());
        }

        self::assertSame($auditBefore, $client->audit());
        self::assertTrue($client->requiresResync());
    }

    public function testMalformedPresentSnapshotProofStateFailsClosed(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->reset();

        $envelope = unserialize((string) file_get_contents($this->stateFile), ['allowed_classes' => true]);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload']['privateWs'] ?? null);
        $envelope['payload']['privateWs']['resync_cycle_id'] = str_repeat('a', 64);
        $envelope['payload']['privateWs']['snapshot_proof'] = [
            'schema_version' => 'malformed',
        ];
        $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
        file_put_contents($this->stateFile, serialize($envelope));

        $this->expectException(FakeExchangeStateCorruptedException::class);
        $this->expectExceptionMessage('fake_exchange_state_shape_invalid');
        new FakeExchangeStateStore($this->stateFile);
    }

    public function testMalformedPrivateWsStateFailsClosed(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->reset();

        $envelope = unserialize((string) file_get_contents($this->stateFile), ['allowed_classes' => true]);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        $envelope['payload']['privateWs']['connection_state'] = 'silently_repaired';
        $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
        file_put_contents($this->stateFile, serialize($envelope));

        $this->expectException(FakeExchangeStateCorruptedException::class);
        $this->expectExceptionMessage('fake_exchange_state_shape_invalid');
        new FakeExchangeStateStore($this->stateFile);
    }

    public function testExplicitNullPrivateWsStateFailsClosed(): void
    {
        $state = new FakeExchangeStateStore($this->stateFile);
        $state->reset();

        $envelope = unserialize((string) file_get_contents($this->stateFile), ['allowed_classes' => true]);
        self::assertIsArray($envelope);
        self::assertIsArray($envelope['payload'] ?? null);
        $envelope['payload']['privateWs'] = null;
        $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
        file_put_contents($this->stateFile, serialize($envelope));

        $this->expectException(FakeExchangeStateCorruptedException::class);
        $this->expectExceptionMessage('fake_exchange_state_shape_invalid');
        new FakeExchangeStateStore($this->stateFile);
    }

    /**
     * @param list<int> $sequences
     */
    private function scenario(array $sequences): FakePrivateWsScenario
    {
        return FakePrivateWsScenario::fromEvents(
            'restart-v1',
            array_map(fn (int $sequence): FakeExchangeEvent => $this->event($sequence), $sequences),
        );
    }

    private function successfulGlobalReconciliation(FakeExchangeStateStore $state): ExchangeReconciliationResult
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
        $projectionStore = new PrivateWsPersistenceProjectionStore();
        $reconciliation = new ExchangeReconciliationService(
            new ExchangeEventBus($projectionStore, new NullLogger()),
            $projectionStore,
            $clock,
            new NullLogger(),
        );
        $book = new FakeExchangeOrderBook($state);
        $adapter = new FakeExchangeAdapter(
            $state,
            $book,
            new FakeExchangeMatchingEngine($state, $book, $clock),
            $clock,
        );

        return $reconciliation->reconcile($adapter);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function event(int $sequence, array $payload = []): FakeExchangeEvent
    {
        return new FakeExchangeEvent(
            'order.created',
            'BTCUSDT',
            new \DateTimeImmutable(sprintf('2026-01-01T00:00:%02d+00:00', $sequence)),
            ['event_sequence' => $sequence] + $payload,
        );
    }
}

final class PrivateWsPersistenceProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    public function hasOrder(ExchangeOrderDto $order): bool
    {
        return false;
    }

    public function openOrders(Exchange $exchange, MarketType $marketType): array
    {
        return [];
    }

    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array
    {
        return [];
    }

    public function project(ExchangeEventInterface $event): void
    {
    }

    public function projectAtomically(array $events): void
    {
    }
}
