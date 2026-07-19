<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Fake\FakeLiquidationCalculator;
use App\Exchange\Fake\FakeLiquidationInput;
use App\Exchange\Fake\FakeLiquidationPolicy;
use App\Exchange\Fake\FakeLiquidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeLiquidationCalculator::class)]
#[CoversClass(FakeLiquidationInput::class)]
#[CoversClass(FakeLiquidationPolicy::class)]
#[CoversClass(FakeLiquidationResult::class)]
final class FakeLiquidationCalculatorTest extends TestCase
{
    public function testCalculatesExactLongThresholdGuardAndStates(): void
    {
        $calculator = self::calculator();

        $safe = $calculator->calculate(self::input(ExchangePositionSide::LONG, '25000'));
        $guard = $calculator->calculate(self::input(ExchangePositionSide::LONG, '22800'));
        $threshold = $calculator->calculate(self::input(
            ExchangePositionSide::LONG,
            '22613.065326633166',
        ));
        $gap = $calculator->calculate(self::input(ExchangePositionSide::LONG, '22000'));

        self::assertSame(FakeLiquidationResult::READY, $safe->status);
        self::assertNull($safe->reason);
        self::assertSame('22613.065326633166', $safe->liquidationPrice);
        self::assertSame('22863.065326633166', $safe->guardPrice);
        self::assertSame('250.000000000000', $safe->guardBufferAmount);
        self::assertSame(FakeLiquidationResult::SAFE, $safe->markState);
        self::assertSame(FakeLiquidationResult::GUARD, $guard->markState);
        self::assertSame(FakeLiquidationResult::LIQUIDATE, $threshold->markState);
        self::assertSame(FakeLiquidationResult::LIQUIDATE, $gap->markState);
        self::assertSame(FakeLiquidationPolicy::MODEL_VERSION, $safe->toAuditMetadata()['liquidation_model_version']);
        self::assertSame('isolated', $safe->toAuditMetadata()['liquidation_margin_mode']);
    }

    public function testCalculatesExactShortThresholdGuardAndStates(): void
    {
        $calculator = self::calculator();

        $safe = $calculator->calculate(self::input(ExchangePositionSide::SHORT, '25000'));
        $guard = $calculator->calculate(self::input(ExchangePositionSide::SHORT, '27200'));
        $threshold = $calculator->calculate(self::input(
            ExchangePositionSide::SHORT,
            '27363.184079601990',
        ));
        $gap = $calculator->calculate(self::input(ExchangePositionSide::SHORT, '28000'));

        self::assertSame(FakeLiquidationResult::READY, $safe->status);
        self::assertSame('27363.184079601990', $safe->liquidationPrice);
        self::assertSame('27113.184079601990', $safe->guardPrice);
        self::assertSame('250.000000000000', $safe->guardBufferAmount);
        self::assertSame(FakeLiquidationResult::SAFE, $safe->markState);
        self::assertSame(FakeLiquidationResult::GUARD, $guard->markState);
        self::assertSame(FakeLiquidationResult::LIQUIDATE, $threshold->markState);
        self::assertSame(FakeLiquidationResult::LIQUIDATE, $gap->markState);
    }

    public function testOneTimesLongHasValidZeroThresholdAndDistinctGuard(): void
    {
        $result = self::calculator()->calculate(new FakeLiquidationInput(
            side: ExchangePositionSide::LONG,
            marginMode: 'isolated',
            quantity: '1',
            entryPrice: '25000',
            isolatedMargin: '25000',
            contractSize: '1',
            maintenanceMarginRate: '0.005',
            markPrice: '25000',
        ));

        self::assertSame(FakeLiquidationResult::READY, $result->status);
        self::assertSame('0.000000000000', $result->liquidationPrice);
        self::assertSame('250.000000000000', $result->guardPrice);
        self::assertSame(FakeLiquidationResult::SAFE, $result->markState);
    }

    public function testCrossMarginIsExplicitlyUnsupported(): void
    {
        $result = self::calculator()->calculate(new FakeLiquidationInput(
            side: ExchangePositionSide::LONG,
            marginMode: 'cross',
            quantity: '1',
            entryPrice: '25000',
            isolatedMargin: '2500',
            contractSize: '1',
            maintenanceMarginRate: '0.005',
            markPrice: '25000',
        ));

        self::assertSame(FakeLiquidationResult::UNSUPPORTED, $result->status);
        self::assertSame('liquidation_cross_margin_unsupported', $result->reason);
        self::assertSame(FakeLiquidationResult::UNKNOWN, $result->markState);
        self::assertNull($result->liquidationPrice);
        self::assertNull($result->guardPrice);
    }

