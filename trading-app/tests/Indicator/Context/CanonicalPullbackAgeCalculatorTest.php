<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Context;

use App\Indicator\Context\CanonicalPullbackAgeCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalPullbackAgeCalculator::class)]
final class CanonicalPullbackAgeCalculatorTest extends TestCase
{
    public function testCurrentMaCrossHasAgeZero(): void
    {
        self::assertSame(0, (new CanonicalPullbackAgeCalculator())->age(
            [99.0, 99.5, 101.0], [100.0, 100.0, 100.0],
            [110.0, 110.0, 110.0], [100.0, 100.0, 100.0], 3, 0.0015,
        ));
    }

    public function testNearVwapTwoClosedBarsAgoHasAgeTwo(): void
    {
        self::assertSame(2, (new CanonicalPullbackAgeCalculator())->age(
            [99.0, 99.0, 99.0, 99.0], [100.0, 100.0, 100.0, 100.0],
            [105.0, 100.1, 104.0, 105.0], [100.0, 100.0, 100.0, 100.0], 3, 0.0015,
        ));
    }

    public function testReturnsNullForNoConfirmationMisalignedSeriesAndInvalidInputs(): void
    {
        $calculator = new CanonicalPullbackAgeCalculator();

        self::assertNull($calculator->age([99.0, 99.0, 99.0], [100.0, 100.0, 100.0], [103.0, 104.0, 105.0], [100.0, 100.0, 100.0], 2, 0.0015));
        self::assertNull($calculator->age([99.0], [100.0], [100.0], [100.0], 3, 0.0015));
        self::assertNull($calculator->age([99.0, 101.0], [100.0], [100.0, 100.0], [100.0, 100.0], 3, 0.0015));
        self::assertNull($calculator->age([99.0, INF], [100.0, 100.0], [100.0, 100.0], [100.0, 100.0], 3, 0.0015));
        self::assertNull($calculator->age([99.0, 101.0], [100.0, 100.0], [100.0, 100.0], [100.0, 100.0], -1, 0.0015));
    }
}
