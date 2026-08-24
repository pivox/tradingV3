<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\TradeEntryConfigResolver;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use App\Logging\Dto\LifecycleContextBuilder;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\Execution\EmergencyCloseService;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\Execution\ProtectionEnforcer;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\TradeEntry\Policy\OrderModePolicyInterface;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\TradeEntry\Types\Side;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Persistence\PaperOrderIntentRecorderInterface;
use App\Trading\Paper\Execution\Persistence\PaperCanonicalOrderIntentRecorderInterface;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyDecision;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparationInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparationResult;
use App\Trading\Paper\Execution\Strategy\PaperStrategyPreparationInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Runtime\PaperDatabaseGuard;
use App\Trading\Paper\Runtime\PaperDatabaseInspection;
use App\Trading\Paper\Runtime\PaperDatabaseInspectorInterface;
use App\Trading\Paper\Runtime\PaperRuntimeGuard;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperExecutionCoordinator::class)]
final class PaperExecutionCoordinatorTest extends TestCase
{
    public function testModernCanonicalEffectIsReservedDispatchedAndReplayedWithoutLegacyIntentMutation(): void
    {
        $root = sys_get_temp_dir() . '/paper_coord_modern_' . bin2hex(random_bytes(5));
        try {
            $store = new InMemoryPaperExecutionStore();
            $projection = new RecordingProjectionStore();
            $legacyIntents = new RecordingPaperOrderIntents();
            $canonicalIntents = new RecordingCanonicalPaperOrderIntents();
            $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
            $cell = self::modernCellFromEffect($effect);
            $store->bindDataset($cell, 'dataset-modern-1', str_repeat('4', 64), 'paper-dataset-recorder.v2');
            $canonicalStrategy = new DeterministicCanonicalPaperStrategy($effect);
            $coordinator = $this->coordinator(
                $store,
                $projection,
                $root,
                legacyIntents: $legacyIntents,
                canonicalStrategy: $canonicalStrategy,
                canonicalIntents: $canonicalIntents,
                clock: new MockClock('2026-08-10T12:00:00Z'),
            );
            $event = $this->modernEvent();

            $coordinator->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-modern-1', 0, $event);
            $coordinator->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-modern-1', 0, $event);

            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-10T12:00:00Z')))->forCell($cell);
            $entries = array_values(array_filter(
                $runtime->stateStore->getOrders('BTCUSDT'),
                static fn ($order): bool => !$order->reduceOnly,
            ));
            self::assertCount(1, $entries);
            self::assertSame($effect->plan->planHash, $entries[0]->metadata['plan_hash'] ?? null);
            self::assertSame(1, $canonicalIntents->reservations);
            self::assertSame(1, $canonicalIntents->acknowledgements);
            self::assertSame(0, $legacyIntents->reservations);
            self::assertSame(0, $legacyIntents->acknowledgements);
            self::assertSame('dataset-modern-1', $canonicalStrategy->observedSourceDatasetId);
            self::assertSame(str_repeat('4', 64), $canonicalStrategy->observedSourceEventsFileSha256);
            self::assertSame('paper-dataset-recorder.v2', $canonicalStrategy->observedSourceBuildVersion);
            self::assertCount(1, $store->strategyObservations());
            self::assertSame('planned', $store->strategyObservations()[0]->status);
            self::assertSame('paper_canonical_strategy_planned', $store->strategyObservations()[0]->reasonCode);
            self::assertSame(2, $coordinator->counters($cell)->requested);
            self::assertSame(2, $coordinator->counters($cell)->acknowledged);
            self::assertSame([], $store->pendingEffects($cell));
        } finally {
            $this->cleanup($root);
        }
    }

