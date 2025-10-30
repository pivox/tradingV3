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
    ) {}

    public function __invoke(TradeEntryRequest $req, ?string $decisionKey = null): PreflightReport
    {
        $this->flowLogger->info('build_pre_order.start', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'order_type' => $req->orderType,
            'decision_key' => $decisionKey,
        ]);

        try {
            $pre = $this->checks->run($req);

            $this->flowLogger->debug('build_pre_order.preflight', [
                'symbol' => $pre->symbol,
                'decision_key' => $decisionKey,
                'best_bid' => $pre->bestBid,
                'best_ask' => $pre->bestAsk,
                'price_precision' => $pre->pricePrecision,
                'contract_size' => $pre->contractSize,
                'min_volume' => $pre->minVolume,
                'max_leverage' => $pre->maxLeverage,
                'min_leverage' => $pre->minLeverage,
                'available_usdt' => $pre->availableUsdt,
                'spread_pct' => $pre->spreadPct,
                'mode_note' => $pre->modeNote,
            ]);

            return $pre;
        } catch (\Throwable $e) {
            $this->flowLogger->error('build_pre_order.error', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
