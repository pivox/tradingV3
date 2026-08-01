<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry;

use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\MtfValidator\Service\ExecutionSelectionService;
use App\MtfValidator\Service\Execution\ExecutionSelectorEngineInterface;
use App\MtfValidator\Service\TimeframeValidationService;
use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Provider\Context\ExchangeContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TradeEntryRequest::class)]
#[CoversClass(OrderPlanModel::class)]
#[CoversClass(LineageContext::class)]
#[CoversClass(ExecutionSelectionService::class)]
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

    private function identity(): LineageContext
    {
        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-1',
            'correlation_run_id' => 'corr-1',
            'orchestration_set_id' => 'set-1',
            'mode_id' => 'scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'scalping.trend_continuation.long',
            'setup_version' => '1.0.0',
            'config_hash' => 'sha256:' . str_repeat('a', 64),
            'condition_catalog_hash' => 'sha256:' . str_repeat('b', 64),
            'side' => 'LONG',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'effective_config_reference' => 'cfg://scalping/1.0.0',
            'effective_config_snapshot' => $this->effectiveSnapshot('a'),
        ]);
    }

    /** @return array<string,mixed> */
    private function effectiveSnapshot(string $configHash): array
    {
        return [
            'request' => [
                'mode_id' => 'scalping', 'mode_version' => '1.0.0',
                'setup_id' => 'scalping.trend_continuation.long', 'setup_version' => '1.0.0',
                'exchange' => 'fake', 'environment' => 'test', 'side' => 'long',
            ],
            'config_hash' => 'sha256:' . str_repeat($configHash, 64),
            'condition_catalog_hash' => 'sha256:' . str_repeat('b', 64),
            'executable' => true,
        ];
    }
}
