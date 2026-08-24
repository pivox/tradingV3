<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalStrategyEvidenceUnavailable::class)]
final class PaperCanonicalStrategyEvidenceUnavailableTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function stages(): iterable
    {
        yield 'projection' => ['indicatorProjection', 'paper_indicator_projection_unavailable'];
        yield 'book' => ['orderBook', 'paper_order_book_unavailable'];
        yield 'instrument' => ['instrument', 'paper_instrument_unavailable'];
        yield 'costs' => ['executionCosts', 'paper_execution_cost_unavailable'];
        yield 'plan' => ['orderPlan', 'paper_order_plan_unavailable'];
    }

    #[DataProvider('stages')]
    public function testEachEvidenceStageHasAnExactBoundedReason(string $factory, string $reason): void
    {
        $exception = PaperCanonicalStrategyEvidenceUnavailable::$factory();

        self::assertSame($reason, $exception->reasonCode);
        self::assertSame($reason, $exception->getMessage());
    }
}
