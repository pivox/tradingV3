<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use App\Indicator\Condition\CloseGtEma200Condition;
use App\Indicator\Condition\CloseGtPriorHighCondition;
use App\Indicator\Condition\CloseLtEma200Condition;
use App\Indicator\Condition\CloseLtPriorLowCondition;
use App\Indicator\Condition\ConditionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CloseGtEma200Condition::class)]
#[CoversClass(CloseLtEma200Condition::class)]
#[CoversClass(CloseGtPriorHighCondition::class)]
#[CoversClass(CloseLtPriorLowCondition::class)]
final class SwingBreakoutConditionsTest extends TestCase
{
    /** @return iterable<string, array{class-string<ConditionInterface>, string, string}> */
    public static function conditions(): iterable
    {
        yield 'close above ema200' => [CloseGtEma200Condition::class, 'close_gt_ema_200', 'long'];
        yield 'close below ema200' => [CloseLtEma200Condition::class, 'close_lt_ema_200', 'short'];
        yield 'close above prior high' => [CloseGtPriorHighCondition::class, 'close_gt_prior_high', 'long'];
        yield 'close below prior low' => [CloseLtPriorLowCondition::class, 'close_lt_prior_low', 'short'];
    }

    /** @param class-string<ConditionInterface> $class */
    #[DataProvider('conditions')]
    public function testConditionsAreRegisteredForTheFourHourTimeframeAndOneSide(string $class, string $name, string $side): void
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(AsIndicatorCondition::class);
        self::assertCount(1, $attributes);
        $attribute = $attributes[0]->newInstance();

