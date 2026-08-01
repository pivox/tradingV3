<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\TradeEntryConfigResolver;
use App\Exchange\Event\ExchangeEventInterface;
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
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperStrategyPreparationInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Runtime\PaperDatabaseGuard;
use App\Trading\Paper\Runtime\PaperDatabaseInspection;
use App\Trading\Paper\Runtime\PaperDatabaseInspectorInterface;
use App\Trading\Paper\Runtime\PaperRuntimeGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperExecutionCoordinator::class)]
final class PaperExecutionCoordinatorTest extends TestCase
{
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
            self::assertSame(1, $coordinator->counters($cell)->requested);
            self::assertSame(1, $coordinator->counters($cell)->acknowledged);
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

    public function testNoDecisionAcknowledgesSourceWithoutFakeEffect(): void
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
            self::assertSame(0, $coordinator->counters($this->cell())->requested);
            self::assertSame([], $store->pendingEffects($this->cell()));
        } finally {
            $this->cleanup($root);
        }
    }

    private function coordinator(InMemoryPaperExecutionStore $store, RecordingProjectionStore $projection, string $root, ?PaperStrategyPreparationInterface $strategy = null): PaperExecutionCoordinator
    {
        $clock = new MockClock('2026-08-01T10:00:00Z');
        return new PaperExecutionCoordinator(
            $store,
            new PaperMarketStateProjector(new PaperKlineProvider()),
            $strategy ?? new DeterministicPaperStrategy(),
            new PaperPreparedEffectCodec(),
            new PaperFakeRuntimeFactory($root, $clock),
            new PaperFakeEffectDispatcher($this->executionService(), new FakeExchangeEventNormalizer()),
            $projection,
            new PaperRuntimeGuard(),
            new PaperDatabaseGuard(new class implements PaperDatabaseInspectorInterface {
                public function inspect(): PaperDatabaseInspection { return new PaperDatabaseInspection('unit_paper_test', 0); }
            }),
            'test',
            true,
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

final class RecordingProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    /** @var list<ExchangeEventInterface> */ public array $events = [];
    public function hasOrder(\App\Exchange\Dto\ExchangeOrderDto $order): bool { return false; }
    public function openOrders(Exchange $exchange, MarketType $marketType): array { return []; }
    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array { return []; }
    public function project(ExchangeEventInterface $event): void { $this->events[] = $event; }
    public function projectAtomically(array $events): void { foreach ($events as $event) { $this->project($event); } }
}
