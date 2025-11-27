<?php
declare(strict_types=1);

namespace App\TradeEntry\Example;

use App\Config\TradeEntryConfigResolver;
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
        #[Autowire(service: 'App\\Config\\TradeEntryConfigResolver')]
        private readonly TradeEntryConfigResolver $tradeEntryConfigResolver,
    ) {}

    /**
     * Exemple: place un ordre LIMIT Maker sur le symbole donné.
     *
     * @param string $symbol Ex: BTCUSDT
     * @param Side $side Sens de l'ordre
     * @param float $price Prix limite souhaité
     * @param float|null $atr ATR pré-calculé (optionnel)
     */
    public function placeLimitOrder(string $symbol, Side $side, float $price, ?float $atr = null, ?string $mode = null)
    {
        $config = $this->tradeEntryConfigResolver->resolve($mode);
        $defaults = $config->getDefaults();
        $riskPctPercent = (float)($defaults['risk_pct_percent'] ?? 2.0);
        $riskPct = max(0.0, $riskPctPercent > 1.0 ? $riskPctPercent / 100.0 : $riskPctPercent);
        $pivotSlPolicy = (string)($defaults['pivot_sl_policy'] ?? 'nearest_below');
        $pivotSlBufferPct = isset($defaults['pivot_sl_buffer_pct']) ? (float)$defaults['pivot_sl_buffer_pct'] : null;
        if ($pivotSlBufferPct !== null && $pivotSlBufferPct < 0.0) {
            $pivotSlBufferPct = null;
        }
        $pivotSlMinKeepRatio = isset($defaults['pivot_sl_min_keep_ratio']) ? (float)$defaults['pivot_sl_min_keep_ratio'] : null;
        if ($pivotSlMinKeepRatio !== null && $pivotSlMinKeepRatio <= 0.0) {
            $pivotSlMinKeepRatio = null;
        }

        $request = new TradeEntryRequest(
            symbol: $symbol,
            side: $side,
            orderType: 'limit',
            openType: $defaults['open_type'] ?? 'isolated',
            orderMode: (int)($defaults['order_mode'] ?? 1),
            initialMarginUsdt: (float)($defaults['initial_margin_usdt'] ?? 100.0),
            riskPct: $riskPct,
            rMultiple: (float)($defaults['r_multiple'] ?? 2.0),
            entryLimitHint: $price,
            stopFrom: $atr !== null ? 'atr' : ($defaults['stop_from'] ?? 'risk'),
            pivotSlPolicy: $pivotSlPolicy,
            pivotSlBufferPct: $pivotSlBufferPct,
            pivotSlMinKeepRatio: $pivotSlMinKeepRatio,
            atrValue: $atr,
            atrK: (float)($defaults['atr_k'] ?? 1.5),
            marketMaxSpreadPct: (float)($defaults['market_max_spread_pct'] ?? 0.001)
        );

        return $this->tradeEntryService->buildAndExecute($request, mode: $mode);
    }
}
