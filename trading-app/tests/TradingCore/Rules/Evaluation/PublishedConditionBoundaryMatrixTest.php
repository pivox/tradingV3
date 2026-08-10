<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Evaluation;

use App\Indicator\Registry\ConditionRegistry;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class PublishedConditionBoundaryMatrixTest extends KernelTestCase
{
    public function testMatrixCoversEveryParameterizedExecutableConditionService(): void
    {
        $catalog = (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 4) . '/config/trading/condition_catalog/1.0.0.yaml',
        );
        $published = [];
        foreach ($catalog->conditionIds() as $conditionId) {
            $definition = $catalog->definition($conditionId);
            if ($definition->status === 'executable'
                && $definition->parameters !== []
                && str_starts_with($definition->implementation, 'condition_service:')) {
                $published[] = $conditionId;
            }
        }

        $covered = [];
        foreach (self::serviceBoundaryCases() as $case) {
            $covered[] = $case[0];
        }

        $covered = array_values(array_unique($covered));
        sort($published, SORT_STRING);
        sort($covered, SORT_STRING);

        self::assertSame($published, $covered);
    }

    /**
     * @param array<string, mixed> $below
     * @param array<string, mixed> $equal
     * @param array<string, mixed> $above
     * @param array{bool, bool, bool} $expected
     */
    #[DataProvider('serviceBoundaryCases')]
    public function testPublishedServiceBoundary(
        string $conditionId,
        array $below,
        array $equal,
        array $above,
        array $expected,
    ): void {
        self::bootKernel();
        $registry = self::getContainer()->get(ConditionRegistry::class);
        self::assertInstanceOf(ConditionRegistry::class, $registry);
        $condition = $registry->get($conditionId);
        self::assertNotNull($condition);

        self::assertSame($expected, [
            $condition->evaluate($below)->passed,
            $condition->evaluate($equal)->passed,
            $condition->evaluate($above)->passed,
        ], $conditionId);
        self::assertFalse($condition->evaluate([])->passed, $conditionId . ' must reject missing data.');
    }

    public function testMacdHysteresisUsesCanonicalSeriesAcrossTheFullCooldownWindow(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ConditionRegistry::class);
        self::assertInstanceOf(ConditionRegistry::class, $registry);

        $up = $registry->get('macd_line_cross_up_with_hysteresis');
        $down = $registry->get('macd_line_cross_down_with_hysteresis');
        self::assertNotNull($up);
        self::assertNotNull($down);
        self::assertTrue($up->evaluate([
            'macd_hist_series' => [-0.002, 0.002, 0.003, 0.004, 0.005],
            'min_gap' => 0.001,
            'cool_down_bars' => 3,
            'require_prev_below' => true,
        ])->passed);
        self::assertTrue($down->evaluate([
            'macd_hist_series' => [0.002, -0.002, -0.003, -0.004, -0.005],
            'min_gap' => 0.001,
            'cool_down_bars' => 3,
            'require_prev_above' => true,
        ])->passed);
    }

    /**
     * @return iterable<string, array{
     *   string, array<string, mixed>, array<string, mixed>, array<string, mixed>, array{bool, bool, bool}
     * }>
     */
    public static function serviceBoundaryCases(): iterable
    {
        yield 'adx-gte' => ['adx_min_for_trend',
            ['adx' => [14 => 19.999], 'threshold' => 20.0, 'timeframe' => '5m'],
            ['adx' => [14 => 20.0], 'threshold' => 20.0, 'timeframe' => '5m'],
            ['adx' => [14 => 20.001], 'threshold' => 20.0, 'timeframe' => '5m'],
            [false, true, true],
        ];
        yield 'atr-min-inclusive' => ['atr_volatility_ok',
            ['atr' => 0.0999, 'close' => 100.0, 'min_atr_pct' => 0.001, 'max_atr_pct' => 0.03],
            ['atr' => 0.1, 'close' => 100.0, 'min_atr_pct' => 0.001, 'max_atr_pct' => 0.03],
            ['atr' => 0.1001, 'close' => 100.0, 'min_atr_pct' => 0.001, 'max_atr_pct' => 0.03],
            [false, true, true],
        ];
        yield 'atr-max-inclusive' => ['atr_volatility_ok',
            ['atr' => 2.999, 'close' => 100.0, 'min_atr_pct' => 0.001, 'max_atr_pct' => 0.03],
            ['atr' => 3.0, 'close' => 100.0, 'min_atr_pct' => 0.001, 'max_atr_pct' => 0.03],
            ['atr' => 3.001, 'close' => 100.0, 'min_atr_pct' => 0.001, 'max_atr_pct' => 0.03],
            [true, true, false],
        ];
        yield 'atr-15m-min-inclusive' => ['atr_rel_in_range_15m',
            ['atr' => 0.0499, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            ['atr' => 0.05, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            ['atr' => 0.0501, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            [false, true, true],
        ];
        yield 'atr-15m-max-inclusive' => ['atr_rel_in_range_15m',
            ['atr' => 4.499, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            ['atr' => 4.5, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            ['atr' => 4.501, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            [true, true, false],
        ];
        yield 'atr-5m-min-inclusive' => ['atr_rel_in_range_5m',
            ['atr' => 0.0499, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            ['atr' => 0.05, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            ['atr' => 0.0501, 'close' => 100.0, 'min_atr_pct' => 0.0005, 'max_atr_pct' => 0.045],
            [false, true, true],
        ];
        yield 'macd-decreasing-strict' => ['macd_hist_decreasing_n',
            ['macd_hist_series' => [0.2, 0.100001], 'series_order' => 'oldest_to_newest', 'n' => 1, 'eps' => 0.1],
            ['macd_hist_series' => [0.2, 0.1], 'series_order' => 'oldest_to_newest', 'n' => 1, 'eps' => 0.1],
            ['macd_hist_series' => [0.2, 0.099999], 'series_order' => 'oldest_to_newest', 'n' => 1, 'eps' => 0.1],
            [false, false, true],
        ];
        yield 'macd-gt-eps-inclusive' => ['macd_hist_gt_eps',
            ['macd' => ['hist' => -0.100001], 'eps' => 0.1],
            ['macd' => ['hist' => -0.1], 'eps' => 0.1],
            ['macd' => ['hist' => -0.099999], 'eps' => 0.1],
            [false, true, true],
        ];
        yield 'macd-increasing-strict' => ['macd_hist_increasing_n',
            ['macd_hist_series' => [0.1, 0.099999], 'series_order' => 'oldest_to_newest', 'macd_hist_increasing_n' => 1],
            ['macd_hist_series' => [0.1, 0.1], 'series_order' => 'oldest_to_newest', 'macd_hist_increasing_n' => 1],
            ['macd_hist_series' => [0.1, 0.100001], 'series_order' => 'oldest_to_newest', 'macd_hist_increasing_n' => 1],
            [false, false, true],
        ];
        yield 'macd-lt-eps-inclusive' => ['macd_hist_lt_eps',
            ['macd' => ['hist' => 0.099999], 'eps' => 0.1],
            ['macd' => ['hist' => 0.1], 'eps' => 0.1],
            ['macd' => ['hist' => 0.100001], 'eps' => 0.1],
            [true, true, false],
        ];
        yield 'macd-cross-up-gap-inclusive' => ['macd_line_cross_up_with_hysteresis',
            ['macd_hist_last3' => [-0.001, 0.000999], 'min_gap' => 0.001, 'cool_down_bars' => 0, 'require_prev_below' => true],
            ['macd_hist_last3' => [-0.001, 0.001], 'min_gap' => 0.001, 'cool_down_bars' => 0, 'require_prev_below' => true],
            ['macd_hist_last3' => [-0.001, 0.001001], 'min_gap' => 0.001, 'cool_down_bars' => 0, 'require_prev_below' => true],
            [false, true, true],
        ];
        yield 'macd-cross-down-gap-inclusive' => ['macd_line_cross_down_with_hysteresis',
            ['macd_hist_last3' => [0.001, -0.001001], 'min_gap' => 0.001, 'cool_down_bars' => 0, 'require_prev_above' => true],
            ['macd_hist_last3' => [0.001, -0.001], 'min_gap' => 0.001, 'cool_down_bars' => 0, 'require_prev_above' => true],
            ['macd_hist_last3' => [0.001, -0.000999], 'min_gap' => 0.001, 'cool_down_bars' => 0, 'require_prev_above' => true],
            [true, true, false],
        ];
        $nearThreshold = abs((100.4 / 100.0) - 1.0);
        yield 'near-vwap-inclusive' => ['near_vwap',
            ['close' => 100.3999, 'vwap' => 100.0, 'near_vwap_tolerance' => $nearThreshold],
            ['close' => 100.4, 'vwap' => 100.0, 'near_vwap_tolerance' => $nearThreshold],
            ['close' => 100.4001, 'vwap' => 100.0, 'near_vwap_tolerance' => $nearThreshold],
            [true, true, false],
        ];
        yield 'rsi-softfloor-strict' => ['rsi_gt_softfloor',
            ['rsi' => 21.999, 'rsi_softfloor_threshold' => 22.0],
            ['rsi' => 22.0, 'rsi_softfloor_threshold' => 22.0],
            ['rsi' => 22.001, 'rsi_softfloor_threshold' => 22.0],
            [false, false, true],
        ];
        yield 'rsi-cap-strict' => ['rsi_lt_70',
            ['rsi' => 69.999, 'rsi_lt_70_threshold' => 70.0],
            ['rsi' => 70.0, 'rsi_lt_70_threshold' => 70.0],
            ['rsi' => 70.001, 'rsi_lt_70_threshold' => 70.0],
            [true, false, false],
        ];
        yield 'rsi-bullish-strict' => ['rsi_bullish',
            ['rsi' => 51.999, 'rsi_threshold' => 52.0, 'timeframe' => '5m'],
            ['rsi' => 52.0, 'rsi_threshold' => 52.0, 'timeframe' => '5m'],
            ['rsi' => 52.001, 'rsi_threshold' => 52.0, 'timeframe' => '5m'],
            [false, false, true],
        ];
        yield 'rsi-bearish-strict' => ['rsi_bearish',
            ['rsi' => 47.999, 'rsi_threshold' => 48.0],
            ['rsi' => 48.0, 'rsi_threshold' => 48.0],
            ['rsi' => 48.001, 'rsi_threshold' => 48.0],
            [true, false, false],
        ];
        yield 'rsi-gt-30-strict' => ['rsi_gt_30',
            ['rsi' => 29.999, 'rsi_threshold' => 30.0],
            ['rsi' => 30.0, 'rsi_threshold' => 30.0],
            ['rsi' => 30.001, 'rsi_threshold' => 30.0],
            [false, false, true],
        ];
        yield 'volume-ratio-inclusive' => ['volume_ratio_ok',
            ['volume_ratio' => 1.398, 'volume_ratio_threshold' => 1.4],
            ['volume_ratio' => 1.4, 'volume_ratio_threshold' => 1.4],
            ['volume_ratio' => 1.401, 'volume_ratio_threshold' => 1.4],
            [false, true, true],
        ];
    }

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }
}
