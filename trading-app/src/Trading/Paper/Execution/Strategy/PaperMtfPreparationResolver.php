<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Contract\MtfValidator\Dto\MtfResultDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Logging\Dto\LifecycleContextBuilder;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final readonly class PaperMtfPreparationResolver
{
    public const MODEL_VERSION = 'paper-prudent-plan-v1';

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

        if (Timeframe::tryFrom($tradable->executionTimeframe) === null) {
            throw new \LogicException('paper_mtf_timeframe_invalid');
        }
        $side = Side::tryFrom(strtolower($tradable->side));
        if ($side === null) {
            throw new \LogicException('paper_mtf_side_invalid');
        }
        $entry = $this->positivePrice($event->payload['close'] ?? null);
        $high = $this->positivePrice($event->payload['high'] ?? null);
        $low = $this->positivePrice($event->payload['low'] ?? null);
        if ($low > $entry || $high < $entry || $low > $high) {
            throw new \LogicException('paper_prudent_plan_candle_invalid');
        }
        $risk = max($high - $low, $entry * 0.005);
        $stop = $side === Side::Long ? $entry - $risk : $entry + $risk;
        $takeProfit = $side === Side::Long ? $entry + $risk : $entry - $risk;
        if ($stop <= 0.0 || $takeProfit <= 0.0) {
            return null;
        }
        $decisionKey = 'paper:' . hash('sha256', $cell->id . '|' . $event->eventId . '|' . strtolower($tradable->side));
        $tradeId = 'ptrd:' . hash('sha256', $cell->id . '|' . $event->eventId . '|' . $decisionKey);
        $lifecycle = (new LifecycleContextBuilder($event->symbol))->merge([
            'run_id' => $cell->runId,
            'config_hash' => $cell->configurationSnapshotId,
            'origin' => 'replay',
            'decision_key' => $decisionKey,
            'trade_id' => $tradeId,
            'internal_trade_id' => $tradeId,
            'profile' => $cell->strategyProfile,
            'paper_plan_model' => self::MODEL_VERSION,
        ]);

        return new PreparedTradeEntry(
            new OrderPlanModel(
                symbol: $event->symbol,
                side: $side,
                orderType: 'market',
                openType: 'isolated',
                orderMode: 1,
                entry: $entry,
                stop: $stop,
                takeProfit: $takeProfit,
                size: 1,
                leverage: 1,
                pricePrecision: 8,
                contractSize: 1.0,
                entryZoneLow: $entry,
                entryZoneHigh: $entry,
                zoneExpiresAt: $event->exchangeTimestamp->modify('+180 seconds'),
                entryZoneMeta: [
                    'model_version' => self::MODEL_VERSION,
                    'cell_id' => $cell->id,
                    'source_event_id' => $event->eventId,
                ],
                stopRisk: $risk,
                stopFinalSource: self::MODEL_VERSION,
                exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
            ),
            null,
            $decisionKey,
            $tradeId,
            $lifecycle,
            $cell->strategyProfile,
            $tradable->executionTimeframe,
        );
    }

    private function positivePrice(mixed $value): float
    {
        if (!is_string($value) || !is_numeric($value)) {
            throw new \LogicException('paper_prudent_plan_candle_invalid');
        }
        $price = (float) $value;
        if (!is_finite($price) || $price <= 0.0) {
            throw new \LogicException('paper_prudent_plan_candle_invalid');
        }

        return $price;
    }
}