    public function testModernNoTradeObservationIsDurableAndReplaySafeWithoutIntent(): void
    {
        $root = sys_get_temp_dir() . '/paper_coord_modern_no_trade_' . bin2hex(random_bytes(5));
        try {
            $store = new InMemoryPaperExecutionStore();
            $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
            $cell = self::modernCellFromEffect($effect);
            $store->bindDataset($cell, 'dataset-modern-1', str_repeat('4', 64), 'paper-dataset-recorder.v2');
            $intents = new RecordingCanonicalPaperOrderIntents();
            $coordinator = $this->coordinator(
                $store,
                new RecordingProjectionStore(),
                $root,
                canonicalStrategy: new RejectingCanonicalPaperStrategy(),
                canonicalIntents: $intents,
                clock: new MockClock('2026-08-10T12:00:00Z'),
            );
            $event = $this->modernEvent();

            $coordinator->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-modern-1', 0, $event);
            $coordinator->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-modern-1', 0, $event);

            self::assertSame(0, $intents->reservations);
            self::assertCount(1, $store->strategyObservations());
            $observation = $store->strategyObservations()[0];
            self::assertSame('no_trade', $observation->status);
            self::assertSame('scalping_shadow_setup_filter_failed', $observation->reasonCode);
            self::assertSame($event->eventId, $observation->sourceEventId);
            self::assertSame(1, $coordinator->counters($cell)->requested);
            self::assertSame(1, $coordinator->counters($cell)->acknowledged);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testModernCanonicalSymbolMismatchFailsBeforeIntentOrJournalMutation(): void
    {
        $root = sys_get_temp_dir() . '/paper_coord_modern_invalid_' . bin2hex(random_bytes(5));
        try {
            $store = new InMemoryPaperExecutionStore();
            $canonicalIntents = new RecordingCanonicalPaperOrderIntents();
            $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
            $cell = self::modernCellFromEffect($effect);
            $store->bindDataset($cell, 'dataset-modern-1', str_repeat('4', 64), 'paper-dataset-recorder.v2');
            $coordinator = $this->coordinator(
                $store,
                new RecordingProjectionStore(),
                $root,
                canonicalStrategy: new DeterministicCanonicalPaperStrategy($effect),
                canonicalIntents: $canonicalIntents,
                clock: new MockClock('2026-08-10T12:00:00Z'),
            );
            $event = PaperMarketEvent::create(
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'ETHUSDT',
                PaperMarketDataChannel::CANDLE_1M,
                new \DateTimeImmutable('2026-08-10T11:59:59Z'),
                new \DateTimeImmutable('2026-08-10T12:00:00Z'),
                '1',
                $this->payload(),
            );

            try {
                $coordinator->consumeAt(
                    $cell,
                    PaperProfileEligibility::REFERENCE_ONLY,
                    'dataset-modern-1',
                    0,
                    $event,
                );
                self::fail('Mismatched canonical symbol was accepted.');
            } catch (\LogicException $exception) {
                self::assertSame('paper_canonical_strategy_symbol_mismatch', $exception->getMessage());
            }

            self::assertSame(0, $canonicalIntents->reservations);
            self::assertSame(0, $canonicalIntents->acknowledgements);
            self::assertSame(0, $store->checkpoint($cell)->nextSourcePosition);
            self::assertSame(0, $coordinator->counters($cell)->requested);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testModernCanonicalDatasetMismatchFailsBeforeStrategyOrJournalMutation(): void
    {
        $root = sys_get_temp_dir() . '/paper_coord_modern_dataset_' . bin2hex(random_bytes(5));
        try {
            $store = new InMemoryPaperExecutionStore();
            $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
            $cell = self::modernCellFromEffect($effect);
            $store->bindDataset($cell, 'dataset-modern-1', str_repeat('4', 64), 'paper-dataset-recorder.v2');
            $strategy = new DeterministicCanonicalPaperStrategy($effect);
            $coordinator = $this->coordinator(
                $store,
                new RecordingProjectionStore(),
                $root,
                canonicalStrategy: $strategy,
                canonicalIntents: new RecordingCanonicalPaperOrderIntents(),
                clock: new MockClock('2026-08-10T12:00:00Z'),
            );

            try {
                $coordinator->consumeAt(
                    $cell,
                    PaperProfileEligibility::REFERENCE_ONLY,
                    'dataset-foreign',
                    0,
                    $this->modernEvent(),
                );
                self::fail('A foreign canonical replay dataset was accepted.');
            } catch (\LogicException $exception) {
                self::assertSame('paper_canonical_strategy_dataset_mismatch', $exception->getMessage());
            }

            self::assertNull($strategy->observedSourceDatasetId);
            self::assertSame(0, $store->checkpoint($cell)->nextSourcePosition);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testAcceptedEffectAndExactReplayProduceOneFakeOrder(): void
    {
        $root = sys_get_temp_dir() . '/paper_coord_' . bin2hex(random_bytes(5));
        try {
            $store = new InMemoryPaperExecutionStore();
            $projection = new RecordingProjectionStore();
            $coordinator = $this->coordinator($store, $projection, $root);
            $cell = $this->cell();
            $event = $this->event();

            $coordinator->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-1', 0, $event);
            $coordinator->consumeAt($cell, PaperProfileEligibility::REFERENCE_ONLY, 'dataset-1', 0, $event);

            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-01T10:00:00Z')))->forCell($cell);
            $entries = array_filter($runtime->stateStore->getOrders('BTCUSDT'), static fn ($order): bool => !$order->reduceOnly);
            self::assertCount(1, $entries);
            self::assertNotEmpty($projection->events);
            self::assertSame(2, $coordinator->counters($cell)->requested);
            self::assertSame(2, $coordinator->counters($cell)->acknowledged);
        } finally {
            $this->cleanup($root);
        }
    }

    public function testNetworkMismatchFailsBeforeClaim(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $coordinator = $this->coordinator($store, new RecordingProjectionStore(), sys_get_temp_dir() . '/paper_coord_' . bin2hex(random_bytes(5)));
        $event = PaperMarketEvent::create(PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::HYPERLIQUID, 'BTCUSDT', PaperMarketDataChannel::CANDLE_1M, new \DateTimeImmutable('2026-08-01T10:00:59Z'), new \DateTimeImmutable('2026-08-01T10:01:00Z'), '1', $this->payload());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_network_mismatch');
        $coordinator->consumeAt($this->cell(), PaperProfileEligibility::REFERENCE_ONLY, 'dataset-1', 0, $event);
    }

    public function testModernCellFailsBeforeAnyCoordinatorMutationUntilCanonicalBridgeExists(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $coordinator = $this->coordinator(
            $store,
            new RecordingProjectionStore(),
            sys_get_temp_dir() . '/paper_coord_' . bin2hex(random_bytes(5)),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_modern_strategy_bridge_unavailable');
        $coordinator->consumeAt(
            $this->modernCell(),
            PaperProfileEligibility::REFERENCE_ONLY,
            'dataset-1',
            0,
            $this->event(),
        );
    }

    public function testNoDecisionStillAdvancesTheDurableFakeMarketEffect(): void
    {
        $root = sys_get_temp_dir() . '/paper_coord_' . bin2hex(random_bytes(5));
        try {
            $store = new InMemoryPaperExecutionStore();
            $strategy = new class implements PaperStrategyPreparationInterface {
                public function prepareFor(PaperExecutionCell $cell, PaperMarketEvent $event): ?PreparedTradeEntry { return null; }
            };
            $coordinator = $this->coordinator($store, new RecordingProjectionStore(), $root, $strategy);
            $coordinator->consumeAt($this->cell(), PaperProfileEligibility::REFERENCE_ONLY, 'dataset-1', 0, $this->event());

            self::assertSame(1, $store->checkpoint($this->cell())->nextSourcePosition);
            self::assertSame(1, $coordinator->counters($this->cell())->requested);
            self::assertSame(1, $coordinator->counters($this->cell())->acknowledged);
            self::assertSame([], $store->pendingEffects($this->cell()));
        } finally {
            $this->cleanup($root);
        }
    }

    public function testLaterBookEventTriggersExistingProtectionAndProjectsTheFill(): void
    {
        $root = sys_get_temp_dir() . '/paper_coord_' . bin2hex(random_bytes(5));
        try {
            $store = new InMemoryPaperExecutionStore();
            $projection = new RecordingProjectionStore();
            $coordinator = $this->coordinator($store, $projection, $root, new FirstCandleMarketStrategy());
            $coordinator->consumeAt($this->cell(), PaperProfileEligibility::REFERENCE_ONLY, 'dataset-1', 0, PaperMarketEvent::create(
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'BTCUSDT',
                PaperMarketDataChannel::CANDLE_1M,
                new \DateTimeImmutable('2026-08-01T10:00:59Z'),
                new \DateTimeImmutable('2026-08-01T10:01:00Z'),
                '1',
                ['interval' => '1m', 'start_time' => '1785578400000', 'open' => '100', 'high' => '105', 'low' => '95', 'close' => '100', 'volume' => '5', 'confirmed' => true],
            ));
            $fillsBefore = array_values(array_filter($projection->events, static fn ($event): bool => $event instanceof ExchangeFillReceived));
            self::assertNotEmpty($fillsBefore);

            $coordinator->consumeAt($this->cell(), PaperProfileEligibility::REFERENCE_ONLY, 'dataset-1', 1, PaperMarketEvent::create(
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'BTCUSDT',
                PaperMarketDataChannel::TOP_OF_BOOK,
                new \DateTimeImmutable('2026-08-01T10:01:01Z'),
                new \DateTimeImmutable('2026-08-01T10:01:01Z'),
                '2',
                ['bid_price' => '104.5', 'ask_price' => '105'],
            ));

            $fillsAfter = array_values(array_filter($projection->events, static fn ($event): bool => $event instanceof ExchangeFillReceived));
            self::assertGreaterThan(count($fillsBefore), count($fillsAfter));
            self::assertSame(3, $coordinator->counters($this->cell())->acknowledged);
        } finally {
            $this->cleanup($root);
        }
    }

    private function coordinator(
        InMemoryPaperExecutionStore $store,
        RecordingProjectionStore $projection,
        string $root,
        ?PaperStrategyPreparationInterface $strategy = null,
        ?RecordingPaperOrderIntents $legacyIntents = null,
        ?PaperCanonicalStrategyPreparationInterface $canonicalStrategy = null,
        ?RecordingCanonicalPaperOrderIntents $canonicalIntents = null,
        ?MockClock $clock = null,
    ): PaperExecutionCoordinator
    {
        $clock ??= new MockClock('2026-08-01T10:00:00Z');
        return new PaperExecutionCoordinator(
            $store,
            new PaperMarketStateProjector(new PaperKlineProvider()),
            $strategy ?? new DeterministicPaperStrategy(),
            new PaperPreparedEffectCodec(),
            new PaperFakeRuntimeFactory($root, $clock),
            new PaperFakeEffectDispatcher($this->executionService(), new FakeExchangeEventNormalizer()),
            $projection,
            $legacyIntents ?? new RecordingPaperOrderIntents(),
            new PaperRuntimeGuard(),
            new PaperDatabaseGuard(new class implements PaperDatabaseInspectorInterface {
                public function inspect(): PaperDatabaseInspection { return new PaperDatabaseInspection('unit_paper_test', 0); }
            }),
            'test',
            true,
            canonicalStrategy: $canonicalStrategy,
            canonicalCodec: new PaperCanonicalPreparedEffectCodec(),
            canonicalDispatcher: new PaperCanonicalFakeEffectDispatcher(new FakeExchangeEventNormalizer(), $clock),
            canonicalOrderIntents: $canonicalIntents,
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

    private function modernCell(): PaperExecutionCell
    {
        $conditionHash = 'sha256:' . str_repeat('c', 64);
        $payload = ['decision' => ['enabled' => true]];
        $layers = [];
        foreach (['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'] as $type) {
            $layers[] = ['type' => $type, 'name' => $type, 'path' => $type . '.yaml', 'required' => true];
        }
        $snapshot = new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest(
                'micro_scalping', '1.1.0', 'micro_scalping.momentum_ofi.long', '1.1.0',
                'hyperliquid', 'testnet', 'long', ShadowExecutionCapability::Paper,
            ),
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $conditionHash),
            $conditionHash,
            $layers,
            ['decision.enabled' => $layers[0]],
        );

        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('d', 64),
            PaperModernStrategyIdentity::fromResolvedSnapshot(
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                $snapshot,
            ),
            'modern-run-1',
        );
    }

    private static function modernCellFromEffect(PaperCanonicalPreparedEffect $effect): PaperExecutionCell
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
            $this->payload(),
        );
    }

    private function event(): PaperMarketEvent
    {
        return PaperMarketEvent::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'BTCUSDT', PaperMarketDataChannel::CANDLE_1M, new \DateTimeImmutable('2026-08-01T10:00:59Z'), new \DateTimeImmutable('2026-08-01T10:01:00Z'), '1', $this->payload());
    }

    /** @return array<string, mixed> */
    private function payload(): array { return ['interval' => '1m', 'start_time' => '1785578400000', 'open' => '25000', 'high' => '25100', 'low' => '24900', 'close' => '25000', 'volume' => '5', 'confirmed' => true]; }

    private function cleanup(string $root): void
    {
        if (!is_dir($root)) { return; }
        foreach (glob($root . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($root);
    }
}

final class DeterministicPaperStrategy implements PaperStrategyPreparationInterface
{
    public function prepareFor(PaperExecutionCell $cell, PaperMarketEvent $event): ?PreparedTradeEntry
    {
        return new PreparedTradeEntry(new OrderPlanModel('BTCUSDT', Side::Long, 'market', 'isolated', 1, 25000.0, 24800.0, 25200.0, 1, 3, 2, 1.0, exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL)), null, 'decision-1', 'paper-trade-1', new LifecycleContextBuilder('BTCUSDT'), 'scalper_micro', '1m');
    }
}

final class FirstCandleMarketStrategy implements PaperStrategyPreparationInterface
{
    public function prepareFor(PaperExecutionCell $cell, PaperMarketEvent $event): ?PreparedTradeEntry
    {
        if ($event->channel !== PaperMarketDataChannel::CANDLE_1M) {
            return null;
        }

        return new PreparedTradeEntry(new OrderPlanModel('BTCUSDT', Side::Long, 'market', 'isolated', 1, 100.0, 98.0, 104.0, 1, 3, 2, 1.0, exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL)), null, 'decision-market-1', 'paper-trade-market-1', new LifecycleContextBuilder('BTCUSDT'), 'scalper_micro', '1m');
    }
}

final class RecordingProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    /** @var list<ExchangeEventInterface> */ public array $events = [];
    public function hasOrder(\App\Exchange\Dto\ExchangeOrderDto $order): bool { return false; }
    public function openOrders(Exchange $exchange, MarketType $marketType): array { return []; }
    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array { return []; }
    public function project(ExchangeEventInterface $event): void { $this->events[] = $event; }
    public function projectAtomically(array $events): void { foreach ($events as $event) { $this->project($event); } }
}

final class RecordingPaperOrderIntents implements PaperOrderIntentRecorderInterface
{
    public int $reservations = 0;
    public int $acknowledgements = 0;
    /** @var list<array<string, mixed>> */ public array $reserved = [];
    /** @var list<array<string, mixed>> */ public array $acknowledged = [];

    public function reserve(PreparedTradeEntry $prepared, array $identity, array $provenance): array
    {
        ++$this->reservations;
        $this->reserved[] = ['prepared' => $prepared, 'identity' => $identity, 'provenance' => $provenance];

        return $identity + ['order_intent_id' => 42];
    }

    public function acknowledge(array $identity, \App\TradeEntry\Dto\ExecutionResult $result): void
    {
        ++$this->acknowledgements;
        $this->acknowledged[] = ['identity' => $identity, 'result' => $result];
    }
}

final class DeterministicCanonicalPaperStrategy implements PaperCanonicalStrategyPreparationInterface
{
    public ?string $observedSourceDatasetId = null;
    public ?string $observedSourceEventsFileSha256 = null;
    public ?string $observedSourceBuildVersion = null;

    public function __construct(private readonly PaperCanonicalPreparedEffect $effect) {}

    public function prepareFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        string $sourceDatasetId,
        string $sourceEventsFileSha256,
        string $sourceBuildVersion,
    ): PaperCanonicalStrategyPreparationResult {
        $this->observedSourceDatasetId = $sourceDatasetId;
        $this->observedSourceEventsFileSha256 = $sourceEventsFileSha256;
        $this->observedSourceBuildVersion = $sourceBuildVersion;

        return PaperCanonicalStrategyPreparationResult::planned(
            'paper_canonical_strategy_planned',
            PaperCanonicalStrategyDecision::fromPreparedEffect($this->effect),
        );
    }
}

final class RejectingCanonicalPaperStrategy implements PaperCanonicalStrategyPreparationInterface
{
    public function prepareFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        string $sourceDatasetId,
        string $sourceEventsFileSha256,
        string $sourceBuildVersion,
    ): PaperCanonicalStrategyPreparationResult {
        return PaperCanonicalStrategyPreparationResult::noTrade('scalping_shadow_setup_filter_failed');
    }
}

final class RecordingCanonicalPaperOrderIntents implements PaperCanonicalOrderIntentRecorderInterface
{
    public int $reservations = 0;
    public int $acknowledgements = 0;
    /** @var list<array<string, mixed>> */ public array $reserved = [];
    /** @var list<array<string, mixed>> */ public array $acknowledged = [];

    public function reserve(
        CanonicalOrderPlan $plan,
        LineageContext $lineage,
        string $decisionKey,
        string $executionTimeframe,
        array $identity,
        array $provenance,
    ): array {
        ++$this->reservations;
        $this->reserved[] = compact('plan', 'lineage', 'decisionKey', 'executionTimeframe', 'identity', 'provenance');

        return $identity + ['order_intent_id' => 84];
    }

    public function acknowledge(array $identity, \App\TradeEntry\Dto\ExecutionResult $result): void
    {
        ++$this->acknowledgements;
        $this->acknowledged[] = ['identity' => $identity, 'result' => $result];
    }
}
