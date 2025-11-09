<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\SpreadBpsGtCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class SpreadBpsGtConditionTest extends TestCase
{
    private SpreadBpsGtCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new SpreadBpsGtCondition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('spread_bps_gt', $this->condition->getName());
    }

    public function testEvaluateWithValueAboveDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'spread_bps' => 10.0, // > 8.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('spread_bps_gt', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(10.0, $result->value);
        $this->assertEquals(8.0, $result->threshold);
    }

    public function testEvaluateWithValueBelowDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'spread_bps' => 5.0, // <= 8.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('spread_bps_gt', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(5.0, $result->value);
        $this->assertEquals(8.0, $result->threshold);
    }

    public function testEvaluateWithCustomThresholdFromContext(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'spread_bps' => 9.0,
            'spread_bps_gt_threshold' => 8.0, // Seuil depuis YAML
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('spread_bps_gt', $result->name);
        $this->assertTrue($result->passed); // 9.0 > 8.0
        $this->assertEquals(9.0, $result->value);
        $this->assertEquals(8.0, $result->threshold); // Utilise le seuil du contexte
    }

    public function testEvaluateWithMissingSpreadBps(): void
    {
        $context = [
            'symbol' => 'UNIUSDT',
            'timeframe' => '15m',
            // spread_bps manquant
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('spread_bps_gt', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(8.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }
}

