<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\PriceRegimeOkLongCondition;
use App\Indicator\Condition\PriceRegimeOkShortCondition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceRegimeOkLongCondition::class)]
#[CoversClass(PriceRegimeOkShortCondition::class)]
final class PriceRegimeConditionTest extends TestCase
{
    public function testLongAdxThresholdBoundaryAndMissingDataFailClosed(): void
    {
        $condition = new PriceRegimeOkLongCondition();
        $context = ['close' => 101.0, 'ema' => [50 => 100.0, 200 => 102.0]];

        self::assertFalse($condition->evaluate($context + ['adx' => [14 => 19.999]])->passed);
        self::assertTrue($condition->evaluate($context + ['adx' => [14 => 20.0]])->passed);
        self::assertTrue($condition->evaluate($context + ['adx' => [14 => 20.001]])->passed);
        $missing = $condition->evaluate($context);
        self::assertFalse($missing->passed);
        self::assertTrue($missing->meta['missing_data']);
    }

    public function testShortAdxThresholdBoundaryAndMissingDataFailClosed(): void
    {
        $condition = new PriceRegimeOkShortCondition();
        $context = ['close' => 101.0, 'ema' => [50 => 102.0, 200 => 100.0]];

        self::assertFalse($condition->evaluate($context + ['adx' => [14 => 19.999]])->passed);
        self::assertTrue($condition->evaluate($context + ['adx' => [14 => 20.0]])->passed);
        self::assertTrue($condition->evaluate($context + ['adx' => [14 => 20.001]])->passed);
        $missing = $condition->evaluate($context);
        self::assertFalse($missing->passed);
        self::assertTrue($missing->meta['missing_data']);
    }
}
