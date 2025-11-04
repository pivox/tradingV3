<?php

declare(strict_types=1);

use App\Config\{MtfValidationConfig, TradingDecisionConfig};
use App\MtfValidator\Service\Decision\TradingDecisionEvaluation;
use App\MtfValidator\Service\Decision\TradingDecisionService;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\Repository\MtfSwitchRepository;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Types\Side;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TradingDecisionServiceTest extends TestCase
{
    /** @var TradingDecisionConfig&MockObject */
    private TradingDecisionConfig $decisionConfig;
    /** @var MtfValidationConfig&MockObject */
    private MtfValidationConfig $mtfConfig;
    /** @var MtfSwitchRepository&MockObject */
    private MtfSwitchRepository $switchRepository;
    private TradingDecisionService $service;
    private array $defaults;
    private array $decisionValues;

    protected function setUp(): void
    {
        $this->decisionConfig = $this->createMock(TradingDecisionConfig::class);
        $this->mtfConfig = $this->createMock(MtfValidationConfig::class);
        $this->switchRepository = $this->createMock(MtfSwitchRepository::class);

        $this->defaults = [
            'allowed_execution_timeframes' => ['1m', '5m'],
            'require_price_or_atr' => true,
            'risk_pct_percent' => 5.0,
            'initial_margin_usdt' => 50.0,
            'order_type' => 'limit',
            'open_type' => 'isolated',
            'order_mode' => 1,
            'stop_from' => 'risk',
            'atr_k' => 1.5,
            'market_max_spread_pct' => 0.001,
            'inside_ticks' => 1,
            'max_deviation_pct' => null,
            'implausible_pct' => null,
            'zone_max_deviation_pct' => null,
            'tp_policy' => 'pivot_conservative',
            'tp_buffer_pct' => null,
            'tp_buffer_ticks' => null,
            'tp_min_keep_ratio' => 0.95,
            'tp_max_extra_r' => null,
            'pivot_sl_policy' => 'nearest_below',
            'pivot_sl_buffer_pct' => 0.0015,
            'pivot_sl_min_keep_ratio' => 0.8,
            'timeframe_multipliers' => [
                '1m' => 1.0,
                '5m' => 0.75,
            ],
        ];

        $this->decisionValues = [
            'allowed_execution_timeframes' => ['1m', '5m'],
            'require_price_or_atr' => true,
        ];

        $this->decisionConfig
            ->method('get')
            ->willReturnCallback(fn(string $key, mixed $default = null) => $this->decisionValues[$key] ?? $default);

        $this->mtfConfig
            ->method('getDefaults')
            ->willReturnCallback(fn() => $this->defaults);

        $this->mtfConfig
            ->method('getDefault')
            ->willReturnCallback(fn(string $key, mixed $default = null) => $this->defaults[$key] ?? $default);

        $this->service = new TradingDecisionService(
            decisionConfig: $this->decisionConfig,
            mtfConfig: $this->mtfConfig,
            mtfSwitchRepository: $this->switchRepository,
            logger: new NullLogger(),
        );
    }

    public function testEvaluateReturnsNoneWhenStatusNotReady(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'PROCESSING');
        $decisionKey = $this->service->generateDecisionKey('BTCUSDT');

        $evaluation = $this->service->evaluate($symbolResult, $decisionKey);

        $this->assertSame(TradingDecisionEvaluation::ACTION_NONE, $evaluation->action);
        $this->assertSame($symbolResult, $evaluation->result);
    }

    public function testEvaluateSkipsWhenAtrRequiredButMissing(): void
    {
        $this->defaults['stop_from'] = 'atr';

        $symbolResult = new SymbolResultDto(
            symbol: 'BTCUSDT',
            status: 'READY',
            executionTf: '1m',
            signalSide: 'long',
            tradingDecision: null,
            error: null,
            context: null,
            currentPrice: 50250.0,
            atr: null,
        );

        $evaluation = $this->service->evaluate($symbolResult, $this->service->generateDecisionKey('BTCUSDT'));

        $this->assertSame(TradingDecisionEvaluation::ACTION_SKIP, $evaluation->action);
        $this->assertSame('unable_to_build_request', $evaluation->skipReason);
        $this->assertSame('unable_to_build_request', $evaluation->blockReason);
    }

    public function testEvaluateBuildsTradeRequestWhenConditionsMet(): void
    {
        $symbolResult = new SymbolResultDto(
            symbol: 'ETHUSDT',
            status: 'READY',
            executionTf: '5m',
            signalSide: 'short',
            tradingDecision: null,
            error: null,
            context: null,
            currentPrice: 3200.0,
            atr: 25.0,
        );

        $evaluation = $this->service->evaluate($symbolResult, $this->service->generateDecisionKey('ETHUSDT'));

        $this->assertSame(TradingDecisionEvaluation::ACTION_PREPARE, $evaluation->action);
        $this->assertNotNull($evaluation->tradeRequest);
        $this->assertSame('ETHUSDT', $evaluation->tradeRequest->symbol);
        $this->assertSame(Side::Short, $evaluation->tradeRequest->side);
        $this->assertGreaterThan(0.0, $evaluation->tradeRequest->riskPct);
    }

    public function testApplyPostExecutionGuardsDisablesSymbolOnSubmittedOrder(): void
    {
        $execution = new ExecutionResult(
            clientOrderId: 'cid',
            exchangeOrderId: 'ex',
            status: 'submitted',
            raw: []
        );

        $symbolResult = new SymbolResultDto('BTCUSDT', 'READY', '1m');

        $this->switchRepository
            ->expects($this->once())
            ->method('turnOffSymbolFor15Minutes')
            ->with('BTCUSDT');

        $this->service->applyPostExecutionGuards($symbolResult, $execution, false);
    }
}

