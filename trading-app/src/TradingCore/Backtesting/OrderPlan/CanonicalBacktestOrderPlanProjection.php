<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\OrderPlan;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;

final readonly class CanonicalBacktestOrderPlanProjection
{
    /** @return array{schema_version: string, dataset_id: string, dataset_checksum: string, timeframe: string, plan: array<string, mixed>} */
    public function project(
        CanonicalOrderPlan $plan,
        string $datasetId,
        string $datasetChecksum,
        string $timeframe,
    ): array {
        if (
            preg_match('/\Abacktest-dataset-[a-f0-9]{64}\z/D', $datasetId) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $datasetChecksum) !== 1
            || $datasetId !== 'backtest-dataset-' . substr($datasetChecksum, 7)
            || !\in_array($timeframe, ['1m', '5m', '15m', '1h', '4h'], true)
            || $plan->exchange !== 'fake'
            || !\in_array($plan->environment, ['local', 'test'], true)
            || !hash_equals($plan->expectedPlanHash(), $plan->planHash)
        ) {
            throw new CanonicalOrderPlanException('canonical_backtest_order_plan_invalid');
        }

        return [
            'schema_version' => 'canonical-backtest-order-plan.v1',
            'dataset_id' => $datasetId,
            'dataset_checksum' => $datasetChecksum,
            'timeframe' => $timeframe,
            'plan' => $plan->toArray(),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function canonicalJson(array $payload): string
    {
        return CanonicalOrderPlanDecimal::encodeCanonicalJson(
            $payload,
            'canonical_backtest_order_plan_encoding_invalid',
        );
    }
}
