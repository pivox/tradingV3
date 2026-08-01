<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Dto\PreparedTradeEntry;

interface PaperOrderIntentRecorderInterface
{
    /**
     * @param array{client_order_id: string} $identity
     * @param array<string, string> $provenance
     * @return array{client_order_id: string, order_intent_id: int}
     */
    public function reserve(PreparedTradeEntry $prepared, array $identity, array $provenance): array;

    /** @param array{client_order_id: string, order_intent_id: int} $identity */
    public function acknowledge(array $identity, ExecutionResult $result): void;
}
