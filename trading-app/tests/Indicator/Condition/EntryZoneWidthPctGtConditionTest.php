<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\EntryZoneWidthPctGtCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class EntryZoneWidthPctGtConditionTest extends TestCase
{
    private EntryZoneWidthPctGtCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new EntryZoneWidthPctGtCondition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('entry_zone_width_pct_gt', $this->condition->getName());
    }

    public function testEvaluateWithValueAboveDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.5, // > 1.2 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_gt', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(1.5, $result->value);
        $this->assertEquals(1.2, $result->threshold);
    }

    public function testEvaluateWithValueBelowDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.0, // < 1.2 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_gt', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(1.0, $result->value);
        $this->assertEquals(1.2, $result->threshold);
    }

    public function testEvaluateWithCustomThresholdFromContext(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.25,
            'entry_zone_width_pct_gt_threshold' => 1.2, // Seuil depuis YAML
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_gt', $result->name);
        $this->assertTrue($result->passed); // 1.25 > 1.2
        $this->assertEquals(1.25, $result->value);
        $this->assertEquals(1.2, $result->threshold); // Utilise le seuil du contexte
    }

    public function testEvaluateWithValueExactlyAtCustomThreshold(): void
    {
        $context = [
            'symbol' => 'ADAUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.2,
            'entry_zone_width_pct_gt_threshold' => 1.2,
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_gt', $result->name);
        $this->assertFalse($result->passed); // 1.2 n'est pas > 1.2
        $this->assertEquals(1.2, $result->value);
        $this->assertEquals(1.2, $result->threshold);
    }

    public function testEvaluateWithMissingEntryZoneWidthPct(): void
    {
        $context = [
            'symbol' => 'UNIUSDT',
            'timeframe' => '15m',
            // entry_zone_width_pct manquant
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_gt', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(1.2, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }
}

