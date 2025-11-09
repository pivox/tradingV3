<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Execution;

use App\Config\MtfValidationConfig;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use App\MtfValidator\Execution\ExecutionDecision;
use App\MtfValidator\Execution\ExecutionSelector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test d'intégration end-to-end pour ExecutionSelector avec validations.yaml réel.
 * Vérifie que les seuils du YAML sont correctement extraits et utilisés.
 */
class ExecutionSelectorIntegrationTest extends TestCase
{
    private ExecutionSelector $selector;
    private ConditionRegistry $registry;
    private MtfValidationConfig $mtfConfig;

    protected function setUp(): void
    {
        $projectRoot = dirname(dirname(dirname(dirname(__DIR__))));
        $configPath = $projectRoot . '/src/MtfValidator/config/validations.yaml';

        $this->mtfConfig = new MtfValidationConfig($configPath);
        $this->registry = new ConditionRegistry([], $this->createMock(\Psr\Container\ContainerInterface::class), $this->createMock(LoggerInterface::class));
        $this->registry->loadFromConfigs($this->mtfConfig);

        $this->selector = new ExecutionSelector(
            $this->mtfConfig,
            $this->registry,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testStayOn15mWithAllConditionsPassingAndYamlThresholds(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 2.5, // >= 2.0 (YAML)
            'entry_zone_width_pct' => 1.0, // <= 1.3 (YAML)
            'atr_pct_15m_bps' => 100.0, // <= 120 (YAML)
            'close' => 50000.0,
            'rsi' => 65.0, // < 70
            'adx_1h' => 30.0, // >= 25
            'leverage' => 5.0, // entre 2.0 et 20.0
        ];

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('15m', $decision->executionTimeframe);
        $this->assertEquals(2.5, $decision->expectedRMultiple);
        $this->assertEquals(1.0, $decision->entryZoneWidthPct);
    }

    public function testDropTo5mWhenEntryZoneWidthExceedsThreshold(): void
    {
        $context = [
            'symbol' => 'ETHUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 2.1,
            'entry_zone_width_pct' => 1.5, // > 1.2 (YAML drop_to_5m_if_any)
            'atr_pct_15m_bps' => 100.0,
            'close' => 3000.0,
            'rsi' => 65.0,
            'adx_1h' => 30.0,
            'leverage' => 5.0,
            'adx_5m' => 25.0, // >= 20 (forbid_drop ne bloque pas)
            'spread_bps' => 5.0, // <= 8 (forbid_drop ne bloque pas)
        ];

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('5m', $decision->executionTimeframe);
    }

    public function testDropTo5mBlockedByForbidConditions(): void
    {
        $context = [
            'symbol' => 'ADAUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 1.5, // < 2.0 (drop condition)
            'entry_zone_width_pct' => 1.0,
            'atr_pct_15m_bps' => 100.0,
            'close' => 1.0,
            'rsi' => 65.0,
            'adx_1h' => 30.0,
            'leverage' => 5.0,
            'adx_5m' => 15.0, // < 20 (forbid_drop bloque)
            'spread_bps' => 5.0,
        ];

        $decision = $this->selector->decide($context);

        // Ne doit pas drop à 5m car adx_5m < 20 bloque
        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertNotEquals('5m', $decision->executionTimeframe);
    }

    public function testYamlThresholdsAreUsedInsteadOfDefaults(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 2.1,
            'entry_zone_width_pct' => 1.25, // Entre 1.2 (défaut) et 1.3 (YAML)
            'atr_pct_15m_bps' => 110.0,
            'close' => 50000.0,
            'rsi' => 65.0,
            'adx_1h' => 30.0,
            'leverage' => 5.0,
        ];

        // Avec le seuil YAML de 1.3, entry_zone_width_pct_lte devrait passer
        // Avec le défaut de 1.2, ça échouerait
        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        // Si le seuil YAML (1.3) est utilisé, stay_on_15m_if devrait passer
        // Si le défaut (1.2) est utilisé, ça échouerait et on drop à 5m
        // On vérifie que le seuil YAML est bien utilisé en vérifiant qu'on reste sur 15m
        $this->assertEquals('15m', $decision->executionTimeframe);
    }

    public function testMultipleSymbolsWithDifferentContexts(): void
    {
        $symbols = [
            'BTCUSDT' => [
                'expected_r_multiple' => 2.5,
                'entry_zone_width_pct' => 1.0,
                'atr_pct_15m_bps' => 100.0,
                'close' => 50000.0,
            ],
            'ETHUSDT' => [
                'expected_r_multiple' => 1.8,
                'entry_zone_width_pct' => 1.5,
                'atr_pct_15m_bps' => 130.0,
                'close' => 3000.0,
            ],
        ];

        $baseContext = [
            'symbol' => '',
            'timeframe' => '15m',
            'rsi' => 65.0,
            'adx_1h' => 30.0,
            'leverage' => 5.0,
            'adx_5m' => 25.0,
            'spread_bps' => 5.0,
        ];

        foreach ($symbols as $symbol => $specificContext) {
            $context = array_merge($baseContext, $specificContext, ['symbol' => $symbol]);
            $decision = $this->selector->decide($context);

            $this->assertInstanceOf(ExecutionDecision::class, $decision);
            $this->assertContains($decision->executionTimeframe, ['15m', '5m', '1m', 'NONE']);
        }
    }

    public function testNoneWhenMandatoryFiltersFail(): void
    {
        $context = [
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'expected_r_multiple' => 2.5,
            'entry_zone_width_pct' => 1.0,
            'atr_pct_15m_bps' => 100.0,
            'close' => 50000.0,
            'rsi' => 75.0, // >= 70, filtre échoue
            'adx_1h' => 30.0,
            'leverage' => 5.0,
        ];

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('NONE', $decision->executionTimeframe);
    }
}

