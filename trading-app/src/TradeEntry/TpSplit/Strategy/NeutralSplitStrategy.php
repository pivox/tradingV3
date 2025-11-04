<?php
declare(strict_types=1);

namespace App\TradeEntry\TpSplit\Strategy;

use App\TradeEntry\TpSplit\Attribute\AsTpSplitStrategy;
use App\TradeEntry\TpSplit\Dto\TpSplitContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsTpSplitStrategy(name: 'neutral', priority: 10)]
#[AutoconfigureTag('app.trade.tp_split')]
final class NeutralSplitStrategy implements TpSplitStrategyInterface
{
    public function getName(): string { return 'neutral'; }
    public function getPriority(): int { return 10; }

    public function supports(TpSplitContext $ctx): bool
    {
        $atr = $ctx->atrPct;
        $mtf = $ctx->mtfValidCount;
        return ($ctx->momentum === 'moyen') && ($atr >= 1.2 && $atr <= 1.8) && ($mtf >= 2);
    }

    public function ratio(TpSplitContext $ctx): float
    {
        return 0.50; // 50 / 50
    }
}

