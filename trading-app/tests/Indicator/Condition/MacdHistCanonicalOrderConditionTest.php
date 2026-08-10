<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\MacdHistDecreasingNCondition;
use App\Indicator\Condition\MacdHistIncreasingNCondition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(MacdHistDecreasingNCondition::class)]
#[CoversClass(MacdHistIncreasingNCondition::class)]
final class MacdHistCanonicalOrderConditionTest extends TestCase
{
    public function testDecreasingConditionRequiresCanonicalOldestToNewestSeries(): void
    {
        $condition = new MacdHistDecreasingNCondition(new NullLogger());

        $passed = $condition->evaluate([
            'macd_hist_series' => [0.5, 0.3, 0.1],
            'series_order' => 'oldest_to_newest',
            'n' => 2,
            'eps' => 0.01,
        ]);
        $reversed = $condition->evaluate([
            'macd_hist_series' => [0.1, 0.3, 0.5],
            'series_order' => 'oldest_to_newest',
            'n' => 2,
            'eps' => 0.01,
        ]);
        $ambiguous = $condition->evaluate([
            'macd_hist_series' => [0.5, 0.3, 0.1],
            'n' => 2,
        ]);

        self::assertTrue($passed->passed);
        self::assertSame(-0.2, $passed->value);
        self::assertFalse($reversed->passed);
        self::assertFalse($ambiguous->passed);
        self::assertTrue($ambiguous->meta['missing_data']);
        self::assertSame('invalid_series_order', $ambiguous->meta['reason']);
    }

    public function testIncreasingConditionRequiresCanonicalOldestToNewestSeries(): void
    {
        $condition = new MacdHistIncreasingNCondition(new NullLogger());

        $below = $condition->evaluate([
            'macd_hist_series' => [0.1, 0.2, 0.199999],
            'series_order' => 'oldest_to_newest',
            'macd_hist_increasing_n' => 2,
        ]);
        $equal = $condition->evaluate([
            'macd_hist_series' => [0.1, 0.2, 0.2],
            'series_order' => 'oldest_to_newest',
            'macd_hist_increasing_n' => 2,
        ]);
        $above = $condition->evaluate([
            'macd_hist_series' => [0.1, 0.2, 0.200001],
            'series_order' => 'oldest_to_newest',
            'macd_hist_increasing_n' => 2,
        ]);
        $ambiguous = $condition->evaluate([
            'macd_hist_series' => [0.1, 0.2, 0.3],
            'macd_hist_increasing_n' => 2,
        ]);

        self::assertFalse($below->passed);
        self::assertFalse($equal->passed);
        self::assertTrue($above->passed);
        self::assertFalse($ambiguous->passed);
        self::assertTrue($ambiguous->meta['missing_data']);
        self::assertSame('invalid_series_order', $ambiguous->meta['reason']);
    }
}
