<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\ExpectedRMultipleLtCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class ExpectedRMultipleLtConditionTest extends TestCase
{
    private ExpectedRMultipleLtCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new ExpectedRMultipleLtCondition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('expected_r_multiple_lt', $this->condition->getName());
    }

    public function testEvaluateWithValueBelowDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 1.5, // < 2.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('expected_r_multiple_lt', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(1.5, $result->value);
        $this->assertEquals(2.0, $result->threshold);
    }

    public function testEvaluateWithValueAboveDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 2.5, // >= 2.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('expected_r_multiple_lt', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(2.5, $result->value);
        $this->assertEquals(2.0, $result->threshold);
    }

    public function testEvaluateWithCustomThresholdFromContext(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 1.8,
            'expected_r_multiple_lt_threshold' => 2.0, // Seuil depuis YAML
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('expected_r_multiple_lt', $result->name);
        $this->assertTrue($result->passed); // 1.8 < 2.0
        $this->assertEquals(1.8, $result->value);
        $this->assertEquals(2.0, $result->threshold); // Utilise le seuil du contexte
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
        $this->assertEquals('expected_r_multiple_lt', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(2.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }
}

