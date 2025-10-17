<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\MacdHistLt0Condition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class MacdHistLt0ConditionTest extends TestCase
{
    private MacdHistLt0Condition $condition;

    protected function setUp(): void
    {
        $this->condition = new MacdHistLt0Condition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('macd_hist_lt_0', $this->condition->getName());
    }

    public function testEvaluateWithNegativeHistogram(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'macd' => [
                'hist' => -0.5
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('macd_hist_lt_0', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(-0.5, $result->value);
        $this->assertEquals(0.0, $result->threshold);
        $this->assertArrayHasKey('source', $result->meta);
        $this->assertEquals('MACD', $result->meta['source']);
    }

    public function testEvaluateWithPositiveHistogram(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '4h',
            'macd' => [
                'hist' => 0.3
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('macd_hist_lt_0', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(0.3, $result->value);
        $this->assertEquals(0.0, $result->threshold);
    }

    public function testEvaluateWithZeroHistogram(): void
    {
        $context = [
            'symbol' => 'ADAUSDT',
            'timeframe' => '1d',
            'macd' => [
                'hist' => 0.0
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('macd_hist_lt_0', $result->name);
        $this->assertFalse($result->passed); // 0.0 n'est pas < 0.0
        $this->assertEquals(0.0, $result->value);
        $this->assertEquals(0.0, $result->threshold);
    }

    public function testEvaluateWithMissingMacdData(): void
    {
        $context = [
            'symbol' => 'DOTUSDT',
            'timeframe' => '1h'
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('macd_hist_lt_0', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(0.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertEquals('macd', $result->meta['missing_data']);
    }

    public function testEvaluateWithMissingHistogramData(): void
    {
        $context = [
            'symbol' => 'LINKUSDT',
            'timeframe' => '4h',
            'macd' => [
                'macd' => 0.1,
                'signal' => 0.2
                // hist manquant
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('macd_hist_lt_0', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(0.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertEquals('macd.hist', $result->meta['missing_data']);
    }

    public function testEvaluateWithNonNumericHistogram(): void
    {
        $context = [
            'symbol' => 'UNIUSDT',
            'timeframe' => '1h',
            'macd' => [
                'hist' => 'invalid'
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('macd_hist_lt_0', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(0.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertEquals('macd.hist', $result->meta['missing_data']);
    }

    public function testEvaluateWithStringNumericHistogram(): void
    {
        $context = [
            'symbol' => 'AAVEUSDT',
            'timeframe' => '4h',
            'macd' => [
                'hist' => '-0.25'
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('macd_hist_lt_0', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(-0.25, $result->value);
        $this->assertEquals(0.0, $result->threshold);
    }

    public function testEvaluateWithVerySmallNegativeValue(): void
    {
        $context = [
            'symbol' => 'SUSHIUSDT',
            'timeframe' => '1h',
            'macd' => [
                'hist' => -1e-10
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('macd_hist_lt_0', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(-1e-10, $result->value);
        $this->assertEquals(0.0, $result->threshold);
    }
}

