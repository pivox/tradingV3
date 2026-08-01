<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\TradeEntry\Dto\PreparedTradeEntry;

final readonly class PaperPreparedDecision
{
    /**
     * @param array{client_order_id: string, order_intent_id: int} $orderIntentIdentity
     * @param array<string, string> $provenance
     */
    public function __construct(
        public PreparedTradeEntry $prepared,
        public array $orderIntentIdentity,
        public array $provenance,
    ) {
    }
}
