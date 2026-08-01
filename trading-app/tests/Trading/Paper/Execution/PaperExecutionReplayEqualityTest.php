<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Config\TradeEntryConfigResolver;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use App\TradeEntry\Execution\EmergencyCloseService;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\Execution\ProtectionEnforcer;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\TradeEntry\Policy\OrderModePolicyInterface;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\PaperCrashPoint;
use App\Trading\Paper\Execution\PaperExecutionConsumer;
use App\Trading\Paper\Execution\PaperExecutionCoordinator;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Runtime\PaperDatabaseGuard;
use App\Trading\Paper\Runtime\PaperDatabaseInspection;
use App\Trading\Paper\Runtime\PaperDatabaseInspectorInterface;
use App\Trading\Paper\Runtime\PaperRuntimeGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

require_once __DIR__ . '/PaperExecutionEndToEndTest.php';

#[CoversClass(PaperExecutionCoordinator::class)]
final class PaperExecutionReplayEqualityTest extends TestCase
{
    public function testCrashRestartAndUninterruptedRunsHaveEqualCanonicalBusinessFacts(): void
    {
        $clean = $this->executeScenario(false);
        $restarted = $this->executeScenario(true);

        self::assertSame($clean, $restarted);
    }

    /** @return array<string, mixed> */
    private function executeScenario(bool $crash): array
    {
        $dataset = dirname(__DIR__, 3) . '/Fixtures/PaperExecution/hyperliquid-testnet-cell';
        $manifest = (new PaperDatasetManifestCodec())->decode((string) file_get_contents($dataset . '/manifest.json'));
        $cell = PaperExecutionCell::create($manifest->network, $manifest->venue, 'sha256:' . str_repeat('f', 64), 'scalper_micro', 'replay-equality-run');
        $events = [];
        foreach (file($dataset . '/events.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $events[] = PaperMarketEvent::fromArray($decoded);
        }

        $root = sys_get_temp_dir() . '/paper_equality_' . bin2hex(random_bytes(5));
        $store = new InMemoryPaperExecutionStore();
        $projection = new RecordingProjectionStore();
        $intents = new RecordingPaperOrderIntents();
        $thrown = false;
        try {
            $coordinator = $this->coordinator($store, $projection, $intents, $root, $crash ? static function (PaperCrashPoint $point) use (&$thrown): void {
                if (!$thrown && $point === PaperCrashPoint::AFTER_FAKE_EFFECT) {
                    $thrown = true;
                    throw new \RuntimeException('injected_crash');
                }
            } : null);
            $consumer = new PaperExecutionConsumer($coordinator, $store, $cell, PaperProfileEligibility::REFERENCE_ONLY);
            try {
                foreach ($events as $position => $event) {
                    $consumer->consumeReplay($manifest->datasetId, $position, $event);
                }
            } catch (\RuntimeException $exception) {
                self::assertTrue($crash);
                self::assertSame('injected_crash', $exception->getMessage());
                $coordinator = $this->coordinator($store, $projection, $intents, $root);
                $consumer = new PaperExecutionConsumer($coordinator, $store, $cell, PaperProfileEligibility::REFERENCE_ONLY);
                foreach ($events as $position => $event) {
                    $consumer->consumeReplay($manifest->datasetId, $position, $event);
                }
            }

            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-01T10:00:00Z')))->forCell($cell);
            $orders = [];
            foreach ($runtime->stateStore->getOrders('BTCUSDT') as $order) {
                if ($order->reduceOnly) { continue; }
                $orders[] = [
                    'exchange' => $order->exchange->value,
                    'client_order_id' => $order->clientOrderId,
                    'exchange_order_id' => $order->exchangeOrderId,
                    'status' => $order->status->value,
                    'metadata' => $order->metadata,
                ];
            }
            $projected = array_map(static fn ($event): array => [
                'type' => $event->eventType(),
                'payload' => $event->payload(),
            ], $projection->events);

            return json_decode(CanonicalJson::encode([
                'orders' => $orders,
                'projected' => $projected,
                'reserved' => array_map(static fn (array $row): array => [
                    'identity' => $row['identity'],
                    'provenance' => $row['provenance'],
                ], $intents->reserved),
                'acknowledged' => array_map(static fn (array $row): array => [
                    'identity' => $row['identity'],
                    'status' => $row['result']->status,
                    'exchange_order_id' => $row['result']->exchangeOrderId,
                ], $intents->acknowledged),
                'next_source_position' => $store->checkpoint($cell)->nextSourcePosition,
            ]), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            if (is_dir($root)) {
                foreach (glob($root . '/*') ?: [] as $file) { @unlink($file); }
                @rmdir($root);
            }
        }
    }

    private function coordinator(InMemoryPaperExecutionStore $store, RecordingProjectionStore $projection, RecordingPaperOrderIntents $intents, string $root, ?callable $crash = null): PaperExecutionCoordinator
    {
        return new PaperExecutionCoordinator($store, new PaperMarketStateProjector(new PaperKlineProvider()), new FixturePaperStrategy(), new PaperPreparedEffectCodec(), new PaperFakeRuntimeFactory($root, new MockClock('2026-08-01T10:00:00Z')), new PaperFakeEffectDispatcher($this->executionService(), new FakeExchangeEventNormalizer()), $projection, $intents, new PaperRuntimeGuard(), new PaperDatabaseGuard(new class implements PaperDatabaseInspectorInterface { public function inspect(): PaperDatabaseInspection { return new PaperDatabaseInspection('equality_paper_test', 0); } }), 'test', true, $crash);
    }

    private function executionService(): ExchangeExecutionService
    {
        $metrics = new TradeEntryMetricsService();
        $logger = new NullLogger();
        return new ExchangeExecutionService(new ExchangeAdapterRegistry([]), new ProtectionEnforcer(new EmergencyCloseService($metrics, $logger), $metrics, $logger), new IdempotencyPolicy(), new class implements OrderModePolicyInterface { public function enforce(OrderPlanModel $plan): void {} }, (new \ReflectionClass(TradeEntryConfigResolver::class))->newInstanceWithoutConstructor(), $logger);
    }
}
