<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final class PaperMtfStrategyBridge implements PaperStrategyPreparationInterface
{
    /** @var \Closure(MtfRunResponseDto, PaperExecutionCell, PaperMarketEvent): ?PreparedTradeEntry */
    private readonly \Closure $preparationResolver;

    /** @param callable(MtfRunResponseDto, PaperExecutionCell, PaperMarketEvent): ?PreparedTradeEntry $preparationResolver */
    public function __construct(
        private readonly MtfValidatorInterface $validator,
        private readonly PaperKlineProvider $klineProvider,
        callable $preparationResolver,
        private readonly ?IndicatorProviderInterface $indicators = null,
    ) {
        $this->preparationResolver = \Closure::fromCallable($preparationResolver);
    }

    public function prepareFor(PaperExecutionCell $cell, PaperMarketEvent $event): ?PreparedTradeEntry
    {
        if ($event->channel !== PaperMarketDataChannel::CANDLE_1M
            || ($event->payload['confirmed'] ?? false) !== true
            || $event->sourceNetwork !== $cell->network
            || $event->sourceVenue !== $cell->marketDataVenue
        ) {
            return null;
        }

        $this->indicators?->clearCaches();

        foreach ($this->validator->getListTimeframe($cell->strategyProfile) as $timeframeValue) {
            if (!is_string($timeframeValue)) {
                throw new \InvalidArgumentException('paper_mtf_timeframe_invalid');
            }
            $timeframe = Timeframe::tryFrom($timeframeValue);
            if ($timeframe === null || $this->klineProvider->getLastKline($event->symbol, $timeframe) === null) {
                return null;
            }
        }

        $lineage = new LineageContext(
            origin: LineageContext::ORIGIN_MANUAL,
            orchestrationRunId: $cell->runId,
            correlationRunId: $cell->runId,
            mtfProfile: $cell->strategyProfile,
            exchange: Exchange::FAKE->value,
            marketType: MarketType::PERPETUAL->value,
            symbol: $event->symbol,
            configHash: $cell->configurationSnapshotId,
            dryRun: true,
        );
        $response = $this->validator->run(new MtfRunRequestDto(
            symbols: [$event->symbol],
            dryRun: true,
            currentTf: Timeframe::TF_1M->value,
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            profile: $cell->strategyProfile,
            requestId: $cell->runId,
            orchestrationRunId: $cell->runId,
            lineageContext: $lineage,
        ));
        $prepared = ($this->preparationResolver)($response, $cell, $event);
        if ($prepared !== null && $prepared->plan?->symbol !== $event->symbol) {
            throw new \LogicException('paper_prepared_symbol_mismatch');
        }

        return $prepared;
    }
}
