<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Exchange\Fake\FakeFillCost;
use App\Exchange\Fake\FakeFillCostModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeFillCostModel::class)]
#[CoversClass(FakeFillCost::class)]
final class FakeFillCostModelTest extends TestCase
{
    public function testPostOnlyFillHasExplicitMakerZeroSlippage(): void
    {
        $cost = (new FakeFillCostModel())->forFill(
            quantity: 2.0,
            price: 100.0,
            contractSize: 3.0,
            postOnly: true,
        );

        self::assertSame('maker', $cost->liquidityRole);
        self::assertSame(0.0, $cost->spreadCostUsdt);
        self::assertSame(0.0, $cost->slippageCostUsdt);
        self::assertSame('fixed_adverse_slippage_bps_v1', $cost->modelVersion);
        self::assertSame('top_of_book_embedded_spread_v1', $cost->spreadModelVersion);
    }

    public function testNonPostOnlyFillHasFiveBpsTakerSlippageScaledByContractSize(): void
    {
        $cost = (new FakeFillCostModel())->forFill(
            quantity: 2.0,
            price: 100.0,
            contractSize: 3.0,
            postOnly: false,
        );

        self::assertSame('taker', $cost->liquidityRole);
        self::assertSame(0.0, $cost->spreadCostUsdt);
        self::assertSame(0.3, $cost->slippageCostUsdt);
        self::assertSame(5.0, FakeFillCostModel::TAKER_SLIPPAGE_BPS);
    }

    /**
     * @return iterable<string,array{float,float,float}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'zero quantity' => [0.0, 100.0, 1.0];
        yield 'negative quantity' => [-1.0, 100.0, 1.0];
        yield 'non-finite quantity' => [NAN, 100.0, 1.0];
        yield 'zero price' => [1.0, 0.0, 1.0];
        yield 'negative price' => [1.0, -100.0, 1.0];
        yield 'non-finite price' => [1.0, INF, 1.0];
        yield 'zero contract size' => [1.0, 100.0, 0.0];
        yield 'negative contract size' => [1.0, 100.0, -1.0];
        yield 'non-finite contract size' => [1.0, 100.0, INF];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputProvider')]
    public function testRejectsInvalidFillInputs(float $quantity, float $price, float $contractSize): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FakeFillCostModel())->forFill(
            quantity: $quantity,
            price: $price,
            contractSize: $contractSize,
            postOnly: false,
        );
    }
}
