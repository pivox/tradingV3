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
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $flowLogger,
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $journeyLogger,
    ) {}

    public function __invoke(TradeEntryRequest $req, ?string $decisionKey = null): PreflightReport
    {
        $this->flowLogger->info('build_pre_order.start', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'order_type' => $req->orderType,
            'decision_key' => $decisionKey,
        ]);
        $this->journeyLogger->info('order_journey.preflight.started', [
            'symbol' => $req->symbol,
            'decision_key' => $decisionKey,
            'side' => $req->side->value,
            'order_type' => $req->orderType,
            'reason' => 'collect_exchange_state',
        ]);

        try {
            $pre = $this->checks->run($req);

            $this->flowLogger->debug('build_pre_order.preflight', [
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
            ]);

            $this->journeyLogger->info('order_journey.preflight.completed', [
                'symbol' => $pre->symbol,
                'decision_key' => $decisionKey,
                'best_bid' => $pre->bestBid,
                'best_ask' => $pre->bestAsk,
                'spread_pct' => $pre->spreadPct,
                'available_usdt' => $pre->availableUsdt,
                'mark_price' => $pre->markPrice,
                'vol_precision' => $pre->volPrecision,
                'max_volume' => $pre->maxVolume,
                'market_max_volume' => $pre->marketMaxVolume,
                'reason' => 'pretrade_snapshot_ready',
            ]);

            return $pre;
        } catch (\Throwable $e) {
            $this->flowLogger->error('build_pre_order.error', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'message' => $e->getMessage(),
            ]);
            $this->journeyLogger->error('order_journey.preflight.failed', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'exception_during_pretrade_checks',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
