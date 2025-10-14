<?php

declare(strict_types=1);

namespace App\Service\Trading;

use App\Service\Config\TradingParameters;
use Psr\Log\LoggerInterface;

final class ScalpModeTriggerService
{
    public function __construct(
        private readonly TradingParameters $tradingParameters,
        private readonly LoggerInterface $signalsLogger,
    ) {}

    /**
     * Returns trigger context when the scalping mode conditions are satisfied.
     * The returned payload keeps evaluation details for downstream logging/meta tagging.
     */
    public function evaluate(string $symbol, string $timeframe, array $signalsPayload, array $context = []): ?array
    {
        $config = $this->tradingParameters->all();
        $triggerCfg = $config['scalp_mode_trigger'] ?? null;
        if (!\is_array($triggerCfg) || $triggerCfg === []) {
            return null; // Nothing to evaluate
        }

        $finalSide = strtoupper((string)($signalsPayload['final']['signal'] ?? 'NONE'));
        if (!\in_array($finalSide, ['LONG', 'SHORT'], true)) {
            $this->signalsLogger->debug('Scalp trigger ignored: final side is not actionable', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'final_side' => $finalSide,
            ]);
            return null;
        }

        $conditions = $triggerCfg['all'] ?? [];
        if (!\is_array($conditions)) {
            $conditions = [];
        }

        $evaluations = [];
        $passedAll = true;
        foreach ($conditions as $condition) {
            if (!\is_array($condition) || $condition === []) {
                continue;
            }

            $name = (string)array_key_first($condition);
            $expected = $condition[$name];
            [$passed, $actual] = $this->evaluateCondition(
                condition: $name,
                expected: $expected,
                timeframe: $timeframe,
                finalSide: $finalSide,
                signalsPayload: $signalsPayload,
                context: $context
            );

            $evaluations[] = [
                'condition' => $name,
                'expected' => $expected,
                'actual' => $actual,
                'passed' => $passed,
            ];

            if (!$passed) {
                $passedAll = false;
            }
        }

