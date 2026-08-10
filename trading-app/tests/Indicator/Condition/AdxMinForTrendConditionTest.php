<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\AdxMinForTrendCondition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdxMinForTrendCondition::class)]
final class AdxMinForTrendConditionTest extends TestCase
{
    public function testReadsDedicatedAdx14AndHonorsThePublishedThresholdBoundary(): void
    {
        $condition = new AdxMinForTrendCondition();

        self::assertFalse($condition->evaluate(['adx' => [14 => 19.999], 'threshold' => 20.0, 'timeframe' => '5m'])->passed);
        self::assertTrue($condition->evaluate(['adx' => [14 => 20.0], 'threshold' => 20.0, 'timeframe' => '5m'])->passed);
        self::assertTrue($condition->evaluate(['adx' => [14 => 20.001], 'threshold' => 20.0, 'timeframe' => '5m'])->passed);
    }

    public function testMissingAndNonFiniteAdxReject(): void
    {
        $condition = new AdxMinForTrendCondition();

        self::assertFalse($condition->evaluate(['timeframe' => '1h'])->passed);
        self::assertFalse($condition->evaluate(['adx' => [14 => INF], 'timeframe' => '1h'])->passed);
    }
}
