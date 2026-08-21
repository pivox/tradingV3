<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;

final readonly class PaperCanonicalStrategyDecision
{
    public function __construct(
        public CanonicalOrderPlan $plan,
        public CanonicalPortfolioAdmissionProof $admissionProof,
        public CanonicalPortfolioReservation $reservation,
        public LineageContext $lineage,
        public string $decisionKey,
        public string $executionTimeframe,
    ) {
    }

    public static function fromPreparedEffect(
        PaperCanonicalPreparedEffect $effect,
    ): self {
        $effect->assertValid();

        return new self(
            $effect->plan,
            $effect->admissionProof,
            $effect->reservation,
            $effect->lineage,
            $effect->decisionKey,
            $effect->executionTimeframe,
        );
    }

    /**
     * @param array{client_order_id: string, order_intent_id: int} $identity
     * @param array<string, string>                                $provenance
     */
    public function prepare(array $identity, array $provenance): PaperCanonicalPreparedEffect
    {
        return new PaperCanonicalPreparedEffect(
            $this->plan,
            $this->admissionProof,
            $this->reservation,
            $this->lineage,
            $this->decisionKey,
            $this->executionTimeframe,
            $identity,
            $provenance,
        );
    }
}
