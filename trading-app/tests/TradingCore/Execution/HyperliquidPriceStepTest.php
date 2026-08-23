<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Hyperliquid\HyperliquidPriceStep;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPriceStep::class)]
final class HyperliquidPriceStepTest extends TestCase
{
    public function testCombinesTheDecimalCapAndFiveSignificantFigureRule(): void
    {
        foreach ([
            ['9999.9', '0.1', '0.1'],
            ['10000.1', '0.1', '1'],
            ['65001', '0.1', '1'],
            ['4000.5', '0.01', '0.1'],
            ['0.12345', '0.0001', '0.0001'],
        ] as [$price, $minimumTick, $expected]) {
            self::assertSame($expected, (string) HyperliquidPriceStep::forPrice(
                BigDecimal::of($price),
                BigDecimal::of($minimumTick),
            ));
        }
    }

    public function testRejectsNonPositiveInputs(): void
    {
        foreach ([['0', '0.1'], ['1', '0']] as [$price, $minimumTick]) {
            try {
                HyperliquidPriceStep::forPrice(
                    BigDecimal::of($price),
                    BigDecimal::of($minimumTick),
                );
                self::fail('Non-positive Hyperliquid price step input was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('hyperliquid_price_step_input_invalid', $exception->getMessage());
            }
        }
    }
}
