<?php
declare(strict_types=1);

namespace App\TradeEntry\TpSplit\Strategy;

use App\TradeEntry\TpSplit\Attribute\AsTpSplitStrategy;
use App\TradeEntry\TpSplit\Dto\TpSplitContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsTpSplitStrategy(name: 'strong_momentum', priority: 40)]
#[AutoconfigureTag('app.trade.tp_split')]
final class StrongMomentumSplitStrategy implements TpSplitStrategyInterface
{
    public function getName(): string { return 'strong_momentum'; }
    public function getPriority(): int { return 40; }

    public function supports(TpSplitContext $ctx): bool
    {
        return ($ctx->momentum === 'fort') && ($ctx->atrPct < 1.2) && ($ctx->mtfValidCount >= 3) && $ctx->pullbackClear;
    }

    public function ratio(TpSplitContext $ctx): float
    {
        return 0.30; // 30 / 70
    }
}

