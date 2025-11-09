<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\EntryZoneWidthPctLteCondition;
use App\Indicator\Condition\ConditionResult;
use PHPUnit\Framework\TestCase;

class EntryZoneWidthPctLteConditionTest extends TestCase
{
    private EntryZoneWidthPctLteCondition $condition;

    protected function setUp(): void
    {
        $this->condition = new EntryZoneWidthPctLteCondition();
    }

    public function testGetName(): void
    {
        $this->assertEquals('entry_zone_width_pct_lte', $this->condition->getName());
    }

    public function testEvaluateWithValueBelowDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.0, // < 1.2 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_lte', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->value);
        $this->assertEquals(1.2, $result->threshold);
    }

    public function testEvaluateWithValueAboveDefaultThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.5, // > 1.2 (défaut)
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_lte', $result->name);
        $this->assertFalse($result->passed);
        $this->assertEquals(1.5, $result->value);
        $this->assertEquals(1.2, $result->threshold);
    }

    public function testEvaluateWithCustomThresholdFromContext(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.25,
            'entry_zone_width_pct_lte_threshold' => 1.3, // Seuil depuis YAML
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_lte', $result->name);
        $this->assertTrue($result->passed); // 1.25 <= 1.3
        $this->assertEquals(1.25, $result->value);
        $this->assertEquals(1.3, $result->threshold); // Utilise le seuil du contexte
    }

    public function testEvaluateWithValueExactlyAtCustomThreshold(): void
    {
        $context = [
            'symbol' => 'ADAUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.3,
            'entry_zone_width_pct_lte_threshold' => 1.3,
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_lte', $result->name);
        $this->assertTrue($result->passed); // 1.3 <= 1.3
        $this->assertEquals(1.3, $result->value);
        $this->assertEquals(1.3, $result->threshold);
    }

    public function testEvaluateWithValueAboveCustomThreshold(): void
    {
        $context = [
            'symbol' => 'DOTUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.35,
            'entry_zone_width_pct_lte_threshold' => 1.3,
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_lte', $result->name);
        $this->assertFalse($result->passed); // 1.35 > 1.3
        $this->assertEquals(1.35, $result->value);
        $this->assertEquals(1.3, $result->threshold);
    }

    public function testEvaluateWithDefaultThresholdWhenContextThresholdMissing(): void
    {
        $context = [
            'symbol' => 'LINKUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 1.0,
            // entry_zone_width_pct_lte_threshold non défini, doit utiliser 1.2 par défaut
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_lte', $result->name);
        $this->assertTrue($result->passed);
        $this->assertEquals(1.0, $result->value);
        $this->assertEquals(1.2, $result->threshold); // Défaut PHP
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
        $this->assertEquals('entry_zone_width_pct_lte', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(1.2, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithNonFloatEntryZoneWidthPct(): void
    {
        $context = [
            'symbol' => 'AAVEUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 'invalid',
        ];

        $result = $this->condition->evaluate($context);

        $this->assertInstanceOf(ConditionResult::class, $result);
        $this->assertEquals('entry_zone_width_pct_lte', $result->name);
        $this->assertFalse($result->passed);
        $this->assertNull($result->value);
        $this->assertEquals(1.2, $result->threshold);
        $this->assertArrayHasKey('missing_data', $result->meta);
        $this->assertTrue($result->meta['missing_data']);
    }

    public function testEvaluateWithExtremeValues(): void
    {
        // Test avec valeur très basse
        $contextLow = [
            'symbol' => 'COMPUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 0.1,
        ];

        $resultLow = $this->condition->evaluate($contextLow);
        $this->assertTrue($resultLow->passed);
        $this->assertEquals(0.1, $resultLow->value);

        // Test avec valeur très haute
        $contextHigh = [
            'symbol' => 'YFIUSDT',
            'timeframe' => '15m',
            'entry_zone_width_pct' => 5.0,
            'entry_zone_width_pct_lte_threshold' => 1.3,
        ];

        $resultHigh = $this->condition->evaluate($contextHigh);
        $this->assertFalse($resultHigh->passed);
        $this->assertEquals(5.0, $resultHigh->value);
        $this->assertEquals(1.3, $resultHigh->threshold);
    }
}

