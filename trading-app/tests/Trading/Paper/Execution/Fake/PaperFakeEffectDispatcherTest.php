<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\TradeEntryConfigResolver;
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
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperPreparedDecision;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperFakeEffectDispatcher::class)]
final class PaperFakeEffectDispatcherTest extends TestCase
{
    public function testDuplicatePreparedEffectMutatesFakeStateOnlyOnce(): void
    {
        $root = sys_get_temp_dir() . '/paper_fake_dispatch_' . bin2hex(random_bytes(6));
        try {
            $cell = PaperExecutionCell::create(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'sha256:' . str_repeat('c', 64), 'scalper_micro', 'run-1');
            $runtime = (new PaperFakeRuntimeFactory($root, new MockClock('2026-08-01T10:00:00Z')))->forCell($cell);
            $policy = new RecordingPaperOrderModePolicy();
            $dispatcher = new PaperFakeEffectDispatcher($this->executionService($policy), new FakeExchangeEventNormalizer());
            $decision = $this->decision($cell);
            $decision = new PaperPreparedDecision(
                $dispatcher->prepare($decision->prepared),
                $decision->orderIntentIdentity,
                $decision->provenance,
            );

            $first = $dispatcher->dispatch($runtime, $decision);
            $balanceAfterFirst = $runtime->stateStore->totalBalanceUsdt();
            $second = $dispatcher->dispatch($runtime, $decision);

            $entries = array_values(array_filter(
                $runtime->stateStore->getOrders('BTCUSDT'),
                static fn ($order): bool => !$order->reduceOnly,
            ));
            self::assertCount(1, $entries);
            self::assertNotEmpty($first->events);
            self::assertSame([], $second->events);
            self::assertTrue($second->idempotentReplay);
            self::assertSame($balanceAfterFirst, $runtime->stateStore->totalBalanceUsdt());
            self::assertSame(1, $policy->calls, 'The durable plan must pass through standard preparation exactly once.');
        } finally {
            if (is_dir($root)) {
                foreach (glob($root . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($root);
            }
        }
    }

    private function decision(PaperExecutionCell $cell): PaperPreparedDecision
    {
        $prepared = new PreparedTradeEntry(
            new OrderPlanModel(
                'BTCUSDT', Side::Long, 'market', 'isolated', 1, 25000.0, 24800.0, 25200.0,
                1, 3, 2, 1.0, exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
            ),
            null,
            'decision-1',
            'paper-trade-1',
            (new LifecycleContextBuilder('BTCUSDT'))->withDecisionKey('decision-1'),
            'scalper_micro',
            '1m',
        );

        return new PaperPreparedDecision(
            $prepared,
            ['client_order_id' => 'CIDPAPER0001', 'order_intent_id' => 42],
            $cell->provenance(PaperProfileEligibility::REFERENCE_ONLY),
        );
    }

    private function executionService(?OrderModePolicyInterface $policy = null): ExchangeExecutionService
    {
        $metrics = new TradeEntryMetricsService();
        $logger = new NullLogger();
        $resolver = (new \ReflectionClass(TradeEntryConfigResolver::class))->newInstanceWithoutConstructor();

        return new ExchangeExecutionService(
            new ExchangeAdapterRegistry([]),
            new ProtectionEnforcer(new EmergencyCloseService($metrics, $logger), $metrics, $logger),
            new IdempotencyPolicy(),
            $policy ?? new class implements OrderModePolicyInterface {
                public function enforce(OrderPlanModel $plan): void
                {
                }
            },
            $resolver,
            $logger,
        );
    }
}

final class RecordingPaperOrderModePolicy implements OrderModePolicyInterface
{
    public int $calls = 0;

    public function enforce(OrderPlanModel $plan): void
    {
        ++$this->calls;
    }
}
