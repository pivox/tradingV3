<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\Ema50Gt200Condition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class Ema50Gt200ConditionTest extends TestCase
{
    private Ema50Gt200Condition $condition;

    protected function setUp(): void
    {
        $this->condition = new Ema50Gt200Condition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('ema_50_gt_200', $this->condition->getName());
    }

    public function testEvaluateWithEma50AboveEma200(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'ema' => [
                50 => 52000.0,
                200 => 50000.0
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(0.04, $result->value); // (52000/50000) - 1 = 0.04
        $this->assertNull($result->threshold);
        $this->assertArrayHasKey('ema50', $result->meta);
        $this->assertArrayHasKey('ema200', $result->meta);
        $this->assertArrayHasKey('source', $result->meta);
        $this->assertEquals(52000.0, $result->meta['ema50']);
        $this->assertEquals(50000.0, $result->meta['ema200']);
        $this->assertEquals('EMA', $result->meta['source']);
    }

    public function testEvaluateWithEma50BelowEma200(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '4h',
            'ema' => [
                50 => 3000.0,
                200 => 3200.0
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(-0.0625, $result->value); // (3000/3200) - 1 = -0.0625
        $this->assertNull($result->threshold);
        $this->assertEquals(3000.0, $result->meta['ema50']);
        $this->assertEquals(3200.0, $result->meta['ema200']);
    }

    public function testEvaluateWithEma50EqualToEma200(): void
    {
        $context = [
            'symbol' => 'ADAUSDT',
            'timeframe' => '1d',
            'ema' => [
                50 => 1.5,
                200 => 1.5
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertFalse($result->passed); // 1.5 n'est pas > 1.5
        $this->assertEquals(0.0, $result->value); // (1.5/1.5) - 1 = 0.0
        $this->assertNull($result->threshold);
        $this->assertEquals(1.5, $result->meta['ema50']);
        $this->assertEquals(1.5, $result->meta['ema200']);
    }

    public function testEvaluateWithMissingEma50(): void
    {
        $context = [
            'symbol' => 'DOTUSDT',
            'timeframe' => '1h',
            'ema' => [
                200 => 8.0
                // ema50 manquant
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertNull($result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithMissingEma200(): void
    {
        $context = [
            'symbol' => 'LINKUSDT',
            'timeframe' => '4h',
            'ema' => [
                50 => 15.0
                // ema200 manquant
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertNull($result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithMissingEmaData(): void
    {
        $context = [
            'symbol' => 'UNIUSDT',
            'timeframe' => '1h'
            // ema manquant complètement
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertNull($result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithNonFloatEma50(): void
    {
        $context = [
            'symbol' => 'AAVEUSDT',
            'timeframe' => '4h',
            'ema' => [
                50 => 'invalid',
                200 => 200.0
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertNull($result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithNonFloatEma200(): void
    {
        $context = [
            'symbol' => 'SUSHIUSDT',
            'timeframe' => '1h',
            'ema' => [
                50 => 5.0,
                200 => 'invalid'
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertNull($result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithZeroEma200(): void
    {
        $context = [
            'symbol' => 'COMPUSDT',
            'timeframe' => '1h',
            'ema' => [
                50 => 100.0,
                200 => 0.0
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertTrue($result->passed); // 100.0 > 0.0
        $this->assertNull($result->value); // ratio null car division par zéro
        $this->assertNull($result->threshold);
        $this->assertEquals(100.0, $result->meta['ema50']);
        $this->assertEquals(0.0, $result->meta['ema200']);
    }

    public function testEvaluateWithVerySmallValues(): void
    {
        $context = [
            'symbol' => 'YFIUSDT',
            'timeframe' => '4h',
            'ema' => [
                50 => 0.0001,
                200 => 0.0002
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertFalse($result->passed); // 0.0001 < 0.0002
        $this->assertEquals(-0.5, $result->value); // (0.0001/0.0002) - 1 = -0.5
        $this->assertNull($result->threshold);
        $this->assertEquals(0.0001, $result->meta['ema50']);
        $this->assertEquals(0.0002, $result->meta['ema200']);
    }

    public function testEvaluateWithLargeValues(): void
    {
        $context = [
            'symbol' => 'MKRUSDT',
            'timeframe' => '1d',
            'ema' => [
                50 => 1000000.0,
                200 => 500000.0
            ]
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('ema_50_gt_200', $result->name);
        $this->assertTrue($result->passed); // 1000000.0 > 500000.0
        $this->assertEquals(1.0, $result->value); // (1000000/500000) - 1 = 1.0
        $this->assertNull($result->threshold);
        $this->assertEquals(1000000.0, $result->meta['ema50']);
        $this->assertEquals(500000.0, $result->meta['ema200']);
    }
}

