<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\TradeEntry\Dto\ExecutionResult;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;

interface PaperCanonicalOrderIntentRecorderInterface
{
    /**
     * @param array{client_order_id: string} $identity
     * @param array<string, string>          $provenance
     * @return array{client_order_id: string, order_intent_id: int}
     */
    public function reserve(
        CanonicalOrderPlan $plan,
        LineageContext $lineage,
        string $decisionKey,
        string $executionTimeframe,
        array $identity,
        array $provenance,
    ): array;

    /** @param array{client_order_id: string, order_intent_id: int} $identity */
    public function acknowledge(array $identity, ExecutionResult $result): void;
}
