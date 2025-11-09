<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Execution;

use App\Config\MtfValidationConfig;
use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use App\MtfValidator\Execution\ExecutionDecision;
use App\MtfValidator\Execution\ExecutionSelector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExecutionSelectorTest extends TestCase
{
    private ExecutionSelector $selector;
    private MtfValidationConfig $mtfConfig;
    /** @var ConditionRegistry&MockObject */
    private ConditionRegistry $registry;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Créer un config mock avec execution_selector
        $configData = [
            'execution_selector' => [
                'stay_on_15m_if' => [
                    'expected_r_multiple_gte' => 2.0,
                    'entry_zone_width_pct_lte' => 1.3,
                    'atr_pct_15m_lte_bps' => 120,
                ],
                'drop_to_5m_if_any' => [
                    'expected_r_multiple_lt' => 2.0,
                    'atr_pct_15m_gt_bps' => 120,
                    'entry_zone_width_pct_gt' => 1.2,
                ],
                'forbid_drop_to_5m_if_any' => [
                    'adx_5m_lt' => 20,
                    'spread_bps_gt' => 8,
                ],
            ],
            'filters_mandatory' => [],
        ];

        $this->mtfConfig = $this->createMock(MtfValidationConfig::class);
        $this->mtfConfig->method('getConfig')->willReturn($configData);

        $this->registry = $this->createMock(ConditionRegistry::class);

        $this->selector = new ExecutionSelector(
            $this->mtfConfig,
            $this->registry,
            $this->logger
        );
    }

    public function testDecideStaysOn15mWhenAllConditionsPass(): void
    {
        $context = [
            'expected_r_multiple' => 2.5,
            'entry_zone_width_pct' => 1.0,
            'atr_pct_15m_bps' => 100.0,
        ];

        // Mock registry pour retourner toutes les conditions passées
        $this->registry
            ->expects($this->atLeastOnce())
            ->method('evaluate')
            ->willReturnCallback(function ($ctx, $names) {
                $results = [];
                foreach ($names as $name) {
                    $results[$name] = [
                        'name' => $name,
                        'passed' => true,
                        'value' => $ctx['expected_r_multiple'] ?? $ctx['entry_zone_width_pct'] ?? $ctx['atr_pct_15m_bps'] ?? null,
                        'threshold' => null,
                        'meta' => [],
                    ];
                }
                return $results;
            });

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('15m', $decision->executionTimeframe);
        $this->assertEquals(2.5, $decision->expectedRMultiple);
        $this->assertEquals(1.0, $decision->entryZoneWidthPct);
    }

    public function testDecideDropsTo5mWhenAnyDropConditionPasses(): void
    {
        $context = [
            'expected_r_multiple' => 1.5, // < 2.0
            'entry_zone_width_pct' => 1.0,
            'atr_pct_15m_bps' => 100.0,
        ];

        $this->registry
            ->expects($this->atLeastOnce())
            ->method('evaluate')
            ->willReturnCallback(function ($ctx, $names) {
                $results = [];
                foreach ($names as $name) {
                    $passed = false;
                    if ($name === 'expected_r_multiple_lt' && ($ctx['expected_r_multiple'] ?? 999) < 2.0) {
                        $passed = true;
                    }
                    $results[$name] = [
                        'name' => $name,
                        'passed' => $passed,
                        'value' => $ctx['expected_r_multiple'] ?? null,
                        'threshold' => null,
                        'meta' => [],
                    ];
                }
                return $results;
            });

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('5m', $decision->executionTimeframe);
    }

    public function testDecideReturnsNoneWhenMandatoryFiltersFail(): void
    {
        $configData = [
            'execution_selector' => [
                'stay_on_15m_if' => [],
            ],
            'filters_mandatory' => ['rsi_lt_70'],
        ];

        $this->mtfConfig->method('getConfig')->willReturn($configData);

        $context = ['rsi' => 75.0]; // RSI > 70, filtre échoue

        $this->registry
            ->expects($this->once())
            ->method('evaluate')
            ->with($context, ['rsi_lt_70'])
            ->willReturn([
                'rsi_lt_70' => [
                    'name' => 'rsi_lt_70',
                    'passed' => false,
                    'value' => 75.0,
                    'threshold' => 70.0,
                    'meta' => [],
                ],
            ]);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('[ExecSelector] filters_mandatory failed', $this->anything());

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('NONE', $decision->executionTimeframe);
    }

    public function testDecideUsesCustomThresholdsFromYaml(): void
    {
        $context = [
            'expected_r_multiple' => 2.1,
            'entry_zone_width_pct' => 1.25,
            'atr_pct_15m_bps' => 110.0,
        ];

        // Vérifier que les seuils du YAML sont injectés dans le contexte
        $this->registry
            ->expects($this->atLeastOnce())
            ->method('evaluate')
            ->willReturnCallback(function ($ctx, $names) {
                // Vérifier que les seuils sont présents dans le contexte
                $this->assertArrayHasKey('expected_r_multiple_gte_threshold', $ctx);
                $this->assertEquals(2.0, $ctx['expected_r_multiple_gte_threshold']);
                $this->assertArrayHasKey('entry_zone_width_pct_lte_threshold', $ctx);
                $this->assertEquals(1.3, $ctx['entry_zone_width_pct_lte_threshold']);
                $this->assertArrayHasKey('atr_pct_15m_lte_bps_threshold', $ctx);
                $this->assertEquals(120, $ctx['atr_pct_15m_lte_bps_threshold']);

                $results = [];
                foreach ($names as $name) {
                    $results[$name] = [
                        'name' => $name,
                        'passed' => true,
                        'value' => $ctx['expected_r_multiple'] ?? $ctx['entry_zone_width_pct'] ?? $ctx['atr_pct_15m_bps'] ?? null,
                        'threshold' => null,
                        'meta' => [],
                    ];
                }
                return $results;
            });

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('15m', $decision->executionTimeframe);
    }

    public function testDecideFallsBackTo15mWhenNoConditionsMatch(): void
    {
        $context = [
            'expected_r_multiple' => 2.1,
            'entry_zone_width_pct' => 1.0,
            'atr_pct_15m_bps' => 100.0,
        ];

        $this->registry
            ->expects($this->atLeastOnce())
            ->method('evaluate')
            ->willReturnCallback(function ($ctx, $names) {
                $results = [];
                foreach ($names as $name) {
                    // stay_on_15m_if échoue (pas toutes passent)
                    // drop_to_5m_if_any échoue (aucune ne passe)
                    $results[$name] = [
                        'name' => $name,
                        'passed' => false,
                        'value' => null,
                        'threshold' => null,
                        'meta' => [],
                    ];
                }
                return $results;
            });

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('15m', $decision->executionTimeframe); // Fallback pragmatique
    }

    public function testDecideWithStringOnlySpec(): void
    {
        $configData = [
            'execution_selector' => [
                'stay_on_15m_if' => [
                    'expected_r_multiple_gte',
                    'entry_zone_width_pct_lte',
                ],
            ],
            'filters_mandatory' => [],
        ];

        $this->mtfConfig->method('getConfig')->willReturn($configData);

        $context = [
            'expected_r_multiple' => 2.5,
            'entry_zone_width_pct' => 1.0,
        ];

        $this->registry
            ->expects($this->atLeastOnce())
            ->method('evaluate')
            ->willReturn([
                'expected_r_multiple_gte' => [
                    'name' => 'expected_r_multiple_gte',
                    'passed' => true,
                    'value' => 2.5,
                    'threshold' => 2.0,
                    'meta' => [],
                ],
                'entry_zone_width_pct_lte' => [
                    'name' => 'entry_zone_width_pct_lte',
                    'passed' => true,
                    'value' => 1.0,
                    'threshold' => 1.2,
                    'meta' => [],
                ],
            ]);

        $decision = $this->selector->decide($context);

        $this->assertInstanceOf(ExecutionDecision::class, $decision);
        $this->assertEquals('15m', $decision->executionTimeframe);
    }
}

