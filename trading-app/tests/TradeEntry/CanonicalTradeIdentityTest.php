<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry;

use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Builder\TradeEntryRequestBuilder;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\MtfValidator\Service\ExecutionSelectionService;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\MtfValidator\Service\Execution\ExecutionSelectorEngineInterface;
use App\MtfValidator\Service\TimeframeValidationService;
use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Provider\Context\ExchangeContext;
use App\Config\TradeEntryConfigProvider;
use App\Config\TradeEntryModeContext;
use App\Config\ZoneDeviationOverrideStore;
use Psr\Log\NullLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TradeEntryRequest::class)]
#[CoversClass(OrderPlanModel::class)]
#[CoversClass(LineageContext::class)]
#[CoversClass(ExecutionSelectionService::class)]
#[CoversClass(TradingDecisionHandler::class)]
final class CanonicalTradeIdentityTest extends TestCase
{
    public function testTradeEntryRejectsMissingCanonicalIdentity(): void
    {
        $request = new TradeEntryRequest('BTCUSDT', Side::Long);

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_missing:lineage_context');

        $request->canonicalIdentity();
    }

    public function testTradeEntryRejectsSideMismatchBeforeAlgorithms(): void
    {
        $request = new TradeEntryRequest('BTCUSDT', Side::Short, lineageContext: $this->identity());

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:side');

        $request->canonicalIdentity();
    }

    public function testOrderPlanCopyKeepsExactCanonicalIdentity(): void
    {
        $identity = $this->identity()->withDecision(
            '018f47a2-4f42-7e1b-8d3a-4dc9571bb11b',
            'decision-key-1',
        );
        $plan = new OrderPlanModel(
            'BTCUSDT', Side::Long, 'limit', 'isolated', 4,
            100.0, 99.0, 102.0, 1, 2, 2, 1.0,
            lineageContext: $identity,
        );

        $market = $plan->copyWith(orderType: 'market', orderMode: 1);

        self::assertSame($identity, $market->lineageContext);
        self::assertSame($identity->toArray(), $market->canonicalSnapshot());
    }

    public function testSelectorMissingTypedMetricsReturnsNoSelectionWithoutUsingStaleState(): void
    {
        /** @var TimeframeValidationService $validator */
        $validator = (new \ReflectionClass(TimeframeValidationService::class))->newInstanceWithoutConstructor();
        $engine = $this->createMock(ExecutionSelectorEngineInterface::class);
        $engine->expects(self::never())->method('select');
        $service = new ExecutionSelectionService($validator, $engine);
        $identity = $this->identity();

        $selection = $service->selectExecutionTimeframe(
            'BTCUSDT', 'scalping', ['1m'], [], [],
            new ContextDecisionDto(true, null, []),
            ExchangeContext::fromValues('fake', 'perpetual'),
            $identity,
            null,
        );

        self::assertNull($selection->selectedTimeframe);
        self::assertSame('selector_metrics_missing', $selection->reasonIfNone);
    }

    public function testModernHandlerResolvesTradeEntryOnlyFromEffectiveSnapshot(): void
    {
        /** @var TradingDecisionHandler $handler */
        $handler = (new \ReflectionClass(TradingDecisionHandler::class))->newInstanceWithoutConstructor();
        $resolve = new \ReflectionMethod(TradingDecisionHandler::class, 'resolveTradeEntryConfig');

        [$mode, $config] = $resolve->invoke($handler, $this->identity(), 'legacy-mode-must-not-be-read');

        self::assertSame('scalping', $mode);
        self::assertSame(50.0, $config->getDefault('initial_margin_usdt'));
    }

    public function testModernRequestUsesModeOwnedQuoteBudgetAsInitialMargin(): void
    {
        /** @var TradeEntryConfigProvider $provider */
        $provider = (new \ReflectionClass(TradeEntryConfigProvider::class))->newInstanceWithoutConstructor();
        /** @var TradeEntryModeContext $modeContext */
        $modeContext = (new \ReflectionClass(TradeEntryModeContext::class))->newInstanceWithoutConstructor();
        $builder = new TradeEntryRequestBuilder(
            $provider,
            $modeContext,
            new ZoneDeviationOverrideStore(sys_get_temp_dir() . '/canonical-zone-overrides-' . bin2hex(random_bytes(4)) . '.json'),
            new NullLogger(),
        );

        $request = $builder->fromMtfSignal(
            'BTCUSDT', 'LONG', '1m', 100.0, 1.0, 'ignored',
            exchangeContext: ExchangeContext::fromValues('fake', 'perpetual'),
            lineageContext: $this->identity()->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-key'),
        );

        self::assertNotNull($request);
        self::assertSame(50.0, $request->initialMarginUsdt);
    }

    private function identity(): LineageContext
    {
        return CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config());
    }
}
