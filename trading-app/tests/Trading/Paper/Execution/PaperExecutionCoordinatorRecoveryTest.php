<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Config\TradeEntryConfigResolver;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use App\TradeEntry\Execution\EmergencyCloseService;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\Execution\ProtectionEnforcer;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\TradeEntry\Policy\OrderModePolicyInterface;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Lifecycle\PaperLifecycleSubmission;
use App\Trading\Paper\Execution\Lifecycle\PaperTradeLifecycleSinkInterface;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\PaperCrashPoint;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodec;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Runtime\PaperDatabaseGuard;
use App\Trading\Paper\Runtime\PaperDatabaseInspection;
use App\Trading\Paper\Runtime\PaperDatabaseInspectorInterface;
use App\Trading\Paper\Runtime\PaperRuntimeGuard;
use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

require_once __DIR__ . '/PaperExecutionCoordinatorTest.php';

#[CoversClass(PaperExecutionCoordinator::class)]
#[CoversClass(PaperCrashPoint::class)]
final class PaperExecutionCoordinatorRecoveryTest extends TestCase
{
    public function testCrashPointContractIsStable(): void
    {
        self::assertSame([
            'before_phase_1_commit',
            'after_phase_1_commit',
            'after_fake_effect',
            'before_phase_3_commit',
            'after_phase_3_commit',
        ], array_column(PaperCrashPoint::cases(), 'value'));
    }

    #[DataProvider('crashPoints')]
    public function testEveryCrashBoundaryConvergesToOneAcknowledgedFakeOrder(PaperCrashPoint $target): void
    {
        $root = sys_get_temp_dir() . '/paper_recovery_' . bin2hex(random_bytes(5));
        $store = new InMemoryPaperExecutionStore();
        $cell = $this->cell();
        $event = $this->event();
        $thrown = false;
        try {
            $first = $this->coordinator($store, new RecordingProjectionStore(), $root, static function (PaperCrashPoint $point) use ($target, &$thrown): void {
                if (!$thrown && $point === $target) {
                    $thrown = true;
                    throw new \RuntimeException('injected_crash');
                }
            });
            try {
                $first->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-1', 0, $event);
                self::fail('Crash point was not reached.');
            } catch (\RuntimeException $exception) {
                self::assertSame('injected_crash', $exception->getMessage());
            }

            $projection = new RecordingProjectionStore();
            $restarted = $this->coordinator($store, $projection, $root);
            $restarted->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-1', 0, $event);

            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-01T10:00:00Z')))->forCell($cell);
            self::assertCount(1, array_filter($runtime->stateStore->getOrders('BTCUSDT'), static fn ($order): bool => !$order->reduceOnly));
            self::assertSame([], $store->pendingEffects($cell));
            self::assertSame(2, $restarted->counters($cell)->requested);
            self::assertSame(2, $restarted->counters($cell)->acknowledged);
            $expectedRetries = match ($target) {
                PaperCrashPoint::AFTER_PHASE_1_COMMIT,
                PaperCrashPoint::AFTER_FAKE_EFFECT,
                PaperCrashPoint::BEFORE_PHASE_3_COMMIT => 2,
                PaperCrashPoint::AFTER_PHASE_3_COMMIT => 1,
                default => 0,
            };
            self::assertSame($expectedRetries, $restarted->counters($cell)->retried);
        } finally {
            if (is_dir($root)) {
                foreach (glob($root . '/*') ?: [] as $file) { @unlink($file); }
                @rmdir($root);
            }
        }
    }

