<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Execution;

use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;

final readonly class ExecutionSelectorMetrics
{
    /** @param array<string,array<string,mixed>> $byTimeframe */
    public function __construct(
        public LineageContext $identity,
        public array $byTimeframe,
    ) {
        if ($identity->modeId === null) {
            throw new LineageContextException('canonical_identity_missing:selector_identity');
        }
    }

    /** @param string[] $timeframes */
    public function covers(array $timeframes): bool
    {
        foreach ($timeframes as $timeframe) {
            if (!isset($this->byTimeframe[$timeframe]) || $this->byTimeframe[$timeframe] === []) {
                return false;
            }
        }
        return true;
    }
}
