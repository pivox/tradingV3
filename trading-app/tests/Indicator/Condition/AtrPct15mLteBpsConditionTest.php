<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\AtrPct15mLteBpsCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class AtrPct15mLteBpsConditionTest extends TestCase
{
    private AtrPct15mLteBpsCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new AtrPct15mLteBpsCondition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('atr_pct_15m_lte_bps', $this->condition->getName());
    }

    public function testEvaluateWithValueBelowDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'atr_pct_15m_bps' => 100.0, // <= 120.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('atr_pct_15m_lte_bps', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(100.0, $result->value);
        $this->assertEquals(120.0, $result->threshold);
    }

    public function testEvaluateWithValueAboveDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'atr_pct_15m_bps' => 150.0, // > 120.0 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('atr_pct_15m_lte_bps', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(150.0, $result->value);
        $this->assertEquals(120.0, $result->threshold);
    }

    public function testEvaluateWithCustomThresholdFromContext(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'atr_pct_15m_bps' => 110.0,
            'atr_pct_15m_lte_bps_threshold' => 120.0, // Seuil depuis YAML
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('atr_pct_15m_lte_bps', $result->name);
        $this->assertTrue($result->passed); // 110.0 <= 120.0
        $this->assertEquals(110.0, $result->value);
        $this->assertEquals(120.0, $result->threshold); // Utilise le seuil du contexte
    }

    public function testEvaluateWithValueExactlyAtCustomThreshold(): void
    {
        $context = [
            'symbol' => 'ADAUSDT',
            'timeframe' => '15m',
            'atr_pct_15m_bps' => 120.0,
            'atr_pct_15m_lte_bps_threshold' => 120.0,
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('atr_pct_15m_lte_bps', $result->name);
        $this->assertTrue($result->passed); // 120.0 <= 120.0
        $this->assertEquals(120.0, $result->value);
        $this->assertEquals(120.0, $result->threshold);
    }

    public function testEvaluateWithMissingAtrPct15mBps(): void
    {
        $context = [
            'symbol' => 'UNIUSDT',
            'timeframe' => '15m',
            // atr_pct_15m_bps manquant
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('atr_pct_15m_lte_bps', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(120.0, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }
}