    /**
     * @return iterable<string,array{array<string,string|null>,string}>
     */
    public static function invalidMetadataProvider(): iterable
    {
        yield 'quantity unknown' => [['quantity' => null], 'liquidation_quantity_unknown'];
        yield 'quantity malformed' => [['quantity' => 'not-a-decimal'], 'liquidation_quantity_invalid'];
        yield 'quantity zero' => [['quantity' => '0'], 'liquidation_quantity_invalid'];
        yield 'entry unknown' => [['entryPrice' => null], 'liquidation_entry_price_unknown'];
        yield 'entry negative' => [['entryPrice' => '-1'], 'liquidation_entry_price_invalid'];
        yield 'margin unknown' => [['isolatedMargin' => null], 'liquidation_isolated_margin_unknown'];
        yield 'margin negative' => [['isolatedMargin' => '-1'], 'liquidation_isolated_margin_invalid'];
        yield 'contract unknown' => [['contractSize' => null], 'liquidation_contract_size_unknown'];
        yield 'contract zero' => [['contractSize' => '0'], 'liquidation_contract_size_invalid'];
        yield 'maintenance unknown' => [['maintenanceMarginRate' => null], 'liquidation_maintenance_margin_rate_unknown'];
        yield 'maintenance zero' => [['maintenanceMarginRate' => '0'], 'liquidation_maintenance_margin_rate_invalid'];
        yield 'maintenance one' => [['maintenanceMarginRate' => '1'], 'liquidation_maintenance_margin_rate_invalid'];
        yield 'mark unknown' => [['markPrice' => null], 'liquidation_mark_price_unknown'];
        yield 'mark zero' => [['markPrice' => '0'], 'liquidation_mark_price_invalid'];
    }

    /** @param array<string,string|null> $overrides */
    #[DataProvider('invalidMetadataProvider')]
    public function testUnknownOrInvalidMetadataFailsClosed(array $overrides, string $reason): void
    {
        $values = array_replace([
            'quantity' => '1',
            'entryPrice' => '25000',
            'isolatedMargin' => '2500',
            'contractSize' => '1',
            'maintenanceMarginRate' => '0.005',
            'markPrice' => '25000',
        ], $overrides);

        $result = self::calculator()->calculate(new FakeLiquidationInput(
            side: ExchangePositionSide::LONG,
            marginMode: 'isolated',
            quantity: $values['quantity'],
            entryPrice: $values['entryPrice'],
            isolatedMargin: $values['isolatedMargin'],
            contractSize: $values['contractSize'],
            maintenanceMarginRate: $values['maintenanceMarginRate'],
            markPrice: $values['markPrice'],
        ));

        self::assertSame(FakeLiquidationResult::INVALID, $result->status);
        self::assertSame($reason, $result->reason);
        self::assertSame(FakeLiquidationResult::UNKNOWN, $result->markState);
        self::assertNull($result->liquidationPrice);
    }

    public function testInvalidGuardPolicyFailsClosed(): void
    {
        $result = (new FakeLiquidationCalculator(new FakeLiquidationPolicy(
            guardBufferRate: '0.95',
            liquidationFeeRate: '0.005',
        )))->calculate(self::input(ExchangePositionSide::LONG, '25000'));

        self::assertSame(FakeLiquidationResult::INVALID, $result->status);
        self::assertSame('liquidation_guard_buffer_invalid', $result->reason);
    }

    public function testCalculatesKnownSeparateLiquidationFee(): void
    {
        $calculator = self::calculator();

        self::assertSame('110.000000000000', $calculator->liquidationFeeUsdt(
            quantity: '1',
            markPrice: '22000',
            contractSize: '1',
        ));
        self::assertSame(FakeLiquidationPolicy::FEE_MODEL_VERSION, $calculator->policy()->feeModelVersion);
        self::assertSame('USDT', $calculator->policy()->feeCurrency);
        self::assertNotSame('0.000000000000', $calculator->liquidationFeeUsdt('1', '22000', '1'));
    }

    /**
     * @return iterable<string,array{string,string,string,string}>
     */
    public static function invalidFeeInputProvider(): iterable
    {
        yield 'quantity' => ['0', '22000', '1', 'liquidation_fee_quantity_invalid'];
        yield 'mark' => ['1', '0', '1', 'liquidation_fee_mark_price_invalid'];
        yield 'contract' => ['1', '22000', 'invalid', 'liquidation_fee_contract_size_invalid'];
    }

    #[DataProvider('invalidFeeInputProvider')]
    public function testLiquidationFeeFailsClosedOnInvalidInputs(
        string $quantity,
        string $markPrice,
        string $contractSize,
        string $reason,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        self::calculator()->liquidationFeeUsdt($quantity, $markPrice, $contractSize);
    }

    private static function calculator(): FakeLiquidationCalculator
    {
        return new FakeLiquidationCalculator(new FakeLiquidationPolicy(
            guardBufferRate: '0.01',
            liquidationFeeRate: '0.005',
        ));
    }

    private static function input(ExchangePositionSide $side, string $markPrice): FakeLiquidationInput
    {
        return new FakeLiquidationInput(
            side: $side,
            marginMode: 'isolated',
            quantity: '1',
            entryPrice: '25000',
            isolatedMargin: '2500',
            contractSize: '1',
            maintenanceMarginRate: '0.005',
            markPrice: $markPrice,
        );
    }
}
