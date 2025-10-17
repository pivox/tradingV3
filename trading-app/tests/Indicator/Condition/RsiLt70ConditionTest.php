<?php

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\RsiLt70Condition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class RsiLt70ConditionTest extends TestCase
{
    private RsiLt70Condition $condition;

    protected function setUp(): void
    {
        $this->condition = new RsiLt70Condition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('rsi_lt_70', $this->condition->getName());
    }

    public function testEvaluateWithRsiBelowThreshold(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'rsi' => 65.5,
            'rsi_lt_70_threshold' => 70.0
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('rsi_lt_70', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(65.5, $result->value);
        $this->assertEquals(70.0, $result->threshold);
        $this->assertArrayHasKey('source', $result->meta);
        $this->assertEquals('RSI', $result->meta['source']);
    }

    public function testEvaluateWithRsiAboveThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '4h',
            'rsi' => 75.2,
            'rsi_lt_70_threshold' => 70.0
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('rsi_lt_70', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(75.2, $result->value);
        $this->assertEquals(70.0, $result->threshold);
    }

    public function testEvaluateWithRsiExactlyAtThreshold(): void
    {
        $context = [
            'symbol' => 'ADAUSDT',
            'timeframe' => '1d',
            'rsi' => 70.0,
            'rsi_lt_70_threshold' => 70.0
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('rsi_lt_70', $result->name);
        $this->assertFalse($result->passed); // 70.0 n'est pas < 70.0
        $this->assertEquals(70.0, $result->value);
        $this->assertEquals(70.0, $result->threshold);
    }

    public function testEvaluateWithCustomThreshold(): void
    {
        $context = [
            'symbol' => 'DOTUSDT',
            'timeframe' => '1h',
            'rsi' => 68.0,
            'rsi_lt_70_threshold' => 65.0
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('rsi_lt_70', $result->name);
        $this->assertFalse($result->passed); // 68.0 > 65.0
        $this->assertEquals(68.0, $result->value);
        $this->assertEquals(65.0, $result->threshold);
    }

    public function testEvaluateWithDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'LINKUSDT',
            'timeframe' => '4h',
            'rsi' => 60.0
            // rsi_lt_70_threshold non défini, doit utiliser 70.0 par défaut
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('rsi_lt_70', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(60.0, $result->value);
        $this->assertEquals(70.0, $result->threshold);
    }

    public function testEvaluateWithMissingRsiData(): void
    {
        $context = [
            'symbol' => 'UNIUSDT',
            'timeframe' => '1h'
            // rsi manquant
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('rsi_lt_70', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(70.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithNonFloatRsi(): void
    {
        $context = [
            'symbol' => 'AAVEUSDT',
            'timeframe' => '4h',
            'rsi' => 'invalid'
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('rsi_lt_70', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(70.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithStringNumericRsi(): void
    {
        $context = [
            'symbol' => 'SUSHIUSDT',
            'timeframe' => '1h',
            'rsi' => '55.5'
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('rsi_lt_70', $result->name);
        $this->assertFalse($result->passed); // '55.5' n'est pas un float
        $this->assertNull($result->value);
        $this->assertEquals(70.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithExtremeValues(): void
    {
        // Test avec RSI très bas
        $contextLow = [
            'symbol' => 'COMPUSDT',
            'timeframe' => '1h',
            'rsi' => 10.0
        ];

        $resultLow = $this->condition->evaluate($contextLow);
        $this->assertTrue($resultLow->passed);
        $this->assertEquals(10.0, $resultLow->value);

        // Test avec RSI très haut
        $contextHigh = [
            'symbol' => 'YFIUSDT',
            'timeframe' => '4h',
            'rsi' => 95.0
        ];

        $resultHigh = $this->condition->evaluate($contextHigh);
        $this->assertFalse($resultHigh->passed);
        $this->assertEquals(95.0, $resultHigh->value);
    }
}

