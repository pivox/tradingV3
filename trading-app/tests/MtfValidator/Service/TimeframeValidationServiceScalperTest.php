<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\MtfValidator\Service\Rule\TimeframeRuleEvaluator;
use App\MtfValidator\Service\Rule\YamlRuleEngine;
use App\MtfValidator\Service\TimeframeValidationService;
use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TimeframeValidationServiceScalperTest extends TestCase
{
    private TimeframeValidationService $service;

    protected function setUp(): void
    {
        $ruleEngine      = new YamlRuleEngine();
        $tfEvaluator     = new TimeframeRuleEvaluator($ruleEngine);
        $this->service   = new TimeframeValidationService(
            $tfEvaluator,
            $ruleEngine,
            new NullLogger(),
        );
    }

    public function testScalper5mLong_AllOfAnyOfWithRsiOverride_Passes(): void
    {
        $symbol    = 'TESTUSDT';
        $timeframe = '5m';
        $phase     = 'execution';
        $mode      = 'scalper';

        // --- mini-config inspirée de validations.scalper.yaml ---
        $mtfConfig = [
            'rules' => [
                // rsi_lt_70 "de base"
                'rsi_lt_70' => [
                    'field' => 'rsi',
                    'lt'    => 70.0,
                ],
                // macd_hist_gt_eps
                'macd_hist_gt_eps' => [
                    'op'    => '>',
                    'left'  => 'macd_hist',
                    'right' => 0.0,
                    'eps'   => 1.0e-6,
                ],
            ],
            'validation' => [
                'timeframe' => [
                    '5m' => [
                        'long' => [
                            // === Cas 1 : all_of + any_of + alias rsi_lt_70: { lt: 72 } ===
                            [
                                'all_of' => [
                                    [
                                        'any_of' => [
                                            'macd_hist_gt_eps',
                                        ],
                                    ],
                                    [
                                        // alias avec override : rsi < 72 au lieu de 70
                                        'rsi_lt_70' => [
                                            'lt' => 72.0,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'short' => [
                            // rien pour ce test → short non valide
                        ],
                    ],
                ],
            ],
            // Aucun filtre global obligatoire pour ce test
            'filters_mandatory' => [],
        ];

        // --- indicateurs cohérents avec le scénario long ---
        $indicators = [
            'macd_hist' => 0.0012, // > 0 → macd_hist_gt_eps true
            'rsi'       => 65.0,   // < 72 → rsi_lt_70 avec override true
        ];

        $decision = $this->service->validateTimeframe(
            symbol: $symbol,
            timeframe: $timeframe,
            phase: $phase,
            mode: $mode,
            mtfConfig: $mtfConfig,
            indicators: $indicators,
        );

        self::assertInstanceOf(TimeframeDecisionDto::class, $decision);
        self::assertSame('5m', $decision->timeframe);
        self::assertSame('VALID', $decision->status);
        self::assertSame('LONG', $decision->side);
        self::assertNull($decision->reason);
    }

    public function testScalper5mLong_FailsWhenRsiTooHigh(): void
    {
        $symbol    = 'TESTUSDT';
        $timeframe = '5m';
        $phase     = 'execution';
        $mode      = 'scalper';

        $mtfConfig = [
            'rules' => [
                'rsi_lt_70' => [
                    'field' => 'rsi',
                    'lt'    => 70.0,
                ],
                'macd_hist_gt_eps' => [
                    'op'    => '>',
                    'left'  => 'macd_hist',
                    'right' => 0.0,
                    'eps'   => 1.0e-6,
                ],
            ],
            'validation' => [
                'timeframe' => [
                    '5m' => [
                        'long' => [
                            [
                                'all_of' => [
                                    [
                                        'any_of' => [
                                            'macd_hist_gt_eps',
                                        ],
                                    ],
                                    [
                                        'rsi_lt_70' => [
                                            'lt' => 72.0,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'short' => [],
                    ],
                ],
            ],
            'filters_mandatory' => [],
        ];

        // Ici, macd_hist ok mais rsi trop haut → aucun side valide
        $indicators = [
            'macd_hist' => 0.0020, // > 0
            'rsi'       => 85.0,   // > 72 → rsi_lt_70 (override) false
        ];

        $decision = $this->service->validateTimeframe(
            symbol: $symbol,
            timeframe: $timeframe,
            phase: $phase,
            mode: $mode,
            mtfConfig: $mtfConfig,
            indicators: $indicators,
        );

        self::assertSame('5m', $decision->timeframe);
        self::assertSame('INVALID', $decision->status);
        self::assertNull($decision->side);
        self::assertSame('NO_LONG_NO_SHORT', $decision->reason);
    }
}
