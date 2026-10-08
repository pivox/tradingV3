<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\Trading\Lineage\LineageContext;

/**
 * Internal trade id of a canonical Paper decision (#132 l and o): derived from the decision, so
 * the same decision always opens the same trade, whatever the run, the resume or the database.
 * The order intent's lineage (PaperCanonicalOrderIntentRecorder) and the Fake order metadata
 * (PaperCanonicalFakeEffectDispatcher), which the protective orders and every fill inherit,
 * carry the same id.
 */
final class PaperCanonicalTradeIdentity
{
    public static function internalTradeId(LineageContext $lineage, string $decisionKey): string
    {
        return $lineage->internalTradeId
            ?? 'itd:' . substr(hash('sha256', 'paper-canonical-trade:' . $decisionKey), 0, 32);
    }
}