        self::assertSame($name, (new $class())->getName());
        self::assertSame($name, $attribute->name);
        self::assertSame(['4h'], $attribute->timeframes);
        self::assertSame($side, $attribute->side);
    }

    public function testEmaConditionsCompareTheCloseStrictlyWithTheEma200(): void
    {
        $above = ['close' => 101.5, 'ema' => [200 => 100.0]];
        $equal = ['close' => 100.0, 'ema' => [200 => 100.0]];
        $below = ['close' => 98.0, 'ema' => [200 => 100.0]];

        self::assertTrue((new CloseGtEma200Condition())->evaluate($above)->passed);
        self::assertSame(1.5, (new CloseGtEma200Condition())->evaluate($above)->value);
        self::assertSame(100.0, (new CloseGtEma200Condition())->evaluate($above)->threshold);
        self::assertFalse((new CloseGtEma200Condition())->evaluate($equal)->passed);
        self::assertFalse((new CloseLtEma200Condition())->evaluate($equal)->passed);
        self::assertTrue((new CloseLtEma200Condition())->evaluate($below)->passed);
        self::assertSame(-2.0, (new CloseLtEma200Condition())->evaluate($below)->value);
    }

    public function testEmaConditionsAcceptIntegralNumbersAndStringIndexedPeriods(): void
    {
        // Canonical JSON turns 101.0 into 101 and integer keys into strings.
        $context = ['close' => 101, 'ema' => ['200' => 100]];

        self::assertTrue((new CloseGtEma200Condition())->evaluate($context)->passed);
        self::assertFalse((new CloseLtEma200Condition())->evaluate($context)->passed);
        self::assertArrayNotHasKey('missing_data', (new CloseLtEma200Condition())->evaluate($context)->meta);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEmaContexts(): iterable
    {
        yield 'missing close' => [['ema' => [200 => 100.0]]];
        yield 'missing ema200' => [['close' => 100.0, 'ema' => [50 => 100.0]]];
        yield 'string close' => [['close' => '101.0', 'ema' => [200 => 100.0]]];
        yield 'non-finite ema200' => [['close' => 101.0, 'ema' => [200 => INF]]];
    }

    /** @param array<string, mixed> $context */
    #[DataProvider('invalidEmaContexts')]
    public function testEmaConditionsFailClosedOnMissingOrInvalidInputs(array $context): void
    {
        foreach ([new CloseGtEma200Condition(), new CloseLtEma200Condition()] as $condition) {
            $result = $condition->evaluate($context);
            self::assertFalse($result->passed);
            self::assertNull($result->value);
            self::assertTrue($result->meta['missing_data']);
        }
    }

    public function testPriorHighExcludesTheCurrentBarAndIsStrict(): void
    {
        $condition = new CloseGtPriorHighCondition();
        // Three prior bars (10, 12, 11), then the current bar whose own high (20) is ignored.
        $series = [9.0, 10.0, 12.0, 11.0, 20.0];

        $passed = $condition->evaluate($this->breakout('high_series', $series, 12.5, 3));
        $equal = $condition->evaluate($this->breakout('high_series', $series, 12.0, 3));
        $shorterWindow = $condition->evaluate($this->breakout('high_series', $series, 11.5, 1));

        self::assertTrue($passed->passed);
        self::assertSame(0.5, $passed->value);
        self::assertSame(12.0, $passed->threshold);
        self::assertSame(12.0, $passed->meta['prior_high']);
        self::assertSame(3, $passed->meta['lookback_bars']);
        self::assertFalse($equal->passed);
        self::assertSame(0.0, $equal->value);
        self::assertTrue($shorterWindow->passed);
        self::assertSame(11.0, $shorterWindow->threshold);
    }

    public function testPriorLowExcludesTheCurrentBarAndIsStrict(): void
    {
        $condition = new CloseLtPriorLowCondition();
        $series = [12.0, 10.0, 8.0, 9.0, 1.0];

        $passed = $condition->evaluate($this->breakout('low_series', $series, 7.5, 3));
        $equal = $condition->evaluate($this->breakout('low_series', $series, 8.0, 3));

        self::assertTrue($passed->passed);
        self::assertSame(-0.5, $passed->value);
        self::assertSame(8.0, $passed->threshold);
        self::assertSame(8.0, $passed->meta['prior_low']);
        self::assertFalse($equal->passed);
    }

    public function testBreakoutConditionsAcceptIntegralNumbers(): void
    {
        $high = (new CloseGtPriorHighCondition())->evaluate($this->breakout('high_series', [10, 12, 11, 13], 13, 3));
        $low = (new CloseLtPriorLowCondition())->evaluate($this->breakout('low_series', [10, 8, 9, 7], 7, 3));

        self::assertTrue($high->passed);
        self::assertSame(1.0, $high->value);
        self::assertTrue($low->passed);
        self::assertSame(-1.0, $low->value);
    }

    public function testBreakoutConditionsRequireTheCanonicalSeriesOrder(): void
    {
        foreach ([[new CloseGtPriorHighCondition(), 'high_series'], [new CloseLtPriorLowCondition(), 'low_series']] as [$condition, $key]) {
            $context = $this->breakout($key, [10.0, 11.0, 12.0, 13.0], 20.0, 3);
            unset($context['series_order']);
            $missing = $condition->evaluate($context);
            $context['series_order'] = 'newest_to_oldest';
            $reversed = $condition->evaluate($context);

            foreach ([$missing, $reversed] as $result) {
                self::assertFalse($result->passed);
                self::assertTrue($result->meta['missing_data']);
                self::assertSame('invalid_series_order', $result->meta['reason']);
            }
        }
    }

    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function invalidBreakoutInputs(): iterable
    {
        yield 'series one bar too short' => [[10.0, 11.0, 12.0], 20.0, 3];
        yield 'missing series' => [null, 20.0, 3];
        yield 'mapping series' => [['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0], 20.0, 3];
        yield 'non-finite prior bar' => [[10.0, NAN, 12.0, 13.0], 20.0, 3];
        yield 'string prior bar' => [[10.0, '11.0', 12.0, 13.0], 20.0, 3];
        yield 'missing close' => [[10.0, 11.0, 12.0, 13.0], null, 3];
        yield 'float lookback' => [[10.0, 11.0, 12.0, 13.0], 20.0, 3.0];
        yield 'zero lookback' => [[10.0, 11.0, 12.0, 13.0], 20.0, 0];
        yield 'missing lookback' => [[10.0, 11.0, 12.0, 13.0], 20.0, null];
    }

    #[DataProvider('invalidBreakoutInputs')]
    public function testBreakoutConditionsFailClosedOnMissingOrInvalidInputs(mixed $series, mixed $close, mixed $bars): void
    {
        foreach ([[new CloseGtPriorHighCondition(), 'high_series'], [new CloseLtPriorLowCondition(), 'low_series']] as [$condition, $key]) {
            $result = $condition->evaluate([
                $key => $series,
                'close' => $close,
                'lookback_bars' => $bars,
                'series_order' => 'oldest_to_newest',
            ]);

            self::assertFalse($result->passed, $key);
            self::assertNull($result->value, $key);
            self::assertTrue($result->meta['missing_data'], $key);
        }
    }

    /**
     * @param list<mixed> $series
     *
     * @return array<string, mixed>
     */
    private function breakout(string $key, array $series, int|float $close, int $bars): array
    {
        return [$key => $series, 'close' => $close, 'lookback_bars' => $bars, 'series_order' => 'oldest_to_newest'];
    }
}
