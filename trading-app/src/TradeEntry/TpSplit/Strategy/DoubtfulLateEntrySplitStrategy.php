<?php
declare(strict_types=1);

namespace App\TradeEntry\TpSplit\Strategy;

use App\TradeEntry\TpSplit\Attribute\AsTpSplitStrategy;
use App\TradeEntry\TpSplit\Dto\TpSplitContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsTpSplitStrategy(name: 'doubtful_late_entry', priority: 50)]
#[AutoconfigureTag('app.trade.tp_split')]
final class DoubtfulLateEntrySplitStrategy implements TpSplitStrategyInterface
{
    public function getName(): string { return 'doubtful_late_entry'; }
    public function getPriority(): int { return 50; }

    public function supports(TpSplitContext $ctx): bool
    {
        return ($ctx->momentum === 'faible') && ($ctx->atrPct > 2.0) && ($ctx->mtfValidCount <= 1) && $ctx->lateEntry;
    }

    public function ratio(TpSplitContext $ctx): float
    {
        return 0.70; // 70 / 30
    }
}
