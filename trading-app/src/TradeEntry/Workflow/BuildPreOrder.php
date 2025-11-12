<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\Dto\{PreflightReport, TradeEntryRequest};
use App\TradeEntry\Policy\PreTradeChecks;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BuildPreOrder
{
    public function __construct(
        private readonly PreTradeChecks $checks,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function __invoke(TradeEntryRequest $req, ?string $decisionKey = null): PreflightReport
    {
        $this->positionsLogger->info('build_pre_order.start', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'order_type' => $req->orderType,
            'decision_key' => $decisionKey,
            'reason' => 'collect_exchange_state',
        ]);

        try {
            $pre = $this->checks->run($req);

            $this->positionsLogger->debug('build_pre_order.preflight', [
                'symbol' => $pre->symbol,
                'decision_key' => $decisionKey,
                'best_bid' => $pre->bestBid,
                'best_ask' => $pre->bestAsk,
                'price_precision' => $pre->pricePrecision,
                'last_price' => $pre->lastPrice,
                'mark_price' => $pre->markPrice,
                'tick_size' => $pre->tickSize,
                'contract_size' => $pre->contractSize,
                'min_volume' => $pre->minVolume,
                'vol_precision' => $pre->volPrecision,
                'max_volume' => $pre->maxVolume,
                'market_max_volume' => $pre->marketMaxVolume,
                'max_leverage' => $pre->maxLeverage,
                'min_leverage' => $pre->minLeverage,
                'available_usdt' => $pre->availableUsdt,
                'spread_pct' => $pre->spreadPct,
                'mode_note' => $pre->modeNote,
                'reason' => 'pretrade_snapshot_ready',
            ]);

            return $pre;
        } catch (\Throwable $e) {
            $this->positionsLogger->error('build_pre_order.error', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
                'reason' => 'exception_during_pretrade_checks',
            ]);
            throw $e;
        }
    }
}
