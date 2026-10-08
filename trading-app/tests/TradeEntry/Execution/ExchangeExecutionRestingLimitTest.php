<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Execution;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\TradeEntryConfigProvider;
use App\Config\TradeEntryConfigResolver;
use App\Config\TradeEntryModeContext;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use App\Provider\Context\ExchangeContext;
use App\Tests\TradeEntry\Support\ScriptedExchangeAdapter;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Execution\EmergencyCloseService;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\Execution\ProtectionEnforcer;
use App\TradeEntry\Message\LimitFillWatchMessage;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\TradeEntry\Policy\OrderModePolicyInterface;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\TradeEntry\Types\Side;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[CoversClass(ExchangeExecutionService::class)]
#[CoversClass(LimitFillWatchMessage::class)]
#[CoversClass(OrderPlanModel::class)]
final class ExchangeExecutionRestingLimitTest extends TestCase
{
    private ScriptedExchangeAdapter $adapter;

    /** @var list<Envelope> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->adapter = new ScriptedExchangeAdapter();
        $this->dispatched = [];
    }

    /**
     * @return iterable<string,array{int}>
     */
    public static function restingModes(): iterable
    {
        yield 'gtc' => [1];
        yield 'maker only' => [4];
    }

    #[DataProvider('restingModes')]
    public function testMakerAndGtcLimitEntriesRestAndSeedTheFillWatch(int $orderMode): void
    {
        $result = $this->service($this->bus())->execute($this->plan('limit', $orderMode), 'decision-rest', 'unit', null, 'client-rest-1');

        self::assertSame(ExecutionResult::STATUS_ENTRY_SUBMITTED, $result->status);
        self::assertSame('entry_resting_limit_watch_scheduled', $result->raw['reason']);
        self::assertSame(45, $result->raw['watch_seconds']);
        self::assertSame([], $this->adapter->cancelled);
        self::assertCount(1, $this->adapter->placed);

        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0]->getMessage();
        self::assertInstanceOf(LimitFillWatchMessage::class, $message);
        self::assertSame('BTCUSDT', $message->symbol);
        self::assertSame('ord-1', $message->exchangeOrderId);
        self::assertSame('client-rest-1', $message->clientOrderId);
        self::assertSame('BUY', $message->side);
        self::assertSame(45, $message->cancelAfterSec);
        self::assertSame(0, $message->tries);
        self::assertFalse($message->cancelIssued);
        self::assertSame('decision-rest', $message->decisionKey);
        self::assertSame('unit', $message->mode);
        self::assertSame('okx', $message->lifecycleContext['exchange'] ?? null);
        self::assertSame('perpetual', $message->lifecycleContext['market_type'] ?? null);
        self::assertSame(24800.0, $message->plan['stop'] ?? null);
        self::assertSame('long', $message->plan['side'] ?? null);
        self::assertSame($orderMode, $message->plan['order_mode'] ?? null);

        $delay = $this->dispatched[0]->last(DelayStamp::class);
        self::assertSame(5000, $delay?->getDelay());
    }

    /**
     * @return iterable<string,array{string,int}>
     */
    public static function takerEntries(): iterable
    {
        yield 'fok' => ['limit', 2];
        yield 'ioc' => ['limit', 3];
        yield 'market' => ['market', 1];
    }

    #[DataProvider('takerEntries')]
    public function testTakerEntriesKeepTheImmediateCancelBehaviour(string $orderType, int $orderMode): void
    {
        $result = $this->service($this->bus())->execute($this->plan($orderType, $orderMode), 'decision-taker', 'unit');

        self::assertSame('entry_pending_cancelled_without_fill', $result->raw['reason']);
        self::assertSame([], $this->dispatched);
        self::assertCount(1, $this->adapter->cancelled);
        self::assertSame('protective', $this->adapter->cancelled[0]->metadata['write_kind']);
    }

    public function testWithoutABusTheEntryRemainderIsCancelledAsBefore(): void
    {
        $result = $this->service(null)->execute($this->plan('limit', 1), 'decision-nobus', 'unit');

        self::assertSame('entry_pending_cancelled_without_fill', $result->raw['reason']);
        self::assertCount(1, $this->adapter->cancelled);
    }

    public function testSeedFailureFallsBackToCancellingTheRemainder(): void
    {
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('transport down');
            }
        };

        $result = $this->service($bus)->execute($this->plan('limit', 1), 'decision-seedfail', 'unit');

        self::assertSame('entry_pending_cancelled_without_fill', $result->raw['reason']);
        self::assertCount(1, $this->adapter->cancelled);
    }

    private function bus(): MessageBusInterface
    {
        $test = $this;

        return new class($test) implements MessageBusInterface {
            public function __construct(private readonly ExchangeExecutionRestingLimitTest $test)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = new Envelope($message, $stamps);
                $this->test->record($envelope);

                return $envelope;
            }
        };
    }

    public function record(Envelope $envelope): void
    {
        $this->dispatched[] = $envelope;
    }

    private function service(?MessageBusInterface $bus): ExchangeExecutionService
    {
        $logger = new NullLogger();
        $metrics = new TradeEntryMetricsService();

        return new ExchangeExecutionService(
            new ExchangeAdapterRegistry([$this->adapter]),
            new ProtectionEnforcer(new EmergencyCloseService($metrics, $logger), $metrics, $logger),
            new IdempotencyPolicy(),
            new class implements OrderModePolicyInterface {
                public function enforce(OrderPlanModel $plan): void
                {
                }
            },
            $this->configResolver(),
            $logger,
            $bus,
        );
    }

    private function configResolver(): TradeEntryConfigResolver
    {
        $projectDir = sys_get_temp_dir() . '/trade_entry_resting_' . bin2hex(random_bytes(4));
        mkdir($projectDir . '/config/app', 0777, true);
        file_put_contents($projectDir . '/config/app/trade_entry.unit.yaml', <<<YAML
trade_entry:
  defaults:
    initial_margin_usdt: 100.0
  entry:
    limit_order_ttl_sec: 45
  leverage: {}
YAML);
        $provider = new TradeEntryConfigProvider(new ParameterBag(['kernel.project_dir' => $projectDir, 'mode' => []]));

        return new TradeEntryConfigResolver(
            provider: $provider,
            modeContext: new TradeEntryModeContext($provider, 'unit', new NullLogger()),
            logger: new NullLogger(),
        );
    }

    private function plan(string $orderType, int $orderMode): OrderPlanModel
    {
        return new OrderPlanModel(
            symbol: 'BTCUSDT',
            side: Side::Long,
            orderType: $orderType,
            openType: 'isolated',
            orderMode: $orderMode,
            entry: 25000.0,
            stop: 24800.0,
            takeProfit: 25200.0,
            size: 1,
            leverage: 3,
            pricePrecision: 2,
            contractSize: 1.0,
            exchangeContext: new ExchangeContext(Exchange::OKX, MarketType::PERPETUAL),
        );
    }
}
