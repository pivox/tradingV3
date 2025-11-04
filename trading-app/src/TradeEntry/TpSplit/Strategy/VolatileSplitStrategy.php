<?php
declare(strict_types=1);

namespace App\TradeEntry\TpSplit\Strategy;

use App\TradeEntry\TpSplit\Attribute\AsTpSplitStrategy;
use App\TradeEntry\TpSplit\Dto\TpSplitContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsTpSplitStrategy(name: 'volatile', priority: 30)]
#[AutoconfigureTag('app.trade.tp_split')]
final class VolatileSplitStrategy implements TpSplitStrategyInterface
{
    public function getName(): string { return 'volatile'; }
    public function getPriority(): int { return 30; }

    public function supports(TpSplitContext $ctx): bool
    {
        // fort mais bruité: on approxime par ATR% élevé et confiance faible
        return ($ctx->atrPct > 1.8) && ($ctx->mtfValidCount <= 1);
    }

    public function ratio(TpSplitContext $ctx): float
    {
        return 0.60; // 60 / 40
    }
}