        if (!$passedAll) {
            $this->signalsLogger->info('Scalp mode trigger skipped: conditions not met', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'final_side' => $finalSide,
                'evaluations' => $evaluations,
            ]);
            return null;
        }

        $result = [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'final_side' => $finalSide,
            'conditions' => $evaluations,
            'overrides' => $triggerCfg['overrides'] ?? [],
            'meta' => $triggerCfg['meta'] ?? null,
        ];

        $this->signalsLogger->info('Scalp mode trigger satisfied', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'final_side' => $finalSide,
            'overrides' => $result['overrides'],
        ]);

        return $result;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateCondition(
        string $condition,
        mixed $expected,
        string $timeframe,
        string $finalSide,
        array $signalsPayload,
        array $context
    ): array {
        return match ($condition) {
            'mtf_confirmed' => $this->evaluateMtfConfirmed($expected, $signalsPayload, $finalSide),
            'timeframe' => [$expected === $timeframe, $timeframe],
            'atr_rel_in_range' => $this->evaluateAtrRange($expected, $signalsPayload, $timeframe, $context),
            'volume_rel_gt_1_5x' => $this->evaluateVolumeRel($expected, $signalsPayload, $timeframe, $context),
            'ema_20_gt_50_or_lt_50' => $this->evaluateEmaAlignment($expected, $signalsPayload, $timeframe, $finalSide, $context),
            'macd_hist_sign_aligned' => $this->evaluateMacdAlignment($expected, $signalsPayload, $timeframe, $finalSide, $context),
            'vwap_slope_stable' => $this->evaluateVwapSlope($expected, $signalsPayload, $timeframe, $context),
            default => [false, null],
        };
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateMtfConfirmed(mixed $expected, array $signalsPayload, string $finalSide): array
    {
        $actual = $this->isMtfConfirmed($signalsPayload, $finalSide);
        return [$actual === (bool)$expected, $actual];
    }

    private function isMtfConfirmed(array $signalsPayload, string $finalSide): bool
    {
        if (!\in_array($finalSide, ['LONG', 'SHORT'], true)) {
            return false;
        }

        foreach (['15m', '5m'] as $tf) {
            $signal = strtoupper((string)($signalsPayload[$tf]['signal'] ?? $signalsPayload[$tf]['final']['signal'] ?? 'NONE'));
            if ($signal === 'NONE' || $signal !== $finalSide) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateAtrRange(mixed $expected, array $signalsPayload, string $timeframe, array $context): array
    {
        $value = $this->getMetricValue($signalsPayload, $timeframe, 'atr_rel', $context);
        if (!\is_array($expected) || \count($expected) !== 2) {
            return [false, $value];
        }

        $min = (float)$expected[0];
        $max = (float)$expected[1];
        $passed = $value !== null && $value >= $min && $value <= $max;

        return [$passed, $value];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateVolumeRel(mixed $expected, array $signalsPayload, string $timeframe, array $context): array
    {
        $threshold = 1.5;
        if (\is_array($expected) && isset($expected['gte'])) {
            $threshold = (float)$expected['gte'];
        }
        if (\is_numeric($expected)) {
            $threshold = (float)$expected;
        }

        $value = $this->getMetricValue($signalsPayload, $timeframe, 'volume_rel', $context);
        if ($value === null) {
            $value = $this->getMetricValue($signalsPayload, $timeframe, 'volume_ratio', $context);
        }

        if ($value === null) {
            return [false, null];
        }

        $actual = $value >= $threshold;
        return [$actual === (bool)$expected, $value];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateEmaAlignment(mixed $expected, array $signalsPayload, string $timeframe, string $finalSide, array $context): array
    {
        $emaFast = $this->getMetricValue($signalsPayload, $timeframe, 'ema_20', $context);
        if ($emaFast === null) {
            $emaFast = $this->getMetricValue($signalsPayload, $timeframe, 'ema_fast', $context);
        }
        $emaSlow = $this->getMetricValue($signalsPayload, $timeframe, 'ema_50', $context);
        if ($emaSlow === null) {
            $emaSlow = $this->getMetricValue($signalsPayload, $timeframe, 'ema_slow', $context);
        }

        $actual = null;
        if ($emaFast !== null && $emaSlow !== null) {
            if ($finalSide === 'LONG') {
                $actual = $emaFast > $emaSlow;
            } else {
                $actual = $emaFast < $emaSlow;
            }
        }

        return [$actual === (bool)$expected, $actual];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateMacdAlignment(mixed $expected, array $signalsPayload, string $timeframe, string $finalSide, array $context): array
    {
        $macd = $this->getMetricValue($signalsPayload, $timeframe, 'macd_hist', $context);
        $actual = null;
        if ($macd !== null) {
            $actual = $finalSide === 'LONG' ? $macd > 0 : $macd < 0;
        }

        return [$actual === (bool)$expected, $actual];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateVwapSlope(mixed $expected, array $signalsPayload, string $timeframe, array $context): array
    {
        $value = $this->getMetricValue($signalsPayload, $timeframe, 'vwap_slope_stable', $context);
        if ($value === null) {
            $value = $this->getMetricValue($signalsPayload, $timeframe, 'vwap_slope', $context);
            if ($value !== null) {
                $value = abs((float)$value) < 0.15; // heuristique : slope « stable »
            }
        }

        return [$value === (bool)$expected, $value];
    }

    private function getMetricValue(array $signalsPayload, string $timeframe, string $metric, array $context): mixed
    {
        $tfPayload = $signalsPayload[$timeframe] ?? [];
        if (isset($tfPayload['metrics'][$metric])) {
            return $tfPayload['metrics'][$metric];
        }
        if (array_key_exists($metric, $tfPayload)) {
            return $tfPayload[$metric];
        }

        if (isset($signalsPayload['metrics'][$metric])) {
            return $signalsPayload['metrics'][$metric];
        }
        if (isset($context[$metric])) {
            return $context[$metric];
        }

        return null;
    }
}