    #[DataProvider('crashPoints')]
    public function testSameCoordinatorRetryReconcilesEveryCrashBoundary(PaperCrashPoint $target): void
    {
        $root = sys_get_temp_dir() . '/paper_same_instance_recovery_' . bin2hex(random_bytes(5));
        $store = new InMemoryPaperExecutionStore();
        $cell = $this->cell();
        $event = $this->event();
        $thrown = false;
        try {
            $coordinator = $this->coordinator(
                $store,
                new RecordingProjectionStore(),
                $root,
                static function (PaperCrashPoint $point) use ($target, &$thrown): void {
                    if (!$thrown && $point === $target) {
                        $thrown = true;
                        throw new \RuntimeException('injected_crash');
                    }
                },
            );
            try {
                $coordinator->consumeAt(
                    $cell,
                    PaperProfileEligibility::REFERENCE_ONLY,
                    'dataset-1',
                    0,
                    $event,
                );
                self::fail('Crash point was not reached.');
            } catch (\RuntimeException $exception) {
                self::assertSame('injected_crash', $exception->getMessage());
            }

            $coordinator->consumeAt(
                $cell,
                PaperProfileEligibility::REFERENCE_ONLY,
                'dataset-1',
                0,
                $event,
            );

            $runtime = (new PaperFakeRuntimeFactory(
                $root,
                new MockClock('2026-08-01T10:00:00Z'),
            ))->forCell($cell);
            self::assertCount(1, array_filter(
                $runtime->stateStore->getOrders('BTCUSDT'),
                static fn ($order): bool => !$order->reduceOnly,
            ));
            self::assertSame([], $store->pendingEffects($cell));
        } finally {
            if (is_dir($root)) {
                foreach (glob($root . '/*') ?: [] as $file) { @unlink($file); }
                @rmdir($root);
            }
        }
    }

    #[DataProvider('crashPoints')]
    public function testModernCanonicalCrashBoundaryConvergesWithoutDuplicateFakeOrder(PaperCrashPoint $target): void
    {
        $root = sys_get_temp_dir() . '/paper_modern_recovery_' . bin2hex(random_bytes(5));
        $store = new InMemoryPaperExecutionStore();
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $cell = $this->modernCell($effect);
        $store->bindDataset($cell, 'dataset-modern-1', str_repeat('4', 64), 'paper-dataset-recorder.v2');
        $event = $this->modernEvent();
        $thrown = false;
        try {
            $first = $this->modernCoordinator(
                $store,
                new RecordingProjectionStore(),
                $root,
                $effect,
                static function (PaperCrashPoint $point) use ($target, &$thrown): void {
                    if (!$thrown && $point === $target) {
                        $thrown = true;
                        throw new \RuntimeException('injected_crash');
                    }
                },
            );
            try {
                $first->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-modern-1', 0, $event);
                self::fail('Crash point was not reached.');
            } catch (\RuntimeException $exception) {
                self::assertSame('injected_crash', $exception->getMessage());
            }

            $canonicalIntents = new RecordingCanonicalPaperOrderIntents();
            $restarted = $this->modernCoordinator(
                $store,
                new RecordingProjectionStore(),
                $root,
                $effect,
                canonicalIntents: $canonicalIntents,
            );
            $restarted->consumeAt(
                $cell,
                PaperProfileEligibility::REFERENCE_ONLY,
                'dataset-modern-1',
                0,
                $event,
            );

            $runtime = (new PaperFakeRuntimeFactory(
                $root,
                new MockClock('2026-08-10T12:00:00Z'),
            ))->forCell($cell);
            self::assertCount(1, array_filter(
                $runtime->stateStore->getOrders('BTCUSDT'),
                static fn ($order): bool => !$order->reduceOnly,
            ));
            self::assertSame([], $store->pendingEffects($cell));
            self::assertSame(2, $restarted->counters($cell)->requested);
            self::assertSame(2, $restarted->counters($cell)->acknowledged);
            $expectedRetries = match ($target) {
                PaperCrashPoint::AFTER_PHASE_1_COMMIT,
                PaperCrashPoint::AFTER_FAKE_EFFECT,
                PaperCrashPoint::BEFORE_PHASE_3_COMMIT => 2,
                PaperCrashPoint::AFTER_PHASE_3_COMMIT => 1,
                default => 0,
            };
            self::assertSame($expectedRetries, $restarted->counters($cell)->retried);
        } finally {
            if (is_dir($root)) {
                foreach (glob($root . '/*') ?: [] as $file) { @unlink($file); }
                @rmdir($root);
            }
        }
    }

