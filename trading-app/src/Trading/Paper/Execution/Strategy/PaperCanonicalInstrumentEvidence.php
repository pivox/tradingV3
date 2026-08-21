<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\TradingCore\OrderPlan\Canonical\CanonicalTickSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalInstrumentSnapshot;

final readonly class PaperCanonicalInstrumentEvidence
{
    public function __construct(
        public CanonicalInstrumentSnapshot $instrument,
        public CanonicalTickSnapshot $tick,
    ) {
        if ($instrument->exchange !== $tick->exchange
            || $instrument->environment !== $tick->environment
            || $instrument->symbol !== $tick->symbol
            || $instrument->marketType !== $tick->marketType
            || !hash_equals($instrument->inputHash, $tick->inputHash)
        ) {
            throw new \LogicException('paper_canonical_instrument_evidence_mismatch');
        }
    }
}
