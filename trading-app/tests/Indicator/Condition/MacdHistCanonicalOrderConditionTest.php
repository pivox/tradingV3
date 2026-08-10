<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\MacdHistDecreasingNCondition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(MacdHistDecreasingNCondition::class)]
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
}