    /**
     * #132 j: the trade lifecycle of a projected batch is recorded once whatever the crash point,
     * after the batch is projected and before its effect is acknowledged (same transaction), and
     * through the projection store of the lifecycle.
     */
    #[DataProvider('crashPoints')]
    public function testTheTradeLifecycleOfEveryBatchIsRecordedOnceAcrossACrash(PaperCrashPoint $target): void
    {
        $root = sys_get_temp_dir() . '/paper_lifecycle_recovery_' . bin2hex(random_bytes(5));
        $store = new InMemoryPaperExecutionStore();
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $cell = $this->modernCell($effect);
        $store->bindDataset($cell, 'dataset-modern-1', str_repeat('4', 64), 'paper-dataset-recorder.v2');
        $event = $this->modernEvent();
        $sink = new RecordingLifecycleSink($store, $cell);
        $ownProjection = new RecordingProjectionStore();
        $thrown = false;
        try {
            $first = $this->modernCoordinator(
                $store,
                $ownProjection,
                $root,
                $effect,
                static function (PaperCrashPoint $point) use ($target, &$thrown): void {
                    if (!$thrown && $point === $target) {
                        $thrown = true;
                        throw new \RuntimeException('injected_crash');
                    }
                },
                lifecycle: $sink,
            );
            try {
                $first->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-modern-1', 0, $event);
                self::fail('Crash point was not reached.');
            } catch (\RuntimeException $exception) {
                self::assertSame('injected_crash', $exception->getMessage());
            }
            $this->modernCoordinator($store, $ownProjection, $root, $effect, lifecycle: $sink)
                ->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-modern-1', 0, $event);

            self::assertSame([], $store->pendingEffects($cell));
            self::assertSame([null, 'canonical'], array_column($sink->calls, 'submission'), 'one market batch, one canonical order');
            $orders = array_values(array_filter(array_column($sink->calls, 'order')));
            self::assertCount(1, $orders);
            self::assertStringStartsWith('fake-', (string) $orders[0]);
            foreach ($sink->calls as $call) {
                self::assertTrue($call['effect_pending'], 'Recorded before the effect is acknowledged.');
                self::assertTrue($call['projected'], 'Recorded after its batch is projected.');
            }
            self::assertNotSame([], $sink->store->events);
            self::assertSame([], $ownProjection->events, 'The projection store of the lifecycle replaces the coordinator one.');
        } finally {
            if (is_dir($root)) {
                foreach (glob($root . '/*') ?: [] as $file) { @unlink($file); }
                @rmdir($root);
            }
        }
    }

    /** @return iterable<string, array{PaperCrashPoint}> */
    public static function crashPoints(): iterable
    {
        foreach (PaperCrashPoint::cases() as $point) {
            yield $point->value => [$point];
        }
    }

    private function coordinator(InMemoryPaperExecutionStore $store, RecordingProjectionStore $projection, string $root, ?callable $crash = null): PaperExecutionCoordinator
    {
        $clock = new MockClock('2026-08-01T10:00:00Z');
        return new PaperExecutionCoordinator($store, new PaperMarketStateProjector(new PaperKlineProvider()), new DeterministicPaperStrategy(), new PaperPreparedEffectCodec(), new PaperFakeRuntimeFactory($root, $clock), new PaperFakeEffectDispatcher($this->executionService(), new FakeExchangeEventNormalizer()), $projection, new RecordingPaperOrderIntents(), new PaperRuntimeGuard(), new PaperDatabaseGuard(new class implements PaperDatabaseInspectorInterface { public function inspect(): PaperDatabaseInspection { return new PaperDatabaseInspection('unit_paper_test', 0); } }), 'test', true, $crash);
    }

