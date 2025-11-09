<?php

declare(strict_types=1);

namespace App\Tests\Indicator\ConditionLoader;

use App\MtfValidator\ConditionLoader\ConditionRegistry;
use PHPUnit\Framework\TestCase;

final class ConditionRegistryTrendIntegrationTest extends TestCase
{
    public function testRegistryEvaluatesTrendRulesFromArrayConfig(): void
    {
        $config = [
            'mtf_validation' => [
                'rules' => [
                    'macd_hist_increasing_n' => [
                        'increasing' => [
                            'field' => 'macd_hist',
                            'n' => 2,
                            'strict' => true,
                            'eps' => 1.0e-8,
                        ],
                    ],
                    'macd_hist_decreasing_n' => [
                        'decreasing' => [
                            'field' => 'macd_hist',
                            'n' => 2,
                            'strict' => true,
                            'eps' => 1.0e-8,
                        ],
                    ],
                ],
                'validation' => [
                    'start_from_timeframe' => '1m',
                    'timeframe' => [
                        '1m' => [
                            'long' => [
                                ['all_of' => ['macd_hist_increasing_n']],
                            ],
                            'short' => [
                                ['all_of' => ['macd_hist_decreasing_n']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $registry = new ConditionRegistry([]);
        $registry->load($config);

        $result = $registry->evaluate([
            '1m' => [
                'macd_hist_series' => [0.015, 0.010, 0.005],
            ],
        ]);

        $this->assertArrayHasKey('1m', $result);
        $tf = $result['1m'];
        $this->assertTrue($tf['long']['passed'] ?? false);
        $this->assertFalse($tf['short']['passed'] ?? true);

        $longRule = $this->extractFirstCondition($tf['long']['conditions'] ?? []);
        $this->assertSame('macd_hist_increasing_n', $longRule['name'] ?? null);
        $this->assertEquals([0.015, 0.010, 0.005], $longRule['meta']['series_used'] ?? []);

        $shortRule = $this->extractFirstCondition($tf['short']['conditions'] ?? []);
        $this->assertSame('macd_hist_decreasing_n', $shortRule['name'] ?? null);
        $this->assertFalse($shortRule['passed'] ?? true);
    }

    public function testRegistryMarksMissingDataWhenSeriesTooShort(): void
    {
        $config = [
            'mtf_validation' => [
                'rules' => [
                    'macd_hist_increasing_n' => [
                        'increasing' => [
                            'field' => 'macd_hist',
                            'n' => 2,
                        ],
                    ],
                ],
                'validation' => [
                    'start_from_timeframe' => '1m',
                    'timeframe' => [
                        '1m' => [
                            'long' => [
                                'macd_hist_increasing_n',
                            ],
                            'short' => [],
                        ],
                    ],
                ],
            ],
        ];

        $registry = new ConditionRegistry([]);
        $registry->load($config);

        $result = $registry->evaluate([
            '1m' => [
                'macd_hist_series' => [0.01, 0.009],
            ],
        ]);

        $long = $result['1m']['long'] ?? [];
        $this->assertFalse($long['passed'] ?? true);

        $first = $this->extractFirstCondition($long['conditions'] ?? []);
        $this->assertTrue($first['meta']['missing_data'] ?? false);
        $this->assertSame(2, $first['meta']['available_points'] ?? null);
    }

    /**
     * @param array<int,array<string,mixed>> $conditions
     * @return array<string,mixed>
     */
    private function extractFirstCondition(array $conditions): array
    {
        if ($conditions === []) {
            return [];
        }
        $first = $conditions[0];
        if (isset($first['items']) && is_array($first['items'])) {
            return $first['items'][0] ?? [];
        }
        return $first;
    }
}
