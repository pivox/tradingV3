<?php
declare(strict_types=1);

namespace App\TradeEntry\TpSplit;

use App\TradeEntry\TpSplit\Dto\TpSplitContext;
use App\TradeEntry\TpSplit\Strategy\TpSplitStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class TpSplitResolver
{
    /** @var TpSplitStrategyInterface[] */
    private array $strategies;

    /**
     * @param iterable<TpSplitStrategyInterface> $strategies
     */
    public function __construct(#[TaggedIterator('app.trade.tp_split')] iterable $strategies)
    {
        $this->strategies = is_array($strategies) ? $strategies : iterator_to_array($strategies);

        // Trier par priorité décroissante
        usort($this->strategies, function (TpSplitStrategyInterface $a, TpSplitStrategyInterface $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    /**
     * Retourne la part TP1 (0..1). Fallback 0.5 si aucune stratégie ne matche.
     */
    public function resolve(TpSplitContext $ctx): float
    {
        foreach ($this->strategies as $s) {
            if ($s->supports($ctx)) {
                return max(0.0, min(1.0, $s->ratio($ctx)));
            }
        }
        return 0.50;
    }
}

