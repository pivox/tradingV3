<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\TradingCore\Decision\Dto\TradeCandidate;
use App\TradingCore\Entry\Dto\EntryZone;
use App\TradingCore\Mtf\Dto\MtfValidationResult;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Dto\OrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Enum\OrderPlanStatus;
use App\TradingCore\OrderPlan\Service\OrderPlanBuilder;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;
use App\TradingCore\Risk\Dto\LeverageCalculationResult;
use App\TradingCore\Risk\Dto\RiskCalculationResult;
use App\TradingCore\Risk\Enum\RiskSource;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderPlanBuilder::class)]
#[CoversClass(OrderPlanBuildRequest::class)]
final class OrderPlanBuilderTest extends TestCase
{
    public function testBuildsValidExecutableOrderPlanFromCompleteRequest(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request());

        self::assertSame(OrderPlanStatus::Valid, $plan->validation->status);
        self::assertTrue($plan->validation->isExecutable);
        self::assertSame('BTCUSDT', $plan->symbol);
        self::assertSame('long', $plan->side);
        self::assertSame('bitmart', $plan->exchange);
        self::assertSame('perpetual', $plan->marketType);
        self::assertSame(100.0, $plan->entryPrice);   // entryZone.center
        self::assertSame(12.0, $plan->quantity);       // riskCalculation.quantity
        self::assertSame(5, $plan->leverage);          // leverageCalculation.finalLeverage
        self::assertSame('trading_core_order_plan_builder', $plan->metadata['source']);
    }

    public function testUsesOrderPlanValidatorToProduceValidation(): void
    {
        // The validation carried by the built plan must match an independent
        // OrderPlanValidator run — proving the builder delegates to it.
        $plan = (new OrderPlanBuilder())->build($this->request());
        $independent = (new OrderPlanValidator())->validate($plan);

        self::assertSame($independent->status, $plan->validation->status);
        self::assertSame($independent->isExecutable, $plan->validation->isExecutable);
        self::assertSame($independent->invalidReasons, $plan->validation->invalidReasons);
    }

    public function testRejectsWhenEntryZoneMissing(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request(['entryZone' => null]));

        self::assertFalse($plan->validation->isExecutable);
        self::assertContains('entry_price_not_positive', $plan->validation->invalidReasons);
        self::assertContains('entry_zone', $plan->metadata['build_missing_inputs']);
    }

    public function testRejectsWhenRiskCalculationMissing(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request(['risk' => null]));

        self::assertFalse($plan->validation->isExecutable);
        self::assertContains('quantity_not_positive', $plan->validation->invalidReasons);
        self::assertContains('risk_calculation', $plan->metadata['build_missing_inputs']);
    }

    public function testRejectsWhenLeverageCalculationMissing(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request(['leverage' => null]));

        self::assertFalse($plan->validation->isExecutable);
        self::assertContains('leverage_not_positive', $plan->validation->invalidReasons);
        self::assertContains('leverage_calculation', $plan->metadata['build_missing_inputs']);
    }

    public function testRejectsWhenProtectionPlanMissing(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request(['protectionPlan' => null]));

        self::assertFalse($plan->validation->isExecutable);
        self::assertContains('protection_plan_missing', $plan->validation->invalidReasons);
        self::assertContains('protection_plan', $plan->metadata['build_missing_inputs']);
    }

    public function testRejectsWhenProtectionPlanInvalid(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request([
            'protectionPlan' => $this->protectionPlan(isValid: false),
        ]));

        self::assertFalse($plan->validation->isExecutable);
        self::assertContains('protection_plan_invalid', $plan->validation->invalidReasons);
    }

    public function testRejectsWhenStopLossMissing(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request([
            'protectionPlan' => $this->protectionPlan(stopLoss: null),
        ]));

        self::assertFalse($plan->validation->isExecutable);
        self::assertContains('stop_loss_missing', $plan->validation->invalidReasons);
    }

    public function testRejectsWhenStopLossNotFullSize(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request([
            'protectionPlan' => $this->protectionPlan(stopLoss: new StopLossResult(
                stopPrice: 98.0,
                stopPct: 0.02,
                stopDistance: 2.0,
                stopSource: 'pivot',
                isFullSize: false,
            )),
        ]));

        self::assertFalse($plan->validation->isExecutable);
        self::assertContains('stop_loss_not_full_size', $plan->validation->invalidReasons);
    }

    public function testRejectsUnusableCandidate(): void
    {
        // TradeCandidate is type-guaranteed present in OrderPlanBuildRequest, so an
        // unusable candidate (blank symbol / unknown direction) is the meaningful
        // "missing candidate" path: the plan must still be invalid.
        $plan = (new OrderPlanBuilder())->build($this->request([
            'candidate' => $this->candidate(symbol: '', direction: 'sideways'),
        ]));

        self::assertFalse($plan->validation->isExecutable);
        self::assertContains('symbol_missing', $plan->validation->invalidReasons);
        self::assertContains('side_invalid', $plan->validation->invalidReasons);
    }

    public function testPreservesIdempotencyKeyClientOrderIdAndMetadata(): void
    {
        $plan = (new OrderPlanBuilder())->build($this->request([
            'clientOrderId' => null,
            'idempotencyKey' => 'decision:BTCUSDT:long:v1',
            'metadata' => ['caller' => 'unit-test'],
        ]));

        self::assertSame('decision:BTCUSDT:long:v1', $plan->idempotencyKey);
        self::assertSame('decision:BTCUSDT:long:v1', $plan->decisionKey);
        self::assertNotNull($plan->clientOrderId);
        self::assertStringStartsWith('CID', $plan->clientOrderId);
        self::assertSame('unit-test', $plan->metadata['caller']);
    }

    // --- fixtures ---

    /**
     * @param array<string,mixed> $overrides
     */
    private function request(array $overrides = []): OrderPlanBuildRequest
    {
        return new OrderPlanBuildRequest(
            candidate: $overrides['candidate'] ?? $this->candidate(),
            entryZone: \array_key_exists('entryZone', $overrides) ? $overrides['entryZone'] : $this->entryZone(),
            riskCalculation: \array_key_exists('risk', $overrides) ? $overrides['risk'] : $this->risk(),
            leverageCalculation: \array_key_exists('leverage', $overrides) ? $overrides['leverage'] : $this->leverage(),
            protectionPlan: \array_key_exists('protectionPlan', $overrides) ? $overrides['protectionPlan'] : $this->protectionPlan(),
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            clientOrderId: \array_key_exists('clientOrderId', $overrides) ? $overrides['clientOrderId'] : 'CID-PREBUILT',
            idempotencyKey: \array_key_exists('idempotencyKey', $overrides) ? $overrides['idempotencyKey'] : 'decision:BTCUSDT:long',
            metadata: $overrides['metadata'] ?? [],
        );
    }

    private function candidate(string $symbol = 'BTCUSDT', string $direction = 'long'): TradeCandidate
    {
        return new TradeCandidate(
            symbol: $symbol,
            profile: 'scalper_micro',
            exchange: Exchange::BITMART,
            marketType: MarketType::PERPETUAL,
            direction: $direction,
            executionTimeframe: '1m',
            signalTime: new \DateTimeImmutable('2025-12-10T10:00:00+00:00'),
            validationResult: new MtfValidationResult(symbol: $symbol, profile: 'scalper_micro', status: 'READY'),
            dryRun: true,
        );
    }

    private function entryZone(float $center = 100.0): EntryZone
    {
        return new EntryZone(
            low: 99.5,
            high: 100.5,
            center: $center,
            widthPct: 0.01,
            ttlSec: 60,
            expiresAt: null,
            source: 'test',
            atrUsed: null,
            quantized: true,
        );
    }

    private function risk(?float $quantity = 12.0): RiskCalculationResult
    {
        return new RiskCalculationResult(
            effectiveRiskPct: 0.01,
            riskSource: RiskSource::FixedRiskPct,
            riskUsdt: 10.0,
            stopPct: 0.02,
            positionNotional: 1200.0,
            quantity: $quantity,
        );
    }

    private function leverage(int $final = 5): LeverageCalculationResult
    {
        return new LeverageCalculationResult(
            rawLeverage: 5.0,
            cappedLeverage: 5.0,
            finalLeverage: $final,
            capsApplied: [],
        );
    }

    private function protectionPlan(StopLossResult|false|null $stopLoss = false, bool $isValid = true): ProtectionPlan
    {
        $sl = $stopLoss === false
            ? new StopLossResult(
                stopPrice: 98.0,
                stopPct: 0.02,
                stopDistance: 2.0,
                stopSource: 'pivot',
                isFullSize: true,
            )
            : $stopLoss;

        return new ProtectionPlan(
            stopLoss: $sl,
            takeProfit: new TakeProfitResult(
                tp1Price: 103.0,
                tp2Price: null,
                expectedR: 1.5,
                expectedNetR: 1.4,
                tpPolicyApplied: 'r_multiple',
            ),
            liquidationCheck: new LiquidationCheckResult(
                isSafe: true,
                liquidationPrice: 80.0,
                liquidationDistancePct: 0.20,
                stopToLiquidationRatio: 0.1,
            ),
            isValid: $isValid,
            status: $isValid ? ProtectionPlanStatus::Valid : ProtectionPlanStatus::Invalid,
            invalidReasons: $isValid ? [] : ['liquidation_guard_unsafe'],
        );
    }
}
