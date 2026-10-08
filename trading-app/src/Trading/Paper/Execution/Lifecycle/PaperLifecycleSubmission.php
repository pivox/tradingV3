<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Lifecycle;

use App\TradeEntry\Dto\ExecutionResult;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\Execution\Strategy\PaperPreparedDecision;

/** An entry order the Fake exchange accepted for a Paper decision, legacy or canonical (#132 j). */
final readonly class PaperLifecycleSubmission
{
    private function __construct(
        public ?PaperCanonicalPreparedEffect $canonical,
        public ?PaperPreparedDecision $legacy,
        public ExecutionResult $execution,
    ) {
    }

    public static function canonical(PaperCanonicalPreparedEffect $effect, ExecutionResult $execution): self
    {
        return new self($effect, null, $execution);
    }

    public static function legacy(PaperPreparedDecision $decision, ExecutionResult $execution): self
    {
        return new self(null, $decision, $execution);
    }
}
