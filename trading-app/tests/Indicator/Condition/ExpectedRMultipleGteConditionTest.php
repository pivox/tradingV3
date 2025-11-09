<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\ExpectedRMultipleGteCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class ExpectedRMultipleGteConditionTest extends TestCase
{
    private ExpectedRMultipleGteCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new ExpectedRMultipleGteCondition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('expected_r_multiple_gte', $this->condition->getName());
    }

    public function testEvaluateWithValueAboveDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 2.5, // >= 2.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('expected_r_multiple_gte', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(2.5, $result->value);
        $this->assertEquals(2.0, $result->threshold);
    }

    public function testEvaluateWithValueBelowDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 1.5, // < 2.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('expected_r_multiple_gte', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(1.5, $result->value);
        $this->assertEquals(2.0, $result->threshold);
    }

    public function testEvaluateWithCustomThresholdFromContext(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 2.1,
            'expected_r_multiple_gte_threshold' => 2.0, // Seuil depuis YAML
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('expected_r_multiple_gte', $result->name);
        $this->assertTrue($result->passed); // 2.1 >= 2.0
        $this->assertEquals(2.1, $result->value);
        $this->assertEquals(2.0, $result->threshold); // Utilise le seuil du contexte
    }

    public function testEvaluateWithValueExactlyAtCustomThreshold(): void
    {
        $context = [
            'symbol' => 'ADAUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 2.0,
            'expected_r_multiple_gte_threshold' => 2.0,
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('expected_r_multiple_gte', $result->name);
        $this->assertTrue($result->passed); // 2.0 >= 2.0
        $this->assertEquals(2.0, $result->value);
        $this->assertEquals(2.0, $result->threshold);
    }

    public function testEvaluateWithMissingExpectedRMultiple(): void
    {
        $context = [
            'symbol' => 'UNIUSDT',
            'timeframe' => '15m',
            // expected_r_multiple manquant
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('expected_r_multiple_gte', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(2.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }
}