    private function modernCoordinator(
        InMemoryPaperExecutionStore $store,
        RecordingProjectionStore $projection,
        string $root,
        PaperCanonicalPreparedEffect $effect,
        ?callable $crash = null,
        ?RecordingCanonicalPaperOrderIntents $canonicalIntents = null,
        ?PaperTradeLifecycleSinkInterface $lifecycle = null,
    ): PaperExecutionCoordinator {
        $clock = new MockClock('2026-08-10T12:00:00Z');

        return new PaperExecutionCoordinator(
            $store,
            new PaperMarketStateProjector(new PaperKlineProvider()),
            new DeterministicPaperStrategy(),
            new PaperPreparedEffectCodec(),
            new PaperFakeRuntimeFactory($root, $clock),
            new PaperFakeEffectDispatcher($this->executionService(), new FakeExchangeEventNormalizer()),
            $projection,
            new RecordingPaperOrderIntents(),
            new PaperRuntimeGuard(),
            new PaperDatabaseGuard(new class implements PaperDatabaseInspectorInterface {
                public function inspect(): PaperDatabaseInspection { return new PaperDatabaseInspection('unit_paper_test', 0); }
            }),
            'test',
            true,
            $crash,
            canonicalStrategy: new DeterministicCanonicalPaperStrategy($effect),
            canonicalCodec: new PaperCanonicalPreparedEffectCodec(),
            canonicalDispatcher: new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock),
            canonicalOrderIntents: $canonicalIntents ?? new RecordingCanonicalPaperOrderIntents(),
            lifecycle: $lifecycle,
        );
    }

    private function executionService(): ExchangeExecutionService
    {
        $metrics = new TradeEntryMetricsService();
        $logger = new NullLogger();
        return new ExchangeExecutionService(new ExchangeAdapterRegistry([]), new ProtectionEnforcer(new EmergencyCloseService($metrics, $logger), $metrics, $logger), new IdempotencyPolicy(), new class implements OrderModePolicyInterface { public function enforce(OrderPlanModel $plan): void {} }, (new \ReflectionClass(TradeEntryConfigResolver::class))->newInstanceWithoutConstructor(), $logger);
    }

    private function cell(): PaperExecutionCell
    {
        return PaperExecutionCell::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'sha256:' . str_repeat('d', 64), 'scalper_micro', 'run-1');
    }

    private function modernCell(PaperCanonicalPreparedEffect $effect): PaperExecutionCell
    {
        $provenance = $effect->provenance;
        $network = PaperMarketDataNetwork::from($provenance['paper_network']);
        $venue = PaperMarketDataVenue::from($provenance['market_data_venue']);

        return PaperExecutionCell::createModern(
            $network,
            $venue,
            $provenance['configuration_snapshot_id'],
            PaperModernStrategyIdentity::fromDurableIdentity(
                $network,
                $venue,
                $provenance['mode_id'],
                $provenance['mode_version'],
                $provenance['setup_id'],
                $provenance['setup_version'],
                $provenance['side'],
                $provenance['config_hash'],
                $provenance['condition_catalog_hash'],
            ),
            $provenance['run_id'],
        );
    }

    private function modernEvent(): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable('2026-08-10T11:59:59Z'),
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            '1',
            ['interval' => '1m', 'start_time' => '1785578400000', 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '5', 'confirmed' => true],
        );
    }

    private function event(): PaperMarketEvent
    {
        return PaperMarketEvent::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'BTCUSDT', PaperMarketDataChannel::CANDLE_1M, new \DateTimeImmutable('2026-08-01T10:00:59Z'), new \DateTimeImmutable('2026-08-01T10:01:00Z'), '1', ['interval' => '1m', 'start_time' => '1785578400000', 'open' => '25000', 'high' => '25100', 'low' => '24900', 'close' => '25000', 'volume' => '5', 'confirmed' => true]);
    }
}

/** #132 j: records what the coordinator hands to the trade lifecycle, and when. */
final class RecordingLifecycleSink implements PaperTradeLifecycleSinkInterface
{
    /** @var list<array{submission: ?string, order: ?string, events: int, effect_pending: bool, projected: bool}> */
    public array $calls = [];

    public RecordingProjectionStore $store;

    public function __construct(
        private readonly InMemoryPaperExecutionStore $journal,
        private readonly PaperExecutionCell $cell,
    ) {
        $this->store = new RecordingProjectionStore();
    }

    public function projection(): ?ExchangeLocalProjectionStoreInterface
    {
        return $this->store;
    }

    public function afterProjection(PaperExecutionCell $cell, array $events, ?PaperLifecycleSubmission $submission): void
    {
        $this->calls[] = [
            'submission' => $submission === null ? null : ($submission->canonical !== null ? 'canonical' : 'legacy'),
            'order' => $submission?->execution->exchangeOrderId,
            'events' => \count($events),
            'effect_pending' => $this->journal->pendingEffects($this->cell) !== [],
            'projected' => $events === [] || \array_slice($this->store->events, -\count($events)) === $events,
        ];
    }
}
