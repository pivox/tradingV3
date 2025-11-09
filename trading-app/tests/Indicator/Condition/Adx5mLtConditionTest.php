<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\Adx5mLtCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class Adx5mLtConditionTest extends TestCase
{
    private Adx5mLtCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new Adx5mLtCondition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('adx_5m_lt', $this->condition->getName());
    }

    public function testEvaluateWithValueBelowDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '5m',
            'adx_5m' => 15.0, // < 20.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('adx_5m_lt', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(15.0, $result->value);
        $this->assertEquals(20.0, $result->threshold);
    }

    public function testEvaluateWithValueAboveDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '5m',
            'adx_5m' => 25.0, // >= 20.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('adx_5m_lt', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(25.0, $result->value);
        $this->assertEquals(20.0, $result->threshold);
    }

    public function testEvaluateWithCustomThresholdFromContext(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '5m',
            'adx_5m' => 18.0,
            'adx_5m_lt_threshold' => 20.0, // Seuil depuis YAML
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('adx_5m_lt', $result->name);
        $this->assertTrue($result->passed); // 18.0 < 20.0
        $this->assertEquals(18.0, $result->value);
        $this->assertEquals(20.0, $result->threshold); // Utilise le seuil du contexte
    }

    public function testEvaluateWithMissingAdx5m(): void
    {
        $context = [
            'symbol' => 'UNIUSDT',
            'timeframe' => '5m',
            // adx_5m manquant
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('adx_5m_lt', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(20.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }
}

