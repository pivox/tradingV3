<?php
declare(strict_types=1);

namespace App\TradeEntry\TpSplit\Strategy;

use App\TradeEntry\TpSplit\Dto\TpSplitContext;

interface TpSplitStrategyInterface
{
    public function getName(): string;
    public function getPriority(): int;
    public function supports(TpSplitContext $ctx): bool;
    /**
     * Retourne la part TP1 (0.0..1.0). TP2 = 1 - TP1
     */
    public function ratio(TpSplitContext $ctx): float;
}

