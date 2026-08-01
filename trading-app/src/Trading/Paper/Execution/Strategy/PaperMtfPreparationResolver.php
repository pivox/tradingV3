<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Logging\LifecycleContextFactory;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Builder\TradeEntryRequestBuilder;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\Service\TradeEntryService;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final readonly class PaperMtfPreparationResolver
{
    public function __construct(
        private TradeEntryRequestBuilder $requests,
        private TradeEntryService $tradeEntry,
        private LifecycleContextFactory $lifecycleContexts,
        private PaperKlineProvider $klines,
    ) {
    }

    public function __invoke(MtfRunResponseDto $response, PaperExecutionCell $cell, PaperMarketEvent $event): ?PreparedTradeEntry
    {
        $tradable = null;
        foreach ($response->results as $entry) {
            $result = is_array($entry) ? ($entry['result'] ?? null) : null;
            if (!$result instanceof MtfResultDto || !$result->isTradable || $result->symbol !== $event->symbol) {
                continue;
            }
            if ($tradable !== null) {
                throw new \LogicException('paper_mtf_multiple_tradable_results');
            }
            $tradable = $result;
        }
        if (!$tradable instanceof MtfResultDto || $tradable->side === null || $tradable->executionTimeframe === null) {
            return null;
        }

        $timeframe = Timeframe::tryFrom($tradable->executionTimeframe);
        if ($timeframe === null) {
            throw new \LogicException('paper_mtf_timeframe_invalid');
        }
        $last = $this->klines->getLastKline($event->symbol, $timeframe);
        $atr = $last === null ? null : (float) (string) $last->high->minus($last->low);
        $close = $event->payload['close'] ?? null;
        $price = is_string($close) && is_numeric($close) ? (float) $close : null;
        $request = $this->requests->fromMtfSignal(
            $event->symbol,
            $tradable->side,
            $tradable->executionTimeframe,
            $price,
            $atr !== null && $atr > 0.0 ? $atr : null,
            $cell->strategyProfile,
            exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
        );
        if ($request === null) {
            return null;
        }
        $decisionKey = 'paper:' . hash('sha256', $cell->id . '|' . $event->eventId . '|' . strtolower($tradable->side));
        $lifecycle = $this->lifecycleContexts->create($event->symbol)->merge([
            'run_id' => $cell->runId,
            'config_hash' => $cell->configurationSnapshotId,
            'origin' => 'replay',
        ]);

        return $this->tradeEntry->prepare(
            $request,
            $decisionKey,
            $cell->strategyProfile,
            $lifecycle,
            paperCellId: $cell->id,
            sourceEventId: $event->eventId,
        );
    }
}
