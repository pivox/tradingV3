<?php
declare(strict_types=1);

namespace App\EntryTrade\Example;

use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Service\TradeEntryService;
use App\TradeEntry\Types\Side;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service d'exemple montrant comment déclencher un ordre via TradeEntryService.
 *
 * À adapter selon vos besoins (stratégie, params dynamiques…).
 */
final class ExampleTradeEntryRunner
{
    public function __construct(
        private readonly TradeEntryService $tradeEntryService,
        #[Autowire('%trade_entry.defaults%')]
        private readonly array $defaults = [],
    ) {}

    /**
     * Exemple: place un ordre LIMIT Maker sur le symbole donné.
     *
     * @param string $symbol Ex: BTCUSDT
     * @param Side $side Sens de l'ordre
     * @param float $price Prix limite souhaité
     * @param float|null $atr ATR pré-calculé (optionnel)
     */
    public function placeLimitOrder(string $symbol, Side $side, float $price, ?float $atr = null)
    {
        $riskPctPercent = (float)($this->defaults['risk_pct_percent'] ?? 2.0);
        $riskPct = max(0.0, $riskPctPercent > 1.0 ? $riskPctPercent / 100.0 : $riskPctPercent);

        $request = new TradeEntryRequest(
            symbol: $symbol,
            side: $side,
            orderType: 'limit',
            openType: $this->defaults['open_type'] ?? 'isolated',
            orderMode: (int)($this->defaults['order_mode'] ?? 4),
            initialMarginUsdt: (float)($this->defaults['initial_margin_usdt'] ?? 100.0),
            riskPct: $riskPct,
            rMultiple: (float)($this->defaults['r_multiple'] ?? 2.0),
            entryLimitHint: $price,
            stopFrom: $atr !== null ? 'atr' : ($this->defaults['stop_from'] ?? 'risk'),
            atrValue: $atr,
            atrK: (float)($this->defaults['atr_k'] ?? 1.5),
            marketMaxSpreadPct: (float)($this->defaults['market_max_spread_pct'] ?? 0.001)
        );

        return $this->tradeEntryService->buildAndExecute($request);
    }
}
