<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\TradeEntryConfigResolver;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Event\ExchangeFillReceived;
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
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\PaperExecutionConsumer;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperStrategyPreparationInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Runtime\PaperDatabaseGuard;
use App\Trading\Paper\Runtime\PaperDatabaseInspection;
use App\Trading\Paper\Runtime\PaperDatabaseInspectorInterface;
use App\Trading\Paper\Runtime\PaperRuntimeGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

require_once __DIR__ . '/PaperExecutionCoordinatorTest.php';

#[CoversClass(PaperExecutionCoordinator::class)]
#[CoversClass(PaperExecutionConsumer::class)]
final class PaperExecutionEndToEndTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testFixtureProducesOneFakeOnlyIntentWithExactCellProvenance(string $directory): void
    {
        $dataset = dirname(__DIR__, 3) . '/Fixtures/PaperExecution/' . $directory;
        $manifest = (new PaperDatasetManifestCodec())->decode((string) file_get_contents($dataset . '/manifest.json'));
        $cell = PaperExecutionCell::create($manifest->network, $manifest->venue, 'sha256:' . str_repeat('e', 64), 'scalper_micro', 'run-' . $directory);
        $root = sys_get_temp_dir() . '/paper_e2e_' . bin2hex(random_bytes(5));
        $store = new InMemoryPaperExecutionStore();
        $intents = new RecordingPaperOrderIntents();
        $projection = new RecordingProjectionStore();
        try {
            $coordinator = $this->coordinator($store, $projection, $intents, $root);
            $consumer = new PaperExecutionConsumer($coordinator, $store, $cell, PaperProfileEligibility::REFERENCE_ONLY);
            foreach (file($dataset . '/events.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $consumer->consume($manifest->datasetId, PaperMarketEvent::fromArray($decoded));
            }

            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-01T10:00:00Z')))->forCell($cell);
            $orders = array_values(array_filter($runtime->stateStore->getOrders('BTCUSDT'), static fn ($order): bool => !$order->reduceOnly));
            self::assertCount(1, $orders);
            self::assertSame(1, $intents->reservations);
            self::assertSame(1, $intents->acknowledgements);
            self::assertSame(42, $orders[0]->metadata['order_intent_id']);
            self::assertSame(Exchange::FAKE, $orders[0]->exchange);
            self::assertSame($manifest->network->value, $orders[0]->metadata['paper_network']);
            self::assertSame($manifest->venue->value, $orders[0]->metadata['market_data_venue']);
            self::assertSame($cell->id, $orders[0]->metadata['paper_execution_cell_id']);
            self::assertSame('reference_only', $orders[0]->metadata['paper_eligibility']);
            self::assertSame(4, $store->checkpoint($cell)->nextSourcePosition);
            self::assertSame(1, $coordinator->counters($cell)->acknowledged);
            $fills = array_values(array_filter(
                $projection->events,
                static fn ($event): bool => $event instanceof ExchangeFillReceived,
            ));
            self::assertNotEmpty($fills);
            $fill = $fills[0]->fill();
            self::assertSame(42, $fill->metadata['order_intent_id']);
            self::assertSame($cell->id, $fill->metadata['paper_execution_cell_id']);
            self::assertNotNull($fill->fee);
            self::assertSame('USDT', $fill->feeCurrency);
            foreach (['spread_cost_usdt', 'slippage_cost_usdt', 'cost_model_version'] as $costKey) {
                self::assertArrayHasKey($costKey, $fill->metadata);
            }
        } finally {
            $this->cleanup($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (['okx-mainnet-cell', 'hyperliquid-mainnet-cell', 'hyperliquid-testnet-cell'] as $directory) {
            yield $directory => [$directory];
        }
    }

    private function coordinator(InMemoryPaperExecutionStore $store, RecordingProjectionStore $projection, RecordingPaperOrderIntents $intents, string $root): PaperExecutionCoordinator
    {
        return new PaperExecutionCoordinator(
            $store,
            new PaperMarketStateProjector(new PaperKlineProvider()),
            new FixturePaperStrategy(),
            new PaperPreparedEffectCodec(),
            new PaperFakeRuntimeFactory($root, new MockClock('2026-08-01T10:00:00Z')),
            new PaperFakeEffectDispatcher($this->executionService(), new FakeExchangeEventNormalizer()),
            $projection,
            $intents,
            new PaperRuntimeGuard(),
            new PaperDatabaseGuard(new class implements PaperDatabaseInspectorInterface {
                public function inspect(): PaperDatabaseInspection { return new PaperDatabaseInspection('fixture_paper_test', 0); }
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

    private function cleanup(string $root): void
    {
        if (!is_dir($root)) { return; }
        foreach (glob($root . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($root);
    }
}

final class FixturePaperStrategy implements PaperStrategyPreparationInterface
{
    public function prepareFor(PaperExecutionCell $cell, PaperMarketEvent $event): ?PreparedTradeEntry
    {
        if ($event->channel !== PaperMarketDataChannel::CANDLE_1M) {
            return null;
        }

        return new PreparedTradeEntry(
            new OrderPlanModel('BTCUSDT', Side::Long, 'market', 'isolated', 1, 100.0, 98.0, 104.0, 1, 3, 2, 1.0, exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL)),
            null,
            'fixture-decision-' . substr($cell->id, 7, 12),
            'fixture-trade-' . substr($cell->id, 7, 12),
            new LifecycleContextBuilder('BTCUSDT'),
            'scalper_micro',
            '1m',
        );
    }
}
